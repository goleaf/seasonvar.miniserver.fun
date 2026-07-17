<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WarmCatalogCaches;
use App\Models\CatalogTitle;
use App\Services\Catalog\CacheWarmingState;
use App\Services\Catalog\CatalogCacheWarmer;
use App\Services\Catalog\CatalogCacheWarmRequestStore;
use App\Services\Catalog\CatalogStatsSnapshotCache;
use App\Services\Catalog\PublicPageCacheWarmer;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheKeyFactory;
use App\Support\Cache\CacheRebuildTimeout;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Cache;
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
        $this->assertSame(600, $job->timeout);
        $this->assertSame('catalog-critical-cache-warm-v2', $job->uniqueId());
        $this->assertContainsOnlyInstancesOf(WithoutOverlapping::class, $job->middleware());
    }

    public function test_warmer_job_can_wait_for_a_worker_without_expiring_before_handle(): void
    {
        $queue = new class extends SyncQueue
        {
            /** @return array<string, mixed> */
            public function payload(object $job, string $queue): array
            {
                return json_decode(
                    $this->createPayload($job, $queue),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
            }
        };
        $queue->setContainer($this->app);

        $payload = $queue->payload(new WarmCatalogCaches, 'cache-warm-v2');

        $this->assertNull($payload['retryUntil']);
        $this->assertSame(3, $payload['maxTries']);
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
        config(['cache-architecture.warming.queue' => 'cache-warm-v2']);
        Queue::fake();

        $this->artisan('cache:warm-catalog', ['--queue' => true, '--refresh' => true])
            ->expectsOutput('Прогрев поставлен в очередь cache-warm-v2.')
            ->assertSuccessful();

        Queue::assertPushed(
            WarmCatalogCaches::class,
            fn (WarmCatalogCaches $job): bool => $job->refresh && $job->queue === 'cache-warm-v2',
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
            'cache-architecture.page_cache.warm_url_limit' => 1_000,
            'cache-architecture.page_cache.warm_title_limit' => 1_000,
            'cache-architecture.warming.request_batch_title_limit' => 250,
        ]);
        $criticalUrlCount = 1_000 - app(PublicPageCacheWarmer::class)->titleCapacity();
        config(['cache-architecture.page_cache.warm_url_limit' => $criticalUrlCount + 1]);
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

    public function test_job_acknowledges_intent_after_a_degraded_public_page_warm_without_retry_storm(): void
    {
        config([
            'app.url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warming_enabled' => true,
            'cache-architecture.page_cache.warm_base_url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warm_url_limit' => 1,
            'cache-architecture.page_cache.warm_retry_times' => 1,
        ]);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response('', 503));
        Queue::fake();
        $store = app(CatalogCacheWarmRequestStore::class);
        $store->request(refresh: true);

        (new WarmCatalogCaches)->handle(app(CatalogCacheWarmer::class), $store);

        $this->assertNull($store->claim(10));
        $this->assertSame('degraded', app(CacheWarmingState::class)->read()['status'] ?? null);
        Queue::assertNotPushed(WarmCatalogCaches::class);
    }

    public function test_critical_warmer_marks_budget_limited_http_pass_as_degraded(): void
    {
        config([
            'app.url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warming_enabled' => true,
            'cache-architecture.page_cache.warm_base_url' => 'https://seasonvar.test',
            'cache-architecture.page_cache.warm_url_limit' => 2,
            'cache-architecture.page_cache.warm_budget_seconds' => 1,
            'cache-architecture.page_cache.warm_retry_times' => 1,
            'cache-architecture.warming.full_request_delay_milliseconds' => 0,
        ]);
        Http::preventStrayRequests();
        Http::fake(function () {
            usleep(1_050_000);

            return Http::response('<html></html>');
        });

        $result = app(CatalogCacheWarmer::class)->warmCritical();

        $this->assertSame(1, $result['public_pages']['attempted']);
        $this->assertSame(1, $result['public_pages']['skipped']);
        $this->assertTrue($result['public_pages']['limited']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('degraded', app(CacheWarmingState::class)->read()['status'] ?? null);
        Http::assertSentCount(1);
    }

    public function test_refresh_skips_a_contended_cache_target_and_keeps_warming_the_neighbors(): void
    {
        $locale = (string) collect(config('catalog-collections.supported_locales'))->first();
        $keys = app(CacheKeyFactory::class);
        $dataKey = $keys->data(
            CacheDomain::Homepage,
            'metrics',
            ['audience' => 'public', 'locale' => $locale],
            app(CacheVersionRegistry::class)->version(CacheDomain::Homepage),
        );
        $repository = Cache::store((string) config('cache-architecture.stores.locks'));
        $this->assertInstanceOf(Repository::class, $repository);
        $this->assertInstanceOf(LockProvider::class, $repository->getStore());
        $lock = $repository->getStore()->lock($keys->lock($dataKey), 30);
        $this->assertTrue($lock->get());

        try {
            $result = app(CatalogCacheWarmer::class)->warmCritical(refresh: true);
        } finally {
            $lock->release();
        }

        $this->assertGreaterThanOrEqual(1, $result['failed']);
        $this->assertContains("home_metrics:{$locale}", array_column($result['failures'], 'target'));
        $this->assertSame('degraded', app(CacheWarmingState::class)->read()['status'] ?? null);
        $this->assertArrayHasKey('home_snapshot', $result['targets']);
    }

    public function test_refresh_skips_a_contended_stats_target_and_keeps_warming_the_neighbors(): void
    {
        $this->app->instance(CatalogStatsSnapshotCache::class, new class extends CatalogStatsSnapshotCache
        {
            public function __construct() {}

            public function refresh(): array
            {
                throw new CacheRebuildTimeout('stats lock is busy');
            }
        });

        $result = app(CatalogCacheWarmer::class)->warmCritical(refresh: true);

        $this->assertGreaterThanOrEqual(1, $result['failed']);
        $this->assertContains('catalog_stats', array_column($result['failures'], 'target'));
        $this->assertArrayHasKey('catalog_stats', $result['targets']);
        $this->assertArrayHasKey('home_metrics', $result['targets']);
        $this->assertSame('degraded', app(CacheWarmingState::class)->read()['status'] ?? null);
    }
}
