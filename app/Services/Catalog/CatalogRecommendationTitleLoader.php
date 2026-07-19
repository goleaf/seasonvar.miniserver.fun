<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationContext;
use App\Models\CatalogTitle;
use Illuminate\Support\Collection;

final class CatalogRecommendationTitleLoader
{
    public function __construct(
        private readonly CatalogRecommendationVisibilityService $visibility,
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogUserCardStateLoader $cardStates,
        private readonly CatalogTitleCardCountLoader $cardCounts,
    ) {}

    /**
     * @param  list<int>  $ids
     * @return Collection<int, CatalogTitle>
     */
    public function load(CatalogRecommendationContext $context, array $ids, bool $watchable = true): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $positions = array_flip($ids);
        $titles = $this->visibility
            ->eligible($context, $watchable)
            ->whereKey($ids)
            ->select(['catalog_titles.id', 'slug', 'title', 'original_title', 'type', 'year', 'description', 'poster_url', 'indexed_at'])
            ->with($this->taxonomies->cardSummaryLoads())
            ->get()
            ->sortBy(fn (CatalogTitle $title): int => (int) ($positions[(int) $title->id] ?? PHP_INT_MAX))
            ->values();
        $this->cardCounts->load($titles, $context->user);
        $this->cardStates->load($titles, $context->user);

        return $titles;
    }
}
