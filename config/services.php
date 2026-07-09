<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'application_credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'),
        'project_id' => env('GOOGLE_CLOUD_PROJECT', env('GOOGLE_PROJECT_ID')),

        'search_console' => [
            'enabled' => (bool) env('GOOGLE_SEARCH_CONSOLE_ENABLED', false),
            'site_url' => env('GOOGLE_SEARCH_CONSOLE_SITE_URL'),
            'readonly' => (bool) env('GOOGLE_SEARCH_CONSOLE_READONLY', true),
        ],

        'analytics' => [
            'enabled' => (bool) env('GOOGLE_ANALYTICS_ENABLED', false),
            'property_id' => env('GOOGLE_ANALYTICS_PROPERTY_ID'),
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
