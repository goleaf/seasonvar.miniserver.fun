<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Catalog\CatalogRecommendationCandidateGenerator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CatalogRecommendationCandidateGeneratorTest extends TestCase
{
    #[Test]
    public function rare_composite_candidates_precede_broad_genre_candidates(): void
    {
        $generator = app(CatalogRecommendationCandidateGenerator::class);
        $source = $this->profile(
            1,
            relations: ['genre' => [10], 'country' => [20]],
            themes: ['romance'],
        );

        $generator->add($source);
        $generator->add($this->profile(
            9,
            relations: ['genre' => [10], 'country' => [20]],
            themes: ['romance'],
        ));

        foreach (range(2, 8) as $id) {
            $generator->add($this->profile($id, relations: ['genre' => [10]]));
        }

        $ids = $generator->idsFor($source, 4);

        $this->assertSame(9, $ids[0]);
        $this->assertCount(4, $ids);
    }

    #[Test]
    public function directed_provider_target_is_reachable_without_shared_metadata(): void
    {
        $generator = app(CatalogRecommendationCandidateGenerator::class);
        $source = $this->profile(1, providerTargets: [99 => 900]);

        $generator->add($source);
        $generator->add($this->profile(2, relations: ['genre' => [10]]));
        $generator->add($this->profile(99));

        $this->assertSame([99], $generator->idsFor($source, 5));
    }

    #[Test]
    public function shared_editorial_collection_is_reachable_without_shared_metadata(): void
    {
        $generator = app(CatalogRecommendationCandidateGenerator::class);
        $source = $this->profile(1, signals: [
            'editorial_collection:award-winners' => 280,
        ]);

        $generator->add($source);
        $generator->add($this->profile(2, signals: [
            'editorial_collection:award-winners' => 280,
        ]));
        $generator->add($this->profile(3, signals: [
            'related_title:award-winners' => 900,
        ]));

        $this->assertSame([2], $generator->idsFor($source, 5));
    }

    #[Test]
    public function result_is_unique_stable_bounded_and_excludes_the_source(): void
    {
        $generator = app(CatalogRecommendationCandidateGenerator::class);
        $source = $this->profile(
            5,
            relations: ['genre' => [10], 'tag' => [30], 'country' => [20]],
            themes: ['family'],
        );
        $profiles = [
            $source,
            $this->profile(2, relations: ['genre' => [10], 'tag' => [30]]),
            $this->profile(3, relations: ['genre' => [10]], themes: ['family']),
            $this->profile(4, relations: ['genre' => [10], 'country' => [20]], themes: ['family']),
            $this->profile(6, relations: ['tag' => [30]]),
        ];

        foreach ($profiles as $profile) {
            $generator->add($profile);
        }

        $first = $generator->idsFor($source, 3);
        $second = $generator->idsFor($source, 3);

        $this->assertSame($first, $second);
        $this->assertCount(3, $first);
        $this->assertCount(3, array_unique($first));
        $this->assertNotContains(5, $first);
    }

    #[Test]
    public function reset_discards_all_indexed_profiles(): void
    {
        $generator = app(CatalogRecommendationCandidateGenerator::class);
        $source = $this->profile(1, relations: ['genre' => [10]]);

        $generator->add($source);
        $generator->add($this->profile(2, relations: ['genre' => [10]]));
        $this->assertSame([2], $generator->idsFor($source, 10));

        $generator->reset();

        $this->assertSame([], $generator->idsFor($source, 10));
    }

    /**
     * @param  array<string, list<int>>  $relations
     * @param  list<string>  $themes
     * @param  array<string, int>  $signals
     * @param  array<int, int>  $providerTargets
     * @return array{id: int, relations: array<string, list<int>>, themes: list<string>, signals: array<string, int>, provider_targets: array<int, int>}
     */
    private function profile(
        int $id,
        array $relations = [],
        array $themes = [],
        array $signals = [],
        array $providerTargets = [],
    ): array {
        return [
            'id' => $id,
            'relations' => $relations,
            'themes' => $themes,
            'signals' => $signals,
            'provider_targets' => $providerTargets,
        ];
    }
}
