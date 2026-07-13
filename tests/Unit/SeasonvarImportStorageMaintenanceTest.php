<?php

namespace Tests\Unit;

use App\Models\SeasonvarImportEvent;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use App\Models\SourcePageSnapshot;
use App\Services\Seasonvar\SeasonvarImportStorageMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
}
