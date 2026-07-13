<?php

use Illuminate\Support\Str;

return [
    'application' => env('CACHE_APPLICATION', Str::slug((string) env('APP_NAME', 'seasonvar'))),
    'environment' => env('CACHE_ENVIRONMENT', env('APP_ENV', 'production')),
    'schema_version' => (int) env('CACHE_SCHEMA_VERSION', 1),
    'format_version' => (int) env('CACHE_FORMAT_VERSION', 1),

    'stores' => [
        'hot' => env('CACHE_HOT_STORE', 'memcached-hot'),
        'domain' => env('CACHE_DOMAIN_STORE', 'redis-domain'),
        'locks' => env('CACHE_LOCK_STORE', 'redis-locks'),
        'versions' => env('CACHE_VERSION_STORE', 'redis-locks'),
        'metrics' => env('CACHE_METRICS_STORE', 'redis-domain'),
    ],

    'max_payload_bytes' => (int) env('CACHE_MAX_PAYLOAD_BYTES', 900_000),
    'max_dimensions' => (int) env('CACHE_MAX_DIMENSIONS', 24),
    'max_dimension_length' => (int) env('CACHE_MAX_DIMENSION_LENGTH', 160),
    'metrics_retention_seconds' => (int) env('CACHE_METRICS_RETENTION_SECONDS', 604_800),
    'version_retention_seconds' => (int) env('CACHE_VERSION_RETENTION_SECONDS', 31_536_000),
    'run_infrastructure_tests' => env('RUN_CACHE_INFRASTRUCTURE_TESTS', false),

    'operations' => [
        'warming_state_retention_seconds' => (int) env('CACHE_WARMING_STATE_RETENTION_SECONDS', 604_800),
        'queue_worker_heartbeat_seconds' => (int) env('CACHE_QUEUE_WORKER_HEARTBEAT_SECONDS', 120),
        'health_probe_seconds' => (int) env('CACHE_HEALTH_PROBE_SECONDS', 10),
    ],

    'framework_events' => [
        'enabled' => env('CACHE_FRAMEWORK_EVENT_METRICS', true),
        'connection' => env('CACHE_FRAMEWORK_EVENT_REDIS_CONNECTION', 'cache'),
    ],

    'warming' => [
        'enabled' => env('CACHE_WARMING_ENABLED', true),
        'connection' => env('CACHE_WARM_QUEUE_CONNECTION', 'redis'),
        'queue' => env('CACHE_WARM_QUEUE', 'cache-warm'),
        'timeout' => (int) env('CACHE_WARM_TIMEOUT', 300),
        'unique_seconds' => (int) env('CACHE_WARM_UNIQUE_SECONDS', 300),
    ],

    'domains' => [
        'homepage' => [
            'fresh' => 120,
            'stale' => 900,
            'hot' => 60,
            'negative' => 15,
            'lock' => 60,
            'wait_milliseconds' => 500,
            'jitter_percent' => 10,
        ],
        'catalog-facets' => [
            'fresh' => 300,
            'stale' => 1_800,
            'hot' => 120,
            'negative' => 30,
            'lock' => 90,
            'wait_milliseconds' => 500,
            'jitter_percent' => 10,
        ],
        'catalog-stats' => [
            'fresh' => 1800,
            'stale' => 86400,
            'hot' => 300,
            'negative' => 30,
            'lock' => 180,
            'wait_milliseconds' => 250,
            'jitter_percent' => 15,
        ],
        'title-detail' => [
            'fresh' => 300,
            'stale' => 1_800,
            'hot' => 120,
            'negative' => 30,
            'lock' => 60,
            'wait_milliseconds' => 250,
            'jitter_percent' => 10,
        ],
        'recommendations' => [
            'fresh' => 1_800,
            'stale' => 21_600,
            'hot' => 600,
            'negative' => 60,
            'lock' => 120,
            'wait_milliseconds' => 500,
            'jitter_percent' => 15,
        ],
        'search-suggestions' => [
            'fresh' => 60,
            'stale' => 300,
            'hot' => 30,
            'negative' => 20,
            'lock' => 30,
            'wait_milliseconds' => 200,
            'jitter_percent' => 15,
        ],
        'sitemap' => [
            'fresh' => 1_800,
            'stale' => 21_600,
            'hot' => 300,
            'negative' => 30,
            'lock' => 120,
            'wait_milliseconds' => 500,
            'jitter_percent' => 15,
        ],
        'api' => [
            'fresh' => 60,
            'stale' => 300,
            'hot' => 30,
            'negative' => 20,
            'lock' => 30,
            'wait_milliseconds' => 250,
            'jitter_percent' => 10,
        ],
        'operational' => [
            'fresh' => 10,
            'stale' => 60,
            'hot' => 5,
            'negative' => 5,
            'lock' => 15,
            'wait_milliseconds' => 100,
            'jitter_percent' => 10,
        ],
    ],

    'http' => [
        'api' => [
            'max_age' => 60,
            'shared_max_age' => 300,
            'stale_while_revalidate' => 60,
            'stale_if_error' => 600,
        ],
        'documents' => [
            'max_age' => 300,
            'shared_max_age' => 1_800,
            'stale_while_revalidate' => 300,
            'stale_if_error' => 3_600,
        ],
    ],
];
