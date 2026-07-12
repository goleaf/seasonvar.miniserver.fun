<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'catalog_title_id',
    'episode_id',
    'position_seconds',
    'duration_seconds',
    'completed_at',
    'last_watched_at',
])]
class EpisodeViewProgress extends Model
{
    protected $table = 'episode_view_progress';

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

    /** @return BelongsTo<Episode, $this> */
    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'completed_at' => 'datetime',
            'last_watched_at' => 'datetime',
        ];
    }
}
