<?php

return [
    'external_playlist_enforce_public_dns' => filter_var(env('EXTERNAL_PLAYLIST_ENFORCE_PUBLIC_DNS', true), FILTER_VALIDATE_BOOL),

    'csp_report_only' => [
        'enabled' => filter_var(env('SECURITY_CSP_REPORT_ONLY', true), FILTER_VALIDATE_BOOL),
        'image_sources' => array_merge(
            ["'self'", 'data:'],
            preg_split('/\s*,\s*/', (string) env('SECURITY_CSP_IMAGE_SOURCES', 'https:'), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ),
        'media_sources' => array_merge(
            ["'self'", 'blob:'],
            preg_split('/\s*,\s*/', (string) env('SECURITY_CSP_MEDIA_SOURCES', 'https://11cdn.org,https://*.11cdn.org'), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ),
        'connect_sources' => array_merge(
            ["'self'"],
            preg_split('/\s*,\s*/', (string) env('SECURITY_CSP_CONNECT_SOURCES', 'https://11cdn.org,https://*.11cdn.org'), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ),
    ],
];
