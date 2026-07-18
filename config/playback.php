<?php

return [
    'signed_url_ttl_seconds' => (int) env('PLAYBACK_SIGNED_URL_TTL_SECONDS', 300),
    'autoplay_countdown_seconds' => (int) env('PLAYBACK_AUTOPLAY_COUNTDOWN_SECONDS', 8),
    'progress' => [
        'session_ttl_seconds' => (int) env('PLAYBACK_PROGRESS_SESSION_TTL_SECONDS', 21600),
        'max_duration_seconds' => (int) env('PLAYBACK_PROGRESS_MAX_DURATION_SECONDS', 86400),
        'position_tolerance_seconds' => (int) env('PLAYBACK_PROGRESS_POSITION_TOLERANCE_SECONDS', 5),
        'completion_percent' => (int) env('PLAYBACK_PROGRESS_COMPLETION_PERCENT', 95),
        'completion_remaining_seconds' => (int) env('PLAYBACK_PROGRESS_COMPLETION_REMAINING_SECONDS', 15),
    ],
    'allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PLAYBACK_ALLOWED_HOSTS', '11cdn.org')),
    ))),
    'enforce_public_dns' => filter_var(env('PLAYBACK_ENFORCE_PUBLIC_DNS', true), FILTER_VALIDATE_BOOL),
    'allowed_storage_disks' => ['local', 's3'],
    'allowed_formats' => ['m3u8', 'mp4', 'm4v', 'webm', 'mov'],
    'supported_qualities' => ['4320p', '2160p', '1440p', '1080p', '720p', '576p', '540p', '480p', '360p', '240p'],
    'provider_priority' => [
        'seasonvar_parsed' => 100,
        'local' => 90,
        's3' => 80,
        'external_playlist' => 50,
    ],
    'downloads' => [
        'allowed_formats' => ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi'],
        'chunk_bytes' => 65_536,
        'timeout_seconds' => 3600,
        'connect_timeout_seconds' => 10,
        'retry_times' => 1,
        'retry_sleep_milliseconds' => 250,
        'requests_per_minute' => 12,
        'media_requests_per_minute' => 4,
    ],
];
