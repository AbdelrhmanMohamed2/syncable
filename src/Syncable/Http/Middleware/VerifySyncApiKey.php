<?php

namespace Syncable\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Syncable\Exceptions\SyncAuthenticationException;
use Syncable\Exceptions\SyncDecryptionException;
use Syncable\Services\EncryptionService;

class VerifySyncApiKey
{
    /**
     * The encryption service.
     *
     * @var EncryptionService
     */
    protected $encryptionService;

    /**
     * Create a new middleware instance.
     *
     * @param EncryptionService $encryptionService
     */
    public function __construct(EncryptionService $encryptionService)
    {
        $this->encryptionService = $encryptionService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     * @throws SyncAuthenticationException
     */
    public function handle(Request $request, Closure $next)
    {
        $apiKey = Config::get('syncable.api.key');
        
        if (empty($apiKey)) {
            throw new SyncAuthenticationException('API key not configured.');
        }

        // Get the API key from the header
        $requestApiKey = $request->header('X-SYNCABLE-API-KEY');
        
        if (empty($requestApiKey)) {
            throw new SyncAuthenticationException('API key is required.');
        }
        
        // Check if the sender indicated whether the key is encrypted
        $isEncrypted = $request->header('X-SYNCABLE-API-KEY-ENCRYPTED', 'true') === 'true';
        
        if ($isEncrypted) {
            try {
                // Decrypt the received API key
                $decryptedApiKey = $this->encryptionService->decrypt($requestApiKey);
                
                if ($decryptedApiKey === null) {
                    throw new SyncDecryptionException('API key decryption returned null');
                }
                
                // Compare with the configured API key
                if ($decryptedApiKey !== $apiKey) {
                    throw new SyncAuthenticationException('Invalid API key.');
                }
            } catch (SyncDecryptionException $e) {
                throw new SyncAuthenticationException('Invalid encrypted API key format: ' . $e->getMessage());
            } catch (\Exception $e) {
                throw new SyncAuthenticationException('API key validation failed: ' . $e->getMessage());
            }
        } else {
            // Direct comparison for unencrypted keys
            if ($requestApiKey !== $apiKey) {
                throw new SyncAuthenticationException('Invalid API key.');
            }
        }

        return $next($request);
    }
} 