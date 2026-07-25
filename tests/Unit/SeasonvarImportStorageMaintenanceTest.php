<?php

namespace Tests\Unit;

use App\Models\SeasonvarImportEvent;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Models\SourcePage;
use App\Models\SourcePageSnapshot;
use App\Services\Seasonvar\SeasonvarImportStorageMaintenance;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeasonvarImportStorageMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sanitizes_url_values_before_import_events_are_persisted(): void
    {
        $maintenance = new SeasonvarImportStorageMaintenance;
        $urlHash = hash('sha256', 'https://seasonvar.ru/player.php?token=secret');

        $context = $maintenance->sanitizeEventContext([
            'source_page_id' => 123,
            'playback_url' => 'https://seasonvar.ru/player.php?token=secret',
            'url_hash' => $urlHash,
            'message' => 'Не удалось проверить https://cdn.example.test/video.m3u8',
            'items' => [
                [
                    'source_url' => 'https://seasonvar.ru/serial-1-test.html',
                    'quality' => '720p',
                ],
                [
                    'note' => 'plain text',
                ],
            ],
            'successful' => true,
        ]);

        $this->assertSame(123, $context['source_page_id']);
        $this->assertSame('[redacted-url]', $context['playback_url']);
        $this->assertSame($urlHash, $context['url_hash']);
        $this->assertSame('[redacted-url]', $context['message']);
        $this->assertSame('[redacted-url]', $context['items'][0]['source_url']);
        $this->assertSame('720p', $context['items'][0]['quality']);
        $this->assertSame('plain text', $context['items'][1]['note']);
        $this->assertTrue($context['successful']);
    }

    public function test_it_prunes_old_events_and_snapshots_without_touching_running_import_runs(): void
    {
        config()->set('seasonvar.import.event_retention_days', 7);
        config()->set('seasonvar.import.snapshot_retention_days', 14);
        config()->set('seasonvar.import.maintenance_chunk_size', 2);

        $sourcePage = SourcePage::factory()->create([
            'url' => 'https://seasonvar.ru/serial-101-test.html',
            'url_hash' => hash('sha256', 'https://seasonvar.ru/serial-101-test.html'),
        ]);
        $completedRun = $this->createImportRun('completed');
        $runningRun = $this->createImportRun('running');

        $oldCompletedEvent = $this->createImportEvent($completedRun, now()->subDays(10));
        $runningEvent = $this->createImportEvent($runningRun, now()->subDays(10));
        $recentEvent = $this->createImportEvent($completedRun, now()->subDay());

        $oldCompletedSnapshot = $this->createSnapshot($sourcePage, $completedRun, 'old-completed', now()->subDays(20));
        $runningSnapshot = $this->createSnapshot($sourcePage, $runningRun, 'running', now()->subDays(20));
        $recentSnapshot = $this->createSnapshot($sourcePage, $completedRun, 'recent', now()->subDay());
        $retainedPage = SourcePage::factory()->create();
        $latestRetainedSnapshot = $this->createSnapshot(
            $retainedPage,
            $completedRun,
            'latest-retained',
            now()->subDays(30),
        );

        $result = (new SeasonvarImportStorageMaintenance)->prune();

        $this->assertSame(1, $result['events_deleted']);
        $this->assertSame(1, $result['snapshots_deleted']);
        $this->assertDatabaseMissing('seasonvar_import_events', ['id' => $oldCompletedEvent->id]);
        $this->assertDatabaseHas('seasonvar_import_events', ['id' => $runningEvent->id]);
        $this->assertDatabaseHas('seasonvar_import_events', ['id' => $recentEvent->id]);
        $this->assertDatabaseMissing('source_page_snapshots', ['id' => $oldCompletedSnapshot->id]);
        $this->assertDatabaseHas('source_page_snapshots', ['id' => $runningSnapshot->id]);
        $this->assertDatabaseHas('source_page_snapshots', ['id' => $recentSnapshot->id]);
        $this->assertDatabaseHas('source_page_snapshots', ['id' => $latestRetainedSnapshot->id]);
    }

    public function test_it_deletes_no_more_than_the_configured_rows_across_chunks(): void
    {
        config()->set('seasonvar.import.event_retention_days', 7);
        config()->set('seasonvar.import.snapshot_retention_days', 0);
        config()->set('seasonvar.import.prepared_retention_days', 0);
        config()->set('seasonvar.import.maintenance_chunk_size', 2);
        config()->set('seasonvar.import.maintenance_max_chunks', 10);
        config()->set('seasonvar.import.maintenance_max_rows', 3);
        config()->set('seasonvar.import.maintenance_time_budget_seconds', 60);

        $completedRun = $this->createImportRun('completed');

        foreach (range(1, 5) as $offset) {
            $this->createImportEvent($completedRun, now()->subDays(10)->addSeconds($offset));
        }

        $result = (new SeasonvarImportStorageMaintenance)->prune();

        $this->assertSame(3, $result['events_deleted']);
        $this->assertSame(3, $result['rows_deleted']);
        $this->assertSame(2, $result['chunks_processed']);
        $this->assertSame('max_rows', $result['stopped_reason']);
        $this->assertDatabaseCount('seasonvar_import_events', 2);
    }

    public function test_row_budget_is_shared_by_events_and_snapshots(): void
    {
        config()->set('seasonvar.import.event_retention_days', 7);
        config()->set('seasonvar.import.snapshot_retention_days', 14);
        config()->set('seasonvar.import.prepared_retention_days', 0);
        config()->set('seasonvar.import.maintenance_chunk_size', 2);
        config()->set('seasonvar.import.maintenance_max_chunks', 10);
        config()->set('seasonvar.import.maintenance_max_rows', 3);
        config()->set('seasonvar.import.maintenance_time_budget_seconds', 60);

        $completedRun = $this->createImportRun('completed');

        foreach (range(1, 2) as $offset) {
            $this->createImportEvent($completedRun, now()->subDays(10)->addSeconds($offset));
        }

        foreach (range(1, 2) as $offset) {
            $sourcePage = SourcePage::factory()->create();
            $this->createSnapshot($sourcePage, $completedRun, 'old-'.$offset, now()->subDays(20));
            $this->createSnapshot($sourcePage, $completedRun, 'new-'.$offset, now()->subDay());
        }

        $result = (new SeasonvarImportStorageMaintenance)->prune();

        $this->assertSame(3, $result['rows_deleted']);
        $this->assertSame(2, $result['events_deleted']);
        $this->assertSame(1, $result['snapshots_deleted']);
        $this->assertSame('max_rows', $result['stopped_reason']);
        $this->assertDatabaseCount('seasonvar_import_events', 0);
        $this->assertDatabaseCount('source_page_snapshots', 3);
    }

    public function test_it_stops_before_starting_another_chunk_after_the_monotonic_time_budget(): void
    {
        config()->set('seasonvar.import.event_retention_days', 7);
        config()->set('seasonvar.import.snapshot_retention_days', 0);
        config()->set('seasonvar.import.prepared_retention_days', 0);
        config()->set('seasonvar.import.maintenance_chunk_size', 1);
        config()->set('seasonvar.import.maintenance_max_chunks', 10);
        config()->set('seasonvar.import.maintenance_max_rows', 10);
        config()->set('seasonvar.import.maintenance_time_budget_seconds', 1);

        $completedRun = $this->createImportRun('completed');

        foreach (range(1, 3) as $offset) {
            $this->createImportEvent($completedRun, now()->subDays(10)->addSeconds($offset));
        }

        $delayed = false;
        DB::listen(function (QueryExecuted $query) use (&$delayed): void {
            if (! $delayed && str_starts_with(strtolower($query->sql), 'delete from "seasonvar_import_events"')) {
                $delayed = true;
                usleep(1_100_000);
            }
        });

        $result = (new SeasonvarImportStorageMaintenance)->prune();

        $this->assertSame(1, $result['events_deleted']);
        $this->assertSame(1, $result['chunks_processed']);
        $this->assertSame('time_budget', $result['stopped_reason']);
        $this->assertDatabaseCount('seasonvar_import_events', 2);
    }

    public function test_it_deletes_only_terminal_groups_from_terminal_runs_and_cascades_their_pages(): void
    {
        config()->set('seasonvar.import.event_retention_days', 0);
        config()->set('seasonvar.import.snapshot_retention_days', 0);
        config()->set('seasonvar.import.prepared_retention_days', 7);
        config()->set('seasonvar.import.maintenance_chunk_size', 10);
        config()->set('seasonvar.import.maintenance_max_chunks', 10);
        config()->set('seasonvar.import.maintenance_max_rows', 10);
        config()->set('seasonvar.import.maintenance_time_budget_seconds', 60);

        $completedRun = $this->createImportRun('completed');
        $runningRun = $this->createImportRun('running');
        $terminalGroup = $this->createTitleGroup($completedRun, 'completed', 'terminal');
        $finalizingGroup = $this->createTitleGroup($completedRun, 'finalizing', 'finalizing');
        $activeRunGroup = $this->createTitleGroup($runningRun, 'completed', 'active-run');
        $terminalPage = $this->createPreparedPage($terminalGroup);
        $finalizingPage = $this->createPreparedPage($finalizingGroup);
        $activeRunPage = $this->createPreparedPage($activeRunGroup);

        $result = (new SeasonvarImportStorageMaintenance)->prune();

        $this->assertSame(1, $result['title_groups_deleted']);
        $this->assertDatabaseMissing('seasonvar_import_title_groups', ['id' => $terminalGroup->id]);
        $this->assertDatabaseMissing('seasonvar_import_prepared_pages', ['id' => $terminalPage->id]);
        $this->assertDatabaseHas('seasonvar_import_title_groups', ['id' => $finalizingGroup->id]);
        $this->assertDatabaseHas('seasonvar_import_prepared_pages', ['id' => $finalizingPage->id]);
        $this->assertDatabaseHas('seasonvar_import_title_groups', ['id' => $activeRunGroup->id]);
        $this->assertDatabaseHas('seasonvar_import_prepared_pages', ['id' => $activeRunPage->id]);
    }

    public function test_storage_preview_contract_is_available(): void
    {
        $this->assertTrue(method_exists(SeasonvarImportStorageMaintenance::class, 'preview'));
    }

    public function test_preview_counts_only_rows_outside_retention_and_terminal_runs(): void
    {
        config()->set('seasonvar.import.event_retention_days', 7);
        config()->set('seasonvar.import.snapshot_retention_days', 14);
        config()->set('seasonvar.import.prepared_retention_days', 7);

        $completedRun = $this->createImportRun('completed');
        $runningRun = $this->createImportRun('running');

        $this->createImportEvent($completedRun, now()->subDays(10));
        $this->createImportEvent($completedRun, now()->subDay());
        $this->createImportEvent($runningRun, now()->subDays(10));

        $snapshotPage = SourcePage::factory()->create();
        $this->createSnapshot($snapshotPage, $completedRun, 'expired', now()->subDays(20));
        $this->createSnapshot($snapshotPage, $completedRun, 'latest', now()->subDay());
        $retainedLatestPage = SourcePage::factory()->create();
        $this->createSnapshot($retainedLatestPage, $completedRun, 'old-latest', now()->subDays(30));
        $runningSnapshotPage = SourcePage::factory()->create();
        $this->createSnapshot($runningSnapshotPage, $runningRun, 'running-old', now()->subDays(20));
        $this->createSnapshot($runningSnapshotPage, $runningRun, 'running-new', now()->subDay());

        $terminalGroup = $this->createTitleGroup($completedRun, 'completed', 'preview-terminal');
        $this->createPreparedPage($terminalGroup);
        $finalizingGroup = $this->createTitleGroup($completedRun, 'finalizing', 'preview-finalizing');
        $this->createPreparedPage($finalizingGroup);
        $activeRunGroup = $this->createTitleGroup($runningRun, 'completed', 'preview-active-run');
        $this->createPreparedPage($activeRunGroup);

        $preview = (new SeasonvarImportStorageMaintenance)->preview();
        $safePayload = $preview->toArray();
        $encodedPayload = json_encode($safePayload, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $preview->expiredEvents);
        $this->assertGreaterThan(0, $preview->eventContextBytes);
        $this->assertNotNull($preview->oldestExpiredEventAt);
        $this->assertSame(1, $preview->expiredSnapshots);
        $this->assertSame(128, $preview->snapshotBodyBytes);
        $this->assertNotNull($preview->oldestExpiredSnapshotAt);
        $this->assertSame(1, $preview->expiredTitleGroups);
        $this->assertSame(1, $preview->expiredPreparedPages);
        $this->assertGreaterThan(0, $preview->preparedPayloadBytes);
        $this->assertNotNull($preview->oldestExpiredTitleGroupAt);
        $this->assertSame(1, $preview->activeRuns);
        $this->assertSame(1, $preview->activeTitleGroups);
        $this->assertSame(4, $preview->totalExpiredRows());
        $this->assertStringNotContainsString('seasonvar.ru', $encodedPayload);
        $this->assertStringNotContainsString('Тест', $encodedPayload);
        $this->assertArrayNotHasKey('url', $safePayload);
        $this->assertArrayNotHasKey('body', $safePayload);
        $this->assertArrayNotHasKey('payload', $safePayload);
        $this->assertArrayNotHasKey('last_error', $safePayload);
    }

    private function createImportRun(string $status): SeasonvarImportRun
    {
        return SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'status' => $status,
            'force' => false,
            'forever' => false,
            'started_at' => now()->subDays(20),
            'finished_at' => $status === 'running' ? null : now()->subDays(19),
        ]);
    }

    private function createImportEvent(SeasonvarImportRun $run, Carbon $createdAt): SeasonvarImportEvent
    {
        $event = SeasonvarImportEvent::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'event' => 'seasonvar-media-url-checked',
            'level' => 'info',
            'context' => ['source_page_id' => 1],
        ]);
        $event->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $event;
    }

    private function createSnapshot(
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

    private function createTitleGroup(
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

    private function createPreparedPage(SeasonvarImportTitleGroup $group): SeasonvarImportPreparedPage
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
