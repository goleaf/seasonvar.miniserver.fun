<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $parent_id
 * @property string $slug
 * @property int $position
 * @property bool $is_active
 * @property-read string $display_name
 */
#[Fillable([
    'public_id',
    'parent_id',
    'slug',
    'position',
    'is_active',
])]
final class CatalogCollectionCategory extends Model
{
    /** @return BelongsTo<CatalogCollectionCategory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<CatalogCollectionCategory, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /** @return HasMany<CatalogCollectionCategoryTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(CatalogCollectionCategoryTranslation::class);
    }

    /** @return HasMany<CatalogCollection, $this> */
    public function collections(): HasMany
    {
        return $this->hasMany(CatalogCollection::class);
    }

    /** @return Attribute<string, never> */
    protected function displayName(): Attribute
    {
        return Attribute::get(function (): string {
            if (! $this->relationLoaded('translations')) {
                return $this->slug;
            }

            $locale = app()->currentLocale();
            $fallback = (string) config('catalog-collections.default_locale', 'ru');

            return (string) (
                $this->translations->firstWhere('locale', $locale)?->name
                ?? $this->translations->firstWhere('locale', $fallback)?->name
                ?? $this->slug
            );
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $category): void {
            $category->public_id ??= (string) Str::uuid();
        });
    }
}
