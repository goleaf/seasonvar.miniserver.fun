<?php

namespace App\Models;

use Database\Factories\LicensedMediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    use HasFactory;

    protected $attributes = [
        'has_subtitles' => false,
    ];

    /**
     * @param  Builder<LicensedMedia>  $query
     * @return Builder<LicensedMedia>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'has_subtitles' => 'boolean',
            'last_http_status' => 'integer',
            'published_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }
}
