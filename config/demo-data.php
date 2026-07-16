<?php

declare(strict_types=1);

return [
    'version' => 'seasonvar-demo-v1',
    'enabled' => true,
    'user_count' => 100,
    'coverage_numerator' => 1,
    'coverage_denominator' => 2,
    'chunk_size' => 1_000,
    'minimum_free_bytes' => 25 * 1024 * 1024 * 1024,
    'personal_tags' => [
        'minimum' => 12,
        'maximum' => 40,
        'per_title_minimum' => 2,
        'per_title_maximum' => 7,
    ],
    'collections' => [
        'minimum' => 8,
        'maximum' => 20,
        'per_title_minimum' => 1,
        'per_title_maximum' => 3,
    ],
    'requests' => [
        'minimum' => 3,
        'maximum' => 10,
    ],
    'issues' => [
        'minimum' => 2,
        'maximum' => 6,
    ],
    'notifications' => [
        'minimum' => 20,
        'maximum' => 60,
    ],
    'public_tag_target' => 800,
    'asset_disk' => env('UPLOAD_DISK', 'uploads'),
    'asset_prefix' => 'demo-data/seasonvar-demo-v1',
];
