<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\DTOs\CatalogCollectionItemCriteria;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionReportStatus;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogCollectionSyncRun;
use App\Models\CatalogStatus;
use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Genre;
use App\Models\User;
use App\Services\Catalog\CatalogTaxonomyRegistry;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Catalog\CatalogUserCardStateLoader;
use App\Services\Catalog\Search\CatalogSearchNormalizer;
use App\Services\Comments\CommentRelationshipService;
use App\Services\UserPortal\UserPortalCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class CatalogCollectionQuery
{
    public function __construct(
        private readonly CatalogTitleQuery $titles,
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogUserCardStateLoader $cardStates,
        private readonly CatalogSearchNormalizer $search,
        private readonly CatalogCollectionSchema $schema,
        private readonly CommentRelationshipService $relationships,
        private readonly UserPortalCache $userPortalCache,
    ) {}

    /** @return LengthAwarePaginator<int, CatalogCollection> */
    public function publicDirectory(
        string $search = '',
        string $sort = 'featured',
        int $perPage = 18,
        string $pageName = 'collectionsPage',
    ): LengthAwarePaginator {
        $perPage = max(6, min(36, $perPage));

        if (! $this->schema->available()) {
            return $this->emptyPaginator($perPage, $pageName);
        }

        $search = $this->search->display(mb_substr($search, 0, 100));
        $query = $this->summaryQuery()
            ->publiclyListed()
            ->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhereHas('translations', fn (Builder $query): Builder => $query
                        ->whereIn('locale', $this->searchLocales())
                        ->where(fn (Builder $query): Builder => $query
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('description', 'like', '%'.$search.'%')))
                    ->orWhereHas('owner', fn (Builder $query): Builder => $query->where('name', 'like', '%'.$search.'%'));
            }));

        match ($sort) {
            'recent' => $query->orderByDesc('updated_at'),
            'title' => $query->orderBy('name'),
            default => $query->orderByDesc('is_featured')->orderByDesc('updated_at'),
        };

        return $query->orderByDesc('id')->paginate($perPage, pageName: $pageName);
    }

    /** @return Collection<int, CatalogCollection> */
    public function publicSearch(string $search, int $limit = 6): Collection
    {
        $search = $this->search->display(mb_substr($search, 0, 100));

        if ($search === '' || ! $this->schema->available()) {
            return collect();
        }

        return $this->summaryQuery()
            ->publiclyListed()
            ->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhereHas('translations', fn (Builder $query): Builder => $query
                        ->whereIn('locale', $this->searchLocales())
                        ->where(fn (Builder $query): Builder => $query
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('description', 'like', '%'.$search.'%')))
                    ->orWhereHas('owner', fn (Builder $query): Builder => $query->where('name', 'like', '%'.$search.'%'));
            })
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(max(1, min(12, $limit)))
            ->get();
    }

    /** @return LengthAwarePaginator<int, CatalogCollection> */
    public function ownedBy(User $owner, bool $withTrashed = false, int $perPage = 18): LengthAwarePaginator
    {
        $perPage = max(6, min(36, $perPage));
        $pageName = $withTrashed ? 'deletedCollectionsPage' : 'myCollectionsPage';

        if (! $this->schema->available()) {
            return $this->emptyPaginator($perPage, $pageName);
        }

        $query = $this->summaryQuery()
            ->where('owner_id', $owner->id)
            ->when($withTrashed, fn (Builder $query): Builder => $query->withTrashed()->whereNotNull('deleted_at'))
            ->when(! $withTrashed, fn (Builder $query): Builder => $query->whereNull('deleted_at'));

        $paginator = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage, pageName: $pageName);

        if ($withTrashed) {
            $cutoff = now()->subDays(max(1, (int) config('catalog-collections.restoration_days', 30)));
            $paginator->getCollection()->each(fn (CatalogCollection $collection) => $collection->setAttribute(
                'is_restorable',
                $collection->deleted_at !== null && $collection->deleted_at->gt($cutoff),
            ));
        }

        return $paginator;
    }

    /** @return LengthAwarePaginator<int, CatalogCollection> */
    public function publicByOwner(User $owner, int $perPage = 18): LengthAwarePaginator
    {
        $perPage = max(6, min(36, $perPage));

        if (! $this->schema->available()) {
            return $this->emptyPaginator($perPage, 'profileCollectionsPage');
        }

        return $this->summaryQuery()
            ->publiclyListed()
            ->where('owner_id', $owner->id)
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage, pageName: 'profileCollectionsPage');
    }

    /** @return array{total: int, public: int, unlisted: int, private: int} */
    public function ownerCounts(User $owner, bool $refresh = false): array
    {
        if (! $this->schema->available()) {
            return ['total' => 0, 'public' => 0, 'unlisted' => 0, 'private' => 0];
        }

        /** @var array{total: int, public: int, unlisted: int, private: int} $snapshot */
        $snapshot = $this->userPortalCache->remember(
            $owner,
            'profile-collection-counts',
            ['projection' => 'visibility-counts-v1'],
            function () use ($owner): array {
                $counts = CatalogCollection::query()
                    ->where('owner_id', $owner->id)
                    ->selectRaw('visibility, COUNT(*) as aggregate')
                    ->groupBy('visibility')
                    ->pluck('aggregate', 'visibility');

                return [
                    'total' => (int) $counts->sum(),
                    'public' => (int) ($counts[CatalogCollectionVisibility::Public->value] ?? 0),
                    'unlisted' => (int) ($counts[CatalogCollectionVisibility::Unlisted->value] ?? 0),
                    'private' => (int) ($counts[CatalogCollectionVisibility::Private->value] ?? 0),
                ];
            },
            $refresh,
        );

        return $snapshot;
    }

    /** @return Collection<int, CatalogCollection> */
    public function manageableForTitle(User $user, int $titleId): Collection
    {
        if (! $this->schema->available()) {
            return collect();
        }

        return CatalogCollection::query()
            ->select(['id', 'public_id', 'name', 'visibility', 'type', 'sort_mode', 'updated_at'])
            ->where('owner_id', $user->id)
            ->with(['translations' => fn ($query) => $query
                ->select(['id', 'catalog_collection_id', 'locale', 'name', 'description', 'seo_title', 'seo_description'])
                ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))])
            ->withExists(['items as contains_title' => fn (Builder $query): Builder => $query->where('catalog_title_id', $titleId)])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    /** @return Collection<int, CatalogCollection> */
    public function publicForTitle(int $titleId, int $limit = 6): Collection
    {
        if (! $this->schema->available()) {
            return collect();
        }

        $collectionIds = CatalogCollectionItem::query()
            ->where('catalog_title_id', $titleId)
            ->select('catalog_collection_id');

        return $this->summaryQuery()
            ->publiclyListed()
            ->whereIn('catalog_collections.id', $collectionIds)
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(max(1, min(12, $limit)))
            ->get();
    }

    /** @return Collection<int, CatalogCollection> */
    public function featured(int $limit = 6): Collection
    {
        if (! $this->schema->available()) {
            return collect();
        }

        return $this->summaryQuery()
            ->publiclyListed()
            ->where('is_featured', true)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(max(1, min(12, $limit)))
            ->get();
    }

    public function summary(CatalogCollection $collection): CatalogCollection
    {
        return $this->summaryQuery()->whereKey($collection->id)->firstOrFail();
    }

    /** @return Collection<int, CatalogCollection> */
    public function related(CatalogCollection $collection, ?User $viewer = null, int $limit = 6): Collection
    {
        $titleIds = CatalogCollectionItem::query()
            ->whereBelongsTo($collection, 'collection')
            ->whereIn('catalog_title_id', $this->visibleTitleIds())
            ->select('catalog_title_id');

        return $this->summaryQuery()
            ->publiclyListed()
            ->whereKeyNot($collection->id)
            ->when($viewer !== null, fn (Builder $query): Builder => $query
                ->whereNotIn('owner_id', $this->relationships->blockedUserIds($viewer)))
            ->whereHas('items', fn (Builder $query): Builder => $query->whereIn('catalog_title_id', $titleIds))
            ->orderByDesc('is_featured')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(max(1, min(12, $limit)))
            ->get();
    }

    /** @return LengthAwarePaginator<int, CatalogTitle> */
    public function items(
        CatalogCollection $collection,
        ?User $viewer,
        CatalogCollectionItemCriteria $criteria,
        string $pageName = 'collectionPage',
    ): LengthAwarePaginator {
        /** @var array<int|string, string|\Closure(\Illuminate\Database\Eloquent\Relations\Relation<*, *, *>): mixed> $cardLoads */
        $cardLoads = $this->taxonomies->cardSummaryLoads();
        $query = $this->titles->visibleTo($viewer)
            ->join('catalog_collection_items as collection_item', function ($join) use ($collection): void {
                $join->on('collection_item.catalog_title_id', '=', 'catalog_titles.id')
                    ->where('collection_item.catalog_collection_id', '=', $collection->id);
            })
            ->select('catalog_titles.*')
            ->addSelect([
                'collection_item.id as collection_item_id',
                'collection_item.position as collection_position',
                'collection_item.created_at as collection_added_at',
            ])
            ->withCasts([
                'collection_item_id' => 'integer',
                'collection_position' => 'integer',
                'collection_added_at' => 'immutable_datetime',
            ])
            ->with($cardLoads)
            ->withCount($this->titles->publicCardCounts($viewer));

        $search = $this->search->display(mb_substr($criteria->search, 0, 100));

        if ($search !== '') {
            $variants = $this->search->legacyVariants($search);
            $query->where(function (Builder $query) use ($variants): void {
                foreach ($variants as $variant) {
                    $query->orWhere('catalog_titles.title', 'like', '%'.$variant.'%')
                        ->orWhere('catalog_titles.original_title', 'like', '%'.$variant.'%')
                        ->orWhereHas('aliases', fn (Builder $query): Builder => $query->where('name', 'like', '%'.$variant.'%'));
                }
            });
        }

        foreach (['genres' => $criteria->genre, 'countries' => $criteria->country, 'statuses' => $criteria->status] as $relation => $slug) {
            if (is_string($slug) && preg_match('/^[a-z0-9][a-z0-9-]{0,119}$/D', $slug) === 1) {
                $query->whereHas($relation, fn (Builder $query): Builder => $query->where('slug', $slug));
            }
        }

        if ($criteria->year !== null && $criteria->year >= 1900 && $criteria->year <= now()->year + 5) {
            $query->where('catalog_titles.year', $criteria->year);
        }

        $query = match ($criteria->sort) {
            CatalogCollectionSort::Manual => $query->orderBy('collection_item.position')->orderBy('collection_item.id'),
            CatalogCollectionSort::RecentlyAdded => $query->orderByDesc('collection_item.created_at')->orderByDesc('collection_item.id'),
            CatalogCollectionSort::OldestAdded => $query->orderBy('collection_item.created_at')->orderBy('collection_item.id'),
            CatalogCollectionSort::Title => $query->orderBy('catalog_titles.title')->orderBy('catalog_titles.id'),
            CatalogCollectionSort::ReleaseYear => $query->orderByDesc('catalog_titles.year')->orderByDesc('catalog_titles.id'),
            CatalogCollectionSort::Rating => $query
                ->withMax('ratings as collection_rating', 'rating')
                ->orderByDesc('collection_rating')
                ->orderByDesc('catalog_titles.id'),
            CatalogCollectionSort::RecentlyUpdated => $query->orderByDesc('catalog_titles.indexed_at')->orderByDesc('catalog_titles.id'),
        };

        $paginator = $query->paginate(max(6, min(48, $criteria->perPage)), pageName: $pageName);
        $this->cardStates->load(collect($paginator->items()), $viewer);

        return $paginator;
    }

    /** @return Collection<int, CatalogCollectionItem> */
    public function unavailableItems(CatalogCollection $collection, User $viewer, int $limit = 20): Collection
    {
        if (! $collection->isOwnedBy($viewer)) {
            return collect();
        }

        $visibleIds = $this->titles->visibleTo($viewer)->select('catalog_titles.id');

        return CatalogCollectionItem::query()
            ->whereBelongsTo($collection, 'collection')
            ->whereNotIn('catalog_title_id', $visibleIds)
            ->with('catalogTitleWithTrashed:id,slug,title,original_title,poster_url,deleted_at')
            ->orderBy('position')
            ->orderBy('id')
            ->limit(max(1, min(100, $limit)))
            ->get();
    }

    /**
     * @return array{
     *     genres: EloquentCollection<int, Genre>,
     *     countries: EloquentCollection<int, Country>,
     *     statuses: EloquentCollection<int, CatalogStatus>,
     *     years: Collection<int, int>
     * }
     */
    public function filterOptions(CatalogCollection $collection, ?User $viewer): array
    {
        $visibleCollectionTitleIds = $this->titles->visibleTo($viewer)
            ->whereHas('collectionItems', fn (Builder $query): Builder => $query
                ->where('catalog_collection_id', $collection->id))
            ->select('catalog_titles.id');
        $taxonomyConstraint = fn (Builder $query): Builder => $query
            ->whereIn('catalog_titles.id', clone $visibleCollectionTitleIds);

        $genres = Genre::query()
            ->select(['id', 'name', 'slug'])
            ->whereHas('catalogTitles', $taxonomyConstraint)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $countries = Country::query()
            ->select(['id', 'name', 'slug'])
            ->whereHas('catalogTitles', $taxonomyConstraint)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $statuses = CatalogStatus::query()
            ->select(['id', 'name', 'slug'])
            ->whereHas('catalogTitles', $taxonomyConstraint)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $years = $this->titles->visibleTo($viewer)
            ->whereHas('collectionItems', fn (Builder $query): Builder => $query
                ->where('catalog_collection_id', $collection->id))
            ->whereNotNull('year')
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn (mixed $year): int => (int) $year)
            ->values();

        return compact('genres', 'countries', 'statuses', 'years');
    }

    /** @return LengthAwarePaginator<int, CatalogCollection> */
    public function moderationQueue(string $search = '', int $perPage = 20): LengthAwarePaginator
    {
        $perPage = max(10, min(50, $perPage));

        if (! $this->schema->available()) {
            return $this->emptyPaginator($perPage, 'collectionAdminPage');
        }

        $search = $this->search->display(mb_substr($search, 0, 100));

        return $this->summaryQuery()
            ->withTrashed()
            ->withCount(['reports as open_reports_count' => fn (Builder $query): Builder => $query->where('status', CatalogCollectionReportStatus::Open->value)])
            ->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('slug', 'like', $search.'%');
            }))
            ->where(function (Builder $query): void {
                $query->where('moderation_status', CatalogCollectionModerationStatus::Pending->value)
                    ->orWhereHas('reports', fn (Builder $query): Builder => $query->where('status', CatalogCollectionReportStatus::Open->value))
                    ->orWhere(fn (Builder $query): Builder => $query
                        ->where('type', CatalogCollectionType::Editorial->value)
                        ->where('visibility', CatalogCollectionVisibility::Public->value)
                        ->where('moderation_status', CatalogCollectionModerationStatus::Approved->value)
                        ->whereNull('catalog_collections.deleted_at'));
            })
            ->orderByRaw('CASE WHEN moderation_status = ? THEN 0 ELSE 1 END', [CatalogCollectionModerationStatus::Pending->value])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage, pageName: 'collectionAdminPage');
    }

    /**
     * @return array{status: string, counters: array{collections_processed: int, pages: int, items: int, matched: int, ambiguous: int, unmatched: int}, completed_at_label: string, completed_at_iso: string}|null
     */
    public function latestSourceSyncSummary(): ?array
    {
        if (! $this->schema->sourceSyncAvailable()) {
            return null;
        }

        $run = CatalogCollectionSyncRun::query()
            ->select(['id', 'status', 'counters', 'started_at', 'completed_at'])
            ->where('provider', 'hdrezka')
            ->latest('started_at')
            ->latest('id')
            ->limit(1)
            ->first();

        if ($run === null) {
            return null;
        }

        $rawCounters = is_array($run->counters) ? $run->counters : [];
        $counters = [];

        foreach (['collections_processed', 'pages', 'items', 'matched', 'ambiguous', 'unmatched'] as $key) {
            $counters[$key] = max(0, (int) ($rawCounters[$key] ?? 0));
        }

        $timestamp = $run->completed_at ?? $run->started_at;

        return [
            'status' => $run->status->value,
            'counters' => $counters,
            'completed_at_label' => $timestamp->format('d.m.Y H:i'),
            'completed_at_iso' => $timestamp->toAtomString(),
        ];
    }

    /** @return Builder<CatalogCollection> */
    public function publicSitemapQuery(): Builder
    {
        return CatalogCollection::query()
            ->publiclyListed()
            ->whereHas('items', fn (Builder $query): Builder => $query
                ->whereIn('catalog_title_id', $this->visibleTitleIds()))
            ->select(['id', 'public_id', 'slug', 'name', 'is_featured', 'updated_at', 'cover_path', 'cover_version'])
            ->orderBy('id');
    }

    /** @return Builder<CatalogCollection> */
    private function summaryQuery(): Builder
    {
        $fallbackPoster = $this->titles->visibleTo(null)
            ->join('catalog_collection_items as fallback_item', 'fallback_item.catalog_title_id', '=', 'catalog_titles.id')
            ->whereColumn('fallback_item.catalog_collection_id', 'catalog_collections.id')
            ->orderBy('fallback_item.position')
            ->orderBy('fallback_item.id')
            ->select('catalog_titles.poster_url')
            ->limit(1);

        $query = CatalogCollection::query()
            ->select('catalog_collections.*')
            ->selectSub($fallbackPoster, 'fallback_poster_url')
            ->with('owner:id,public_id,name')
            ->with(['translations' => fn ($query) => $query
                ->select(['id', 'catalog_collection_id', 'locale', 'name', 'description', 'seo_title', 'seo_description'])
                ->whereIn('locale', array_values(array_unique([
                    app()->currentLocale(),
                    (string) config('catalog-collections.default_locale', 'ru'),
                ])))])
            ->withCount([
                'items as total_items_count',
                'items as visible_items_count' => fn (Builder $query): Builder => $query
                    ->whereIn('catalog_title_id', $this->visibleTitleIds()),
            ]);

        if ($this->schema->sourceSyncAvailable()) {
            $query
                ->with('sourceRecord:id,catalog_collection_id,missing_since_at')
                ->withExists(['sourceRecord as has_import_source']);
        } else {
            $query->selectRaw('0 as has_import_source');
        }

        return $query;
    }

    /** @return Builder<CatalogTitle> */
    private function visibleTitleIds(?User $viewer = null): Builder
    {
        return $this->titles->visibleTo($viewer)->select('catalog_titles.id');
    }

    /** @return list<string> */
    private function searchLocales(): array
    {
        return array_values(array_unique([
            app()->currentLocale(),
            (string) config('catalog-collections.default_locale', 'ru'),
        ]));
    }

    /** @return LengthAwarePaginator<int, CatalogCollection> */
    private function emptyPaginator(int $perPage, string $pageName): LengthAwarePaginator
    {
        $page = max(1, LengthAwarePaginator::resolveCurrentPage($pageName));

        return new LengthAwarePaginator([], 0, $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
            'pageName' => $pageName,
        ]);
    }
}
