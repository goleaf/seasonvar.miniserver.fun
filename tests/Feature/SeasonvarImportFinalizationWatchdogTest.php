<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\FinalizeSeasonvarImportTitleGroup;
use App\Jobs\FinalizeSeasonvarQueuedImport;
use App\Jobs\WakeSeasonvarImportFinalizers;
use App\Models\SeasonvarImportRun;
use App\Services\Seasonvar\SeasonvarImportFinalizationDispatcher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SeasonvarImportFinalizationWatchdogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'seasonvar.queue.lock_store' => 'array',
            'seasonvar.queue.finalizer_watchdog_batch_size' => 100,
        ]);
        Cache::store('array')->flush();
        Queue::fake();
    }

    public function test_watchdog_dispatches_only_ready_groups_and_active_global_runs(): void
    {
        $globalRun = $this->importRun('sitemap');
        $readyGroup = $globalRun->titleGroups()->create([
            'group_key_hash' => hash('sha256', 'ready'),
            'queue_name' => 'seasonvar-import',
            'status' => 'running',
            'expected_pages' => 2,
            'prepared_pages' => 1,
            'failed_pages' => 1,
            'started_at' => now(),
        ]);
        $waitingGroup = $globalRun->titleGroups()->create([
            'group_key_hash' => hash('sha256', 'waiting'),
            'queue_name' => 'seasonvar-import',
            'status' => 'running',
            'expected_pages' => 2,
            'prepared_pages' => 1,
            'failed_pages' => 0,
            'started_at' => now(),
        ]);
        $staleImpossibleGroup = $globalRun->titleGroups()->create([
            'group_key_hash' => hash('sha256', 'stale-impossible'),
            'queue_name' => 'seasonvar-import',
            'status' => 'running',
            'expected_pages' => 2,
            'prepared_pages' => 1,
            'failed_pages' => 0,
            'started_at' => now()->subDays(2),
        ]);
        DB::table($staleImpossibleGroup->getTable())
            ->where('id', $staleImpossibleGroup->id)
            ->update(['updated_at' => now()->subDays(2)]);
        $finishedRun = $this->importRun('sitemap', 'completed');
        $finishedRun->titleGroups()->create([
            'group_key_hash' => hash('sha256', 'finished'),
            'queue_name' => 'seasonvar-import',
            'status' => 'running',
            'expected_pages' => 1,
            'prepared_pages' => 1,
            'started_at' => now(),
        ]);

        $result = app(SeasonvarImportFinalizationDispatcher::class)->wakeReady();

        $this->assertSame(['title_groups' => 2, 'global_runs' => 1], $result);
        Queue::assertPushed(
            FinalizeSeasonvarImportTitleGroup::class,
            fn (FinalizeSeasonvarImportTitleGroup $job): bool => $job->groupId === $readyGroup->id,
        );
        Queue::assertNotPushed(
            FinalizeSeasonvarImportTitleGroup::class,
            fn (FinalizeSeasonvarImportTitleGroup $job): bool => $job->groupId === $waitingGroup->id,
        );
        Queue::assertPushed(
            FinalizeSeasonvarImportTitleGroup::class,
            fn (FinalizeSeasonvarImportTitleGroup $job): bool => $job->groupId === $staleImpossibleGroup->id,
        );
        Queue::assertPushed(
            FinalizeSeasonvarQueuedImport::class,
            fn (FinalizeSeasonvarQueuedImport $job): bool => $job->importRunId === $globalRun->id,
        );
        Queue::assertNotPushed(
            FinalizeSeasonvarQueuedImport::class,
            fn (FinalizeSeasonvarQueuedImport $job): bool => $job->importRunId === $finishedRun->id,
        );
    }

    public function test_watchdog_job_and_schedule_are_deduplicated(): void
    {
        $job = new WakeSeasonvarImportFinalizers;

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('redis', $job->connection);
        $this->assertSame('seasonvar-import', $job->queue);
        $this->assertSame('seasonvar-import-finalization-watchdog', $job->uniqueId());

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => $event->description === 'seasonvar-import-finalization-watchdog');

        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
        $this->assertSame('redis-locks', $event->mutex->store);
    }

    private function importRun(string $mode, string $status = 'running'): SeasonvarImportRun
    {
        return SeasonvarImportRun::query()->create([
            'mode' => $mode,
            'execution_mode' => 'queue',
            'status' => $status,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
    }
}
