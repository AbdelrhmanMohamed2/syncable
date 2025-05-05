<?php

namespace Syncable\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Syncable\Exceptions\SyncAuthenticationException;
use Syncable\Exceptions\SyncException;

class ApiService
{
    /**
     * @var string
     */
    protected $baseUrl;

    /**
     * @var string
     */
    protected $apiKey;

    /**
     * @var int
     */
    protected $timeout;

    /**
     * @var int
     */
    protected $retryAttempts;

    /**
     * @var int
     */
    protected $retryDelay;
    
    /**
     * @var EncryptionService
     */
    protected $encryptionService;
    
    /**
     * @var bool
     */
    protected $encryptApiKey;

    /**
     * Create a new ApiService instance.
     *
     * @param string $baseUrl
     * @param string $apiKey
     * @param int $timeout
     * @param EncryptionService|null $encryptionService
     * @param bool|null $encryptApiKey
     */
    public function __construct(
        string $baseUrl, 
        string $apiKey, 
        int $timeout = 30,
        EncryptionService $encryptionService = null,
        bool $encryptApiKey = null
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
        $this->retryAttempts = Config::get('syncable.api.retry_attempts', 3);
        $this->retryDelay = Config::get('syncable.api.retry_delay', 5);
        $this->encryptionService = $encryptionService ?? app(EncryptionService::class);
        $this->encryptApiKey = $encryptApiKey ?? Config::get('syncable.api.encrypt_key', true);
    }

    /**
     * Send a sync request to the target application.
     *
     * @param array $data
     * @param string $action
     * @return mixed
     * @throws SyncException
     */
    public function sendRequest(array $data, string $action)
    {
        $endpoint = $this->getEndpointForAction($action);
        $url = "{$this->baseUrl}{$endpoint}";
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        try {
            // Prepare API key for the header
            if ($this->encryptApiKey) {
                try {
                    $apiKeyForHeader = $this->encryptionService->encrypt($this->apiKey);
                    $headers['X-SYNCABLE-API-KEY'] = $apiKeyForHeader;
                } catch (\Exception $e) {
                    // If encryption fails, fall back to plain API key with a warning
                    Log::warning('Failed to encrypt API key, using plaintext: ' . $e->getMessage());
                    $headers['X-SYNCABLE-API-KEY'] = $this->apiKey;
                }
            } else {
                // Use plaintext API key if encryption is disabled
                $headers['X-SYNCABLE-API-KEY'] = $this->apiKey;
            }
            
            // Add documentation in the header to indicate whether the key is encrypted
            $headers['X-SYNCABLE-API-KEY-ENCRYPTED'] = $this->encryptApiKey ? 'true' : 'false';
            
            $response = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->retry($this->retryAttempts, $this->retryDelay * 1000, function ($exception, $request) {
                    // Only retry on connection errors, not on 4xx client errors
                    return $exception instanceof \Illuminate\Http\Client\ConnectionException ||
                           ($exception instanceof \Illuminate\Http\Client\RequestException && 
                            $exception->response->status() >= 500);
                })
                ->post($url, $data);

            if ($response->successful()) {
                return $response->json();
            } elseif ($response->status() === 401) {
                throw new SyncAuthenticationException(
                    'API authentication failed: ' . $response->body(), 
                    $response->status()
                );
            } elseif ($response->clientError()) {
                throw new SyncException(
                    'API request failed with client error: ' . $response->reason(), 
                    $response->status()
                );
            } elseif ($response->serverError()) {
                throw new SyncException(
                    'API request failed with server error: ' . $response->reason(), 
                    $response->status()
                );
            }

            // Log error details
            if (Config::get('syncable.logging.enabled', true)) {
                Log::channel(Config::get('syncable.logging.channel', 'stack'))
                    ->error('Sync API request failed', [
                        'status' => $response->status(),
                        'reason' => $response->reason(),
                        'body' => $response->body(),
                        'action' => $action,
                    ]);
            }

            return ['success' => false, 'message' => 'API request failed: ' . $response->reason()];
        } catch (SyncAuthenticationException | SyncException $e) {
            // Re-throw these exceptions as they're already properly formatted
            throw $e;
        } catch (\Exception $e) {
            // Log exception details
            if (Config::get('syncable.logging.enabled', true)) {
                Log::channel(Config::get('syncable.logging.channel', 'stack'))
                    ->error('Sync API request exception', [
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'action' => $action,
                    ]);
            }

            throw new SyncException('API request exception: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the endpoint for the given action.
     *
     * @param string $action
     * @return string
     */
    protected function getEndpointForAction(string $action): string
    {
        switch ($action) {
            case 'create':
                return '/api/syncable/create';
            case 'update':
                return '/api/syncable/update';
            case 'delete':
                return '/api/syncable/delete';
            case 'batch':
                return '/api/syncable/batch';
            default:
                return '/api/syncable';
        }
    }
} 