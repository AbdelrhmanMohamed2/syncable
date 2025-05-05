<?php

namespace Syncable\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

class ThrottleSyncRequests
{
    /**
     * The rate limiter instance.
     *
     * @var \Illuminate\Cache\RateLimiter
     */
    protected $limiter;

    /**
     * Create a new middleware instance.
     *
     * @param  \Illuminate\Cache\RateLimiter  $limiter
     * @return void
     */
    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Illuminate\Http\Exceptions\ThrottleRequestsException
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get the IP address of the client
        $key = $this->resolveRequestSignature($request);
        
        // Get max requests per minute from config
        $maxAttempts = Config::get('syncable.throttling.max_per_minute', 60);
        
        // Check if the client has exceeded the rate limit
        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            return $this->buildTooManyAttemptsResponse($key, $maxAttempts);
        }
        
        // Increment the request count
        $this->limiter->hit($key, 60);
        
        // Get response
        $response = $next($request);
        
        // Add rate limit headers to the response
        $response->headers->add([
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => $maxAttempts - $this->limiter->attempts($key),
        ]);
        
        return $response;
    }
    
    /**
     * Resolve the request signature to be used for throttling.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function resolveRequestSignature(Request $request): string
    {
        // Use API key as the signature if available
        $api_key = $request->header('X-SYNCABLE-API-KEY');
        
        if ($api_key) {
            return 'syncable|api|' . $api_key;
        }
        
        // Otherwise fallback to IP address
        return 'syncable|ip|' . $request->ip();
    }
    
    /**
     * Create a too many attempts response.
     *
     * @param  string  $key
     * @param  int  $maxAttempts
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function buildTooManyAttemptsResponse(string $key, int $maxAttempts): Response
    {
        $retryAfter = $this->limiter->availableIn($key);
        
        return response()->json([
            'success' => false,
            'message' => 'Too many sync requests. Please try again in ' . $retryAfter . ' seconds.',
        ], 429)->withHeaders([
            'Retry-After' => $retryAfter,
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => 0,
        ]);
    }
} 