<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'per_page' => 20,
    'admin_per_page' => 25,
    'autocomplete_limit' => 8,
    'duplicate_candidate_limit' => 12,
    'max_source_links' => 3,
    'max_external_ids' => 5,
    'supported_locales' => ['ru', 'en'],
    'language_codes' => ['ru', 'en', 'uk', 'de', 'fr', 'es', 'it', 'ja', 'ko', 'zh'],
    'translation_types' => ['dubbed', 'voice_over', 'original_audio'],
    'correction_fields' => ['title', 'description', 'year', 'country', 'genre', 'cast', 'director', 'episode_list', 'release_date', 'poster', 'translation'],
    'rate_limits' => [
        'create' => ['attempts' => 4, 'global_attempts' => 8, 'decay_seconds' => 3600],
        'edit' => ['attempts' => 10, 'global_attempts' => 20, 'decay_seconds' => 3600],
        'vote' => ['attempts' => 30, 'global_attempts' => 80, 'decay_seconds' => 60],
        'follow' => ['attempts' => 30, 'global_attempts' => 80, 'decay_seconds' => 60],
        'clarify' => ['attempts' => 8, 'global_attempts' => 16, 'decay_seconds' => 3600],
        'moderate' => ['attempts' => 60, 'global_attempts' => 120, 'decay_seconds' => 60],
    ],
];
