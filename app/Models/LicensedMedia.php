<?php

namespace App\Models;

use App\Enums\ContentAudience;
use App\Enums\MediaHealthErrorCategory;
use App\Enums\MediaHealthStatus;
use App\Models\Concerns\HasPublicationAvailability;
use Carbon\CarbonInterface;
use Database\Factories\LicensedMediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int|null $catalog_title_id
 * @property int|null $season_id
 * @property int|null $episode_id
 * @property string $storage_disk
 * @property string $path
 * @property string|null $playback_url
 * @property string $status
 * @property string|null $quality
 * @property string|null $translation_name
 * @property string|null $variant_name
 * @property string|null $variant_key
 * @property string|null $format
 * @property string|null $check_status
 * @property MediaHealthStatus $health_status
 * @property ContentAudience $audience
 * @property CarbonInterface|null $published_at
 * @property CarbonInterface|null $available_from
 * @property CarbonInterface|null $available_until
 * @property CarbonInterface|null $checked_at
 * @property CarbonInterface|null $last_successful_check_at
 * @property CarbonInterface|null $next_check_at
 * @property CarbonInterface|null $deleted_at
 */
#[Fillable([
    'catalog_title_id',
    'season_id',
    'episode_id',
    'title',
    'storage_disk',
    'path',
    'playback_url',
    'duration_seconds',
    'status',
    'published_at',
    'audience',
    'available_from',
    'available_until',
    'source_media_key',
    'source_url',
    'quality',
    'translation_name',
    'variant_type',
    'variant_name',
    'variant_key',
    'has_subtitles',
    'format',
    'check_status',
    'health_status',
    'last_http_status',
    'checked_at',
    'last_successful_check_at',
    'last_error_category',
    'consecutive_failures',
    'check_latency_ms',
    'next_check_at',
])]
class LicensedMedia extends Model
{
    /** @use HasFactory<LicensedMediaFactory> */
    use HasFactory, HasPublicationAvailability, SoftDeletes;

    protected $attributes = [
        'has_subtitles' => false,
        'health_status' => 'active',
        'consecutive_failures' => 0,
    ];

    /**
     * @return BelongsTo<CatalogTitle, $this>
     */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /**
     * @return BelongsTo<Season, $this>
     */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /**
     * @return BelongsTo<Episode, $this>
     */
    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    /**
     * @param  Builder<LicensedMedia>  $query
     * @return Builder<LicensedMedia>
     */
    public function scopeForAvailableReleases(Builder $query, ?User $user): Builder
    {
        return $query
            ->where(function (Builder $query) use ($user): void {
                $query
                    ->whereNull('season_id')
                    ->orWhereIn('season_id', Season::query()->availableTo($user)->select('id'));
            })
            ->where(function (Builder $query) use ($user): void {
                $query
                    ->whereNull('episode_id')
                    ->orWhereIn('episode_id', Episode::query()
                        ->availableTo($user)
                        ->whereIn('season_id', Season::query()->availableTo($user)->select('id'))
                        ->select('id'));
            });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithPlaybackLocation(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where(function (Builder $query): void {
                    $query->whereNotNull('playback_url')->where('playback_url', '!=', '');
                })
                ->orWhere('path', '!=', '');
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutKnownFailures(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('health_status'), [
            MediaHealthStatus::Active->value,
            MediaHealthStatus::Degraded->value,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'has_subtitles' => 'boolean',
            'last_http_status' => 'integer',
            'health_status' => MediaHealthStatus::class,
            'last_error_category' => MediaHealthErrorCategory::class,
            'consecutive_failures' => 'integer',
            'check_latency_ms' => 'integer',
            'published_at' => 'datetime',
            'audience' => ContentAudience::class,
            'available_from' => 'datetime',
            'available_until' => 'datetime',
            'checked_at' => 'datetime',
            'last_successful_check_at' => 'datetime',
            'next_check_at' => 'datetime',
        ];
    }
}
