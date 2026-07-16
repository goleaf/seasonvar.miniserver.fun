<?php

return [
    'enabled' => true,
    'supported_locales' => ['ru', 'en'],
    'context_ttl_minutes' => 120,
    'per_page' => 12,
    'support_per_page' => 20,
    'duplicate_candidate_limit' => 12,
    'maximum_attachments' => 3,
    'maximum_image_pixels' => 24_000_000,
    'maximum_image_dimension' => 6_000,
    'retention' => [
        'diagnostics_days' => 180,
        'attachments_days_after_closed' => 365,
    ],
    'rate_limits' => [
        'create_per_hour' => 6,
        'update_per_minute' => 12,
        'engagement_per_minute' => 20,
        'message_per_minute' => 8,
        'reopen_per_day' => 3,
        'upload_per_minute' => 30,
    ],
    'browser_families' => ['chromium', 'edge', 'firefox', 'safari', 'opera', 'samsung', 'unknown'],
    'operating_systems' => ['windows', 'macos', 'ios', 'android', 'linux', 'chromeos', 'other', 'unknown'],
    'device_categories' => ['desktop', 'tablet', 'mobile', 'television', 'unknown'],
    'feature_codes' => ['player', 'title', 'season', 'episode', 'catalog', 'search', 'filters', 'library', 'account', 'notifications', 'calendar', 'general'],
    'support_teams' => ['support', 'content', 'video', 'subtitles', 'accounts', 'infrastructure', 'accessibility'],
    'source_actions' => ['under_review', 'disabled', 'restored'],
];
