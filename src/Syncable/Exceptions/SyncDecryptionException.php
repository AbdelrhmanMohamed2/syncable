<?php

namespace Syncable\Exceptions;

class SyncDecryptionException extends SyncException
{
    /**
     * Create a new SyncDecryptionException instance.
     *
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(string $message = 'Failed to decrypt sync data', int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
} 