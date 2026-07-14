<?php

return [
    'version' => 'v1',
    'minimum_supported_version' => 'v1',
    'default_per_page' => 20,
    'maximum_per_page' => 50,
    'progress_heartbeat_seconds' => 15,
    'sync' => [
        'default_pull_items' => 100,
        'max_pull_items' => 200,
        'max_push_items' => 50,
        'change_retention_days' => 30,
        'mutation_retention_days' => 90,
    ],
];
