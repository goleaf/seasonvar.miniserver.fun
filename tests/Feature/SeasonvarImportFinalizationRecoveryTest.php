<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SeasonvarImportFinalizationStage;
use App\Jobs\FinalizeSeasonvarQueuedImport;
use App\Models\SeasonvarImportRun;
use App\Services\Catalog\CatalogCacheInvalidator;
use App\Services\Seasonvar\SeasonvarImportFinalizationCoordinator;
use App\Services\Seasonvar\SeasonvarImportPipeline;
use App\Services\Seasonvar\SeasonvarImportRunRecorder;
use App\Services\Seasonvar\SeasonvarPageClaimManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class SeasonvarImportFinalizationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_stages_are_skipped_and_a_failed_stage_is_resumed(): void
    {
        $run = $this->importRun();
        $coordinator = app(SeasonvarImportFinalizationCoordinator::class);

        $this->assertSame(
            SeasonvarImportFinalizationStage::StorageMaintenance,
            $coordinator->nextStage($run),
        );
        $this->assertTrue($coordinator->beginStage(
            $run,
            SeasonvarImportFinalizationStage::StorageMaintenance,
        ));
        $coordinator->completeStage(
            $run,
            SeasonvarImportFinalizationStage::StorageMaintenance,
            ['events_deleted' => 3],
        );
        $this->assertFalse($coordinator->beginStage(
            $run,
            SeasonvarImportFinalizationStage::StorageMaintenance,
        ));
        $this->assertSame(
            SeasonvarImportFinalizationStage::ProviderAvailability,
            $coordinator->nextStage($run),
        );

        $this->assertTrue($coordinator->beginStage(
            $run,
            SeasonvarImportFinalizationStage::ProviderAvailability,
        ));
        $coordinator->failStage(
            $run,
            SeasonvarImportFinalizationStage::ProviderAvailability,
            new RuntimeException('https://seasonvar.ru/private?token=secret'),
        );

        $this->assertSame(
            SeasonvarImportFinalizationStage::ProviderAvailability,
            $coordinator->nextStage($run),
        );
        $this->assertSame(
            'completed',
            data_get(
                $run->fresh()->summary,
                'queued_finalization.stages.storage_maintenance.status',
            ),
        );
        $this->assertSame(
            'failed',
            data_get(
                $run->fresh()->summary,
                'queued_finalization.stages.provider_availability.status',
            ),
        );
        $this->assertStringNotContainsString(
            'seasonvar.ru',
            (string) data_get(
                $run->fresh()->summary,
                'queued_finalization.stages.provider_availability.failure',
            ),
        );

        $this->assertTrue($coordinator->beginStage(
            $run,
            SeasonvarImportFinalizationStage::ProviderAvailability,
        ));
        $coordinator->completeStage(
            $run,
            SeasonvarImportFinalizationStage::ProviderAvailability,
            ['checked' => 9],
        );

        $this->assertSame(
            2,
            data_get(
                $run->fresh()->summary,
                'queued_finalization.stages.provider_availability.attempts',
            ),
        );
        $this->assertSame(
            [
                'storage_maintenance' => ['events_deleted' => 3],
                'provider_availability_backfill' => ['checked' => 9],
            ],
            array_intersect_key(
                $coordinator->stageResults($run),
                array_flip([
                    'storage_maintenance',
                    'provider_availability_backfill',
                ]),
            ),
        );
    }

    public function test_legacy_maintenance_checkpoint_resumes_at_recommendations(): void
    {
        $legacy = [
            'storage_maintenance' => ['events_deleted' => 1],
            'provider_availability_backfill' => [],
            'metadata_backfill' => [],
            'source_status_backfill' => [],
            'media_metadata_backlog' => [],
            'media_source_key_backlog' => [],
            'media_backlog' => ['media_updated' => 0, 'media_failed' => 0],
            'media_size_backlog' => [],
            'relation_cleanup' => [],
            'merge' => ['titles' => 0],
        ];
        $run = $this->importRun([
            'queued_finalization_checkpoint' => [
                'version' => 1,
                ...$legacy,
            ],
        ]);
        $coordinator = app(SeasonvarImportFinalizationCoordinator::class);

        $this->assertSame(
            SeasonvarImportFinalizationStage::Recommendations,
            $coordinator->nextStage($run),
        );
        $this->assertSame($legacy, $coordinator->stageResults($run));
    }

    public function test_queue_job_executes_one_stage_and_dispatches_the_next_checkpoint(): void
    {
        config(['seasonvar.queue.lock_store' => 'array']);
        Queue::fake();
        $run = $this->importRun([
            'dispatch_completed' => true,
        ]);

        (new FinalizeSeasonvarQueuedImport($run->id))->handle(
            app(SeasonvarPageClaimManager::class),
            app(SeasonvarImportPipeline::class),
            app(SeasonvarImportRunRecorder::class),
            app(CatalogCacheInvalidator::class),
        );

        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame(
            'completed',
            data_get(
                $run->fresh()->summary,
                'queued_finalization.stages.storage_maintenance.status',
            ),
        );
        $this->assertSame(
            SeasonvarImportFinalizationStage::ProviderAvailability,
            app(SeasonvarImportFinalizationCoordinator::class)->nextStage($run),
        );
        Queue::assertPushed(
            FinalizeSeasonvarQueuedImport::class,
            fn (FinalizeSeasonvarQueuedImport $job): bool => $job->importRunId === $run->id,
        );
    }

    /** @param array<string, mixed> $summary */
    private function importRun(array $summary = []): SeasonvarImportRun
    {
        return SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'summary' => $summary,
            'started_at' => now(),
            'last_progress_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
    }
}
