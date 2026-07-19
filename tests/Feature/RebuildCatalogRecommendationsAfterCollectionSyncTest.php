<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RebuildCatalogRecommendationsAfterCollectionSync;
use App\Jobs\WarmCatalogCaches;
use App\Models\CatalogRecommendationBuild;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\CatalogTitleRecommendationSignal;
use App\Models\LicensedMedia;
use App\Models\SeasonvarImportRun;
use App\Services\Catalog\CatalogCacheWarmRequestStore;
use App\Services\Catalog\CatalogRecommendationDirtyTitleTracker;
use App\Services\Catalog\CatalogTitleRecommendationBuilder;
use App\Services\Seasonvar\SeasonvarImportActivity;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class RebuildCatalogRecommendationsAfterCollectionSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache-architecture.stores.locks' => 'array',
            'cache-architecture.warming.enabled' => true,
            'recommendations.similarity_v6.allow_activation_without_golden' => true,
            'seasonvar.recommendations.min_score' => 1,
        ]);
    }

    public function test_job_has_a_unique_bounded_queue_contract(): void
    {
        $job = new RebuildCatalogRecommendationsAfterCollectionSync;

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff());
        $this->assertSame('catalog-recommendations-after-collection-sync', $job->uniqueId());
        $this->assertSame('redis', $job->connection);
        $this->assertSame('seasonvar-import', $job->queue);
    }

    public function test_job_rebuilds_dirty_editorial_collection_similarity_then_dispatches_cache_warm(): void
    {
        Queue::fake();
        CatalogRecommendationBuild::query()->create([
            'algorithm_version' => 'v6',
            'feature_version' => 'tokens-v2',
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(4),
            'activated_at' => now()->subMinutes(4),
        ]);
        $source = CatalogTitle::factory()->create(['year' => 2018]);
        $candidate = CatalogTitle::factory()->create(['year' => 2024]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $candidate->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        foreach ([$source, $candidate] as $title) {
            CatalogTitleRecommendationSignal::query()->create([
                'catalog_title_id' => $title->id,
                'source' => 'hdrezka',
                'signal_type' => 'editorial_collection',
                'signal_key' => 'job-test',
                'weight' => 280,
                'observed_at' => now(),
            ]);
        }

        app(CatalogRecommendationDirtyTitleTracker::class)->markMany(
            [$source->id, $candidate->id],
            'editorial-collection-sync',
        );

        (new RebuildCatalogRecommendationsAfterCollectionSync)->handle(
            app(CatalogTitleRecommendationBuilder::class),
            app(CatalogCacheWarmRequestStore::class),
            app(SeasonvarImportActivity::class),
        );

        $recommendation = CatalogTitleRecommendation::query()
            ->where('catalog_title_id', $source->id)
            ->where('recommended_title_id', $candidate->id)
            ->firstOrFail();

        $this->assertArrayHasKey('collection_signal', $recommendation->reasons);
        $this->assertDatabaseCount('catalog_recommendation_dirty_titles', 0);
        $this->assertTrue(app(CatalogCacheWarmRequestStore::class)->claim(10)?->refresh);
        Queue::assertPushed(WarmCatalogCaches::class, 1);
    }

    public function test_job_defers_a_full_fallback_until_the_import_pipeline_without_warming_cache(): void
    {
        Queue::fake();
        $title = CatalogTitle::factory()->create();
        app(CatalogRecommendationDirtyTitleTracker::class)->mark(
            $title->id,
            'editorial-collection-sync',
        );

        (new RebuildCatalogRecommendationsAfterCollectionSync)->handle(
            app(CatalogTitleRecommendationBuilder::class),
            app(CatalogCacheWarmRequestStore::class),
            app(SeasonvarImportActivity::class),
        );

        $this->assertDatabaseHas('catalog_recommendation_dirty_titles', [
            'catalog_title_id' => $title->id,
        ]);
        $this->assertDatabaseCount('catalog_recommendation_builds', 0);
        $this->assertNull(app(CatalogCacheWarmRequestStore::class)->claim(10));
        Queue::assertNotPushed(WarmCatalogCaches::class);
    }

    public function test_job_leaves_dirty_titles_for_an_active_import_pipeline_without_warming_cache(): void
    {
        Queue::fake();
        CatalogRecommendationBuild::query()->create([
            'algorithm_version' => 'v6',
            'feature_version' => 'tokens-v2',
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(4),
            'activated_at' => now()->subMinutes(4),
        ]);
        SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'status' => 'running',
            'started_at' => now()->subMinute(),
            'last_heartbeat_at' => now(),
        ]);
        $title = CatalogTitle::factory()->create();
        app(CatalogRecommendationDirtyTitleTracker::class)->mark(
            $title->id,
            'editorial-collection-sync',
        );

        (new RebuildCatalogRecommendationsAfterCollectionSync)->handle(
            app(CatalogTitleRecommendationBuilder::class),
            app(CatalogCacheWarmRequestStore::class),
            app(SeasonvarImportActivity::class),
        );

        $this->assertDatabaseHas('catalog_recommendation_dirty_titles', [
            'catalog_title_id' => $title->id,
        ]);
        $this->assertNull(app(CatalogCacheWarmRequestStore::class)->claim(10));
        Queue::assertNotPushed(WarmCatalogCaches::class);
    }
}
