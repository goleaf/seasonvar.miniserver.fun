<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheKeyFactory;
use App\Support\Cache\CacheMetricsSnapshot;
use App\Support\Cache\CacheRebuildTimeout;
use App\Support\Cache\CacheTtlPolicy;
use App\Support\Cache\CacheVersionRegistry;
use App\Support\Cache\TieredCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class TieredCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.stores.tiered-hot-test' => ['driver' => 'array', 'serialize' => true],
            'cache.stores.tiered-domain-test' => ['driver' => 'array', 'serialize' => true],
            'cache.stores.tiered-lock-test' => ['driver' => 'array', 'serialize' => true],
            'cache.stores.tiered-metrics-test' => ['driver' => 'array', 'serialize' => true],
            'cache-architecture.application' => 'seasonvar',
            'cache-architecture.environment' => 'testing',
            'cache-architecture.schema_version' => 1,
            'cache-architecture.format_version' => 1,
            'cache-architecture.stores.hot' => 'tiered-hot-test',
            'cache-architecture.stores.domain' => 'tiered-domain-test',
            'cache-architecture.stores.locks' => 'tiered-lock-test',
            'cache-architecture.stores.metrics' => 'tiered-metrics-test',
            'cache-architecture.max_payload_bytes' => 900_000,
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

    public function test_selected_public_data_is_rebuilt_once_and_promoted_through_both_layers(): void
    {
        $calls = 0;
        $cache = app(TieredCache::class);
        $window = app(CacheTtlPolicy::class)->for(CacheDomain::CatalogStats);
        $resource = 'stats-'.bin2hex(random_bytes(6));
        $rebuild = function () use (&$calls): array {
            $calls++;

            return ['titles' => 42];
        };

        $first = $cache->remember(CacheDomain::CatalogStats, $resource, [], $window, $rebuild);
        $second = $cache->remember(CacheDomain::CatalogStats, $resource, [], $window, $rebuild);

        $this->assertSame(['titles' => 42], $first->value);
        $this->assertSame(['titles' => 42], $second->value);
        $this->assertSame('rebuild', $first->source);
        $this->assertContains($second->source, ['memo', 'hot']);
        $this->assertSame(1, $calls);
    }

    public function test_version_bump_invalidates_without_scanning_or_flushing_stores(): void
    {
        $calls = 0;
        $domain = CacheDomain::CatalogStats;
        $resource = 'versioned-'.bin2hex(random_bytes(6));
        $window = app(CacheTtlPolicy::class)->for($domain);
        $cache = app(TieredCache::class);
        $versions = app(CacheVersionRegistry::class);
        $rebuild = function () use (&$calls): int {
            return ++$calls;
        };

        $this->assertSame(1, $cache->remember($domain, $resource, [], $window, $rebuild)->value);
        $versions->bump($domain);
        $this->assertSame(2, $cache->remember($domain, $resource, [], $window, $rebuild)->value);
        $this->assertSame(2, $calls);
    }

    public function test_forced_refresh_rebuilds_the_current_namespace_without_a_stale_gap(): void
    {
        $calls = 0;
        $domain = CacheDomain::CatalogStats;
        $resource = 'refresh-'.bin2hex(random_bytes(6));
        $window = app(CacheTtlPolicy::class)->for($domain);
        $cache = app(TieredCache::class);
        $versions = app(CacheVersionRegistry::class);
        $version = $versions->version($domain);
        $rebuild = function () use (&$calls): array {
            return ['revision' => ++$calls];
        };

        $this->assertSame(['revision' => 1], $cache->remember($domain, $resource, [], $window, $rebuild)->value);
        $this->assertSame(['revision' => 2], $cache->refresh($domain, $resource, [], $window, $rebuild)->value);
        $this->assertSame($version, $versions->version($domain));
        $this->assertSame(['revision' => 2], $cache->remember($domain, $resource, [], $window, $rebuild)->value);
        $this->assertSame(2, $calls);
    }

    public function test_failed_forced_refresh_leaves_the_previous_envelope_readable(): void
    {
        $domain = CacheDomain::CatalogStats;
        $resource = 'failed-refresh-'.bin2hex(random_bytes(6));
        $window = app(CacheTtlPolicy::class)->for($domain);
        $cache = app(TieredCache::class);
        $cache->remember($domain, $resource, [], $window, fn (): array => ['revision' => 1]);

        try {
            $cache->refresh(
                $domain,
                $resource,
                [],
                $window,
                fn (): never => throw new \RuntimeException('refresh failed'),
            );
            $this->fail('Forced refresh must surface an authoritative rebuild failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('refresh failed', $exception->getMessage());
        }

        $result = $cache->remember(
            $domain,
            $resource,
            [],
            $window,
            fn (): never => throw new \LogicException('previous envelope must remain readable'),
        );

        $this->assertSame(['revision' => 1], $result->value);
    }

    public function test_null_negative_lookup_is_cached_only_when_explicitly_allowed(): void
    {
        $calls = 0;
        $domain = CacheDomain::CatalogStats;
        $resource = 'negative-'.bin2hex(random_bytes(6));
        $window = app(CacheTtlPolicy::class)->for($domain);
        $cache = app(TieredCache::class);
        $rebuild = function () use (&$calls): mixed {
            $calls++;

            return null;
        };

        $first = $cache->remember($domain, $resource, [], $window, $rebuild, cacheNull: true);
        $second = $cache->remember($domain, $resource, [], $window, $rebuild, cacheNull: true);

        $this->assertNull($first->value);
        $this->assertNull($second->value);
        $this->assertTrue($second->negative);
        $this->assertSame(1, $calls);
    }

    public function test_payload_limit_prevents_oversized_hot_or_redis_values(): void
    {
        config(['cache-architecture.max_payload_bytes' => 32]);
        $domain = CacheDomain::CatalogStats;
        $resource = 'large-'.bin2hex(random_bytes(6));
        $window = app(CacheTtlPolicy::class)->for($domain);
        $cache = app(TieredCache::class);
        $value = ['payload' => str_repeat('x', 100)];

        $result = $cache->remember($domain, $resource, [], $window, fn (): array => $value);
        $key = app(CacheKeyFactory::class)->data($domain, $resource, [], app(CacheVersionRegistry::class)->version($domain));

        $this->assertSame($value, $result->value);
        $this->assertFalse(Cache::store('tiered-hot-test')->has($key));
        $this->assertFalse(Cache::store('tiered-domain-test')->has($key));
        $metrics = app(CacheMetricsSnapshot::class)->forDate();
        $this->assertSame(1, $metrics['totals']['cache-payload-count']);
        $this->assertGreaterThan(32, $metrics['totals']['average-cache-payload-bytes']);
    }

    public function test_bounded_stale_value_is_served_when_a_rebuild_fails(): void
    {
        $domain = CacheDomain::CatalogStats;
        $resource = 'stale-'.bin2hex(random_bytes(6));
        $window = app(CacheTtlPolicy::class)->for($domain);
        $cache = app(TieredCache::class);
        $cache->remember($domain, $resource, [], $window, fn (): array => ['safe' => true]);
        $this->travel(61)->seconds();

        $result = $cache->remember(
            $domain,
            $resource,
            [],
            $window,
            fn (): never => throw new \RuntimeException('rebuild failed'),
        );

        $this->assertSame(['safe' => true], $result->value);
        $this->assertTrue($result->stale);
        $this->assertSame('stale', $result->source);
    }

    public function test_request_memoization_avoids_a_second_hot_store_read(): void
    {
        $domain = CacheDomain::CatalogStats;
        $resource = 'memo-'.bin2hex(random_bytes(6));
        $window = app(CacheTtlPolicy::class)->for($domain);
        $version = app(CacheVersionRegistry::class)->version($domain);
        $key = app(CacheKeyFactory::class)->data($domain, $resource, [], $version);
        $store = Cache::store('tiered-hot-test');
        $store->put($key, ['format' => 1, 'negative' => false, 'value' => ['revision' => 1]], 60);

        $first = app(TieredCache::class)->remember($domain, $resource, [], $window, fn (): never => throw new \LogicException('must not rebuild'));
        $store->put($key, ['format' => 1, 'negative' => false, 'value' => ['revision' => 2]], 60);
        $second = app(TieredCache::class)->remember($domain, $resource, [], $window, fn (): never => throw new \LogicException('must not rebuild'));

        $this->assertSame(['revision' => 1], $first->value);
        $this->assertSame(['revision' => 1], $second->value);
    }

    public function test_waiting_for_an_in_progress_rebuild_has_a_bounded_timeout(): void
    {
        $domain = CacheDomain::CatalogStats;
        $resource = 'locked-'.bin2hex(random_bytes(6));
        $window = app(CacheTtlPolicy::class)->for($domain);
        $keys = app(CacheKeyFactory::class);
        $version = app(CacheVersionRegistry::class)->version($domain);
        $dataKey = $keys->data($domain, $resource, [], $version);
        $lock = Cache::store('tiered-lock-test')->lock($keys->lock($dataKey), 10);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(CacheRebuildTimeout::class);

            app(TieredCache::class)->remember(
                $domain,
                $resource,
                [],
                $window,
                fn (): array => ['must-not-run' => true],
            );
        } finally {
            $lock->release();
        }
    }

    public function test_unavailable_version_registry_never_reuses_an_old_hot_namespace(): void
    {
        $domain = CacheDomain::CatalogStats;
        $resource = 'version-outage-'.bin2hex(random_bytes(6));
        $key = app(CacheKeyFactory::class)->data($domain, $resource, [], 1);
        Cache::store('tiered-hot-test')->put($key, [
            'format' => 1,
            'negative' => false,
            'value' => ['revision' => 'stale-v1'],
        ], 60);
        config([
            'cache.stores.unavailable-version-test' => ['driver' => 'unsupported-version-test'],
            'cache-architecture.stores.versions' => 'unavailable-version-test',
        ]);

        $result = app(TieredCache::class)->remember(
            $domain,
            $resource,
            [],
            app(CacheTtlPolicy::class)->for($domain),
            fn (): array => ['revision' => 'database'],
        );

        $this->assertSame(['revision' => 'database'], $result->value);
        $this->assertSame('rebuild', $result->source);
    }
}
