<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Catalog\CatalogRecommendationQualityEvaluator;
use Tests\TestCase;

final class CatalogRecommendationQualityEvaluatorTest extends TestCase
{
    public function test_it_calculates_relevance_coverage_availability_and_concentration(): void
    {
        $fixture = require base_path('tests/Fixtures/recommendations/quality-baseline.php');

        $report = app(CatalogRecommendationQualityEvaluator::class)->evaluate(
            $fixture['rows'],
            $fixture['grades'],
            $fixture['limit'],
        );

        $this->assertSame(0.75, $report->precisionAtLimit);
        $this->assertSame(1.0, $report->ndcgAtLimit);
        $this->assertSame(3, $report->sourceCount);
        $this->assertSame(1, $report->emptySourceCount);
        $this->assertSame(0.6667, $report->watchableRate);
        $this->assertSame(2, $report->candidateCoverage);
        $this->assertSame(2, $report->maximumIncoming);
        $this->assertSame(0, $report->incomingAtLeast100);
        $this->assertSame(1, $report->reasonFaithfulnessFailures);
        $this->assertSame(3, $report->judgedRowCount);
        $this->assertSame(1.0, $report->judgmentCoverage);
    }

    public function test_it_is_deterministic_and_bounds_invalid_rows_and_grades(): void
    {
        $rows = [
            ['source' => 'source-a', 'candidate' => 'candidate-a', 'rank' => 2, 'watchable' => 1, 'reasons' => ['genre']],
            ['source' => 'source-a', 'candidate' => 'candidate-b', 'rank' => 1, 'watchable' => true, 'reasons' => ['theme_romance']],
            ['source' => 'source-a', 'candidate' => 'candidate-b', 'rank' => 1, 'watchable' => false, 'reasons' => []],
            ['source' => '', 'candidate' => 'invalid', 'rank' => 1, 'watchable' => true, 'reasons' => ['genre']],
            ['source' => 'source-a', 'candidate' => 'candidate-c', 'rank' => 0, 'watchable' => true, 'reasons' => ['actor']],
        ];
        $grades = [
            'source-a' => ['candidate-a' => 9, 'candidate-b' => -4],
        ];

        $first = app(CatalogRecommendationQualityEvaluator::class)->evaluate($rows, $grades, 2);
        $second = app(CatalogRecommendationQualityEvaluator::class)->evaluate(array_reverse($rows), $grades, 2);

        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame(0.5, $first->precisionAtLimit);
        $this->assertSame(0.6309, $first->ndcgAtLimit);
        $this->assertSame(2, $first->candidateCoverage);
    }

    public function test_unjudged_candidates_do_not_count_as_irrelevant_or_distort_ndcg(): void
    {
        $rows = [
            ['source' => 'source-a', 'candidate' => 'unjudged', 'rank' => 1, 'watchable' => true, 'reasons' => ['genre']],
            ['source' => 'source-a', 'candidate' => 'relevant', 'rank' => 2, 'watchable' => true, 'reasons' => ['theme_romance']],
            ['source' => 'source-a', 'candidate' => 'irrelevant', 'rank' => 3, 'watchable' => true, 'reasons' => ['actor']],
        ];
        $grades = [
            'source-a' => ['relevant' => 2, 'irrelevant' => 0],
        ];

        $report = app(CatalogRecommendationQualityEvaluator::class)->evaluate($rows, $grades, 3);

        $this->assertSame(0.5, $report->precisionAtLimit);
        $this->assertSame(1.0, $report->ndcgAtLimit);
        $this->assertSame(2, $report->judgedRowCount);
        $this->assertSame(1.0, $report->judgmentCoverage);
    }

    public function test_judgment_coverage_measures_retrieved_positive_golden_pairs(): void
    {
        $rows = [
            ['source' => 'source-a', 'candidate' => 'relevant', 'rank' => 1, 'watchable' => true, 'reasons' => ['genre']],
            ['source' => 'source-a', 'candidate' => 'known-negative', 'rank' => 2, 'watchable' => true, 'reasons' => ['actor']],
            ['source' => 'source-a', 'candidate' => 'unjudged', 'rank' => 3, 'watchable' => true, 'reasons' => ['theme_romance']],
        ];
        $grades = [
            'source-a' => [
                'relevant' => 2,
                'known-negative' => 0,
                'missed-relevant' => 1,
            ],
        ];

        $report = app(CatalogRecommendationQualityEvaluator::class)->evaluate($rows, $grades, 3);

        $this->assertSame(2, $report->judgedRowCount);
        $this->assertSame(0.5, $report->judgmentCoverage);
    }
}
