<?php

namespace Syncable\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Syncable\Services\SyncService;
use Syncable\Services\TenantService;

class ScheduledSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'syncable:scheduled-sync
                            {--schedule= : Run a specific schedule name}
                            {--tenant= : Only run for a specific tenant ID (for multi-tenant applications)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run scheduled synchronization jobs';

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
        $scheduleName = $this->option('schedule');
        $tenant = $this->option('tenant');
        
        // Set tenant context if specified
        if ($tenant) {
            $this->setupTenant($tenant);
        }
        
        // Get the schedules from config
        $schedules = Config::get('syncable.scheduled_sync.schedules', []);
        
        if (empty($schedules)) {
            $this->info('No sync schedules defined.');
            return 0;
        }
        
        // If a specific schedule was requested, filter to only that one
        if ($scheduleName) {
            if (!isset($schedules[$scheduleName])) {
                $this->error("Schedule '{$scheduleName}' not found.");
                return 1;
            }
            
            $schedules = [$scheduleName => $schedules[$scheduleName]];
        }
        
        // Process each schedule
        foreach ($schedules as $name => $schedule) {
            $this->info("Running sync schedule: {$name}");
            
            // Validate the schedule configuration
            if (!isset($schedule['model']) || !isset($schedule['action'])) {
                $this->error("Invalid schedule configuration for '{$name}'.");
                continue;
            }
            
            $modelClass = $schedule['model'];
            $action = $schedule['action'];
            $filters = $schedule['filters'] ?? [];
            $batchSize = $schedule['batch_size'] ?? 100;
            
            // Check if the model class exists
            if (!class_exists($modelClass)) {
                $this->error("Model class {$modelClass} not found.");
                continue;
            }
            
            // Build the query with filters
            $query = $modelClass::query();
            
            foreach ($filters as $field => $value) {
                if (is_array($value)) {
                    $query->whereIn($field, $value);
                } else {
                    $query->where($field, $value);
                }
            }
            
            // Add date range filter if specified
            if (isset($schedule['date_field']) && isset($schedule['date_range'])) {
                $dateField = $schedule['date_field'];
                $dateRange = $schedule['date_range'];
                
                if (is_string($dateRange)) {
                    // Handle predefined ranges (today, yesterday, this_week, last_week, this_month, last_month)
                    switch ($dateRange) {
                        case 'today':
                            $query->whereDate($dateField, today());
                            break;
                        case 'yesterday':
                            $query->whereDate($dateField, today()->subDay());
                            break;
                        case 'this_week':
                            $query->whereBetween($dateField, [now()->startOfWeek(), now()->endOfWeek()]);
                            break;
                        case 'last_week':
                            $query->whereBetween($dateField, [
                                now()->subWeek()->startOfWeek(), 
                                now()->subWeek()->endOfWeek()
                            ]);
                            break;
                        case 'this_month':
                            $query->whereBetween($dateField, [now()->startOfMonth(), now()->endOfMonth()]);
                            break;
                        case 'last_month':
                            $query->whereBetween($dateField, [
                                now()->subMonth()->startOfMonth(), 
                                now()->subMonth()->endOfMonth()
                            ]);
                            break;
                    }
                } elseif (is_array($dateRange) && count($dateRange) === 2) {
                    // Handle custom date range
                    $query->whereBetween($dateField, $dateRange);
                }
            }
            
            // Process in batches to prevent memory issues
            $total = $query->count();
            $this->info("Found {$total} records to process");
            
            if ($total === 0) {
                $this->info("No records to sync for schedule '{$name}'.");
                continue;
            }
            
            $processed = 0;
            $successful = 0;
            $failed = 0;
            
            $bar = $this->output->createProgressBar($total);
            $bar->start();
            
            $query->chunk($batchSize, function ($models) use (&$processed, &$successful, &$failed, $action, $bar) {
                foreach ($models as $model) {
                    try {
                        $result = $this->syncService->syncModel($model, $action);
                        $processed++;
                        
                        if ($result) {
                            $successful++;
                        } else {
                            $failed++;
                        }
                    } catch (\Exception $e) {
                        $this->error("Error syncing model ID {$model->getKey()}: {$e->getMessage()}");
                        $failed++;
                    }
                    
                    $bar->advance();
                }
            });
            
            $bar->finish();
            $this->newLine();
            
            $this->info("Completed sync schedule '{$name}':");
            $this->info("  Processed: {$processed}");
            $this->info("  Successful: {$successful}");
            $this->info("  Failed: {$failed}");
            $this->newLine();
        }
        
        return 0;
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