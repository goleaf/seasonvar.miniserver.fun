<?php

return [
    'enabled' => true,
    'supported_locales' => ['ru', 'en'],
    'default_locale' => 'ru',
    'per_page' => 10,
    'profile_per_page' => 12,
    'admin_per_page' => 20,
    'maximum_targeted_cache_titles' => 1_000,
    'title' => [
        'minimum_length' => 5,
        'maximum_length' => 120,
        'generic_values' => ['отзыв', 'мой отзыв', 'review', 'my review', 'хорошо', 'нормально'],
    ],
    'body' => [
        'minimum_length' => 100,
        'maximum_length' => 12_000,
        'maximum_lines' => 80,
        'maximum_links' => 2,
        'maximum_repeated_characters' => 30,
    ],
    'editing' => [
        'restoration_days' => 30,
    ],
    'verification' => [
        'minimum_progress_percent' => 10,
        'minimum_position_seconds' => 300,
    ],
    'anti_spam' => [
        'new_account_review_minutes' => 60,
        'duplicate_body_window_days' => 7,
    ],
    'rate_limits' => [
        'create_global' => ['attempts' => 6, 'decay_seconds' => 3_600],
        'create' => ['attempts' => 2, 'decay_seconds' => 600],
        'edit_global' => ['attempts' => 30, 'decay_seconds' => 3_600],
        'edit' => ['attempts' => 10, 'decay_seconds' => 3_600],
        'delete' => ['attempts' => 6, 'decay_seconds' => 600],
        'restore' => ['attempts' => 6, 'decay_seconds' => 600],
        'vote_global' => ['attempts' => 120, 'decay_seconds' => 60],
        'vote' => ['attempts' => 60, 'decay_seconds' => 60],
        'report_global' => ['attempts' => 12, 'decay_seconds' => 3_600],
        'report' => ['attempts' => 6, 'decay_seconds' => 3_600],
        'reveal_global' => ['attempts' => 240, 'decay_seconds' => 60],
        'reveal' => ['attempts' => 120, 'decay_seconds' => 60],
        'moderate' => ['attempts' => 60, 'decay_seconds' => 60],
        'restrict' => ['attempts' => 30, 'decay_seconds' => 60],
    ],
];
