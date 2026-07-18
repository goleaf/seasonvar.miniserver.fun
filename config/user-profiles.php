<?php

return [
    'username' => [
        'minimum_length' => 3,
        'maximum_length' => 32,
        'change_attempts' => 5,
        'change_decay_seconds' => 3600,
        'reserved' => [
            'admin',
            'administrator',
            'api',
            'auth',
            'catalog',
            'collections',
            'login',
            'logout',
            'moderator',
            'profile',
            'profiles',
            'register',
            'search',
            'settings',
            'support',
            'system',
        ],
    ],
    'biography_maximum_length' => 1200,
    'biography_collapse_after' => 420,
    'pagination' => [
        'reviews' => 10,
        'comments' => 12,
        'collections' => 12,
        'watch_lists' => 18,
    ],
    'uploads' => [
        'avatar_maximum_kilobytes' => 3072,
        'cover_maximum_kilobytes' => 6144,
    ],
    'reports' => [
        'maximum_details_length' => 1500,
        'attempts' => 4,
        'decay_seconds' => 3600,
    ],
];
