<?php

namespace Syncable\Exceptions;

class SyncValidationException extends SyncException
{
    /**
     * @var array
     */
    protected $errors;

    /**
     * Create a new SyncValidationException instance.
     *
     * @param string $message
     * @param array $errors
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(string $message = 'Sync data validation failed', array $errors = [], int $code = 0, \Throwable $previous = null)
    {
        $this->errors = $errors;
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the validation errors.
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
} 