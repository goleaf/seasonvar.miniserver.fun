<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CatalogRecommendationFeedback;
use App\Enums\CatalogRecommendationFeedbackReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogRecommendationFeedbackReasonTest extends TestCase
{
    /** @return iterable<string, array{CatalogRecommendationFeedbackReason, string|null}> */
    public static function subjectReasons(): iterable
    {
        yield 'genre' => [CatalogRecommendationFeedbackReason::DislikeGenre, 'genre'];
        yield 'country' => [CatalogRecommendationFeedbackReason::DislikeCountry, 'country'];
        yield 'actor' => [CatalogRecommendationFeedbackReason::DislikeActor, 'actor'];
    }

    public function test_reason_values_are_stable_and_complete(): void
    {
        $this->assertSame([
            'watched_elsewhere',
            'dislike_genre',
            'dislike_country',
            'dislike_actor',
            'too_many_episodes',
            'unfinished',
            'too_old',
            'low_rating',
            'wrong_mood',
            'not_this_title',
            'not_similar',
        ], CatalogRecommendationFeedbackReason::values());
    }

    #[DataProvider('subjectReasons')]
    public function test_taxonomy_reasons_require_the_expected_server_verified_subject(
        CatalogRecommendationFeedbackReason $reason,
        string $subjectType,
    ): void {
        $this->assertTrue($reason->requiresSubject());
        $this->assertSame($subjectType, $reason->subjectType());
    }

    public function test_non_taxonomy_reasons_prohibit_a_subject(): void
    {
        foreach (CatalogRecommendationFeedbackReason::cases() as $reason) {
            if (in_array($reason, [
                CatalogRecommendationFeedbackReason::DislikeGenre,
                CatalogRecommendationFeedbackReason::DislikeCountry,
                CatalogRecommendationFeedbackReason::DislikeActor,
            ], true)) {
                continue;
            }

            $this->assertFalse($reason->requiresSubject());
            $this->assertNull($reason->subjectType());
        }
    }

    public function test_exact_title_reason_uses_the_existing_strong_exclusion_contract(): void
    {
        $this->assertSame(
            CatalogRecommendationFeedback::Blacklisted,
            CatalogRecommendationFeedbackReason::NotThisTitle->feedback(),
        );

        foreach (CatalogRecommendationFeedbackReason::cases() as $reason) {
            if ($reason === CatalogRecommendationFeedbackReason::NotThisTitle) {
                continue;
            }

            $this->assertSame(CatalogRecommendationFeedback::NotInterested, $reason->feedback());
        }
    }
}
