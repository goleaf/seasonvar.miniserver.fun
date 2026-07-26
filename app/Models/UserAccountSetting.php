<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 * @property string|null $locale
 * @property string|null $timezone
 * @property bool|null $autoplay
 * @property bool|null $remember_volume
 * @property int|null $volume
 * @property bool|null $muted
 * @property string|null $playback_speed
 * @property string|null $preferred_quality
 * @property string|null $preferred_variant
 * @property string|null $fallback_variant
 * @property string|null $preferred_playback_mode
 * @property string|null $preferred_subtitle_language
 * @property bool|null $notify_preferred_translation
 * @property bool|null $subtitles_enabled
 * @property bool|null $keyboard_shortcuts_enabled
 * @property bool|null $reduced_motion
 * @property string|null $collection_default_visibility
 * @property int $settings_version
 */
#[Fillable([
    'user_id',
    'locale',
    'timezone',
    'autoplay',
    'remember_volume',
    'volume',
    'muted',
    'playback_speed',
    'preferred_quality',
    'preferred_variant',
    'fallback_variant',
    'preferred_playback_mode',
    'preferred_subtitle_language',
    'notify_preferred_translation',
    'subtitles_enabled',
    'keyboard_shortcuts_enabled',
    'reduced_motion',
    'collection_default_visibility',
    'settings_version',
])]
final class UserAccountSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'user_id';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'autoplay' => 'boolean',
            'remember_volume' => 'boolean',
            'volume' => 'integer',
            'muted' => 'boolean',
            'notify_preferred_translation' => 'boolean',
            'subtitles_enabled' => 'boolean',
            'keyboard_shortcuts_enabled' => 'boolean',
            'reduced_motion' => 'boolean',
            'settings_version' => 'integer',
        ];
    }
}
