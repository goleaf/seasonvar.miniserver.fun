<?php

return [
    'base_url' => env('SEASONVAR_BASE_URL', 'https://seasonvar.ru'),
    'sitemap_url' => env('SEASONVAR_SITEMAP_URL', 'https://seasonvar.ru/sitemap_index.xml'),
    'crawl_delay_seconds' => (int) env('SEASONVAR_CRAWL_DELAY', 3),
    'import' => [
        'parse_batch_size' => (int) env('SEASONVAR_IMPORT_PARSE_BATCH_SIZE', 25),
        'sleep_seconds' => (int) env('SEASONVAR_IMPORT_SLEEP_SECONDS', 60),
        'refresh_after_hours' => (int) env('SEASONVAR_IMPORT_REFRESH_AFTER_HOURS', 168),
        'season_url_limit' => (int) env('SEASONVAR_IMPORT_SEASON_URL_LIMIT', 200),
    ],
    'media_check' => [
        'enabled' => filter_var(env('SEASONVAR_MEDIA_CHECK_ENABLED', true), FILTER_VALIDATE_BOOL),
        'retries' => (int) env('SEASONVAR_MEDIA_CHECK_RETRIES', 3),
        'timeout_seconds' => (int) env('SEASONVAR_MEDIA_CHECK_TIMEOUT', 10),
        'connect_timeout_seconds' => (int) env('SEASONVAR_MEDIA_CHECK_CONNECT_TIMEOUT', 5),
    ],
];
