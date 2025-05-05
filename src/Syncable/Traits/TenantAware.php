<?php

namespace Syncable\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
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
                    $query->where($column, $tenantId);
                }
            }
        });

        static::creating(function ($model) {
            $tenantService = app(TenantService::class);
            if ($tenantService->isEnabled()) {
                $tenantId = $tenantService->getCurrentTenantId();
                if ($tenantId) {
                    $column = Config::get('syncable.tenancy.identifier_column', 'tenant_id');
                    if (!isset($model->{$column})) {
                        $model->{$column} = $tenantId;
                    }
                }
            }
        });
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
} 