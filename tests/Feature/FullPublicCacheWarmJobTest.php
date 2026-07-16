<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\PublicCacheWarmBatch;
use App\Jobs\WarmPublicCatalogCaches;
use App\Models\SeasonvarImportRun;
use App\Services\Catalog\PublicCatalogWarmStateStore;
use App\Services\Catalog\PublicCatalogWarmTargetSource;
use App\Services\Catalog\PublicPageCacheWarmer;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class FullPublicCacheWarmJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warm_base_url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warming_enabled' => true,
            'cache-architecture.page_cache.warm_retry_times' => 1,
            'cache-architecture.warming.full_batch_url_limit' => 1,
            'cache-architecture.warming.full_job_budget_seconds' => 60,
            'cache-architecture.warming.full_import_pause_seconds' => 300,
        ]);
        Http::preventStrayRequests();
    }

    public function test_job_is_unique_after_commit_and_overlap_protected(): void
    {
        $job = new WarmPublicCatalogCaches('generation-1');

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertTrue($job->afterCommit);
        $this->assertSame('catalog-all-public-cache-warm:generation-1', $job->uniqueId());
        $this->assertContainsOnlyInstancesOf(WithoutOverlapping::class, $job->middleware());
    }

    public function test_job_advances_cursor_and_dispatches_one_tail(): void
    {
        Http::fake(fn () => Http::response('<html></html>'));
        Queue::fake();
        $store = app(PublicCatalogWarmStateStore::class);
        $state = $store->start(false);

        (new WarmPublicCatalogCaches($state['generation']))->handle(
            $store,
            app(PublicCatalogWarmTargetSource::class),
            app(PublicPageCacheWarmer::class),
        );

        $updated = $store->read();
        $this->assertSame(1, $updated['attempted'] ?? null);
        $this->assertSame(1, $updated['warmed'] ?? null);
        $this->assertSame('running', $updated['status'] ?? null);
        $this->assertNotNull($updated['cursor'] ?? null);
        Queue::assertPushed(WarmPublicCatalogCaches::class, 1);
    }

    public function test_job_releases_without_http_while_import_is_active(): void
    {
        Http::fake();
        $store = app(PublicCatalogWarmStateStore::class);
        $state = $store->start(false);
        SeasonvarImportRun::query()->create(['mode' => 'all', 'status' => 'running']);
        $job = (new WarmPublicCatalogCaches($state['generation']))->withFakeQueueInteractions();

        $job->handle(
            $store,
            app(PublicCatalogWarmTargetSource::class),
            app(PublicPageCacheWarmer::class),
        );

        $job->assertReleased(300);
        Http::assertNothingSent();
        $this->assertSame(0, $store->read()['attempted'] ?? null);
    }

    public function test_duplicate_job_does_not_restart_a_completed_generation(): void
    {
        Http::fake();
        Queue::fake();
        $store = app(PublicCatalogWarmStateStore::class);
        $state = $store->start(false);
        $store->advance(
            $state['generation'],
            new PublicCacheWarmBatch([], null, true),
            ['attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'errors' => []],
        );

        (new WarmPublicCatalogCaches($state['generation']))->handle(
            $store,
            app(PublicCatalogWarmTargetSource::class),
            app(PublicPageCacheWarmer::class),
        );

        $this->assertSame('completed', $store->read()['status'] ?? null);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
