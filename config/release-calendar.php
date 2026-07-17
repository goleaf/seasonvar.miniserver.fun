<?php

return [
    'enabled' => true,
    'supported_locales' => ['ru', 'en'],
    'default_timezone' => env('RELEASE_CALENDAR_TIMEZONE', config('app.timezone', 'UTC')),
    'per_page' => 24,
    'upcoming_days' => 366,
    'recent_days' => 60,
    'maximum_window_days' => 400,
    'week_start' => 1,
    'date_change_notification_threshold_minutes' => 30,
    'rate_limits' => [
        'subscription_per_minute' => 30,
        'administration_per_minute' => 60,
    ],
];
