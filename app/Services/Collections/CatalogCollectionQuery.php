<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\DTOs\CatalogCollectionItemCriteria;
use App\Enums\CatalogCollectionMode;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionQualityIssueStatus;
use App\Enums\CatalogCollectionReportStatus;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionSourceScope;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogCollectionSource;
use App\Models\CatalogCollectionSourceItem;
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
use App\Services\Collections\Import\HdRezkaCollectionTypeCompatibility;
use App\Services\Comments\CommentRelationshipService;
use App\Services\UserPortal\UserPortalCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class CatalogCollectionQuery
{
    /** @var array<string, list<string>> */
    private const QUALITY_ISSUE_FILTER_CODES = [
        'duplicate' => ['exact_duplicate'],
        'similar' => ['similar_text'],
        'template' => ['template_content'],
        'theme' => ['weak_theme'],
        'structure' => ['missing_category', 'empty_collection', 'too_many_items'],
        'reported' => ['user_reports'],
    ];

    private const SOURCE_MATCH_METHOD_METRIC_KEYS = [
        'matched:primary' => 'matched_primary',
        'matched:original' => 'matched_original',
        'matched:alias' => 'matched_alias',
        'matched:detail_original' => 'matched_detail_original',
        'ambiguous:candidate_limit' => 'ambiguous_candidate_limit',
        'ambiguous:insufficient_lead' => 'ambiguous_insufficient_lead',
        'unmatched:no_exact_candidate' => 'unmatched_no_exact_candidate',
        'unmatched:no_eligible_candidate' => 'unmatched_no_eligible_candidate',
        'unmatched:low_confidence' => 'unmatched_low_confidence',
    ];

    public function __construct(
        private readonly CatalogTitleQuery $titles,
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogUserCardStateLoader $cardStates,
        private readonly CatalogSearchNormalizer $search,
        private readonly CatalogCollectionSchema $schema,
        private readonly CatalogCollectionSummaryLoader $summaryLoader,
        private readonly CatalogCollectionPublicationReadiness $readiness,
        private readonly HdRezkaCollectionTypeCompatibility $sourceTypes,
        private readonly CommentRelationshipService $relationships,
        private readonly UserPortalCache $userPortalCache,
        private readonly CatalogSmartCollectionQuery $smartCollections,
    ) {}

    /** @return LengthAwarePaginator<int, CatalogCollection> */
    public function publicDirectory(
        string $search = '',
        string $sort = 'featured',
        int $perPage = 18,
        string $pageName = 'collectionsPage',
        ?string $category = null,
        ?string $subcategory = null,
    ): LengthAwarePaginator {
        $perPage = max(6, min(36, $perPage));

        if (! $this->schema->available()) {
            return $this->emptyPaginator($perPage, $pageName);
        }

        $search = $this->search->display(mb_substr($search, 0, 100));
        $query = CatalogCollection::query()
            ->select('catalog_collections.id')
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
        $this->applyCategoryFilter($query, $category, $subcategory);

        match ($sort) {
            'recent' => $query->orderByDesc('updated_at'),
            'title' => $query->orderBy('name'),
            default => $query->orderByDesc('is_featured')->orderByDesc('updated_at'),
        };

        $paginator = $query->orderByDesc('id')->paginate($perPage, pageName: $pageName);
        $ids = $paginator->getCollection()
            ->map(fn (CatalogCollection $collection): int => (int) $collection->id)
            ->all();

        if ($ids === []) {
            return $paginator;
        }

        $summaries = $this->summaryLoader
            ->hydratePage($this->summaryQuery(withCounts: false), $ids)
            ->keyBy(fn (CatalogCollection $collection): int => (int) $collection->id);
        $ordered = collect($ids)
            ->map(fn (int $id): ?CatalogCollection => $summaries->get($id))
            ->filter()
            ->values();
        $paginator->setCollection(new EloquentCollection($ordered->all()));

        return $paginator;
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
            $paginator->getCollection()->each(function (CatalogCollection $collection) use ($cutoff): void {
                $collection->setAttribute(
                    'is_restorable',
                    $collection->deleted_at !== null && $collection->deleted_at->gt($cutoff),
                );
            });
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
            ->select(['id', 'public_id', 'name', 'visibility', 'type', 'mode', 'sort_mode', 'updated_at'])
            ->where('owner_id', $user->id)
            ->where('mode', CatalogCollectionMode::Manual->value)
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
            ->whereIn('catalog_collections.id', $this->readiness->eligibleFeaturedCollectionIds())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(max(1, min(12, $limit)))
            ->get();
    }

    public function summary(CatalogCollection $collection): CatalogCollection
    {
        return $this->summaryQuery()->whereKey($collection->id)->firstOrFail();
    }

    public function isPubliclyListed(CatalogCollection $collection): bool
    {
        return CatalogCollection::query()
            ->publiclyListed()
            ->whereKey($collection->id)
            ->exists();
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
        $query = $this->titles->visibleTo($viewer);

        if ($collection->isSmart()) {
            $query = $this->smartCollections
                ->constrain($query, $collection, $viewer)
                ->select('catalog_titles.*')
                ->addSelect('catalog_titles.id as collection_item_id')
                ->withCasts(['collection_item_id' => 'integer']);
        } else {
            $query = $query
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
                ]);

            if ($this->schema->qualityAvailable()) {
                $query
                    ->addSelect([
                        'collection_item.theme_match_percent as collection_theme_match_percent',
                        'collection_item.inclusion_reason_code as collection_inclusion_reason_code',
                        'collection_item.quality_content_version as collection_quality_content_version',
                    ])
                    ->withCasts([
                        'collection_theme_match_percent' => 'integer',
                        'collection_quality_content_version' => 'integer',
                    ]);
            }
        }

        $query
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

        $query = match ([$collection->isSmart(), $criteria->sort]) {
            [false, CatalogCollectionSort::Manual] => $query->orderBy('collection_item.position')->orderBy('collection_item.id'),
            [false, CatalogCollectionSort::RecentlyAdded] => $query->orderByDesc('collection_item.created_at')->orderByDesc('collection_item.id'),
            [false, CatalogCollectionSort::OldestAdded] => $query->orderBy('collection_item.created_at')->orderBy('collection_item.id'),
            [true, CatalogCollectionSort::Manual], [true, CatalogCollectionSort::RecentlyUpdated] => $query
                ->orderByDesc('catalog_titles.indexed_at')
                ->orderByDesc('catalog_titles.id'),
            [true, CatalogCollectionSort::RecentlyAdded] => $query
                ->orderByDesc('catalog_titles.content_added_at')
                ->orderByDesc('catalog_titles.id'),
            [true, CatalogCollectionSort::OldestAdded] => $query
                ->orderBy('catalog_titles.content_added_at')
                ->orderBy('catalog_titles.id'),
            [false, CatalogCollectionSort::Title], [true, CatalogCollectionSort::Title] => $query
                ->orderBy('catalog_titles.title')
                ->orderBy('catalog_titles.id'),
            [false, CatalogCollectionSort::ReleaseYear], [true, CatalogCollectionSort::ReleaseYear] => $query
                ->orderByDesc('catalog_titles.year')
                ->orderByDesc('catalog_titles.id'),
            [false, CatalogCollectionSort::Rating], [true, CatalogCollectionSort::Rating] => $query
                ->withMax('ratings as collection_rating', 'rating')
                ->orderByDesc('collection_rating')
                ->orderByDesc('catalog_titles.id'),
            [false, CatalogCollectionSort::RecentlyUpdated] => $query
                ->orderByDesc('catalog_titles.indexed_at')
                ->orderByDesc('catalog_titles.id'),
        };

        $paginator = $query->paginate(max(6, min(48, $criteria->perPage)), pageName: $pageName);

        if ($collection->isSmart()) {
            $paginator->getCollection()->each(function (CatalogTitle $title) use ($collection): void {
                $title->setAttribute('collection_theme_match_percent', 100);
                $title->setAttribute('collection_inclusion_reason_code', 'smart_rule');
                $title->setAttribute(
                    'collection_quality_content_version',
                    $collection->content_version,
                );
            });
        }

        $this->cardStates->load(collect($paginator->items()), $viewer);

        return $paginator;
    }

    /** @return Collection<int, CatalogCollectionItem> */
    public function unavailableItems(CatalogCollection $collection, User $viewer, int $limit = 20): Collection
    {
        if (! $collection->isOwnedBy($viewer) || $collection->isSmart()) {
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
        $visibleCollectionTitleIds = $collection->isSmart()
            ? $this->smartCollections
                ->constrain($this->titles->visibleTo($viewer), $collection, $viewer)
                ->select('catalog_titles.id')
            : $this->titles->visibleTo($viewer)
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
        $years = (clone $visibleCollectionTitleIds)
            ->select('catalog_titles.year')
            ->whereNotNull('year')
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn (mixed $year): int => (int) $year)
            ->values();

        return compact('genres', 'countries', 'statuses', 'years');
    }

    /** @return LengthAwarePaginator<int, CatalogCollection> */
    public function moderationQueue(
        string $search = '',
        string $qualityFilter = 'all',
        int $perPage = 20,
    ): LengthAwarePaginator {
        $perPage = max(10, min(50, $perPage));

        if (! $this->schema->available()) {
            return $this->emptyPaginator($perPage, 'collectionAdminPage');
        }

        $search = $this->search->display(mb_substr($search, 0, 100));
        $qualityAvailable = $this->schema->qualityAvailable();
        $qualityFilter = in_array($qualityFilter, [
            'all',
            'critical',
            'warning',
            'low',
            'stale',
            'unassessed',
            'verified',
            ...array_keys(self::QUALITY_ISSUE_FILTER_CODES),
        ], true) && $qualityAvailable ? $qualityFilter : 'all';

        $query = $this->summaryQuery()
            ->withTrashed()
            ->withCount(['reports as open_reports_count' => fn (Builder $query): Builder => $query->where('status', CatalogCollectionReportStatus::Open->value)])
            ->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('slug', 'like', $search.'%');
            }))
            ->when($qualityFilter === 'all', fn (Builder $query): Builder => $query
                ->where(function (Builder $queue) use ($qualityAvailable): void {
                    $queue
                        ->where('moderation_status', CatalogCollectionModerationStatus::Pending->value)
                        ->orWhereHas('reports', fn (Builder $reports): Builder => $reports
                            ->where('status', CatalogCollectionReportStatus::Open->value))
                        ->orWhere(fn (Builder $editorial): Builder => $editorial
                            ->where('type', CatalogCollectionType::Editorial->value)
                            ->where('visibility', CatalogCollectionVisibility::Public->value)
                            ->where('moderation_status', CatalogCollectionModerationStatus::Approved->value)
                            ->whereNull('catalog_collections.deleted_at'));

                    if ($qualityAvailable) {
                        $queue->orWhereHas('qualityIssues', fn (Builder $issues): Builder => $issues
                            ->where('status', CatalogCollectionQualityIssueStatus::Open->value));
                    }
                }))
            ->when(
                in_array($qualityFilter, ['critical', 'warning'], true),
                fn (Builder $query): Builder => $query->whereHas(
                    'qualityIssues',
                    fn (Builder $issues): Builder => $issues
                        ->where('status', CatalogCollectionQualityIssueStatus::Open->value)
                        ->where('severity', $qualityFilter),
                ),
            )
            ->when($qualityFilter === 'low', fn (Builder $query): Builder => $query
                ->whereColumn('quality_content_version', 'content_version')
                ->where(
                    'quality_score',
                    '<',
                    min(100, max(0, (int) config(
                        'catalog-collections.quality.minimum_public_score',
                        60,
                    ))),
                ))
            ->when($qualityFilter === 'stale', fn (Builder $query): Builder => $query
                ->where(function (Builder $assessed): void {
                    $assessed
                        ->whereNotNull('quality_score')
                        ->orWhereNotNull('quality_content_version')
                        ->orWhereNotNull('quality_evaluated_at');
                })
                ->where(function (Builder $quality): void {
                    $quality
                        ->whereNull('quality_score')
                        ->orWhereNull('quality_content_version')
                        ->orWhereNull('quality_evaluated_at')
                        ->orWhereColumn('quality_content_version', '!=', 'content_version')
                        ->orWhere(
                            'quality_evaluated_at',
                            '<',
                            now()->subDays(max(
                                1,
                                (int) config(
                                    'catalog-collections.quality.stale_after_days',
                                    14,
                                ),
                            )),
                        );
                }))
            ->when($qualityFilter === 'unassessed', fn (Builder $query): Builder => $query
                ->whereNull('quality_score')
                ->whereNull('quality_content_version')
                ->whereNull('quality_evaluated_at'))
            ->when($qualityFilter === 'verified', fn (Builder $query): Builder => $query
                ->whereNotNull('editorially_verified_at')
                ->whereColumn('editorially_verified_content_version', 'content_version'))
            ->when(
                isset(self::QUALITY_ISSUE_FILTER_CODES[$qualityFilter]),
                fn (Builder $query): Builder => $query->whereHas(
                    'qualityIssues',
                    fn (Builder $issues): Builder => $issues
                        ->where('status', CatalogCollectionQualityIssueStatus::Open->value)
                        ->whereIn(
                            'code',
                            self::QUALITY_ISSUE_FILTER_CODES[$qualityFilter],
                        ),
                ),
            )
            ->orderByRaw('CASE WHEN moderation_status = ? THEN 0 ELSE 1 END', [CatalogCollectionModerationStatus::Pending->value])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($qualityAvailable) {
            $query->with(['qualityIssues' => fn ($issues) => $issues
                ->select(['id', 'catalog_collection_id', 'code'])
                ->where('status', CatalogCollectionQualityIssueStatus::Open->value)
                ->orderBy('id')]);
        }

        $paginator = $query->paginate($perPage, pageName: 'collectionAdminPage');

        if (! $qualityAvailable) {
            $paginator->getCollection()->each(function (CatalogCollection $collection): void {
                $collection->setRelation('qualityIssues', new EloquentCollection);
            });
        }

        return $paginator;
    }

    /**
     * @return array{
     *     status: string,
     *     counters: array{collections_processed: int, pages: int, items: int, matched: int, ambiguous: int, unmatched: int},
     *     diagnostics: array{
     *         empty_collections: int,
     *         actionable_empty_collections: int,
     *         unsupported_empty_collections: int,
     *         match_coverage_percent: float,
     *         source_scopes: array{supported: int, unsupported: int, unknown: int},
     *         match_methods: array<string, int>
     *     },
     *     completed_at_label: string,
     *     completed_at_iso: string
     * }|null
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
            'diagnostics' => $this->sourceSyncDiagnostics($run->id, $counters),
            'completed_at_label' => $timestamp->format('d.m.Y H:i'),
            'completed_at_iso' => $timestamp->toAtomString(),
        ];
    }

    /**
     * @param  array{collections_processed: int, pages: int, items: int, matched: int, ambiguous: int, unmatched: int}  $counters
     * @return array{
     *     empty_collections: int,
     *     actionable_empty_collections: int,
     *     unsupported_empty_collections: int,
     *     match_coverage_percent: float,
     *     source_scopes: array{supported: int, unsupported: int, unknown: int},
     *     match_methods: array<string, int>
     * }
     */
    private function sourceSyncDiagnostics(int $runId, array $counters): array
    {
        $emptyCollections = CatalogCollectionSource::query()
            ->where('provider', 'hdrezka')
            ->whereNotNull('catalog_collection_id')
            ->whereNotIn(
                'catalog_collection_id',
                CatalogCollectionItem::query()->select('catalog_collection_id'),
            )
            ->count();
        $scopeCounts = array_fill_keys(
            array_map(
                fn (CatalogCollectionSourceScope $scope): string => $scope->value,
                CatalogCollectionSourceScope::cases(),
            ),
            0,
        );
        $emptyScopeState = [];
        $scopeRows = CatalogCollectionSourceItem::query()
            ->join(
                'catalog_collection_sources',
                'catalog_collection_sources.id',
                '=',
                'catalog_collection_source_items.catalog_collection_source_id',
            )
            ->where('catalog_collection_sources.provider', 'hdrezka')
            ->where('catalog_collection_source_items.last_seen_run_id', $runId)
            ->toBase()
            ->select([
                'catalog_collection_source_items.catalog_collection_source_id',
                'catalog_collection_source_items.source_type',
            ])
            ->selectRaw('COUNT(*) AS aggregate')
            ->selectRaw(
                'CASE WHEN catalog_collection_sources.catalog_collection_id IS NOT NULL
                    AND NOT EXISTS (
                        SELECT 1
                        FROM catalog_collection_items
                        WHERE catalog_collection_items.catalog_collection_id = catalog_collection_sources.catalog_collection_id
                    )
                    THEN 1 ELSE 0 END AS collection_is_empty',
            )
            ->groupBy(
                'catalog_collection_source_items.catalog_collection_source_id',
                'catalog_collection_source_items.source_type',
                'catalog_collection_sources.catalog_collection_id',
            )
            ->get();

        foreach ($scopeRows as $row) {
            $scope = $this->sourceTypes->sourceScope(
                is_string($row->source_type) ? $row->source_type : null,
            );
            $scopeCounts[$scope->value] += max(0, (int) $row->aggregate);

            if ((int) $row->collection_is_empty !== 1) {
                continue;
            }

            $sourceId = (int) $row->catalog_collection_source_id;
            $emptyScopeState[$sourceId] ??= false;

            if ($scope !== CatalogCollectionSourceScope::Unsupported) {
                $emptyScopeState[$sourceId] = true;
            }
        }

        $unsupportedEmptyCollections = collect($emptyScopeState)
            ->filter(fn (bool $actionable): bool => ! $actionable)
            ->count();
        $matchMethods = array_fill_keys(array_values(self::SOURCE_MATCH_METHOD_METRIC_KEYS), 0);
        $rows = CatalogCollectionSourceItem::query()
            ->whereIn(
                'catalog_collection_source_id',
                CatalogCollectionSource::query()
                    ->select('id')
                    ->where('provider', 'hdrezka'),
            )
            ->where('last_seen_run_id', $runId)
            ->whereNotNull('match_method')
            ->toBase()
            ->select(['match_status', 'match_method'])
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('match_status', 'match_method')
            ->get();

        foreach ($rows as $row) {
            $metricKey = self::SOURCE_MATCH_METHOD_METRIC_KEYS[
                (string) $row->match_status.':'.(string) $row->match_method
            ] ?? null;

            if ($metricKey !== null) {
                $matchMethods[$metricKey] = max(0, (int) $row->aggregate);
            }
        }

        $matched = min($counters['matched'], $counters['items']);

        return [
            'empty_collections' => $emptyCollections,
            'actionable_empty_collections' => max(0, $emptyCollections - $unsupportedEmptyCollections),
            'unsupported_empty_collections' => $unsupportedEmptyCollections,
            'match_coverage_percent' => $counters['items'] === 0
                ? 0.0
                : round(($matched / $counters['items']) * 100, 2),
            'source_scopes' => $scopeCounts,
            'match_methods' => $matchMethods,
        ];
    }

    /** @return Builder<CatalogCollection> */
    public function publicSitemapQuery(): Builder
    {
        return CatalogCollection::query()
            ->publiclyListed()
            ->whereHas('items', fn (Builder $query): Builder => $query
                ->whereIn('catalog_title_id', $this->visibleTitleIds()))
            ->select(['id', 'public_id', 'slug', 'name', 'is_featured', 'updated_at'])
            ->orderBy('id');
    }

    /** @return Builder<CatalogCollection> */
    private function summaryQuery(bool $withCounts = true): Builder
    {
        $query = CatalogCollection::query()
            ->select('catalog_collections.*')
            ->with('owner:id,public_id,name')
            ->with([
                'category:id,public_id,parent_id,slug,position,is_active',
                'category.translations' => fn ($query) => $query
                    ->select(['id', 'catalog_collection_category_id', 'locale', 'name'])
                    ->whereIn('locale', $this->searchLocales()),
                'category.parent:id,public_id,parent_id,slug,position,is_active',
                'category.parent.translations' => fn ($query) => $query
                    ->select(['id', 'catalog_collection_category_id', 'locale', 'name'])
                    ->whereIn('locale', $this->searchLocales()),
            ])
            ->with(['translations' => fn ($query) => $query
                ->select(['id', 'catalog_collection_id', 'locale', 'name', 'description', 'seo_title', 'seo_description'])
                ->whereIn('locale', array_values(array_unique([
                    app()->currentLocale(),
                    (string) config('catalog-collections.default_locale', 'ru'),
                ])))]);

        if ($withCounts) {
            $query->withCount([
                'items as total_items_count',
                'items as visible_items_count' => fn (Builder $query): Builder => $query
                    ->whereIn('catalog_title_id', $this->visibleTitleIds()),
            ]);
        }

        if ($this->schema->sourceSyncAvailable()) {
            $query
                ->with('sourceRecord:id,catalog_collection_id,missing_since_at')
                ->withExists(['sourceRecord as has_import_source']);
        } else {
            $query->selectRaw('0 as has_import_source');
        }

        return $query;
    }

    /** @param Builder<CatalogCollection> $query */
    private function applyCategoryFilter(
        Builder $query,
        ?string $category,
        ?string $subcategory,
    ): void {
        $category = is_string($category) ? Str::lower(trim($category)) : null;
        $subcategory = is_string($subcategory) ? Str::lower(trim($subcategory)) : null;
        $category = $category !== '' ? $category : null;
        $subcategory = $subcategory !== '' ? $subcategory : null;

        if ($category === null) {
            if ($subcategory !== null) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        if ($category === 'uncategorized') {
            $subcategory === null
                ? $query->whereNull('catalog_collection_category_id')
                : $query->whereRaw('1 = 0');

            return;
        }

        if (! $this->validCategorySlug($category)
            || ($subcategory !== null && ! $this->validCategorySlug($subcategory))) {
            $query->whereRaw('1 = 0');

            return;
        }

        $rootIds = CatalogCollectionCategory::query()
            ->select('id')
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->where('slug', $category);

        if ($subcategory !== null) {
            $query->whereIn(
                'catalog_collection_category_id',
                CatalogCollectionCategory::query()
                    ->select('id')
                    ->where('is_active', true)
                    ->where('slug', $subcategory)
                    ->whereIn('parent_id', clone $rootIds),
            );

            return;
        }

        $query->where(function (Builder $query) use ($rootIds): void {
            $query
                ->whereIn('catalog_collection_category_id', clone $rootIds)
                ->orWhereIn(
                    'catalog_collection_category_id',
                    CatalogCollectionCategory::query()
                        ->select('id')
                        ->where('is_active', true)
                        ->whereIn('parent_id', clone $rootIds),
                );
        });
    }

    private function validCategorySlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,119}$/D', $slug) === 1;
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
