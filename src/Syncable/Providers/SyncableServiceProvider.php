<?php

namespace Syncable\Providers;

use Illuminate\Support\ServiceProvider;
use Syncable\Services\SyncService;
use Syncable\Services\ApiService;
use Syncable\Services\EncryptionService;
use Syncable\Services\TenantService;
use Syncable\Services\ConflictResolutionService;
use Syncable\Console\Commands\SyncCommand;
use Syncable\Console\Commands\ScheduledSyncCommand;
use Syncable\Http\Middleware\SetTenantContext;
use Syncable\Http\Middleware\VerifySyncApiKey;
use Syncable\Http\Middleware\ThrottleSyncRequests;
use Syncable\Http\Middleware\CheckIpWhitelist;

class SyncableServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../../../config/syncable.php' => config_path('syncable.php'),
        ], 'config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../../../database/migrations/' => database_path('migrations'),
        ], 'migrations');

        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/../../../routes/api.php');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncCommand::class,
                ScheduledSyncCommand::class,
            ]);
        }
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__ . '/../../../config/syncable.php', 'syncable'
        );

        // Register services
        $this->app->singleton('syncable', function ($app) {
            return new SyncService(
                $app->make(ApiService::class),
                $app->make(EncryptionService::class),
                $app->make(TenantService::class)
            );
        });

        $this->app->singleton(ApiService::class, function ($app) {
            return new ApiService(
                config('syncable.api.base_url'),
                config('syncable.api.key'),
                config('syncable.api.timeout', 30),
                $app->make(EncryptionService::class),
                config('syncable.api.encrypt_key', true)
            );
        });

        $this->app->singleton(EncryptionService::class, function ($app) {
            return new EncryptionService(
                config('syncable.encryption.key'),
                config('syncable.encryption.cipher', 'AES-256-CBC'),
                config('syncable.encryption.serialize_data', true)
            );
        });

        $this->app->singleton(TenantService::class, function ($app) {
            return new TenantService(
                config('syncable.tenancy.enabled', false),
                config('syncable.tenancy.identifier_column', 'tenant_id')
            );
        });
        
        $this->app->singleton(ConflictResolutionService::class, function ($app) {
            return new ConflictResolutionService();
        });
        
        // Register middleware
        $router = $this->app['router'];
        
        // Register tenant context middleware
        $router->aliasMiddleware('syncable.tenant_context', SetTenantContext::class);
        
        // Register throttling middleware
        if (config('syncable.throttling.enabled', false)) {
            $router->aliasMiddleware('syncable.throttle', ThrottleSyncRequests::class);
        }
        
        // Register API key verification middleware
        $router->aliasMiddleware('syncable.api_key', VerifySyncApiKey::class);
        
        // Register IP whitelist middleware
        $router->aliasMiddleware('syncable.ip_whitelist', CheckIpWhitelist::class);
    }
} 