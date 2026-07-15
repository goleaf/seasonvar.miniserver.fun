<?php

declare(strict_types=1);

namespace App\Services\Tags;

use App\DTOs\TagImportSyncResult;
use App\Enums\SeasonvarPageType;
use App\Enums\TagAliasSource;
use App\Enums\TagModerationStatus;
use App\Enums\TagProviderMappingStatus;
use App\Enums\TagSource;
use App\Enums\TagType;
use App\Enums\TagVisibility;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleTagSource;
use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\TagProviderMapping;
use App\Services\Catalog\CatalogRelationNameSanitizer;
use App\Services\Catalog\CatalogRelationSourceIdentityRegistry;
use App\Services\Seasonvar\SeasonvarUrl;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final readonly class TagImportSynchronizer
{
    public function __construct(
        private TagNormalizationService $normalizer,
        private TagSlugService $slugs,
        private CatalogRelationNameSanitizer $relationNames,
        private CatalogRelationSourceIdentityRegistry $sourceIdentities,
        private TagAssignmentService $assignments,
        private SeasonvarUrl $seasonvarUrl,
    ) {}

    /**
     * @param  list<array{type: string, name: string, source_id?: int|null, source_external_id?: string|int|null, source_url?: string|null}>  $items
     */
    public function syncTitle(
        CatalogTitle $title,
        array $items,
        bool $completeProviderSnapshot = false,
    ): TagImportSyncResult {
        $observedProviderKeys = [];
        $publicMetadataChanged = false;
        $tagIds = (new Collection($items))
            ->map(function (array $item) use ($title, &$observedProviderKeys, &$publicMetadataChanged): ?int {
                $result = $this->resolveProviderTag(
                    sourceId: $this->sourceId($item['source_id'] ?? $title->source_id),
                    name: $item['name'],
                    sourceUrl: $item['source_url'] ?? null,
                    sourceExternalId: $item['source_external_id'] ?? null,
                );

                if ($result === null) {
                    return null;
                }

                $tag = $result['tag'];
                $observedProviderKeys[] = $result['provider_key'];
                $publicMetadataChanged = $publicMetadataChanged
                    || (($result['created'] || $result['changed']) && $tag->isPubliclyEligible());
                $this->recordObservation($title, $tag, $result['provider_key'], $this->sourceId($item['source_id'] ?? $title->source_id));

                return $this->assignments->hasEditorialSuppression($title, $tag) || ! $tag->isGloballyAssignable()
                    ? null
                    : (int) $tag->id;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        $detachedTagIds = $completeProviderSnapshot
            ? $this->reconcileStaleObservations($title, $observedProviderKeys)
            : [];

        return new TagImportSyncResult(
            tagIds: $tagIds,
            detachedTagIds: $detachedTagIds,
            publicMetadataChanged: $publicMetadataChanged,
        );
    }

    /**
     * @param  list<string>  $aliases
     * @return array{tag: Tag, provider_key: string, created: bool, changed: bool}|null
     */
    public function resolveProviderTag(
        int $sourceId,
        string $name,
        ?string $sourceUrl,
        string|int|null $sourceExternalId = null,
        array $aliases = [],
    ): ?array {
        if ($this->normalizer->containsUnsafeInput($name)) {
            return null;
        }

        $name = $this->normalizer->display($name);

        if (! $this->relationNames->isValid('tag', $name)) {
            return null;
        }

        $sourceUrl = $this->safeTagSourceUrl($sourceUrl);
        $normalized = $this->normalizer->comparison($name);
        $hash = hash('sha256', $normalized);
        $isSubtitle = $sourceUrl === null && in_array($normalized, ['субтитры', 'subtitles'], true);
        $providerKey = $isSubtitle
            ? hash('sha256', 'system:subtitle-available')
            : ($this->sourceIdentities->sourceKeyHash($sourceExternalId, $sourceUrl)
                ?? hash('sha256', "label\0{$normalized}"));
        $mapping = TagProviderMapping::query()
            ->where('provider', 'seasonvar')
            ->where('provider_key', $providerKey)
            ->first();

        if ($mapping?->status === TagProviderMappingStatus::Rejected) {
            $mapping->forceFill(['raw_label' => $name, 'last_seen_at' => now()])->save();

            return null;
        }

        $fallbackSlug = $isSubtitle
            ? 'subtitry'
            : $this->relationNames->canonicalKey('tag', $name);
        $canonicalSlug = $this->sourceIdentities->resolve(
            $sourceId,
            'tag',
            $sourceExternalId,
            $sourceUrl,
            $fallbackSlug,
        );
        $mappedTag = $mapping instanceof TagProviderMapping ? $mapping->tag : null;
        $matchedAliasTagIds = TagAlias::query()
            ->where('normalized_name_hash', $hash)
            ->whereIn('moderation_status', [
                TagModerationStatus::Pending->value,
                TagModerationStatus::Approved->value,
            ])
            ->select('tag_id')
            ->distinct()
            ->limit(2)
            ->pluck('tag_id');
        $aliasTag = $matchedAliasTagIds->count() === 1
            ? Tag::query()->find($matchedAliasTagIds->first())
            : null;
        $tag = $mappedTag
            ?? ($sourceUrl === null ? null : Tag::query()->where('source_url', $sourceUrl)->first())
            ?? Tag::query()->where('normalized_name_hash', $hash)->first()
            ?? $aliasTag
            ?? Tag::query()->where('slug', $canonicalSlug)->first();
        $created = false;
        $changed = false;

        if ($tag === null) {
            $publicId = (string) Str::uuid();
            try {
                $tag = Tag::query()->create([
                    'public_id' => $publicId,
                    'name' => $name,
                    'slug' => $this->slugs->generate($name, $publicId),
                    'source_url' => $sourceUrl,
                    'code' => $isSubtitle ? 'subtitle-available' : null,
                    'type' => $isSubtitle ? TagType::System : TagType::Imported,
                    'visibility' => TagVisibility::Public,
                    'moderation_status' => $isSubtitle ? TagModerationStatus::Approved : TagModerationStatus::Pending,
                    'source' => $isSubtitle ? TagSource::System : TagSource::Seasonvar,
                    'normalized_name' => $normalized,
                    'normalized_name_hash' => $hash,
                ]);
                $created = true;
                $changed = true;
            } catch (UniqueConstraintViolationException $exception) {
                $tag = Tag::query()
                    ->where('normalized_name_hash', $hash)
                    ->when(
                        $isSubtitle,
                        fn ($query) => $query->orWhere('code', 'subtitle-available'),
                    )
                    ->first();

                if (! $tag instanceof Tag) {
                    throw $exception;
                }
            }
        } else {
            $before = $tag->only(['name', 'source_url', 'normalized_name', 'normalized_name_hash']);
            $attributes = [];

            if ((! is_string($tag->source_url) || $tag->source_url === '') && $sourceUrl !== null) {
                $attributes['source_url'] = $sourceUrl;
            }

            if (! is_string($tag->normalized_name) || $tag->normalized_name === '') {
                $attributes['normalized_name'] = $normalized;
            }

            if (! is_string($tag->normalized_name_hash) || $tag->normalized_name_hash === '') {
                $attributes['normalized_name_hash'] = $hash;
            }

            if ($tag->type === TagType::Imported
                && $tag->source === TagSource::Seasonvar
                && $tag->moderation_status === TagModerationStatus::Pending) {
                $preferredName = $this->relationNames->preferredName('tag', $tag->canonicalName(), $name);
                $preferredNormalized = $this->normalizer->comparison($preferredName);
                $preferredHash = hash('sha256', $preferredNormalized);
                $identityConflict = Tag::query()
                    ->whereKeyNot($tag->id)
                    ->where('normalized_name_hash', $preferredHash)
                    ->exists()
                    || TagAlias::query()
                        ->where('tag_id', '!=', $tag->id)
                        ->where('normalized_name_hash', $preferredHash)
                        ->exists();

                if (! $identityConflict) {
                    $attributes['name'] = $preferredName;
                    $attributes['normalized_name'] = $preferredNormalized;
                    $attributes['normalized_name_hash'] = $preferredHash;
                    TagAlias::query()
                        ->whereBelongsTo($tag)
                        ->where('normalized_name_hash', $preferredHash)
                        ->delete();
                }
            }

            if ($attributes !== []) {
                $tag->forceFill($attributes)->save();
            }

            $changed = $before !== $tag->only(['name', 'source_url', 'normalized_name', 'normalized_name_hash']);
        }

        $mappingStatus = $tag->moderation_status === TagModerationStatus::Approved
            ? TagProviderMappingStatus::Approved
            : TagProviderMappingStatus::Pending;
        $storedMapping = TagProviderMapping::query()->firstOrCreate([
            'provider' => 'seasonvar',
            'provider_key' => $providerKey,
        ], [
            'tag_id' => $tag->id,
            'raw_label' => $name,
            'normalized_name' => $normalized,
            'normalized_name_hash' => $hash,
            'source_url' => $sourceUrl,
            'status' => $mapping instanceof TagProviderMapping ? $mapping->status : $mappingStatus,
            'confidence' => $sourceUrl === null ? 80 : 100,
            'last_seen_at' => now(),
        ]);

        if ($storedMapping->status === TagProviderMappingStatus::Rejected) {
            $storedMapping->forceFill(['raw_label' => $name, 'last_seen_at' => now()])->save();

            return null;
        }

        if ($storedMapping->tag_id !== null && (int) $storedMapping->tag_id !== (int) $tag->id) {
            $mappedTag = Tag::query()->find($storedMapping->tag_id);

            if (! $mappedTag instanceof Tag) {
                return null;
            }

            $tag = $mappedTag;
            $created = false;
            $changed = false;
        }

        $storedMapping->forceFill([
            'tag_id' => $tag->id,
            'raw_label' => $name,
            'normalized_name' => $normalized,
            'normalized_name_hash' => $hash,
            'source_url' => $sourceUrl,
            'confidence' => $sourceUrl === null ? 80 : 100,
            'last_seen_at' => now(),
        ])->save();

        foreach ([$name, ...$aliases] as $alias) {
            $changed = $this->storeProviderAlias($tag, $alias, $providerKey) || $changed;
        }

        return [
            'tag' => $tag,
            'provider_key' => $providerKey,
            'created' => $created,
            'changed' => $changed,
        ];
    }

    private function recordObservation(CatalogTitle $title, Tag $tag, string $providerKey, int $sourceId): void
    {
        $now = now();
        $observation = CatalogTitleTagSource::query()->firstOrNew([
            'catalog_title_id' => $title->id,
            'tag_id' => $tag->id,
            'source' => TagSource::Seasonvar->value,
            'source_key' => $providerKey,
        ]);
        $observation->fill([
            'provider' => 'seasonvar',
            'source_id' => $sourceId > 0 ? $sourceId : null,
            'is_current' => true,
            'first_seen_at' => $observation->exists ? $observation->first_seen_at : $now,
            'last_seen_at' => $now,
        ])->save();
    }

    /**
     * A provider snapshot is reconciled only after the caller has proved that the
     * complete title metadata block was parsed. Legacy pivots without provenance
     * remain untouched until they have first been observed by this canonical path.
     *
     * @param  list<string>  $observedProviderKeys
     * @return list<int>
     */
    private function reconcileStaleObservations(CatalogTitle $title, array $observedProviderKeys): array
    {
        $stale = CatalogTitleTagSource::query()
            ->whereBelongsTo($title)
            ->where('source', TagSource::Seasonvar->value)
            ->where('provider', 'seasonvar')
            ->where('is_current', true)
            ->when(
                $observedProviderKeys !== [],
                fn ($query) => $query->whereNotIn('source_key', array_values(array_unique($observedProviderKeys))),
            )
            ->get(['id', 'tag_id']);

        if ($stale->isEmpty()) {
            return [];
        }

        CatalogTitleTagSource::query()
            ->whereKey($stale->pluck('id'))
            ->update(['is_current' => false, 'updated_at' => now()]);

        $candidateTagIds = $stale
            ->pluck('tag_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $stillCurrent = CatalogTitleTagSource::query()
            ->whereBelongsTo($title)
            ->whereIn('tag_id', $candidateTagIds)
            ->where('is_current', true)
            ->pluck('tag_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();
        $detachable = $candidateTagIds->diff($stillCurrent)->values();

        if ($detachable->isNotEmpty()) {
            $title->tags()->detach($detachable->all());
        }

        return $detachable->all();
    }

    private function storeProviderAlias(Tag $tag, mixed $value, string $providerKey): bool
    {
        if ($this->normalizer->containsUnsafeInput($value)) {
            return false;
        }

        $name = $this->normalizer->display($value);

        if ($name === '' || $this->normalizer->hash($name) === $tag->normalized_name_hash) {
            return false;
        }

        $normalized = $this->normalizer->comparison($name);
        $hash = hash('sha256', $normalized);
        $conflict = Tag::query()->whereKeyNot($tag->id)->where('normalized_name_hash', $hash)->exists()
            || TagAlias::query()->where('normalized_name_hash', $hash)->where('tag_id', '!=', $tag->id)->exists();

        if ($conflict) {
            return false;
        }

        try {
            $alias = TagAlias::query()->firstOrCreate([
                'locale' => 'und',
                'normalized_name_hash' => $hash,
            ], [
                'tag_id' => $tag->id,
                'name' => $name,
                'normalized_name' => $normalized,
                'slug' => null,
                'source' => TagAliasSource::Provider,
                'source_key' => $providerKey,
                'moderation_status' => TagModerationStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            $alias = TagAlias::query()
                ->where('locale', 'und')
                ->where('normalized_name_hash', $hash)
                ->first();
        }

        if (! $alias instanceof TagAlias || (int) $alias->tag_id !== (int) $tag->id) {
            return false;
        }

        return $alias->wasRecentlyCreated;
    }

    private function safeTagSourceUrl(?string $sourceUrl): ?string
    {
        if ($sourceUrl === null || Str::length($sourceUrl) > 255) {
            return null;
        }

        try {
            $sourceUrl = $this->seasonvarUrl->normalize($sourceUrl);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->seasonvarUrl->isAllowed($sourceUrl)
            && $this->seasonvarUrl->pageType($sourceUrl) === SeasonvarPageType::Tag
                ? $sourceUrl
                : null;
    }

    private function sourceId(mixed $value): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($value) && $value > 0 ? $value : 0;
    }
}
