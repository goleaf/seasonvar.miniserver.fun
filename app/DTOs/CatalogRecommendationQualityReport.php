<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CatalogRecommendationQualityReport
{
    public function __construct(
        public float $precisionAtLimit,
        public float $ndcgAtLimit,
        public int $sourceCount,
        public int $emptySourceCount,
        public float $watchableRate,
        public int $candidateCoverage,
        public int $maximumIncoming,
        public int $incomingAtLeast100,
        public int $reasonFaithfulnessFailures,
        public int $judgedRowCount,
        public float $judgmentCoverage,
    ) {}

    /**
     * @return array{
     *     precision_at_limit: float,
     *     ndcg_at_limit: float,
     *     source_count: int,
     *     empty_source_count: int,
     *     watchable_rate: float,
     *     candidate_coverage: int,
     *     maximum_incoming: int,
     *     incoming_at_least_100: int,
     *     reason_faithfulness_failures: int,
     *     judged_row_count: int,
     *     judgment_coverage: float
     * }
     */
    public function toArray(): array
    {
        return [
            'precision_at_limit' => $this->precisionAtLimit,
            'ndcg_at_limit' => $this->ndcgAtLimit,
            'source_count' => $this->sourceCount,
            'empty_source_count' => $this->emptySourceCount,
            'watchable_rate' => $this->watchableRate,
            'candidate_coverage' => $this->candidateCoverage,
            'maximum_incoming' => $this->maximumIncoming,
            'incoming_at_least_100' => $this->incomingAtLeast100,
            'reason_faithfulness_failures' => $this->reasonFaithfulnessFailures,
            'judged_row_count' => $this->judgedRowCount,
            'judgment_coverage' => $this->judgmentCoverage,
        ];
    }
}
