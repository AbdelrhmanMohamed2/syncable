<?php

namespace Syncable\Models;

use Illuminate\Database\Eloquent\Model;

class SyncConflict extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'syncable_conflicts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'model_type',
        'model_id',
        'conflicts',
        'local_values',
        'remote_values',
        'origin_system_id',
        'status',
        'resolved_at',
        'resolved_by',
        'resolution_notes',
        'tenant_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'conflicts' => 'array',
        'local_values' => 'array',
        'remote_values' => 'array',
        'resolved_at' => 'datetime',
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
     * Scope a query to only include conflicts for a specific model.
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
     * Scope a query to only include pending conflicts.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include resolved conflicts.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeResolved($query)
    {
        return $query->where('status', 'resolved');
    }

    /**
     * Mark this conflict as resolved.
     *
     * @param string $resolvedBy
     * @param string|null $notes
     * @return bool
     */
    public function markAsResolved(string $resolvedBy, ?string $notes = null): bool
    {
        $this->status = 'resolved';
        $this->resolved_at = now();
        $this->resolved_by = $resolvedBy;
        
        if ($notes) {
            $this->resolution_notes = $notes;
        }

        return $this->save();
    }
} 