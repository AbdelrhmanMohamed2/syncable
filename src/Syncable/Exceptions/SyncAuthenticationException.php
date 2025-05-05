<?php

namespace Syncable\Exceptions;

class SyncAuthenticationException extends SyncException
{
    /**
     * Create a new SyncAuthenticationException instance.
     *
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(string $message = 'Sync authentication failed', int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
} 