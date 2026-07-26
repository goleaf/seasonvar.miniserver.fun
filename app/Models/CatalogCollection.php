<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\CatalogSmartCollectionRules;
use App\Enums\CatalogCollectionMode;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Enums\CommentTargetType;
use App\Policies\CatalogCollectionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $owner_id
 * @property int|null $catalog_collection_category_id
 * @property string $name
 * @property string|null $description
 * @property string $slug
 * @property CatalogCollectionType $type
 * @property CatalogCollectionMode $mode
 * @property CatalogCollectionVisibility $visibility
 * @property CatalogCollectionModerationStatus $moderation_status
 * @property CatalogCollectionSort $sort_mode
 * @property array<string, mixed>|null $smart_rules
 * @property int $smart_rules_version
 * @property string|null $content_locale
 * @property bool $is_featured
 * @property int $content_version
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read string $display_name
 * @property-read string|null $display_description
 * @property-read string|null $display_seo_title
 * @property-read string|null $display_seo_description
 * @property-read bool $contains_title
 * @property-read bool $has_import_source
 * @property-read bool $is_restorable
 * @property-read int|null $total_items_count
 * @property-read int|null $visible_items_count
 * @property-read int|null $open_reports_count
 */
#[Fillable([
    'public_id',
    'owner_id',
    'catalog_collection_category_id',
    'name',
    'description',
    'slug',
    'type',
    'mode',
    'visibility',
    'moderation_status',
    'sort_mode',
    'smart_rules',
    'smart_rules_version',
    'content_locale',
    'is_featured',
    'content_version',
    'published_at',
])]
#[UsePolicy(CatalogCollectionPolicy::class)]
final class CatalogCollection extends Model
{
    use SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'mode' => CatalogCollectionMode::Manual->value,
        'smart_rules_version' => 1,
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<CatalogCollectionCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CatalogCollectionCategory::class, 'catalog_collection_category_id');
    }

    /** @return HasMany<CatalogCollectionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CatalogCollectionItem::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasOne<CatalogCollectionSource, $this> */
    public function sourceRecord(): HasOne
    {
        return $this->hasOne(CatalogCollectionSource::class);
    }

    /** @return HasMany<CatalogCollectionSlug, $this> */
    public function historicalSlugs(): HasMany
    {
        return $this->hasMany(CatalogCollectionSlug::class);
    }

    /** @return HasMany<CatalogCollectionReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(CatalogCollectionReport::class);
    }

    /** @return HasMany<ReleaseCalendarFeed, $this> */
    public function releaseCalendarFeeds(): HasMany
    {
        return $this->hasMany(ReleaseCalendarFeed::class);
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'target_id')
            ->where('target_type', CommentTargetType::Collection->value);
    }

    /** @return HasMany<CatalogCollectionTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(CatalogCollectionTranslation::class);
    }

    /** @return Attribute<string, never> */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => (string) ($this->localizedTranslation()?->name ?: $this->name));
    }

    /** @return Attribute<covariant string|null, never> */
    protected function displayDescription(): Attribute
    {
        return Attribute::get(function (mixed $value, array $attributes): ?string {
            $translation = $this->localizedTranslation();

            return $translation instanceof CatalogCollectionTranslation
                ? $translation->description
                : $this->description;
        });
    }

    /** @return Attribute<covariant string|null, never> */
    protected function displaySeoTitle(): Attribute
    {
        return Attribute::get(function (mixed $value, array $attributes): ?string {
            $translation = $this->localizedTranslation();

            return $translation instanceof CatalogCollectionTranslation ? $translation->seo_title : null;
        });
    }

    /** @return Attribute<covariant string|null, never> */
    protected function displaySeoDescription(): Attribute
    {
        return Attribute::get(function (mixed $value, array $attributes): ?string {
            $translation = $this->localizedTranslation();

            return $translation instanceof CatalogCollectionTranslation ? $translation->seo_description : null;
        });
    }

    /** @param Builder<CatalogCollection> $query */
    public function scopeEligibleForPublicListing(Builder $query): void
    {
        $maximumItems = max(
            1,
            (int) config('catalog-collections.maximum_public_items_per_collection', 500),
        );

        $query
            ->where('mode', CatalogCollectionMode::Manual->value)
            ->whereNotNull('catalog_collection_category_id')
            ->whereHas('category', fn (Builder $category): Builder => $category
                ->where('is_active', true)
                ->where(fn (Builder $category): Builder => $category
                    ->whereNull('parent_id')
                    ->orWhereHas('parent', fn (Builder $parent): Builder => $parent
                        ->where('is_active', true))))
            ->has('items')
            ->has('items', '<=', $maximumItems)
            ->whereDoesntHave('sourceRecord', fn (Builder $source): Builder => $source
                ->whereNotNull('missing_since_at'));
    }

    /** @param Builder<CatalogCollection> $query */
    public function scopePubliclyListed(Builder $query): void
    {
        $query
            ->where('visibility', CatalogCollectionVisibility::Public->value)
            ->where('moderation_status', CatalogCollectionModerationStatus::Approved->value)
            ->whereNotNull('published_at')
            ->eligibleForPublicListing();
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->owner_id !== null && $this->owner_id === $user->getKey();
    }

    public function isSmart(): bool
    {
        return $this->mode === CatalogCollectionMode::Smart;
    }

    public function smartRules(): ?CatalogSmartCollectionRules
    {
        if (! $this->isSmart() || ! is_array($this->smart_rules)) {
            return null;
        }

        return CatalogSmartCollectionRules::fromStored(
            $this->smart_rules,
            $this->smart_rules_version,
        );
    }

    public function isPubliclyViewable(): bool
    {
        return ! $this->isSmart()
            && $this->visibility->isDirectlyViewable()
            && $this->moderation_status->isPubliclyViewable()
            && $this->catalog_collection_category_id !== null
            && ($this->visibility !== CatalogCollectionVisibility::Public || $this->published_at !== null)
            && (! $this->relationLoaded('sourceRecord') || $this->sourceRecord?->missing_since_at === null)
            && $this->deleted_at === null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CatalogCollectionType::class,
            'mode' => CatalogCollectionMode::class,
            'visibility' => CatalogCollectionVisibility::class,
            'moderation_status' => CatalogCollectionModerationStatus::class,
            'sort_mode' => CatalogCollectionSort::class,
            'smart_rules' => 'array',
            'smart_rules_version' => 'integer',
            'is_featured' => 'boolean',
            'content_version' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }

    private function localizedTranslation(): ?CatalogCollectionTranslation
    {
        if ($this->type !== CatalogCollectionType::Editorial || ! $this->relationLoaded('translations')) {
            return null;
        }

        $locale = app()->currentLocale();
        $fallback = (string) config('catalog-collections.default_locale', 'ru');

        return $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', $fallback);
    }
}
