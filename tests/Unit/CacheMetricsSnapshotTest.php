<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheKeyFactory;
use App\Support\Cache\CacheMetricsSnapshot;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class CacheMetricsSnapshotTest extends TestCase
{
    public function test_snapshot_reports_low_cardinality_framework_cache_events(): void
    {
        $date = '2026-07-13';
        $keys = app(CacheKeyFactory::class);
        $store = Cache::store('array');
        $store->put($keys->metric(CacheDomain::Operational, 'framework-hit', $date), 7, 60);
        $store->put($keys->metric(CacheDomain::Operational, 'framework-miss', $date), 3, 60);
        $store->put($keys->metric(CacheDomain::Operational, 'framework-failover', $date), 1, 60);

        $snapshot = app(CacheMetricsSnapshot::class)->forDate($date);

        $this->assertSame(7, $snapshot['totals']['framework-hit']);
        $this->assertSame(3, $snapshot['totals']['framework-miss']);
        $this->assertSame(1, $snapshot['totals']['framework-failover']);
    }

    public function test_snapshot_reports_invalidation_and_cache_warming_operations(): void
    {
        $date = '2026-07-13';
        $keys = app(CacheKeyFactory::class);
        $store = Cache::store('array');
        $store->put($keys->metric(CacheDomain::Homepage, 'invalidation', $date), 4, 60);
        $store->put($keys->metric(CacheDomain::Operational, 'warming-count', $date), 2, 60);
        $store->put($keys->metric(CacheDomain::Operational, 'warming-milliseconds', $date), 240, 60);
        $store->put($keys->metric(CacheDomain::Operational, 'warming-failure', $date), 1, 60);
        $store->put($keys->metric(CacheDomain::Operational, 'warming-dispatch-failure', $date), 3, 60);

        $snapshot = app(CacheMetricsSnapshot::class)->forDate($date);

        $this->assertSame(4, $snapshot['totals']['invalidation']);
        $this->assertSame(2, $snapshot['totals']['warming-count']);
        $this->assertSame(240, $snapshot['totals']['warming-milliseconds']);
        $this->assertSame(120.0, $snapshot['totals']['average-warming-ms']);
        $this->assertSame(1, $snapshot['totals']['warming-failure']);
        $this->assertSame(3, $snapshot['totals']['warming-dispatch-failure']);
    }
}
