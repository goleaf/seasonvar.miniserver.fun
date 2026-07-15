<?php

declare(strict_types=1);

namespace App\Models;

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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $owner_id
 * @property string $name
 * @property string|null $description
 * @property string $slug
 * @property CatalogCollectionType $type
 * @property CatalogCollectionVisibility $visibility
 * @property CatalogCollectionModerationStatus $moderation_status
 * @property CatalogCollectionSort $sort_mode
 * @property string|null $content_locale
 * @property bool $is_featured
 * @property string|null $cover_disk
 * @property string|null $cover_path
 * @property int $cover_version
 * @property int $content_version
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read string $display_name
 * @property-read string|null $display_description
 * @property-read string|null $display_seo_title
 * @property-read string|null $display_seo_description
 * @property-read string|null $fallback_poster_url
 * @property-read bool $contains_title
 * @property-read bool $is_restorable
 * @property-read int|null $total_items_count
 * @property-read int|null $visible_items_count
 * @property-read int|null $open_reports_count
 */
#[Fillable([
    'public_id',
    'owner_id',
    'name',
    'description',
    'slug',
    'type',
    'visibility',
    'moderation_status',
    'sort_mode',
    'content_locale',
    'is_featured',
    'cover_disk',
    'cover_path',
    'cover_mime_type',
    'cover_size',
    'cover_version',
    'content_version',
    'published_at',
])]
#[UsePolicy(CatalogCollectionPolicy::class)]
final class CatalogCollection extends Model
{
    use SoftDeletes;

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<CatalogCollectionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CatalogCollectionItem::class)->orderBy('position')->orderBy('id');
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
    public function scopePubliclyListed(Builder $query): void
    {
        $query
            ->where('visibility', CatalogCollectionVisibility::Public->value)
            ->where('moderation_status', CatalogCollectionModerationStatus::Approved->value);
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->owner_id !== null && $this->owner_id === $user->getKey();
    }

    public function isPubliclyViewable(): bool
    {
        return $this->visibility->isDirectlyViewable()
            && $this->moderation_status->isPubliclyViewable()
            && $this->deleted_at === null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CatalogCollectionType::class,
            'visibility' => CatalogCollectionVisibility::class,
            'moderation_status' => CatalogCollectionModerationStatus::class,
            'sort_mode' => CatalogCollectionSort::class,
            'is_featured' => 'boolean',
            'cover_size' => 'integer',
            'cover_version' => 'integer',
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
