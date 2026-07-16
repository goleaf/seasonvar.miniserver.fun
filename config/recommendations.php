<?php

return [
    'version' => 'task18-v5',
    'section_limit' => 12,
    'page_size' => 24,
    'candidate_limit' => 180,
    'history_title_limit' => 120,
    'meaningful_progress_percent' => 10,
    'meaningful_progress_seconds' => 180,
    'repeat_suppression' => [
        'max_ids' => 96,
        'days' => 7,
    ],
    'diversity' => [
        'franchise_limit' => 2,
        'primary_genre_limit' => 5,
        'leading_actor_limit' => 4,
    ],
    'soft_demotions' => [
        'watchlist' => 40,
        'planned' => 30,
        'watching' => 80,
        'completed' => 100,
        'dropped' => 120,
    ],
    'availability_boosts' => [
        'quality' => 12,
        'variant' => 12,
        'subtitles' => 6,
    ],
    'top_rated' => [
        'default_source' => 'kinopoisk',
        'minimum_votes' => [
            'portal' => 5,
            'kinopoisk' => 1_000,
            'imdb' => 1_000,
        ],
    ],
    'trending' => [
        'default_period' => 'week',
        'maximum_period_days' => 30,
    ],
    'random' => [
        'maximum_probes' => 12,
        'probe_size' => 8,
    ],
    'personalized' => [
        'history_weight' => 120,
        'completed_weight' => 160,
        'watchlist_weight' => 140,
        'status_weight' => 135,
        'collection_weight' => 110,
        'personal_tag_weight' => 100,
        'rating_weight' => 130,
        'public_fallback_limit' => 24,
    ],
];
