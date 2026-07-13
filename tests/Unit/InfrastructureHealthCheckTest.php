<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Operations\InfrastructureHealthCheck;
use Tests\TestCase;

final class InfrastructureHealthCheckTest extends TestCase
{
    public function test_unavailable_memcached_store_degrades_health_instead_of_crashing_the_check(): void
    {
        config([
            'cache.stores.memcached-hot' => ['driver' => 'unsupported-health-test'],
        ]);

        $result = app(InfrastructureHealthCheck::class)->run();

        $this->assertSame('failed', $result['components']['memcached']['status']);
        $this->assertSame('degraded', $result['status']);
        $this->assertTrue($result['ready']);
    }

    public function test_failed_worker_observation_marks_an_otherwise_ready_service_as_degraded(): void
    {
        config([
            'cache.stores.unavailable-worker-heartbeat' => ['driver' => 'unsupported-health-test'],
            'cache-architecture.stores.domain' => 'unavailable-worker-heartbeat',
        ]);

        $result = app(InfrastructureHealthCheck::class)->run();

        $this->assertSame('failed', $result['components']['queue_workers']['status']);
        $this->assertSame('degraded', $result['status']);
        $this->assertTrue($result['ready']);
    }
}
