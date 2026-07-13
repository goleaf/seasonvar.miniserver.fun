<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Catalog\CatalogStatsSnapshotBuilder;
use App\Services\Catalog\CatalogStatsSnapshotCache;
use Mockery;
use Tests\TestCase;

final class CatalogStatsSnapshotCacheTest extends TestCase
{
    public function test_snapshot_uses_tiered_cache_and_versioned_invalidation(): void
    {
        $builder = Mockery::mock(CatalogStatsSnapshotBuilder::class);
        $builder->shouldReceive('build')->twice()->andReturn(
            ['headlineStats' => [['value' => 1]]],
            ['headlineStats' => [['value' => 2]]],
        );
        $this->app->instance(CatalogStatsSnapshotBuilder::class, $builder);
        $cache = app(CatalogStatsSnapshotCache::class);

        $first = $cache->snapshot();
        $cached = $cache->snapshot();
        $cache->forget();
        $rebuilt = $cache->snapshot();

        $this->assertSame(1, $first['data']['headlineStats'][0]['value']);
        $this->assertSame(1, $cached['data']['headlineStats'][0]['value']);
        $this->assertSame(2, $rebuilt['data']['headlineStats'][0]['value']);
        $this->assertSame('rebuild', $first['meta']['cache_source']);
        $this->assertContains($cached['meta']['cache_source'], ['hot', 'shared']);
        $this->assertFalse($first['meta']['is_stale']);
    }
}
