<?php

namespace Syncable\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Syncable\Services\TenantService;

class IdMapping extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'syncable_id_mappings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'local_model_type',
        'local_model_id',
        'remote_model_type',
        'remote_model_id',
        'system_id',
        'tenant_id',
    ];

    /**
     * Get the local model by remote details.
     *
     * @param string $remoteModelType
     * @param int $remoteModelId
     * @param string $systemId
     * @param string|null $tenantId
     * @return mixed|null
     */
    public static function getLocalModelId(string $remoteModelType, $remoteModelId, string $systemId, ?string $tenantId = null)
    {
        $query = self::where('remote_model_type', $remoteModelType)
            ->where('remote_model_id', $remoteModelId)
            ->where('system_id', $systemId);
            
        // Add tenant filtering if applicable
        $query = self::applyTenantScope($query, $tenantId);
        
        $mapping = $query->first();

        return $mapping ? [$mapping->local_model_type, $mapping->local_model_id] : null;
    }

    /**
     * Get the remote model details by local model.
     *
     * @param string $localModelType
     * @param int $localModelId
     * @param string $systemId
     * @param string|null $tenantId
     * @return mixed|null
     */
    public static function getRemoteModelId(string $localModelType, $localModelId, string $systemId, ?string $tenantId = null)
    {
        $query = self::where('local_model_type', $localModelType)
            ->where('local_model_id', $localModelId)
            ->where('system_id', $systemId);
            
        // Add tenant filtering if applicable
        $query = self::applyTenantScope($query, $tenantId);
        
        $mapping = $query->first();

        return $mapping ? [$mapping->remote_model_type, $mapping->remote_model_id] : null;
    }

    /**
     * Store or update a mapping between local and remote models.
     *
     * @param string $localModelType
     * @param int $localModelId
     * @param string $remoteModelType
     * @param int $remoteModelId
     * @param string $systemId
     * @param string|null $tenantId
     * @return self
     */
    public static function createOrUpdateMapping(
        string $localModelType,
        $localModelId,
        string $remoteModelType,
        $remoteModelId,
        string $systemId,
        ?string $tenantId = null
    ) {
        $criteria = [
            'local_model_type' => $localModelType,
            'local_model_id' => $localModelId,
            'system_id' => $systemId,
        ];
        
        // Add tenant ID to criteria if applicable
        if ($tenantId !== null || (Config::get('syncable.tenancy.enabled', false) && app(TenantService::class)->isEnabled())) {
            $criteria['tenant_id'] = $tenantId ?? app(TenantService::class)->getCurrentTenantId();
        }
        
        return self::updateOrCreate(
            $criteria,
            [
                'remote_model_type' => $remoteModelType,
                'remote_model_id' => $remoteModelId,
            ]
        );
    }
    
    /**
     * Apply tenant scoping to a query if tenancy is enabled.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|null $tenantId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function applyTenantScope($query, ?string $tenantId = null)
    {
        if (Config::get('syncable.tenancy.enabled', false)) {
            $tenantService = app(TenantService::class);
            
            if ($tenantService->isEnabled()) {
                $tenantId = $tenantId ?? $tenantService->getCurrentTenantId();
                
                if ($tenantId !== null) {
                    return $query->where('tenant_id', $tenantId);
                }
            }
        }
        
        return $query;
    }
} 