<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogPersonalizedCandidateSet;
use App\DTOs\CatalogRecommendationContext;
use App\Enums\CatalogPersonalizationConfidence;
use App\Enums\CatalogRecommendationReason;
use App\Enums\CatalogRecommendationSource;
use App\Enums\CatalogWatchStatus;
use App\Models\CatalogTitleRecommendation;
use App\Models\CatalogTitleUserState;
use App\Models\EpisodeViewProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CatalogPersonalizedRecommendationQuery
{
    public function __construct(
        private readonly CatalogRecommendationVisibilityService $visibility,
        private readonly CatalogPersonalPreferenceProfileBuilder $profiles,
        private readonly CatalogRecommendationScoreNormalizer $normalizer,
        private readonly CatalogPersonalizedCandidateScorer $scorer,
        private readonly CatalogRecommendationFeatureExtractor $features,
    ) {}

    /** @param list<int> $excludedIds */
    public function candidateSet(
        CatalogRecommendationContext $context,
        array $excludedIds,
    ): CatalogPersonalizedCandidateSet {
        $user = $context->user;

        if (! $user instanceof User) {
            return CatalogPersonalizedCandidateSet::cold();
        }

        $profile = $this->profiles->forUser($user);

        if ($profile->confidence === CatalogPersonalizationConfidence::Cold) {
            return CatalogPersonalizedCandidateSet::cold();
        }

        $range = $this->normalizer->forActiveBuild();

        if ($range === null) {
            return CatalogPersonalizedCandidateSet::cold();
        }

        $sourceIds = $profile->sourceTitleIds();
        $recommendations = CatalogTitleRecommendation::query()
            ->whereIn('catalog_title_id', $sourceIds)
            ->where('algorithm_version', $range->algorithmVersion)
            ->where('rank', '<=', 24)
            ->orderBy('catalog_title_id')
            ->orderBy('rank')
            ->limit(min(4_000, max(240, count($sourceIds) * 24)))
            ->get(['catalog_title_id', 'recommended_title_id', 'score', 'rank']);
        $candidateFeatures = $profile->featureDemotions === []
            ? []
            : $this->features->forTitleIds($recommendations
                ->pluck('recommended_title_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all());
        $candidates = $this->scorer->score($profile, $recommendations, $range, $candidateFeatures);

        if ($candidates !== []) {
            $allExcluded = collect([...$excludedIds, ...$sourceIds])->unique()->values()->all();
            $eligibleIds = $this->visibility
                ->eligible($context, watchable: true, excludedIds: $allExcluded)
                ->whereKey(array_column($candidates, 'id'))
                ->pluck('catalog_titles.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->flip();
            $candidates = collect($candidates)
                ->filter(static fn (array $candidate): bool => $eligibleIds->has($candidate['id']))
                ->take(max(24, min(500, (int) config('recommendations.candidate_limit', 180))))
                ->values()
                ->all();
        }

        return new CatalogPersonalizedCandidateSet(
            candidates: $candidates,
            confidence: $profile->confidence,
            sourceTitleIds: $sourceIds,
        );
    }

    /**
     * @param  list<int>  $excludedIds
     * @return list<array{id: int, score: int, source: string, reason: string}>
     */
    public function candidates(CatalogRecommendationContext $context, array $excludedIds): array
    {
        $user = $context->user;

        if (! $user instanceof User) {
            return [];
        }

        $signals = $this->signals($user);

        if ($signals === []) {
            return [];
        }

        $sourceIds = array_keys($signals);
        $recommendations = CatalogTitleRecommendation::query()
            ->whereIn('catalog_title_id', $sourceIds)
            ->where('rank', '<=', 24)
            ->orderBy('catalog_title_id')
            ->orderBy('rank')
            ->limit(min(4_000, max(240, count($sourceIds) * 24)))
            ->get(['catalog_title_id', 'recommended_title_id', 'score', 'rank']);
        $candidates = [];

        foreach ($recommendations as $recommendation) {
            $signal = $signals[(int) $recommendation->catalog_title_id] ?? null;

            if ($signal === null) {
                continue;
            }

            $candidateId = (int) $recommendation->recommended_title_id;
            $contribution = $signal['weight'] + min(500, (int) round(((int) $recommendation->score) / 10));
            $current = $candidates[$candidateId] ?? [
                'id' => $candidateId,
                'score' => 0,
                'source' => $signal['source']->value,
                'reason' => $signal['reason']->value,
                'strongest' => 0,
            ];
            $current['score'] += $contribution;

            if ($contribution > $current['strongest']) {
                $current['source'] = $signal['source']->value;
                $current['reason'] = $signal['reason']->value;
                $current['strongest'] = $contribution;
            }

            $candidates[$candidateId] = $current;
        }

        if ($candidates === []) {
            return [];
        }

        $excludedIds = collect([...$excludedIds, ...$sourceIds])->unique()->values()->all();
        $eligibleIds = $this->visibility
            ->eligible($context, watchable: true, excludedIds: $excludedIds)
            ->whereKey(array_keys($candidates))
            ->pluck('catalog_titles.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->flip();

        return collect($candidates)
            ->filter(fn (array $candidate): bool => $eligibleIds->has($candidate['id']))
            ->sort(function (array $left, array $right): int {
                $score = $right['score'] <=> $left['score'];

                return $score !== 0 ? $score : $right['id'] <=> $left['id'];
            })
            ->take(max(24, min(500, (int) config('recommendations.candidate_limit', 180))))
            ->map(fn (array $candidate): array => [
                'id' => $candidate['id'],
                'score' => $candidate['score'],
                'source' => $candidate['source'],
                'reason' => $candidate['reason'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{weight: int, source: CatalogRecommendationSource, reason: CatalogRecommendationReason}>
     */
    private function signals(User $user): array
    {
        $signals = [];
        $historyLimit = max(10, min(500, (int) config('recommendations.history_title_limit', 120)));
        $progress = EpisodeViewProgress::query()
            ->whereBelongsTo($user)
            ->where(function ($query): void {
                $query
                    ->where('position_seconds', '>=', max(1, (int) config('recommendations.meaningful_progress_seconds', 180)))
                    ->orWhere('progress_percent', '>=', max(1, (int) config('recommendations.meaningful_progress_percent', 10)))
                    ->orWhereNotNull('completed_at');
            })
            ->select(['catalog_title_id', 'completed_at', 'last_watched_at'])
            ->latest('last_watched_at')
            ->limit($historyLimit * 8)
            ->get()
            ->groupBy('catalog_title_id')
            ->take($historyLimit);

        foreach ($progress as $titleId => $rows) {
            $completed = $rows->contains(fn (EpisodeViewProgress $row): bool => $row->completed_at !== null);
            $this->rememberSignal($signals, (int) $titleId, [
                'weight' => (int) config($completed
                    ? 'recommendations.personalized.completed_weight'
                    : 'recommendations.personalized.history_weight', $completed ? 160 : 120),
                'source' => CatalogRecommendationSource::UserHistory,
                'reason' => CatalogRecommendationReason::BecauseHistory,
            ]);
        }

        $watchlistTitleIds = $this->orderBySignalActivity(CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->where('in_watchlist', true), 'watchlist_updated_at')
            ->limit(80)
            ->pluck('catalog_title_id');

        foreach ($watchlistTitleIds as $titleId) {
            $this->rememberSignal($signals, (int) $titleId, [
                'weight' => (int) config('recommendations.personalized.watchlist_weight', 140),
                'source' => CatalogRecommendationSource::UserWatchlist,
                'reason' => CatalogRecommendationReason::BecauseWatchlist,
            ]);
        }

        if (Schema::hasColumn('catalog_title_user_states', 'watch_status')) {
            $statusTitleIds = $this->orderBySignalActivity(CatalogTitleUserState::query()
                ->whereBelongsTo($user)
                ->whereIn('watch_status', [CatalogWatchStatus::Planned->value, CatalogWatchStatus::Watching->value]), 'watch_status_updated_at')
                ->limit(80)
                ->pluck('catalog_title_id');

            foreach ($statusTitleIds as $titleId) {
                $this->rememberSignal($signals, (int) $titleId, [
                    'weight' => (int) config('recommendations.personalized.status_weight', 135),
                    'source' => CatalogRecommendationSource::UserStatuses,
                    'reason' => CatalogRecommendationReason::BecauseStatus,
                ]);
            }
        }

        $ratingThreshold = max(1, (int) ceil((int) config('catalog.user_rating.maximum', 10) * 0.7));
        $ratedTitleIds = $this->orderBySignalActivity(CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->where('rating', '>=', $ratingThreshold), 'rating_updated_at')
            ->limit(80)
            ->pluck('catalog_title_id');

        foreach ($ratedTitleIds as $titleId) {
            $this->rememberSignal($signals, (int) $titleId, [
                'weight' => (int) config('recommendations.personalized.rating_weight', 130),
                'source' => CatalogRecommendationSource::UserRatings,
                'reason' => CatalogRecommendationReason::BecauseRating,
            ]);
        }

        $collectionTitleIds = DB::table('catalog_collection_items')
            ->join('catalog_collections', 'catalog_collections.id', '=', 'catalog_collection_items.catalog_collection_id')
            ->where('catalog_collections.owner_id', $user->id)
            ->whereNull('catalog_collections.deleted_at')
            ->latest('catalog_collection_items.updated_at')
            ->limit(120)
            ->pluck('catalog_collection_items.catalog_title_id');

        foreach ($collectionTitleIds as $titleId) {
            $this->rememberSignal($signals, (int) $titleId, [
                'weight' => (int) config('recommendations.personalized.collection_weight', 110),
                'source' => CatalogRecommendationSource::UserCollections,
                'reason' => CatalogRecommendationReason::BecauseCollection,
            ]);
        }

        $personalTagTitleIds = DB::table('catalog_title_user_tag')
            ->join('user_tags', 'user_tags.id', '=', 'catalog_title_user_tag.user_tag_id')
            ->where('user_tags.user_id', $user->id)
            ->whereNull('user_tags.deleted_at')
            ->latest('catalog_title_user_tag.updated_at')
            ->limit(120)
            ->pluck('catalog_title_user_tag.catalog_title_id');

        foreach ($personalTagTitleIds as $titleId) {
            $this->rememberSignal($signals, (int) $titleId, [
                'weight' => (int) config('recommendations.personalized.personal_tag_weight', 100),
                'source' => CatalogRecommendationSource::UserTags,
                'reason' => CatalogRecommendationReason::BecausePersonalTags,
            ]);
        }

        return array_slice($this->withoutNegativeSignals($signals, $user), 0, $historyLimit, true);
    }

    /**
     * @param  array<int, array{weight: int, source: CatalogRecommendationSource, reason: CatalogRecommendationReason}>  $signals
     * @return array<int, array{weight: int, source: CatalogRecommendationSource, reason: CatalogRecommendationReason}>
     */
    private function withoutNegativeSignals(array $signals, User $user): array
    {
        if ($signals === []) {
            return [];
        }

        $feedbackAvailable = Schema::hasColumn('catalog_title_user_states', 'recommendation_feedback');
        $statusAvailable = Schema::hasColumn('catalog_title_user_states', 'watch_status');

        if (! $feedbackAvailable && ! $statusAvailable) {
            return $signals;
        }

        $negativeStates = CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->whereIn('catalog_title_id', array_keys($signals));

        if ($feedbackAvailable && $statusAvailable) {
            $negativeStates->where(function (Builder $query): void {
                $query
                    ->whereNotNull('recommendation_feedback')
                    ->orWhere('watch_status', CatalogWatchStatus::Dropped->value);
            });
        } elseif ($feedbackAvailable) {
            $negativeStates->whereNotNull('recommendation_feedback');
        } else {
            $negativeStates->where('watch_status', CatalogWatchStatus::Dropped->value);
        }

        $negativeIds = $negativeStates
            ->pluck('catalog_title_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_diff_key($signals, array_fill_keys($negativeIds, true));
    }

    /**
     * @param  Builder<CatalogTitleUserState>  $query
     * @return Builder<CatalogTitleUserState>
     */
    private function orderBySignalActivity(Builder $query, string $column): Builder
    {
        return $query
            ->when(
                Schema::hasColumn('catalog_title_user_states', $column),
                fn (Builder $query): Builder => $query->orderByDesc($column),
            )
            ->orderByDesc('id');
    }

    /**
     * @param  array<int, array{weight: int, source: CatalogRecommendationSource, reason: CatalogRecommendationReason}>  $signals
     * @param  array{weight: int, source: CatalogRecommendationSource, reason: CatalogRecommendationReason}  $candidate
     */
    private function rememberSignal(array &$signals, int $titleId, array $candidate): void
    {
        if ($titleId < 1) {
            return;
        }

        $current = $signals[$titleId] ?? null;

        if ($current === null || $candidate['weight'] > $current['weight']) {
            $signals[$titleId] = $candidate;
        }
    }
}
