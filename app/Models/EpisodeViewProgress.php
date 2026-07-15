<?php

declare(strict_types=1);

namespace App\Models;

use App\Policies\EpisodeViewProgressPolicy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $catalog_title_id
 * @property int $episode_id
 * @property int|null $licensed_media_id
 * @property int $position_seconds
 * @property int $duration_seconds
 * @property int $progress_percent
 * @property CarbonInterface|null $first_started_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface $last_watched_at
 */
#[Fillable([
    'user_id',
    'catalog_title_id',
    'episode_id',
    'licensed_media_id',
    'position_seconds',
    'duration_seconds',
    'progress_percent',
    'first_started_at',
    'playback_session_id',
    'playback_event_sequence',
    'completed_at',
    'last_watched_at',
])]
#[UsePolicy(EpisodeViewProgressPolicy::class)]
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

    /** @return BelongsTo<LicensedMedia, $this> */
    public function licensedMedia(): BelongsTo
    {
        return $this->belongsTo(LicensedMedia::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'progress_percent' => 'integer',
            'first_started_at' => 'datetime',
            'playback_event_sequence' => 'integer',
            'completed_at' => 'datetime',
            'last_watched_at' => 'datetime',
        ];
    }
}
