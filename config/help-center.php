<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'default_locale' => 'ru',
    'fallback_locale' => 'ru',
    'supported_locales' => ['ru', 'en'],
    'articles_per_page' => 12,
    'search_candidates' => 120,
    'autocomplete_limit' => 7,
    'related_limit' => 4,
    'popular_limit' => 6,
    'featured_limit' => 6,
    'maximum_category_depth' => 2,
    'review_cycle_days' => 180,
    'feedback' => [
        'attempts' => 12,
        'decay_seconds' => 3_600,
    ],
    'reports' => [
        'attempts' => 5,
        'decay_seconds' => 3_600,
        'details_maximum' => 1_000,
    ],
    'allowed_callouts' => ['information', 'note', 'warning', 'privacy', 'security', 'limitation', 'next_step'],
    'allowed_issue_types' => [
        'video_unavailable', 'video_loading_failure', 'excessive_buffering', 'audio_missing', 'audio_sync',
        'subtitles_missing', 'subtitle_sync', 'quality_unavailable', 'fullscreen_problem',
        'autoplay_problem', 'progress_not_saved', 'continue_watching_problem', 'account_problem',
        'regional_access_problem', 'premium_access_problem', 'accessibility_problem',
        'browser_compatibility', 'mobile_device_problem', 'other_technical_issue',
    ],
    'allowed_request_types' => [
        'serial', 'season', 'episode', 'translation', 'subtitles', 'quality_upgrade',
        'metadata_correction', 'episode_list_correction', 'broken_content_restoration',
        'other_content_request',
    ],
    'allowed_route_names' => [
        'home', 'titles.index', 'search.index', 'calendar.upcoming', 'requests.index',
        'requests.create', 'issues.create', 'library.index', 'viewing-activity',
        'login', 'register', 'password.request', 'verification.notice', 'settings.index',
        'profile.security', 'discover.index', 'notifications.index', 'premium.index',
    ],
];
