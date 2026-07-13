<?php

namespace Tests\Feature;

use App\Exceptions\Seasonvar\SeasonvarSourceRequestException;
use App\Jobs\FinalizeSeasonvarQueuedImport;
use App\Jobs\ImportSeasonvarSourcePage;
use App\Jobs\StartSeasonvarQueuedImport;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use App\Models\User;
use App\Services\Catalog\CatalogStatsSnapshotCache;
use App\Services\Seasonvar\SeasonvarCatalogImporter;
use App\Services\Seasonvar\SeasonvarImportAdminService;
use App\Services\Seasonvar\SeasonvarImportFailureClassifier;
use App\Services\Seasonvar\SeasonvarImportGroupKey;
use App\Services\Seasonvar\SeasonvarImportPipeline;
use App\Services\Seasonvar\SeasonvarImportRunRecorder;
use App\Services\Seasonvar\SeasonvarPageClaimManager;
use App\Services\Seasonvar\SeasonvarQueuedImportDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SeasonvarParallelImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('array')->flush();
    }

    public function test_parallel_import_schema_and_defaults_are_available(): void
    {
        $page = SourcePage::factory()->create([
            'import_claim_token' => 'claim-token',
            'import_claimed_at' => now(),
            'import_claim_expires_at' => now()->addHour(),
        ]);
        $run = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $page->update(['import_claim_run_id' => $run->id]);

        $freshPage = $page->fresh();

        $this->assertSame('claim-token', $freshPage->import_claim_token);
        $this->assertTrue($freshPage->import_claimed_at->equalTo($page->import_claimed_at));
        $this->assertTrue($freshPage->import_claim_expires_at->equalTo($page->import_claim_expires_at));
        $this->assertTrue($freshPage->importClaimRun->is($run));
        $this->assertSame('queue', $run->fresh()->execution_mode);
        $this->assertSame('redis', config('seasonvar.queue.connection'));
        $this->assertSame('seasonvar-import', config('seasonvar.queue.queue'));
        $this->assertSame('redis', config('seasonvar.queue.lock_store'));
        $this->assertSame(86400, config('seasonvar.queue.claim_seconds'));
        $this->assertSame(24, config('seasonvar.import.refresh_after_hours'));
        $this->assertSame('IMMEDIATE', config('database.connections.sqlite.transaction_mode'));
    }

    public function test_admin_start_is_queued_once_with_safe_scalar_job_payload(): void
    {
        config([
            'seasonvar.admin_emails' => ['admin@example.com'],
            'seasonvar.queue.lock_store' => 'array',
        ]);
        Queue::fake();
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $imports = app(SeasonvarImportAdminService::class);

        $first = $imports->start($admin, force: true, discover: false);
        $duplicate = $imports->start($admin, force: true, discover: false);

        $this->assertTrue($first->created);
        $this->assertFalse($duplicate->created);
        $this->assertTrue($duplicate->run->is($first->run));
        $this->assertSame('queued', $first->run->status);
        $this->assertSame($admin->id, $first->run->requested_by_user_id);
        $this->assertNull($first->run->started_at);
        Queue::assertPushedTimes(StartSeasonvarQueuedImport::class, 1);
        Queue::assertPushed(StartSeasonvarQueuedImport::class, function (StartSeasonvarQueuedImport $job) use ($first): bool {
            return $job->importRunId === $first->run->id
                && $job->connection === 'redis'
                && $job->queue === 'seasonvar-import'
                && ! str_contains(serialize($job), 'admin@example.com')
                && ! str_contains(serialize($job), 'seasonvar.ru/');
        });
    }

    public function test_admin_can_retry_terminal_run_cancel_active_run_and_recover_stale_run(): void
    {
        config([
            'seasonvar.admin_emails' => ['admin@example.com'],
            'seasonvar.queue.lock_store' => 'array',
            'seasonvar.queue.stale_after_minutes' => 60,
        ]);
        Queue::fake();
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $imports = app(SeasonvarImportAdminService::class);
        $failed = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'failed',
            'finished_at' => now(),
        ]);

        $retry = $imports->retry($admin, $failed->id);

        $this->assertTrue($retry->created);
        $this->assertSame($failed->id, $retry->run->retry_of_run_id);
        $retry->run->update([
            'status' => 'running',
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
        $page = SourcePage::factory()->create();
        $claims = app(SeasonvarPageClaimManager::class);
        $token = $claims->claim($page, $retry->run->id, 3600);
        $this->assertNotNull($token);

        $cancelled = $imports->cancel($admin, $retry->run->id);

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->cancel_requested_at);
        $this->assertNotNull($cancelled->finished_at);
        $this->assertFalse($claims->owns($page->id, $retry->run->id, (string) $token));

        $stale = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'started_at' => now()->subHours(2),
            'last_heartbeat_at' => now()->subHours(2),
        ]);
        $live = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'started_at' => now()->subHours(2),
            'last_heartbeat_at' => now()->subHours(2),
        ]);
        $livePage = SourcePage::factory()->create();
        $this->assertNotNull($claims->claim($livePage, $live->id, 3600));

        $this->assertSame(1, $imports->recoverStale());
        $this->assertSame('failed', $stale->fresh()->status);
        $this->assertSame('running', $live->fresh()->status);
        $this->assertStringNotContainsString('http', (string) $stale->fresh()->last_error);
    }

    public function test_coordinator_job_retries_transient_failure_and_stops_on_permanent_failure(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        $imports = app(SeasonvarImportAdminService::class);
        $classifier = app(SeasonvarImportFailureClassifier::class);
        $transientRun = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'queued',
        ]);
        $transientDispatcher = Mockery::mock(SeasonvarQueuedImportDispatcher::class);
        $transientDispatcher->shouldReceive('dispatchRun')
            ->once()
            ->andThrow(SeasonvarSourceRequestException::forStatus(503));
        $job = new StartSeasonvarQueuedImport($transientRun->id);

        try {
            $job->handle($transientDispatcher, $imports, $classifier);
            $this->fail('Transient coordinator failure must be retried.');
        } catch (SeasonvarSourceRequestException $exception) {
            $this->assertSame(503, $exception->status);
        }

        $this->assertSame('queued', $transientRun->fresh()->status);
        $this->assertSame([60, 300, 900], $job->backoff());
        $this->assertSame(3, $job->tries);
        $this->assertSame(900, $job->timeout);
        $this->assertSame('seasonvar-coordinator:'.$transientRun->id, $job->uniqueId());

        $permanentRun = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'queued',
        ]);
        $permanentDispatcher = Mockery::mock(SeasonvarQueuedImportDispatcher::class);
        $permanentDispatcher->shouldReceive('dispatchRun')
            ->once()
            ->andThrow(SeasonvarSourceRequestException::forStatus(404));

        (new StartSeasonvarQueuedImport($permanentRun->id))->handle($permanentDispatcher, $imports, $classifier);

        $this->assertSame('failed', $permanentRun->fresh()->status);
        $this->assertSame('Seasonvar вернул HTTP 404.', $permanentRun->fresh()->last_error);
    }

    public function test_cancelled_run_page_job_does_not_call_importer(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        $run = $this->queuedRun();
        $page = SourcePage::factory()->create();
        $claims = app(SeasonvarPageClaimManager::class);
        $token = $claims->claim($page, $run->id, 3600);
        $run->update(['status' => 'cancelled', 'finished_at' => now()]);
        $importer = Mockery::mock(SeasonvarCatalogImporter::class);
        $importer->shouldNotReceive('parsePages');

        (new ImportSeasonvarSourcePage(
            sourcePageId: $page->id,
            importRunId: $run->id,
            claimToken: (string) $token,
            groupKey: 'seasonvar-title:cancelled',
        ))->handle(
            $claims,
            $importer,
            app(SeasonvarImportRunRecorder::class),
            app(SeasonvarImportGroupKey::class),
        );

        $this->assertFalse($claims->owns($page->id, $run->id, (string) $token));
        $this->assertSame(0, $run->fresh()->parsed);
    }

    public function test_run_completion_status_distinguishes_success_and_partial_success(): void
    {
        $successful = $this->queuedRun();
        $partial = $this->queuedRun();
        $partial->update(['failed' => 1]);

        $this->assertSame('completed', $successful->completionStatus());
        $this->assertSame('partial', $partial->completionStatus());
    }

    public function test_page_job_retry_deadline_covers_the_longer_retry_or_claim_window(): void
    {
        $this->travelTo('2026-07-13 12:00:00');

        config([
            'seasonvar.queue.retry_window_seconds' => 21600,
            'seasonvar.queue.claim_seconds' => 86400,
        ]);

        $claimBoundJob = new ImportSeasonvarSourcePage(1, 1, 'claim-token', 'seasonvar-title:1');

        $this->assertSame(now()->addDay()->getTimestamp(), $claimBoundJob->retryUntil()->getTimestamp());

        config([
            'seasonvar.queue.retry_window_seconds' => 172800,
            'seasonvar.queue.claim_seconds' => 86400,
        ]);

        $retryBoundJob = new ImportSeasonvarSourcePage(1, 1, 'claim-token', 'seasonvar-title:1');

        $this->assertSame(now()->addDays(2)->getTimestamp(), $retryBoundJob->retryUntil()->getTimestamp());
    }

    public function test_page_claim_is_atomic_owned_and_recoverable_after_expiry(): void
    {
        $run = $this->queuedRun();
        $page = SourcePage::factory()->create();
        $claims = app(SeasonvarPageClaimManager::class);

        $token = $claims->claim($page, $run->id, 60);

        $this->assertNotNull($token);
        $this->assertTrue($claims->owns($page->id, $run->id, $token));
        $this->assertNull($claims->claim($page, $run->id, 60));
        $this->assertFalse($claims->release($page->id, $run->id, 'wrong-token'));

        $page->update(['import_claim_expires_at' => now()->subSecond()]);

        $this->assertSame(1, $claims->recoverExpired());
        $this->assertSame(0, $claims->outstandingForRun($run->id));
        $this->assertNotNull($claims->claim($page->fresh(), $run->id, 60));
    }

    public function test_import_group_key_groups_seasons_by_canonical_title_slug(): void
    {
        $keys = app(SeasonvarImportGroupKey::class);

        $this->assertSame(
            $keys->forUrl('https://seasonvar.ru/serial-14979-Sinij_ekzortcist_psftqae-2-season.html', 'hash-a'),
            $keys->forUrl('https://seasonvar.ru/serial-42722-Sinij_ekzortcist_pslwzbv-5-season.html', 'hash-b'),
        );
        $this->assertSame(
            $keys->forUrl('https://seasonvar.ru/serial-3177--Sinij_ekzortcist_psbdtjm.html', 'hash-first'),
            $keys->forUrl('https://seasonvar.ru/serial-42722-Sinij_ekzortcist_pslwzbv-5-season.html', 'hash-b'),
        );
        $this->assertNotSame(
            $keys->forUrl('https://seasonvar.ru/serial-42722-Sinij_ekzortcist_pslwzbv-5-season.html', 'hash-b'),
            $keys->forUrl('https://seasonvar.ru/serial-10532-Po_dolgu_sluzhby_pssxanb-2-season.html', 'hash-c'),
        );
        $this->assertSame(
            'seasonvar-page:hash-d',
            $keys->forUrl('https://seasonvar.ru/catalog/test.html', 'hash-d'),
        );
    }

    public function test_import_group_key_groups_legacy_season_urls_without_ps_suffix(): void
    {
        $keys = app(SeasonvarImportGroupKey::class);

        $this->assertSame(
            $keys->forUrl('https://seasonvar.ru/serial-608--25_cheloveka-006-sezon.html', 'hash-a'),
            $keys->forUrl('https://seasonvar.ru/serial-726--25_cheloveka-007-sezon.html', 'hash-b'),
        );
        $this->assertSame(
            $keys->forUrl('https://seasonvar.ru/serial-12776-Igra_s_ognyem_1-season.html', 'hash-c'),
            $keys->forUrl('https://seasonvar.ru/serial-99999-Igra_s_ognyem_2-season.html', 'hash-d'),
        );
    }

    public function test_worker_with_wrong_claim_token_does_not_request_source(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        Http::preventStrayRequests();
        Http::fake();
        $run = $this->queuedRun();
        $page = SourcePage::factory()->create();
        $importer = Mockery::mock(SeasonvarCatalogImporter::class);
        $importer->shouldNotReceive('parsePages');

        (new ImportSeasonvarSourcePage(
            sourcePageId: $page->id,
            importRunId: $run->id,
            claimToken: 'wrong-token',
            groupKey: 'seasonvar-title:1',
        ))->handle(
            app(SeasonvarPageClaimManager::class),
            $importer,
            app(SeasonvarImportRunRecorder::class),
            app(SeasonvarImportGroupKey::class),
        );

        Http::assertNothingSent();
        $this->assertSame(0, $run->fresh()->parsed);
    }

    public function test_worker_with_live_claim_processes_one_page_and_releases_it(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        $run = $this->queuedRun();
        $page = SourcePage::factory()->create();
        $claims = app(SeasonvarPageClaimManager::class);
        $token = $claims->claim($page, $run->id, 3600);
        $importer = Mockery::mock(SeasonvarCatalogImporter::class);
        $receivedRetryTransient = null;
        $importer->shouldReceive('parsePages')
            ->once()
            ->andReturnUsing(function (...$arguments) use (&$receivedRetryTransient, $page, $run): array {
                $this->assertSame([$page->id], $arguments[0]->pluck('id')->all());
                $this->assertNull($arguments[1]);
                $this->assertFalse($arguments[2]);
                $this->assertSame($run->id, $arguments[3]);
                $receivedRetryTransient = $arguments[4] ?? null;

                return [
                    'parsed' => 1,
                    'failed' => 0,
                    'media_attached' => 2,
                    'media_updated' => 1,
                    'media_skipped' => 0,
                    'media_failed' => 0,
                    'failures' => [],
                ];
            });

        (new ImportSeasonvarSourcePage(
            sourcePageId: $page->id,
            importRunId: $run->id,
            claimToken: (string) $token,
            groupKey: 'seasonvar-title:1',
        ))->handle(
            $claims,
            $importer,
            app(SeasonvarImportRunRecorder::class),
            app(SeasonvarImportGroupKey::class),
        );

        $freshRun = $run->fresh();
        $freshPage = $page->fresh();

        $this->assertSame(0, $freshRun->selected);
        $this->assertSame(1, $freshRun->parsed);
        $this->assertSame(2, $freshRun->media_attached);
        $this->assertSame(1, $freshRun->media_updated);
        $this->assertTrue($receivedRetryTransient);
        $this->assertNull($freshPage->import_claim_token);
        $this->assertNull($freshPage->import_claim_run_id);
    }

    public function test_failed_worker_releases_its_claim_and_counts_failure_once(): void
    {
        $run = $this->queuedRun();
        $page = SourcePage::factory()->create();
        $claims = app(SeasonvarPageClaimManager::class);
        $token = $claims->claim($page, $run->id, 3600);
        $job = new ImportSeasonvarSourcePage(
            sourcePageId: $page->id,
            importRunId: $run->id,
            claimToken: (string) $token,
            groupKey: 'seasonvar-title:1',
        );

        $job->failed(new RuntimeException('Retry window exhausted.'));
        $job->failed(new RuntimeException('Duplicate failure callback.'));

        $this->assertFalse($claims->owns($page->id, $run->id, (string) $token));
        $this->assertSame(1, $run->fresh()->failed);
    }

    public function test_worker_releases_itself_when_title_lock_is_held(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        $run = $this->queuedRun();
        $page = SourcePage::factory()->create();
        $claims = app(SeasonvarPageClaimManager::class);
        $token = $claims->claim($page, $run->id, 3600);
        $groupKey = app(SeasonvarImportGroupKey::class)->forUrl($page->url, $page->url_hash);
        $lock = Cache::store('array')->lock($groupKey, 1200);
        $this->assertTrue($lock->get());
        $importer = Mockery::mock(SeasonvarCatalogImporter::class);
        $importer->shouldNotReceive('parsePages');

        try {
            $job = (new ImportSeasonvarSourcePage(
                sourcePageId: $page->id,
                importRunId: $run->id,
                claimToken: (string) $token,
                groupKey: $groupKey,
            ))->withFakeQueueInteractions();

            $job->handle(
                $claims,
                $importer,
                app(SeasonvarImportRunRecorder::class),
                app(SeasonvarImportGroupKey::class),
            );

            $job->assertReleased(delay: 30);
            $this->assertTrue($claims->owns($page->id, $run->id, (string) $token));
        } finally {
            $lock->release();
        }
    }

    public function test_worker_recomputes_group_key_from_source_page_for_queued_payloads(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        $run = $this->queuedRun();
        $page = SourcePage::factory()->create([
            'url' => 'https://seasonvar.ru/serial-42722-Sinij_ekzortcist_pslwzbv-5-season.html',
        ]);
        $claims = app(SeasonvarPageClaimManager::class);
        $token = $claims->claim($page, $run->id, 3600);
        $canonicalKey = app(SeasonvarImportGroupKey::class)->forUrl($page->url, $page->url_hash);
        $lock = Cache::store('array')->lock($canonicalKey, 1200);
        $this->assertTrue($lock->get());
        $importer = Mockery::mock(SeasonvarCatalogImporter::class);
        $importer->shouldNotReceive('parsePages');
        $this->app->instance(SeasonvarCatalogImporter::class, $importer);

        try {
            $job = (new ImportSeasonvarSourcePage(
                sourcePageId: $page->id,
                importRunId: $run->id,
                claimToken: (string) $token,
                groupKey: 'seasonvar-title:42722',
            ))->withFakeQueueInteractions();

            $this->app->call([$job, 'handle']);

            $job->assertReleased(delay: 30);
            $this->assertTrue($claims->owns($page->id, $run->id, (string) $token));
        } finally {
            $lock->release();
        }
    }

    public function test_dispatcher_queues_each_eligible_page_once_across_repeated_runs(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        Queue::fake();
        $pages = SourcePage::factory()->count(2)->create([
            'page_type' => 'serial',
            'parse_status' => 'pending',
            'import_status' => 'pending',
        ]);

        $run = app(SeasonvarQueuedImportDispatcher::class)->dispatch(discover: false);
        $secondRun = app(SeasonvarQueuedImportDispatcher::class)->dispatch(discover: false);

        Queue::assertPushedOn('seasonvar-import', ImportSeasonvarSourcePage::class);
        Queue::assertPushed(
            ImportSeasonvarSourcePage::class,
            fn (ImportSeasonvarSourcePage $job): bool => $job->afterCommit === true,
        );
        Queue::assertPushedTimes(ImportSeasonvarSourcePage::class, 2);
        Queue::assertPushed(
            FinalizeSeasonvarQueuedImport::class,
            fn (FinalizeSeasonvarQueuedImport $job): bool => $job->afterCommit === true,
        );
        $this->assertSame(2, $run->fresh()->selected);
        $this->assertSame(2, SourcePage::query()->where('import_claim_run_id', $run->id)->count());
        $this->assertSame('completed', $secondRun->fresh()->status);
        $this->assertEqualsCanonicalizing(
            $pages->modelKeys(),
            SourcePage::query()->where('import_claim_run_id', $run->id)->pluck('id')->all(),
        );
    }

    public function test_dispatcher_recovers_expired_claim_before_queuing_page(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        Queue::fake();
        $oldRun = $this->queuedRun();
        $page = SourcePage::factory()->create([
            'page_type' => 'serial',
            'parse_status' => 'pending',
        ]);
        $claims = app(SeasonvarPageClaimManager::class);
        $this->assertNotNull($claims->claim($page, $oldRun->id, 60));
        $page->update(['import_claim_expires_at' => now()->subSecond()]);

        $run = app(SeasonvarQueuedImportDispatcher::class)->dispatch(discover: false);

        Queue::assertPushed(ImportSeasonvarSourcePage::class, fn (ImportSeasonvarSourcePage $job): bool => $job->sourcePageId === $page->id
            && $job->importRunId === $run->id);
        $this->assertSame($run->id, $page->fresh()->import_claim_run_id);
    }

    public function test_finalizer_waits_while_run_has_live_claims(): void
    {
        $run = $this->queuedRun();
        $page = SourcePage::factory()->create();
        $claims = app(SeasonvarPageClaimManager::class);
        $this->assertNotNull($claims->claim($page, $run->id, 3600));
        $pipeline = Mockery::mock(SeasonvarImportPipeline::class);
        $pipeline->shouldNotReceive('finalizeQueuedRun');
        $stats = Mockery::mock(CatalogStatsSnapshotCache::class);
        $stats->shouldNotReceive('refresh');
        $job = (new FinalizeSeasonvarQueuedImport($run->id))->withFakeQueueInteractions();

        $job->handle($claims, $pipeline, $stats, app(SeasonvarImportRunRecorder::class));

        $job->assertReleased(delay: 60);
    }

    public function test_finalizer_completes_run_after_all_claims_are_released(): void
    {
        $run = $this->queuedRun();
        $completed = $run->replicate();
        $completed->id = $run->id;
        $completed->exists = true;
        $completed->status = 'completed';
        $pipeline = Mockery::mock(SeasonvarImportPipeline::class);
        $pipeline->shouldReceive('finalizeQueuedRun')->once()->withArgs(
            fn (SeasonvarImportRun $candidate): bool => $candidate->is($run),
        )->andReturn($completed);
        $stats = Mockery::mock(CatalogStatsSnapshotCache::class);
        $stats->shouldReceive('refresh')->once();

        (new FinalizeSeasonvarQueuedImport($run->id))->handle(
            app(SeasonvarPageClaimManager::class),
            $pipeline,
            $stats,
            app(SeasonvarImportRunRecorder::class),
        );

        $this->assertTrue(true);
    }

    public function test_queued_command_dispatches_pages_without_discovery(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        $run = $this->queuedRun();
        $run->selected = 2;
        $run->save();
        $dispatcher = Mockery::mock(SeasonvarQueuedImportDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(false, false)
            ->andReturn($run);
        $this->app->instance(SeasonvarQueuedImportDispatcher::class, $dispatcher);

        $this->artisan('seasonvar:import', [
            '--queued' => true,
            '--no-discovery' => true,
        ])
            ->expectsOutputToContain('поставлено в очередь: 2')
            ->assertExitCode(0);
    }

    public function test_queued_mode_rejects_sync_only_options(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        $dispatcher = Mockery::mock(SeasonvarQueuedImportDispatcher::class);
        $dispatcher->shouldNotReceive('dispatch');
        $this->app->instance(SeasonvarQueuedImportDispatcher::class, $dispatcher);

        $this->artisan('seasonvar:import', [
            'url' => 'https://seasonvar.ru/serial-1-Test-1-season.html',
            '--queued' => true,
        ])->assertExitCode(1);
        $this->artisan('seasonvar:import', [
            '--queued' => true,
            '--forever' => true,
        ])->assertExitCode(1);
        $this->artisan('seasonvar:import', [
            '--queued' => true,
            '--sleep' => 30,
        ])->assertExitCode(1);
    }

    public function test_queued_command_skips_when_coordinator_lock_is_held(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        $dispatcher = Mockery::mock(SeasonvarQueuedImportDispatcher::class);
        $dispatcher->shouldNotReceive('dispatch');
        $this->app->instance(SeasonvarQueuedImportDispatcher::class, $dispatcher);
        $lock = Cache::store('array')->lock('seasonvar-import-coordinator', 300);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('seasonvar:import', ['--queued' => true])
                ->expectsOutputToContain('Диспетчер уже запущен')
                ->assertExitCode(0);
        } finally {
            $lock->release();
        }
    }

    private function queuedRun(): SeasonvarImportRun
    {
        return SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'started_at' => now(),
        ]);
    }
}
