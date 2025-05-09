<?php

namespace Syncable\Handlers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

abstract class SyncHandler
{
    /**
     * The model instance being synced.
     *
     * @var Model
     */
    protected $model;

    /**
     * Create a new sync handler instance.
     *
     * @param Model $model
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Get the target model class name for syncing.
     *
     * @return string
     */
    abstract public function getTargetModel(): string;

    /**
     * Get the field mapping for syncing.
     *
     * @return array
     */
    abstract public function getFieldMap(): array;

    /**
     * Get the syncable fields.
     *
     * @return array
     */
    public function getSyncableFields(): array
    {
        return [];
    }

    /**
     * Get the sync relations configuration.
     *
     * @return array
     */
    public function getSyncRelations(): array
    {
        return [];
    }

    /**
     * Get additional data to be synced.
     *
     * @return array
     */
    public function getAdditionalData(): array
    {
        return [];
    }

    /**
     * Get the sync conditions.
     *
     * @return array
     */
    public function getSyncConditions(): array
    {
        return [];
    }

    /**
     * Determine if the model should be synced.
     *
     * @return bool
     */
    public function shouldSync(): bool
    {
        $conditions = $this->getSyncConditions();
        
        // If no conditions are defined, sync by default
        if (empty($conditions)) {
            return true;
        }
        
        // Evaluate each condition
        foreach ($conditions as $field => $value) {
            // Handle array of possible values
            if (is_array($value)) {
                if (!in_array($this->model->$field, $value)) {
                    return false;
                }
            } 
            // Handle callback function
            elseif (is_callable($value)) {
                if (!call_user_func($value, $this->model)) {
                    return false;
                }
            }
            // Handle direct value comparison
            else {
                if ($this->model->$field != $value) {
                    return false;
                }
            }
        }
        
        return true;
    }

    /**
     * Get the conflict resolution strategy.
     *
     * @return string
     */
    public function getConflictResolutionStrategy(): string
    {
        return 'last_write_wins'; // Default strategy
    }

    /**
     * Get the complete sync configuration.
     *
     * @return array
     */
    public function getSyncConfig(): array
    {
        $config = [
            'target_model' => $this->getTargetModel(),
            'fields' => $this->getFieldMap(),
            'changed_fields' => $this->model->changedFields ?? [],
        ];

        // Add syncable fields to field map if not already present
        $syncableFields = $this->getSyncableFields();
        if (!empty($syncableFields)) {
            foreach ($syncableFields as $field) {
                if (!array_key_exists($field, $config['fields'])) {
                    $config['fields'][$field] = $field;
                }
            }
        }

        // Add relations configuration if defined
        $syncRelations = $this->getSyncRelations();
        if (!empty($syncRelations)) {
            $config['relations'] = $syncRelations;
        }

        // Add additional data if defined
        $additionalData = $this->getAdditionalData();
        if (!empty($additionalData)) {
            $config['additional'] = $additionalData;
        }

        return $config;
    }

    /**
     * Sync the model to the target application.
     *
     * @param string $action
     * @return bool
     */
    public function sync(string $action = 'update'): bool
    {
        $syncService = App::make('syncable');
        return $syncService->syncModel($this->model, $action);
    }
} 