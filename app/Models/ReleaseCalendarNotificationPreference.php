<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'premiere_notifications', 'season_notifications', 'episode_notifications',
    'translation_notifications', 'subtitle_notifications', 'date_change_notifications',
    'postponed_notifications', 'cancelled_notifications', 'portal_publication_notifications',
])]
final class ReleaseCalendarNotificationPreference extends Model
{
    /** @var list<string> */
    public const FIELDS = [
        'premiere_notifications', 'season_notifications', 'episode_notifications',
        'translation_notifications', 'subtitle_notifications', 'date_change_notifications',
        'postponed_notifications', 'cancelled_notifications', 'portal_publication_notifications',
    ];

    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $attributes = [
        'premiere_notifications' => true, 'season_notifications' => true, 'episode_notifications' => true,
        'translation_notifications' => true, 'subtitle_notifications' => true, 'date_change_notifications' => true,
        'postponed_notifications' => true, 'cancelled_notifications' => true, 'portal_publication_notifications' => true,
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'premiere_notifications' => 'boolean', 'season_notifications' => 'boolean',
            'episode_notifications' => 'boolean', 'translation_notifications' => 'boolean',
            'subtitle_notifications' => 'boolean', 'date_change_notifications' => 'boolean',
            'postponed_notifications' => 'boolean', 'cancelled_notifications' => 'boolean',
            'portal_publication_notifications' => 'boolean',
        ];
    }
}
