<?php

namespace Tests\Feature;

use App\Jobs\FinalizeSeasonvarQueuedImport;
use App\Jobs\ImportSeasonvarSourcePage;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use App\Services\Catalog\CatalogStatsSnapshotCache;
use App\Services\Seasonvar\SeasonvarCatalogImporter;
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

    public function test_import_group_key_uses_external_id_and_hash_fallback(): void
    {
        $keys = app(SeasonvarImportGroupKey::class);

        $this->assertSame(
            'seasonvar-title:47915',
            $keys->forUrl('https://seasonvar.ru/serial-47915-Test-4-season.html', 'hash-a'),
        );
        $this->assertSame(
            'seasonvar-page:hash-b',
            $keys->forUrl('https://seasonvar.ru/catalog/test.html', 'hash-b'),
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
        $importer->shouldReceive('parsePages')
            ->once()
            ->withArgs(fn ($pages, $progress, $force, $runId): bool => $pages->pluck('id')->all() === [$page->id]
                && $progress === null
                && $force === false
                && $runId === $run->id)
            ->andReturn([
                'parsed' => 1,
                'failed' => 0,
                'media_attached' => 2,
                'media_updated' => 1,
                'media_skipped' => 0,
                'media_failed' => 0,
                'failures' => [],
            ]);

        (new ImportSeasonvarSourcePage(
            sourcePageId: $page->id,
            importRunId: $run->id,
            claimToken: (string) $token,
            groupKey: 'seasonvar-title:1',
        ))->handle(
            $claims,
            $importer,
            app(SeasonvarImportRunRecorder::class),
        );

        $freshRun = $run->fresh();
        $freshPage = $page->fresh();

        $this->assertSame(0, $freshRun->selected);
        $this->assertSame(1, $freshRun->parsed);
        $this->assertSame(2, $freshRun->media_attached);
        $this->assertSame(1, $freshRun->media_updated);
        $this->assertNull($freshPage->import_claim_token);
        $this->assertNull($freshPage->import_claim_run_id);
    }

    public function test_worker_releases_itself_when_title_lock_is_held(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        $run = $this->queuedRun();
        $page = SourcePage::factory()->create();
        $claims = app(SeasonvarPageClaimManager::class);
        $token = $claims->claim($page, $run->id, 3600);
        $lock = Cache::store('array')->lock('seasonvar-title:1', 1200);
        $this->assertTrue($lock->get());
        $importer = Mockery::mock(SeasonvarCatalogImporter::class);
        $importer->shouldNotReceive('parsePages');

        try {
            $job = (new ImportSeasonvarSourcePage(
                sourcePageId: $page->id,
                importRunId: $run->id,
                claimToken: (string) $token,
                groupKey: 'seasonvar-title:1',
            ))->withFakeQueueInteractions();

            $job->handle($claims, $importer, app(SeasonvarImportRunRecorder::class));

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
        Queue::assertPushedTimes(ImportSeasonvarSourcePage::class, 2);
        Queue::assertPushed(FinalizeSeasonvarQueuedImport::class, 1);
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

        $job->handle($claims, $pipeline, $stats);

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
