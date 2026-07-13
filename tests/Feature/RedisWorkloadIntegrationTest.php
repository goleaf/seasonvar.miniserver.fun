<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class RedisWorkloadIntegrationTest extends TestCase
{
    public function test_redis_session_handler_round_trips_on_the_sessions_connection(): void
    {
        $this->requireInfrastructureTests();
        config([
            'session.driver' => 'redis',
            'session.connection' => 'sessions',
            'session.store' => null,
        ]);
        $session = (new SessionManager(app()))->driver();
        $handler = $session->getHandler();
        $id = bin2hex(random_bytes(20));
        $otherId = bin2hex(random_bytes(20));
        $payload = 'session-payload-'.bin2hex(random_bytes(8));
        $otherPayload = 'other-session-payload-'.bin2hex(random_bytes(8));

        try {
            $this->assertTrue($handler->write($id, $payload));
            $this->assertTrue($handler->write($otherId, $otherPayload));
            $this->assertSame($payload, $handler->read($id));
            $this->assertSame($otherPayload, $handler->read($otherId));
            $this->assertNotSame($handler->read($id), $handler->read($otherId));
            $this->assertFalse(Cache::store('redis-domain')->has($id));
        } finally {
            $handler->destroy($id);
            $handler->destroy($otherId);
        }
    }

    public function test_redis_queue_uses_an_isolated_named_queue(): void
    {
        $this->requireInfrastructureTests();
        $queue = 'integration-'.bin2hex(random_bytes(10));
        $connection = Queue::connection('redis');

        try {
            $connection->push(new RedisIntegrationProbe, queue: $queue);

            $this->assertSame(1, $connection->size($queue));
            $job = $connection->pop($queue);
            $this->assertNotNull($job);
            $job->delete();
            $this->assertSame(0, $connection->size($queue));
        } finally {
            while (($job = $connection->pop($queue)) !== null) {
                $job->delete();
            }
        }
    }

    private function requireInfrastructureTests(): void
    {
        if (! (bool) config('cache-architecture.run_infrastructure_tests', false)) {
            $this->markTestSkipped('Real Redis workload tests are enabled explicitly in CI and operations.');
        }
    }
}

final class RedisIntegrationProbe implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}
