<?php

namespace Syncable\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Syncable\Models\SyncLog;

class ConflictResolutionService
{
    /**
     * The default conflict resolution strategy.
     * 
     * @var string
     */
    protected $defaultStrategy;
    
    /**
     * Create a new ConflictResolutionService instance.
     */
    public function __construct()
    {
        $this->defaultStrategy = Config::get('syncable.conflict_resolution.strategy', 'last_write_wins');
    }
    
    /**
     * Resolve conflicts between local and remote changes.
     *
     * @param Model $localModel The local model instance
     * @param array $remoteData The incoming remote data
     * @param array $changedFields Fields that were changed in the remote system
     * @param string $originSystemId The ID of the system that sent the data
     * @return array The resolved data to apply
     */
    public function resolveConflicts(Model $localModel, array $remoteData, array $changedFields, string $originSystemId): array
    {
        // Get the strategy to use (global or model-specific)
        $strategy = $this->getResolutionStrategy($localModel);
        
        // Find conflicts by determining which fields were modified both locally and remotely
        $localChanges = $this->getLocalChanges($localModel, $originSystemId);
        $conflicts = $this->findConflicts($localChanges, $changedFields);
        
        if (empty($conflicts)) {
            // No conflicts, just return the remote data
            return $remoteData;
        }
        
        // Log the conflicts if enabled
        if (Config::get('syncable.logging.enabled', true)) {
            Log::channel(Config::get('syncable.logging.channel', 'stack'))
                ->info('Conflict detected during sync', [
                    'model' => get_class($localModel),
                    'id' => $localModel->getKey(),
                    'conflicts' => $conflicts,
                    'strategy' => $strategy,
                    'origin_system' => $originSystemId
                ]);
        }
        
        // Resolve each conflict based on the selected strategy
        $resolvedData = $remoteData;
        foreach ($conflicts as $field) {
            $resolvedData[$field] = $this->resolveFieldConflict(
                $strategy, 
                $field, 
                $localModel->$field, 
                $remoteData[$field], 
                $localModel->getOriginal($field)
            );
        }
        
        // If strategy is set to manual, flag this sync for manual review
        if ($strategy === 'manual') {
            $this->flagForManualReview($localModel, $conflicts, $remoteData, $localChanges, $originSystemId);
        }
        
        return $resolvedData;
    }
    
    /**
     * Get the resolution strategy for a specific model.
     *
     * @param Model $model
     * @return string
     */
    protected function getResolutionStrategy(Model $model): string
    {
        // Check if the model defines its own conflict resolution strategy
        if (method_exists($model, 'getConflictResolutionStrategy')) {
            return $model->getConflictResolutionStrategy();
        }
        
        // Check if there's a model-specific strategy in config
        $className = get_class($model);
        $modelSpecificStrategy = Config::get("syncable.conflict_resolution.models.{$className}");
        
        return $modelSpecificStrategy ?? $this->defaultStrategy;
    }
    
    /**
     * Get recent local changes for the model since last sync with the origin system.
     *
     * @param Model $model
     * @param string $originSystemId
     * @return array
     */
    protected function getLocalChanges(Model $model, string $originSystemId): array
    {
        // If model is clean, there are no local changes
        if (!$model->isDirty()) {
            return [];
        }
        
        // Find the last successful sync from this system
        $lastSync = SyncLog::where('model_type', get_class($model))
            ->where('model_id', $model->getKey())
            ->where('status', 'success')
            ->where('data->origin_system_id', $originSystemId)
            ->orderBy('created_at', 'desc')
            ->first();
        
        // If no previous sync found, consider all changes as local changes
        if (!$lastSync) {
            return $model->getDirty();
        }
        
        // Get changes since last sync from this system
        $originalValues = $model->getOriginal();
        $dirty = $model->getDirty();
        
        // Filter out changes that haven't happened since the last sync
        $syncTime = $lastSync->created_at;
        $recentChanges = [];
        
        foreach ($dirty as $field => $value) {
            // Check if field was updated after the last sync
            // This is a simplified approach, in a real implementation you would need
            // to track when each field was modified
            $recentChanges[$field] = $value;
        }
        
        return $recentChanges;
    }
    
    /**
     * Find fields that have conflicts between local and remote changes.
     *
     * @param array $localChanges
     * @param array $remoteChanges
     * @return array
     */
    protected function findConflicts(array $localChanges, array $remoteChanges): array
    {
        // Fields that were changed in both systems
        return array_keys(array_intersect_key($localChanges, $remoteChanges));
    }
    
    /**
     * Resolve a conflict for a specific field based on the strategy.
     *
     * @param string $strategy
     * @param string $field
     * @param mixed $localValue
     * @param mixed $remoteValue
     * @param mixed $originalValue
     * @return mixed
     */
    protected function resolveFieldConflict(string $strategy, string $field, $localValue, $remoteValue, $originalValue)
    {
        switch ($strategy) {
            case 'last_write_wins':
                // Remote changes always win in this strategy
                return $remoteValue;
                
            case 'local_wins':
                // Local changes always win
                return $localValue;
                
            case 'remote_wins':
                // Remote changes always win (same as last_write_wins)
                return $remoteValue;
                
            case 'merge':
                // Try to merge the values (this is a simplified implementation)
                if (is_array($localValue) && is_array($remoteValue)) {
                    return array_merge($localValue, $remoteValue);
                } elseif (is_string($localValue) && is_string($remoteValue)) {
                    // For strings, we'd need more context for a proper merge
                    // This is just a simplified approach
                    if ($localValue === $remoteValue) {
                        return $localValue;
                    } else {
                        // Fallback to custom merge function if available
                        if (function_exists('syncable_merge_strings')) {
                            return syncable_merge_strings($field, $localValue, $remoteValue, $originalValue);
                        }
                        // Otherwise default to remote value
                        return $remoteValue;
                    }
                } else {
                    // For other types, we default to the remote value
                    return $remoteValue;
                }
                
            case 'manual':
                // For manual, we'll flag it but still need to return a value for now
                // Default to keeping the local value until manual resolution
                return $localValue;
                
            default:
                // Unknown strategy, default to remote value
                return $remoteValue;
        }
    }
    
    /**
     * Flag a conflict for manual review.
     *
     * @param Model $model
     * @param array $conflicts
     * @param array $remoteData
     * @param array $localChanges
     * @param string $originSystemId
     * @return void
     */
    protected function flagForManualReview(Model $model, array $conflicts, array $remoteData, array $localChanges, string $originSystemId): void
    {
        // Create a conflict record in the database
        // This would require a separate ConflictModel that would be reviewed by users
        // Check if the model is set up to be flagged for manual review
        if (Config::get('syncable.conflict_resolution.store_conflicts', true)) {
            // You would create a record in a conflicts table here
            // Example:
            if (class_exists('Syncable\\Models\\SyncConflict')) {
                $conflictData = [
                    'model_type' => get_class($model),
                    'model_id' => $model->getKey(),
                    'conflicts' => $conflicts,
                    'local_values' => array_intersect_key($localChanges, array_flip($conflicts)),
                    'remote_values' => array_intersect_key($remoteData, array_flip($conflicts)),
                    'origin_system_id' => $originSystemId,
                    'status' => 'pending',
                ];
                
                // Create conflict record
                \Syncable\Models\SyncConflict::create($conflictData);
            }
        }
    }
} 