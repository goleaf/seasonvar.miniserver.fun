<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Catalog\CatalogRecommendationPairScorer;
use Tests\TestCase;

final class CatalogRecommendationPairScorerTest extends TestCase
{
    public function test_one_common_actor_in_large_cast_does_not_pass_the_relevance_gate(): void
    {
        $source = $this->profile(1, relations: [
            'genre' => [10],
            'actor' => range(1, 40),
        ]);
        $candidate = $this->profile(2, relations: [
            'genre' => [10],
            'actor' => [1, ...range(41, 79)],
        ]);

        $score = app(CatalogRecommendationPairScorer::class)->score(
            $source,
            $candidate,
            ['genre' => [10 => 500], 'actor' => [1 => 2]],
            1_000,
        );

        $this->assertNull($score);
    }

    public function test_two_specific_shared_actors_and_genre_pass_the_gate(): void
    {
        $source = $this->profile(1, relations: [
            'genre' => [10],
            'actor' => [1, 2, 3, 4, 5],
        ]);
        $candidate = $this->profile(2, relations: [
            'genre' => [10],
            'actor' => [1, 2, 6, 7, 8],
        ]);

        $score = app(CatalogRecommendationPairScorer::class)->score(
            $source,
            $candidate,
            ['genre' => [10 => 500], 'actor' => [1 => 10, 2 => 10]],
            1_000,
        );

        $this->assertNotNull($score);
        $this->assertGreaterThanOrEqual(600, $score->metadataScore + $score->sourceScore);
        $this->assertSame(2, $score->reasons['actor']['count']);
        $this->assertSame(0.4, $score->reasons['actor']['ratio']);
        $this->assertArrayHasKey('genre', $score->reasons);
    }

    public function test_exact_theme_and_country_beat_broad_genre_only(): void
    {
        $source = $this->profile(1, relations: ['genre' => [10], 'country' => [20]], themes: ['romance']);
        $topical = $this->profile(2, relations: ['genre' => [10], 'country' => [20]], themes: ['romance']);
        $broad = $this->profile(3, relations: ['genre' => [10]]);
        $frequencies = [
            'genre' => [10 => 500],
            'country' => [20 => 100],
            'theme' => ['romance' => 100],
        ];
        $scorer = app(CatalogRecommendationPairScorer::class);

        $topicalScore = $scorer->score($source, $topical, $frequencies, 1_000);
        $broadScore = $scorer->score($source, $broad, $frequencies, 1_000);

        $this->assertNotNull($topicalScore);
        $this->assertNull($broadScore);
        $this->assertArrayHasKey('theme_romance', $topicalScore->reasons);
        $this->assertArrayHasKey('country', $topicalScore->reasons);
    }

    public function test_quality_cannot_rescue_an_unrelated_candidate(): void
    {
        $source = $this->profile(1);
        $candidate = $this->profile(
            2,
            publishedMediaCount: 20,
            reviewsCount: 100,
            bestRating: 10.0,
        );

        $score = app(CatalogRecommendationPairScorer::class)->score($source, $candidate, [], 1_000);

        $this->assertNull($score);
    }

    public function test_verified_provider_relation_is_a_strong_explainable_source_match(): void
    {
        $source = $this->profile(1, providerTargets: [2 => 900]);
        $candidate = $this->profile(2);

        $score = app(CatalogRecommendationPairScorer::class)->score($source, $candidate, [], 1_000);

        $this->assertNotNull($score);
        $this->assertSame(650, $score->sourceScore);
        $this->assertSame(2, $score->reasons['source_signal']['target_id']);
        $this->assertGreaterThan($score->metadataScore, $score->sourceScore);
    }

    public function test_shared_editorial_collection_is_separate_capped_and_explainable(): void
    {
        config(['recommendations.similarity_v6.collection_signal_score_cap' => 220]);
        $signal = 'editorial_collection:award-winners';
        $source = $this->profile(1, signals: [$signal => 500]);
        $candidate = $this->profile(2, signals: [$signal => 500]);

        $score = app(CatalogRecommendationPairScorer::class)->score(
            $source,
            $candidate,
            ['editorial_collection' => [$signal => 2]],
            1_000,
            1,
        );

        $this->assertNotNull($score);
        $this->assertArrayHasKey('collection_signal', $score->reasons);
        $this->assertArrayNotHasKey('source_signal', $score->reasons);
        $this->assertLessThanOrEqual(220, $score->sourceScore);
        $this->assertSame($score->sourceScore, $score->reasons['collection_signal']['score']);
        $this->assertSame(1, $score->reasons['collection_signal']['count']);
        $this->assertContains($signal, $score->diversityFeatures);
    }

    public function test_broad_editorial_collection_scores_lower_than_a_rare_one(): void
    {
        $signal = 'editorial_collection:shared-source-key';
        $source = $this->profile(1, signals: [$signal => 280]);
        $candidate = $this->profile(2, signals: [$signal => 280]);
        $scorer = app(CatalogRecommendationPairScorer::class);

        $rare = $scorer->score(
            $source,
            $candidate,
            ['editorial_collection' => [$signal => 2]],
            1_000,
            1,
        );
        $broad = $scorer->score(
            $source,
            $candidate,
            ['editorial_collection' => [$signal => 900]],
            1_000,
            1,
        );

        $this->assertNotNull($rare);
        $this->assertNotNull($broad);
        $this->assertGreaterThan($broad->sourceScore, $rare->sourceScore);
    }

    /**
     * @param  array<string, list<int>>  $relations
     * @param  list<string>  $themes
     * @param  array<string, int>  $signals
     * @param  array<int, int>  $providerTargets
     * @return array{
     *     id: int,
     *     type: string,
     *     year: int|null,
     *     published_media_count: int,
     *     reviews_count: int,
     *     best_rating: float|null,
     *     signals: array<string, int>,
     *     provider_targets: array<int, int>,
     *     relations: array<string, list<int>>,
     *     themes: list<string>
     * }
     */
    private function profile(
        int $id,
        array $relations = [],
        array $themes = [],
        array $signals = [],
        array $providerTargets = [],
        int $publishedMediaCount = 1,
        int $reviewsCount = 0,
        ?float $bestRating = null,
    ): array {
        return [
            'id' => $id,
            'type' => 'serial',
            'year' => 2020,
            'published_media_count' => $publishedMediaCount,
            'reviews_count' => $reviewsCount,
            'best_rating' => $bestRating,
            'signals' => $signals,
            'provider_targets' => $providerTargets,
            'relations' => $relations,
            'themes' => $themes,
        ];
    }
}
