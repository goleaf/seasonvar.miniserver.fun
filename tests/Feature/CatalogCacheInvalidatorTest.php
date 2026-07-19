<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WarmCatalogCaches;
use App\Services\Catalog\CatalogCacheInvalidator;
use App\Services\Catalog\CatalogCacheWarmRequestStore;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CatalogCacheInvalidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_grouped_invalidation_is_deferred_until_commit_and_dispatches_one_warmer(): void
    {
        config(['cache-architecture.warming.enabled' => true]);
        Queue::fake();
        $versions = app(CacheVersionRegistry::class);
        $before = collect([
            CacheDomain::Homepage,
            CacheDomain::CatalogPages,
            CacheDomain::CatalogFacets,
            CacheDomain::CatalogStats,
            CacheDomain::Api,
            CacheDomain::Sitemap,
            CacheDomain::Recommendations,
        ])->mapWithKeys(fn (CacheDomain $domain): array => [$domain->value => $versions->version($domain)]);
        $titleVersion = $versions->version(CacheDomain::TitleDetail, 'title:17');

        DB::beginTransaction();
        app(CatalogCacheInvalidator::class)->catalogChanged([17, 17, 23]);

        $this->assertSame($before[CacheDomain::Homepage->value], $versions->version(CacheDomain::Homepage));
        Queue::assertNothingPushed();

        DB::commit();

        foreach ($before as $domain => $version) {
            $this->assertGreaterThan($version, $versions->version(CacheDomain::from($domain)));
        }

        $this->assertGreaterThan($titleVersion, $versions->version(CacheDomain::TitleDetail, 'title:17'));
        $work = app(CatalogCacheWarmRequestStore::class)->claim(10);
        $this->assertNotNull($work);
        $this->assertEqualsCanonicalizing([17, 23], $work->titleIds);
        Queue::assertPushed(
            WarmCatalogCaches::class,
            fn (WarmCatalogCaches $job): bool => ! $job->refresh,
        );
    }

    public function test_unknown_title_invalidation_bumps_global_title_generation_and_records_refresh_work(): void
    {
        config(['cache-architecture.warming.enabled' => true]);
        Queue::fake();
        $versions = app(CacheVersionRegistry::class);
        $before = $versions->version(CacheDomain::TitleDetail);

        app(CatalogCacheInvalidator::class)->catalogChanged();

        $this->assertGreaterThan($before, $versions->version(CacheDomain::TitleDetail));
        $work = app(CatalogCacheWarmRequestStore::class)->claim(10);
        $this->assertNotNull($work);
        $this->assertTrue($work->refresh);
        $this->assertSame([], $work->titleIds);
        Queue::assertPushed(WarmCatalogCaches::class, 1);
    }

    public function test_repeated_invalidations_coalesce_the_pending_warm_job(): void
    {
        config(['cache-architecture.warming.enabled' => true]);
        Queue::fake();

        app(CatalogCacheInvalidator::class)->importedTitleChanged(17);
        app(CatalogCacheInvalidator::class)->importedTitleChanged(23);

        Queue::assertPushed(WarmCatalogCaches::class, 1);
    }

    public function test_rolled_back_invalidation_does_not_create_warm_intent(): void
    {
        config(['cache-architecture.warming.enabled' => true]);
        Queue::fake();

        DB::beginTransaction();
        app(CatalogCacheInvalidator::class)->catalogChanged([91]);
        DB::rollBack();

        $this->assertNull(app(CatalogCacheWarmRequestStore::class)->claim(10));
        Queue::assertNothingPushed();
    }
}
