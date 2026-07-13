<?php

namespace App\Services\Seasonvar;

use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogRelationNameSanitizer;
use App\Services\Catalog\CatalogTaxonomyRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SeasonvarCatalogRelationSyncer
{
    public function __construct(
        private readonly CatalogRelationNameSanitizer $relationNames,
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly SeasonvarUrl $seasonvarUrl,
    ) {}

    /**
     * @param  list<array{type: string, name: string, source_url?: string|null}>  $taxonomies
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array<string, array{ids: list<int>, count: int, attached_ids: list<int>, attached_count: int}>
     */
    public function sync(CatalogTitle $title, array $taxonomies, ?callable $progress = null): array
    {
        $this->report($progress, 'taxonomy-sync-started', [
            'catalog_title_id' => $title->id,
            'total' => count($taxonomies),
        ]);

        $relationsByType = collect($taxonomies)
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
     * @param  Collection<int, array{type: string, name: string, source_url?: string|null}>  $items
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return list<int>
     */
    private function syncType(CatalogTitle $title, string $type, Collection $items, ?callable $progress): array
    {
        $config = $this->taxonomies->relations()[$type];
        /** @var class-string<Model> $modelClass */
        $modelClass = $config['model'];
        $now = now();
        $normalizedItems = $items
            ->map(function (array $item) use ($type): ?array {
                $name = $this->relationNames->normalize($item['name']);

                if ($name === '') {
                    return null;
                }

                return [
                    'name' => $name,
                    'slug' => $this->relationNames->canonicalKey($type, $name),
                    'source_url' => $this->safeSourceUrl($item['source_url'] ?? null),
                ];
            })
            ->filter()
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

        if ($normalizedItems->isEmpty()) {
            return [];
        }

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

    private function safeSourceUrl(?string $sourceUrl): ?string
    {
        if ($sourceUrl === null || Str::length($sourceUrl) > 255) {
            return null;
        }

        try {
            $sourceUrl = $this->seasonvarUrl->normalize($sourceUrl);
        } catch (InvalidArgumentException) {
            return null;
        }

        $parts = parse_url($sourceUrl);

        if (! is_array($parts) || Str::lower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }

        return in_array(Str::lower((string) ($parts['host'] ?? '')), ['seasonvar.ru', 'www.seasonvar.ru'], true)
            ? $sourceUrl
            : null;
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
