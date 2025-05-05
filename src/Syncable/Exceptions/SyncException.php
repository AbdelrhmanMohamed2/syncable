<?php

namespace Syncable\Exceptions;

use Exception;

class SyncException extends Exception
{
    /**
     * Create a new SyncException instance.
     *
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(string $message = 'Sync operation failed', int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
} 