<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommentTargetType;
use App\Enums\ContentAudience;
use App\Enums\PublicationStatus;
use App\Enums\ReleaseKind;
use App\Models\Concerns\HasPublicationAvailability;
use Carbon\CarbonInterface;
use Database\Factories\SeasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $catalog_title_id
 * @property int $number
 * @property CarbonInterface|null $latest_episode_released_at
 * @property int|null $episodes_released
 * @property int|null $episodes_total
 * @property ReleaseKind $kind
 * @property PublicationStatus $publication_status
 * @property ContentAudience $audience
 * @property CarbonInterface|null $available_from
 * @property CarbonInterface|null $available_until
 * @property CarbonInterface|null $deleted_at
 */
#[Fillable([
    'catalog_title_id',
    'source_page_id',
    'number',
    'kind',
    'sort_order',
    'title',
    'source_url',
    'source_url_hash',
    'latest_episode_released_at',
    'episodes_released',
    'episodes_total',
    'translation_name',
    'release_status_text',
    'publication_status',
    'audience',
    'available_from',
    'available_until',
])]
class Season extends Model
{
    /** @use HasFactory<SeasonFactory> */
    use HasFactory, HasPublicationAvailability, SoftDeletes;

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'kind' => ReleaseKind::class,
            'sort_order' => 'integer',
            'latest_episode_released_at' => 'date',
            'episodes_released' => 'integer',
            'episodes_total' => 'integer',
            'publication_status' => PublicationStatus::class,
            'audience' => ContentAudience::class,
            'available_from' => 'datetime',
            'available_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CatalogTitle, $this>
     */
    public function catalogTitle(): BelongsTo
    {
        return $this->belongsTo(CatalogTitle::class);
    }

    /**
     * @return BelongsTo<SourcePage, $this>
     */
    public function sourcePage(): BelongsTo
    {
        return $this->belongsTo(SourcePage::class);
    }

    /**
     * @return HasMany<Episode, $this>
     */
    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class)
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->orderBy('number')
            ->orderBy('id');
    }

    /**
     * @return HasMany<LicensedMedia, $this>
     */
    public function licensedMedia(): HasMany
    {
        return $this->hasMany(LicensedMedia::class);
    }

    /** @return HasMany<LicensedMedia, $this> */
    public function publishedMedia(): HasMany
    {
        return $this->licensedMedia()->published();
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'target_id')
            ->where('target_type', CommentTargetType::Season->value);
    }

    /** @return HasMany<ContentRequest, $this> */
    public function contentRequests(): HasMany
    {
        return $this->hasMany(ContentRequest::class);
    }

    /** @return HasMany<ReleaseScheduleEntry, $this> */
    public function releaseScheduleEntries(): HasMany
    {
        return $this->hasMany(ReleaseScheduleEntry::class);
    }
}
