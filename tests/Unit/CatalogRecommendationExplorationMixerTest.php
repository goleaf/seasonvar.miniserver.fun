<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\CatalogRecommendationExplanation;
use App\Enums\CatalogRecommendationReason;
use App\Services\Catalog\CatalogRecommendationExplorationMixer;
use App\Services\Catalog\CatalogRecommendationPresenter;
use Tests\TestCase;

final class CatalogRecommendationExplorationMixerTest extends TestCase
{
    public function test_exploration_slots_are_bounded_by_ratio_and_relevance(): void
    {
        $mixer = app(CatalogRecommendationExplorationMixer::class);
        $exploit = $this->rows(range(1, 30));
        $explore = [
            ...$this->rows(range(101, 110), relevance: 0.8),
            ...$this->rows([999], relevance: 0.44),
        ];
        $twelve = $mixer->mix($exploit, $explore, 12, 'stable-seed');
        $twentyFour = $mixer->mix($exploit, $explore, 24, 'stable-seed');

        $this->assertCount(12, $twelve);
        $this->assertCount(1, $this->explorationRows($twelve));
        $this->assertCount(24, $twentyFour);
        $this->assertCount(3, $this->explorationRows($twentyFour));
        $this->assertNotContains(999, array_column($twentyFour, 'id'));
        $this->assertSame(
            'Новое для вас',
            app(CatalogRecommendationPresenter::class)->explanation(
                new CatalogRecommendationExplanation(CatalogRecommendationReason::NewForYou),
            ),
        );
    }

    public function test_config_cannot_raise_exploration_above_the_fifteen_percent_contract(): void
    {
        config(['recommendations.personalized_v2.exploration_ratio' => 0.9]);
        $mixer = app(CatalogRecommendationExplorationMixer::class);
        $result = $mixer->mix(
            $this->rows(range(1, 30)),
            $this->rows(range(101, 130), relevance: 0.9),
            24,
            'bounded-ratio',
        );

        $this->assertCount(24, $result);
        $this->assertCount(3, $this->explorationRows($result));
    }

    public function test_seed_is_stable_duplicates_are_not_reintroduced_and_empty_pool_is_a_noop(): void
    {
        $mixer = app(CatalogRecommendationExplorationMixer::class);
        $exploit = $this->rows(range(1, 12));
        $explore = [
            ...$this->rows([1], relevance: 0.9),
            ...$this->rows(range(101, 110), relevance: 0.9),
        ];
        $first = $mixer->mix($exploit, $explore, 12, 'same');
        $again = $mixer->mix($exploit, $explore, 12, 'same');
        $different = $mixer->mix($exploit, $explore, 12, 'different');

        $this->assertSame($first, $again);
        $this->assertNotSame(
            array_column($this->explorationRows($first), 'id'),
            array_column($this->explorationRows($different), 'id'),
        );
        $this->assertSame(array_values(array_unique(array_column($first, 'id'))), array_column($first, 'id'));
        $this->assertSame(array_slice($exploit, 0, 12), $mixer->mix($exploit, [], 12, 'same'));
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, score: int, source: string, reason: string, normalized_relevance: float}>
     */
    private function rows(array $ids, float $relevance = 1.0): array
    {
        return array_map(static fn (int $id): array => [
            'id' => $id,
            'score' => 10_000 - $id,
            'source' => 'content_similarity',
            'reason' => 'similar_genres',
            'normalized_relevance' => $relevance,
        ], $ids);
    }

    /** @param list<array<string, mixed>> $rows */
    private function explorationRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['reason'] ?? null) === CatalogRecommendationReason::NewForYou->value,
        ));
    }
}
