<?php

namespace Syncable\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Response;

class CheckIpWhitelist
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Get whitelist configuration
        $ipWhitelist = Config::get('syncable.api.ip_whitelist');
        
        // If no whitelist is configured, allow all IPs
        if (empty($ipWhitelist)) {
            return $next($request);
        }
        
        // Convert comma-separated string to array if needed
        $allowedIps = is_string($ipWhitelist) 
            ? array_map('trim', explode(',', $ipWhitelist)) 
            : (array) $ipWhitelist;
        
        // Get client IP address
        $clientIp = $request->ip();
        
        // Check if client IP is in whitelist
        if (!in_array($clientIp, $allowedIps)) {
            return Response::json([
                'success' => false,
                'message' => 'Access denied: Your IP address is not whitelisted.',
            ], 403);
        }

        return $next($request);
    }
} 