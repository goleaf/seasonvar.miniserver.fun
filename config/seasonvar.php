<?php

return [
    'base_url' => env('SEASONVAR_BASE_URL', 'https://seasonvar.ru'),
    'sitemap_url' => env('SEASONVAR_SITEMAP_URL', 'https://seasonvar.ru/sitemap_index.xml'),
    'crawl_delay_seconds' => (int) env('SEASONVAR_CRAWL_DELAY', 3),
    'import' => [
        'chunk_size' => (int) env('SEASONVAR_IMPORT_CHUNK_SIZE', 100),
        'sleep_seconds' => (int) env('SEASONVAR_IMPORT_SLEEP_SECONDS', 60),
        'refresh_after_hours' => (int) env('SEASONVAR_IMPORT_REFRESH_AFTER_HOURS', 24),
        'missing_data_retry_hours' => (int) env('SEASONVAR_IMPORT_MISSING_DATA_RETRY_HOURS', 24),
        'lock_seconds' => (int) env('SEASONVAR_IMPORT_LOCK_SECONDS', 604800),
        'stale_after_minutes' => (int) env('SEASONVAR_IMPORT_STALE_AFTER_MINUTES', 15),
        'transaction_attempts' => (int) env('SEASONVAR_IMPORT_TRANSACTION_ATTEMPTS', 5),
        'transaction_retry_delay_ms' => (int) env('SEASONVAR_IMPORT_TRANSACTION_RETRY_DELAY_MS', 250),
        'storage_maintenance_enabled' => filter_var(env('SEASONVAR_IMPORT_STORAGE_MAINTENANCE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'event_retention_days' => (int) env('SEASONVAR_IMPORT_EVENT_RETENTION_DAYS', 7),
        'snapshot_retention_days' => (int) env('SEASONVAR_IMPORT_SNAPSHOT_RETENTION_DAYS', 14),
        'maintenance_chunk_size' => (int) env('SEASONVAR_IMPORT_MAINTENANCE_CHUNK_SIZE', 500),
    ],
    'queue' => [
        'connection' => env('SEASONVAR_QUEUE_CONNECTION', 'redis'),
        'queue' => env('SEASONVAR_QUEUE_NAME', 'seasonvar-import'),
        'lock_store' => env('SEASONVAR_QUEUE_LOCK_STORE', 'redis'),
        'claim_seconds' => (int) env('SEASONVAR_QUEUE_CLAIM_SECONDS', 86400),
        'worker_timeout' => (int) env('SEASONVAR_QUEUE_WORKER_TIMEOUT', 900),
        'retry_window_seconds' => (int) env('SEASONVAR_QUEUE_RETRY_WINDOW_SECONDS', 21600),
        'finalizer_delay_seconds' => (int) env('SEASONVAR_QUEUE_FINALIZER_DELAY_SECONDS', 60),
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
        'max_per_title' => (int) env('SEASONVAR_RECOMMENDATION_MAX_PER_TITLE', 12),
    ],
];
