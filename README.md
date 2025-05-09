# Syncable for Laravel

A robust Laravel package for syncing data between multiple Laravel projects via API.

## Features

- Model/table synchronization between Laravel projects
- Field name mapping between different database structures
- End-to-end encryption for secure data transfer
- Support for multi-tenant database systems
- Configuration-based or trait-based implementation
- Event-driven architecture for real-time sync
- Queued jobs for background processing
- **Bidirectional synchronization** for changes from either system
- **Conflict resolution strategies** for handling concurrent changes
- **Batch sync operations** for improved performance
- **Throttling & rate limiting** to prevent overwhelming target systems
- **Selective sync** based on model conditions or criteria
- **Scheduled syncs** for periodic reconciliation between systems
- **Differential sync** to only transmit changed fields
- **Dynamic value mapping** to transform data during sync
- **Relationship sync** to sync related models in one operation

## Installation

You can install the package via composer:

```bash
composer require abdelrhman-essa/syncable
```

## Publish the configuration file

```bash
php artisan vendor:publish --provider="Syncable\Providers\SyncableServiceProvider" --tag="config"
```

## Publish the migrations

```bash
php artisan vendor:publish --provider="Syncable\Providers\SyncableServiceProvider" --tag="migrations"
```

Then run the migrations:

```bash
php artisan migrate
```

## Usage

### Configuration-based approach

Define your syncing rules in the config file:

```php
// config/syncable.php
return [
    'models' => [
        'User' => [
            'target_model' => 'Customer',
            'fields' => [
                'name' => 'full_name',
                'email' => 'email_address',
            ],
        ],
    ],
    // Other configuration...
];
```

### Trait-based approach

Add the `Syncable` trait to your model:

```php
use Syncable\Traits\Syncable;

class User extends Model
{
    use Syncable;
    
    // Define which attributes should be synced
    protected $syncable = ['name', 'email'];
    
    // Define the target model in the other project
    protected $syncTarget = 'Customer';
    
    // Define field mappings if names differ
    protected $syncMap = [
        'name' => 'full_name',
        'email' => 'email_address',
    ];
    
    // Define conditions for selective sync (optional)
    protected $syncConditions = [
        'is_active' => true,
        'status' => ['published', 'approved'],
    ];
    
    // Custom method to determine if model should sync (optional)
    public function shouldSync(): bool
    {
        // Custom logic - return true if model should be synced
        return $this->is_syncable && $this->status === 'active';
    }
    
    // Custom conflict resolution strategy (optional)
    public function getConflictResolutionStrategy(): string 
    {
        return 'local_wins'; // Options: last_write_wins, local_wins, remote_wins, merge, manual
    }
}
```

### SyncHandler-based approach (Recommended)

For better separation of concerns, you can use the SyncHandler pattern to move all sync-related logic to dedicated classes:

1. First, create a sync handler class for your model:

```php
// app/Syncable/Handlers/UserSync.php
namespace App\Syncable\Handlers;

use App\Models\User;
use Syncable\Handlers\SyncHandler;

class UserSync extends SyncHandler
{
    /**
     * Get the target model class name for syncing.
     *
     * @return string
     */
    public function getTargetModel(): string
    {
        return 'Customer';
    }

    /**
     * Get the field mapping for syncing.
     *
     * @return array
     */
    public function getFieldMap(): array
    {
        return [
            'name' => 'full_name',
            'email' => 'email_address',
        ];
    }

    /**
     * Get the syncable fields.
     *
     * @return array
     */
    public function getSyncableFields(): array
    {
        return ['name', 'email'];
    }

    /**
     * Get the sync conditions.
     *
     * @return array
     */
    public function getSyncConditions(): array
    {
        return [
            'is_active' => true,
            'status' => ['published', 'approved'],
        ];
    }

    /**
     * Custom sync logic for this model.
     *
     * @return bool
     */
    public function shouldSync(): bool
    {
        if (!parent::shouldSync()) {
            return false;
        }
        
        return $this->model->is_syncable && $this->model->status === 'active';
    }
    
    /**
     * Get the conflict resolution strategy.
     *
     * @return string
     */
    public function getConflictResolutionStrategy(): string
    {
        return 'local_wins';
    }
}
```

2. Then, in your model, simply add a method to return the sync handler:

```php
use Syncable\Traits\Syncable;
use App\Syncable\Handlers\UserSync;

class User extends Model
{
    use Syncable;

    /**
     * Get the sync handler for this model.
     *
     * @return UserSync
     */
    public function syncHandler()
    {
        return new UserSync($this);
    }
}
```

This approach keeps your models clean and focused on their core functionality, while all sync-related logic is contained in dedicated handler classes.

### Dynamic Value Mapping

You can now use dynamic expressions to transform data during synchronization:

```php
class User extends Model
{
    use Syncable;
    
    protected $syncTarget = 'Customer';
    
    protected $syncMap = [
        // Target field => Source value (Dynamic expression)
        'first_name' => '$this->name',
        'email_address' => '$this->email',
        'full_name' => '$this->getFullName()',  // Method call
    ];
    
    public function getFullName()
    {
        return $this->first_name . ' ' . $this->last_name;
    }
}
```

### Relationship Synchronization

You can sync related models along with the parent model:

```php
class Client extends Model
{
    use Syncable;
    
    protected $syncTarget = 'Customer';
    
    protected $syncMap = [
        'name' => '$this->company_name',
        'email' => '$this->contact_email',
    ];
    
    // Define related models to sync
    protected $syncRelations = [
        'addresses' => [
            'type' => 'hasMany',
            'target_relation' => 'locations',  // Relation name in target system
            'fields' => [
                'street' => 'address_line1',
                'city' => 'city',
                'state' => 'state',
                'zip_code' => 'postal_code',
            ],
        ],
        'primaryContact' => [
            'type' => 'hasOne',
            'target_relation' => 'contact',
            'fields' => [
                'contact_name' => 'name',
                'contact_phone' => 'phone',
                'contact_email' => 'email',
            ],
        ],
    ];
    
    // Define relationships in your model
    public function addresses()
    {
        return $this->hasMany(Address::class);
    }
    
    public function primaryContact()
    {
        return $this->hasOne(Contact::class);
    }
}
```

### API Configuration

Set up the API connection in your `.env` file:

```
SYNCABLE_TARGET_URL=https://your-target-project.com/api
SYNCABLE_API_KEY=your-api-key
SYNCABLE_SECRET_KEY=your-secret-key
SYNCABLE_SYSTEM_ID=unique-system-identifier
SYNCABLE_TARGET_SYSTEM_ID=target-system-identifier
```

The API key is automatically encrypted for secure transmission between systems using the encryption key specified in your configuration. The package uses a custom header `X-SYNCABLE-API-KEY` to send the encrypted API key.

### ID Mapping Between Systems

Syncable automatically handles ID mapping between different systems, allowing you to sync models that have different primary keys in each system:

1. When creating a model in the target system, Syncable stores a mapping between the local and remote IDs.
2. Subsequent updates and deletes automatically use the correct remote ID.
3. When receiving data from other systems, Syncable looks up the corresponding local ID.

This means you don't need to worry about ID differences between systems - Syncable handles all the mapping for you transparently.

### IP Whitelisting

You can restrict access to sync endpoints by whitelisting specific IP addresses:

```
# In .env file - comma-separated list of allowed IPs
SYNCABLE_IP_WHITELIST=192.168.1.1,10.0.0.5,203.0.113.42

# Or configure in config/syncable.php
'api' => [
    // ... other API config ...
    'ip_whitelist' => ['192.168.1.1', '10.0.0.5', '203.0.113.42'],
],
```

If no IP whitelist is configured, all IPs will be allowed.

### Bidirectional Sync

To enable bidirectional synchronization:

```
SYNCABLE_BIDIRECTIONAL_ENABLED=true
```

### Conflict Resolution

Configure how conflicts are handled:

```
SYNCABLE_CONFLICT_STRATEGY=last_write_wins
```

Available strategies:
- `last_write_wins`: Remote changes always win (default)
- `local_wins`: Local changes always win
- `remote_wins`: Remote changes always win
- `merge`: Attempt to merge the changes
- `manual`: Flag conflicts for manual resolution

### Selective Sync

Skip synchronization for certain models:

```php
// Skip this update from syncing
$user->withoutSync()->update(['name' => 'New Name']);

// Re-enable sync for future operations
$user->withSync();
```

### Scheduled Sync

Configure recurring synchronization jobs:

```php
// config/syncable.php
'scheduled_sync' => [
    'enabled' => true,
    'schedules' => [
        'daily_users' => [
            'model' => 'App\Models\User',
            'action' => 'update',
            'filters' => [
                'is_active' => true,
            ],
            'date_field' => 'updated_at',
            'date_range' => 'today',
            'batch_size' => 100,
        ],
    ],
],
```

Add to your Laravel scheduler:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('syncable:scheduled-sync')
             ->daily();
             
    // Or for a specific schedule:
    $schedule->command('syncable:scheduled-sync --schedule=daily_users')
             ->daily();
}
```

### Batch Sync Operations

To sync multiple models in a single request:

```php
// Batch sync endpoint: POST /api/syncable/batch

// Request format:
{
    "operations": [
        {
            "action": "create",
            "data": {
                "target_model": "App\\Models\\User",
                "data": { "name": "John", "email": "john@example.com" }
            }
        },
        {
            "action": "update",
            "data": {
                "target_model": "App\\Models\\User",
                "source_id": 1,
                "data": { "name": "Updated Name" }
            }
        }
    ]
}
```

### Throttling & Rate Limiting

Enable throttling to prevent overwhelming the target system:

```
SYNCABLE_THROTTLING_ENABLED=true
SYNCABLE_THROTTLING_MAX_PER_MINUTE=60
```

### Differential Sync

Enable differential sync to only transmit changed fields:

```
SYNCABLE_DIFFERENTIAL_SYNC_ENABLED=true
```

## Security

The package uses Laravel's encryption capabilities for secure data transfer between applications.

## Multi-Tenant Database Support

Syncable supports multi-tenant applications, including those where each tenant has their own separate database. 

### Single Database Multi-Tenancy

For applications where all tenants share the same database but data is segregated by a tenant ID:

1. Enable tenancy in your `.env` file:

```
SYNCABLE_TENANCY_ENABLED=true
SYNCABLE_TENANCY_IDENTIFIER_COLUMN=tenant_id
```

2. Apply the `TenantAware` trait to your models:

```php
use Syncable\Traits\Syncable;
use Syncable\Traits\TenantAware;

class Product extends Model
{
    use Syncable, TenantAware;
    
    // Rest of your model...
}
```

The `TenantAware` trait automatically adds a global scope to filter queries by the current tenant ID and ensures new records include the tenant ID when created.

### Cross-System Tenant Initialization

When syncing data from System A to System B, you can control which tenant is initialized in System B by:

1. **Using the model's tenant ID** (default behavior):  
   By default, the package will use the same tenant ID for the target system as the source model.

2. **Customizing target tenant ID logic**:  
   Override the `getTargetTenantId()` method in your model to provide custom logic:

```php
use Syncable\Traits\Syncable;
use Syncable\Traits\TenantAware;

class User extends Model
{
    use Syncable, TenantAware;
    
    /**
     * Get the tenant ID to use for the target system when syncing.
     * 
     * @return mixed|null
     */
    public function getTargetTenantId()
    {
        // Example: Use a related model's tenant ID
        return $this->account->tenant_id;
        
        // Or any other custom logic
        // return $this->target_system_tenant_id;
    }
}
```

This gives you complete control over which tenant is initialized in System B during sync operations, and the tenant initialization happens without affecting the logs.

### Cross-System Tenancy When Only One System is Tenant-Based

#### Scenario 1: System A is NOT tenant-based, but System B IS tenant-based

When your source system is not multi-tenant but your target system is, you need to specify which tenant should be used in System B:

1. **Global configuration** - Set in your `.env` file or config:
   ```php
   // In .env file of System A
   SYNCABLE_TARGET_TENANT_ID=123
   
   // Or in config/syncable.php
   'target_tenant_id' => 123,
   ```

2. **Per-model configuration** - Specify in your model config:
   ```php
   // In config/syncable.php of System A
   'models' => [
       'App\Models\User' => [
           // ... other config ...
           'target_tenant_id' => 123,
       ],
   ],
   ```

3. **Dynamic assignment** - Implement in your code:
   ```php
   // In SystemA - assign a tenant ID before syncing
   $user->target_tenant_id = 123;
   $user->sync();
   ```

#### Scenario 2: System A IS tenant-based, but System B is NOT tenant-based

When your source system is multi-tenant but your target system is not, the package automatically:

1. Includes the tenant ID in the sync data
2. System B safely ignores the tenant ID (no initialization is required)
3. You can still track the source tenant ID in System B by adding a `tenant_id` field to your models

### Separate Database per Tenant

For applications where each tenant has their own database (using packages like [stancl/tenancy](https://github.com/stancl/tenancy)), Syncable integrates seamlessly:

1. Make sure your Stancl tenancy package is properly configured
2. Syncable will automatically detect the current tenant's database connection

If you're using Stancl's tenancy with `tenancy()->initialize($tenant)`, Syncable will automatically:

- Detect the current tenant ID
- Scope data operations to the tenant's database
- Maintain tenant context during sync operations

For console commands, you can specify a tenant:

```bash
php artisan syncable:sync "App\Models\Product" --tenant=123
```

### Custom Tenant Initialization

If you need to customize tenant initialization, you can extend the `TenantService`:

```php
namespace App\Services;

use Syncable\Services\TenantService as BaseTenantService;

class CustomTenantService extends BaseTenantService
{
    public function initializeTenant($tenantId): bool
    {
        // Your custom logic to switch to the tenant's database
        
        // Call parent implementation
        return parent::initializeTenant($tenantId);
    }
}
```

Then bind your custom implementation in a service provider:

```php
$this->app->bind(
    \Syncable\Services\TenantService::class, 
    \App\Services\CustomTenantService::class
);
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information. 