<?php

declare(strict_types=1);

return [
    'default_visibility' => 'private',
    'restoration_days' => 30,
    'prune_batch_size' => 200,
    'name_max_length' => 160,
    'description_max_length' => 10_000,
    'seo_title_max_length' => 180,
    'seo_description_max_length' => 500,
    'report_details_max_length' => 2_000,
    'items_per_page' => 24,
    'maximum_items_per_collection' => 5_000,
    'maximum_collections_per_user' => 100,
    'maximum_reorder_items' => 500,
    'supported_locales' => ['ru', 'en'],
    'default_locale' => 'ru',
    'rate_limits' => [
        'create' => ['attempts' => 20, 'decay_seconds' => 3_600],
        'mutate' => ['attempts' => 60, 'decay_seconds' => 60],
        'cover' => ['attempts' => 20, 'decay_seconds' => 3_600],
        'membership' => ['attempts' => 120, 'decay_seconds' => 60],
        'reorder' => ['attempts' => 120, 'decay_seconds' => 60],
        'report' => ['attempts' => 5, 'decay_seconds' => 3_600],
    ],
];
