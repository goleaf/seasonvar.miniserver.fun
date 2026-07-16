<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\CatalogPersonalPreferenceProfile;
use App\DTOs\CatalogPersonalSourceSignal;
use App\DTOs\CatalogRecommendationScoreRange;
use App\Enums\CatalogPersonalEvidence;
use App\Enums\CatalogRecommendationReason;
use App\Services\Catalog\CatalogPersonalizedCandidateScorer;
use Tests\TestCase;

final class CatalogPersonalizedCandidateScorerTest extends TestCase
{
    public function test_three_independent_medium_sources_beat_one_strong_source(): void
    {
        $profile = CatalogPersonalPreferenceProfile::fromSignals([
            $this->signal(1, 200, CatalogRecommendationReason::BecauseHistory),
            $this->signal(2, 200, CatalogRecommendationReason::BecauseWatchlist),
            $this->signal(3, 200, CatalogRecommendationReason::BecauseRating),
            $this->signal(4, 320, CatalogRecommendationReason::BecauseCollection),
        ]);
        $rows = [
            $this->row(1, 100, 900),
            $this->row(2, 100, 900),
            $this->row(3, 100, 900),
            $this->row(4, 200, 900),
        ];

        $candidates = app(CatalogPersonalizedCandidateScorer::class)->score(
            $profile,
            $rows,
            $this->range(),
        );

        $this->assertSame(100, $candidates[0]['id']);
        $this->assertGreaterThan($candidates[1]['score'], $candidates[0]['score']);
        $this->assertSame(3, $candidates[0]['support_count']);
        $this->assertCount(3, $candidates[0]['reasons']);
        $this->assertArrayNotHasKey('source_title_id', $candidates[0]);
    }

    public function test_rows_below_the_content_relevance_floor_are_rejected(): void
    {
        $profile = CatalogPersonalPreferenceProfile::fromSignals([
            $this->signal(1, 320, CatalogRecommendationReason::BecauseHistory),
        ]);

        $candidates = app(CatalogPersonalizedCandidateScorer::class)->score(
            $profile,
            [$this->row(1, 100, 799)],
            $this->range(),
        );

        $this->assertSame([], $candidates);
    }

    public function test_matching_negative_features_only_demote_and_stay_private(): void
    {
        $signal = $this->signal(1, 320, CatalogRecommendationReason::BecauseHistory);
        $plain = CatalogPersonalPreferenceProfile::fromSignals([$signal]);
        $negative = CatalogPersonalPreferenceProfile::fromSignals([$signal], ['genre:9' => 90]);
        $scorer = app(CatalogPersonalizedCandidateScorer::class);
        $rows = [$this->row(1, 100, 1_200)];
        $plainScore = $scorer->score($plain, $rows, $this->range(), [100 => ['genre:9']])[0];
        $negativeScore = $scorer->score($negative, $rows, $this->range(), [100 => ['genre:9']])[0];

        $this->assertSame(90, $plainScore['score'] - $negativeScore['score']);
        $this->assertArrayNotHasKey('feature_demotions', $negativeScore);
        $this->assertStringNotContainsString('genre:9', json_encode($negativeScore, JSON_THROW_ON_ERROR));
    }

    private function signal(
        int $titleId,
        int $confidence,
        CatalogRecommendationReason $reason,
    ): CatalogPersonalSourceSignal {
        return CatalogPersonalSourceSignal::make(
            titleId: $titleId,
            confidence: $confidence,
            evidence: [CatalogPersonalEvidence::MeaningfulProgress],
            reasonCodes: [$reason],
        );
    }

    /** @return array{catalog_title_id: int, recommended_title_id: int, score: int} */
    private function row(int $sourceId, int $candidateId, int $score): array
    {
        return [
            'catalog_title_id' => $sourceId,
            'recommended_title_id' => $candidateId,
            'score' => $score,
        ];
    }

    private function range(): CatalogRecommendationScoreRange
    {
        return new CatalogRecommendationScoreRange(
            minimum: 600,
            median: 1_000,
            p95: 1_600,
            algorithmVersion: 'v6',
            featureVersion: 'tokens-v2',
        );
    }
}
