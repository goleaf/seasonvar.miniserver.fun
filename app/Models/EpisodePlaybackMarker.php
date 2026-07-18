<?php

declare(strict_types=1);

namespace App\Models;

use App\Policies\EpisodePlaybackMarkerPolicy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int $catalog_title_id
 * @property int $episode_id
 * @property int $position_seconds
 * @property int $version
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'public_id',
    'user_id',
    'catalog_title_id',
    'episode_id',
    'position_seconds',
    'version',
])]
#[UsePolicy(EpisodePlaybackMarkerPolicy::class)]
final class EpisodePlaybackMarker extends Model
{
    protected static function booted(): void
    {
        self::creating(function (self $marker): void {
            $marker->public_id = $marker->public_id ?: (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

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
            'version' => 'integer',
        ];
    }
}
