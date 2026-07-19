<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CheckInfrastructureHealthCommandTest extends TestCase
{
    public function test_degraded_operational_health_returns_failure_while_traffic_remains_ready(): void
    {
        config([
            'cache-architecture.stores.domain' => 'array',
            'cache-architecture.stores.versions' => 'array',
            'cache-architecture.warming.connection' => 'sync',
            'cache.stores.memcached-hot' => ['driver' => 'unsupported-health-test'],
            'queue.default' => 'sync',
            'seasonvar.queue.connection' => 'sync',
        ]);

        $exitCode = Artisan::call('app:health', ['--json' => true]);
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($result['ready']);
        $this->assertSame('degraded', $result['status']);
        $this->assertSame(1, $exitCode);

        $response = $this->getJson('/health/ready');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('ready', true)
            ->assertJsonPath('status', 'ok');
        $this->assertStringNotContainsString(base_path(), $response->getContent());
    }
}
