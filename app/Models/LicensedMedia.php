<?php

namespace App\Models;

use App\Enums\ContentAudience;
use App\Models\Concerns\HasPublicationAvailability;
use Database\Factories\LicensedMediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    'last_http_status',
    'checked_at',
])]
class LicensedMedia extends Model
{
    /** @use HasFactory<LicensedMediaFactory> */
    use HasFactory, HasPublicationAvailability, SoftDeletes;

    protected $attributes = [
        'has_subtitles' => false,
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
                    ->orWhereHas('season', fn (Builder $query): Builder => $query->availableTo($user));
            })
            ->where(function (Builder $query) use ($user): void {
                $query
                    ->whereNull('episode_id')
                    ->orWhereHas('episode', fn (Builder $query): Builder => $query
                        ->availableTo($user)
                        ->whereHas('season', fn (Builder $query): Builder => $query->availableTo($user)));
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
        return $query
            ->where(function (Builder $query): void {
                $query
                    ->whereNull($this->qualifyColumn('check_status'))
                    ->orWhereNotIn($this->qualifyColumn('check_status'), ['unavailable', 'check_failed', 'invalid_url']);
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereNull($this->qualifyColumn('last_http_status'))
                    ->orWhere($this->qualifyColumn('last_http_status'), '<', 400);
            });
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
            'published_at' => 'datetime',
            'audience' => ContentAudience::class,
            'available_from' => 'datetime',
            'available_until' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    protected function publicationStatusColumn(): string
    {
        return 'status';
    }

    protected function publishedStatusValue(): string
    {
        return 'published';
    }

    protected function usesPublishedAtGate(): bool
    {
        return true;
    }
}
