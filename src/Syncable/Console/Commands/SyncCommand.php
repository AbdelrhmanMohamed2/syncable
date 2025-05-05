<?php

namespace Syncable\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Syncable\Services\SyncService;
use Syncable\Services\TenantService;

class SyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'syncable:sync
                            {model : The model class to sync (e.g., App\\Models\\User)}
                            {--id= : Sync a specific model by ID}
                            {--all : Sync all records of the model}
                            {--action=update : The action to perform (create, update, delete)}
                            {--tenant= : Only run for a specific tenant ID (for multi-tenant applications)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync models to the target application';

    /**
     * @var SyncService
     */
    protected $syncService;

    /**
     * Create a new command instance.
     *
     * @param SyncService $syncService
     */
    public function __construct(SyncService $syncService)
    {
        parent::__construct();
        $this->syncService = $syncService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $modelClass = $this->argument('model');
        $action = $this->option('action');
        $modelId = $this->option('id');
        $syncAll = $this->option('all');
        $tenant = $this->option('tenant');

        if (!class_exists($modelClass)) {
            $this->error("Model class {$modelClass} not found.");
            return 1;
        }

        // Set tenant if specified
        if ($tenant) {
            $this->setupTenant($tenant);
        }

        // Check if the model uses the Syncable trait
        if (!method_exists($modelClass, 'getSyncConfig')) {
            $this->warn("Warning: {$modelClass} does not use the Syncable trait. Continuing anyway...");
        }

        // Sync specific model by ID
        if ($modelId) {
            return $this->syncModelById($modelClass, $modelId, $action);
        }

        // Sync all models
        if ($syncAll) {
            return $this->syncAllModels($modelClass, $action);
        }

        $this->error('You must specify either --id or --all option.');
        return 1;
    }

    /**
     * Sync a specific model by ID.
     *
     * @param string $modelClass
     * @param int $modelId
     * @param string $action
     * @return int
     */
    protected function syncModelById(string $modelClass, $modelId, string $action)
    {
        $model = $modelClass::find($modelId);

        if (!$model) {
            $this->error("Model {$modelClass} with ID {$modelId} not found.");
            return 1;
        }

        $this->info("Syncing {$modelClass} with ID {$modelId}...");
        
        $success = $this->syncService->syncModel($model, $action);

        if ($success) {
            $this->info("Successfully synced {$modelClass} with ID {$modelId}.");
            return 0;
        }

        $this->error("Failed to sync {$modelClass} with ID {$modelId}.");
        return 1;
    }

    /**
     * Sync all models of the given class.
     *
     * @param string $modelClass
     * @param string $action
     * @return int
     */
    protected function syncAllModels(string $modelClass, string $action)
    {
        $this->info("Syncing all {$modelClass} records...");

        $count = 0;
        $failCount = 0;
        $models = $modelClass::all();
        $bar = $this->output->createProgressBar($models->count());

        foreach ($models as $model) {
            $success = $this->syncService->syncModel($model, $action);
            
            if ($success) {
                $count++;
            } else {
                $failCount++;
            }
            
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Synced {$count} {$modelClass} records successfully.");
        
        if ($failCount > 0) {
            $this->warn("Failed to sync {$failCount} {$modelClass} records.");
        }

        return $failCount > 0 ? 1 : 0;
    }

    /**
     * Set up the tenant context for a tenant ID.
     *
     * @param string|int $tenantId
     * @return void
     */
    protected function setupTenant($tenantId)
    {
        $tenantService = app(TenantService::class);
        
        // Use the new initializeTenant method which will also attempt to switch databases
        if ($tenantService->initializeTenant($tenantId)) {
            $this->info("Initialized tenant {$tenantId} with database connection.");
        } else {
            // Fall back to just setting the tenant ID
            $tenantService->setCurrentTenant($tenantId);
            $this->info("Set tenant context to {$tenantId}.");
        }
    }
} 