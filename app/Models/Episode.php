<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommentTargetType;
use App\Enums\ContentAudience;
use App\Enums\PublicationStatus;
use App\Enums\ReleaseKind;
use App\Models\Concerns\HasPublicationAvailability;
use Carbon\CarbonInterface;
use Database\Factories\EpisodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $season_id
 * @property int $number
 * @property CarbonInterface|null $released_at
 * @property ReleaseKind $kind
 * @property PublicationStatus $publication_status
 * @property ContentAudience $audience
 * @property CarbonInterface|null $available_from
 * @property CarbonInterface|null $available_until
 * @property CarbonInterface|null $deleted_at
 */
#[Fillable([
    'season_id',
    'source_page_id',
    'number',
    'kind',
    'sort_order',
    'title',
    'source_url',
    'source_url_hash',
    'released_at',
    'summary',
    'publication_status',
    'audience',
    'available_from',
    'available_until',
])]
class Episode extends Model
{
    /** @use HasFactory<EpisodeFactory> */
    use HasFactory, HasPublicationAvailability, SoftDeletes;

    /**
     * @return BelongsTo<Season, $this>
     */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /**
     * @return BelongsTo<SourcePage, $this>
     */
    public function sourcePage(): BelongsTo
    {
        return $this->belongsTo(SourcePage::class);
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

    /** @return HasMany<EpisodeViewProgress, $this> */
    public function viewProgress(): HasMany
    {
        return $this->hasMany(EpisodeViewProgress::class);
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'target_id')
            ->where('target_type', CommentTargetType::Episode->value);
    }

    /** @return HasMany<ContentRequest, $this> */
    public function contentRequests(): HasMany
    {
        return $this->hasMany(ContentRequest::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'kind' => ReleaseKind::class,
            'sort_order' => 'integer',
            'released_at' => 'date',
            'publication_status' => PublicationStatus::class,
            'audience' => ContentAudience::class,
            'available_from' => 'datetime',
            'available_until' => 'datetime',
        ];
    }
}
