<?php

namespace Syncable\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Syncable\Exceptions\SyncDecryptionException;

class EncryptionService
{
    /**
     * @var Encrypter
     */
    protected $encrypter;
    
    /**
     * @var bool
     */
    protected $serializeData;

    /**
     * Create a new EncryptionService instance.
     *
     * @param string|null $key
     * @param string $cipher
     * @param bool $serializeData
     */
    public function __construct(
        string $key = null, 
        string $cipher = 'AES-256-CBC',
        bool $serializeData = true
    ) {
        // If no key is provided, use the application key
        if (empty($key)) {
            $key = config('syncable.encryption.key') ?? config('app.key');
        }

        // Extract the base64 encoded key if it starts with 'base64:'
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        $this->encrypter = new Encrypter($key, $cipher);
        $this->serializeData = $serializeData;
    }

    /**
     * Encrypt the given data.
     *
     * @param mixed $data
     * @return string
     * @throws \Exception
     */
    public function encrypt($data): string
    {
        try {
            // Serialize complex data types if enabled
            if ($this->serializeData && (is_array($data) || is_object($data))) {
                $data = serialize($data);
                $serialized = true;
            } else {
                $serialized = false;
            }
            
            $encrypted = $this->encrypter->encrypt([
                'data' => $data,
                'serialized' => $serialized
            ]);
            
            return $encrypted;
        } catch (\Exception $e) {
            Log::error('Encryption failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Decrypt the given data.
     *
     * @param string $data
     * @return mixed
     * @throws SyncDecryptionException
     */
    public function decrypt(string $data)
    {
        try {
            $decrypted = $this->encrypter->decrypt($data);
            
            // Check if we have a structured encrypted data
            if (is_array($decrypted) && isset($decrypted['data']) && isset($decrypted['serialized'])) {
                $rawData = $decrypted['data'];
                $serialized = $decrypted['serialized'];
                
                // Unserialize if the data was serialized during encryption
                if ($serialized) {
                    return unserialize($rawData);
                }
                
                return $rawData;
            }
            
            // Legacy format handling
            return $decrypted;
        } catch (DecryptException $e) {
            if (Config::get('syncable.logging.enabled', true)) {
                Log::channel(Config::get('syncable.logging.channel', 'stack'))
                    ->error('Decryption failed: ' . $e->getMessage(), [
                        'trace' => $e->getTraceAsString()
                    ]);
            }
            throw new SyncDecryptionException('Failed to decrypt data: ' . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            if (Config::get('syncable.logging.enabled', true)) {
                Log::channel(Config::get('syncable.logging.channel', 'stack'))
                    ->error('Unexpected error during decryption: ' . $e->getMessage(), [
                        'trace' => $e->getTraceAsString()
                    ]);
            }
            throw new SyncDecryptionException('Unexpected error during decryption: ' . $e->getMessage(), 0, $e);
        }
    }
} 