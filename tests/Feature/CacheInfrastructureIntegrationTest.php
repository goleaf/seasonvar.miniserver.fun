<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheKeyFactory;
use App\Support\Cache\CacheTtlPolicy;
use App\Support\Cache\CacheVersionRegistry;
use App\Support\Cache\TieredCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class CacheInfrastructureIntegrationTest extends TestCase
{
    public function test_real_redis_domain_store_and_lock_are_available(): void
    {
        $this->requireInfrastructureTests();
        $key = 'integration:redis:'.bin2hex(random_bytes(12));
        $lockKey = $key.':lock';

        try {
            Cache::store('redis-domain')->put($key, ['ok' => true], 30);
            $this->assertSame(['ok' => true], Cache::store('redis-domain')->get($key));
            $lock = Cache::store('redis-locks')->lock($lockKey, 10);
            $this->assertTrue($lock->get());
            $this->assertFalse(Cache::store('redis-locks')->lock($lockKey, 10)->get());
            $lock->release();
        } finally {
            Cache::store('redis-domain')->forget($key);
            Cache::store('redis-locks')->lock($lockKey, 10)->forceRelease();
        }
    }

    public function test_real_redis_version_registry_invalidates_one_bounded_scope(): void
    {
        $this->requireInfrastructureTests();
        $scope = 'integration-'.bin2hex(random_bytes(8));
        $domain = CacheDomain::Operational;
        $keys = app(CacheKeyFactory::class);
        $originalStore = config('cache-architecture.stores.versions');
        config(['cache-architecture.stores.versions' => 'redis-locks']);

        try {
            $versions = app(CacheVersionRegistry::class);
            $before = $versions->version($domain, $scope);

            $this->assertSame($before + 1, $versions->bump($domain, $scope));
            $this->assertSame($before + 1, $versions->version($domain, $scope));
        } finally {
            Cache::store('redis-locks')->forget($keys->version($domain, $scope));
            Cache::store('redis-locks')->forget($keys->modified($domain, $scope));
            config(['cache-architecture.stores.versions' => $originalStore]);
        }
    }

    public function test_real_memcached_hot_store_is_disposable_and_recovers_from_a_miss(): void
    {
        $this->requireInfrastructureTests();
        $key = 'integration:memcached:'.bin2hex(random_bytes(12));

        try {
            $this->assertNull(Cache::store('memcached-hot')->get($key));
            Cache::store('memcached-hot')->put($key, ['hot' => true], 30);
            $this->assertSame(['hot' => true], Cache::store('memcached-hot')->get($key));
        } finally {
            Cache::store('memcached-hot')->forget($key);
        }
    }

    public function test_readiness_endpoint_distinguishes_cache_session_queue_lock_and_warming_components(): void
    {
        $this->requireInfrastructureTests();

        $response = $this->getJson('/health/ready');

        $response->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonStructure([
                'status',
                'ready',
                'checked_at',
                'components' => [
                    'database',
                    'redis_cache' => [
                        'status',
                        'latency_ms',
                        'used_memory_bytes',
                        'maxmemory_bytes',
                        'evicted_keys',
                    ],
                    'redis_sessions',
                    'redis_queues',
                    'redis_locks',
                    'redis_limiter',
                    'memcached' => [
                        'status',
                        'latency_ms',
                        'get_hits',
                        'get_misses',
                        'evictions',
                        'curr_items',
                        'bytes',
                        'limit_maxbytes',
                        'connections',
                        'rejected_connections',
                    ],
                    'queue_workers',
                    'horizon',
                    'cache_warming',
                ],
            ]);
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_redis_tag_invalidation_removes_only_the_selected_domain_group(): void
    {
        $this->requireInfrastructureTests();
        $tag = 'integration-tag-'.bin2hex(random_bytes(8));
        $taggedKey = 'tagged-'.bin2hex(random_bytes(8));
        $unrelatedKey = 'unrelated-'.bin2hex(random_bytes(8));
        $store = Cache::store('redis-domain');

        try {
            $store->tags([$tag])->put($taggedKey, 'tagged', 30);
            $store->put($unrelatedKey, 'unrelated', 30);
            $store->tags([$tag])->flush();

            $this->assertNull($store->get($taggedKey));
            $this->assertSame('unrelated', $store->get($unrelatedKey));
        } finally {
            $store->tags([$tag])->flush();
            $store->forget($unrelatedKey);
        }
    }

    public function test_session_queue_and_limiter_connections_are_isolated(): void
    {
        $this->requireInfrastructureTests();
        $key = 'integration-isolation-'.bin2hex(random_bytes(12));
        $connections = ['sessions', 'queues', 'limiter'];

        try {
            Redis::connection('sessions')->setex($key, 30, 'session');
            Redis::connection('queues')->setex($key, 30, 'queue');
            Redis::connection('limiter')->setex($key, 30, 'limiter');

            $this->assertSame('session', Redis::connection('sessions')->get($key));
            $this->assertSame('queue', Redis::connection('queues')->get($key));
            $this->assertSame('limiter', Redis::connection('limiter')->get($key));
        } finally {
            foreach ($connections as $connection) {
                Redis::connection($connection)->del($key);
            }
        }
    }

    public function test_memcached_outage_falls_back_to_redis_without_duplicate_rebuild(): void
    {
        $this->requireInfrastructureTests();
        $originalHot = config('cache-architecture.stores.hot');
        $calls = 0;

        config([
            'cache.stores.integration-broken-memcached' => [
                'driver' => 'memcached',
                'persistent_id' => 'integration-broken-'.bin2hex(random_bytes(4)),
                'options' => [\Memcached::OPT_CONNECT_TIMEOUT => 20],
                'servers' => [['host' => '127.0.0.1', 'port' => 1, 'weight' => 100]],
            ],
            'cache-architecture.stores.hot' => 'integration-broken-memcached',
            'cache-architecture.stores.domain' => 'redis-domain',
            'cache-architecture.stores.locks' => 'redis-locks',
        ]);

        try {
            $resource = 'memcached-outage-'.bin2hex(random_bytes(8));
            $cache = app(TieredCache::class);
            $window = app(CacheTtlPolicy::class)->for(CacheDomain::Operational);
            $rebuild = function () use (&$calls): array {
                $calls++;

                return ['safe' => true];
            };

            $this->assertSame(['safe' => true], $cache->remember(CacheDomain::Operational, $resource, [], $window, $rebuild)->value);
            $this->assertSame(['safe' => true], $cache->remember(CacheDomain::Operational, $resource, [], $window, $rebuild)->value);
            $this->assertSame(1, $calls);
        } finally {
            config(['cache-architecture.stores.hot' => $originalHot]);
            app('cache')->forgetDriver('integration-broken-memcached');
        }
    }

    public function test_redis_cache_outage_uses_disposable_hot_data_and_preserves_read_correctness(): void
    {
        $this->requireInfrastructureTests();
        $originalDomain = config('cache-architecture.stores.domain');
        $originalMetrics = config('cache-architecture.stores.metrics');
        $calls = 0;
        $resource = 'redis-outage-'.bin2hex(random_bytes(8));

        config([
            'database.redis.integration-broken-cache' => [
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 15,
                'prefix' => 'integration-broken-cache-',
                'timeout' => 0.02,
                'read_timeout' => 0.02,
                'max_retries' => 0,
            ],
            'cache.stores.integration-broken-redis' => [
                'driver' => 'redis',
                'connection' => 'integration-broken-cache',
                'lock_connection' => 'locks',
            ],
            'cache-architecture.stores.hot' => 'memcached-hot',
            'cache-architecture.stores.domain' => 'integration-broken-redis',
            'cache-architecture.stores.metrics' => 'integration-broken-redis',
            'cache-architecture.stores.locks' => 'redis-locks',
        ]);

        try {
            $cache = app(TieredCache::class);
            $window = app(CacheTtlPolicy::class)->for(CacheDomain::Operational);
            $rebuild = function () use (&$calls): array {
                $calls++;

                return ['authoritative' => true];
            };

            $this->assertSame(['authoritative' => true], $cache->remember(CacheDomain::Operational, $resource, [], $window, $rebuild)->value);
            $this->assertSame(['authoritative' => true], $cache->remember(CacheDomain::Operational, $resource, [], $window, $rebuild)->value);
            $this->assertSame(1, $calls);
        } finally {
            $key = app(CacheKeyFactory::class)->data(CacheDomain::Operational, $resource, [], 1);
            Cache::store('memcached-hot')->forget($key);
            config([
                'cache-architecture.stores.domain' => $originalDomain,
                'cache-architecture.stores.metrics' => $originalMetrics,
            ]);
            app('cache')->forgetDriver('integration-broken-redis');
            Redis::purge('integration-broken-cache');
        }
    }

    public function test_version_invalidation_remains_authoritative_when_domain_redis_is_unavailable(): void
    {
        $this->requireInfrastructureTests();
        $originalDomain = config('cache-architecture.stores.domain');
        $originalMetrics = config('cache-architecture.stores.metrics');
        $originalVersions = config('cache-architecture.stores.versions');
        $resource = 'redis-outage-invalidation-'.bin2hex(random_bytes(8));
        $scope = 'integration-'.bin2hex(random_bytes(8));
        $calls = 0;

        config([
            'database.redis.integration-broken-versioned-cache' => [
                'host' => '127.0.0.1',
                'port' => 1,
                'database' => 15,
                'prefix' => 'integration-broken-versioned-cache-',
                'timeout' => 0.02,
                'read_timeout' => 0.02,
                'max_retries' => 0,
            ],
            'cache.stores.integration-broken-versioned-redis' => [
                'driver' => 'redis',
                'connection' => 'integration-broken-versioned-cache',
                'lock_connection' => 'locks',
            ],
            'cache-architecture.stores.hot' => 'memcached-hot',
            'cache-architecture.stores.domain' => 'integration-broken-versioned-redis',
            'cache-architecture.stores.metrics' => 'integration-broken-versioned-redis',
            'cache-architecture.stores.locks' => 'redis-locks',
            'cache-architecture.stores.versions' => 'redis-locks',
        ]);

        try {
            $domain = CacheDomain::Operational;
            $cache = app(TieredCache::class);
            $versions = app(CacheVersionRegistry::class);
            $window = app(CacheTtlPolicy::class)->for($domain);
            $before = $versions->version($domain, $scope);
            $rebuild = function () use (&$calls): array {
                return ['revision' => ++$calls];
            };

            $this->assertSame(
                ['revision' => 1],
                $cache->remember($domain, $resource, [], $window, $rebuild, versionScope: $scope)->value,
            );
            $after = $versions->bump($domain, $scope);
            $this->assertGreaterThan($before, $after);
            $this->assertSame(
                ['revision' => 2],
                $cache->remember($domain, $resource, [], $window, $rebuild, versionScope: $scope)->value,
            );
            $this->assertSame(2, $calls);
        } finally {
            $keys = app(CacheKeyFactory::class);
            Cache::store('memcached-hot')->forget($keys->data(CacheDomain::Operational, $resource, [], $before ?? 1));
            Cache::store('memcached-hot')->forget($keys->data(CacheDomain::Operational, $resource, [], $after ?? 2));
            config([
                'cache-architecture.stores.domain' => $originalDomain,
                'cache-architecture.stores.metrics' => $originalMetrics,
                'cache-architecture.stores.versions' => $originalVersions,
            ]);
            app('cache')->forgetDriver('integration-broken-versioned-redis');
            Redis::purge('integration-broken-versioned-cache');
        }
    }

    private function requireInfrastructureTests(): void
    {
        if (! (bool) config('cache-architecture.run_infrastructure_tests', false)) {
            $this->markTestSkipped('Real Redis/Memcached integration tests are enabled explicitly in CI and operations.');
        }
    }
}
