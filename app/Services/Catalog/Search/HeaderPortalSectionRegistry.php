<?php

declare(strict_types=1);

namespace App\Services\Catalog\Search;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

final readonly class HeaderPortalSectionRegistry
{
    public function __construct(private CatalogSearchNormalizer $normalizer) {}

    /**
     * @return Collection<int, array{id: string, type: string, label: string, url: string, meta: string, route_name: string, rank: int}>
     */
    public function search(string $query, int $limit = 3): Collection
    {
        $needle = $this->normalizer->key($query);

        if (mb_strlen($needle) < 2) {
            return collect();
        }

        return collect($this->definitions())
            ->filter(fn (array $section): bool => Route::has($section['route_name']))
            ->map(function (array $section) use ($needle): array {
                $sectionLabel = __("catalog.header_search.sections.{$section['translation']}.label");
                $keywords = __("catalog.header_search.sections.{$section['translation']}.keywords");
                $label = $this->normalizer->key($sectionLabel.' '.$keywords);

                return [
                    'id' => 'section-'.$section['route_name'],
                    'type' => 'section',
                    'label' => $sectionLabel,
                    'url' => route($section['route_name'], $section['parameters']),
                    'meta' => __('catalog.header_search.meta.section'),
                    'route_name' => $section['route_name'],
                    'rank' => $this->rank($label, $needle),
                ];
            })
            ->filter(fn (array $section): bool => $section['rank'] < 4)
            ->sortBy([
                ['rank', 'asc'],
                ['label', 'asc'],
            ])
            ->take(max(1, min(3, $limit)))
            ->values();
    }

    /**
     * @return list<array{route_name: string, parameters: array<string, string>, translation: string}>
     */
    private function definitions(): array
    {
        return [
            ['route_name' => 'home', 'parameters' => [], 'translation' => 'home'],
            ['route_name' => 'titles.index', 'parameters' => [], 'translation' => 'catalog'],
            ['route_name' => 'discover.index', 'parameters' => ['type' => 'popular'], 'translation' => 'discovery'],
            ['route_name' => 'collections.index', 'parameters' => [], 'translation' => 'collections'],
            ['route_name' => 'requests.index', 'parameters' => [], 'translation' => 'requests'],
            ['route_name' => 'stats', 'parameters' => [], 'translation' => 'stats'],
            ['route_name' => 'genres.index', 'parameters' => [], 'translation' => 'genres'],
            ['route_name' => 'countries.index', 'parameters' => [], 'translation' => 'countries'],
            ['route_name' => 'actors.index', 'parameters' => [], 'translation' => 'actors'],
            ['route_name' => 'directors.index', 'parameters' => [], 'translation' => 'directors'],
            ['route_name' => 'tags.index', 'parameters' => [], 'translation' => 'tags'],
            ['route_name' => 'years.index', 'parameters' => [], 'translation' => 'years'],
        ];
    }

    private function rank(string $haystack, string $needle): int
    {
        return match (true) {
            $haystack === $needle => 0,
            str_starts_with($haystack, $needle) => 1,
            str_contains(' '.$haystack, ' '.$needle) => 2,
            str_contains($haystack, $needle) => 3,
            default => 4,
        };
    }
}
