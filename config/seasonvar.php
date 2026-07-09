<?php

return [
    'base_url' => env('SEASONVAR_BASE_URL', 'https://seasonvar.ru'),
    'sitemap_url' => env('SEASONVAR_SITEMAP_URL', 'https://seasonvar.ru/sitemap_index.xml'),
    'crawl_delay_seconds' => (int) env('SEASONVAR_CRAWL_DELAY', 3),
    'import' => [
        'parse_batch_size' => (int) env('SEASONVAR_IMPORT_PARSE_BATCH_SIZE', 1000),
        'sleep_seconds' => (int) env('SEASONVAR_IMPORT_SLEEP_SECONDS', 60),
        'refresh_after_hours' => (int) env('SEASONVAR_IMPORT_REFRESH_AFTER_HOURS', 168),
        'season_url_limit' => (int) env('SEASONVAR_IMPORT_SEASON_URL_LIMIT', 200),
        'lock_seconds' => (int) env('SEASONVAR_IMPORT_LOCK_SECONDS', 604800),
        'source_status_backfill_per_cycle' => (int) env('SEASONVAR_SOURCE_STATUS_BACKFILL_PER_CYCLE', 1000),
    ],
    'media_check' => [
        'enabled' => filter_var(env('SEASONVAR_MEDIA_CHECK_ENABLED', true), FILTER_VALIDATE_BOOL),
        'retries' => (int) env('SEASONVAR_MEDIA_CHECK_RETRIES', 3),
        'timeout_seconds' => (int) env('SEASONVAR_MEDIA_CHECK_TIMEOUT', 10),
        'connect_timeout_seconds' => (int) env('SEASONVAR_MEDIA_CHECK_CONNECT_TIMEOUT', 5),
        'backfill_per_cycle' => (int) env('SEASONVAR_MEDIA_CHECK_BACKFILL_PER_CYCLE', 25),
        'refresh_after_hours' => (int) env('SEASONVAR_MEDIA_CHECK_REFRESH_AFTER_HOURS', 168),
    ],
    'media_metadata' => [
        'backfill_per_cycle' => (int) env('SEASONVAR_MEDIA_METADATA_BACKFILL_PER_CYCLE', 100),
    ],
    'media_identity' => [
        'backfill_per_cycle' => (int) env('SEASONVAR_MEDIA_SOURCE_KEY_BACKFILL_PER_CYCLE', 250),
    ],
];
