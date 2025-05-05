<?php

namespace Syncable\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SyncReceived
{
    use Dispatchable, SerializesModels;

    /**
     * The data that was received.
     *
     * @var array
     */
    public $data;

    /**
     * Create a new event instance.
     *
     * @param array $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }
} 