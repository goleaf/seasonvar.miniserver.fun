<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\DTOs\ContentRequests\ContentRequestCardData;
use App\DTOs\ContentRequests\ContentRequestDetailData;
use App\Enums\ContentRequestSort;
use App\Enums\ContentRequestStatus;
use App\Enums\ContentRequestType;
use App\Models\CatalogTitle;
use App\Models\ContentRequest;
use App\Models\User;
use App\Services\Catalog\Search\CatalogSearchNormalizer;
use App\Services\Catalog\Search\CatalogSearchQueryParser;
use App\Services\Catalog\Search\CatalogTitleSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final readonly class ContentRequestQuery
{
    public function __construct(
        private ContentRequestPresenter $presenter,
        private CatalogSearchNormalizer $normalizer,
        private CatalogSearchQueryParser $catalogSearchParser,
        private CatalogTitleSearch $catalogTitleSearch,
    ) {}

    /** @return LengthAwarePaginator<int, ContentRequestCardData> */
    public function directory(
        ?User $viewer,
        string $search,
        ?ContentRequestType $type,
        ?ContentRequestStatus $status,
        ContentRequestSort $sort,
    ): LengthAwarePaginator {
        $query = ContentRequest::query()->publiclyVisible();
        $this->applyFilters($query, $search, $type, $status);

        return $this->paginate($this->base($query, $viewer), $viewer, $sort, 'requestsPage');
    }

    /** @return LengthAwarePaginator<int, ContentRequestCardData> */
    public function mine(User $viewer, string $scope, ?ContentRequestStatus $status, ContentRequestSort $sort): LengthAwarePaginator
    {
        $query = ContentRequest::query()->where(function (Builder $query) use ($viewer, $scope): void {
            match ($scope) {
                'voted' => $query->whereHas('votes', fn (Builder $votes): Builder => $votes->where('user_id', $viewer->id)),
                'followed' => $query->whereHas('followers', fn (Builder $followers): Builder => $followers->where('user_id', $viewer->id)),
                default => $query->where('requester_id', $viewer->id),
            };
        });
        $this->applyFilters($query, '', null, $status);

        return $this->paginate($this->base($query, $viewer), $viewer, $sort, 'myRequestsPage');
    }

    /** @return LengthAwarePaginator<int, ContentRequestCardData> */
    public function administration(string $search, ?ContentRequestType $type, ?ContentRequestStatus $status, ContentRequestSort $sort): LengthAwarePaginator
    {
        $query = ContentRequest::query();
        $this->applyFilters($query, $search, $type, $status);

        return $this->paginate($this->base($query, null), null, $sort, 'adminRequestsPage', (int) config('content-requests.admin_per_page', 25));
    }

    public function detail(ContentRequest $request, ?User $viewer, bool $includeClarifications): ContentRequestDetailData
    {
        $canModerate = $viewer !== null && Gate::forUser($viewer)->allows('moderate', $request);
        $request->loadMissing([
            'catalogTitle:id,slug,title,original_title',
            'completedCatalogTitle:id,slug,title,original_title',
            'completedSeason:id,catalog_title_id,number',
            'completedSeason.catalogTitle:id,slug,title,original_title',
            'completedEpisode:id,season_id,number',
            'completedEpisode.season:id,catalog_title_id,number',
            'completedEpisode.season.catalogTitle:id,slug,title,original_title',
            'completedMedia:id,catalog_title_id,season_id,episode_id',
            'statusHistory' => fn ($query) => $query->select(['id', 'content_request_id', 'to_status', 'public_reason', 'created_at']),
            'sourceLinks' => fn ($query) => $query
                ->when(! $canModerate, fn ($query) => $query->where('is_public', true))
                ->select(['id', 'content_request_id', 'url', 'provider']),
            'externalIdentifiers:id,content_request_id,provider,identifier',
        ])->loadCount(['votes', 'followers']);
        $this->viewerOverlay($request, $viewer);

        if ($includeClarifications) {
            $request->loadMissing('clarifications:id,content_request_id,author_role,body,created_at');
        }

        return $this->presenter->detail($request, $viewer, $includeClarifications);
    }

    /** @return list<array{kind: string, id: int, label: string, meta: string, url: string}> */
    public function autocomplete(string $search): array
    {
        $search = str_replace(['%', '_', '\\'], '', trim($search));

        if (mb_strlen($search) < 2) {
            return [];
        }

        $limit = max(1, (int) config('content-requests.autocomplete_limit', 8));
        $catalogSearch = $this->catalogSearchParser->parse($search);
        $candidateIds = $this->catalogTitleSearch->candidateQuery($catalogSearch)
            ?->limit($limit)
            ->pluck('catalog_title_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values() ?? collect();
        $titles = $candidateIds->isNotEmpty()
            ? CatalogTitle::query()->availableTo(null)
                ->whereKey($candidateIds)->get(['id', 'slug', 'title', 'original_title', 'year'])
                ->sortBy(function ($title) use ($candidateIds): int {
                    $position = $candidateIds->search($title->id, strict: true);

                    return $position === false ? PHP_INT_MAX : $position;
                })->values()
            : CatalogTitle::query()->availableTo(null)
                ->where(function (Builder $query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('original_title', 'like', "%{$search}%")
                        ->orWhereHas('aliases', fn (Builder $aliases): Builder => $aliases->where('name', 'like', "%{$search}%"));
                })->orderBy('title')->orderBy('id')->limit($limit)->get(['id', 'slug', 'title', 'original_title', 'year']);

        $requests = ContentRequest::query()->publiclyVisible()
            ->where(function (Builder $query) use ($search): void {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('original_title', 'like', "%{$search}%")
                    ->orWhere('alternative_title', 'like', "%{$search}%");
            })->orderByDesc('updated_at')->limit($limit)->get(['id', 'public_id', 'title', 'status']);

        return $titles->map(fn ($title): array => [
            'kind' => 'catalog', 'id' => $title->id, 'label' => $title->display_title,
            'meta' => $title->year !== null ? (string) $title->year : '', 'url' => route('titles.show', $title),
        ])->concat($requests->map(fn (ContentRequest $request): array => [
            'kind' => 'request', 'id' => $request->id, 'label' => $request->title,
            'meta' => $request->status->label(), 'url' => route('requests.show', $request),
        ]))->values()->all();
    }

    /** @param Builder<ContentRequest> $query */
    private function base(Builder $query, ?User $viewer): Builder
    {
        return $query->select([
            'content_requests.id',
            'content_requests.public_id',
            'content_requests.requester_id',
            'content_requests.type',
            'content_requests.status',
            'content_requests.title',
            'content_requests.normalized_title',
            'content_requests.original_title',
            'content_requests.release_year',
            'content_requests.catalog_title_id',
            'content_requests.created_at',
            'content_requests.updated_at',
        ])
            ->with('catalogTitle:id,slug,title,original_title')
            ->withCount(['votes', 'followers'])
            ->when($viewer !== null, fn (Builder $query): Builder => $query
                ->withExists(['votes as viewer_has_voted' => fn (Builder $votes): Builder => $votes->where('user_id', $viewer->id)])
                ->withExists(['followers as viewer_is_following' => fn (Builder $followers): Builder => $followers->where('user_id', $viewer->id)]));
    }

    /** @param Builder<ContentRequest> $query */
    private function applyFilters(Builder $query, string $search, ?ContentRequestType $type, ?ContentRequestStatus $status): void
    {
        $search = str_replace(['%', '_', '\\'], '', trim($search));
        $normalized = $this->normalizer->key($search);
        $query->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $searchQuery) use ($search, $normalized): void {
            $searchQuery->where('title', 'like', "%{$search}%")
                ->orWhere('original_title', 'like', "%{$search}%")
                ->orWhere('alternative_title', 'like', "%{$search}%")
                ->when($normalized !== '', fn (Builder $query): Builder => $query->orWhere('normalized_title', 'like', "%{$normalized}%"));
        }))->when($type !== null, fn (Builder $query): Builder => $query->where('type', $type->value))
            ->when($status !== null, fn (Builder $query): Builder => $query->where('status', $status->value));
    }

    /** @param Builder<ContentRequest> $query
     * @return LengthAwarePaginator<int, ContentRequestCardData>
     */
    private function paginate(Builder $query, ?User $viewer, ContentRequestSort $sort, string $pageName, ?int $perPage = null): LengthAwarePaginator
    {
        match ($sort) {
            ContentRequestSort::MostVoted => $query->orderByDesc('votes_count')->orderByDesc('created_at')->orderByDesc('id'),
            ContentRequestSort::Oldest => $query->oldest('created_at')->orderBy('id'),
            ContentRequestSort::RecentlyUpdated => $query->latest('updated_at')->orderByDesc('id'),
            ContentRequestSort::Title => $query->orderBy('normalized_title')->orderBy('id'),
            default => $query->latest('created_at')->orderByDesc('id'),
        };

        return $query->paginate(max(1, $perPage ?? (int) config('content-requests.per_page', 20)), pageName: $pageName)
            ->through(fn (ContentRequest $request): ContentRequestCardData => $this->presenter->card($request, $viewer));
    }

    private function viewerOverlay(ContentRequest $request, ?User $viewer): void
    {
        $request->setAttribute('viewer_has_voted', $viewer !== null && $request->votes()->where('user_id', $viewer->id)->exists());
        $request->setAttribute('viewer_is_following', $viewer !== null && $request->followers()->where('user_id', $viewer->id)->exists());
    }
}
