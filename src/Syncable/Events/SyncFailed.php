<?php

namespace Syncable\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SyncFailed
{
    use Dispatchable, SerializesModels;

    /**
     * The model that failed to sync.
     *
     * @var Model
     */
    public $model;

    /**
     * The action that was attempted.
     *
     * @var string
     */
    public $action;

    /**
     * The reason for the failure.
     *
     * @var string
     */
    public $reason;

    /**
     * Create a new event instance.
     *
     * @param Model $model
     * @param string $action
     * @param string $reason
     */
    public function __construct(Model $model, string $action, string $reason)
    {
        $this->model = $model;
        $this->action = $action;
        $this->reason = $reason;
    }
} 