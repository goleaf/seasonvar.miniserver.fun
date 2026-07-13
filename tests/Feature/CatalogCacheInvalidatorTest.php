<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\WarmCatalogCaches;
use App\Services\Catalog\CatalogCacheInvalidator;
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
        Queue::assertPushed(
            WarmCatalogCaches::class,
            fn (WarmCatalogCaches $job): bool => ! $job->refresh,
        );
    }
}
