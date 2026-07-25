<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CatalogRecommendationFeedback;
use PHPUnit\Framework\TestCase;

final class CatalogRecommendationFeedbackTest extends TestCase
{
    public function test_positive_feedback_is_supported_without_becoming_a_negative_signal(): void
    {
        $this->assertContains('more_like_this', CatalogRecommendationFeedback::values());
        $this->assertSame([
            CatalogRecommendationFeedback::NotInterested,
            CatalogRecommendationFeedback::Blacklisted,
        ], CatalogRecommendationFeedback::negativeCases());
        $this->assertSame([
            'not_interested',
            'blacklisted',
        ], CatalogRecommendationFeedback::negativeValues());
        $this->assertFalse(CatalogRecommendationFeedback::MoreLikeThis->isNegative());
        $this->assertTrue(CatalogRecommendationFeedback::NotInterested->isNegative());
        $this->assertTrue(CatalogRecommendationFeedback::Blacklisted->isNegative());
    }
}
