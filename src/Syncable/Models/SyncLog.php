<?php

namespace Syncable\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

class SyncLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'syncable_logs';

    /**
     * Get the database connection name to use.
     *
     * @return string
     */
    public function getConnectionName()
    {
        return Config::get('syncable.database.central_connection', 'central');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'model_type',
        'model_id',
        'action',
        'status',
        'message',
        'data',
        'tenant_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'data' => 'array',
    ];

    /**
     * Get the parent model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function model()
    {
        return $this->morphTo();
    }

    /**
     * Scope a query to only include logs for a specific model.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param Model $model
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForModel($query, Model $model)
    {
        return $query->where('model_type', get_class($model))
                     ->where('model_id', $model->getKey());
    }

    /**
     * Scope a query to only include logs with a specific status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include logs for a specific tenant.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $tenantId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
} 