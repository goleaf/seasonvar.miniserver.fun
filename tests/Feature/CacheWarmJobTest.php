<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WarmCatalogCaches;
use App\Models\CatalogTitle;
use App\Services\Catalog\CacheWarmingState;
use App\Services\Catalog\CatalogCacheWarmer;
use App\Services\Catalog\CatalogCacheWarmRequestStore;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CacheWarmJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_warmer_job_is_unique_after_commit_and_overlap_protected(): void
    {
        $job = new WarmCatalogCaches;

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertTrue($job->afterCommit);
        $this->assertSame(604_800, $job->uniqueFor);
        $this->assertSame('catalog-critical-cache-warm', $job->uniqueId());
        $this->assertContainsOnlyInstancesOf(WithoutOverlapping::class, $job->middleware());
    }

    public function test_critical_warmer_records_an_operational_snapshot(): void
    {
        $result = app(CatalogCacheWarmer::class)->warmCritical();
        $state = app(CacheWarmingState::class)->read();

        $this->assertSame('ok', $state['status'] ?? null);
        $this->assertSame($result['duration_ms'], $state['duration_ms'] ?? null);
        $this->assertSame(
            ['catalog_stats', 'home_metrics', 'home_snapshot', 'home_genres', 'home_countries'],
            array_keys($result['targets']),
        );
    }

    public function test_refresh_option_is_carried_by_the_queued_warmer(): void
    {
        Queue::fake();

        $this->artisan('cache:warm-catalog', ['--queue' => true, '--refresh' => true])
            ->assertSuccessful();

        Queue::assertPushed(
            WarmCatalogCaches::class,
            fn (WarmCatalogCaches $job): bool => $job->refresh,
        );
        $work = app(CatalogCacheWarmRequestStore::class)->claim(10);

        $this->assertNotNull($work);
        $this->assertTrue($work->refresh);
    }

    public function test_refresh_warmer_keeps_the_current_cache_namespaces_available(): void
    {
        $versions = app(CacheVersionRegistry::class);
        $before = collect([CacheDomain::Homepage, CacheDomain::CatalogFacets, CacheDomain::CatalogStats])
            ->mapWithKeys(fn (CacheDomain $domain): array => [$domain->value => $versions->version($domain)]);

        app(CatalogCacheWarmer::class)->warmCritical(refresh: true);

        foreach ($before as $domain => $version) {
            $this->assertSame($version, $versions->version(CacheDomain::from($domain)));
        }
    }

    public function test_legacy_job_without_pending_intent_is_a_no_op(): void
    {
        (new WarmCatalogCaches)->handle(
            app(CatalogCacheWarmer::class),
            app(CatalogCacheWarmRequestStore::class),
        );

        $this->assertNull(app(CacheWarmingState::class)->read());
    }

    public function test_job_acknowledges_one_batch_and_dispatches_a_tail_for_remaining_work(): void
    {
        config(['cache-architecture.warming.request_batch_title_limit' => 1]);
        Queue::fake();
        $store = app(CatalogCacheWarmRequestStore::class);
        $store->request([41, 42]);

        (new WarmCatalogCaches)->handle(app(CatalogCacheWarmer::class), $store);

        $remaining = $store->claim(10);
        $this->assertNotNull($remaining);
        $this->assertSame([42], $remaining->titleIds);
        Queue::assertPushed(WarmCatalogCaches::class, 1);
    }

    public function test_http_url_limit_leaves_unwarmed_title_ids_for_a_tail_job(): void
    {
        config([
            'app.url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warming_enabled' => true,
            'cache-architecture.page_cache.warm_base_url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warm_url_limit' => 15,
            'cache-architecture.warming.request_batch_title_limit' => 250,
        ]);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response('<html></html>'));
        Queue::fake();
        $titles = CatalogTitle::factory()->count(2)->create();
        $store = app(CatalogCacheWarmRequestStore::class);
        $store->request($titles->modelKeys());

        (new WarmCatalogCaches)->handle(app(CatalogCacheWarmer::class), $store);

        $remaining = $store->claim(10);
        $this->assertNotNull($remaining);
        $this->assertCount(1, $remaining->titleIds);
        Queue::assertPushed(WarmCatalogCaches::class, 1);
    }
}
