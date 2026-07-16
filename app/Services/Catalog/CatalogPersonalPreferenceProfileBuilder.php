<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogPersonalPreferenceProfile;
use App\DTOs\CatalogPersonalSourceSignal;
use App\Enums\CatalogPersonalEvidence;
use App\Enums\CatalogRecommendationReason;
use App\Enums\CatalogWatchStatus;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\Season;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CatalogPersonalPreferenceProfileBuilder
{
    /** @var list<string>|null */
    private ?array $stateColumns = null;

    public function __construct(private readonly CatalogPersonalNegativePreferenceBuilder $negativePreferences) {}

    public function forUser(User $user): CatalogPersonalPreferenceProfile
    {
        $limit = max(10, min(500, (int) config('recommendations.personalized_v2.history_limit', 120)));
        $progress = $this->progressRows($user, $limit);
        $states = $this->stateRows($user, $limit);
        $collections = $this->collectionRows($user, $limit);
        $personalTags = $this->personalTagRows($user, $limit);
        $publishedEpisodes = $this->publishedEpisodeCounts(
            $user,
            $progress->pluck('catalog_title_id')->map(static fn (mixed $id): int => (int) $id)->all(),
        );
        $evidence = [];
        $negativeTitleIds = [];

        foreach ($progress as $row) {
            $titleId = (int) $row->catalog_title_id;
            $publishedCount = $publishedEpisodes[$titleId] ?? 0;
            $completedCount = (int) $row->completed_episodes;
            $completionDepth = $publishedCount > 0 ? $completedCount / $publishedCount : 0.0;
            $depth = max(0.0, min(1.0, max((float) $row->progress_depth, $completionDepth)));
            $activity = $this->activity($row->last_activity_at);
            $this->addEvidence(
                $evidence,
                $titleId,
                CatalogPersonalEvidence::MeaningfulProgress,
                CatalogRecommendationReason::BecauseHistory,
                60 + (int) round(100 * $depth),
                $activity,
            );

            if ($publishedCount > 0) {
                $required = min(3, max(1, (int) ceil($publishedCount * 0.5)));

                if ($completedCount >= $required) {
                    $this->addEvidence(
                        $evidence,
                        $titleId,
                        CatalogPersonalEvidence::CompletedDepth,
                        CatalogRecommendationReason::BecauseHistory,
                        140,
                        $activity,
                    );
                }
            }
        }

        foreach ($states as $state) {
            $titleId = (int) $state->catalog_title_id;
            $status = $this->watchStatus($state);

            if ($this->isNegativeState($state, $status)) {
                $negativeTitleIds[$titleId] = true;

                continue;
            }

            if ($state->in_watchlist) {
                $this->addEvidence(
                    $evidence,
                    $titleId,
                    CatalogPersonalEvidence::Watchlist,
                    CatalogRecommendationReason::BecauseWatchlist,
                    60,
                    $this->stateActivity($state, 'watchlist_updated_at'),
                );
            }

            $rating = (int) ($state->rating ?? 0);

            if ($rating >= 7) {
                $this->addEvidence(
                    $evidence,
                    $titleId,
                    CatalogPersonalEvidence::Rating,
                    CatalogRecommendationReason::BecauseRating,
                    ($rating - 6) * 35,
                    $this->stateActivity($state, 'rating_updated_at'),
                );
            }

            $statusEvidence = match ($status) {
                CatalogWatchStatus::Planned => [CatalogPersonalEvidence::PlannedStatus, 50],
                CatalogWatchStatus::Watching => [CatalogPersonalEvidence::WatchingStatus, 100],
                CatalogWatchStatus::Completed => [CatalogPersonalEvidence::CompletedStatus, 150],
                default => null,
            };

            if (is_array($statusEvidence)) {
                $this->addEvidence(
                    $evidence,
                    $titleId,
                    $statusEvidence[0],
                    CatalogRecommendationReason::BecauseStatus,
                    $statusEvidence[1],
                    $this->stateActivity($state, 'watch_status_updated_at'),
                );
            }
        }

        foreach ($collections as $row) {
            $this->addEvidence(
                $evidence,
                (int) $row->catalog_title_id,
                CatalogPersonalEvidence::Collection,
                CatalogRecommendationReason::BecauseCollection,
                45,
                $this->activity($row->last_activity_at),
            );
        }

        foreach ($personalTags as $row) {
            $this->addEvidence(
                $evidence,
                (int) $row->catalog_title_id,
                CatalogPersonalEvidence::PersonalTag,
                CatalogRecommendationReason::BecausePersonalTags,
                35,
                $this->activity($row->last_activity_at),
            );
        }

        $signals = [];

        foreach ($evidence as $titleId => $items) {
            if (isset($negativeTitleIds[$titleId])) {
                continue;
            }

            usort($items, fn (array $left, array $right): int => ($right['weighted'] <=> $left['weighted'])
                ?: ($left['evidence']->value <=> $right['evidence']->value));
            $confidence = 0.0;

            foreach ($items as $index => $item) {
                $confidence += $item['weighted'] * match ($index) {
                    0 => 1.0,
                    1 => 0.6,
                    default => 0.35,
                };
            }

            $activities = array_values(array_filter(array_column($items, 'activity')));
            usort($activities, static fn (CarbonImmutable $left, CarbonImmutable $right): int => $right <=> $left);
            $signals[] = CatalogPersonalSourceSignal::make(
                titleId: $titleId,
                confidence: $confidence,
                evidence: array_column($items, 'evidence'),
                reasonCodes: array_column($items, 'reason'),
                lastActivityAt: $activities[0] ?? null,
            );
        }

        $profile = CatalogPersonalPreferenceProfile::fromSignals($signals);

        return CatalogPersonalPreferenceProfile::fromSignals(
            $profile->signals,
            $this->negativePreferences->forUser($user, $profile->sourceTitleIds()),
        );
    }

    /** @return Collection<int, object> */
    private function progressRows(User $user, int $limit): Collection
    {
        $minimumSeconds = max(1, (int) config('recommendations.meaningful_progress_seconds', 180));
        $minimumPercent = max(1, (int) config('recommendations.meaningful_progress_percent', 10));

        return DB::table('episode_view_progress')
            ->where('user_id', $user->id)
            ->where(function ($query) use ($minimumSeconds, $minimumPercent): void {
                $query
                    ->where('position_seconds', '>=', $minimumSeconds)
                    ->orWhere('progress_percent', '>=', $minimumPercent)
                    ->orWhereNotNull('completed_at');
            })
            ->groupBy('catalog_title_id')
            ->selectRaw('catalog_title_id')
            ->selectRaw('COUNT(DISTINCT episode_id) AS started_episodes')
            ->selectRaw('COUNT(DISTINCT CASE WHEN completed_at IS NOT NULL THEN episode_id END) AS completed_episodes')
            ->selectRaw('MAX(CASE WHEN duration_seconds > 0 THEN CAST(position_seconds AS REAL) / duration_seconds ELSE COALESCE(progress_percent, 0) / 100.0 END) AS progress_depth')
            ->selectRaw('MAX(last_watched_at) AS last_activity_at')
            ->orderByDesc('last_activity_at')
            ->limit($limit)
            ->get();
    }

    /** @return EloquentCollection<int, CatalogTitleUserState> */
    private function stateRows(User $user, int $limit): EloquentCollection
    {
        $available = $this->stateColumns();
        $columns = array_values(array_intersect([
            'id',
            'catalog_title_id',
            'in_watchlist',
            'rating',
            'recommendation_feedback',
            'watch_status',
            'watchlist_updated_at',
            'rating_updated_at',
            'watch_status_updated_at',
        ], $available));

        return CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->latest('updated_at')
            ->limit(min(2_000, $limit * 4))
            ->get($columns);
    }

    /** @return Collection<int, object> */
    private function collectionRows(User $user, int $limit): Collection
    {
        return DB::table('catalog_collection_items')
            ->join('catalog_collections', 'catalog_collections.id', '=', 'catalog_collection_items.catalog_collection_id')
            ->where('catalog_collections.owner_id', $user->id)
            ->whereNull('catalog_collections.deleted_at')
            ->groupBy('catalog_collection_items.catalog_title_id')
            ->selectRaw('catalog_collection_items.catalog_title_id')
            ->selectRaw('MAX(catalog_collection_items.updated_at) AS last_activity_at')
            ->orderByDesc('last_activity_at')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, object> */
    private function personalTagRows(User $user, int $limit): Collection
    {
        return DB::table('catalog_title_user_tag')
            ->join('user_tags', 'user_tags.id', '=', 'catalog_title_user_tag.user_tag_id')
            ->where('user_tags.user_id', $user->id)
            ->whereNull('user_tags.deleted_at')
            ->groupBy('catalog_title_user_tag.catalog_title_id')
            ->selectRaw('catalog_title_user_tag.catalog_title_id')
            ->selectRaw('MAX(catalog_title_user_tag.updated_at) AS last_activity_at')
            ->orderByDesc('last_activity_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  list<int>  $titleIds
     * @return array<int, int>
     */
    private function publishedEpisodeCounts(User $user, array $titleIds): array
    {
        if ($titleIds === []) {
            return [];
        }

        return Episode::query()
            ->availableTo($user)
            ->join('seasons', 'seasons.id', '=', 'episodes.season_id')
            ->whereIn('seasons.catalog_title_id', $titleIds)
            ->whereIn('seasons.id', Season::query()->availableTo($user)->select('seasons.id'))
            ->groupBy('seasons.catalog_title_id')
            ->selectRaw('seasons.catalog_title_id, COUNT(DISTINCT episodes.id) AS aggregate')
            ->pluck('aggregate', 'seasons.catalog_title_id')
            ->mapWithKeys(static fn (mixed $count, mixed $titleId): array => [(int) $titleId => (int) $count])
            ->all();
    }

    /**
     * @param  array<int, list<array{evidence: CatalogPersonalEvidence, reason: CatalogRecommendationReason, weighted: float, activity: CarbonImmutable|null}>>  $items
     */
    private function addEvidence(
        array &$items,
        int $titleId,
        CatalogPersonalEvidence $evidence,
        CatalogRecommendationReason $reason,
        int $rawWeight,
        ?CarbonImmutable $activity,
    ): void {
        if ($titleId < 1 || $rawWeight < 1) {
            return;
        }

        $items[$titleId][] = [
            'evidence' => $evidence,
            'reason' => $reason,
            'weighted' => $rawWeight * $this->recencyFactor($activity),
            'activity' => $activity,
        ];
    }

    private function recencyFactor(?CarbonImmutable $activity): float
    {
        if ($activity === null) {
            return (float) config('recommendations.personalized_v2.legacy_recency_factor', 0.5);
        }

        $days = max(0, $activity->diffInDays(now(), absolute: true));
        $halfLife = max(1, (int) config('recommendations.personalized_v2.recency_half_life_days', 180));

        return max(0.2, min(1.0, 2 ** (-$days / $halfLife)));
    }

    private function activity(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return $value->toImmutable();
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    private function stateActivity(CatalogTitleUserState $state, string $column): ?CarbonImmutable
    {
        return in_array($column, $this->stateColumns(), true)
            ? $this->activity($state->getAttribute($column))
            : null;
    }

    private function watchStatus(CatalogTitleUserState $state): ?CatalogWatchStatus
    {
        if (! in_array('watch_status', $this->stateColumns(), true)) {
            return null;
        }

        $status = $state->getAttribute('watch_status');

        return $status instanceof CatalogWatchStatus
            ? $status
            : CatalogWatchStatus::tryFrom((string) $status);
    }

    private function isNegativeState(CatalogTitleUserState $state, ?CatalogWatchStatus $status): bool
    {
        $hasFeedback = in_array('recommendation_feedback', $this->stateColumns(), true)
            && $state->getAttribute('recommendation_feedback') !== null;

        return $hasFeedback || $status === CatalogWatchStatus::Dropped;
    }

    /** @return list<string> */
    private function stateColumns(): array
    {
        return $this->stateColumns ??= Schema::getColumnListing('catalog_title_user_states');
    }
}
