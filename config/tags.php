<?php

declare(strict_types=1);

return [
    'canonical_schema' => env('TAG_CANONICAL_SCHEMA'),
    'supported_locales' => ['ru', 'en'],
    'default_locale' => 'ru',
    'label_min_length' => 2,
    'label_max_length' => 80,
    'personal_description_max_length' => 1_000,
    'personal_assignment_limit' => 50,
    'personal_tags_per_user' => 250,
    'public_search_limit' => 10,
    'related_limit' => 12,
    'popular_limit' => 24,
    'synonym_expansion_limit' => 12,
    'restoration_days' => 30,
    'creation_rate_limit' => 20,
    'creation_rate_decay_seconds' => 3_600,
    'reserved_names' => [
        'ru' => ['администратор', 'модератор', 'официальный', 'системный'],
        'en' => ['administrator', 'moderator', 'official', 'system'],
    ],
];
