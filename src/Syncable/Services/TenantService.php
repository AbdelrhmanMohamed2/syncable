<?php

namespace Syncable\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;

class TenantService
{
    /**
     * @var bool
     */
    protected $enabled;

    /**
     * @var string
     */
    protected $identifierColumn;

    /**
     * @var string|null
     */
    protected $connectionResolver;
    
    /**
     * @var string|null
     */
    protected $currentTenantId = null;

    /**
     * Create a new TenantService instance.
     *
     * @param bool $enabled
     * @param string $identifierColumn
     * @param string|null $connectionResolver
     */
    public function __construct(
        bool $enabled = false, 
        string $identifierColumn = 'tenant_id',
        ?string $connectionResolver = null
    ) {
        $this->enabled = $enabled;
        $this->identifierColumn = $identifierColumn;
        $this->connectionResolver = $connectionResolver;
    }

    /**
     * Check if tenancy is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
    
    /**
     * Set the current tenant ID.
     *
     * @param string|int $tenantId
     * @return void
     */
    public function setCurrentTenant($tenantId): void
    {
        $this->currentTenantId = $tenantId;
        
        // Set the tenant ID in the request scope for cross-request access
        if (App::bound('request')) {
            App::make('request')->attributes->set('tenant_id', $tenantId);
        }
    }

    /**
     * Get the current tenant ID.
     *
     * @return mixed|null
     */
    public function getCurrentTenantId()
    {
        if (!$this->enabled) {
            return null;
        }
        
        // First check if we have an explicitly set tenant ID
        if ($this->currentTenantId !== null) {
            return $this->currentTenantId;
        }
        
        // Then check if it's in the request
        if (App::bound('request') && App::make('request')->attributes->has('tenant_id')) {
            return App::make('request')->attributes->get('tenant_id');
        }

        // If a connection resolver is provided, use it to get the tenant ID
        if ($this->connectionResolver && is_callable($this->connectionResolver)) {
            return call_user_func($this->connectionResolver);
        }

        // Try to get the tenant ID from common tenant packages
        return $this->detectTenantId();
    }

    /**
     * Attach tenant data to the sync data.
     *
     * @param array $data
     * @param Model $model
     * @return array
     */
    public function attachTenantData(array $data, Model $model): array
    {
        if (!$this->enabled) {
            return $data;
        }

        $tenantId = $this->getCurrentTenantId();
        
        if ($tenantId) {
            $data['tenant_id'] = $tenantId;
            
            // If the model has a tenant ID column, include it
            if (property_exists($model, $this->identifierColumn) || 
                array_key_exists($this->identifierColumn, $model->getAttributes())) {
                $data['data'][$this->identifierColumn] = $model->{$this->identifierColumn};
            }
        }

        return $data;
    }
    
    /**
     * Apply tenant scope to a query.
     *
     * @param Builder $query
     * @param string|null $tenantId
     * @return Builder
     */
    public function applyTenantScope(Builder $query, $tenantId = null): Builder
    {
        if (!$this->enabled) {
            return $query;
        }
        
        $tenantId = $tenantId ?? $this->getCurrentTenantId();
        
        if ($tenantId) {
            return $query->where($this->identifierColumn, $tenantId);
        }
        
        return $query;
    }

    /**
     * Detect the tenant ID from common tenant packages.
     *
     * @return mixed|null
     */
    protected function detectTenantId()
    {
        // Try stancl/tenancy
        if (class_exists('Stancl\Tenancy\Tenancy')) {
            $tenancy = app('Stancl\Tenancy\Tenancy');
            // Get the tenant either via getTenant or the tenant() method
            if (method_exists($tenancy, 'getTenant') && $tenancy->getTenant()) {
                return method_exists($tenancy->getTenant(), 'getTenantKey') 
                    ? $tenancy->getTenant()->getTenantKey() 
                    : $tenancy->getTenant()->getId();
            } else if (method_exists($tenancy, 'tenant') && $tenancy->tenant()) {
                return method_exists($tenancy->tenant(), 'getTenantKey') 
                    ? $tenancy->tenant()->getTenantKey() 
                    : $tenancy->tenant()->id;
            }
        }

        // Try spatie/laravel-multitenancy
        if (class_exists('Spatie\Multitenancy\Models\Tenant')) {
            $tenant = app('Spatie\Multitenancy\Models\Tenant')::current();
            if ($tenant) {
                return $tenant->id;
            }
        }

        // Try to get tenant ID from the current database connection
        try {
            $connection = DB::connection()->getName();
            if (preg_match('/tenant_(\d+)/', $connection, $matches)) {
                return $matches[1];
            }
        } catch (\Exception $e) {
            // Ignore connection errors
        }

        return null;
    }

    /**
     * Initialize a specific tenant for operations
     * This is especially useful for CLI commands
     *
     * @param string|int $tenantId
     * @return bool
     */
    public function initializeTenant($tenantId): bool
    {
        // Set the current tenant ID 
        $this->setCurrentTenant($tenantId);
        
        // Try to initialize through Stancl's tenancy if available
        if (class_exists('Stancl\Tenancy\Tenancy')) {
            try {
                // Try to get the tenant model
                $tenancy = app('Stancl\Tenancy\Tenancy');
                
                $tenantModelClass = config('tenancy.tenant_model');
                if (
                    is_subclass_of($tenantModelClass, 'Stancl\Tenancy\Database\Models\Tenant')
                    || $tenantModelClass === 'Stancl\Tenancy\Database\Models\Tenant'
                ) {
                    $tenant = $tenantModelClass::find($tenantId);
                    
                    if ($tenant) {
                        // Initialize the tenant (switches database connection)
                        if (method_exists($tenancy, 'initialize')) {
                            $tenancy->initialize($tenant);
                            return true;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Log error but continue
                if (class_exists('Illuminate\Support\Facades\Log')) {
                    app('log')->error("Failed to initialize tenant: " . $e->getMessage());
                }
            }
        }
        
        return false;
    }
} 