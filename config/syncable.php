<?php

return [
    /*
    |--------------------------------------------------------------------------
    | System Identifier
    |--------------------------------------------------------------------------
    |
    | A unique identifier for this system, used for bidirectional sync to
    | identify the origin of changes and prevent infinite sync loops.
    |
    */
    'system_id' => env('SYNCABLE_SYSTEM_ID', env('APP_NAME', 'laravel')),

    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the API connection to the target Laravel project.
    | The API key will be automatically encrypted when sent between systems
    | using the X-SYNCABLE-API-KEY header.
    |
    */
    'api' => [
        'base_url' => env('SYNCABLE_TARGET_URL', 'http://localhost'),
        'key' => env('SYNCABLE_API_KEY', ''),
        'timeout' => env('SYNCABLE_API_TIMEOUT', 30),
        'retry_attempts' => env('SYNCABLE_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('SYNCABLE_RETRY_DELAY', 5), // seconds
        'ip_whitelist' => env('SYNCABLE_IP_WHITELIST', null), // Comma-separated list of allowed IPs or null to allow all
        'target_system_id' => env('SYNCABLE_TARGET_SYSTEM_ID', 'target_system'), // Identifier for the target system
        'encrypt_key' => env('SYNCABLE_ENCRYPT_API_KEY', true), // Whether to encrypt the API key when sending requests
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the encryption for secure data transfer between applications.
    |
    */
    'encryption' => [
        'enabled' => env('SYNCABLE_ENCRYPTION_ENABLED', false),
        'key' => env('SYNCABLE_ENCRYPTION_KEY', 'base64:YourRandomEncryptionKey'),
        'cipher' => env('SYNCABLE_ENCRYPTION_CIPHER', 'AES-256-CBC'),
        'serialize_data' => env('SYNCABLE_SERIALIZE_DATA', true), // Whether to serialize complex data types before encryption
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the queue for processing sync jobs.
    |
    */
    'queue' => [
        'enabled' => env('SYNCABLE_QUEUE_ENABLED', true),
        'connection' => env('SYNCABLE_QUEUE_CONNECTION', 'default'),
        'queue' => env('SYNCABLE_QUEUE_NAME', 'syncable'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how the package handles multi-tenant environments.
    |
    */
    'tenancy' => [
        'enabled' => env('SYNCABLE_TENANCY_ENABLED', false),
        'identifier_column' => env('SYNCABLE_TENANCY_IDENTIFIER_COLUMN', 'tenant_id'),
        'connection_resolver' => env('SYNCABLE_TENANCY_CONNECTION_RESOLVER', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bidirectional Sync Configuration
    |--------------------------------------------------------------------------
    |
    | Configure bidirectional synchronization to allow changes from either system
    | to propagate to the other.
    |
    */
    'bidirectional' => [
        'enabled' => env('SYNCABLE_BIDIRECTIONAL_ENABLED', false),
        'detection_window_minutes' => env('SYNCABLE_DETECTION_WINDOW', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conflict Resolution Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how conflicts are handled when the same record is modified in
    | both systems before syncing completes.
    |
    */
    'conflict_resolution' => [
        'strategy' => env('SYNCABLE_CONFLICT_STRATEGY', 'last_write_wins'),
        'store_conflicts' => env('SYNCABLE_STORE_CONFLICTS', true),
        'models' => [
            // Model-specific strategies
            // 'App\Models\User' => 'local_wins',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Throttling & Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Prevent overwhelming target systems by implementing rate limits on sync
    | operations.
    |
    */
    'throttling' => [
        'enabled' => env('SYNCABLE_THROTTLING_ENABLED', false),
        'delay_seconds' => env('SYNCABLE_THROTTLING_DELAY', 0),
        'max_per_minute' => env('SYNCABLE_THROTTLING_MAX_PER_MINUTE', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Differential Sync
    |--------------------------------------------------------------------------
    |
    | Track which fields have actually changed and only send those in sync
    | operations rather than the entire record.
    |
    */
    'differential_sync' => [
        'enabled' => env('SYNCABLE_DIFFERENTIAL_SYNC_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Selective Sync
    |--------------------------------------------------------------------------
    |
    | Add conditions to determine which records should sync based on criteria.
    |
    */
    'selective_sync' => [
        'enabled' => env('SYNCABLE_SELECTIVE_SYNC_ENABLED', false),
        'conditions' => [
            // Global conditions that apply to all models
            // 'status' => 'active',
            // 'is_syncable' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled Sync
    |--------------------------------------------------------------------------
    |
    | Configure recurring synchronization jobs for specific models or data sets.
    |
    */
    'scheduled_sync' => [
        'enabled' => env('SYNCABLE_SCHEDULED_SYNC_ENABLED', false),
        'schedules' => [
            // Example:
            // 'daily_users' => [
            //     'model' => 'App\Models\User',
            //     'action' => 'update',
            //     'filters' => [
            //         'is_active' => true,
            //     ],
            //     'date_field' => 'updated_at',
            //     'date_range' => 'today', // today, yesterday, this_week, last_week, this_month, last_month, or array [start, end]
            //     'batch_size' => 100,
            //     'frequency' => 'daily', // Use with Laravel scheduler
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Syncable Models Configuration
    |--------------------------------------------------------------------------
    |
    | Define which models should be synced and how they map to the target project.
    | This is an alternative to using the Syncable trait on individual models.
    |
    | You can now map fields to dynamic values using the $this-> syntax:
    | 'first_name' => '$this->name'
    | 
    | You can also define relationships to sync along with the model:
    |
    */
    'models' => [
        // Example:
        // 'App\Models\User' => [
        //     'target_model' => 'App\Models\Customer',
        //     'fields' => [
        //         // Static field mapping
        //         'name' => 'full_name',
        //         'email' => 'email_address',
        //         
        //         // Dynamic value mapping with $this-> syntax
        //         'first_name' => '$this->getFirstName()',
        //         'formatted_email' => '$this->email',
        //     ],
        //     // Sync relationships
        //     'relations' => [
        //         'addresses' => [
        //             'type' => 'hasMany',
        //             'target_relation' => 'addresses',
        //             'fields' => [
        //                 'address_line1' => 'street',
        //                 'address_line2' => 'unit',
        //                 'city' => 'city',
        //                 'state' => 'state',
        //                 'zip' => 'postal_code',
        //             ],
        //         ],
        //         'profile' => [
        //             'type' => 'hasOne',
        //             'target_relation' => 'userProfile',
        //             'fields' => [
        //                 'bio' => 'biography',
        //                 'avatar' => 'profile_picture',
        //             ],
        //         ],
        //     ],
        //     // For cross-tenant syncing: specify which tenant ID should be used in the target system 
        //     // when this model is synced (only needed when source or target system is tenant-based, but not both)
        //     'target_tenant_id' => 123,
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Relationship Sync Configuration
    |--------------------------------------------------------------------------
    |
    | Global configuration for relationship synchronization. These settings apply
    | to all models unless overridden at the model level.
    |
    */
    'relationships' => [
        'enabled' => env('SYNCABLE_RELATIONSHIPS_ENABLED', true),
        'recursive' => env('SYNCABLE_RECURSIVE_RELATIONSHIPS', false),
        'max_depth' => env('SYNCABLE_RELATIONSHIPS_MAX_DEPTH', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Events Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which model events should trigger synchronization.
    |
    */
    'events' => [
        'created' => env('SYNCABLE_EVENT_CREATED', true),
        'updated' => env('SYNCABLE_EVENT_UPDATED', true),
        'deleted' => env('SYNCABLE_EVENT_DELETED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configure logging options for sync operations.
    |
    */
    'logging' => [
        'enabled' => env('SYNCABLE_LOGGING_ENABLED', true),
        'database_enabled' => env('SYNCABLE_LOGGING_DB_ENABLED', true),
        'channel' => env('SYNCABLE_LOGGING_CHANNEL', 'stack'),
    ],
]; 