<?php

return [
    'base_url' => env('SEASONVAR_BASE_URL', 'https://seasonvar.ru'),
    'sitemap_url' => env('SEASONVAR_SITEMAP_URL', 'https://seasonvar.ru/sitemap_index.xml'),
    'crawl_delay_seconds' => (int) env('SEASONVAR_CRAWL_DELAY', 3),
    'full_sync' => [
        'discover_limit' => (int) env('SEASONVAR_FULL_SYNC_DISCOVER_LIMIT', 1000000),
        'parse_limit' => (int) env('SEASONVAR_FULL_SYNC_PARSE_LIMIT', 25),
        'sleep_seconds' => (int) env('SEASONVAR_FULL_SYNC_SLEEP_SECONDS', 60),
    ],
];
