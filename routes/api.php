<?php

use Illuminate\Support\Facades\Route;
use Syncable\Http\Controllers\SyncableController;

// Define middleware for Syncable API routes
$middleware = [
    'api', 
    'syncable.ip_whitelist', 
    'syncable.api_key',
    'syncable.tenant_context'
];

// Add throttling middleware if enabled
if (config('syncable.throttling.enabled', false)) {
    $middleware[] = 'syncable.throttle';
}

Route::prefix('api/syncable')->middleware($middleware)->group(function () {
    Route::post('create', [SyncableController::class, 'create'])->name('syncable.create');
    Route::post('update', [SyncableController::class, 'update'])->name('syncable.update');
    Route::post('delete', [SyncableController::class, 'delete'])->name('syncable.delete');
    Route::post('batch', [SyncableController::class, 'batchSync'])->name('syncable.batch');
    Route::post('/', [SyncableController::class, 'sync'])->name('syncable.sync');
}); 