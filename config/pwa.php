<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('PWA_ENABLED', true),

    'manifest' => [
        'name' => 'Seasonvar',
        'short_name' => 'Seasonvar',
        'theme_color' => '#ecfdf5',
        'background_color' => '#f8fafc',
    ],

    'offline' => [
        'library_limit' => 300,
        'help_limit' => 60,
        'poster_cache_limit' => 80,
        'poster_prefetch_limit' => 12,
        'queue_limit' => 100,
        'queue_batch_limit' => 50,
        'queue_retention_days' => 30,
    ],

    'push' => [
        'enabled' => (bool) env('PWA_PUSH_ENABLED', false),
        'public_key' => env('PWA_VAPID_PUBLIC_KEY'),
        'private_key' => env('PWA_VAPID_PRIVATE_KEY'),
        'subject' => env('PWA_VAPID_SUBJECT'),
        'allowed_hosts' => [
            'fcm.googleapis.com',
            '*.push.services.mozilla.com',
            'web.push.apple.com',
            '*.notify.windows.com',
        ],
        'timeout_seconds' => 8,
        'connect_timeout_seconds' => 3,
        'retry_times' => 2,
        'retry_sleep_milliseconds' => 250,
        'failure_disable_threshold' => 5,
    ],
];
