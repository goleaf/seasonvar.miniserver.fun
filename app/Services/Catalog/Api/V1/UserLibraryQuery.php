<?php

declare(strict_types=1);

namespace App\Services\Catalog\Api\V1;

use App\Models\CatalogTitleUserState;
use App\Models\User;
use App\Services\Catalog\CatalogTaxonomyRegistry;
use App\Services\Catalog\CatalogTitleQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final readonly class UserLibraryQuery
{
    public function __construct(
        private CatalogTitleQuery $titles,
        private CatalogTaxonomyRegistry $taxonomies,
    ) {}

    /** @return LengthAwarePaginator<int, CatalogTitleUserState> */
    public function watchlist(User $user, int $perPage): LengthAwarePaginator
    {
        return $this->base($user)
            ->where('in_watchlist', true)
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @return LengthAwarePaginator<int, CatalogTitleUserState> */
    public function ratings(User $user, int $perPage): LengthAwarePaginator
    {
        return $this->base($user)
            ->whereNotNull('rating')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @return Builder<CatalogTitleUserState> */
    private function base(User $user): Builder
    {
        return CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->whereIn(
                'catalog_title_id',
                $this->titles->visibleTo($user)->select('id'),
            )
            ->select([
                'id',
                'user_id',
                'catalog_title_id',
                'in_watchlist',
                'rating',
                'updated_at',
            ])
            ->with(['catalogTitle' => fn (BelongsTo $query): BelongsTo => $query
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
                ->withCount($this->titles->publicCardCounts($user))])
            ->latest('updated_at')
            ->latest('id');
    }
}
