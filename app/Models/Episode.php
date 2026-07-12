<?php

namespace App\Models;

use App\Enums\ContentAudience;
use App\Enums\PublicationStatus;
use App\Enums\ReleaseKind;
use App\Models\Concerns\HasPublicationAvailability;
use Database\Factories\EpisodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    /** @return HasMany<EpisodeViewProgress, $this> */
    public function viewProgress(): HasMany
    {
        return $this->hasMany(EpisodeViewProgress::class);
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
