<?php

declare(strict_types=1);

namespace App\Services\Catalog\Api\V1;

use App\Models\CatalogTitleRecommendation;
use App\Models\User;
use App\Services\Catalog\CatalogTaxonomyRegistry;
use App\Services\Catalog\CatalogTitleQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class CatalogRecommendationQuery
{
    public function __construct(
        private CatalogTitleQuery $titles,
        private CatalogTaxonomyRegistry $taxonomies,
    ) {}

    /** @return Collection<int, CatalogTitleRecommendation> */
    public function forTitle(string $titleSlug, ?User $user): Collection
    {
        $title = $this->titles->visibleTo($user)->where('slug', $titleSlug)->firstOrFail();
        $limit = max(1, min(24, (int) config('seasonvar.recommendations.max_per_title', 12)));

        return $title->recommendations()
            ->select([
                'id',
                'catalog_title_id',
                'recommended_title_id',
                'score',
                'rank',
                'reasons',
            ])
            ->whereHas('recommendedTitle', fn (Builder $query): Builder => $this->titles->constrainVisible($query, $user))
            ->with([
                'recommendedTitle' => function ($relation) use ($user): void {
                    $query = $relation->getQuery();

                    $this->titles->constrainVisible($query, $user)
                        ->select([
                            'id',
                            'slug',
                            'title',
                            'original_title',
                            'type',
                            'year',
                            'description',
                            'poster_url',
                            'indexed_at',
                        ])
                        ->with($this->taxonomies->cardSummaryLoads())
                        ->withCount($this->titles->publicCardCounts($user));
                },
            ])
            ->orderBy('rank')
            ->orderByDesc('score')
            ->limit($limit)
            ->get()
            ->filter(fn (CatalogTitleRecommendation $recommendation): bool => $recommendation->recommendedTitle !== null)
            ->values();
    }
}
