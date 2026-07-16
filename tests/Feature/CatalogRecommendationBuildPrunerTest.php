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

    public function test_it_marks_a_build_without_a_recent_heartbeat_as_failed(): void
    {
        config([
            'recommendations.similarity_v6.build_history_limit' => 5,
            'recommendations.similarity_v6.stale_build_minutes' => 20,
        ]);
        $stale = $this->build('building');
        $recent = $this->build('building');
        CatalogRecommendationBuild::query()->whereKey($stale->id)->update([
            'updated_at' => now()->subMinutes(21),
        ]);

        $deleted = app(CatalogRecommendationBuildPruner::class)->prune();

        $this->assertSame(0, $deleted);
        $this->assertSame('failed', $stale->fresh()->status);
        $this->assertNotNull($stale->fresh()->completed_at);
        $this->assertStringContainsString('heartbeat', (string) $stale->fresh()->failure_message);
        $this->assertSame('building', $recent->fresh()->status);
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
