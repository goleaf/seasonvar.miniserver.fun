<?php

declare(strict_types=1);

return [
    'enabled' => env('COMMENTS_ENABLED', true),

    'targets' => [
        'title',
        'season',
        'episode',
        'collection',
    ],

    'body' => [
        'maximum_length' => 5_000,
        'maximum_lines' => 40,
        'maximum_links' => 2,
        'maximum_mentions' => 5,
        'maximum_repeated_characters' => 30,
        'collapse_after' => 700,
        'excerpt_length' => 360,
    ],

    'pagination' => [
        'comments_per_page' => 15,
        'replies_per_page' => 20,
        'maximum_replies_loaded' => 200,
        'administration_per_page' => 25,
        'profile_per_page' => 15,
    ],

    'editing' => [
        'window_minutes' => 30,
        'restoration_days' => 7,
    ],

    'anti_spam' => [
        'duplicate_window_seconds' => 90,
        'new_account_review_minutes' => 15,
    ],

    'rate_limits' => [
        'create' => ['attempts' => 5, 'global_attempts' => 10, 'decay_seconds' => 60],
        'reply' => ['attempts' => 10, 'global_attempts' => 20, 'decay_seconds' => 60],
        'edit' => ['attempts' => 10, 'global_attempts' => 20, 'decay_seconds' => 60],
        'reaction' => ['attempts' => 60, 'global_attempts' => 120, 'decay_seconds' => 60],
        'report' => ['attempts' => 5, 'global_attempts' => 10, 'decay_seconds' => 3_600],
        'relationship' => ['attempts' => 10, 'global_attempts' => 20, 'decay_seconds' => 60],
    ],
];
