<?php

namespace App\Models;

use Database\Factories\CatalogTitleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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
    'is_published',
    'indexed_at',
])]
class CatalogTitle extends Model
{
    /** @use HasFactory<CatalogTitleFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'slug';
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
        return $this->hasMany(Season::class);
    }

    /**
     * @return HasManyThrough<Episode, Season, $this>
     */
    public function episodes(): HasManyThrough
    {
        return $this->hasManyThrough(Episode::class, Season::class);
    }

    /**
     * @return HasMany<LicensedMedia, $this>
     */
    public function licensedMedia(): HasMany
    {
        return $this->hasMany(LicensedMedia::class);
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

    /**
     * @return HasMany<CatalogTitleRating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(CatalogTitleRating::class);
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
            'is_published' => 'boolean',
            'indexed_at' => 'datetime',
        ];
    }
}
