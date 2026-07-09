<?php

return [
    'base_url' => env('SEASONVAR_BASE_URL', 'https://seasonvar.ru'),
    'sitemap_url' => env('SEASONVAR_SITEMAP_URL', 'https://seasonvar.ru/sitemap_index.xml'),
    'crawl_delay_seconds' => (int) env('SEASONVAR_CRAWL_DELAY', 3),
    'import' => [
        'chunk_size' => (int) env('SEASONVAR_IMPORT_CHUNK_SIZE', 100),
        'sleep_seconds' => (int) env('SEASONVAR_IMPORT_SLEEP_SECONDS', 60),
        'refresh_after_hours' => (int) env('SEASONVAR_IMPORT_REFRESH_AFTER_HOURS', 168),
        'missing_data_retry_hours' => (int) env('SEASONVAR_IMPORT_MISSING_DATA_RETRY_HOURS', 24),
        'lock_seconds' => (int) env('SEASONVAR_IMPORT_LOCK_SECONDS', 604800),
        'stale_after_minutes' => (int) env('SEASONVAR_IMPORT_STALE_AFTER_MINUTES', 15),
        'transaction_attempts' => (int) env('SEASONVAR_IMPORT_TRANSACTION_ATTEMPTS', 5),
    ],
    'media_check' => [
        'enabled' => filter_var(env('SEASONVAR_MEDIA_CHECK_ENABLED', true), FILTER_VALIDATE_BOOL),
        'retries' => (int) env('SEASONVAR_MEDIA_CHECK_RETRIES', 3),
        'timeout_seconds' => (int) env('SEASONVAR_MEDIA_CHECK_TIMEOUT', 10),
        'connect_timeout_seconds' => (int) env('SEASONVAR_MEDIA_CHECK_CONNECT_TIMEOUT', 5),
        'chunk_size' => (int) env('SEASONVAR_MEDIA_CHECK_CHUNK_SIZE', 25),
        'refresh_after_hours' => (int) env('SEASONVAR_MEDIA_CHECK_REFRESH_AFTER_HOURS', 168),
    ],
    'media_metadata' => [
        'chunk_size' => (int) env('SEASONVAR_MEDIA_METADATA_CHUNK_SIZE', 100),
    ],
    'media_identity' => [
        'chunk_size' => (int) env('SEASONVAR_MEDIA_SOURCE_KEY_CHUNK_SIZE', 250),
    ],
    'recommendations' => [
        'chunk_size' => (int) env('SEASONVAR_RECOMMENDATION_CHUNK_SIZE', 100),
        'min_score' => (int) env('SEASONVAR_RECOMMENDATION_MIN_SCORE', 600),
    ],
];
