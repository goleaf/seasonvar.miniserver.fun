<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\FinalizeSeasonvarImportTitleGroup;
use App\Jobs\FinalizeSeasonvarQueuedImport;
use App\Jobs\ImportSeasonvarSourcePage;
use App\Jobs\PrepareSeasonvarImportTitlePage;
use App\Jobs\ReconcileSeasonvarQueuedImportRun;
use App\Jobs\WakeSeasonvarImportFinalizers;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarActiveRunReconciler;
use App\Services\Seasonvar\SeasonvarGlobalImportRunCoordinator;
use App\Services\Seasonvar\SeasonvarPageClaimManager;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class SeasonvarActiveRunReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-25 12:00:00');
        config([
            'seasonvar.queue.connection' => 'redis',
            'seasonvar.queue.queue' => 'seasonvar-import',
            'seasonvar.queue.lock_store' => 'array',
            'seasonvar.queue.stale_after_minutes' => 5,
            'seasonvar.queue.finalizer_watchdog_batch_size' => 2,
        ]);
        Cache::store('array')->flush();
        Queue::fake();
    }

    public function test_it_recovers_an_interrupted_dispatch_from_the_durable_prepared_page_ledger(): void
    {
        [$run, $group, $page, $prepared] = $this->activeLedgerRow(now()->subHour());
        $claim = app(SeasonvarPageClaimManager::class)->claim($page, $run->id, 3600);

        $this->assertNotNull($claim);

        $result = app(SeasonvarActiveRunReconciler::class)->reconcile($run->id);
        $freshRun = $run->fresh();
        $freshPage = $page->fresh();

        $this->assertTrue($result->eligible);
        $this->assertTrue($result->dispatchRecovered);
        $this->assertSame(1, $result->jobsDispatched);
        $this->assertFalse($result->hasRemainingDueWork);
        $this->assertTrue(data_get($freshRun->summary, 'dispatch_completed'));
        $this->assertSame(
            'redis_transport_reconciled',
            data_get($freshRun->summary, 'active_run_reconciliation.reason'),
        );
        $this->assertSame($claim, $freshPage->import_claim_token);
        $this->assertSame($run->id, $freshPage->import_claim_run_id);
        Queue::assertPushed(
            PrepareSeasonvarImportTitlePage::class,
            fn (PrepareSeasonvarImportTitlePage $job): bool => $job->preparedPageId === $prepared->id
                && $job->queue === 'seasonvar-import',
        );
        Queue::assertPushed(
            FinalizeSeasonvarImportTitleGroup::class,
            fn (FinalizeSeasonvarImportTitleGroup $job): bool => $job->groupId === $group->id,
        );
        Queue::assertPushed(
            FinalizeSeasonvarQueuedImport::class,
            fn (FinalizeSeasonvarQueuedImport $job): bool => $job->importRunId === $run->id,
        );
    }

    public function test_it_does_not_recover_a_fresh_incomplete_dispatch(): void
    {
        [$run] = $this->activeLedgerRow(now());

        $result = app(SeasonvarActiveRunReconciler::class)->reconcile($run->id);

        $this->assertFalse($result->eligible);
        $this->assertFalse($result->dispatchRecovered);
        $this->assertSame(0, $result->jobsDispatched);
        $this->assertFalse(data_get($run->fresh()->summary, 'dispatch_completed'));
        Queue::assertNothingPushed();
    }

    public function test_due_transport_replay_is_bounded_and_immediate_retry_does_not_duplicate_attempts(): void
    {
        [$run] = $this->activeLedgerRow(now()->subHour(), 'first');
        $this->activeLedgerRow(now()->subHour(), 'second', $run);
        $this->activeLedgerRow(now()->subHour(), 'third', $run);

        $first = app(SeasonvarActiveRunReconciler::class)->reconcile($run->id);

        $this->assertSame(2, $first->jobsDispatched);
        $this->assertTrue($first->hasRemainingDueWork);
        Queue::assertPushed(PrepareSeasonvarImportTitlePage::class, 2);
        Queue::assertPushed(
            ReconcileSeasonvarQueuedImportRun::class,
            fn (ReconcileSeasonvarQueuedImportRun $job): bool => $job->importRunId === $run->id,
        );

        $second = app(SeasonvarActiveRunReconciler::class)->reconcile($run->id);

        $this->assertSame(1, $second->jobsDispatched);
        $this->assertFalse($second->hasRemainingDueWork);
        Queue::assertPushed(PrepareSeasonvarImportTitlePage::class, 3);

        $third = app(SeasonvarActiveRunReconciler::class)->reconcile($run->id);

        $this->assertSame(0, $third->jobsDispatched);
        Queue::assertPushed(PrepareSeasonvarImportTitlePage::class, 3);
    }

    public function test_recovery_reuses_one_projected_group_query_for_distinct_groups(): void
    {
        [$run] = $this->activeLedgerRow(now()->subHour(), 'first');
        $this->activeLedgerRow(now()->subHour(), 'second', $run);
        $run->update([
            'summary' => array_merge($run->summary ?? [], ['dispatch_completed' => true]),
        ]);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $result = app(SeasonvarActiveRunReconciler::class)->reconcile($run->id);
        $groupSelects = collect($queries)
            ->filter(static fn (string $sql): bool => str_starts_with(
                ltrim($sql),
                'select',
            ))
            ->filter(static fn (string $sql): bool => str_contains(
                $sql,
                'from "seasonvar_import_title_groups"',
            ));

        $this->assertSame(2, $result->jobsDispatched);
        $this->assertCount(1, $groupSelects);
    }

    public function test_exact_recovery_batch_avoids_a_second_prepared_ledger_scan_and_false_followup(): void
    {
        [$run] = $this->activeLedgerRow(now()->subHour(), 'first');
        $this->activeLedgerRow(now()->subHour(), 'second', $run);
        $run->update([
            'summary' => array_merge($run->summary ?? [], ['dispatch_completed' => true]),
        ]);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $result = app(SeasonvarActiveRunReconciler::class)->reconcile($run->id);
        $preparedLedgerSelects = collect($queries)
            ->filter(static fn (string $sql): bool => str_starts_with(
                ltrim($sql),
                'select',
            ))
            ->filter(static fn (string $sql): bool => str_contains(
                $sql,
                'from "seasonvar_import_prepared_pages"',
            ))
            ->reject(static fn (string $sql): bool => str_contains(
                $sql,
                'from "source_pages"',
            ));

        $this->assertSame(2, $result->jobsDispatched);
        $this->assertFalse($result->hasRemainingDueWork);
        $this->assertCount(1, $preparedLedgerSelects);
        Queue::assertNotPushed(ReconcileSeasonvarQueuedImportRun::class);
    }

    public function test_failed_preparation_dispatch_restores_the_due_timestamp_and_schedules_continuation(): void
    {
        [$run, , , $preparedPage] = $this->activeLedgerRow(now()->subHour());
        $run->update([
            'summary' => array_merge($run->summary ?? [], ['dispatch_completed' => true]),
        ]);
        $dispatched = [];
        $this->mock(Dispatcher::class, function (MockInterface $mock) use (&$dispatched): void {
            $mock->shouldReceive('dispatch')
                ->twice()
                ->andReturnUsing(static function (object $job) use (&$dispatched): object {
                    $dispatched[] = $job;

                    if ($job instanceof PrepareSeasonvarImportTitlePage) {
                        throw new RuntimeException('Queue transport unavailable.');
                    }

                    return $job;
                });
        });

        $result = app(SeasonvarActiveRunReconciler::class)->reconcile($run->id);

        $this->assertSame(0, $result->jobsDispatched);
        $this->assertTrue($result->hasRemainingDueWork);
        $this->assertTrue(
            $preparedPage->updated_at->equalTo($preparedPage->fresh()->updated_at),
        );
        $this->assertCount(2, $dispatched);
        $this->assertInstanceOf(PrepareSeasonvarImportTitlePage::class, $dispatched[0]);
        $this->assertInstanceOf(ReconcileSeasonvarQueuedImportRun::class, $dispatched[1]);
        $this->assertSame($run->id, $dispatched[1]->importRunId);
    }

    public function test_replaying_real_transport_work_refreshes_heartbeat_but_an_immediate_noop_does_not(): void
    {
        [$run, $group] = $this->activeLedgerRow(now()->subHour());
        $run->update([
            'summary' => array_merge($run->summary ?? [], ['dispatch_completed' => true]),
            'last_heartbeat_at' => now()->subHour(),
        ]);

        $first = app(SeasonvarActiveRunReconciler::class)->reconcile($run->id);
        $replayedHeartbeat = $run->fresh()->last_heartbeat_at;

        $this->assertFalse($first->dispatchRecovered);
        $this->assertSame(1, $first->jobsDispatched);
        $this->assertTrue($replayedHeartbeat->equalTo(now()));
        Queue::assertPushed(
            FinalizeSeasonvarImportTitleGroup::class,
            fn (FinalizeSeasonvarImportTitleGroup $job): bool => $job->groupId === $group->id,
        );
        Queue::assertPushed(
            FinalizeSeasonvarQueuedImport::class,
            fn (FinalizeSeasonvarQueuedImport $job): bool => $job->importRunId === $run->id,
        );

        $second = app(SeasonvarActiveRunReconciler::class)->reconcile($run->id);

        $this->assertSame(0, $second->jobsDispatched);
        $this->assertTrue($replayedHeartbeat->equalTo($run->fresh()->last_heartbeat_at));
    }

    public function test_it_requeues_a_due_claimed_non_serial_page_without_releasing_its_lease(): void
    {
        $run = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'force' => false,
            'selected' => 1,
            'summary' => [
                'discover' => true,
                'dispatch_completed' => false,
            ],
            'started_at' => now()->subHour(),
            'last_heartbeat_at' => now()->subHour(),
        ]);
        $page = SourcePage::factory()->create([
            'page_type' => 'rss',
        ]);
        $claim = app(SeasonvarPageClaimManager::class)->claim($page, $run->id, 7200);
        $oldAttemptAt = now()->subHour();
        DB::table($page->getTable())
            ->where('id', $page->id)
            ->update([
                'import_claimed_at' => $oldAttemptAt,
                'updated_at' => $oldAttemptAt,
            ]);

        $this->assertNotNull($claim);

        $result = app(SeasonvarActiveRunReconciler::class)->reconcile($run->id);

        $this->assertTrue($result->dispatchRecovered);
        $this->assertSame(1, $result->jobsDispatched);
        $this->assertSame($claim, $page->fresh()->import_claim_token);
        Queue::assertPushed(
            ImportSeasonvarSourcePage::class,
            fn (ImportSeasonvarSourcePage $job): bool => $job->sourcePageId === $page->id
                && $job->importRunId === $run->id
                && $job->claimToken === $claim,
        );
    }

    public function test_the_full_import_command_reconciles_a_stale_active_queued_run_before_refusing_a_sync_duplicate(): void
    {
        [$run, , , $prepared] = $this->activeLedgerRow(now()->subHour());

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->expectsOutputToContain("Восстановлена очередь активного запуска #{$run->id}: повторно поставлено задач — 1.")
            ->expectsOutputToContain(
                "Активный глобальный запуск #{$run->id} уже выполняется. Синхронный запуск не создан.",
            )
            ->assertExitCode(0);

        $this->assertTrue(data_get($run->fresh()->summary, 'dispatch_completed'));
        Queue::assertPushed(
            PrepareSeasonvarImportTitlePage::class,
            fn (PrepareSeasonvarImportTitlePage $job): bool => $job->preparedPageId === $prepared->id,
        );
    }

    public function test_the_existing_finalization_watchdog_reconciles_a_stale_active_run(): void
    {
        [$run, , , $prepared] = $this->activeLedgerRow(now()->subHour());

        $this->app->call([new WakeSeasonvarImportFinalizers, 'handle']);

        $this->assertTrue(data_get($run->fresh()->summary, 'dispatch_completed'));
        Queue::assertPushed(
            PrepareSeasonvarImportTitlePage::class,
            fn (PrepareSeasonvarImportTitlePage $job): bool => $job->preparedPageId === $prepared->id,
        );
    }

    public function test_global_stale_recovery_preserves_a_run_with_replayable_durable_work_after_claim_expiry(): void
    {
        [$run] = $this->activeLedgerRow(now()->subHour());
        $run->update([
            'summary' => array_merge($run->summary ?? [], ['dispatch_completed' => true]),
            'last_heartbeat_at' => now()->subHour(),
        ]);

        $reservation = app(SeasonvarGlobalImportRunCoordinator::class)->acquireSync(
            force: false,
            forever: false,
        );

        $this->assertFalse($reservation->created);
        $this->assertTrue($reservation->run->is($run));
        $this->assertSame('running', $run->fresh()->status);
    }

    /**
     * @return array{SeasonvarImportRun, SeasonvarImportTitleGroup, SourcePage, SeasonvarImportPreparedPage}
     */
    private function activeLedgerRow(
        \DateTimeInterface $updatedAt,
        string $suffix = 'only',
        ?SeasonvarImportRun $run = null,
    ): array {
        $run ??= SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'selected' => 1,
            'summary' => [
                'discover' => true,
                'dispatch_completed' => false,
            ],
            'started_at' => now()->subHour(),
            'last_heartbeat_at' => now()->subHour(),
        ]);
        $group = SeasonvarImportTitleGroup::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'group_key_hash' => hash('sha256', 'reconciliation-'.$suffix),
            'queue_name' => 'seasonvar-import',
            'status' => 'running',
            'expected_pages' => 1,
            'started_at' => now()->subHour(),
        ]);
        $page = SourcePage::factory()->create([
            'page_type' => 'serial',
        ]);
        $prepared = SeasonvarImportPreparedPage::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'seasonvar_import_title_group_id' => $group->id,
            'source_page_id' => $page->id,
            'status' => 'queued',
            'warnings' => [],
        ]);
        DB::table($prepared->getTable())
            ->where('id', $prepared->id)
            ->update([
                'created_at' => $updatedAt,
                'updated_at' => $updatedAt,
            ]);

        return [$run, $group, $page, $prepared->fresh()];
    }
}
