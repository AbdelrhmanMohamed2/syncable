<?php

namespace Syncable\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Syncable\Events\SyncReceived;
use Syncable\Exceptions\SyncDecryptionException;
use Syncable\Exceptions\SyncValidationException;
use Syncable\Exceptions\SyncAuthenticationException;
use Syncable\Services\EncryptionService;
use Syncable\Services\TenantService;
use Syncable\Services\ConflictResolutionService;
use Syncable\Services\SyncService;

class SyncableController extends Controller
{
    /**
     * @var EncryptionService
     */
    protected $encryptionService;

    /**
     * @var TenantService
     */
    protected $tenantService;
    
    /**
     * @var SyncService
     */
    protected $syncService;
    
    /**
     * @var ConflictResolutionService
     */
    protected $conflictService;

    /**
     * Create a new controller instance.
     *
     * @param EncryptionService $encryptionService
     * @param TenantService $tenantService
     * @param ConflictResolutionService $conflictService
     * @param SyncService $syncService
     */
    public function __construct(
        EncryptionService $encryptionService, 
        TenantService $tenantService,
        ConflictResolutionService $conflictService,
        SyncService $syncService
    ) {
        $this->encryptionService = $encryptionService;
        $this->tenantService = $tenantService;
        $this->syncService = $syncService;
        $this->conflictService = $conflictService;
    }

    /**
     * Handle an incoming sync request.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sync(Request $request)
    {
        try {
            $data = $this->getDecryptedData($request);
            
            // Dispatch a generic sync event with the received data
            event(new SyncReceived($data));
            
            return response()->json([
                'success' => true,
                'message' => 'Data received successfully.'
            ]);
        } catch (SyncDecryptionException $e) {
            return $this->handleSyncException($e, 400);
        } catch (SyncValidationException $e) {
            return $this->handleSyncException($e, 422);
        } catch (SyncAuthenticationException $e) {
            return $this->handleSyncException($e, 401);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Handle an incoming create request.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function create(Request $request)
    {
        try {           
            // Get origin system ID if present
            $originSystemId = $request->input('origin_system_id', 'unknown');
            
            // Decrypt data if encrypted
            $data = $this->getDecryptedData($request);
            
            // Process the sync using the service
            $response = $this->syncService->handleIncomingSync($data, 'create', $originSystemId);
            
            return response()->json($response);
        } catch (SyncDecryptionException $e) {
            return $this->handleSyncException($e, 400);
        } catch (SyncValidationException $e) {
            return $this->handleSyncException($e, 422);
        } catch (SyncAuthenticationException $e) {
            return $this->handleSyncException($e, 401);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Handle an incoming update request.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        try {         
            // Get origin system ID if present
            $originSystemId = $request->input('origin_system_id', 'unknown');
            
            // Decrypt data if encrypted
            $data = $this->getDecryptedData($request);
            
            // Check for conflicts if relevant
            if ($this->conflictService && Config::get('syncable.conflict_resolution.enabled', true)) {
                $data = $this->conflictService->checkForConflicts($data, 'update');
            }
            
            // Process the sync using the service
            $response = $this->syncService->handleIncomingSync($data, 'update', $originSystemId);
            
            return response()->json($response);
        } catch (SyncDecryptionException $e) {
            return $this->handleSyncException($e, 400);
        } catch (SyncValidationException $e) {
            return $this->handleSyncException($e, 422);
        } catch (SyncAuthenticationException $e) {
            return $this->handleSyncException($e, 401);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Handle an incoming batch sync request.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchSync(Request $request)
    {
        try {
            $batch = $this->getDecryptedData($request);
            $originSystemId = $request->input('origin_system_id', 'unknown');
            
            // Use the SyncService for batch processing
            $result = $this->syncService->batchSync($batch, $originSystemId);
            
            return response()->json($result);
        } catch (SyncDecryptionException $e) {
            return $this->handleSyncException($e, 400);
        } catch (SyncValidationException $e) {
            return $this->handleSyncException($e, 422);
        } catch (SyncAuthenticationException $e) {
            return $this->handleSyncException($e, 401);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Handle an incoming delete request.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete(Request $request)
    {
        try {            
            // Get origin system ID if present
            $originSystemId = $request->input('origin_system_id', 'unknown');
            
            // Decrypt data if encrypted
            $data = $this->getDecryptedData($request);
            
            // Process the sync using the service
            $response = $this->syncService->handleIncomingSync($data, 'delete', $originSystemId);
            
            return response()->json($response);
        } catch (SyncDecryptionException $e) {
            return $this->handleSyncException($e, 400);
        } catch (SyncValidationException $e) {
            return $this->handleSyncException($e, 422);
        } catch (SyncAuthenticationException $e) {
            return $this->handleSyncException($e, 401);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Get decrypted data from the request.
     *
     * @param Request $request
     * @return array
     * @throws SyncDecryptionException
     */
    protected function getDecryptedData(Request $request): array
    {
        $data = $request->all();
        
        // Decrypt data if it's encrypted
        if (Config::get('syncable.encryption.enabled', true) && isset($data['encrypted']) && $data['encrypted']) {
            $decrypted = $this->encryptionService->decrypt($data['data']);
            
            if ($decrypted === null) {
                throw new SyncDecryptionException('Failed to decrypt data from request');
            }
            
            $data = $decrypted;
        }
        
        // Check for target tenant ID - this is used to initialize tenant in System B
        // without affecting logs (separated from regular tenant handling)
        if (isset($data['target_tenant_id'])) {
            $targetTenantId = $data['target_tenant_id'];
            
            // Only initialize tenant if tenancy is enabled on this system
            if ($this->tenantService->isEnabled()) {
                // Initialize the tenant but don't log tenant changes
                $this->tenantService->initializeTenant($targetTenantId, true);
            }
            
            // Remove the target_tenant_id so it doesn't get processed as part of regular data
            unset($data['target_tenant_id']);
        }
        
        // Handle tenant if needed
        if ($this->tenantService->isEnabled() && isset($data['tenant_id'])) {
            $this->tenantService->setCurrentTenant($data['tenant_id']);
        }
        
        return $data;
    }

    /**
     * Handle a specific sync exception and return a response.
     *
     * @param \Exception $e
     * @param int $statusCode
     * @return \Illuminate\Http\JsonResponse
     */
    protected function handleSyncException(\Exception $e, int $statusCode): \Illuminate\Http\JsonResponse
    {
        // Log the error
        if (Config::get('syncable.logging.enabled', true)) {
            Log::channel(Config::get('syncable.logging.channel', 'stack'))
                ->error('Sync request error: ' . $e->getMessage(), [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'status_code' => $statusCode,
                ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'error_type' => class_basename($e),
        ], $statusCode);
    }

    /**
     * Handle a general exception and return a response.
     *
     * @param \Exception $e
     * @return \Illuminate\Http\JsonResponse
     */
    protected function handleException(\Exception $e): \Illuminate\Http\JsonResponse
    {
        // Log the error
        if (Config::get('syncable.logging.enabled', true)) {
            Log::channel(Config::get('syncable.logging.channel', 'stack'))
                ->error('Sync request error: ' . $e->getMessage(), [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to process request: ' . $e->getMessage(),
            'error_type' => class_basename($e),
        ], 500);
    }
} 