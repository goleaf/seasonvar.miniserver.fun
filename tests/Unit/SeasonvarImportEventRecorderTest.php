<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\SeasonvarImportEventPersistence;
use App\Models\SeasonvarImportEvent;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarImportEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class SeasonvarImportEventRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_failures_and_terminal_lifecycle_events_are_always_persisted(): void
    {
        $run = $this->createRun();
        $recorder = app(SeasonvarImportEventRecorder::class);

        $recorder->record(
            event: 'seasonvar-import-failed',
            importRunId: $run->id,
            context: ['reason' => 'provider_failure'],
        );
        $recorder->record(
            event: 'seasonvar-import-complete',
            importRunId: $run->id,
            context: ['parsed' => 12],
        );

        $this->assertDatabaseHas('seasonvar_import_events', [
            'seasonvar_import_run_id' => $run->id,
            'event' => 'seasonvar-import-failed',
            'level' => 'warning',
        ]);
        $this->assertDatabaseHas('seasonvar_import_events', [
            'seasonvar_import_run_id' => $run->id,
            'event' => 'seasonvar-import-complete',
            'level' => 'info',
        ]);
    }

    public function test_successful_media_item_events_are_aggregated_without_one_row_per_item(): void
    {
        config(['seasonvar.import.event_persistence.aggregate_flush_size' => 100]);
        $run = $this->createRun(['media_updated' => 503]);
        $recorder = app(SeasonvarImportEventRecorder::class);

        foreach (range(1, 503) as $mediaId) {
            $recorder->record(
                event: 'seasonvar-media-updated',
                importRunId: $run->id,
                catalogTitleId: 42,
                context: ['media_id' => $mediaId],
            );
        }

        $aggregateRows = SeasonvarImportEvent::query()
            ->where('event', SeasonvarImportEventRecorder::AGGREGATE_EVENT)
            ->get();

        $this->assertCount(5, $aggregateRows);
        $this->assertSame(
            500,
            $aggregateRows->sum(
                fn (SeasonvarImportEvent $event): int => (int) $event->context['counts']['seasonvar-media-updated'],
            ),
        );
        $this->assertFalse(SeasonvarImportEvent::query()
            ->where('event', 'seasonvar-media-updated')
            ->exists());

        $recorder->record(event: 'seasonvar-import-complete', importRunId: $run->id);

        $aggregateRows = SeasonvarImportEvent::query()
            ->where('event', SeasonvarImportEventRecorder::AGGREGATE_EVENT)
            ->get();

        $this->assertCount(6, $aggregateRows);
        $this->assertSame(
            503,
            $aggregateRows->sum(
                fn (SeasonvarImportEvent $event): int => (int) $event->context['counts']['seasonvar-media-updated'],
            ),
        );
        $this->assertSame(503, $run->fresh()->media_updated);
    }

    public function test_sampling_is_deterministic_for_the_same_run_and_entity(): void
    {
        config(['seasonvar.import.event_persistence.sample_divisor' => 4]);
        $run = $this->createRun();
        $recorder = app(SeasonvarImportEventRecorder::class);

        foreach (range(1, 40) as $sampleKey) {
            $recorder->record(
                event: 'http-request-complete',
                importRunId: $run->id,
                context: ['sample_key' => $sampleKey],
            );
        }

        $firstSelection = SeasonvarImportEvent::query()
            ->where('event', 'http-request-complete')
            ->get()
            ->map(fn (SeasonvarImportEvent $event): int => (int) $event->context['sample_key'])
            ->all();

        $this->assertNotEmpty($firstSelection);
        $this->assertLessThan(40, count($firstSelection));

        SeasonvarImportEvent::query()->delete();

        foreach (range(1, 40) as $sampleKey) {
            $recorder->record(
                event: 'http-request-complete',
                importRunId: $run->id,
                context: ['sample_key' => $sampleKey],
            );
        }

        $secondSelection = SeasonvarImportEvent::query()
            ->where('event', 'http-request-complete')
            ->get()
            ->map(fn (SeasonvarImportEvent $event): int => (int) $event->context['sample_key'])
            ->all();

        $this->assertSame($firstSelection, $secondSelection);
    }

    public function test_sampling_uses_stable_entity_identity_instead_of_volatile_context(): void
    {
        config(['seasonvar.import.event_persistence.sample_divisor' => 2]);
        $run = $this->createRun();
        $sourcePage = SourcePage::factory()->create();
        $recorder = app(SeasonvarImportEventRecorder::class);

        foreach (range(1, 40) as $latency) {
            $recorder->record(
                event: 'http-request-complete',
                importRunId: $run->id,
                sourcePageId: $sourcePage->id,
                context: ['latency_ms' => $latency],
            );
        }

        $this->assertContains(
            SeasonvarImportEvent::query()
                ->where('event', 'http-request-complete')
                ->count(),
            [0, 40],
        );
    }

    public function test_unknown_info_is_transient_while_known_policies_are_explicit(): void
    {
        $recorder = app(SeasonvarImportEventRecorder::class);

        $this->assertSame(
            SeasonvarImportEventPersistence::Aggregate,
            $recorder->persistenceFor('seasonvar-media-url-checked', 'info'),
        );
        $this->assertSame(
            SeasonvarImportEventPersistence::Sampled,
            $recorder->persistenceFor('http-request-complete', 'info'),
        );
        $this->assertSame(
            SeasonvarImportEventPersistence::Transient,
            $recorder->persistenceFor('unregistered-success-detail', 'info'),
        );
        $this->assertSame(
            SeasonvarImportEventPersistence::Always,
            $recorder->persistenceFor('unregistered-failure', 'warning'),
        );

        $recorder->record(event: 'unregistered-success-detail', context: ['count' => 1]);

        $this->assertDatabaseCount('seasonvar_import_events', 0);
    }

    public function test_url_values_and_messages_are_redacted_before_persistence(): void
    {
        $recorder = app(SeasonvarImportEventRecorder::class);

        $recorder->record(
            event: 'seasonvar-import-failed',
            context: [
                'source_url' => 'https://seasonvar.ru/serial-1-test.html?token=secret',
                'message' => 'Ошибка https://cdn.example.test/video.mp4?signature=secret',
                'reason' => 'provider_failure',
            ],
        );

        $context = SeasonvarImportEvent::query()->sole()->context;

        $this->assertSame('[redacted-url]', $context['source_url']);
        $this->assertSame('[redacted-url]', $context['message']);
        $this->assertSame('provider_failure', $context['reason']);
    }

    public function test_event_recorder_failure_never_aborts_catalog_import(): void
    {
        $recorder = app(SeasonvarImportEventRecorder::class);
        $originalConnection = config('database.default');

        try {
            config(['database.default' => 'missing-event-recorder-test-connection']);

            $recorder->record(
                event: 'seasonvar-import-failed',
                context: ['reason' => 'database_unavailable'],
            );
        } finally {
            config(['database.default' => $originalConnection]);
        }

        $this->assertDatabaseCount('seasonvar_import_events', 0);
    }

    public function test_application_uses_one_import_event_writer(): void
    {
        $writers = collect(File::allFiles(app_path()))
            ->filter(
                fn (\SplFileInfo $file): bool => str_contains(
                    $file->getContents(),
                    'SeasonvarImportEvent::query()->create(',
                ),
            )
            ->map(fn (\SplFileInfo $file): string => $file->getRelativePathname())
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'Services/Seasonvar/SeasonvarImportEventRecorder.php',
        ], $writers);
    }

    public function test_queue_apply_boundaries_flush_partial_aggregates(): void
    {
        foreach ([
            'Jobs/FinalizeSeasonvarImportTitleGroup.php',
            'Jobs/ImportSeasonvarSourcePage.php',
        ] as $relativePath) {
            $this->assertStringContainsString(
                '$eventRecorder->flushRun(',
                File::get(app_path($relativePath)),
                $relativePath,
            );
        }
    }

    /** @param array<string, mixed> $attributes */
    private function createRun(array $attributes = []): SeasonvarImportRun
    {
        return SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'sync',
            'status' => 'running',
            'force' => false,
            'forever' => false,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
            ...$attributes,
        ]);
    }
}
