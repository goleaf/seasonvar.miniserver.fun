<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'catalog_title_id', 'premiere_notifications', 'season_notifications', 'episode_notifications',
    'translation_notifications', 'subtitle_notifications', 'portal_publication_notifications', 'date_change_notifications', 'version',
])]
final class ReleaseCalendarSubscription extends Model
{
    protected $attributes = [
        'premiere_notifications' => true,
        'season_notifications' => true,
        'episode_notifications' => true,
        'translation_notifications' => true,
        'subtitle_notifications' => true,
        'portal_publication_notifications' => true,
        'date_change_notifications' => true,
        'version' => 1,
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CatalogTitle, $this> */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'premiere_notifications' => 'boolean', 'season_notifications' => 'boolean',
            'episode_notifications' => 'boolean', 'translation_notifications' => 'boolean',
            'subtitle_notifications' => 'boolean', 'portal_publication_notifications' => 'boolean',
            'date_change_notifications' => 'boolean', 'version' => 'integer',
        ];
    }
}
