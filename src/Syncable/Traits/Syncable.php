<?php

namespace Syncable\Traits;

use Illuminate\Support\Facades\App;
use Syncable\Events\ModelCreated;
use Syncable\Events\ModelDeleted;
use Syncable\Events\ModelUpdated;
use Syncable\Jobs\SyncModelJob;
use Illuminate\Support\Facades\Config;

trait Syncable
{
    /**
     * Flag to disable sync temporarily.
     *
     * @var bool
     */
    protected $syncDisabled = false;
    
    /**
     * The fields that were changed in this update.
     * 
     * @var array
     */
    protected $changedFields = [];

    /**
     * Target tenant ID to use when System A is not tenant-based but System B is.
     * This can be set dynamically before syncing a model.
     *
     * @var mixed
     */
    protected $target_tenant_id = null;

    /**
     * Boot the trait.
     *
     * @return void
     */
    public static function bootSyncable()
    {
        // Register model events to trigger sync
        if (Config::get('syncable.events.created', true)) {
            static::created(function ($model) {
                if ($model->syncDisabled) {
                    return;
                }
                
                static::queueSyncJob($model, 'create');
            });
        }

        if (Config::get('syncable.events.updated', true)) {
            static::updating(function ($model) {
                // Store which fields are changing for differential sync
                $model->changedFields = $model->getDirty();
                return true;
            });
            
            static::updated(function ($model) {
                // Skip sync if explicitly disabled for this operation
                if ($model->syncDisabled) {
                    return;
                }
                
                // Check if this model should be synced based on its current state
                if (method_exists($model, 'shouldSync') && !$model->shouldSync()) {
                    return;
                }
                
                static::queueSyncJob($model, 'update');
            });
        }

        if (Config::get('syncable.events.deleted', true)) {
            static::deleted(function ($model) {
                // Skip sync if explicitly disabled for this operation
                if ($model->syncDisabled) {
                    return;
                }
                
                // Check if this model should be synced based on its current state
                if (method_exists($model, 'shouldSync') && !$model->shouldSync()) {
                    return;
                }
                
                static::queueSyncJob($model, 'delete');
            });
        }
    }

    /**
     * Queue a sync job for the model.
     *
     * @param mixed $model
     * @param string $action
     * @return void
     */
    protected static function queueSyncJob($model, string $action)
    {
        $job = new SyncModelJob($model, $action);

        // Add delay if throttling is enabled
        if (Config::get('syncable.throttling.enabled', false)) {
            $delaySeconds = Config::get('syncable.throttling.delay_seconds', 0);
            if ($delaySeconds > 0) {
                $job->delay(now()->addSeconds($delaySeconds));
            }
        }

        if (Config::get('syncable.queue.enabled', true)) {
            $queue = Config::get('syncable.queue.connection', 'default');
            $queueName = Config::get('syncable.queue.queue', 'syncable');

            $job->onQueue($queueName)->onConnection($queue);
            dispatch($job);
        } else {
            dispatch_sync($job);
        }
    }

    /**
     * Get the syncable configuration for this model.
     *
     * @return array
     */
    public function getSyncConfig(): array
    {
        $config = [
            'target_model' => $this->syncTarget ?? static::class,
            'fields' => $this->syncMap ?? [],
            'changed_fields' => $this->changedFields ?? [],
        ];

        // Add relations configuration if defined
        if (isset($this->syncRelations) && is_array($this->syncRelations)) {
            $config['relations'] = $this->syncRelations;
        }

        // If specific attributes are defined for syncing, include them in the field map
        if (isset($this->syncable) && is_array($this->syncable)) {
            foreach ($this->syncable as $field) {
                if (!array_key_exists($field, $config['fields'])) {
                    $config['fields'][$field] = $field;
                }
            }
        }

        return $config;
    }

    /**
     * Determine if this model should be synced based on conditions.
     * This can be overridden in the model to implement custom logic.
     *
     * @return bool
     */
    public function shouldSync(): bool
    {
        // Check for global conditions in config
        $conditions = Config::get('syncable.selective_sync.conditions', []);
        
        // Check for model-specific conditions
        if (isset($this->syncConditions) && is_array($this->syncConditions)) {
            $conditions = array_merge($conditions, $this->syncConditions);
        }
        
        // If no conditions are defined, sync by default
        if (empty($conditions)) {
            return true;
        }
        
        // Evaluate each condition
        foreach ($conditions as $field => $value) {
            // Handle array of possible values
            if (is_array($value)) {
                if (!in_array($this->$field, $value)) {
                    return false;
                }
            } 
            // Handle callback function
            elseif (is_callable($value)) {
                if (!call_user_func($value, $this)) {
                    return false;
                }
            }
            // Handle direct value comparison
            else {
                if ($this->$field != $value) {
                    return false;
                }
            }
        }
        
        return true;
    }

    /**
     * Sync this model to the target application.
     *
     * @param string $action
     * @return bool
     */
    public function sync(string $action = 'update'): bool
    {
        $syncService = App::make('syncable');
        return $syncService->syncModel($this, $action);
    }
    
    /**
     * Temporarily disable sync for the next operation.
     *
     * @return $this
     */
    public function withoutSync()
    {
        $this->syncDisabled = true;
        return $this;
    }
    
    /**
     * Re-enable sync after it has been disabled.
     *
     * @return $this
     */
    public function withSync()
    {
        $this->syncDisabled = false;
        return $this;
    }
} 