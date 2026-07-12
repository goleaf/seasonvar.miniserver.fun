<?php

namespace App\Services\Seasonvar;

use App\Models\Actor;
use App\Models\AgeRating;
use App\Models\CatalogStatus;
use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Director;
use App\Models\Genre;
use App\Models\Network;
use App\Models\Studio;
use App\Models\Tag;
use App\Models\Translation;
use App\Services\Catalog\CatalogRelationNameSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SeasonvarCatalogRelationSyncer
{
    private const RELATION_TYPES = [
        'genre' => ['model' => Genre::class, 'relation' => 'genres'],
        'country' => ['model' => Country::class, 'relation' => 'countries'],
        'actor' => ['model' => Actor::class, 'relation' => 'actors'],
        'director' => ['model' => Director::class, 'relation' => 'directors'],
        'age_rating' => ['model' => AgeRating::class, 'relation' => 'ageRatings'],
        'translation' => ['model' => Translation::class, 'relation' => 'translations'],
        'status' => ['model' => CatalogStatus::class, 'relation' => 'statuses'],
        'network' => ['model' => Network::class, 'relation' => 'networks'],
        'studio' => ['model' => Studio::class, 'relation' => 'studios'],
        'tag' => ['model' => Tag::class, 'relation' => 'tags'],
    ];

    public function __construct(private readonly CatalogRelationNameSanitizer $relationNames) {}

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
            ->filter(fn (array $item): bool => isset(self::RELATION_TYPES[$item['type']]))
            ->filter(fn (array $item): bool => $this->relationNames->isValid($item['type'], $item['name']))
            ->groupBy('type');
        $result = [];

        foreach ($relationsByType as $type => $items) {
            $config = self::RELATION_TYPES[$type];
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
        $config = self::RELATION_TYPES[$type];
        /** @var class-string<Model> $modelClass */
        $modelClass = $config['model'];
        $now = now();
        $rowsBySlug = $items->reduce(function (Collection $rows, array $item) use ($type, $now): Collection {
            $name = $this->relationNames->normalize($item['name']);

            if ($name === '') {
                return $rows;
            }

            $slug = $this->relationSlug($type, $name);
            $sourceUrl = $this->safeSourceUrl($item['source_url'] ?? null);
            $existing = $rows->get($slug);

            if (is_array($existing) && $existing['source_url'] !== null && $sourceUrl === null) {
                $sourceUrl = $existing['source_url'];
            }

            $rows->put($slug, [
                'name' => $name,
                'slug' => $slug,
                'source_url' => $sourceUrl,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $rows;
        }, collect());

        if ($rowsBySlug->isEmpty()) {
            return [];
        }

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
