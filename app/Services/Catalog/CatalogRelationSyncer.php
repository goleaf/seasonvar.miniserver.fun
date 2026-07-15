<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\Tag;
use App\Services\Tags\TagCacheInvalidator;
use App\Services\Tags\TagImportSynchronizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * @phpstan-type RelationInput array{
 *     type: string,
 *     name: string,
 *     source_id?: int|null,
 *     source_external_id?: string|int|null,
 *     source_url?: string|null
 * }
 */
final class CatalogRelationSyncer
{
    public function __construct(
        private readonly CatalogRelationNameSanitizer $relationNames,
        private readonly CatalogRelationSourceIdentityRegistry $sourceIdentities,
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly TagImportSynchronizer $tagImports,
        private readonly TagCacheInvalidator $tagCache,
    ) {}

    /**
     * @param  list<RelationInput>  $relations
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array<string, array{ids: list<int>, count: int, attached_ids: list<int>, attached_count: int}>
     */
    public function sync(
        CatalogTitle $title,
        array $relations,
        ?callable $progress = null,
        bool $completeTagSnapshot = false,
    ): array {
        return DB::transaction(fn (): array => $this->syncWithinTransaction(
            $title,
            $relations,
            $progress,
            $completeTagSnapshot,
        ));
    }

    /**
     * @param  list<RelationInput>  $relations
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array<string, array{ids: list<int>, count: int, attached_ids: list<int>, attached_count: int}>
     */
    private function syncWithinTransaction(
        CatalogTitle $title,
        array $relations,
        ?callable $progress,
        bool $completeTagSnapshot,
    ): array {
        $this->report($progress, 'taxonomy-sync-started', [
            'catalog_title_id' => $title->id,
            'total' => count($relations),
        ]);

        $relationsByType = $this->supportedRelations($relations);
        $result = [];
        $usesCanonicalTagSchema = Tag::usesCanonicalSchema();

        foreach ($relationsByType as $type => $relationItems) {
            $config = $this->taxonomies->relations()[$type];
            $tagSync = null;
            $ids = $type === 'tag' && $usesCanonicalTagSchema
                ? ($tagSync = $this->tagImports->syncTitle($title, $relationItems, $completeTagSnapshot))->tagIds
                : $this->syncType($title, $type, $relationItems, $progress);
            $attachedIds = $this->attach($title, $config['relation'], $ids);
            $result[$type] = [
                'ids' => $ids,
                'count' => count($ids),
                'attached_ids' => $attachedIds,
                'attached_count' => count($attachedIds),
            ];

            if ($tagSync !== null) {
                $this->report($progress, 'taxonomy-type-synced', [
                    'catalog_title_id' => $title->id,
                    'type' => 'tag',
                    'relation' => 'tags',
                    'records' => count($relationItems),
                    'synced' => count($ids),
                    'detached' => count($tagSync->detachedTagIds),
                ]);
                $this->afterTagChanges(
                    $title,
                    $attachedIds,
                    $tagSync->detachedTagIds,
                    $tagSync->publicMetadataChanged,
                );
            }
        }

        if ($completeTagSnapshot && ! array_key_exists('tag', $relationsByType)) {
            if ($usesCanonicalTagSchema) {
                $tagSync = $this->tagImports->syncTitle($title, [], true);
                $this->afterTagChanges($title, [], $tagSync->detachedTagIds, $tagSync->publicMetadataChanged);
            }

            $result['tag'] = [
                'ids' => [],
                'count' => 0,
                'attached_ids' => [],
                'attached_count' => 0,
            ];
        }

        $syncedIds = [];

        foreach ($result as $item) {
            array_push($syncedIds, ...$item['ids']);
        }

        $this->report($progress, 'taxonomy-sync-complete', [
            'catalog_title_id' => $title->id,
            'synced' => count($syncedIds),
            'taxonomy_ids' => $syncedIds,
        ]);

        return $result;
    }

    /**
     * @param  list<RelationInput>  $items
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return list<int>
     */
    private function syncType(CatalogTitle $title, string $type, array $items, ?callable $progress): array
    {
        $config = $this->taxonomies->relations()[$type];
        /** @var class-string<Model> $modelClass */
        $modelClass = $config['model'];
        $now = now();
        $preparedItems = (new Collection($items))
            ->map(function (array $item) use ($title, $type): ?array {
                $name = $this->relationNames->normalize($item['name']);

                if ($name === '') {
                    return null;
                }

                return [
                    'name' => $name,
                    'fallback_slug' => $this->relationNames->canonicalKey($type, $name),
                    'source_id' => $this->sourceId($item['source_id'] ?? $title->source_id),
                    'source_external_id' => $item['source_external_id'] ?? null,
                    'source_url' => $this->safeSourceUrl($item['source_url'] ?? null),
                ];
            })
            ->filter();

        if ($preparedItems->isEmpty()) {
            return [];
        }

        $sourceUrls = $preparedItems
            ->pluck('source_url')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $existingBySourceUrl = $sourceUrls === []
            ? collect()
            : $modelClass::query()
                ->whereIn('source_url', $sourceUrls)
                ->get()
                ->keyBy('source_url');
        $normalizedBySlug = [];

        foreach ($preparedItems as $item) {
            $existing = $item['source_url'] === null
                ? null
                : $existingBySourceUrl->get($item['source_url']);
            $existingSlug = $existing instanceof Model ? $existing->getAttribute('slug') : null;
            $candidateKey = is_string($existingSlug) && $existingSlug !== ''
                ? $existingSlug
                : $item['fallback_slug'];
            $slug = $this->sourceIdentities->resolve(
                $item['source_id'],
                $type,
                $item['source_external_id'],
                $item['source_url'],
                $candidateKey,
            );
            $normalized = [
                'name' => $item['name'],
                'slug' => $slug,
                'source_url' => $item['source_url'],
            ];
            $duplicate = $normalizedBySlug[$slug] ?? null;

            if (is_array($duplicate)) {
                $normalized['name'] = $this->relationNames->preferredName($type, $duplicate['name'], $normalized['name']);
                $normalized['source_url'] = $duplicate['source_url'] ?? $normalized['source_url'];
            }

            $normalizedBySlug[$slug] = $normalized;
        }

        $normalizedItems = array_values($normalizedBySlug);

        $existingBySlug = $modelClass::query()
            ->whereIn('slug', array_column($normalizedItems, 'slug'))
            ->get()
            ->keyBy('slug');
        $rowsBySlug = [];

        foreach ($normalizedItems as $item) {
            $existing = $existingBySlug->get($item['slug']);
            $existingName = $existing instanceof Model ? $existing->getAttribute('name') : null;
            $name = ! is_string($existingName) || $existingName === ''
                ? $item['name']
                : $this->relationNames->preferredName($type, $existingName, $item['name']);
            $existingSourceUrl = $existing instanceof Model ? $existing->getAttribute('source_url') : null;
            $rowsBySlug[$item['slug']] = [
                'name' => $name,
                'slug' => $item['slug'],
                'source_url' => is_string($existingSourceUrl) && $existingSourceUrl !== ''
                    ? $existingSourceUrl
                    : $item['source_url'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $rowsWithSourceUrl = array_values(array_filter(
            $rowsBySlug,
            fn (array $row): bool => $row['source_url'] !== null,
        ));
        $rowsWithoutSourceUrl = array_values(array_filter(
            $rowsBySlug,
            fn (array $row): bool => $row['source_url'] === null,
        ));

        if ($rowsWithSourceUrl !== []) {
            $modelClass::query()->upsert(
                $rowsWithSourceUrl,
                ['slug'],
                ['name', 'source_url', 'updated_at'],
            );
        }

        if ($rowsWithoutSourceUrl !== []) {
            $modelClass::query()->upsert(
                $rowsWithoutSourceUrl,
                ['slug'],
                ['name', 'updated_at'],
            );
        }

        $relationIds = $modelClass::query()
            ->whereIn('slug', array_keys($rowsBySlug))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $this->report($progress, 'taxonomy-type-synced', [
            'catalog_title_id' => $title->id,
            'type' => $type,
            'relation' => $config['relation'],
            'records' => count($rowsBySlug),
            'synced' => count($relationIds),
        ]);

        return $relationIds;
    }

    /**
     * @param  list<RelationInput>  $relations
     * @return array<string, list<RelationInput>>
     */
    private function supportedRelations(array $relations): array
    {
        $supported = [];

        foreach ($relations as $relation) {
            $type = $relation['type'];

            if (! $this->taxonomies->supports($type)
                || ! $this->relationNames->isValid($type, $relation['name'])) {
                continue;
            }

            $supported[$type][] = $relation;
        }

        return $supported;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function attach(CatalogTitle $title, string $relationName, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $relation = $title->{$relationName}();

        if (! $relation instanceof BelongsToMany) {
            throw new LogicException(sprintf(
                'Связь каталога "%s" должна быть belongsToMany.',
                $relationName,
            ));
        }

        $changes = $relation->syncWithoutDetaching($ids);
        $attached = [];

        foreach ($changes['attached'] as $id) {
            if (is_int($id)) {
                $attached[] = $id;
            } elseif (is_string($id) && ctype_digit($id)) {
                $attached[] = (int) $id;
            }
        }

        return array_values(array_unique($attached));
    }

    /**
     * @param  list<int>  $attachedIds
     * @param  list<int>  $detachedIds
     */
    private function afterTagChanges(
        CatalogTitle $title,
        array $attachedIds,
        array $detachedIds,
        bool $publicMetadataChanged,
    ): void {
        if ($attachedIds === [] && $detachedIds === [] && ! $publicMetadataChanged) {
            return;
        }

        CatalogTitleRecommendation::query()
            ->where('catalog_title_id', $title->id)
            ->orWhere('recommended_title_id', $title->id)
            ->delete();
        $title->forceFill(['indexed_at' => now()])->touch();
        $this->tagCache->publicChanged([$title->id]);
    }

    private function sourceId(mixed $sourceId): int
    {
        $sourceId = filter_var($sourceId, FILTER_VALIDATE_INT);

        return is_int($sourceId) && $sourceId > 0 ? $sourceId : 0;
    }

    private function safeSourceUrl(?string $sourceUrl): ?string
    {
        if ($sourceUrl === null || Str::length($sourceUrl) > 255 || filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($sourceUrl);

        if (! is_array($parts)
            || Str::lower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        return $sourceUrl;
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function report(?callable $progress, string $event, array $context = []): void
    {
        if ($progress !== null) {
            $progress($event, $context);
        }
    }
}
