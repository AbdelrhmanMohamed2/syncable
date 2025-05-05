<?php

namespace Syncable\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Syncable\Services\TenantService;

class SetTenantContext
{
    /**
     * The tenant service instance.
     *
     * @var TenantService
     */
    protected $tenantService;

    /**
     * Create a new middleware instance.
     *
     * @param TenantService $tenantService
     */
    public function __construct(TenantService $tenantService)
    {
        $this->tenantService = $tenantService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if ($this->tenantService->isEnabled()) {
            // Check for tenant ID in the request header
            $tenantId = $request->header('X-TENANT-ID');
            
            // If not in header, check request data
            if (!$tenantId && $request->has('tenant_id')) {
                $tenantId = $request->input('tenant_id');
            }
            
            // If tenant ID is present, set it as the current tenant
            if ($tenantId) {
                $this->tenantService->setCurrentTenant($tenantId);
            }
        }

        return $next($request);
    }
} 