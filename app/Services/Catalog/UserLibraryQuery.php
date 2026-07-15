<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\UserLibraryFilters;
use App\Enums\CatalogPublicationType;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\User;
use App\Services\Api\V1\Sync\ApiSyncReadiness;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final readonly class UserLibraryQuery
{
    public function __construct(
        private CatalogTitleQuery $titles,
        private CatalogTaxonomyRegistry $taxonomies,
        private ApiSyncReadiness $syncReadiness,
    ) {}

    /** @return LengthAwarePaginator<int, CatalogTitleUserState> */
    public function watchlist(User $user, UserLibraryFilters $filters): LengthAwarePaginator
    {
        return $this->applyFilters($this->base($user), $filters)
            ->where('in_watchlist', true)
            ->tap(fn (Builder $query): Builder => $this->applyOrder($query, $filters))
            ->paginate($filters->perPage)
            ->withQueryString();
    }

    /** @return LengthAwarePaginator<int, CatalogTitleUserState> */
    public function ratings(User $user, UserLibraryFilters $filters): LengthAwarePaginator
    {
        return $this->applyFilters($this->base($user), $filters)
            ->whereNotNull('rating')
            ->tap(fn (Builder $query): Builder => $this->applyOrder($query, $filters))
            ->paginate($filters->perPage)
            ->withQueryString();
    }

    /** @return Builder<CatalogTitleUserState> */
    private function base(User $user): Builder
    {
        $query = CatalogTitleUserState::query()
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
            ]);

        if ($this->syncReadiness->stateVersionsAvailable()) {
            $query->addSelect(['watchlist_version', 'rating_version']);
        } else {
            $query->selectRaw('0 AS watchlist_version, 0 AS rating_version');
        }

        return $query->with(['catalogTitle' => fn (BelongsTo $query): BelongsTo => $query
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
            ->withCount($this->titles->publicCardCounts($user))]);
    }

    /**
     * @param  Builder<CatalogTitleUserState>  $query
     * @return Builder<CatalogTitleUserState>
     */
    private function applyFilters(Builder $query, UserLibraryFilters $filters): Builder
    {
        if ($filters->query !== '') {
            $search = '%'.$filters->query.'%';

            $query->whereHas('catalogTitle', fn (Builder $titleQuery): Builder => $titleQuery
                ->where(function (Builder $matchingTitle) use ($search): void {
                    $matchingTitle
                        ->where('title', 'like', $search)
                        ->orWhere('original_title', 'like', $search);
                }));
        }

        if ($filters->type !== null) {
            $types = CatalogPublicationType::from($filters->type)->databaseValues();
            $query->whereHas(
                'catalogTitle',
                fn (Builder $titleQuery): Builder => $titleQuery->whereIn('type', $types),
            );
        }

        if ($filters->year !== null) {
            $query->whereHas(
                'catalogTitle',
                fn (Builder $titleQuery): Builder => $titleQuery->where('year', $filters->year),
            );
        }

        return $query;
    }

    /**
     * @param  Builder<CatalogTitleUserState>  $query
     * @return Builder<CatalogTitleUserState>
     */
    private function applyOrder(Builder $query, UserLibraryFilters $filters): Builder
    {
        $direction = $filters->direction;

        match ($filters->sort) {
            'rating' => $query->orderBy('rating', $direction),
            'title' => $query->orderBy($this->titleOrderColumn('title'), $direction),
            'year' => $query->orderBy($this->titleOrderColumn('year'), $direction),
            default => $query->orderBy('updated_at', $direction),
        };

        return $query->orderBy('id', $direction);
    }

    /** @return Builder<CatalogTitle> */
    private function titleOrderColumn(string $column): Builder
    {
        return CatalogTitle::query()
            ->select($column)
            ->whereColumn('catalog_titles.id', 'catalog_title_user_states.catalog_title_id')
            ->limit(1);
    }
}
