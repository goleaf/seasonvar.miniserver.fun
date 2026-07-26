<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\CatalogRecommendationFeedback;
use App\Enums\CatalogRecommendationFeedbackReason;
use App\Enums\CatalogWatchStatus;
use App\Models\CatalogRecommendationFeedbackDetail;
use App\Models\CatalogTitleUserState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

final class CatalogPersonalNegativePreferenceBuilder
{
    /** @var list<string>|null */
    private ?array $stateColumns = null;

    public function __construct(
        private readonly CatalogRecommendationFeatureExtractor $features,
        private readonly CatalogRecommendationPreferenceQuery $preferences,
    ) {}

    /**
     * @param  list<int>  $positiveTitleIds
     * @return array<string, int>
     */
    public function forUser(User $user, array $positiveTitleIds = []): array
    {
        $columns = $this->stateColumns();
        $resetAt = $this->preferences->forUser($user)->profileResetAt;

        if (! in_array('recommendation_feedback', $columns, true)
            && ! in_array('watch_status', $columns, true)
            && ! Schema::hasTable('catalog_recommendation_feedback_details')) {
            return [];
        }

        $states = CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->where(function (Builder $query) use ($columns): void {
                if (in_array('recommendation_feedback', $columns, true)) {
                    $query->whereIn(
                        'recommendation_feedback',
                        CatalogRecommendationFeedback::negativeValues(),
                    );
                }

                if (in_array('watch_status', $columns, true)) {
                    $method = in_array('recommendation_feedback', $columns, true) ? 'orWhere' : 'where';
                    $query->{$method}('watch_status', CatalogWatchStatus::Dropped->value);
                }
            })
            ->latest('updated_at')
            ->limit(120)
            ->get(array_values(array_intersect([
                'id',
                'catalog_title_id',
                'recommendation_feedback',
                'recommendation_feedback_updated_at',
                'watch_status',
                'watch_status_updated_at',
            ], $columns)));

        if ($states->isEmpty()) {
            return [];
        }

        $featuresByTitle = $this->features->forTitleIds($states
            ->pluck('catalog_title_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
        $support = [];

        foreach ($states as $state) {
            $titleId = (int) $state->catalog_title_id;
            $activity = $this->activityForState($state);

            if ($resetAt !== null && ($activity === null || ! $activity->isAfter($resetAt))) {
                continue;
            }

            $factor = $this->recencyFactor($activity);

            foreach ($featuresByTitle[$titleId] ?? [] as $feature) {
                $support[$feature]['titles'][$titleId] = true;
                $support[$feature]['weight'] = ($support[$feature]['weight'] ?? 0.0) + $factor;
            }
        }

        $minimumSources = max(3, (int) config('recommendations.personalized_v2.negative_minimum_sources', 3));
        $featureCap = max(0, (int) config('recommendations.personalized_v2.negative_feature_cap', 120));
        $positiveFeatures = $this->features->forTitleIds($positiveTitleIds);
        $positiveCounts = [];

        foreach ($positiveFeatures as $features) {
            foreach ($features as $feature) {
                $positiveCounts[$feature] = ($positiveCounts[$feature] ?? 0) + 1;
            }
        }

        $demotions = $this->explicitDemotions($user, $resetAt);

        foreach ($support as $feature => $data) {
            if (count($data['titles']) < $minimumSources) {
                continue;
            }

            $demotion = min($featureCap, (int) round(30 * $data['weight']));
            $demotion = max(0, $demotion - (15 * (int) ($positiveCounts[$feature] ?? 0)));

            if ($demotion > 0) {
                $demotions[$feature] = min(
                    $featureCap,
                    ($demotions[$feature] ?? 0) + $demotion,
                );
            }
        }

        arsort($demotions, SORT_NUMERIC);
        $totalCap = max(0, (int) config('recommendations.personalized_v2.negative_total_cap', 240));
        $bounded = [];
        $total = 0;

        foreach ($demotions as $feature => $demotion) {
            $remaining = $totalCap - $total;

            if ($remaining <= 0) {
                break;
            }

            $bounded[$feature] = min($demotion, $remaining);
            $total += $bounded[$feature];
        }

        ksort($bounded);

        return $bounded;
    }

    /**
     * @return array<string, int>
     */
    private function explicitDemotions(User $user, ?CarbonImmutable $resetAt): array
    {
        if (! Schema::hasTable('catalog_recommendation_feedback_details')) {
            return [];
        }

        $details = CatalogRecommendationFeedbackDetail::query()
            ->whereBelongsTo($user)
            ->when($resetAt !== null, fn (Builder $query): Builder => $query->where('updated_at', '>', $resetAt))
            ->latest('updated_at')
            ->limit(120)
            ->get(['catalog_title_id', 'reason', 'genre_id', 'country_id', 'actor_id']);

        if ($details->isEmpty()) {
            return [];
        }

        $featuresByTitle = $this->features->forTitleIds($details
            ->pluck('catalog_title_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
        $weight = max(1, (int) config('recommendations.feedback.explicit_feature_demotion', 90));
        $featureCap = max($weight, (int) config('recommendations.personalized_v2.negative_feature_cap', 120));
        $demotions = [];

        foreach ($details as $detail) {
            $reason = $detail->reason;

            $titleFeatures = $featuresByTitle[(int) $detail->catalog_title_id] ?? [];
            $features = match ($reason) {
                CatalogRecommendationFeedbackReason::DislikeGenre => $this->subjectFeature('genre', $detail->genre_id),
                CatalogRecommendationFeedbackReason::DislikeCountry => $this->subjectFeature('country', $detail->country_id),
                CatalogRecommendationFeedbackReason::DislikeActor => $this->subjectFeature('actor', $detail->actor_id),
                CatalogRecommendationFeedbackReason::TooManyEpisodes => ['length:long'],
                CatalogRecommendationFeedbackReason::Unfinished => ['status:unfinished'],
                CatalogRecommendationFeedbackReason::TooOld => ['era:classic'],
                CatalogRecommendationFeedbackReason::LowRating => ['rating:low'],
                CatalogRecommendationFeedbackReason::WrongMood => array_values(array_filter(
                    $titleFeatures,
                    static fn (string $feature): bool => str_starts_with($feature, 'theme:'),
                )),
                CatalogRecommendationFeedbackReason::NotSimilar => array_values(array_filter(
                    $titleFeatures,
                    static fn (string $feature): bool => str_starts_with($feature, 'genre:')
                        || str_starts_with($feature, 'tag:')
                        || str_starts_with($feature, 'theme:'),
                )),
                CatalogRecommendationFeedbackReason::WatchedElsewhere,
                CatalogRecommendationFeedbackReason::NotThisTitle => [],
            };

            foreach (array_unique($features) as $feature) {
                $demotions[$feature] = min($featureCap, ($demotions[$feature] ?? 0) + $weight);
            }
        }

        return $demotions;
    }

    /** @return list<string> */
    private function subjectFeature(string $type, mixed $id): array
    {
        return is_int($id) && $id > 0 ? ["{$type}:{$id}"] : [];
    }

    private function activityForState(CatalogTitleUserState $state): ?CarbonImmutable
    {
        $activities = [];

        if ($this->recommendationFeedback($state)?->isNegative() === true) {
            $activities[] = $this->activity($state->getAttribute('recommendation_feedback_updated_at'));
        }

        $status = $state->getAttribute('watch_status');

        if ($status === CatalogWatchStatus::Dropped || $status === CatalogWatchStatus::Dropped->value) {
            $activities[] = $this->activity($state->getAttribute('watch_status_updated_at'));
        }

        $activities = array_values(array_filter($activities));
        usort($activities, static fn (CarbonImmutable $left, CarbonImmutable $right): int => $right <=> $left);

        return $activities[0] ?? null;
    }

    private function recommendationFeedback(CatalogTitleUserState $state): ?CatalogRecommendationFeedback
    {
        $feedback = $state->getAttribute('recommendation_feedback');

        return $feedback instanceof CatalogRecommendationFeedback
            ? $feedback
            : CatalogRecommendationFeedback::tryFrom((string) $feedback);
    }

    private function activity(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return $value->toImmutable();
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
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

    /** @return list<string> */
    private function stateColumns(): array
    {
        return $this->stateColumns ??= Schema::getColumnListing('catalog_title_user_states');
    }
}
