<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationContext;
use App\Enums\CatalogRecommendationType;
use App\Enums\CatalogWatchStatus;
use App\Models\CatalogTitleUserState;
use App\Models\EpisodeViewProgress;
use Illuminate\Support\Facades\Schema;

final class CatalogRecommendationExclusionService
{
    public function __construct(private readonly CatalogRecommendationRepeatSuppressor $repeats) {}

    /** @return list<int> */
    public function hardExclusions(CatalogRecommendationContext $context, bool $includeRecent = false): array
    {
        $ids = collect($context->excludedTitleIds)
            ->when($context->currentTitleId !== null, fn ($items) => $items->push($context->currentTitleId));
        $user = $context->user;

        if ($user !== null && Schema::hasColumn('catalog_title_user_states', 'recommendation_feedback')) {
            $ids = $ids->merge(CatalogTitleUserState::query()
                ->whereBelongsTo($user)
                ->whereNotNull('recommendation_feedback')
                ->limit(5_000)
                ->pluck('catalog_title_id'));
        }

        if ($includeRecent) {
            $ids = $ids->merge($this->repeats->recentIds($user));
        }

        return $this->normalize($ids->all());
    }

    /** @return list<int> */
    public function discoveryDemotions(CatalogRecommendationContext $context): array
    {
        if ($context->user === null || $context->type !== CatalogRecommendationType::Personalized) {
            return [];
        }

        $ids = collect();

        if (Schema::hasColumn('catalog_title_user_states', 'watch_status')) {
            $ids = $ids->merge(CatalogTitleUserState::query()
                ->whereBelongsTo($context->user)
                ->whereIn('watch_status', [
                    CatalogWatchStatus::Watching->value,
                    CatalogWatchStatus::Completed->value,
                    CatalogWatchStatus::Dropped->value,
                ])
                ->limit(2_000)
                ->pluck('catalog_title_id'));
        }

        $historyLimit = max(10, (int) config('recommendations.history_title_limit', 120));
        $progressRows = EpisodeViewProgress::query()
            ->whereBelongsTo($context->user)
            ->select(['catalog_title_id', 'completed_at', 'progress_percent', 'position_seconds', 'last_watched_at'])
            ->where(function ($query): void {
                $query
                    ->where('position_seconds', '>=', max(1, (int) config('recommendations.meaningful_progress_seconds', 180)))
                    ->orWhere('progress_percent', '>=', max(1, (int) config('recommendations.meaningful_progress_percent', 10)))
                    ->orWhereNotNull('completed_at');
            })
            ->latest('last_watched_at')
            ->limit($historyLimit * 8)
            ->get()
            ->groupBy('catalog_title_id')
            ->take($historyLimit);

        foreach ($progressRows as $titleId => $rows) {
            $allCompleted = $rows->isNotEmpty() && $rows->every(fn (EpisodeViewProgress $row): bool => $row->completed_at !== null);
            $hasActiveProgress = $rows->contains(fn (EpisodeViewProgress $row): bool => $row->completed_at === null);

            if ($allCompleted || $hasActiveProgress) {
                $ids->push((int) $titleId);
            }
        }

        return $this->normalize($ids->all());
    }

    /**
     * @param list<array{id: int, score: int, source: string, reason: string, relation_type?: string|null}> $candidates
     * @return list<array{id: int, score: int, source: string, reason: string, relation_type?: string|null}>
     */
    public function applySoftDemotions(CatalogRecommendationContext $context, array $candidates): array
    {
        if ($context->user === null
            || $candidates === []
            || in_array($context->type, [
                CatalogRecommendationType::Personalized,
                CatalogRecommendationType::Editorial,
                CatalogRecommendationType::Related,
                CatalogRecommendationType::Similar,
            ], true)) {
            return $candidates;
        }

        $candidateIds = array_column($candidates, 'id');
        $states = CatalogTitleUserState::query()
            ->whereBelongsTo($context->user)
            ->whereIn('catalog_title_id', $candidateIds)
            ->limit(500)
            ->get(['catalog_title_id', 'in_watchlist', ...(
                Schema::hasColumn('catalog_title_user_states', 'watch_status') ? ['watch_status'] : []
            )])
            ->keyBy('catalog_title_id');

        foreach ($candidates as &$candidate) {
            $state = $states->get($candidate['id']);

            if (! $state instanceof CatalogTitleUserState) {
                continue;
            }

            $demotion = $state->in_watchlist
                ? (int) config('recommendations.soft_demotions.watchlist', 40)
                : 0;
            $status = $state->watch_status;

            if ($status instanceof CatalogWatchStatus) {
                $demotion = max($demotion, (int) config('recommendations.soft_demotions.'.$status->value, 0));
            }

            $candidate['score'] -= max(0, $demotion);
        }
        unset($candidate);

        usort($candidates, fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: ($right['id'] <=> $left['id']));

        return $candidates;
    }

    /** @param iterable<int, mixed> $ids @return list<int> */
    private function normalize(iterable $ids): array
    {
        return collect($ids)
            ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
