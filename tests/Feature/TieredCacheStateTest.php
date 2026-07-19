<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheEntryState;
use App\Support\Cache\CacheTtlPolicy;
use App\Support\Cache\TieredCache;
use Tests\TestCase;

final class TieredCacheStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.stores.tiered-state-hot-test' => ['driver' => 'array', 'serialize' => true],
            'cache.stores.tiered-state-domain-test' => ['driver' => 'array', 'serialize' => true],
            'cache.stores.tiered-state-lock-test' => ['driver' => 'array', 'serialize' => true],
            'cache.stores.tiered-state-metrics-test' => ['driver' => 'array', 'serialize' => true],
            'cache-architecture.application' => 'seasonvar',
            'cache-architecture.environment' => 'testing',
            'cache-architecture.schema_version' => 1,
            'cache-architecture.format_version' => 1,
            'cache-architecture.stores.hot' => 'tiered-state-hot-test',
            'cache-architecture.stores.domain' => 'tiered-state-domain-test',
            'cache-architecture.stores.locks' => 'tiered-state-lock-test',
            'cache-architecture.stores.versions' => 'tiered-state-lock-test',
            'cache-architecture.stores.metrics' => 'tiered-state-metrics-test',
            'cache-architecture.domains.catalog-stats' => [
                'fresh' => 60,
                'stale' => 600,
                'hot' => 30,
                'negative' => 10,
                'lock' => 10,
                'wait_milliseconds' => 50,
                'jitter_percent' => 0,
            ],
        ]);
    }

    public function test_it_reports_missing_fresh_stale_and_unavailable_states(): void
    {
        $cache = app(TieredCache::class);
        $domain = CacheDomain::CatalogStats;
        $window = app(CacheTtlPolicy::class)->for($domain);

        $this->assertSame(CacheEntryState::Missing, $cache->state($domain, 'state', []));
        $cache->remember($domain, 'state', [], $window, fn (): array => ['fresh' => true]);
        $this->assertSame(CacheEntryState::Fresh, $cache->state($domain, 'state', []));
        $this->travel(61)->seconds();
        app()->forgetInstance('cache.__memoized:tiered-state-domain-test');
        $this->assertSame(CacheEntryState::Stale, $cache->state($domain, 'state', []));

        config([
            'cache.stores.unavailable-state-test' => ['driver' => 'unsupported-state-test'],
            'cache-architecture.stores.domain' => 'unavailable-state-test',
        ]);
        $this->assertSame(CacheEntryState::Unavailable, $cache->state($domain, 'unavailable', []));
    }
}
