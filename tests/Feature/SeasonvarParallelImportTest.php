<?php

namespace Tests\Feature;

use App\DTOs\Seasonvar\SeasonvarImportStartResultData;
use App\Exceptions\Seasonvar\SeasonvarSourceRequestException;
use App\Jobs\FinalizeSeasonvarImportTitleGroup;
use App\Jobs\FinalizeSeasonvarQueuedImport;
use App\Jobs\ImportSeasonvarSourcePage;
use App\Jobs\PrepareSeasonvarImportTitlePage;
use App\Jobs\StartSeasonvarQueuedImport;
use App\Jobs\WakeSeasonvarImportFinalizers;
use App\Models\LicensedMedia;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\SeasonvarImportRun;
use App\Models\Source;
use App\Models\SourcePage;
use App\Models\User;
use App\Services\Catalog\CatalogCacheInvalidator;
use App\Services\Seasonvar\SeasonvarCatalogImporter;
use App\Services\Seasonvar\SeasonvarGlobalImportRunCoordinator;
use App\Services\Seasonvar\SeasonvarImportAdminService;
use App\Services\Seasonvar\SeasonvarImportFailureClassifier;
use App\Services\Seasonvar\SeasonvarImportGroupKey;
use App\Services\Seasonvar\SeasonvarImportPipeline;
use App\Services\Seasonvar\SeasonvarImportRunRecorder;
use App\Services\Seasonvar\SeasonvarImportTitleGroupDispatcher;
use App\Services\Seasonvar\SeasonvarPageClaimManager;
use App\Services\Seasonvar\SeasonvarQueuedImportDispatcher;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        $this->assertSame('redis-locks', config('seasonvar.queue.lock_store'));
        $this->assertSame(86400, config('seasonvar.queue.claim_seconds'));
        $this->assertSame(24, config('seasonvar.import.refresh_after_hours'));
        $this->assertSame('IMMEDIATE', config('database.connections.sqlite.transaction_mode'));
        $this->assertInstanceOf(
            ShouldBeUniqueUntilProcessing::class,
            new FinalizeSeasonvarImportTitleGroup(1),
        );
        $this->assertInstanceOf(
            ShouldBeUniqueUntilProcessing::class,
            new FinalizeSeasonvarQueuedImport(1),
        );
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

    public function test_admin_and_cli_dispatcher_share_one_global_run_boundary(): void
    {
        config([
            'seasonvar.admin_emails' => ['admin@example.com'],
            'seasonvar.queue.lock_store' => 'array',
        ]);
        Queue::fake();
        $admin = User::factory()->create(['email' => 'admin@example.com']);

        $adminStart = app(SeasonvarImportAdminService::class)->start($admin, discover: false);
        $cliStart = app(SeasonvarQueuedImportDispatcher::class)->dispatch(discover: false);

        $this->assertTrue($adminStart->created);
        $this->assertFalse($cliStart->created);
        $this->assertTrue($cliStart->run->is($adminStart->run));
        $this->assertSame(1, SeasonvarImportRun::query()
            ->where('mode', 'sitemap')
            ->where('execution_mode', 'queue')
            ->whereIn('status', ['queued', 'running'])
            ->count());
        Queue::assertPushedTimes(StartSeasonvarQueuedImport::class, 1);
        Queue::assertNotPushed(PrepareSeasonvarImportTitlePage::class);
    }

    public function test_admin_start_marks_the_run_failed_when_coordinator_dispatch_is_rejected(): void
    {
        config([
            'seasonvar.admin_emails' => ['admin@example.com'],
            'seasonvar.queue.lock_store' => 'array',
        ]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $bus = $this->createMock(BusDispatcher::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willThrowException(new RuntimeException(
                'token=private-token https://seasonvar.ru/private',
            ));
        $this->app->instance(BusDispatcher::class, $bus);

        try {
            app(SeasonvarImportAdminService::class)->start($admin, discover: false);
            $this->fail('A rejected coordinator dispatch must remain visible to the caller.');
        } catch (RuntimeException) {
            $run = SeasonvarImportRun::query()->sole();

            $this->assertSame('failed', $run->status);
            $this->assertNotNull($run->finished_at);
            $this->assertStringNotContainsString('private-token', (string) $run->last_error);
            $this->assertStringNotContainsString('seasonvar.ru', (string) $run->last_error);
        }
    }

    public function test_targeted_title_refresh_does_not_block_a_global_import_run(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        $targeted = SeasonvarImportRun::query()->create([
            'mode' => 'url',
            'execution_mode' => 'queue',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $global = app(SeasonvarGlobalImportRunCoordinator::class)->acquire(
            force: false,
            discover: false,
        );

        $this->assertTrue($global->created);
        $this->assertFalse($global->run->is($targeted));
        $this->assertSame('sitemap', $global->run->mode);
        $this->assertSame('queued', $global->run->status);
    }

    public function test_import_dashboard_uses_bounded_aggregate_queries(): void
    {
        $requester = User::factory()->create();
        SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'queued',
            'requested_by_user_id' => $requester->id,
            'last_heartbeat_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'health_status' => 'degraded',
            'next_check_at' => now()->subMinute(),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $dashboard = app(SeasonvarImportAdminService::class)->dashboard();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(5, $queryCount);
        $this->assertTrue($dashboard['has_active_run']);
        $this->assertSame(1, $dashboard['media_due_count']);
        $this->assertSame(1, collect($dashboard['media_health'])->firstWhere('status', 'degraded')['count']);
        $this->assertSame($requester->name, $dashboard['runs'][0]['requested_by']);
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
        (new ImportSeasonvarSourcePage(
            sourcePageId: $page->id,
            importRunId: $run->id,
            claimToken: (string) $token,
            groupKey: 'seasonvar-title:cancelled',
        ))->handle(
            $claims,
            app(SeasonvarImportTitleGroupDispatcher::class),
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

    public function test_page_and_title_group_job_deadlines_cover_the_longer_retry_or_claim_window(): void
    {
        $this->travelTo('2026-07-13 12:00:00');

        config([
            'seasonvar.queue.retry_window_seconds' => 21600,
            'seasonvar.queue.claim_seconds' => 86400,
        ]);

        $claimBoundJob = new ImportSeasonvarSourcePage(1, 1, 'claim-token', 'seasonvar-title:1');
        $claimBoundPreparation = new PrepareSeasonvarImportTitlePage(1);
        $claimBoundFinalizer = new FinalizeSeasonvarImportTitleGroup(1);

        $this->assertSame(now()->addDay()->getTimestamp(), $claimBoundJob->retryUntil()->getTimestamp());
        $this->assertSame(now()->addDay()->getTimestamp(), $claimBoundPreparation->retryUntil()->getTimestamp());
        $this->assertSame(86400, $claimBoundPreparation->uniqueFor);
        $this->assertSame(now()->addDay()->getTimestamp(), $claimBoundFinalizer->retryUntil()->getTimestamp());
        $this->assertSame(86700, $claimBoundFinalizer->uniqueFor);

        config([
            'seasonvar.queue.retry_window_seconds' => 172800,
            'seasonvar.queue.claim_seconds' => 86400,
        ]);

        $retryBoundJob = new ImportSeasonvarSourcePage(1, 1, 'claim-token', 'seasonvar-title:1');
        $retryBoundPreparation = new PrepareSeasonvarImportTitlePage(1);
        $retryBoundFinalizer = new FinalizeSeasonvarImportTitleGroup(1);

        $this->assertSame(now()->addDays(2)->getTimestamp(), $retryBoundJob->retryUntil()->getTimestamp());
        $this->assertSame(now()->addDays(2)->getTimestamp(), $retryBoundPreparation->retryUntil()->getTimestamp());
        $this->assertSame(172800, $retryBoundPreparation->uniqueFor);
        $this->assertSame(now()->addDays(2)->getTimestamp(), $retryBoundFinalizer->retryUntil()->getTimestamp());
        $this->assertSame(173100, $retryBoundFinalizer->uniqueFor);
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
        (new ImportSeasonvarSourcePage(
            sourcePageId: $page->id,
            importRunId: $run->id,
            claimToken: 'wrong-token',
            groupKey: 'seasonvar-title:1',
        ))->handle(
            app(SeasonvarPageClaimManager::class),
            app(SeasonvarImportTitleGroupDispatcher::class),
        );

        Http::assertNothingSent();
        $this->assertSame(0, $run->fresh()->parsed);
    }

    public function test_worker_with_live_claim_processes_one_page_and_releases_it(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        Queue::fake();
        $run = $this->queuedRun();
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
        ]);
        $url = 'https://seasonvar.ru/serial-42-Test-1-season.html';
        $page = SourcePage::factory()->for($source)->create([
            'url' => $url,
            'url_hash' => hash('sha256', $url),
        ]);
        $claims = app(SeasonvarPageClaimManager::class);
        $token = $claims->claim($page, $run->id, 3600);

        (new ImportSeasonvarSourcePage(
            sourcePageId: $page->id,
            importRunId: $run->id,
            claimToken: (string) $token,
            groupKey: 'seasonvar-title:1',
        ))->handle(
            $claims,
            app(SeasonvarImportTitleGroupDispatcher::class),
        );

        $freshRun = $run->fresh();
        $freshPage = $page->fresh();

        $this->assertSame(1, $freshRun->selected);
        $this->assertSame(0, $freshRun->parsed);
        $this->assertSame(1, $run->preparedPages()->count());
        $this->assertSame($token, $freshPage->import_claim_token);
        $this->assertSame($run->id, $freshPage->import_claim_run_id);
        Queue::assertPushed(PrepareSeasonvarImportTitlePage::class, 1);
        Queue::assertPushed(FinalizeSeasonvarImportTitleGroup::class, 1);
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

    public function test_legacy_worker_delegates_even_when_the_old_title_lock_is_held(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        Queue::fake();
        $run = $this->queuedRun();
        $source = Source::factory()->create(['code' => 'seasonvar']);
        $url = 'https://seasonvar.ru/serial-42-Test-1-season.html';
        $page = SourcePage::factory()->for($source)->create([
            'url' => $url,
            'url_hash' => hash('sha256', $url),
        ]);
        $claims = app(SeasonvarPageClaimManager::class);
        $token = $claims->claim($page, $run->id, 3600);
        $groupKey = app(SeasonvarImportGroupKey::class)->forUrl($page->url, $page->url_hash);
        $lock = Cache::store('array')->lock($groupKey, 1200);
        $this->assertTrue($lock->get());
        try {
            $job = (new ImportSeasonvarSourcePage(
                sourcePageId: $page->id,
                importRunId: $run->id,
                claimToken: (string) $token,
                groupKey: $groupKey,
            ))->withFakeQueueInteractions();

            $job->handle(
                $claims,
                app(SeasonvarImportTitleGroupDispatcher::class),
            );

            $job->assertNotReleased();
            $this->assertTrue($claims->owns($page->id, $run->id, (string) $token));
            $this->assertSame(1, $run->preparedPages()->count());
        } finally {
            $lock->release();
        }
    }

    public function test_worker_recomputes_group_key_from_source_page_for_queued_payloads(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        Queue::fake();
        $run = $this->queuedRun();
        $source = Source::factory()->create(['code' => 'seasonvar']);
        $page = SourcePage::factory()->for($source)->create([
            'url' => 'https://seasonvar.ru/serial-42722-Sinij_ekzortcist_pslwzbv-5-season.html',
            'url_hash' => hash('sha256', 'https://seasonvar.ru/serial-42722-Sinij_ekzortcist_pslwzbv-5-season.html'),
        ]);
        $claims = app(SeasonvarPageClaimManager::class);
        $token = $claims->claim($page, $run->id, 3600);
        $canonicalKey = app(SeasonvarImportGroupKey::class)->forUrl($page->url, $page->url_hash);
        $job = (new ImportSeasonvarSourcePage(
            sourcePageId: $page->id,
            importRunId: $run->id,
            claimToken: (string) $token,
            groupKey: 'seasonvar-title:42722',
        ))->withFakeQueueInteractions();

        $this->app->call([$job, 'handle']);

        $job->assertNotReleased();
        $this->assertDatabaseHas('seasonvar_import_title_groups', [
            'seasonvar_import_run_id' => $run->id,
            'group_key_hash' => hash('sha256', $canonicalKey),
        ]);
        $this->assertTrue($claims->owns($page->id, $run->id, (string) $token));
    }

    public function test_dispatcher_queues_each_eligible_page_once_across_repeated_runs(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        Queue::fake();
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $pages = collect([1, 2])->map(function (int $season) use ($source): SourcePage {
            $url = "https://seasonvar.ru/serial-24212-Ryzhaya_psbdtie-{$season}-season.html";

            return SourcePage::factory()->for($source)->create([
                'url' => $url,
                'url_hash' => hash('sha256', $url),
                'page_type' => 'serial',
                'parse_status' => 'pending',
                'import_status' => 'pending',
            ]);
        });

        $started = app(SeasonvarQueuedImportDispatcher::class)->dispatch(discover: false);
        $duplicate = app(SeasonvarQueuedImportDispatcher::class)->dispatch(discover: false);
        $run = $started->run;

        Queue::assertPushedOn('seasonvar-import', PrepareSeasonvarImportTitlePage::class);
        Queue::assertPushed(
            PrepareSeasonvarImportTitlePage::class,
            fn (PrepareSeasonvarImportTitlePage $job): bool => $job->afterCommit === true,
        );
        Queue::assertPushedTimes(PrepareSeasonvarImportTitlePage::class, 2);
        Queue::assertPushedTimes(FinalizeSeasonvarImportTitleGroup::class, 1);
        Queue::assertNotPushed(ImportSeasonvarSourcePage::class);
        Queue::assertPushed(
            FinalizeSeasonvarQueuedImport::class,
            fn (FinalizeSeasonvarQueuedImport $job): bool => $job->afterCommit === true,
        );
        Queue::assertPushedTimes(WakeSeasonvarImportFinalizers::class, 1);
        $this->assertSame(2, $run->fresh()->selected);
        $this->assertSame(1, $run->titleGroups()->count());
        $this->assertSame(2, $run->preparedPages()->count());
        $this->assertSame(2, SourcePage::query()->where('import_claim_run_id', $run->id)->count());
        $this->assertTrue($started->created);
        $this->assertFalse($duplicate->created);
        $this->assertTrue($duplicate->run->is($run));
        $this->assertSame(1, SeasonvarImportRun::query()
            ->where('mode', 'sitemap')
            ->where('execution_mode', 'queue')
            ->whereIn('status', ['queued', 'running'])
            ->count());
        $this->assertEqualsCanonicalizing(
            $pages->pluck('id')->all(),
            SourcePage::query()->where('import_claim_run_id', $run->id)->pluck('id')->all(),
        );
    }

    public function test_dispatcher_keeps_non_serial_handlers_on_the_legacy_page_job(): void
    {
        config([
            'seasonvar.queue.lock_store' => 'array',
            'seasonvar.page_types.actor.enabled' => true,
            'seasonvar.page_types.actor.automatic' => true,
        ]);
        Queue::fake();
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $url = 'https://seasonvar.ru/actor/1001-aleksandr-ivanov';
        SourcePage::factory()->for($source)->create([
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'actor',
            'parse_status' => 'pending',
            'import_status' => 'pending',
        ]);

        $run = app(SeasonvarQueuedImportDispatcher::class)->dispatch(
            discover: false,
            pageTypes: ['actor'],
        )->run;

        Queue::assertPushedTimes(ImportSeasonvarSourcePage::class, 1);
        Queue::assertNotPushed(PrepareSeasonvarImportTitlePage::class);
        $this->assertSame(1, $run->fresh()->selected);
        $this->assertSame(0, $run->titleGroups()->count());
    }

    public function test_legacy_page_job_processes_non_serial_handlers_without_a_title_group(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        Queue::fake();
        $run = $this->queuedRun();
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
        ]);
        $url = 'https://seasonvar.ru/actor/1001-aleksandr-ivanov';
        $page = SourcePage::factory()->for($source)->create([
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'actor',
            'parse_status' => 'pending',
        ]);
        $claims = app(SeasonvarPageClaimManager::class);
        $token = $claims->claim($page, $run->id, 3600);
        $importer = Mockery::mock(SeasonvarCatalogImporter::class);
        $importer->shouldReceive('parsePages')
            ->once()
            ->withArgs(fn ($pages, $unused, $force, $runId, $retryTransient): bool => $pages->pluck('id')->all() === [$page->id]
                && $unused === null
                && $force === false
                && $runId === $run->id
                && $retryTransient === true)
            ->andReturn([
                'parsed' => 1,
                'failed' => 0,
                'media_attached' => 0,
                'media_updated' => 0,
                'media_skipped' => 0,
                'media_failed' => 0,
                'failures' => [],
            ]);

        (new ImportSeasonvarSourcePage(
            sourcePageId: $page->id,
            importRunId: $run->id,
            claimToken: (string) $token,
            groupKey: 'untrusted-payload-key',
        ))->handle(
            $claims,
            app(SeasonvarImportTitleGroupDispatcher::class),
            $importer,
            app(SeasonvarImportRunRecorder::class),
            app(SeasonvarImportGroupKey::class),
        );

        $this->assertSame(1, $run->fresh()->parsed);
        $this->assertFalse($claims->owns($page->id, $run->id, (string) $token));
        $this->assertSame(0, $run->titleGroups()->count());
        Queue::assertPushed(
            FinalizeSeasonvarQueuedImport::class,
            fn (FinalizeSeasonvarQueuedImport $job): bool => $job->importRunId === $run->id,
        );
    }

    public function test_dispatcher_recovers_expired_claim_before_queuing_page(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        Queue::fake();
        $oldRun = $this->queuedRun();
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
        ]);
        $url = 'https://seasonvar.ru/serial-24212-Ryzhaya_psbdtie-1-season.html';
        $page = SourcePage::factory()->for($source)->create([
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'parse_status' => 'pending',
        ]);
        $claims = app(SeasonvarPageClaimManager::class);
        $this->assertNotNull($claims->claim($page, $oldRun->id, 60));
        $page->update(['import_claim_expires_at' => now()->subSecond()]);
        $oldRun->update(['status' => 'failed', 'finished_at' => now()]);

        $run = app(SeasonvarQueuedImportDispatcher::class)->dispatch(discover: false)->run;

        Queue::assertPushed(PrepareSeasonvarImportTitlePage::class, function (PrepareSeasonvarImportTitlePage $job) use ($page): bool {
            return SeasonvarImportPreparedPage::query()->find($job->preparedPageId)?->source_page_id === $page->id;
        });
        $this->assertSame($run->id, $page->fresh()->import_claim_run_id);
    }

    public function test_global_finalizer_returns_without_polling_while_a_title_group_is_nonterminal(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        $run = $this->queuedRun();
        $run->titleGroups()->create([
            'catalog_title_id' => null,
            'group_key_hash' => hash('sha256', 'family'),
            'queue_name' => 'seasonvar-import',
            'status' => 'running',
        ]);
        $pipeline = Mockery::mock(SeasonvarImportPipeline::class);
        $pipeline->shouldNotReceive('finalizeQueuedRun');
        $job = (new FinalizeSeasonvarQueuedImport($run->id))->withFakeQueueInteractions();

        $job->handle(
            app(SeasonvarPageClaimManager::class),
            $pipeline,
            app(SeasonvarImportRunRecorder::class),
            app(CatalogCacheInvalidator::class),
        );

        $job->assertNotReleased();
    }

    public function test_global_finalizer_returns_without_polling_while_run_has_live_claims(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        $run = $this->queuedRun();
        $page = SourcePage::factory()->create();
        $claims = app(SeasonvarPageClaimManager::class);
        $this->assertNotNull($claims->claim($page, $run->id, 3600));
        $pipeline = Mockery::mock(SeasonvarImportPipeline::class);
        $pipeline->shouldNotReceive('finalizeQueuedRun');
        $job = (new FinalizeSeasonvarQueuedImport($run->id))->withFakeQueueInteractions();

        $job->handle(
            $claims,
            $pipeline,
            app(SeasonvarImportRunRecorder::class),
            app(CatalogCacheInvalidator::class),
        );

        $job->assertNotReleased();
    }

    public function test_finalizer_waits_while_another_catalog_finalization_holds_the_global_lock(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        $run = $this->queuedRun();
        $lock = Cache::store('array')->lock(FinalizeSeasonvarQueuedImport::GLOBAL_LOCK_KEY, 1200);
        $this->assertTrue($lock->get());
        $pipeline = Mockery::mock(SeasonvarImportPipeline::class);
        $pipeline->shouldNotReceive('finalizeQueuedRun');
        $job = (new FinalizeSeasonvarQueuedImport($run->id))->withFakeQueueInteractions();

        try {
            $job->handle(
                app(SeasonvarPageClaimManager::class),
                $pipeline,
                app(SeasonvarImportRunRecorder::class),
                app(CatalogCacheInvalidator::class),
            );

            $job->assertReleased(delay: 60);
        } finally {
            $lock->release();
        }
    }

    public function test_finalizer_completes_run_after_all_claims_are_released(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        $run = $this->queuedRun();
        $completed = $run->replicate();
        $completed->id = $run->id;
        $completed->exists = true;
        $completed->status = 'completed';
        $pipeline = Mockery::mock(SeasonvarImportPipeline::class);
        $pipeline->shouldReceive('finalizeQueuedRun')->once()->withArgs(
            fn (SeasonvarImportRun $candidate): bool => $candidate->is($run),
        )->andReturn($completed);
        (new FinalizeSeasonvarQueuedImport($run->id))->handle(
            app(SeasonvarPageClaimManager::class),
            $pipeline,
            app(SeasonvarImportRunRecorder::class),
            app(CatalogCacheInvalidator::class),
        );

        $releasedLock = Cache::store('array')->lock(FinalizeSeasonvarQueuedImport::GLOBAL_LOCK_KEY, 1200);
        $this->assertTrue($releasedLock->get());
        $releasedLock->release();
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
            ->andReturn(new SeasonvarImportStartResultData($run, true));
        $this->app->instance(SeasonvarQueuedImportDispatcher::class, $dispatcher);

        $this->artisan('seasonvar:import', [
            '--queued' => true,
            '--no-discovery' => true,
        ])
            ->expectsOutputToContain('поставлено в очередь: 2')
            ->assertExitCode(0);
    }

    public function test_queued_command_explains_that_an_active_global_run_was_reused(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        $run = $this->queuedRun();
        $dispatcher = Mockery::mock(SeasonvarQueuedImportDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(false, false)
            ->andReturn(new SeasonvarImportStartResultData($run, false));
        $this->app->instance(SeasonvarQueuedImportDispatcher::class, $dispatcher);

        $this->artisan('seasonvar:import', [
            '--queued' => true,
            '--no-discovery' => true,
        ])
            ->expectsOutputToContain(
                "Активный глобальный запуск #{$run->id} уже имеет статус «Выполняется». Новый запуск не создан.",
            )
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

    public function test_systemd_worker_has_php_memory_headroom_and_restarts_after_lifecycle_exit(): void
    {
        $unit = file_get_contents(base_path('deploy/systemd/seasonvar-import-worker@.service'));

        $this->assertIsString($unit);
        $this->assertStringContainsString(
            'ExecStart=/usr/bin/php -d memory_limit=256M artisan queue:work redis',
            $unit,
        );
        $this->assertStringContainsString('--memory=192', $unit);
        $this->assertStringContainsString('Restart=always', $unit);
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
