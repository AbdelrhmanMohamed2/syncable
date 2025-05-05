<?php

namespace Syncable\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SyncSucceeded
{
    use Dispatchable, SerializesModels;

    /**
     * The model that was synced.
     *
     * @var Model
     */
    public $model;

    /**
     * The action that was performed.
     *
     * @var string
     */
    public $action;

    /**
     * Create a new event instance.
     *
     * @param Model $model
     * @param string $action
     */
    public function __construct(Model $model, string $action)
    {
        $this->model = $model;
        $this->action = $action;
    }
} 