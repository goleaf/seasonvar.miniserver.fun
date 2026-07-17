<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogRecommendationBuild;
use App\Models\CatalogRecommendationBuildRow;
use App\Models\CatalogTitle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CatalogRecommendationShadowSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_rows_have_a_covering_score_distribution_index(): void
    {
        $index = collect(Schema::getIndexes('catalog_recommendation_build_rows'))
            ->firstWhere('name', 'catalog_recommendation_build_rows_build_score_idx');

        $this->assertNotNull($index);
        $this->assertSame(['build_id', 'score', 'id'], $index['columns']);
    }

    public function test_build_rows_are_ranked_unique_and_deleted_with_their_build(): void
    {
        $source = CatalogTitle::factory()->create();
        $first = CatalogTitle::factory()->create();
        $second = CatalogTitle::factory()->create();
        $build = CatalogRecommendationBuild::query()->create([
            'algorithm_version' => 'v6',
            'feature_version' => 'tokens-v2',
            'status' => 'building',
            'started_at' => now(),
        ]);

        CatalogRecommendationBuildRow::query()->create($this->row(
            $build->id,
            $source->id,
            $second->id,
            rank: 2,
            score: 700,
        ));
        CatalogRecommendationBuildRow::query()->create($this->row(
            $build->id,
            $source->id,
            $first->id,
            rank: 1,
            score: 900,
        ));

        $this->assertSame(
            [$first->id, $second->id],
            $build->rows()->orderBy('catalog_title_id')->orderBy('rank')->pluck('recommended_title_id')->all(),
        );
        $this->assertSame(
            'v6',
            CatalogRecommendationBuildRow::query()->with('build')->firstOrFail()->build->algorithm_version,
        );

        try {
            CatalogRecommendationBuildRow::query()->create($this->row(
                $build->id,
                $source->id,
                $first->id,
                rank: 3,
                score: 500,
            ));
            $this->fail('Повторная пара внутри одного shadow build должна нарушать unique constraint.');
        } catch (QueryException) {
            $this->assertDatabaseCount('catalog_recommendation_build_rows', 2);
        }

        $build->delete();

        $this->assertDatabaseCount('catalog_recommendation_build_rows', 0);
    }

    /** @return array<string, mixed> */
    private function row(int $buildId, int $sourceId, int $candidateId, int $rank, int $score): array
    {
        return [
            'build_id' => $buildId,
            'catalog_title_id' => $sourceId,
            'recommended_title_id' => $candidateId,
            'score' => $score,
            'rank' => $rank,
            'matched_features_count' => 2,
            'metadata_score' => $score - 100,
            'source_score' => 0,
            'quality_score' => 100,
            'reasons' => ['genre' => ['score' => $score - 100]],
            'computed_at' => now(),
        ];
    }
}
