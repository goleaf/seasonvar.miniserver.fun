<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogRelationSyncer
{
    public function __construct(
        private readonly CatalogRelationNameSanitizer $relationNames,
        private readonly CatalogRelationSourceIdentityRegistry $sourceIdentities,
        private readonly CatalogTaxonomyRegistry $taxonomies,
    ) {}

    /**
     * @param  list<array{type: string, name: string, source_id?: int|null, source_external_id?: string|int|null, source_url?: string|null}>  $relations
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array<string, array{ids: list<int>, count: int, attached_ids: list<int>, attached_count: int}>
     */
    public function sync(CatalogTitle $title, array $relations, ?callable $progress = null): array
    {
        return DB::transaction(fn (): array => $this->syncWithinTransaction($title, $relations, $progress));
    }

    /**
     * @param  list<array{type: string, name: string, source_id?: int|null, source_external_id?: string|int|null, source_url?: string|null}>  $relations
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array<string, array{ids: list<int>, count: int, attached_ids: list<int>, attached_count: int}>
     */
    private function syncWithinTransaction(CatalogTitle $title, array $relations, ?callable $progress): array
    {
        $this->report($progress, 'taxonomy-sync-started', [
            'catalog_title_id' => $title->id,
            'total' => count($relations),
        ]);

        $relationsByType = collect($relations)
            ->filter(fn (array $item): bool => $this->taxonomies->supports($item['type']))
            ->filter(fn (array $item): bool => $this->relationNames->isValid($item['type'], $item['name']))
            ->groupBy('type');
        $result = [];

        foreach ($relationsByType as $type => $items) {
            $config = $this->taxonomies->relations()[$type];
            $ids = $this->syncType($title, $type, $items, $progress);
            $changes = $ids === [] ? ['attached' => []] : $title->{$config['relation']}()->syncWithoutDetaching($ids);
            $attachedIds = collect($changes['attached'] ?? [])
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all();
            $result[$type] = [
                'ids' => $ids,
                'count' => count($ids),
                'attached_ids' => $attachedIds,
                'attached_count' => count($attachedIds),
            ];
        }

        $syncedIds = collect($result)
            ->flatMap(fn (array $item): array => $item['ids'])
            ->values();

        $this->report($progress, 'taxonomy-sync-complete', [
            'catalog_title_id' => $title->id,
            'synced' => $syncedIds->count(),
            'taxonomy_ids' => $syncedIds->all(),
        ]);

        return $result;
    }

    /**
     * @param  Collection<int, array{type: string, name: string, source_id?: int|null, source_external_id?: string|int|null, source_url?: string|null}>  $items
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return list<int>
     */
    private function syncType(CatalogTitle $title, string $type, Collection $items, ?callable $progress): array
    {
        $config = $this->taxonomies->relations()[$type];
        /** @var class-string<Model> $modelClass */
        $modelClass = $config['model'];
        $now = now();
        $preparedItems = $items
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
            ->values();
        $existingBySourceUrl = $sourceUrls->isEmpty()
            ? collect()
            : $modelClass::query()
                ->whereIn('source_url', $sourceUrls)
                ->get()
                ->keyBy('source_url');
        $normalizedItems = $preparedItems
            ->map(function (array $item) use ($existingBySourceUrl, $type): array {
                $existing = $item['source_url'] === null
                    ? null
                    : $existingBySourceUrl->get($item['source_url']);
                $candidateKey = $existing?->slug ?: $item['fallback_slug'];

                return [
                    'name' => $item['name'],
                    'slug' => $this->sourceIdentities->resolve(
                        $item['source_id'],
                        $type,
                        $item['source_external_id'],
                        $item['source_url'],
                        $candidateKey,
                    ),
                    'source_url' => $item['source_url'],
                ];
            })
            ->reduce(function (Collection $normalized, array $item) use ($type): Collection {
                $existing = $normalized->get($item['slug']);

                if (is_array($existing)) {
                    $item['name'] = $this->relationNames->preferredName($type, $existing['name'], $item['name']);
                    $item['source_url'] = $existing['source_url'] ?? $item['source_url'];
                }

                $normalized->put($item['slug'], $item);

                return $normalized;
            }, collect())
            ->values();

        $existingBySlug = $modelClass::query()
            ->whereIn('slug', $normalizedItems->pluck('slug'))
            ->get()
            ->keyBy('slug');

        $rowsBySlug = $normalizedItems->mapWithKeys(function (array $item) use ($type, $existingBySlug, $now): array {
            $existing = $existingBySlug->get($item['slug']);
            $name = $existing === null
                ? $item['name']
                : $this->relationNames->preferredName($type, $existing->name, $item['name']);

            return [$item['slug'] => [
                'name' => $name,
                'slug' => $item['slug'],
                'source_url' => $existing?->source_url ?: $item['source_url'],
                'created_at' => $now,
                'updated_at' => $now,
            ]];
        });

        $rowsWithSourceUrl = $rowsBySlug->filter(fn (array $row): bool => $row['source_url'] !== null);
        $rowsWithoutSourceUrl = $rowsBySlug->filter(fn (array $row): bool => $row['source_url'] === null);

        if ($rowsWithSourceUrl->isNotEmpty()) {
            $modelClass::query()->upsert(
                $rowsWithSourceUrl->values()->all(),
                ['slug'],
                ['name', 'source_url', 'updated_at'],
            );
        }

        if ($rowsWithoutSourceUrl->isNotEmpty()) {
            $modelClass::query()->upsert(
                $rowsWithoutSourceUrl->values()->all(),
                ['slug'],
                ['name', 'updated_at'],
            );
        }

        $relationIds = $modelClass::query()
            ->whereIn('slug', $rowsBySlug->keys())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $this->report($progress, 'taxonomy-type-synced', [
            'catalog_title_id' => $title->id,
            'type' => $type,
            'relation' => $config['relation'],
            'records' => $rowsBySlug->count(),
            'synced' => count($relationIds),
        ]);

        return $relationIds;
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
