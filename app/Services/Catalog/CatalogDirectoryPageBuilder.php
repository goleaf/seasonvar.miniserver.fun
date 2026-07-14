<?php

namespace App\Services\Catalog;

use App\DTOs\CatalogDirectoryDefinition;
use App\Support\CatalogAlphabet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CatalogDirectoryPageBuilder
{
    public function __construct(
        private readonly CatalogDirectoryQuery $directories,
        private readonly CatalogSeoBuilder $seo,
    ) {}

    /** @return array<string, mixed> */
    public function data(
        CatalogDirectoryDefinition $directory,
        string $search,
        string $letter,
        string $sort,
        ?int $decade,
    ): array {
        $summary = $this->directories->summary($directory);
        $items = $this->directories->paginate($directory, $search, $letter, $sort, $decade, $summary['values']);
        $this->decorateItems($items, $directory);
        $letters = $this->directories->letters($directory);
        $decades = $directory->isYear() ? $this->directories->decades() : collect();

        return [
            'definition' => $directory,
            'items' => $items,
            'itemsByDecade' => $directory->isYear()
                ? $items->getCollection()->groupBy(fn (object $item): int => (int) floor(((int) $item->year) / 10) * 10)
                : collect(),
            'letterGroups' => CatalogAlphabet::availableGroups($letters),
            'decades' => $decades,
            'totalValues' => $summary['values'],
            'totalTitles' => $summary['titles'],
            'seo' => $this->seo->directory(
                directory: $directory,
                totalValues: $summary['values'],
                totalTitles: $summary['titles'],
                search: $search,
                letter: $letter,
                sort: $sort,
                decade: $decade,
                currentPage: $items->currentPage(),
                previousPageUrl: $items->previousPageUrl(),
                nextPageUrl: $items->nextPageUrl(),
                pageItems: $items->getCollection(),
                firstItemPosition: $items->firstItem() ?? 1,
            ),
        ];
    }

    /** @param LengthAwarePaginator<int, object> $items */
    private function decorateItems(LengthAwarePaginator $items, CatalogDirectoryDefinition $directory): void
    {
        $items->setCollection($items->getCollection()->map(function (object $item) use ($directory): object {
            if ($directory->isYear()) {
                $item->name = (string) $item->year;
                $item->detail_url = route('titles.year', ['year' => $item->year]);
                $item->item_key = 'year-'.$item->year;

                return $item;
            }

            $item->detail_url = route('titles.taxonomy', [
                'type' => $directory->filterType?->value,
                'taxonomy' => $item->slug,
            ]);
            $item->item_key = $directory->key.'-'.$item->id;

            return $item;
        }));
    }
}
