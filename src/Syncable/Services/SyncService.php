<?php

namespace Syncable\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Syncable\Events\SyncFailed;
use Syncable\Events\SyncSucceeded;
use Syncable\Models\SyncLog;
use Syncable\Models\IdMapping;
use Syncable\Services\ConflictResolutionService;

class SyncService
{
    /**
     * @var ApiService
     */
    protected $apiService;

    /**
     * @var EncryptionService
     */
    protected $encryptionService;

    /**
     * @var TenantService
     */
    protected $tenantService;

    /**
     * Create a new SyncService instance.
     *
     * @param ApiService $apiService
     * @param EncryptionService $encryptionService
     * @param TenantService $tenantService
     */
    public function __construct(
        ApiService $apiService,
        EncryptionService $encryptionService,
        TenantService $tenantService
    ) {
        $this->apiService = $apiService;
        $this->encryptionService = $encryptionService;
        $this->tenantService = $tenantService;
    }

    /**
     * Sync a model to the target application.
     *
     * @param Model $model
     * @param string $action
     * @param string|null $origin_system_id
     * @return bool
     */
    public function syncModel(Model $model, string $action = 'update', ?string $origin_system_id = null): bool
    {
        try {
            // Check if this is an incoming sync we've already processed
            // to prevent infinite loops in bidirectional sync
            if ($this->shouldSkipSync($model, $origin_system_id)) {
                return true;
            }

            // Get the model's syncable configuration
            $config = $this->getModelConfig($model);

            // Prepare data for syncing
            $data = $this->prepareData($model, $config, $action);

            // Add tenant information if applicable
            if ($this->tenantService->isEnabled()) {
                $data = $this->tenantService->attachTenantData($data, $model);

                // Add dynamic target tenant ID for System B (without affecting logs)
                // First try to get tenant ID from getTargetTenantId method if available
                if (method_exists($model, 'getTargetTenantId')) {
                    $targetTenantId = $model->getTargetTenantId();
                    if ($targetTenantId !== null) {
                        $data['target_tenant_id'] = $targetTenantId;
                    }
                }
                // Then try tenant_id property or getTenantId method
                else if (property_exists($model, 'tenant_id') || method_exists($model, 'getTenantId')) {
                    $targetTenantId = method_exists($model, 'getTenantId') ? $model->getTenantId() : $model->tenant_id;
                    $data['target_tenant_id'] = $targetTenantId;
                }
                // If not found but specified in config, use that as fallback
                else if ($configTenantId = Config::get('syncable.target_tenant_id')) {
                    $data['target_tenant_id'] = $configTenantId;
                }
            } else {
                // System A is not tenant-based, but System B might be
                // First check if target_tenant_id is set on the model
                if (property_exists($model, 'target_tenant_id') && $model->target_tenant_id !== null) {
                    $data['target_tenant_id'] = $model->target_tenant_id;
                }
                // Then check if getTargetTenantId method exists
                elseif (method_exists($model, 'getTargetTenantId')) {
                    $targetTenantId = $model->getTargetTenantId();
                    if ($targetTenantId !== null) {
                        $data['target_tenant_id'] = $targetTenantId;
                    }
                }
                // Then check model config
                else {
                    $modelConfig = $this->getModelConfig($model);
                    if (isset($modelConfig['target_tenant_id'])) {
                        $data['target_tenant_id'] = $modelConfig['target_tenant_id'];
                    }
                    // Then check global config
                    else if ($configTenantId = Config::get('syncable.target_tenant_id')) {
                        $data['target_tenant_id'] = $configTenantId;
                    }
                }
            }

            // Add our system identifier for bidirectional syncing
            $data['origin_system_id'] = Config::get('syncable.system_id', env('SYNCABLE_SYSTEM_ID'));

            // Track our own system's modified fields for differential sync
            if ($action === 'update' && $model->isDirty()) {
                $data['changed_fields'] = $model->getDirty();
            }

            // Encrypt data if encryption is enabled
            if (Config::get('syncable.encryption.enabled', true)) {
                $data = $this->encryptionService->encrypt($data);
            }

            // Send data to the target application
            $response = $this->apiService->sendRequest($data, $action);

            // Handle the response
            $success = $this->handleResponse($response, $model, $action);

            if ($success) {
                // If this was a create operation, store the ID mapping
                if ($action === 'create' && isset($response['data']['id'])) {
                    $targetSystem = Config::get('syncable.api.target_system_id', env('SYNCABLE_TARGET_SYSTEM_ID'));
                    $tenantId = $this->tenantService->isEnabled() ? $this->tenantService->getCurrentTenantId() : null;

                    IdMapping::createOrUpdateMapping(
                        get_class($model),
                        $model->getKey(),
                        $data['target_model'] ?? get_class($model),
                        $response['data']['id'],
                        $targetSystem,
                        $tenantId
                    );
                }

                event(new SyncSucceeded($model, $action));

                // Log successful sync
                $this->logSync($model, $action, 'success', 'Sync completed successfully');
            } else {
                event(new SyncFailed($model, $action, 'API request failed'));

                // Log failed sync
                $this->logSync($model, $action, 'failed', 'API request failed');
            }

            return $success;
        } catch (\Exception $e) {
            if (Config::get('syncable.logging.enabled', true)) {
                Log::channel(Config::get('syncable.logging.channel', 'stack'))
                    ->error('Sync failed: ' . $e->getMessage(), [
                        'model' => get_class($model),
                        'id' => $model->getKey(),
                        'action' => $action,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
            }

            event(new SyncFailed($model, $action, $e->getMessage()));

            // Log exception
            $this->logSync($model, $action, 'failed', $e->getMessage());

            return false;
        }
    }

    /**
     * Determine if a sync operation should be skipped to prevent infinite loops.
     *
     * @param Model $model
     * @param string|null $origin_system_id
     * @return bool
     */
    protected function shouldSkipSync(Model $model, ?string $origin_system_id): bool
    {
        // If we're handling a sync from another system
        if ($origin_system_id !== null) {
            // Skip if the change originated from our system
            if ($origin_system_id === Config::get('syncable.system_id', env('SYNCABLE_SYSTEM_ID'))) {
                return true;
            }

            // If bidirectional sync is enabled, check if this is a repeat sync
            if (Config::get('syncable.bidirectional.enabled', false)) {
                // Check if we've already processed this specific change
                return $this->syncAlreadyProcessed($model, $origin_system_id);
            }
        }

        // Skip sync based on selective sync rules if defined
        if (method_exists($model, 'shouldSync') && !$model->shouldSync()) {
            return true;
        }

        return false;
    }

    /**
     * Check if a sync has already been processed from a specific origin.
     *
     * @param Model $model
     * @param string $origin_system_id
     * @return bool
     */
    protected function syncAlreadyProcessed(Model $model, string $origin_system_id): bool
    {
        // Look for a recent successful sync log from this origin system
        return SyncLog::where('model_type', get_class($model))
            ->where('model_id', $model->getKey())
            ->where('status', 'success')
            ->where('data->origin_system_id', $origin_system_id)
            ->where('created_at', '>=', now()->subMinutes(
                Config::get('syncable.bidirectional.detection_window_minutes', 5)
            ))
            ->exists();
    }

    /**
     * Log sync operation to database.
     *
     * @param Model $model
     * @param string $action
     * @param string $status
     * @param string $message
     * @return void
     */
    protected function logSync(Model $model, string $action, string $status, string $message): void
    {
        if (!Config::get('syncable.logging.database_enabled', true)) {
            return;
        }

        SyncLog::create([
            'model_type' => get_class($model),
            'model_id' => $model->getKey(),
            'action' => $action,
            'status' => $status,
            'message' => $message,
            'data' => [
                'origin_system_id' => Config::get('syncable.system_id', env('SYNCABLE_SYSTEM_ID')),
                'fields' => $action === 'update' ? $model->getDirty() : []
            ],
            'tenant_id' => $this->tenantService->isEnabled() ? $this->tenantService->getCurrentTenantId() : null
        ]);
    }

    /**
     * Get the model's syncable configuration.
     *
     * @param Model $model
     * @return array
     */
    protected function getModelConfig(Model $model): array
    {
        $className = get_class($model);

        // Check if model is configured in the config file
        if (Config::has("syncable.models.{$className}")) {
            return Config::get("syncable.models.{$className}");
        }

        // Check if model has syncHandler method
        if (method_exists($model, 'syncHandler') && $model->syncHandler() !== null) {
            // Get config from the sync handler
            return $model->getSyncConfig();
        }
        
        // Check if model has syncable trait configuration
        if (method_exists($model, 'getSyncConfig')) {
            return $model->getSyncConfig();
        }

        // Default configuration
        return [
            'target_model' => $className,
            'fields' => [],
        ];
    }

    /**
     * Prepare the data for syncing.
     *
     * @param Model $model
     * @param array $config
     * @param string $action
     * @return array
     */
    protected function prepareData(Model $model, array $config, string $action): array
    {
        $data = [
            'action' => $action,
            'source_model' => get_class($model),
            'source_id' => $model->getKey(),
            'target_model' => $config['target_model'] ?? get_class($model),
            'data' => [],
            'additional' => [],
        ];

        // Process field mappings
        if (!empty($config['fields'])) {
            foreach ($config['fields'] as $sourceField => $targetField) {
                // Check if we're dealing with a dynamic value expression
                if(is_iterable($targetField)){
                    foreach($targetField as $key => $value){
                        $data['data'][$sourceField][$key] = $this->getValue($value, $model, $sourceField);
                    }
                }else{
                    $data['data'][$sourceField] = $this->getValue($targetField, $model, $sourceField);
                }
            }
        } else {
            // Otherwise use all model attributes
            $data['data'] = $model->toArray();
        }

        // Process additional data
        if (!empty($config['additional']) && is_array($config['additional'])) {
            foreach ($config['additional'] as $fieldKey => $fieldValue) {
                if (is_iterable($fieldValue)) {
                    foreach ($fieldValue as $nestedKey => $nestedValue) {
                        $data['additional'][$fieldKey][$nestedKey] = $this->getValue($nestedValue, $model, $nestedKey);
                    }
                } else {
                    $data['additional'][$fieldKey] = $this->getValue($fieldValue, $model, $fieldKey);
                }
            }
        }

        return $data;
    }

    /**
     * Handle the API response.
     *
     * @param mixed $response
     * @param Model $model
     * @param string $action
     * @return bool
     */
    protected function handleResponse($response, Model $model, string $action): bool
    {
        if (!$response) {
            return false;
        }

        if (is_array($response) && isset($response['success'])) {
            return (bool) $response['success'];
        }

        return true;
    }

    /**
     * Get the value of a field from a model.
     *
     * @param string $targetField
     * @param Model $model
     * @param string $sourceField
     * @return mixed
     */
    protected function getValue($targetField, $model, $sourceField)
    {
        // Check if we're dealing with a dynamic value expression
        if (is_string($targetField) && strpos($targetField, '$this->') === 0) {
            // This is a dynamic expression like '$this->name'
            $attribute = substr($targetField, 7); // Remove '$this->' prefix

            // Handle methods vs properties
            if (strpos($attribute, '()') !== false) {
                $method = str_replace('()', '', $attribute);
                if (method_exists($model, $method)) {
                    return $model->$method();
                }
            } else {
                // Access property or accessor
                return $model->$attribute;
            }
        } elseif (is_callable($targetField) && !is_string($targetField)) {
            // This is a callable function
            return call_user_func($targetField, $model);
        } else {
            // Traditional field mapping - get value from model
            $modelData = $model->toArray();
            if (array_key_exists($sourceField, $modelData)) {
                return $modelData[$sourceField];
            }
        }
    }

    /**
     * Handle incoming sync requests.
     *
     * @param array $data
     * @param string $action
     * @param string $originSystemId
     * @return array
     */
    public function handleIncomingSync(array $data, string $action, string $originSystemId): array
    {
        try {
            $targetModelClass = $data['target_model'];

            if(in_array($action, ['update', 'delete'])){
                // Save the original source ID before mapping for correct ID mapping later
                $data['original_source_id'] = $data['source_id'];
                
                $localIds = IdMapping::getLocalModelId(
                    $data['source_model'],
                    $data['source_id'],
                    $originSystemId
                );

                if ($localIds) {
                    $data['source_id'] = $localIds[1];
                }
            }

            // Process the sync based on action
            switch ($action) {
                case 'create':
                    return $this->processCreate($targetModelClass, $data, $originSystemId);

                case 'update':
                    return $this->processUpdate($targetModelClass, $data, $originSystemId);

                case 'delete':
                    return $this->processDelete($targetModelClass, $data, $originSystemId);

                default:
                    throw new \Exception("Unknown action: {$action}");
            }
        } catch (\Exception $e) {
            if (Config::get('syncable.logging.enabled', true)) {
                Log::channel(Config::get('syncable.logging.channel', 'stack'))
                    ->error('Incoming sync failed: ' . $e->getMessage(), [
                        'action' => $action,
                        'data' => $data,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
            }

            throw $e;
        }
    }

    /**
     * Process an incoming create request.
     *
     * @param string $targetModelClass
     * @param array $data
     * @param string $originSystemId
     * @return array
     */
    protected function processCreate(string $targetModelClass, array $data, string $originSystemId): array
    {
        // Create the model using the provided data
        $model = new $targetModelClass();

        foreach ($data['data'] as $key => $value) {
            $model->$key = $value;
        }

        // Disable sync temporarily to prevent loops
        if (method_exists($model, 'withoutSync')) {
            $model->withoutSync();
        }

        $model->save();

        // Process any additional data
        if (isset($data['additional']) && is_array($data['additional'])) {
            $this->processAdditionalData($model, $data['additional']);
        }

        // Store the ID mapping
        $tenantId = $this->tenantService->isEnabled() ? $this->tenantService->getCurrentTenantId() : null;

        IdMapping::createOrUpdateMapping(
            get_class($model),          // Local model type
            $model->getKey(),           // Local model ID
            $data['source_model'],      // Remote model type
            $data['source_id'],         // Remote model ID
            $originSystemId,            // Origin system ID
            $tenantId                   // Tenant ID
        );

        return [
            'success' => true,
            'message' => 'Model created successfully',
            'data' => [
                'id' => $model->getKey(),
                'model_type' => get_class($model),
            ],
        ];
    }

    /**
     * Process an incoming update request.
     *
     * @param string $targetModelClass
     * @param array $data
     * @param string $originSystemId
     * @return array
     */
    protected function processUpdate(string $targetModelClass, array $data, string $originSystemId): array
    {
        // Find the model to update
        $model = $targetModelClass::findOrFail($data['source_id']);

        // If differential sync is enabled, only update changed fields
        if (Config::get('syncable.differential_sync.enabled', true) && isset($data['changed_fields'])) {
            $updateData = array_intersect_key($data['data'], $data['changed_fields']);
        } else {
            $updateData = $data['data'];
        }

        // Check for conflicts if bidirectional sync is enabled
        if (
            Config::get('syncable.bidirectional.enabled', false) &&
            isset($data['origin_system_id']) &&
            isset($data['changed_fields'])
        ) {
            // Get the conflict resolution service to handle conflicts
            $conflictService = app(ConflictResolutionService::class);

            // Handle any conflicts between local and remote changes
            $updateData = $conflictService->resolveConflicts(
                $model,
                $updateData,
                $data['changed_fields'],
                $data['origin_system_id']
            );
        }

        foreach ($updateData as $key => $value) {
            $model->$key = $value;
        }

        // Disable sync temporarily to prevent loops
        if (method_exists($model, 'withoutSync')) {
            $model->withoutSync();
        }

        $model->save();

        // Process any additional data
        if (isset($data['additional']) && is_array($data['additional'])) {
            $this->processAdditionalData($model, $data['additional']);
        }

        // Update the ID mapping with the ORIGINAL remote ID, not the local one used for lookup
        $tenantId = $this->tenantService->isEnabled() ? $this->tenantService->getCurrentTenantId() : null;
        
        // Use the original source ID for mapping to prevent ID corruption
        $remoteId = isset($data['original_source_id']) ? $data['original_source_id'] : $data['source_id'];
        
        IdMapping::createOrUpdateMapping(
            get_class($model),          // Local model type
            $model->getKey(),           // Local model ID
            $data['source_model'],      // Remote model type
            $remoteId,                  // Remote model ID - critical to use original
            $originSystemId,            // Origin system ID
            $tenantId                   // Tenant ID
        );

        return [
            'success' => true,
            'message' => 'Model updated successfully',
            'data' => [
                'id' => $model->getKey(),
                'model_type' => get_class($model),
            ],
        ];
    }

    /**
     * Process an incoming delete request.
     *
     * @param string $targetModelClass
     * @param array $data
     * @param string $originSystemId
     * @return array
     */
    protected function processDelete(string $targetModelClass, array $data, string $originSystemId): array
    {
        // Find the model to delete
        $model = $targetModelClass::findOrFail($data['source_id']);

        // Disable sync temporarily to prevent loops
        if (method_exists($model, 'withoutSync')) {
            $model->withoutSync();
        }

        $model->delete();

        // Delete the ID mapping
        IdMapping::where('local_model_type', get_class($model))
            ->where('local_model_id', $model->getKey())
            ->where('system_id', $originSystemId)
            ->delete();

        return [
            'success' => true,
            'message' => 'Model deleted successfully',
            'data' => [
                'id' => $data['source_id'],
                'model_type' => $targetModelClass,
            ],
        ];
    }

    /**
     * Process additional data for a model.
     *
     * @param Model $model
     * @param array $additionalData
     * @return void
     */
    protected function processAdditionalData(Model $model, array $additionalData): void
    {
        // Check if model has a sync handler that can process this additional data
        $handlerProcessed = false;
        
        if (method_exists($model, 'syncHandler') && $handler = $model->syncHandler()) {
            // Try to process each piece of additional data with the handler
            foreach ($additionalData as $key => $data) {
                if ($handler->processAdditional($key, $data)) {
                    // If handler processed this key, mark it as processed
                    unset($additionalData[$key]);
                }
            }
        }
        
        // Process any remaining data using the traditional model handler methods
        foreach ($additionalData as $key => $data) {
            // Generate the handler method name: handleAdditionalContacts, handleAdditionalAddresses, etc.
            $handlerMethod = 'handleAdditional' . ucfirst($key);

            // If the model has a handler method for this additional data, call it
            if (method_exists($model, $handlerMethod)) {
                $model->$handlerMethod($data);
            }
        }
    }

    /**
     * Process a batch of sync operations.
     *
     * @param array $batch
     * @param string $originSystemId
     * @return array
     */
    public function batchSync(array $batch, string $originSystemId): array
    {
        $results = [];
        $success = true;

        if (!isset($batch['operations']) || !is_array($batch['operations'])) {
            throw new \InvalidArgumentException('Invalid batch format. "operations" array is required.');
        }

        foreach ($batch['operations'] as $index => $operation) {
            try {
                if (!isset($operation['action']) || !isset($operation['data'])) {
                    throw new \InvalidArgumentException('Each operation requires "action" and "data" fields.');
                }

                $action = $operation['action'];
                $data = $operation['data'];

                // Process the individual sync operation
                $opResult = $this->handleIncomingSync($data, $action, $originSystemId);
                $results[$index] = $opResult;

                if (!($opResult['success'] ?? false)) {
                    $success = false;
                }
            } catch (\Exception $e) {
                $results[$index] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                $success = false;

                // Log the error
                if (Config::get('syncable.logging.enabled', true)) {
                    Log::channel(Config::get('syncable.logging.channel', 'stack'))
                        ->error('Batch operation failed: ' . $e->getMessage(), [
                            'index' => $index,
                            'action' => $operation['action'] ?? 'unknown',
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                }
            }
        }

        return [
            'success' => $success,
            'message' => $success ? 'Batch processed successfully.' : 'Batch processed with errors.',
            'results' => $results
        ];
    }
}
