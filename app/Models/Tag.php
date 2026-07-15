<?php

namespace App\Models;

use App\Enums\TagModerationStatus;
use App\Enums\TagSource;
use App\Enums\TagType;
use App\Enums\TagVisibility;
use App\Models\Concerns\HasCatalogTitles;
use App\Services\Tags\TagNormalizationService;
use App\Services\Tags\TagSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property string|null $source_url
 * @property string|null $code
 * @property TagType $type
 * @property TagVisibility $visibility
 * @property TagModerationStatus $moderation_status
 * @property TagSource $source
 * @property string|null $normalized_name
 * @property string|null $normalized_name_hash
 * @property int $content_version
 * @property int|null $merged_into_id
 * @property CarbonImmutable|null $archived_at
 * @property string|null $archived_from_visibility
 * @property string|null $archived_from_moderation_status
 * @property int|null $public_titles_count
 * @property int|null $catalog_titles_count
 * @property int|null $translations_count
 * @property int|null $aliases_count
 * @property int|null $provider_mappings_count
 */
#[Fillable([
    'public_id', 'name', 'slug', 'source_url', 'code', 'type', 'visibility', 'moderation_status', 'source',
    'normalized_name', 'normalized_name_hash', 'content_version', 'merged_into_id', 'archived_at',
    'archived_from_visibility', 'archived_from_moderation_status',
])]
class Tag extends Model
{
    use HasCatalogTitles;

    public static function usesCanonicalSchema(): bool
    {
        $configured = config('tags.canonical_schema');

        if (is_bool($configured)) {
            return $configured;
        }

        return app(TagSchema::class)->available();
    }

    /** @return HasMany<TagTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(TagTranslation::class);
    }

    /** @return HasMany<TagAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(TagAlias::class);
    }

    /** @return HasMany<TagSynonym, $this> */
    public function synonyms(): HasMany
    {
        return $this->hasMany(TagSynonym::class);
    }

    /** @return HasMany<TagSynonym, $this> */
    public function inverseSynonyms(): HasMany
    {
        return $this->hasMany(TagSynonym::class, 'related_tag_id');
    }

    /** @return HasMany<TagSlug, $this> */
    public function historicalSlugs(): HasMany
    {
        return $this->hasMany(TagSlug::class);
    }

    /** @return HasMany<TagProviderMapping, $this> */
    public function providerMappings(): HasMany
    {
        return $this->hasMany(TagProviderMapping::class);
    }

    /** @return BelongsTo<Tag, $this> */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /** @return HasMany<Tag, $this> */
    public function mergedTags(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

    /** @param Builder<Tag> $query */
    public function scopePubliclyEligible(Builder $query): void
    {
        if (! self::usesCanonicalSchema()) {
            return;
        }

        $query
            ->where('visibility', TagVisibility::Public->value)
            ->where('moderation_status', TagModerationStatus::Approved->value)
            ->where('type', '!=', TagType::HiddenInternal->value)
            ->whereNull('archived_at')
            ->whereNull('merged_into_id');
    }

    /** @param Builder<Tag> $query */
    public function scopeGloballyAssignable(Builder $query): void
    {
        if (! self::usesCanonicalSchema()) {
            return;
        }

        $query
            ->whereIn('type', [TagType::System->value, TagType::Editorial->value, TagType::Imported->value])
            ->whereNotIn('moderation_status', [
                TagModerationStatus::Rejected->value,
                TagModerationStatus::Hidden->value,
                TagModerationStatus::Merged->value,
                TagModerationStatus::Archived->value,
            ])
            ->whereNull('archived_at')
            ->whereNull('merged_into_id');
    }

    /** @param Builder<Tag> $query */
    public function scopeWithLocalizedLabel(Builder $query, ?string $locale = null, ?string $fallbackLocale = null): void
    {
        if (! self::usesCanonicalSchema()) {
            return;
        }

        $locale ??= app()->getLocale();
        $fallbackLocale ??= (string) config('app.fallback_locale', config('tags.default_locale', 'ru'));
        $table = $query->getModel()->getTable();
        $query->addSelect([
            'localized_label' => TagTranslation::query()
                ->select('label')
                ->whereColumn('tag_id', $table.'.id')
                ->where('locale', $locale)
                ->limit(1),
            'fallback_label' => TagTranslation::query()
                ->select('label')
                ->whereColumn('tag_id', $table.'.id')
                ->where('locale', $fallbackLocale)
                ->limit(1),
        ]);
    }

    public function isPubliclyEligible(): bool
    {
        if (! self::usesCanonicalSchema()) {
            return true;
        }

        return $this->visibility === TagVisibility::Public
            && $this->moderation_status === TagModerationStatus::Approved
            && $this->type !== TagType::HiddenInternal
            && $this->archived_at === null
            && $this->merged_into_id === null;
    }

    public function isGloballyAssignable(): bool
    {
        if (! self::usesCanonicalSchema()) {
            return true;
        }

        return in_array($this->type, [TagType::System, TagType::Editorial, TagType::Imported], true)
            && ! in_array($this->moderation_status, [
                TagModerationStatus::Rejected,
                TagModerationStatus::Hidden,
                TagModerationStatus::Merged,
                TagModerationStatus::Archived,
            ], true)
            && $this->archived_at === null
            && $this->merged_into_id === null;
    }

    public function canonicalName(): string
    {
        return (string) $this->getRawOriginal('name');
    }

    public function localizedDescription(?string $locale = null, bool $short = false): ?string
    {
        if (! $this->relationLoaded('translations')) {
            return null;
        }

        $locale ??= app()->getLocale();
        $fallback = (string) config('app.fallback_locale', config('tags.default_locale', 'ru'));
        $translation = $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', $fallback);
        $value = $short ? $translation?->short_description : $translation?->description;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    public function getNameAttribute(mixed $value): string
    {
        foreach (['localized_label', 'fallback_label'] as $attribute) {
            $label = $this->attributes[$attribute] ?? null;

            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        return is_string($value) ? $value : '';
    }

    protected static function booted(): void
    {
        static::creating(function (self $tag): void {
            if (! self::usesCanonicalSchema()) {
                return;
            }

            $normalizer = app(TagNormalizationService::class);
            $attributes = $tag->getAttributes();
            $tag->public_id ??= (string) Str::uuid();
            $tag->normalized_name ??= $normalizer->comparison($attributes['name'] ?? '');
            $tag->normalized_name_hash ??= hash('sha256', (string) $tag->normalized_name);
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => TagType::class,
            'visibility' => TagVisibility::class,
            'moderation_status' => TagModerationStatus::class,
            'source' => TagSource::class,
            'content_version' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }

    protected function catalogTitlePivotTable(): string
    {
        return 'catalog_title_tag';
    }

    protected function catalogTitleRelatedPivotKey(): string
    {
        return 'tag_id';
    }

    protected function catalogTitleFilterType(): string
    {
        return 'tag';
    }
}
