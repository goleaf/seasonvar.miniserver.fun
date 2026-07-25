<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PruneSeasonvarImportStorage;
use App\Models\SeasonvarImportEvent;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Models\SourcePage;
use App\Models\SourcePageSnapshot;
use App\Services\Seasonvar\SeasonvarImportStorageMaintenance;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class SeasonvarImportStoragePruneJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.stores.storage-prune-lock-test' => [
                'driver' => 'array',
                'serialize' => true,
            ],
            'seasonvar.queue.connection' => 'redis',
            'seasonvar.queue.queue' => 'seasonvar-import',
            'seasonvar.queue.lock_store' => 'storage-prune-lock-test',
            'seasonvar.import.storage_maintenance_enabled' => true,
            'seasonvar.import.storage_maintenance_scheduled_enabled' => false,
            'seasonvar.import.event_retention_days' => 7,
            'seasonvar.import.snapshot_retention_days' => 0,
            'seasonvar.import.prepared_retention_days' => 0,
            'seasonvar.import.maintenance_chunk_size' => 2,
            'seasonvar.import.maintenance_max_chunks' => 10,
            'seasonvar.import.maintenance_max_rows' => 2,
            'seasonvar.import.maintenance_time_budget_seconds' => 30,
        ]);
        Cache::store('storage-prune-lock-test')->flush();
        Http::preventStrayRequests();
    }

    public function test_storage_prune_job_contract_is_available(): void
    {
        $this->assertTrue(class_exists(PruneSeasonvarImportStorage::class));
    }

    public function test_job_is_unique_overlap_protected_and_uses_the_configured_queue(): void
    {
        $job = new PruneSeasonvarImportStorage;

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('redis', $job->connection);
        $this->assertSame('seasonvar-import', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertSame(900, $job->uniqueFor);
        $this->assertSame([60, 300, 900], $job->backoff());
        $this->assertSame('seasonvar-import-storage-prune-v1', $job->uniqueId());
        $this->assertCount(1, $job->middleware());
        $this->assertSame(WithoutOverlapping::class, get_class($job->middleware()[0]));
        $this->assertSame(
            Cache::store('storage-prune-lock-test')->getStore()::class,
            $job->uniqueVia()->getStore()::class,
        );
    }

    public function test_job_applies_the_shared_row_budget_without_provider_http(): void
    {
        $this->assertTrue(method_exists(PruneSeasonvarImportStorage::class, 'handle'));

        $run = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'status' => 'completed',
            'started_at' => now()->subDays(20),
            'finished_at' => now()->subDays(19),
        ]);

        foreach (range(1, 4) as $offset) {
            $event = SeasonvarImportEvent::query()->create([
                'seasonvar_import_run_id' => $run->id,
                'event' => 'seasonvar-media-url-checked',
                'level' => 'info',
                'context' => ['offset' => $offset],
            ]);
            $event->forceFill([
                'created_at' => now()->subDays(10)->addSeconds($offset),
                'updated_at' => now()->subDays(10)->addSeconds($offset),
            ])->save();
        }

        (new PruneSeasonvarImportStorage)->handle(new SeasonvarImportStorageMaintenance);

        $this->assertDatabaseCount('seasonvar_import_events', 2);
        Http::assertNothingSent();
    }

    public function test_job_preserves_latest_snapshots_and_all_active_work(): void
    {
        config([
            'seasonvar.import.snapshot_retention_days' => 14,
            'seasonvar.import.prepared_retention_days' => 7,
            'seasonvar.import.maintenance_max_rows' => 100,
        ]);
        $completedRun = $this->importRun('completed');
        $runningRun = $this->importRun('running');

        $latestOldPage = SourcePage::factory()->create();
        $latestOldSnapshot = $this->snapshot($latestOldPage, $completedRun, 'latest-old', now()->subDays(30));

        $historyPage = SourcePage::factory()->create();
        $expiredHistory = $this->snapshot($historyPage, $completedRun, 'history-old', now()->subDays(20));
        $recentHistory = $this->snapshot($historyPage, $completedRun, 'history-new', now()->subDay());

        $activePage = SourcePage::factory()->create();
        $activeOldSnapshot = $this->snapshot($activePage, $runningRun, 'active-old', now()->subDays(20));
        $activeRecentSnapshot = $this->snapshot($activePage, $runningRun, 'active-new', now()->subDay());

        $terminalGroup = $this->titleGroup($completedRun, 'completed', 'terminal-job');
        $terminalPreparedPage = $this->preparedPage($terminalGroup);
        $finalizingGroup = $this->titleGroup($completedRun, 'finalizing', 'finalizing-job');
        $finalizingPreparedPage = $this->preparedPage($finalizingGroup);
        $activeRunGroup = $this->titleGroup($runningRun, 'completed', 'active-job');
        $activeRunPreparedPage = $this->preparedPage($activeRunGroup);

        (new PruneSeasonvarImportStorage)->handle(new SeasonvarImportStorageMaintenance);

        $this->assertDatabaseHas('source_page_snapshots', ['id' => $latestOldSnapshot->id]);
        $this->assertDatabaseMissing('source_page_snapshots', ['id' => $expiredHistory->id]);
        $this->assertDatabaseHas('source_page_snapshots', ['id' => $recentHistory->id]);
        $this->assertDatabaseHas('source_page_snapshots', ['id' => $activeOldSnapshot->id]);
        $this->assertDatabaseHas('source_page_snapshots', ['id' => $activeRecentSnapshot->id]);
        $this->assertDatabaseMissing('seasonvar_import_title_groups', ['id' => $terminalGroup->id]);
        $this->assertDatabaseMissing('seasonvar_import_prepared_pages', ['id' => $terminalPreparedPage->id]);
        $this->assertDatabaseHas('seasonvar_import_title_groups', ['id' => $finalizingGroup->id]);
        $this->assertDatabaseHas('seasonvar_import_prepared_pages', ['id' => $finalizingPreparedPage->id]);
        $this->assertDatabaseHas('seasonvar_import_title_groups', ['id' => $activeRunGroup->id]);
        $this->assertDatabaseHas('seasonvar_import_prepared_pages', ['id' => $activeRunPreparedPage->id]);
        Http::assertNothingSent();
    }

    public function test_failure_log_contains_only_low_cardinality_context(): void
    {
        $this->assertTrue(method_exists(PruneSeasonvarImportStorage::class, 'failed'));

        Log::shouldReceive('error')
            ->once()
            ->with(
                'Очистка служебного хранилища импорта Seasonvar завершилась ошибкой.',
                Mockery::on(fn (array $context): bool => $context === [
                    'job' => 'seasonvar-import-storage-prune-v1',
                    'exception' => RuntimeException::class,
                ]),
            );

        (new PruneSeasonvarImportStorage)->failed(new RuntimeException('private payload and token'));
    }

    public function test_scheduler_does_not_dispatch_when_storage_maintenance_is_disabled(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => $event->description === 'seasonvar-import-storage-prune');

        $this->assertNotNull($event);
        $this->assertSame('17 4 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
        $this->assertSame('redis-locks', $event->mutex->store);
        $this->assertFalse($event->filtersPass(app()));

        config()->set('seasonvar.import.storage_maintenance_scheduled_enabled', true);

        $this->assertTrue($event->filtersPass(app()));
    }

    private function importRun(string $status): SeasonvarImportRun
    {
        return SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'status' => $status,
            'started_at' => now()->subDays(20),
            'finished_at' => $status === 'running' ? null : now()->subDays(19),
        ]);
    }

    private function snapshot(
        SourcePage $sourcePage,
        SeasonvarImportRun $run,
        string $hashSeed,
        Carbon $capturedAt,
    ): SourcePageSnapshot {
        return SourcePageSnapshot::query()->create([
            'source_page_id' => $sourcePage->id,
            'seasonvar_import_run_id' => $run->id,
            'url' => $sourcePage->url,
            'content_hash' => hash('sha256', $hashSeed),
            'http_status' => 200,
            'body_bytes' => 128,
            'html' => '<html></html>',
            'captured_at' => $capturedAt,
        ]);
    }

    private function titleGroup(
        SeasonvarImportRun $run,
        string $status,
        string $hashSeed,
    ): SeasonvarImportTitleGroup {
        return SeasonvarImportTitleGroup::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'group_key_hash' => hash('sha256', $hashSeed),
            'queue_name' => 'seasonvar-import',
            'status' => $status,
            'expected_pages' => 1,
            'prepared_pages' => 1,
            'failed_pages' => 0,
            'finished_at' => now()->subDays(10),
        ]);
    }

    private function preparedPage(SeasonvarImportTitleGroup $group): SeasonvarImportPreparedPage
    {
        $sourcePage = SourcePage::factory()->create();

        return SeasonvarImportPreparedPage::query()->create([
            'seasonvar_import_run_id' => $group->seasonvar_import_run_id,
            'seasonvar_import_title_group_id' => $group->id,
            'source_page_id' => $sourcePage->id,
            'status' => 'applied',
            'payload' => ['title' => 'Тест'],
            'applied_at' => now()->subDays(10),
        ]);
    }
}
