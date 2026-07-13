<?php

namespace App\Services\Seasonvar;

use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogRelationNameSanitizer;
use App\Services\Catalog\CatalogTaxonomyRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SeasonvarCatalogRelationSyncer
{
    public function __construct(
        private readonly CatalogRelationNameSanitizer $relationNames,
        private readonly CatalogTaxonomyRegistry $taxonomies,
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
        $normalizedItems = $items->map(function (array $item) use ($type): ?array {
            $name = $this->relationNames->normalize($item['name']);

            if ($name === '') {
                return null;
            }

            $sourceUrl = $this->safeSourceUrl($item['source_url'] ?? null);

            return [
                'name' => $name,
                'base_slug' => $this->relationSlug($type, $name),
                'source_url' => $sourceUrl,
                'identity_url' => $this->stableProviderIdentityUrl($type, $sourceUrl),
            ];
        })->filter()
            ->unique(fn (array $item): string => $item['identity_url'] !== null
                ? 'provider|'.$item['identity_url']
                : 'fallback|'.$item['base_slug'])
            ->values();

        if ($normalizedItems->isEmpty()) {
            return [];
        }

        $identityUrls = $normalizedItems->pluck('identity_url')->filter()->unique()->values();
        $existingByIdentityUrl = $identityUrls->isEmpty()
            ? collect()
            : $modelClass::query()
                ->whereIn('source_url', $identityUrls)
                ->orderBy('id')
                ->get()
                ->groupBy('source_url')
                ->map->first();
        $existingBySlug = $modelClass::query()
            ->whereIn('slug', $normalizedItems->pluck('base_slug')->unique())
            ->get()
            ->keyBy('slug');
        $reservedSlugs = [];

        $rowsBySlug = $normalizedItems->reduce(function (Collection $rows, array $item) use ($type, $existingByIdentityUrl, $existingBySlug, &$reservedSlugs, $now): Collection {
            $identityUrl = $item['identity_url'];
            $slug = $identityUrl !== null
                ? $existingByIdentityUrl->get($identityUrl)?->slug
                : null;

            if ($slug === null) {
                $slug = $item['base_slug'];
                $baseRecord = $existingBySlug->get($slug);
                $baseIdentityUrl = $baseRecord !== null
                    ? $this->stableProviderIdentityUrl($type, $baseRecord->source_url)
                    : null;
                $reservedIdentityUrl = $reservedSlugs[$slug] ?? null;

                if ($identityUrl !== null
                    && (($baseIdentityUrl !== null && $baseIdentityUrl !== $identityUrl)
                        || ($reservedIdentityUrl !== null && $reservedIdentityUrl !== $identityUrl))) {
                    $slug .= '-'.substr(hash('sha256', $identityUrl), 0, 12);
                }
            }

            $existing = $rows->get($slug);
            $sourceUrl = $item['source_url'];

            if (is_array($existing) && $existing['source_url'] !== null && $sourceUrl === null) {
                $sourceUrl = $existing['source_url'];
            }

            if ($identityUrl === null && ($existingBySlug->get($slug)?->source_url ?? null) !== null) {
                $sourceUrl = $existingBySlug->get($slug)->source_url;
            }

            $reservedSlugs[$slug] = $identityUrl;

            $rows->put($slug, [
                'name' => $item['name'],
                'slug' => $slug,
                'source_url' => $sourceUrl,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $rows;
        }, collect());

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

    private function relationSlug(string $type, string $name): string
    {
        $name = $this->relationNames->normalize($name);

        return Str::slug($name) ?: Str::substr(hash('sha256', $type.'|'.$name), 0, 16);
    }

    private function safeSourceUrl(?string $sourceUrl): ?string
    {
        if ($sourceUrl === null || Str::length($sourceUrl) > 255) {
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

    private function stableProviderIdentityUrl(string $type, ?string $sourceUrl): ?string
    {
        if (! in_array($type, ['actor', 'director'], true) || $sourceUrl === null) {
            return null;
        }

        $path = (string) parse_url($sourceUrl, PHP_URL_PATH);

        if (preg_match('~/(?:actor|akter|director|rezhisser)[/-](?<identity>[^/]+)$~iu', $path, $matches) !== 1) {
            return null;
        }

        $identity = trim($matches['identity'], '-_');

        return Str::length($identity) >= 6 && ! str_contains($identity, '&')
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
