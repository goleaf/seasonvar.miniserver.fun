<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheKeyFactory;
use App\Support\Cache\CacheTtlPolicy;
use InvalidArgumentException;
use Tests\TestCase;

final class CacheArchitectureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache-architecture.application' => 'seasonvar',
            'cache-architecture.environment' => 'testing',
            'cache-architecture.schema_version' => 7,
            'cache-architecture.format_version' => 2,
            'cache-architecture.stores.versions' => 'redis-locks',
            'cache-architecture.max_dimensions' => 24,
            'cache-architecture.max_dimension_length' => 160,
        ]);
    }

    public function test_logically_identical_dimensions_produce_the_same_bounded_key(): void
    {
        $keys = app(CacheKeyFactory::class);
        $first = $keys->data(CacheDomain::CatalogFacets, 'landing', [
            'filters' => ['genre' => ['drama', 'comedy'], 'year' => [2026, 2025]],
            'page' => 1,
            'locale' => 'ru',
        ], 4);
        $second = $keys->data(CacheDomain::CatalogFacets, 'landing', [
            'locale' => 'ru',
            'page' => 1,
            'filters' => ['year' => [2026, 2025], 'genre' => ['drama', 'comedy']],
        ], 4);

        $this->assertSame($first, $second);
        $this->assertStringStartsWith('seasonvar:testing:s7:f2:catalog-facets:v4:', $first);
        $this->assertLessThanOrEqual(180, strlen($first));
    }

    public function test_untrusted_dimensions_are_hashed_and_never_exposed_in_a_key(): void
    {
        $rawSearch = '  Очень секретный пользовательский поиск  ';
        $key = app(CacheKeyFactory::class)->data(
            CacheDomain::SearchSuggestions,
            'query',
            ['query' => $rawSearch],
            1,
        );

        $this->assertStringNotContainsString('секретный', mb_strtolower($key));
        $this->assertMatchesRegularExpression('/:[a-f0-9]{64}$/', $key);
    }

    public function test_key_factory_rejects_unbounded_dimensions(): void
    {
        config(['cache-architecture.max_dimension_length' => 8]);

        $this->expectException(InvalidArgumentException::class);

        app(CacheKeyFactory::class)->data(
            CacheDomain::Homepage,
            'snapshot',
            ['query' => str_repeat('x', 9)],
            1,
        );
    }

    public function test_metric_keys_reject_unbounded_or_non_date_dimensions(): void
    {
        $keys = app(CacheKeyFactory::class);

        $this->expectException(InvalidArgumentException::class);
        $keys->metric(CacheDomain::Operational, 'hit', '../../raw-user-input');
    }

    public function test_ttl_policy_has_deliberate_windows_and_bounded_jitter(): void
    {
        config([
            'cache-architecture.domains.homepage' => [
                'fresh' => 120,
                'stale' => 900,
                'hot' => 45,
                'negative' => 20,
                'lock' => 60,
                'wait_milliseconds' => 250,
                'jitter_percent' => 10,
            ],
        ]);

        $window = app(CacheTtlPolicy::class)->for(CacheDomain::Homepage);

        $this->assertSame(120, $window->freshSeconds);
        $this->assertSame(900, $window->staleSeconds);
        $this->assertSame(45, $window->hotSeconds);
        $this->assertSame(20, $window->negativeSeconds);
        $this->assertSame(60, $window->lockSeconds);
        $this->assertSame(250, $window->waitMilliseconds);
        $this->assertGreaterThanOrEqual(108, $window->jitteredFreshSeconds());
        $this->assertLessThanOrEqual(132, $window->jitteredFreshSeconds());
    }

    public function test_expensive_catalog_stats_use_a_long_snapshot_window(): void
    {
        $window = app(CacheTtlPolicy::class)->for(CacheDomain::CatalogStats);

        $this->assertSame(1800, $window->freshSeconds);
        $this->assertSame(86400, $window->staleSeconds);
        $this->assertSame(300, $window->hotSeconds);
        $this->assertSame(180, $window->lockSeconds);
    }

    public function test_named_stores_and_redis_workload_connections_are_explicit(): void
    {
        $this->assertSame('redis', config('cache.stores.redis-domain.driver'));
        $this->assertSame('cache', config('cache.stores.redis-domain.connection'));
        $this->assertSame('locks', config('cache.stores.redis-domain.lock_connection'));
        $this->assertSame('memcached', config('cache.stores.memcached-hot.driver'));
        $this->assertNull(config('cache.limiter'));
        $this->assertNull(config('cache.stores.redis-limiter'));
        $this->assertNull(config('database.redis.limiter'));
        $this->assertSame('redis-locks', config('cache-architecture.stores.versions'));
        $this->assertNull(config('session.connection'));
        $this->assertSame('queues', config('queue.connections.redis.connection'));

        foreach (['cache', 'sessions', 'queues', 'locks', 'broadcasting'] as $connection) {
            $this->assertIsArray(config('database.redis.'.$connection));
        }
    }
}
