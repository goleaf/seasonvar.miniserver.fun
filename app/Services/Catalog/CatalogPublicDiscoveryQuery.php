<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationContext;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Enums\CatalogRecommendationReason;
use App\Enums\CatalogRecommendationSource;
use App\Enums\CatalogRecommendationType;
use App\Enums\CommentStatus;
use App\Enums\CommentTargetType;
use App\Enums\ReviewStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CatalogPublicDiscoveryQuery
{
    public function __construct(
        private readonly CatalogRecommendationVisibilityService $visibility,
        private readonly CatalogPopularityQuery $popularity,
    ) {}

    /**
     * @param  list<int>  $excludedIds
     * @return list<array{id: int, score: int, source: string, reason: string, relation_type?: string|null}>
     */
    public function candidates(CatalogRecommendationContext $context, array $excludedIds = []): array
    {
        return match ($context->type) {
            CatalogRecommendationType::Trending => $this->trending($context, $excludedIds),
            CatalogRecommendationType::Popular => $this->popular($context, $excludedIds),
            CatalogRecommendationType::TopRated => $this->topRated($context, $excludedIds),
            CatalogRecommendationType::RecentlyAdded => $this->recentlyAdded($context, $excludedIds),
            CatalogRecommendationType::RecentlyUpdated => $this->recentlyUpdated($context, $excludedIds),
            CatalogRecommendationType::Upcoming => $this->upcoming($context, $excludedIds),
            CatalogRecommendationType::Editorial => $this->editorial($context, $excludedIds),
            CatalogRecommendationType::Random => $this->random($context, $excludedIds),
            default => [],
        };
    }

    /**
     * @param  list<int>  $excludedIds
     * @return list<array{id: int, score: int, source: string, reason: string}>
     */
    private function trending(CatalogRecommendationContext $context, array $excludedIds): array
    {
        $after = now()->subDays(min(
            max(1, $context->period->days()),
            max(1, (int) config('recommendations.trending.maximum_period_days', 30)),
        ));
        $events = DB::table('episode_view_progress')
            ->select('catalog_title_id')
            ->selectRaw('COUNT(DISTINCT user_id) * 40 AS activity_score')
            ->selectRaw('COUNT(DISTINCT user_id) AS watcher_count')
            ->where('last_watched_at', '>=', $after)
            ->where(function (QueryBuilder $query): void {
                $query
                    ->where('position_seconds', '>=', max(1, (int) config('recommendations.meaningful_progress_seconds', 180)))
                    ->orWhere('progress_percent', '>=', max(1, (int) config('recommendations.meaningful_progress_percent', 10)))
                    ->orWhereNotNull('completed_at');
            })
            ->groupBy('catalog_title_id')
            ->unionAll(DB::table('catalog_title_user_states')
                ->select('catalog_title_id')
                ->selectRaw('COUNT(*) * 25 AS activity_score')
                ->selectRaw('0 AS watcher_count')
                ->where('in_watchlist', true)
                ->where('updated_at', '>=', $after)
                ->groupBy('catalog_title_id'))
            ->unionAll(DB::table('catalog_title_reviews')
                ->select('catalog_title_id')
                ->selectRaw('COUNT(*) * 8 AS activity_score')
                ->selectRaw('0 AS watcher_count')
                ->where('status', ReviewStatus::Published->value)
                ->whereNull('deleted_at')
                ->whereNull('merged_into_id')
                ->where('published_at', '>=', $after)
                ->groupBy('catalog_title_id'))
            ->unionAll(DB::table('comments')
                ->select('catalog_title_id')
                ->selectRaw('COUNT(*) * 4 AS activity_score')
                ->selectRaw('0 AS watcher_count')
                ->where('target_type', CommentTargetType::Title->value)
                ->where('status', CommentStatus::Published->value)
                ->whereNull('deleted_at')
                ->where('created_at', '>=', $after)
                ->groupBy('catalog_title_id'));
        $activity = DB::query()
            ->fromSub($events, 'recommendation_recent_events')
            ->select('catalog_title_id')
            ->selectRaw('SUM(activity_score) AS activity_score')
            ->selectRaw('SUM(watcher_count) AS watcher_count')
            ->groupBy('catalog_title_id');
        $query = $this->eligibleQuery($context, watchable: true, excludedIds: $excludedIds)
            ->joinSub($activity, 'recommendation_recent_activity', 'recommendation_recent_activity.catalog_title_id', '=', 'catalog_titles.id')
            ->select('catalog_titles.id')
            ->orderByDesc('recommendation_recent_activity.activity_score')
            ->orderByDesc('recommendation_recent_activity.watcher_count')
            ->orderByDesc('catalog_titles.id');

        return $this->rows($query, CatalogRecommendationSource::Trending, CatalogRecommendationReason::Trending);
    }

    /**
     * @param  list<int>  $excludedIds
     * @return list<array{id: int, score: int, source: string, reason: string}>
     */
    private function popular(CatalogRecommendationContext $context, array $excludedIds): array
    {
        $provider = $this->provider($context->ratingSource);
        $query = $this->eligibleQuery($context, watchable: true, excludedIds: $excludedIds)
            ->select('catalog_titles.id');
        $this->popularity->apply($query, $provider)->orderByDesc('catalog_titles.id');

        return $this->rows($query, CatalogRecommendationSource::Popularity, CatalogRecommendationReason::Popular);
    }

    /**
     * @param  list<int>  $excludedIds
     * @return list<array{id: int, score: int, source: string, reason: string}>
     */
    private function topRated(CatalogRecommendationContext $context, array $excludedIds): array
    {
        $source = $context->ratingSource;
        $minimumVotes = max(1, (int) config("recommendations.top_rated.minimum_votes.{$source}", 1_000));
        $query = $this->eligibleQuery($context, watchable: true, excludedIds: $excludedIds);

        if ($source === 'portal') {
            $ratings = DB::table('catalog_title_user_states')
                ->select('catalog_title_id')
                ->selectRaw('AVG(rating) AS source_rating')
                ->selectRaw('COUNT(rating) AS source_votes')
                ->whereNotNull('rating')
                ->groupBy('catalog_title_id')
                ->havingRaw('COUNT(rating) >= ?', [$minimumVotes]);

            $query->joinSub(
                $ratings,
                'recommendation_rating',
                'recommendation_rating.catalog_title_id',
                '=',
                'catalog_titles.id',
            );
            $ratingColumn = 'recommendation_rating.source_rating';
            $votesColumn = 'recommendation_rating.source_votes';
        } else {
            $provider = $this->provider($source);
            $query
                ->join('catalog_title_ratings as recommendation_rating', function (JoinClause $join) use ($provider): void {
                    $join
                        ->on('recommendation_rating.catalog_title_id', '=', 'catalog_titles.id')
                        ->where('recommendation_rating.provider', '=', $provider);
                })
                ->whereNotNull('recommendation_rating.rating')
                ->where('recommendation_rating.votes', '>=', $minimumVotes);
            $ratingColumn = 'recommendation_rating.rating';
            $votesColumn = 'recommendation_rating.votes';
        }

        $query
            ->select('catalog_titles.id')
            ->orderByDesc($ratingColumn)
            ->orderByDesc($votesColumn)
            ->orderByDesc('catalog_titles.id');

        return $this->rows($query, CatalogRecommendationSource::Rating, CatalogRecommendationReason::TopRated);
    }

    /**
     * @param  list<int>  $excludedIds
     * @return list<array{id: int, score: int, source: string, reason: string}>
     */
    private function recentlyAdded(CatalogRecommendationContext $context, array $excludedIds): array
    {
        $query = $this->eligibleQuery($context, watchable: true, excludedIds: $excludedIds)
            ->select('catalog_titles.id')
            ->whereNotNull('indexed_at')
            ->orderByDesc('indexed_at')
            ->orderByDesc('catalog_titles.id');

        return $this->rows($query, CatalogRecommendationSource::CatalogPublication, CatalogRecommendationReason::RecentlyAdded);
    }

    /**
     * @param  list<int>  $excludedIds
     * @return list<array{id: int, score: int, source: string, reason: string}>
     */
    private function recentlyUpdated(CatalogRecommendationContext $context, array $excludedIds): array
    {
        $limit = $this->candidateLimit();
        $eventWindow = min(
            max(1, (int) config('recommendations.content_updates.maximum_event_window', 20_000)),
            max(
                max(1, (int) config('recommendations.content_updates.minimum_event_window', 10_000)),
                $limit * max(1, (int) config('recommendations.content_updates.event_window_multiplier', 64)),
            ),
        );
        $orderedTitleIds = $this->recentContentEvents($eventWindow)
            ->pluck('catalog_title_id')
            ->unique()
            ->values();
        $eligibleTitleIds = $this->eligibleOrderedIds($context, $orderedTitleIds, $excludedIds, $limit);

        return $eligibleTitleIds
            ->map(fn (int $id, int $index): array => [
                'id' => $id,
                'score' => $limit - $index,
                'source' => CatalogRecommendationSource::ContentUpdate->value,
                'reason' => CatalogRecommendationReason::RecentlyUpdated->value,
            ])
            ->all();
    }

    /**
     * @return Collection<int, array{catalog_title_id: positive-int, event_at: non-empty-string, event_id: int, event_source: string}>
     */
    private function recentContentEvents(int $limit): Collection
    {
        $media = DB::table('licensed_media')
            ->select(['catalog_title_id', 'published_at as event_at', 'id as event_id'])
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (object $event): array => [
                'catalog_title_id' => (int) $event->catalog_title_id,
                'event_at' => (string) $event->event_at,
                'event_id' => (int) $event->event_id,
                'event_source' => 'media',
            ]);
        $episodes = DB::table('episodes')
            ->join('seasons', 'seasons.id', '=', 'episodes.season_id')
            ->select(['seasons.catalog_title_id', 'episodes.released_at as event_at', 'episodes.id as event_id'])
            ->where('episodes.publication_status', 'published')
            ->whereNull('episodes.deleted_at')
            ->whereNull('seasons.deleted_at')
            ->whereNotNull('episodes.released_at')
            ->where('episodes.released_at', '<=', now())
            ->orderByDesc('episodes.released_at')
            ->orderByDesc('episodes.id')
            ->limit($limit)
            ->get()
            ->map(fn (object $event): array => [
                'catalog_title_id' => (int) $event->catalog_title_id,
                'event_at' => (string) $event->event_at,
                'event_id' => (int) $event->event_id,
                'event_source' => 'episode',
            ]);

        return $media
            ->concat($episodes)
            ->filter(fn (array $event): bool => $event['catalog_title_id'] > 0 && $event['event_at'] !== '')
            ->sort(function (array $left, array $right): int {
                $dateOrder = strcmp($right['event_at'], $left['event_at']);

                if ($dateOrder !== 0) {
                    return $dateOrder;
                }

                $sourceOrder = strcmp($left['event_source'], $right['event_source']);

                return $sourceOrder !== 0 ? $sourceOrder : $right['event_id'] <=> $left['event_id'];
            })
            ->values();
    }

    /**
     * @param  Collection<int, int>  $orderedTitleIds
     * @param  list<int>  $excludedIds
     * @return Collection<int, int>
     */
    private function eligibleOrderedIds(
        CatalogRecommendationContext $context,
        Collection $orderedTitleIds,
        array $excludedIds,
        int $limit,
    ): Collection {
        $eligibleTitleIds = collect();
        $excludedLookup = collect($excludedIds)
            ->filter(fn (int $id): bool => $id > 0)
            ->flip();

        foreach ($orderedTitleIds->reject(fn (int $id): bool => $excludedLookup->has($id))->chunk(500) as $chunk) {
            $eligibleLookup = $this->eligibleQuery($context, watchable: true, excludedIds: [])
                ->whereKey($chunk->all())
                ->pluck('catalog_titles.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->flip();
            $eligibleTitleIds = $eligibleTitleIds
                ->concat($chunk->filter(fn (int $id): bool => $eligibleLookup->has($id)))
                ->take($limit)
                ->values();

            if ($eligibleTitleIds->count() >= $limit) {
                break;
            }
        }

        return $eligibleTitleIds;
    }

    /**
     * @param  list<int>  $excludedIds
     * @return list<array{id: int, score: int, source: string, reason: string}>
     */
    private function upcoming(CatalogRecommendationContext $context, array $excludedIds): array
    {
        $query = $this->eligibleQuery($context, watchable: false, excludedIds: $excludedIds)
            ->select('catalog_titles.id')
            ->selectSub(Episode::query()
                ->join('seasons', 'seasons.id', '=', 'episodes.season_id')
                ->selectRaw('MIN(episodes.released_at)')
                ->whereColumn('seasons.catalog_title_id', 'catalog_titles.id')
                ->where('episodes.released_at', '>', now()), 'next_release_at')
            ->where(function (Builder $query): void {
                $query
                    ->where('catalog_titles.year', '>', now()->year)
                    ->orWhereExists(fn (QueryBuilder $query): QueryBuilder => $query
                        ->selectRaw('1')
                        ->from('episodes')
                        ->join('seasons', 'seasons.id', '=', 'episodes.season_id')
                        ->whereColumn('seasons.catalog_title_id', 'catalog_titles.id')
                        ->where('episodes.publication_status', 'published')
                        ->whereNull('episodes.deleted_at')
                        ->whereNull('seasons.deleted_at')
                        ->where('episodes.released_at', '>', now()));
            });

        if ($context->user !== null) {
            $query->selectSub(EpisodeViewProgress::query()
                ->selectRaw('COUNT(*)')
                ->where('user_id', $context->user->id)
                ->whereColumn('catalog_title_id', 'catalog_titles.id'), 'viewer_release_relevance');

            if (Schema::hasColumn('catalog_title_user_states', 'watch_status')) {
                $query->selectSub(CatalogTitleUserState::query()
                    ->selectRaw('COUNT(*) * 2')
                    ->where('user_id', $context->user->id)
                    ->whereColumn('catalog_title_id', 'catalog_titles.id')
                    ->whereIn('watch_status', ['watching', 'completed']), 'status_release_relevance')
                    ->orderByDesc('status_release_relevance');
            }

            $query->orderByDesc('viewer_release_relevance');
        }

        $query
            ->orderByRaw('CASE WHEN next_release_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('next_release_at')
            ->orderBy('catalog_titles.year')
            ->orderBy('catalog_titles.id');

        return $this->rows($query, CatalogRecommendationSource::ReleaseCalendar, CatalogRecommendationReason::Upcoming);
    }

    /**
     * @param  list<int>  $excludedIds
     * @return list<array{id: int, score: int, source: string, reason: string}>
     */
    private function editorial(CatalogRecommendationContext $context, array $excludedIds): array
    {
        $titleIds = DB::table('catalog_collection_items')
            ->join('catalog_collections', 'catalog_collections.id', '=', 'catalog_collection_items.catalog_collection_id')
            ->where('catalog_collections.type', CatalogCollectionType::Editorial->value)
            ->where('catalog_collections.visibility', CatalogCollectionVisibility::Public->value)
            ->where('catalog_collections.moderation_status', CatalogCollectionModerationStatus::Approved->value)
            ->where('catalog_collections.is_featured', true)
            ->whereNull('catalog_collections.deleted_at')
            ->whereNotNull('catalog_collections.published_at')
            ->orderByDesc('catalog_collections.published_at')
            ->orderBy('catalog_collection_items.position')
            ->orderBy('catalog_collection_items.id')
            ->limit($this->candidateLimit())
            ->pluck('catalog_collection_items.catalog_title_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $eligible = $this->eligibleQuery($context, watchable: true, excludedIds: $excludedIds)
            ->whereKey($titleIds)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->flip();

        return $titleIds
            ->filter(fn (int $id): bool => $eligible->has($id))
            ->values()
            ->map(fn (int $id, int $index): array => [
                'id' => $id,
                'score' => $this->candidateLimit() - $index,
                'source' => CatalogRecommendationSource::Editorial->value,
                'reason' => CatalogRecommendationReason::Editorial->value,
            ])
            ->all();
    }

    /**
     * @param  list<int>  $excludedIds
     * @return list<array{id: int, score: int, source: string, reason: string}>
     */
    private function random(CatalogRecommendationContext $context, array $excludedIds): array
    {
        $query = $this->eligibleQuery($context, watchable: true, excludedIds: $excludedIds)
            ->select('catalog_titles.id');
        $bounds = (clone $query)
            ->reorder()
            ->select([])
            ->selectRaw('MIN(catalog_titles.id) AS minimum_id, MAX(catalog_titles.id) AS maximum_id')
            ->toBase()
            ->first();
        $minimum = (int) ($bounds->minimum_id ?? 0);
        $maximum = (int) ($bounds->maximum_id ?? 0);

        if ($minimum < 1 || $maximum < $minimum) {
            return [];
        }

        $limit = min($this->candidateLimit(), max(1, $context->boundedPerPage() + 1));
        $probeSize = max(1, min(24, (int) config('recommendations.random.probe_size', 8)));
        $maximumProbes = max(1, min(24, (int) config('recommendations.random.maximum_probes', 12)));
        $seed = $context->seed ?? bin2hex(random_bytes(16));
        $ids = collect();

        for ($probe = 0; $probe < $maximumProbes && $ids->count() < $limit; $probe++) {
            $hash = hash('sha256', $seed.'|'.$probe);
            $fraction = hexdec(substr($hash, 0, 8)) / 0xFFFFFFFF;
            $pivot = $minimum + (int) floor(($maximum - $minimum) * $fraction);
            $probeIds = (clone $query)
                ->where('catalog_titles.id', '>=', $pivot)
                ->orderBy('catalog_titles.id')
                ->limit($probeSize)
                ->pluck('catalog_titles.id');

            if ($probeIds->isEmpty()) {
                $probeIds = (clone $query)
                    ->orderBy('catalog_titles.id')
                    ->limit($probeSize)
                    ->pluck('catalog_titles.id');
            }

            $ids = $ids->merge($probeIds)->unique()->take($limit);
        }

        return $ids
            ->values()
            ->map(fn (mixed $id, int $index): array => [
                'id' => (int) $id,
                'score' => $limit - $index,
                'source' => CatalogRecommendationSource::Random->value,
                'reason' => CatalogRecommendationReason::Random->value,
            ])
            ->all();
    }

    /**
     * @param  list<int>  $excludedIds
     * @return Builder<CatalogTitle>
     */
    private function eligibleQuery(CatalogRecommendationContext $context, bool $watchable, array $excludedIds): Builder
    {
        return $this->visibility->eligible($context, $watchable, $excludedIds);
    }

    /**
     * @param  Builder<CatalogTitle>  $query
     * @return list<array{id: int, score: int, source: string, reason: string}>
     */
    private function rows(
        Builder $query,
        CatalogRecommendationSource $source,
        CatalogRecommendationReason $reason,
    ): array {
        $limit = $this->candidateLimit();

        return $query
            ->limit($limit)
            ->get()
            ->pluck('id')
            ->map(fn (mixed $id, int $index): array => [
                'id' => (int) $id,
                'score' => $limit - $index,
                'source' => $source->value,
                'reason' => $reason->value,
            ])
            ->all();
    }

    private function candidateLimit(): int
    {
        return max(24, min(500, (int) config('recommendations.candidate_limit', 180)));
    }

    private function provider(string $source): string
    {
        return in_array($source, ['imdb', 'kinopoisk'], true) ? $source : 'kinopoisk';
    }
}
