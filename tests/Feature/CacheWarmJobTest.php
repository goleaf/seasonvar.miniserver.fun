<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WarmCatalogCaches;
use App\Services\Catalog\CacheWarmingState;
use App\Services\Catalog\CatalogCacheWarmer;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
        $this->assertTrue($job->afterCommit);
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
}
