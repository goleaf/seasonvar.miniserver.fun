<?php

declare(strict_types=1);

use App\Models\Country;
use App\Models\Genre;
use Illuminate\Support\Str;

return [
    'enabled' => env('AUTOCACHE_ENABLED', true),
    'store' => env('AUTOCACHE_STORE', 'recomputable-failover'),
    'ttl' => (int) env('AUTOCACHE_TTL', 300),
    'ttl_jitter' => (float) env('AUTOCACHE_TTL_JITTER', 0.1),
    'prefix' => env(
        'AUTOCACHE_PREFIX',
        Str::slug((string) env('APP_NAME', 'seasonvar'))
            .':'.env('APP_ENV', 'production')
            .':eloquent-autocache:v1',
    ),
    'use_tags' => env('AUTOCACHE_USE_TAGS', false),
    'lock_for' => (int) env('AUTOCACHE_LOCK_FOR', 5),
    'mode' => env('AUTOCACHE_MODE', 'opt-in'),
    'row_cache' => env('AUTOCACHE_ROW_CACHE', false),
    'cache_in_transactions' => env('AUTOCACHE_CACHE_IN_TRANSACTIONS', false),
    'swr' => (int) env('AUTOCACHE_SWR', 0),
    'max_rows' => (int) env('AUTOCACHE_MAX_ROWS', 100),
    'volatile_patterns' => [
        'now()',
        'current_timestamp',
        'rand(',
        'random(',
        'uuid()',
        'newid()',
    ],
    'stats' => env('AUTOCACHE_STATS', false),
    'models' => [
        Country::class,
        Genre::class,
    ],
    'pivot_invalidation' => [
        'enabled' => false,
        'map' => [],
    ],
];
