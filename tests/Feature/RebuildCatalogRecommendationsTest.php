<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RebuildCatalogRecommendations;
use App\Jobs\WarmCatalogCaches;
use App\Models\SeasonvarImportRun;
use App\Services\Catalog\CatalogCacheWarmRequestStore;
use App\Services\Catalog\CatalogTitleRecommendationBuilder;
use App\Services\Seasonvar\SeasonvarImportActivity;
use App\Services\Seasonvar\SeasonvarImportMaintenancePipeline;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class RebuildCatalogRecommendationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_has_a_unique_bounded_contract_on_the_import_worker(): void
    {
        config(['seasonvar.recommendations.worker_timeout' => 840]);

        $job = new RebuildCatalogRecommendations;

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(840, $job->timeout);
        $this->assertSame([60, 300, 900], $job->backoff());
        $this->assertSame('catalog-recommendations-full-v6', $job->uniqueId());
        $this->assertSame('redis', $job->connection);
        $this->assertSame('seasonvar-import', $job->queue);
    }

    public function test_job_waits_for_an_active_import_before_rebuilding(): void
    {
        Queue::fake();
        SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'status' => 'running',
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);

        (new RebuildCatalogRecommendations)->handle(
            app(CatalogTitleRecommendationBuilder::class),
            app(CatalogCacheWarmRequestStore::class),
            app(SeasonvarImportActivity::class),
            app(SeasonvarImportMaintenancePipeline::class),
        );

        $this->assertNull(app(CatalogCacheWarmRequestStore::class)->claim(10));
        Queue::assertNotPushed(WarmCatalogCaches::class);
    }
}
