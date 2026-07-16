<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogRecommendationBuild;
use App\Models\CatalogRecommendationBuildRow;
use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogRecommendationBuildPruner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogRecommendationBuildPrunerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_keeps_the_active_build_and_only_the_latest_bounded_terminal_history(): void
    {
        config(['recommendations.similarity_v6.build_history_limit' => 3]);
        $active = $this->build('active');
        $terminal = collect(range(1, 6))->map(fn (int $index): CatalogRecommendationBuild => $this->build(
            $index % 2 === 0 ? 'rejected' : 'evaluated',
        ));
        $source = CatalogTitle::factory()->create();
        $candidate = CatalogTitle::factory()->create();
        CatalogRecommendationBuildRow::query()->create([
            'build_id' => $terminal->first()->id,
            'catalog_title_id' => $source->id,
            'recommended_title_id' => $candidate->id,
            'score' => 700,
            'rank' => 1,
            'computed_at' => now(),
        ]);

        $deleted = app(CatalogRecommendationBuildPruner::class)->prune();

        $this->assertSame(3, $deleted);
        $this->assertDatabaseHas('catalog_recommendation_builds', ['id' => $active->id, 'status' => 'active']);
        $this->assertSame(
            $terminal->slice(-3)->pluck('id')->all(),
            CatalogRecommendationBuild::query()
                ->where('status', '!=', 'active')
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        );
        $this->assertDatabaseMissing('catalog_recommendation_build_rows', [
            'build_id' => $terminal->first()->id,
        ]);
    }

    private function build(string $status): CatalogRecommendationBuild
    {
        return CatalogRecommendationBuild::query()->create([
            'algorithm_version' => 'v6',
            'feature_version' => 'tokens-v2',
            'status' => $status,
            'started_at' => now(),
            'completed_at' => $status === 'active' ? null : now(),
            'activated_at' => $status === 'active' ? now() : null,
        ]);
    }
}
