<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\CatalogRecommendationScoreRange;
use App\Models\CatalogRecommendationBuild;
use App\Services\Catalog\CatalogRecommendationScoreNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogRecommendationScoreNormalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_minimum_maps_to_zero_and_p95_caps_at_one(): void
    {
        $range = new CatalogRecommendationScoreRange(
            minimum: 600,
            median: 1_000,
            p95: 1_600,
            algorithmVersion: 'v6',
            featureVersion: 'tokens-v2',
        );
        $normalizer = app(CatalogRecommendationScoreNormalizer::class);

        $this->assertSame(0.0, $normalizer->normalize(600, $range));
        $this->assertSame(1.0, $normalizer->normalize(1_600, $range));
        $this->assertSame(1.0, $normalizer->normalize(5_000, $range));
        $this->assertGreaterThan(0.0, $normalizer->normalize(1_000, $range));
        $this->assertLessThan(1.0, $normalizer->normalize(1_000, $range));
    }

    public function test_only_a_compatible_active_build_exposes_a_range(): void
    {
        $normalizer = app(CatalogRecommendationScoreNormalizer::class);
        $this->assertNull($normalizer->forActiveBuild());

        CatalogRecommendationBuild::query()->create([
            'algorithm_version' => 'v5',
            'feature_version' => 'tokens-v2',
            'status' => 'active',
            'metrics' => ['score_min' => 600, 'score_median' => 1_000, 'score_p95' => 1_600],
            'started_at' => now(),
            'activated_at' => now(),
        ]);
        $this->assertNull($normalizer->forActiveBuild());

        CatalogRecommendationBuild::query()->where('status', 'active')->update(['status' => 'evaluated']);
        CatalogRecommendationBuild::query()->create([
            'algorithm_version' => 'v6',
            'feature_version' => 'tokens-v2',
            'status' => 'active',
            'metrics' => ['score_min' => 600, 'score_median' => 1_000, 'score_p95' => 1_600],
            'started_at' => now(),
            'activated_at' => now(),
        ]);

        $range = $normalizer->forActiveBuild();

        $this->assertInstanceOf(CatalogRecommendationScoreRange::class, $range);
        $this->assertSame(600, $range->minimum);
        $this->assertSame(1_600, $range->p95);
    }

    public function test_invalid_or_missing_score_metrics_disable_personalization(): void
    {
        CatalogRecommendationBuild::query()->create([
            'algorithm_version' => 'v6',
            'feature_version' => 'tokens-v2',
            'status' => 'active',
            'metrics' => ['score_min' => 800, 'score_median' => 800, 'score_p95' => 800],
            'started_at' => now(),
            'activated_at' => now(),
        ]);

        $this->assertNull(app(CatalogRecommendationScoreNormalizer::class)->forActiveBuild());
    }
}
