<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

final class CacheWarmScheduleTest extends TestCase
{
    public function test_critical_cache_warming_is_scheduled_as_a_deduplicated_queue_dispatch(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => $event->description === 'catalog-cache-warm');

        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertStringContainsString('cache:warm-catalog --queue --refresh', $event->command);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
        $this->assertSame('redis-locks', $event->mutex->store);
    }
}
