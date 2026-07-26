<?php

declare(strict_types=1);

return [
    'default_locale' => 'ru',
    'default_timezone' => 'UTC',
    'anonymous_storage_key' => 'seasonvar.account-preferences.v1',
    'playback_speeds' => ['0.50', '0.75', '1.00', '1.25', '1.50', '1.75', '2.00'],
    'defaults' => [
        'autoplay' => false,
        'remember_volume' => true,
        'volume' => 70,
        'muted' => false,
        'playback_speed' => '1.00',
        'preferred_quality' => null,
        'preferred_variant' => null,
        'fallback_variant' => null,
        'preferred_playback_mode' => 'automatic',
        'preferred_subtitle_language' => null,
        'notify_preferred_translation' => false,
        'subtitles_enabled' => false,
        'keyboard_shortcuts_enabled' => true,
        'reduced_motion' => false,
        'collection_default_visibility' => 'private',
    ],
];
