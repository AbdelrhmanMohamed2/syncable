<?php

namespace Syncable\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Syncable\Services\TenantService;

trait TenantAware
{
    /**
     * Boot the trait.
     *
     * @return void
     */
    protected static function bootTenantAware()
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $tenantService = app(TenantService::class);
            if ($tenantService->isEnabled()) {
                $tenantId = $tenantService->getCurrentTenantId();
                if ($tenantId) {
                    $column = Config::get('syncable.tenancy.identifier_column', 'tenant_id');
                    
                    // Check if the tenant column exists in the model's table
                    $model = $query->getModel();
                    if (self::hasTenantColumn($model, $column)) {
                        $query->where($column, $tenantId);
                    }
                }
            }
        });

        static::creating(function ($model) {
            $tenantService = app(TenantService::class);
            if ($tenantService->isEnabled()) {
                $tenantId = $tenantService->getCurrentTenantId();
                if ($tenantId) {
                    $column = Config::get('syncable.tenancy.identifier_column', 'tenant_id');
                    
                    // Only set the tenant ID if the column exists in the model's table
                    if (self::hasTenantColumn($model, $column) && !isset($model->{$column})) {
                        $model->{$column} = $tenantId;
                    }
                }
            }
        });
    }

    /**
     * Check if the tenant column exists in the model's table.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param string $column
     * @return bool
     */
    protected static function hasTenantColumn($model, string $column): bool
    {
        // Try to determine if the column exists
        try {
            // Check if the column exists in the model's fillable array
            if (in_array($column, $model->getFillable())) {
                return true;
            }
            
            // Check if the column exists in the actual database table
            $schema = DB::getSchemaBuilder();
            if ($schema->hasColumn($model->getTable(), $column)) {
                return true;
            }
            
            // Check if the attribute exists on the model (could be a dynamic property)
            return array_key_exists($column, $model->getAttributes());
            
        } catch (\Exception $e) {
            // If there's any exception, assume the column doesn't exist
            return false;
        }
    }

    /**
     * Get the tenant ID for this model.
     *
     * @return mixed|null
     */
    public function getTenantId()
    {
        $column = Config::get('syncable.tenancy.identifier_column', 'tenant_id');
        return $this->{$column};
    }

    /**
     * Check if this model belongs to the given tenant.
     *
     * @param mixed $tenantId
     * @return bool
     */
    public function belongsToTenant($tenantId): bool
    {
        $column = Config::get('syncable.tenancy.identifier_column', 'tenant_id');
        return $this->{$column} == $tenantId;
    }

    /**
     * Scope a query to a specific tenant.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  mixed  $tenantId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTenant(Builder $query, $tenantId)
    {
        $column = Config::get('syncable.tenancy.identifier_column', 'tenant_id');
        return $query->where($column, $tenantId);
    }

    /**
     * Get the tenant ID to use for the target system when syncing.
     * This can be overridden in models to provide custom logic for determining the target tenant.
     *
     * @return mixed|null
     */
    public function getTargetTenantId()
    {
        // By default, use the same tenant ID as the current model
        return $this->getTenantId();
    }
} 