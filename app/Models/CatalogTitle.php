<?php

namespace App\Models;

use App\Enums\ContentAudience;
use App\Enums\PublicationStatus;
use App\Enums\ReleaseKind;
use App\Models\Concerns\HasPublicationAvailability;
use Database\Factories\CatalogTitleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'source_id',
    'source_page_id',
    'external_id',
    'slug',
    'title',
    'original_title',
    'type',
    'year',
    'description',
    'poster_url',
    'source_url',
    'source_url_hash',
    'content_hash',
    'provider_field_values',
    'is_published',
    'publication_status',
    'audience',
    'available_from',
    'available_until',
    'indexed_at',
    'relation_metadata_version',
])]
class CatalogTitle extends Model
{
    /** @use HasFactory<CatalogTitleFactory> */
    use HasFactory, HasPublicationAvailability, SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'relation_metadata_version' => 0,
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Keep every current public route binding inside the publication boundary.
     *
     * @param  Model|Builder<CatalogTitle>|\Illuminate\Database\Eloquent\Relations\Relation<*, *, *>  $query
     * @return Builder<CatalogTitle>
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)->availableTo(request()->user());
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $current = parent::resolveRouteBinding($value, $field);

        if ($current !== null || ! request()->routeIs('titles.show') || ($field ?? $this->getRouteKeyName()) !== 'slug') {
            return $current;
        }

        return $this->newQuery()
            ->availableTo(request()->user())
            ->whereHas('historicalSlugs', fn (Builder $query): Builder => $query->where('slug', $value))
            ->first();
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return BelongsTo<SourcePage, $this>
     */
    public function sourcePage(): BelongsTo
    {
        return $this->belongsTo(SourcePage::class);
    }

    /**
     * @return HasMany<Season, $this>
     */
    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class)
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->orderBy('number')
            ->orderBy('id');
    }

    /**
     * @return HasOne<Season, $this>
     */
    public function latestSeason(): HasOne
    {
        return $this->hasOne(Season::class)
            ->where('kind', ReleaseKind::Regular->value)
            ->latestOfMany('number');
    }

    /**
     * @return HasManyThrough<Episode, Season, $this>
     */
    public function episodes(): HasManyThrough
    {
        return $this->hasManyThrough(Episode::class, Season::class)
            ->orderBy('seasons.kind')
            ->orderBy('seasons.sort_order')
            ->orderBy('episodes.kind')
            ->orderBy('episodes.sort_order')
            ->orderBy('episodes.number')
            ->orderBy('episodes.id');
    }

    /**
     * @return HasMany<LicensedMedia, $this>
     */
    public function licensedMedia(): HasMany
    {
        return $this->hasMany(LicensedMedia::class);
    }

    /**
     * @return HasMany<LicensedMedia, $this>
     */
    public function publishedLicensedMedia(): HasMany
    {
        return $this->licensedMedia()->published()->forAvailableReleases(null);
    }

    /**
     * @return HasMany<CatalogTitleReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(CatalogTitleReview::class);
    }

    /**
     * @return HasMany<CatalogTitleAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(CatalogTitleAlias::class);
    }

    /** @return HasOne<CatalogTitleSearchDocument, $this> */
    public function searchDocument(): HasOne
    {
        return $this->hasOne(CatalogTitleSearchDocument::class);
    }

    /** @return HasMany<CatalogTitleSlug, $this> */
    public function historicalSlugs(): HasMany
    {
        return $this->hasMany(CatalogTitleSlug::class);
    }

    /**
     * @return HasMany<CatalogTitleRating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(CatalogTitleRating::class);
    }

    /** @return HasMany<CatalogTitleUserState, $this> */
    public function userStates(): HasMany
    {
        return $this->hasMany(CatalogTitleUserState::class);
    }

    /** @return HasMany<EpisodeViewProgress, $this> */
    public function viewProgress(): HasMany
    {
        return $this->hasMany(EpisodeViewProgress::class);
    }

    /**
     * @return HasMany<SeasonvarImportEvent, $this>
     */
    public function importEvents(): HasMany
    {
        return $this->hasMany(SeasonvarImportEvent::class);
    }

    /**
     * @return HasMany<CatalogTitleRecommendation, $this>
     */
    public function recommendations(): HasMany
    {
        return $this->hasMany(CatalogTitleRecommendation::class);
    }

    /**
     * @return HasMany<CatalogTitleRecommendationSignal, $this>
     */
    public function recommendationSignals(): HasMany
    {
        return $this->hasMany(CatalogTitleRecommendationSignal::class);
    }

    /**
     * @return BelongsToMany<Taxonomy, $this>
     */
    public function taxonomies(): BelongsToMany
    {
        return $this->belongsToMany(Taxonomy::class);
    }

    /**
     * @return BelongsToMany<Genre, $this>
     */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'catalog_title_genre');
    }

    /**
     * @return BelongsToMany<Country, $this>
     */
    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class, 'catalog_title_country');
    }

    /**
     * @return BelongsToMany<Actor, $this>
     */
    public function actors(): BelongsToMany
    {
        return $this->belongsToMany(Actor::class, 'catalog_title_actor');
    }

    /**
     * @return BelongsToMany<Director, $this>
     */
    public function directors(): BelongsToMany
    {
        return $this->belongsToMany(Director::class, 'catalog_title_director');
    }

    /**
     * @return BelongsToMany<AgeRating, $this>
     */
    public function ageRatings(): BelongsToMany
    {
        return $this->belongsToMany(AgeRating::class, 'age_rating_catalog_title');
    }

    /**
     * @return BelongsToMany<Translation, $this>
     */
    public function translations(): BelongsToMany
    {
        return $this->belongsToMany(Translation::class, 'catalog_title_translation');
    }

    /**
     * @return BelongsToMany<CatalogStatus, $this>
     */
    public function statuses(): BelongsToMany
    {
        return $this->belongsToMany(CatalogStatus::class, 'catalog_status_catalog_title');
    }

    /**
     * @return BelongsToMany<Network, $this>
     */
    public function networks(): BelongsToMany
    {
        return $this->belongsToMany(Network::class, 'catalog_title_network');
    }

    /**
     * @return BelongsToMany<Studio, $this>
     */
    public function studios(): BelongsToMany
    {
        return $this->belongsToMany(Studio::class, 'catalog_title_studio');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'catalog_title_tag');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'is_published' => 'boolean',
            'publication_status' => PublicationStatus::class,
            'audience' => ContentAudience::class,
            'available_from' => 'datetime',
            'available_until' => 'datetime',
            'indexed_at' => 'datetime',
            'provider_field_values' => 'array',
            'relation_metadata_version' => 'integer',
        ];
    }
}
