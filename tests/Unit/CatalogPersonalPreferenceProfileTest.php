<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\CatalogPersonalPreferenceProfile;
use App\DTOs\CatalogPersonalSourceSignal;
use App\Enums\CatalogPersonalEvidence;
use App\Enums\CatalogPersonalizationConfidence;
use App\Enums\CatalogRecommendationReason;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class CatalogPersonalPreferenceProfileTest extends TestCase
{
    public function test_confidence_uses_bounded_source_count_and_total_thresholds(): void
    {
        $this->assertSame(
            CatalogPersonalizationConfidence::Cold,
            CatalogPersonalPreferenceProfile::fromSignals([])->confidence,
        );
        $this->assertSame(
            CatalogPersonalizationConfidence::Low,
            CatalogPersonalPreferenceProfile::fromSignals([$this->signal(1, 239)])->confidence,
        );
        $this->assertSame(
            CatalogPersonalizationConfidence::Medium,
            CatalogPersonalPreferenceProfile::fromSignals([
                $this->signal(1, 120),
                $this->signal(2, 120),
            ])->confidence,
        );
        $this->assertSame(
            CatalogPersonalizationConfidence::High,
            CatalogPersonalPreferenceProfile::fromSignals([
                $this->signal(1, 220),
                $this->signal(2, 200),
                $this->signal(3, 180),
            ])->confidence,
        );
    }

    public function test_source_signal_clamps_confidence_and_deduplicates_private_codes(): void
    {
        config(['recommendations.personalized_v2.source_confidence_cap' => 320]);
        $activity = CarbonImmutable::parse('2026-07-15 12:00:00');
        $signal = CatalogPersonalSourceSignal::make(
            titleId: 42,
            confidence: 999,
            evidence: [
                CatalogPersonalEvidence::MeaningfulProgress,
                CatalogPersonalEvidence::MeaningfulProgress,
                CatalogPersonalEvidence::Rating,
            ],
            reasonCodes: [
                CatalogRecommendationReason::BecauseHistory,
                CatalogRecommendationReason::BecauseHistory,
                CatalogRecommendationReason::BecauseRating,
            ],
            lastActivityAt: $activity,
        );

        $this->assertSame(320, $signal->confidence);
        $this->assertSame([
            CatalogPersonalEvidence::MeaningfulProgress,
            CatalogPersonalEvidence::Rating,
        ], $signal->evidence);
        $this->assertSame([
            CatalogRecommendationReason::BecauseHistory,
            CatalogRecommendationReason::BecauseRating,
        ], $signal->reasonCodes);
        $this->assertTrue($activity->equalTo($signal->lastActivityAt));
    }

    public function test_profile_sorts_and_limits_sources_without_storing_user_identity(): void
    {
        config(['recommendations.personalized_v2.history_limit' => 2]);
        $profile = CatalogPersonalPreferenceProfile::fromSignals([
            $this->signal(30, 100),
            $this->signal(20, 200),
            $this->signal(10, 200),
        ]);

        $this->assertSame([10, 20], array_map(
            static fn (CatalogPersonalSourceSignal $signal): int => $signal->titleId,
            $profile->signals,
        ));
        $this->assertSame(400, $profile->totalConfidence);
        $this->assertObjectNotHasProperty('user', $profile);
        $this->assertObjectNotHasProperty('userId', $profile);
    }

    private function signal(int $titleId, int $confidence): CatalogPersonalSourceSignal
    {
        return CatalogPersonalSourceSignal::make(
            titleId: $titleId,
            confidence: $confidence,
            evidence: [CatalogPersonalEvidence::MeaningfulProgress],
            reasonCodes: [CatalogRecommendationReason::BecauseHistory],
        );
    }
}
