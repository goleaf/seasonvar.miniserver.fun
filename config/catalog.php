<?php

return [
    'user_rating' => [
        'minimum' => 1,
        'maximum' => 10,
    ],

    'query_rate_limit' => [
        'human_per_minute' => (int) env('RATE_LIMIT_CATALOG_QUERY_HUMAN', 120),
        'bot_per_minute' => (int) env('RATE_LIMIT_CATALOG_QUERY_BOT', 6),
    ],

    'directories' => [
        'minimum_year' => 1900,
        'maximum_year' => null,
        'per_page' => 36,
        'people_per_page' => 48,
        'search_max_length' => 80,
    ],
];
