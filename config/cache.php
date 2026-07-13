<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache store that will be used by the
    | framework. This connection is utilized if another isn't explicitly
    | specified when running a cache operation inside the application.
    |
    */

    'default' => env('CACHE_STORE', 'redis-domain'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "array", "database", "file", "memcached",
    |                    "redis", "dynamodb", "storage", "octane",
    |                    "session", "failover", "null"
    |
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'storage' => [
            'driver' => 'storage',
            'disk' => env('CACHE_STORAGE_DISK'),
            'path' => env('CACHE_STORAGE_PATH', 'framework/cache/data'),
        ],

        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl' => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options' => [
                // Memcached::OPT_CONNECT_TIMEOUT => 2000,
            ],
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        'memcached-hot' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_HOT_PERSISTENT_ID', 'seasonvar-hot'),
            'sasl' => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options' => extension_loaded('memcached') ? array_filter([
                Memcached::OPT_CONNECT_TIMEOUT => (int) env('MEMCACHED_CONNECT_TIMEOUT_MS', 250),
                Memcached::OPT_RETRY_TIMEOUT => (int) env('MEMCACHED_RETRY_TIMEOUT_SECONDS', 2),
                Memcached::OPT_SERVER_FAILURE_LIMIT => (int) env('MEMCACHED_SERVER_FAILURE_LIMIT', 2),
                Memcached::OPT_REMOVE_FAILED_SERVERS => env('MEMCACHED_REMOVE_FAILED_SERVERS', true),
                Memcached::OPT_BINARY_PROTOCOL => env('MEMCACHED_BINARY_PROTOCOL', true),
                Memcached::OPT_LIBKETAMA_COMPATIBLE => env('MEMCACHED_CONSISTENT_DISTRIBUTION', true),
            ], fn (mixed $value): bool => $value !== null) : [],
            'servers' => array_values(array_filter([
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => (int) env('MEMCACHED_PORT', 11211),
                    'weight' => (int) env('MEMCACHED_WEIGHT', 100),
                ],
                [
                    'host' => env('MEMCACHED_HOST_2'),
                    'port' => (int) env('MEMCACHED_PORT_2', 11211),
                    'weight' => (int) env('MEMCACHED_WEIGHT_2', 100),
                ],
                [
                    'host' => env('MEMCACHED_HOST_3'),
                    'port' => (int) env('MEMCACHED_PORT_3', 11211),
                    'weight' => (int) env('MEMCACHED_WEIGHT_3', 100),
                ],
            ], fn (array $server): bool => is_string($server['host']) && $server['host'] !== '')),
            'prefix' => env('MEMCACHED_HOT_PREFIX', Str::slug((string) env('APP_NAME', 'seasonvar')).'-'.env('APP_ENV', 'production').'-hot-'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

        'redis-domain' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'locks'),
            'prefix' => env('REDIS_DOMAIN_CACHE_PREFIX', ''),
        ],

        'redis-locks' => [
            'driver' => 'redis',
            'connection' => env('REDIS_LOCK_CONNECTION', 'locks'),
            'lock_connection' => env('REDIS_LOCK_CONNECTION', 'locks'),
            'prefix' => env('REDIS_LOCK_CACHE_PREFIX', ''),
        ],

        'recomputable-failover' => [
            'driver' => 'failover',
            'stores' => [
                'redis-domain',
                'file',
            ],
        ],

        'dynamodb' => [
            'driver' => 'dynamodb',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
            'endpoint' => env('DYNAMODB_ENDPOINT'),
        ],

        'octane' => [
            'driver' => 'octane',
        ],

        'failover' => [
            'driver' => 'failover',
            'stores' => [
                'database',
                'array',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the APC, database, memcached, Redis, and DynamoDB cache
    | stores, there might be other applications using the same cache. For
    | that reason, you may prefix every cache key to avoid collisions.
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-cache-'),

    /*
    |--------------------------------------------------------------------------
    | Serializable Classes
    |--------------------------------------------------------------------------
    |
    | This value determines the classes that can be unserialized from cache
    | storage. By default, no PHP classes will be unserialized from your
    | cache to prevent gadget chain attacks if your APP_KEY is leaked.
    |
    */

    'serializable_classes' => false,

];
