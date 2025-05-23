<?php

namespace Syncable\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool syncModel(\Illuminate\Database\Eloquent\Model $model, string $action = 'update')
 * 
 * @see \Syncable\Services\SyncService
 */
class Syncable extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'syncable';
    }
}
