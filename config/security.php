<?php

return [
    'external_playlist_enforce_public_dns' => filter_var(env('EXTERNAL_PLAYLIST_ENFORCE_PUBLIC_DNS', true), FILTER_VALIDATE_BOOL),
    'rate_limits' => [
        'catalog_search' => (int) env('RATE_LIMIT_CATALOG_SEARCH', 60),
        'playback_session' => (int) env('RATE_LIMIT_PLAYBACK_SESSION', 60),
        'progress' => (int) env('RATE_LIMIT_PROGRESS', 120),
        'rating' => (int) env('RATE_LIMIT_RATING', 30),
        'watchlist' => (int) env('RATE_LIMIT_WATCHLIST', 30),
        'history' => (int) env('RATE_LIMIT_HISTORY', 30),
        'import_admin' => (int) env('RATE_LIMIT_IMPORT_ADMIN', 10),
        'catalog_admin' => (int) env('RATE_LIMIT_CATALOG_ADMIN', 60),
        'source_health' => (int) env('RATE_LIMIT_SOURCE_HEALTH', 120),
    ],
];
