<?php

namespace Syncable\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class SyncModelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The model to be synced.
     *
     * @var Model
     */
    protected $model;

    /**
     * The action to perform (create, update, delete).
     *
     * @var string
     */
    protected $action;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries;

    /**
     * Create a new job instance.
     *
     * @param Model $model
     * @param string $action
     */
    public function __construct(Model $model, string $action)
    {
        $this->model = $model;
        $this->action = $action;
        $this->tries = Config::get('syncable.api.retry_attempts', 3);
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $syncService = App::make('syncable');
            $result = $syncService->syncModel($this->model, $this->action);

            if (!$result && Config::get('syncable.logging.enabled', true)) {
                Log::channel(Config::get('syncable.logging.channel', 'stack'))
                    ->warning('Sync job failed', [
                        'model' => get_class($this->model),
                        'id' => $this->model->getKey(),
                        'action' => $this->action,
                    ]);
            }
        } catch (\Exception $e) {
            if (Config::get('syncable.logging.enabled', true)) {
                Log::channel(Config::get('syncable.logging.channel', 'stack'))
                    ->error('Sync job exception: ' . $e->getMessage(), [
                        'model' => get_class($this->model),
                        'id' => $this->model->getKey(),
                        'action' => $this->action,
                        'error' => $e->getMessage(),
                    ]);
            }

            // Determine if we should retry the job
            if ($this->attempts() < $this->tries) {
                $this->release(Config::get('syncable.api.retry_delay', 5));
            }
            
            throw $e;
        }
    }
} 