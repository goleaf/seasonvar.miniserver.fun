<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Operations\RedisPersistenceInspector;
use Illuminate\Redis\RedisManager;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class RedisPersistenceInspectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-24 12:00:00 UTC');

        config([
            'cache-architecture.redis_persistence.connection' => 'queues',
            'cache-architecture.redis_persistence.running_warning_seconds' => 120,
            'cache-architecture.redis_persistence.running_failure_seconds' => 900,
            'cache-architecture.redis_persistence.last_save_warning_seconds' => 3_600,
        ]);
    }

    public function test_long_running_save_is_failed_and_reports_safe_bounded_metrics(): void
    {
        $result = $this->inspector()->summarize([
            'rdb_bgsave_in_progress' => 1,
            'rdb_current_bgsave_time_sec' => 3_601,
            'rdb_last_save_time' => now()->subHours(30)->timestamp,
            'rdb_last_bgsave_status' => 'ok',
            'rdb_changes_since_last_save' => 10_500_000,
            'aof_enabled' => 0,
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertSame(3_601, $result['current_save_seconds']);
        $this->assertSame(108_000, $result['last_save_age_seconds']);
        $this->assertSame(10_500_000, $result['changes_since_last_save']);
        $this->assertFalse($result['aof_enabled']);
        $this->assertSame('Фоновое сохранение Redis выполняется дольше допустимого.', $result['message']);
    }

    public function test_idle_instance_without_unsaved_changes_is_healthy(): void
    {
        $result = $this->inspector()->summarize([
            'rdb_bgsave_in_progress' => 0,
            'rdb_current_bgsave_time_sec' => -1,
            'rdb_last_save_time' => now()->subHours(12)->timestamp,
            'rdb_last_bgsave_status' => 'ok',
            'rdb_changes_since_last_save' => 0,
            'aof_enabled' => 1,
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['current_save_seconds']);
        $this->assertSame(43_200, $result['last_save_age_seconds']);
        $this->assertSame(0, $result['changes_since_last_save']);
        $this->assertTrue($result['aof_enabled']);
        $this->assertArrayNotHasKey('message', $result);
    }

    public function test_recent_active_save_is_healthy(): void
    {
        $result = $this->inspector()->summarize([
            'rdb_bgsave_in_progress' => 1,
            'rdb_current_bgsave_time_sec' => 60,
            'rdb_last_save_time' => now()->subMinutes(10)->timestamp,
            'rdb_last_bgsave_status' => 'ok',
            'rdb_changes_since_last_save' => 25,
            'aof_enabled' => 0,
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(60, $result['current_save_seconds']);
        $this->assertArrayNotHasKey('message', $result);
    }

    public function test_active_save_past_warning_is_degraded(): void
    {
        $result = $this->inspector()->summarize([
            'rdb_bgsave_in_progress' => 1,
            'rdb_current_bgsave_time_sec' => 121,
            'rdb_last_save_time' => now()->subMinutes(10)->timestamp,
            'rdb_last_bgsave_status' => 'ok',
            'rdb_changes_since_last_save' => 25,
            'aof_enabled' => 0,
        ]);

        $this->assertSame('degraded', $result['status']);
        $this->assertSame('Фоновое сохранение Redis выполняется дольше ожидаемого.', $result['message']);
    }

    public function test_failed_last_save_is_failed(): void
    {
        $result = $this->inspector()->summarize([
            'rdb_bgsave_in_progress' => 0,
            'rdb_current_bgsave_time_sec' => -1,
            'rdb_last_save_time' => now()->subMinute()->timestamp,
            'rdb_last_bgsave_status' => 'err',
            'rdb_changes_since_last_save' => 100,
            'aof_enabled' => 0,
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('Последнее фоновое сохранение Redis завершилось ошибкой.', $result['message']);
    }

    public function test_unsaved_changes_past_last_save_warning_are_degraded(): void
    {
        $result = $this->inspector()->summarize([
            'rdb_bgsave_in_progress' => 0,
            'rdb_current_bgsave_time_sec' => -1,
            'rdb_last_save_time' => now()->subSeconds(3_601)->timestamp,
            'rdb_last_bgsave_status' => 'ok',
            'rdb_changes_since_last_save' => 100,
            'aof_enabled' => 0,
        ]);

        $this->assertSame('degraded', $result['status']);
        $this->assertSame('Изменения Redis давно не подтверждены успешным сохранением.', $result['message']);
    }

    public function test_missing_fields_return_a_safe_failure(): void
    {
        $result = $this->inspector()->summarize([]);

        $this->assertSame([
            'status' => 'failed',
            'current_save_seconds' => 0,
            'last_save_age_seconds' => 0,
            'changes_since_last_save' => 0,
            'aof_enabled' => false,
            'message' => 'Данные Redis persistence неполны.',
        ], $result);
    }

    public function test_connection_failure_does_not_expose_the_raw_error(): void
    {
        $manager = Mockery::mock(RedisManager::class);
        $manager->shouldReceive('connection')
            ->once()
            ->with('queues')
            ->andThrow(new RuntimeException('redis://private-user:secret@internal-host:6379'));

        $result = (new RedisPersistenceInspector($manager))->inspect();

        $this->assertSame('failed', $result['status']);
        $this->assertSame('Проверка Redis persistence недоступна.', $result['message']);
        $this->assertStringNotContainsString('secret', $result['message']);
        $this->assertStringNotContainsString('internal-host', $result['message']);
    }

    private function inspector(): RedisPersistenceInspector
    {
        return new RedisPersistenceInspector(app(RedisManager::class));
    }
}
