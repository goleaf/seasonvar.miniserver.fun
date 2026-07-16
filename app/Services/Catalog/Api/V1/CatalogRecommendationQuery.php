<?php

declare(strict_types=1);

namespace App\Services\Catalog\Api\V1;

use App\DTOs\CatalogRecommendationItem;
use App\Models\User;
use App\Services\Catalog\CatalogRecommendationService;
use App\Services\Catalog\CatalogTitleQuery;
use Illuminate\Support\Collection;

final readonly class CatalogRecommendationQuery
{
    public function __construct(
        private CatalogTitleQuery $titles,
        private CatalogRecommendationService $recommendations,
    ) {}

    /** @return Collection<int, CatalogRecommendationItem> */
    public function forTitle(string $titleSlug, ?User $user): Collection
    {
        $title = $this->titles->visibleTo(null)->where('slug', $titleSlug)->firstOrFail();
        $limit = max(1, min(24, (int) config('seasonvar.recommendations.max_per_title', 12)));

        return $this->recommendations->forTitle($title, null, $limit)['similar'];
    }
}
