<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogPersonalPreferenceProfile;
use App\DTOs\CatalogPersonalSourceSignal;
use App\DTOs\CatalogRecommendationScoreRange;
use App\Enums\CatalogRecommendationReason;
use App\Enums\CatalogRecommendationSource;

final class CatalogPersonalizedCandidateScorer
{
    public function __construct(private readonly CatalogRecommendationScoreNormalizer $normalizer) {}

    /**
     * @param  iterable<array<string, mixed>|object>  $similarityRows
     * @param  array<int, list<string>>  $candidateFeatures
     * @return list<array{id: int, score: int, source: string, reason: string, reasons: list<array{reason: string, parameters: array<string, scalar>}>, support_count: int, normalized_relevance: float}>
     */
    public function score(
        CatalogPersonalPreferenceProfile $profile,
        iterable $similarityRows,
        CatalogRecommendationScoreRange $range,
        array $candidateFeatures = [],
    ): array {
        $signals = collect($profile->signals)->keyBy('titleId');
        $sourceIds = array_fill_keys($profile->sourceTitleIds(), true);
        $candidates = [];

        foreach ($similarityRows as $row) {
            $sourceId = (int) $this->value($row, 'catalog_title_id');
            $candidateId = (int) $this->value($row, 'recommended_title_id');
            $signal = $signals->get($sourceId);

            if (! $signal instanceof CatalogPersonalSourceSignal
                || $candidateId < 1
                || isset($sourceIds[$candidateId])) {
                continue;
            }

            $relevance = $this->normalizer->normalize((int) $this->value($row, 'score'), $range);

            if ($relevance < 0.2) {
                continue;
            }

            $contribution = (int) round($signal->confidence * $relevance);

            if ($contribution < 1) {
                continue;
            }

            $reason = $signal->reasonCodes[0] ?? CatalogRecommendationReason::BecauseHistory;
            $candidate = $candidates[$candidateId] ?? [
                'id' => $candidateId,
                'contributions' => [],
                'reason_weights' => [],
                'strongest_contribution' => 0,
                'strongest_reason' => $reason,
                'normalized_relevance' => 0.0,
            ];
            $candidate['contributions'][] = $contribution;
            $candidate['normalized_relevance'] = max($candidate['normalized_relevance'], $relevance);

            foreach ($signal->reasonCodes as $reasonCode) {
                $candidate['reason_weights'][$reasonCode->value] =
                    ($candidate['reason_weights'][$reasonCode->value] ?? 0) + $contribution;
            }

            if ($contribution > $candidate['strongest_contribution']) {
                $candidate['strongest_contribution'] = $contribution;
                $candidate['strongest_reason'] = $reason;
            }

            $candidates[$candidateId] = $candidate;
        }

        $rows = [];

        foreach ($candidates as $candidate) {
            rsort($candidate['contributions'], SORT_NUMERIC);
            $personalSupport = 0.0;

            foreach ($candidate['contributions'] as $index => $contribution) {
                $personalSupport += $contribution * match ($index) {
                    0 => 1.0,
                    1 => 0.65,
                    default => 0.4,
                };
            }

            arsort($candidate['reason_weights'], SORT_NUMERIC);
            $reasonCodes = array_slice(array_keys($candidate['reason_weights']), 0, 3);
            $primary = $candidate['strongest_reason'];
            $featureDemotion = collect($candidateFeatures[$candidate['id']] ?? [])
                ->sum(fn (string $feature): int => (int) ($profile->featureDemotions[$feature] ?? 0));
            $featureDemotion = min(
                max(0, (int) config('recommendations.personalized_v2.negative_total_cap', 240)),
                $featureDemotion,
            );
            $rows[] = [
                'id' => $candidate['id'],
                'score' => max(
                    0,
                    min(1_000, (int) round($personalSupport))
                        + (int) round($candidate['normalized_relevance'] * 500)
                        - $featureDemotion,
                ),
                'source' => $this->sourceForReason($primary)->value,
                'reason' => $primary->value,
                'reasons' => array_map(
                    static fn (string $reason): array => ['reason' => $reason, 'parameters' => []],
                    $reasonCodes,
                ),
                'support_count' => count($candidate['contributions']),
                'normalized_relevance' => round($candidate['normalized_relevance'], 6),
            ];
        }

        usort($rows, static fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: ($right['id'] <=> $left['id']));

        return $rows;
    }

    private function value(array|object $row, string $key): mixed
    {
        return is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);
    }

    private function sourceForReason(CatalogRecommendationReason $reason): CatalogRecommendationSource
    {
        return match ($reason) {
            CatalogRecommendationReason::BecauseWatchlist => CatalogRecommendationSource::UserWatchlist,
            CatalogRecommendationReason::BecauseStatus => CatalogRecommendationSource::UserStatuses,
            CatalogRecommendationReason::BecauseCollection => CatalogRecommendationSource::UserCollections,
            CatalogRecommendationReason::BecausePersonalTags => CatalogRecommendationSource::UserTags,
            CatalogRecommendationReason::BecauseRating => CatalogRecommendationSource::UserRatings,
            default => CatalogRecommendationSource::UserHistory,
        };
    }
}
