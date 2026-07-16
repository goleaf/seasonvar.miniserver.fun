<?php

declare(strict_types=1);

return [
    'hdrezka' => [
        'enabled' => (bool) env('HDREZKA_COLLECTION_SYNC_ENABLED', false),
        'provider' => 'hdrezka',
        'index_path' => '/collections.html',
        'http_version' => '2.0',
        'schedule' => env('HDREZKA_COLLECTION_SYNC_SCHEDULE', '03:37'),
        'delay_seconds' => (int) env('HDREZKA_COLLECTION_SYNC_DELAY_SECONDS', 3),
        'max_response_bytes' => (int) env('HDREZKA_COLLECTION_SYNC_MAX_RESPONSE_BYTES', 4_194_304),
        'max_collections' => (int) env('HDREZKA_COLLECTION_SYNC_MAX_COLLECTIONS', 200),
        'max_pages_per_collection' => (int) env('HDREZKA_COLLECTION_SYNC_MAX_PAGES', 100),
        'max_items_per_collection' => (int) env('HDREZKA_COLLECTION_SYNC_MAX_ITEMS', 10_000),
        'lock_store' => env('HDREZKA_COLLECTION_SYNC_LOCK_STORE', env('CACHE_LOCK_STORE', 'redis-locks')),
        'lock_seconds' => (int) env('HDREZKA_COLLECTION_SYNC_LOCK_SECONDS', 21_600),
        'recommendation_rebuild' => [
            'enabled' => (bool) env('HDREZKA_COLLECTION_RECOMMENDATION_REBUILD_ENABLED', true),
            'connection' => env('HDREZKA_COLLECTION_RECOMMENDATION_QUEUE_CONNECTION', env('SEASONVAR_QUEUE_CONNECTION', 'redis')),
            'queue' => env('HDREZKA_COLLECTION_RECOMMENDATION_QUEUE', env('SEASONVAR_QUEUE_NAME', 'seasonvar-import')),
            'timeout' => (int) env('HDREZKA_COLLECTION_RECOMMENDATION_TIMEOUT', 900),
            'unique_seconds' => (int) env('HDREZKA_COLLECTION_RECOMMENDATION_UNIQUE_SECONDS', 21_600),
        ],
        'cover' => [
            'max_source_bytes' => (int) env('HDREZKA_COLLECTION_COVER_MAX_SOURCE_BYTES', 2_097_152),
            'max_source_dimension' => (int) env('HDREZKA_COLLECTION_COVER_MAX_SOURCE_DIMENSION', 8000),
            'max_source_pixels' => (int) env('HDREZKA_COLLECTION_COVER_MAX_SOURCE_PIXELS', 32_000_000),
            'max_width' => (int) env('HDREZKA_COLLECTION_COVER_MAX_WIDTH', 1280),
            'max_height' => (int) env('HDREZKA_COLLECTION_COVER_MAX_HEIGHT', 720),
            'quality' => (int) env('HDREZKA_COLLECTION_COVER_WEBP_QUALITY', 82),
        ],
    ],
];
