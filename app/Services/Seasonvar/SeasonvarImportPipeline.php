<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\SeasonvarImportFinalizationStage;
use App\Enums\SeasonvarImportStatus;
use App\Jobs\RebuildCatalogRecommendations;
use App\Models\CatalogTitle;
use App\Models\Season;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use App\Services\Catalog\CatalogCacheInvalidator;
use App\Services\Catalog\CatalogRecommendationDirtyTitleTracker;
use App\Services\ContentRequests\ContentRequestImportRunLinker;
use App\Services\ReleaseCalendar\ReleaseCalendarCacheInvalidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class SeasonvarImportPipeline
{
    private const QUEUED_FINALIZATION_CHECKPOINT = 'queued_finalization_checkpoint';

    private bool $stopRequested = false;

    private ?Carbon $lastRunHeartbeatAt = null;

    public function __construct(
        private readonly SeasonvarCatalogImporter $importer,
        private readonly SeasonvarSitemapMirror $sitemapMirror,
        private readonly SeasonvarRefreshPlanner $refreshPlanner,
        private readonly CatalogRecommendationDirtyTitleTracker $recommendationDirtyTitles,
        private readonly SeasonvarImportMaintenancePipeline $maintenance,
        private readonly SeasonvarImportErrorSanitizer $errors,
        private readonly SeasonvarImportRunRecorder $runRecorder,
        private readonly SeasonvarImportFinalizationCoordinator $finalization,
        private readonly ContentRequestImportRunLinker $contentRequests,
        private readonly SeasonvarImportEventRecorder $eventRecorder,
        private readonly ReleaseCalendarCacheInvalidator $releaseCalendarCache,
        private readonly CatalogCacheInvalidator $catalogCache,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  list<string>|null  $pageTypes
     */
    public function run(
        ?string $argument = null,
        bool $force = false,
        bool $forever = false,
        ?int $sleepSeconds = null,
        bool $discover = true,
        ?int $processId = null,
        ?string $processHost = null,
        ?string $processCommand = null,
        ?callable $progress = null,
        ?array $pageTypes = null,
        ?SeasonvarImportRun $reservedRun = null,
        bool $refreshMediaSizes = false,
        bool $forceMediaSizes = false,
        ?int $mediaSizeLimit = null,
        ?int $mediaSizeTimeBudgetSeconds = null,
        bool $queueRecommendations = false,
    ): SeasonvarImportRun {
        $run = $reservedRun ?? SeasonvarImportRun::query()->create([
            'mode' => $argument === null ? 'sitemap' : 'url',
            'status' => 'running',
            'argument' => $argument,
            'force' => $force,
            'forever' => $forever,
            'process_id' => $processId,
            'process_host' => $processHost,
            'process_command' => $processCommand,
            'cycles' => 0,
            'started_at' => now(),
            'last_progress_at' => now(),
            'last_heartbeat_at' => now(),
        ]);

        if ($reservedRun !== null && (
            $argument !== null
            || $reservedRun->mode !== 'sitemap'
            || $reservedRun->execution_mode !== 'sync'
            || $reservedRun->status !== 'running'
        )) {
            throw new LogicException('Reserved Seasonvar run is incompatible with a synchronous global import.');
        }
        $loggedProgress = fn (string $event, array $context = []) => $this->recordProgress($run, $progress, $event, $context);
        $sleepSeconds = max(1, $sleepSeconds ?? (int) config('seasonvar.import.sleep_seconds', 60));

        $this->recordProgress($run, $progress, 'seasonvar-import-started', [
            'mode' => $run->mode,
            'argument' => $argument,
            'force' => $force,
            'forever' => $forever,
            'sleep_seconds' => $sleepSeconds,
            'page_types' => $pageTypes,
            ...($refreshMediaSizes ? [
                'media_size_time_budget_seconds' => $mediaSizeTimeBudgetSeconds,
            ] : []),
        ]);

        try {
            do {
                if ($this->stopRequested) {
                    break;
                }

                $cycle = ((int) $run->cycles) + 1;
                $this->runCycle(
                    $run,
                    $cycle,
                    $argument,
                    $force,
                    $discover,
                    $loggedProgress,
                    $pageTypes,
                    $refreshMediaSizes,
                    $forceMediaSizes,
                    $mediaSizeLimit,
                    $mediaSizeTimeBudgetSeconds,
                    $queueRecommendations,
                );
                $run->refresh();

                if (! $forever || $this->stopRequested) {
                    break;
                }

                $this->sleepBetweenCycles($sleepSeconds, $loggedProgress);
            } while (! $this->stopRequested);

            $terminalAttributes = [
                'status' => $this->stopRequested
                    ? SeasonvarImportStatus::Cancelled->value
                    : $run->completionStatus(),
                'finished_at' => now(),
                'last_progress_at' => now(),
                'last_heartbeat_at' => now(),
            ];

            if ($this->stopRequested) {
                $terminalAttributes['cancel_requested_at'] = now();
            }

            $run->refresh()->fill($terminalAttributes)->save();
            $this->contentRequests->link($run->refresh());

            $this->recordProgress(
                $run,
                $progress,
                $this->stopRequested ? 'seasonvar-import-cancelled' : 'seasonvar-import-complete',
                [
                    'cycles' => $run->cycles,
                    'discovered' => $run->discovered,
                    'stored' => $run->stored,
                    'selected' => $run->selected,
                    'parsed' => $run->parsed,
                    'failed' => $run->failed,
                    'media_attached' => $run->media_attached,
                    'media_updated' => $run->media_updated,
                    'media_skipped' => $run->media_skipped,
                    'media_failed' => $run->media_failed,
                    'media_sizes_checked' => $run->media_sizes_checked,
                    'media_sizes_known' => $run->media_sizes_known,
                    'media_sizes_unknown' => $run->media_sizes_unknown,
                    'media_sizes_unsupported' => $run->media_sizes_unsupported,
                    'media_size_checks_failed' => $run->media_size_checks_failed,
                    'media_size_known_bytes' => $run->media_size_known_bytes,
                ],
            );
        } catch (Throwable $exception) {
            $run->fill([
                'status' => 'failed',
                'last_error' => $this->errors->fromException($exception),
                'finished_at' => now(),
                'last_progress_at' => now(),
                'last_heartbeat_at' => now(),
            ])->save();
            $this->contentRequests->link($run->refresh());

            $this->recordProgress($run, $progress, 'seasonvar-import-failed', [
                'exception' => $exception::class,
                'message' => $this->errors->fromException($exception),
            ]);

            throw $exception;
        }

        return $run->refresh();
    }

    public function stop(): void
    {
        $this->stopRequested = true;
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function finalizeQueuedRun(SeasonvarImportRun $run, ?callable $progress = null): SeasonvarImportRun
    {
        foreach (SeasonvarImportFinalizationStage::ordered() as $_stage) {
            $this->finalizeNextQueuedStage($run, $progress);
            $run->refresh();

            if ($run->status !== SeasonvarImportStatus::Running->value
                || $this->finalization->nextStage($run) === null
            ) {
                break;
            }
        }

        return $run->refresh();
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function finalizeNextQueuedStage(
        SeasonvarImportRun $run,
        ?callable $progress = null,
    ): SeasonvarImportRun {
        $stage = $this->finalization->nextStage($run);

        if ($stage === null || ! $this->finalization->beginStage($run, $stage)) {
            return $run->refresh();
        }

        $loggedProgress = fn (string $event, array $context = []) => $this->recordProgress(
            $run,
            $progress,
            $event,
            $context,
        );

        try {
            $result = $this->executeQueuedFinalizationStage(
                $run,
                $stage,
                $loggedProgress,
            );
            $this->finalization->completeStage($run, $stage, $result);
            $loggedProgress('seasonvar-queued-finalization-stage-completed', [
                'stage' => $stage->value,
            ]);
        } catch (Throwable $exception) {
            $this->finalization->failStage($run, $stage, $exception);

            throw $exception;
        }

        return $run->refresh();
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array<string, mixed>
     */
    private function executeQueuedFinalizationStage(
        SeasonvarImportRun $run,
        SeasonvarImportFinalizationStage $stage,
        callable $progress,
    ): array {
        if ($stage === SeasonvarImportFinalizationStage::Terminal) {
            return $this->completeQueuedFinalization($run);
        }

        return $this->maintenance->executeQueuedStage($run, $stage, $progress);
    }

    /** @return array<string, mixed> */
    private function completeQueuedFinalization(SeasonvarImportRun $run): array
    {
        $results = $this->finalization->stageResults($run);
        $requiredKeys = collect(SeasonvarImportFinalizationStage::ordered())
            ->reject(
                static fn (SeasonvarImportFinalizationStage $stage): bool => $stage === SeasonvarImportFinalizationStage::Terminal,
            )
            ->map(
                static fn (SeasonvarImportFinalizationStage $stage): ?string => $stage->resultKey(),
            )
            ->filter()
            ->values();

        if (! $requiredKeys->every(
            static fn (string $key): bool => isset($results[$key]),
        )) {
            throw new LogicException('Нельзя завершить импорт Seasonvar без всех finalization checkpoints.');
        }

        $mediaBacklog = $results['media_backlog'];
        $this->addRunCounters($run, [
            'cycles' => 1,
            'media_updated' => (int) ($mediaBacklog['media_updated'] ?? 0),
            'media_failed' => (int) ($mediaBacklog['media_failed'] ?? 0),
        ], collect($results)->mapWithKeys(
            static fn (array $result, string $key): array => [
                'last_'.$key => $result,
            ],
        )->all());
        $run->refresh();
        $summary = $run->summary ?? [];
        unset($summary[self::QUEUED_FINALIZATION_CHECKPOINT]);
        $run->fill([
            'status' => $run->completionStatus(),
            'summary' => $summary,
            'finished_at' => now(),
            'last_progress_at' => now(),
            'last_heartbeat_at' => now(),
        ])->save();
        $this->contentRequests->link($run->refresh());

        return [
            'status' => $run->status,
            'completed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @param  list<string>|null  $pageTypes
     */
    private function runCycle(
        SeasonvarImportRun $run,
        int $cycle,
        ?string $argument,
        bool $force,
        bool $discover,
        callable $progress,
        ?array $pageTypes,
        bool $refreshMediaSizes,
        bool $forceMediaSizes,
        ?int $mediaSizeLimit,
        ?int $mediaSizeTimeBudgetSeconds,
        bool $queueRecommendations,
    ): void {
        $progress('seasonvar-import-cycle-started', [
            'cycle' => $cycle,
        ]);

        if ($refreshMediaSizes) {
            $mediaSizeBacklogResult = $this->maintenance->refreshMediaFileSizes(
                $progress,
                $forceMediaSizes,
                $mediaSizeLimit,
                $mediaSizeTimeBudgetSeconds,
                fn (): bool => $this->stopRequested,
            );
            $this->addRunCounters($run, ['cycles' => 1], [
                'media_size_only' => true,
                'last_media_size_backlog' => $mediaSizeBacklogResult,
            ]);
            $progress('seasonvar-import-cycle-complete', [
                'cycle' => $cycle,
                'media_size_only' => true,
                ...$mediaSizeBacklogResult,
            ]);

            return;
        }

        if ($argument !== null) {
            $cycleResult = $this->runUrlCycle($run, $argument, $force, $progress);
            $targetedCatalogTitleId = $cycleResult['catalog_title_id'];

            if ($targetedCatalogTitleId !== null) {
                $this->recommendationDirtyTitles->mark($targetedCatalogTitleId, 'targeted-import');
            }

            $this->addRunCounters($run, [
                'cycles' => 1,
            ], [
                'targeted_maintenance_skipped' => true,
                'last_targeted_catalog_title_id' => $targetedCatalogTitleId,
            ]);

            $progress('seasonvar-import-cycle-complete', [
                'cycle' => $cycle,
                ...$cycleResult,
                'targeted_maintenance_skipped' => true,
            ]);

            return;
        }

        $storageMaintenanceResult = $this->maintenance->pruneStorage();
        $progress('seasonvar-import-storage-pruned', $storageMaintenanceResult);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'storage_maintenance')) {
            return;
        }

        $sourceAvailabilityBackfillResult = $this->maintenance
            ->backfillProviderAvailability($progress);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'provider_availability_backfill')) {
            return;
        }

        $metadataBackfillResult = $this->maintenance->backfillMetadata($progress);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'metadata_backfill')) {
            return;
        }

        $earlyRelationCleanupResult = $this->maintenance->cleanRelations($progress);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'early_relation_cleanup')) {
            return;
        }

        $sourceStatusBackfillResult = $this->maintenance->backfillSourceStatuses($progress);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'source_status_backfill')) {
            return;
        }

        $cycleResult = $this->runSitemapCycle($run, $force, $discover, $progress, $pageTypes);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'sitemap', $cycleResult)) {
            return;
        }

        $mediaMetadataResult = $this->maintenance->refreshMediaMetadata($progress);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'media_metadata_backlog')) {
            return;
        }

        $mediaSourceKeyResult = $this->maintenance->backfillMediaSourceKeys($progress);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'media_source_key_backlog')) {
            return;
        }

        $mediaBacklogResult = $this->maintenance->refreshMediaAvailability($progress);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'media_availability_backlog')) {
            return;
        }

        $mediaSizeBacklogResult = $this->maintenance->refreshMediaFileSizes(
            $progress,
            shouldStop: fn (): bool => $this->stopRequested,
        );

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'media_size_backlog')) {
            return;
        }

        $lateRelationCleanupResult = $this->maintenance->cleanRelations($progress);
        $relationCleanupResult = $this->mergeRelationCleanupResults($earlyRelationCleanupResult, $lateRelationCleanupResult);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'late_relation_cleanup')) {
            return;
        }

        $mergeResult = $this->maintenance->mergeTitles($progress);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'title_merge')) {
            return;
        }

        $recommendationResult = $queueRecommendations
            ? $this->queueFullRecommendationRebuild($progress)
            : $this->maintenance->rebuildRecommendations($progress);
        $recommendationSignalPruneResult = $this->maintenance->pruneRecommendationSignals(
            $recommendationResult,
            $progress,
        );

        $this->addRunCounters($run, [
            'cycles' => 1,
            'media_updated' => $mediaBacklogResult['media_updated'],
            'media_failed' => $mediaBacklogResult['media_failed'],
        ], [
            'last_storage_maintenance' => $storageMaintenanceResult,
            'last_provider_availability_backfill' => $sourceAvailabilityBackfillResult,
            'last_metadata_backfill' => $metadataBackfillResult,
            'last_merge' => $mergeResult,
            'last_source_status_backfill' => $sourceStatusBackfillResult,
            'last_media_metadata_backlog' => $mediaMetadataResult,
            'last_media_source_key_backlog' => $mediaSourceKeyResult,
            'last_media_backlog' => $mediaBacklogResult,
            'last_media_size_backlog' => $mediaSizeBacklogResult,
            'last_relation_cleanup' => $relationCleanupResult,
            'last_recommendations' => $recommendationResult,
            'last_recommendation_signal_prune' => $recommendationSignalPruneResult,
        ]);

        $progress('seasonvar-import-cycle-complete', [
            'cycle' => $cycle,
            ...$cycleResult,
            'storage_events_deleted' => $storageMaintenanceResult['events_deleted'],
            'storage_snapshots_deleted' => $storageMaintenanceResult['snapshots_deleted'],
            'provider_availability_pages_checked' => $sourceAvailabilityBackfillResult['pages_checked'],
            'provider_availability_pages_updated' => $sourceAvailabilityBackfillResult['pages_updated'],
            'provider_availability_region_blocked' => $sourceAvailabilityBackfillResult['region_blocked'],
            'metadata_pages_checked' => $metadataBackfillResult['pages_checked'],
            'metadata_pages_updated' => $metadataBackfillResult['pages_updated'],
            'metadata_titles_checked' => $metadataBackfillResult['titles_checked'],
            'metadata_titles_updated' => $metadataBackfillResult['titles_updated'],
            'metadata_relations_attached' => $metadataBackfillResult['relations_attached'],
            'metadata_failed' => $metadataBackfillResult['failed'],
            'source_status_backfilled' => $sourceStatusBackfillResult['backfilled'],
            'media_metadata_checked' => $mediaMetadataResult['media_checked'],
            'media_metadata_updated' => $mediaMetadataResult['media_updated'],
            'media_source_keys_checked' => $mediaSourceKeyResult['media_checked'],
            'media_source_keys_updated' => $mediaSourceKeyResult['media_updated'],
            'media_checked' => $mediaBacklogResult['media_checked'],
            'media_check_available' => $mediaBacklogResult['media_available'],
            'media_check_unavailable' => $mediaBacklogResult['media_unavailable'],
            'media_sizes_selected' => $mediaSizeBacklogResult['selected'],
            'media_sizes_processed' => $mediaSizeBacklogResult['processed'],
            'media_sizes_changed' => $mediaSizeBacklogResult['changed'],
            'relations_checked' => $relationCleanupResult['checked'],
            'relation_records_removed' => $relationCleanupResult['records_removed'],
            'relation_links_removed' => $relationCleanupResult['links_removed'],
            'merged_titles' => $mergeResult['titles'],
            'merged_seasons' => $mergeResult['seasons'],
            'merged_episodes' => $mergeResult['episodes'],
            'recommendation_titles' => $recommendationResult['titles'],
            'recommendation_titles_without_recommendations' => $recommendationResult['titles_without_recommendations'],
            'recommendations_stored' => $recommendationResult['stored'],
            'recommendations_duration_ms' => $recommendationResult['duration_ms'],
            'recommendation_signals_pruned' => $recommendationSignalPruneResult['deleted'],
        ]);
    }

    /** @param callable(string, array<string, mixed>): void $progress */
    private function queueFullRecommendationRebuild(callable $progress): array
    {
        RebuildCatalogRecommendations::dispatch();

        $result = [
            'queued' => true,
            'deferred' => true,
            'activated' => false,
            'gate_passed' => false,
            'algorithm_version' => null,
            'titles' => 0,
            'titles_without_recommendations' => 0,
            'stored' => 0,
            'duration_ms' => 0,
        ];
        $progress('catalog-recommendations-queued-for-worker', $result);

        return $result;
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @param  array<string, mixed>  $context
     */
    private function finishStoppedCycle(
        SeasonvarImportRun $run,
        int $cycle,
        callable $progress,
        string $phase,
        array $context = [],
    ): bool {
        if (! $this->stopRequested) {
            return false;
        }

        $this->addRunCounters($run, ['cycles' => 1], [
            'last_stop' => [
                'phase' => $phase,
                'requested_at' => now()->toIso8601String(),
            ],
        ]);

        $progress('seasonvar-import-cycle-stopped', [
            'cycle' => $cycle,
            'phase' => $phase,
            ...$context,
        ]);

        return true;
    }

    /**
     * @param  array<string, int>  ...$results
     * @return array<string, int>
     */
    private function mergeRelationCleanupResults(array ...$results): array
    {
        $merged = [
            'checked' => 0,
            'records_removed' => 0,
            'links_removed' => 0,
            'records_merged' => 0,
            'links_moved' => 0,
            'duplicate_links_removed' => 0,
            'records_canonicalized' => 0,
            'legacy_records_removed' => 0,
            'legacy_links_removed' => 0,
            'affected_titles' => 0,
        ];

        foreach ($results as $result) {
            foreach ($merged as $key => $value) {
                $merged[$key] = $value + (int) ($result[$key] ?? 0);
            }
        }

        return $merged;
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @param  list<string>|null  $pageTypes
     * @return array{discovered: int, stored: int, selected: int, parsed: int, failed: int, media_attached: int, media_updated: int, media_skipped: int, media_failed: int, cleaned: int, stopped: bool}
     */
    private function runSitemapCycle(
        SeasonvarImportRun $run,
        bool $force,
        bool $discover,
        callable $progress,
        ?array $pageTypes,
    ): array {
        $discovered = 0;
        $stored = 0;

        if ($discover) {
            $mirror = $this->sitemapMirror->mirror($progress);
            $urls = $mirror['urls'];
            $discovered = count($urls);
            $stored = $this->importer->storeDiscoveredUrls($urls, $progress);
        }

        $cleaned = $this->cleanupMalformedSourcePages($progress);

        if ($discover || $cleaned > 0) {
            $this->addRunCounters($run, [
                'discovered' => $discovered,
                'stored' => $stored,
            ], [
                'last_discovery' => [
                    'discovered' => $discovered,
                    'stored' => $stored,
                    'cleaned' => $cleaned,
                ],
            ]);
        }

        $selected = 0;
        $parseResult = [
            'parsed' => 0,
            'failed' => 0,
            'media_attached' => 0,
            'media_updated' => 0,
            'media_skipped' => 0,
            'media_failed' => 0,
        ];

        foreach ($this->pageChunksForImportCycle($force, $run->id, $progress, $pageTypes) as $pages) {
            if ($this->stopRequested) {
                break;
            }

            $chunkResult = $this->catalogCache->deferPublicInvalidation(
                fn (): array => $this->releaseCalendarCache->deferPublicInvalidation(
                    fn (): array => $this->importer->parsePages(
                        pages: $pages,
                        progress: $progress,
                        force: $force,
                        importRunId: $run->id,
                        shouldStop: fn (): bool => $this->stopRequested,
                    ),
                ),
            );
            $selected += $chunkResult['selected'];
            $chunkCounters = [
                'selected' => $chunkResult['selected'],
                'parsed' => $chunkResult['parsed'],
                'failed' => $chunkResult['failed'],
                'media_attached' => $chunkResult['media_attached'],
                'media_updated' => $chunkResult['media_updated'],
                'media_skipped' => $chunkResult['media_skipped'],
                'media_failed' => $chunkResult['media_failed'],
            ];

            $this->addRunCounters($run, $chunkCounters, [
                'last_page_chunk' => [
                    ...$chunkCounters,
                    'selected_total' => $selected,
                ],
            ]);

            $parseResult['parsed'] += $chunkResult['parsed'];
            $parseResult['failed'] += $chunkResult['failed'];
            $parseResult['media_attached'] += $chunkResult['media_attached'];
            $parseResult['media_updated'] += $chunkResult['media_updated'];
            $parseResult['media_skipped'] += $chunkResult['media_skipped'];
            $parseResult['media_failed'] += $chunkResult['media_failed'];

            $progress('seasonvar-import-page-chunk-complete', [
                ...$chunkCounters,
                'selected_total' => $selected,
                'parsed_total' => $parseResult['parsed'],
                'failed_total' => $parseResult['failed'],
            ]);

            if ($this->stopRequested) {
                break;
            }
        }

        return [
            'discovered' => $discovered,
            'stored' => $stored,
            'selected' => $selected,
            'parsed' => $parseResult['parsed'],
            'failed' => $parseResult['failed'],
            'media_attached' => $parseResult['media_attached'],
            'media_updated' => $parseResult['media_updated'],
            'media_skipped' => $parseResult['media_skipped'],
            'media_failed' => $parseResult['media_failed'],
            'cleaned' => $cleaned,
            'stopped' => $this->stopRequested,
        ];
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array{discovered: int, stored: int, selected: int, parsed: int, failed: int, media_attached: int, media_updated: int, media_skipped: int, media_failed: int, cleaned: int, catalog_title_id: int|null}
     */
    private function runUrlCycle(SeasonvarImportRun $run, string $argument, bool $force, callable $progress): array
    {
        $parsedUrls = collect();
        $selected = 0;
        $parsed = 0;
        $failed = 0;
        $mediaAttached = 0;
        $mediaUpdated = 0;
        $mediaSkipped = 0;
        $mediaFailed = 0;

        try {
            $catalogTitle = $this->parseUrl($run, $argument, $force, $progress, $parsedUrls);
        } catch (Throwable $exception) {
            $catalogTitle = null;
            $parsedUrls->push([
                'url' => $argument,
                'parsed' => 0,
                'failed' => 1,
                'media_attached' => 0,
                'media_updated' => 0,
                'media_skipped' => 0,
                'media_failed' => 0,
            ]);
            $this->addParsedUrlCounters($run, $parsedUrls->last());
            $progress('seasonvar-import-url-failed', [
                'url' => $argument,
                'exception' => $exception::class,
                'message' => $this->errors->fromException($exception),
            ]);
        }

        if ($catalogTitle !== null) {
            $this->parseSeasonUrls($run, $catalogTitle, $force, $progress, $parsedUrls);
        }

        foreach ($parsedUrls as $item) {
            $selected += 1;
            $parsed += (int) $item['parsed'];
            $failed += (int) $item['failed'];
            $mediaAttached += (int) $item['media_attached'];
            $mediaUpdated += (int) $item['media_updated'];
            $mediaSkipped += (int) $item['media_skipped'];
            $mediaFailed += (int) $item['media_failed'];
        }

        return [
            'discovered' => 0,
            'stored' => 0,
            'selected' => $selected,
            'parsed' => $parsed,
            'failed' => $failed,
            'media_attached' => $mediaAttached,
            'media_updated' => $mediaUpdated,
            'media_skipped' => $mediaSkipped,
            'media_failed' => $mediaFailed,
            'cleaned' => 0,
            'catalog_title_id' => $catalogTitle !== null ? (int) $catalogTitle->id : null,
        ];
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @param  list<string>|null  $pageTypes
     * @return iterable<Collection<int, SourcePage>>
     */
    private function pageChunksForImportCycle(
        bool $force,
        ?int $importRunId,
        callable $progress,
        ?array $pageTypes,
    ): iterable {
        $chunkSize = $this->importChunkSize();
        $refreshAfter = now()->subHours(max(1, (int) config('seasonvar.import.refresh_after_hours', 168)));

        $chunks = $force
            ? $this->refreshPlanner->forcedPageChunks($chunkSize, $importRunId, $progress, $pageTypes)
            : $this->refreshPlanner->pageChunksForImportCycle($chunkSize, $refreshAfter, $importRunId, $progress, $pageTypes);

        foreach ($chunks as $pages) {
            foreach ($pages as $page) {
                $this->recordProgress(null, $progress, 'source-page-selected', [
                    'source_page_id' => $page->id,
                    'page_type' => $page->page_type,
                    'parse_status' => $page->parse_status,
                    'import_status' => $page->import_status,
                    'http_status' => $page->http_status,
                    'last_crawled_at' => $page->last_crawled_at,
                    'url' => $page->url,
                ]);
            }

            yield $pages;
        }
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     */
    private function cleanupMalformedSourcePages(callable $progress): int
    {
        $malformedPages = SourcePage::query()
            ->where('url', 'like', '%.html/%')
            ->where(function ($query): void {
                $query->where('parse_status', '!=', 'failed')
                    ->orWhere('import_status', '!=', 'gone')
                    ->orWhereNull('error_message');
            });

        $count = (clone $malformedPages)->count();

        if ($count === 0) {
            return 0;
        }

        $malformedPages->update([
            'parse_status' => 'failed',
            'import_status' => 'gone',
            'error_message' => 'Некорректная склеенная ссылка',
            'retry_after_at' => now()->addDays(30),
            'failure_count' => DB::raw('failure_count + 1'),
            'updated_at' => now(),
        ]);

        $progress('source-pages-malformed-cleaned', [
            'total' => $count,
        ]);

        return $count;
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @param  Collection<int, array<string, mixed>>  $parsedUrls
     */
    private function parseUrl(
        SeasonvarImportRun $run,
        string $url,
        bool $force,
        callable $progress,
        Collection $parsedUrls,
        ?CatalogTitle $preferredCatalogTitle = null,
    ): ?CatalogTitle {
        $pages = $this->importer->pagesForArgument($url, $progress);
        $page = $pages->first();

        if ($page === null) {
            return null;
        }

        if ($parsedUrls->contains('url', $page->url)) {
            return $this->catalogTitleForPage($page);
        }

        $result = $this->importer->parsePage($page, $progress, $force, $run->id, $preferredCatalogTitle);
        $page->refresh();

        $parsedUrls->push([
            'url' => $page->url,
            'parsed' => $result['catalog_title'] === null ? 0 : 1,
            'failed' => 0,
            'media_attached' => $result['media_attached'],
            'media_updated' => $result['media_updated'],
            'media_skipped' => $result['media_skipped'],
            'media_failed' => $result['media_failed'],
        ]);
        $this->addParsedUrlCounters($run, $parsedUrls->last());

        return $result['catalog_title'] ?? $this->catalogTitleForPage($page);
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @param  Collection<int, array<string, mixed>>  $parsedUrls
     */
    private function parseSeasonUrls(SeasonvarImportRun $run, CatalogTitle $catalogTitle, bool $force, callable $progress, Collection $parsedUrls): void
    {
        $seasonUrls = $catalogTitle->fresh(['seasons'])?->seasons
            ->pluck('source_url')
            ->filter()
            ->unique()
            ->filter(fn (string $seasonUrl): bool => $this->isDirectSeasonvarSeasonUrl($seasonUrl))
            ->values() ?? collect();

        $progress('seasonvar-import-season-urls-selected', [
            'catalog_title_id' => $catalogTitle->id,
            'title' => $catalogTitle->title,
            'selected' => $seasonUrls->count(),
        ]);

        foreach ($seasonUrls as $seasonUrl) {
            try {
                $this->parseUrl($run, (string) $seasonUrl, $force, $progress, $parsedUrls, $catalogTitle);
            } catch (Throwable $exception) {
                $parsedUrls->push([
                    'url' => (string) $seasonUrl,
                    'parsed' => 0,
                    'failed' => 1,
                    'media_attached' => 0,
                    'media_updated' => 0,
                    'media_skipped' => 0,
                    'media_failed' => 0,
                ]);
                $this->addParsedUrlCounters($run, $parsedUrls->last());
                $progress('seasonvar-import-season-url-failed', [
                    'catalog_title_id' => $catalogTitle->id,
                    'url' => (string) $seasonUrl,
                    'exception' => $exception::class,
                    'message' => $this->errors->fromException($exception),
                ]);
            }
        }
    }

    private function importChunkSize(): int
    {
        return max(1, (int) config('seasonvar.import.chunk_size', 100));
    }

    private function isDirectSeasonvarSeasonUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';

        return in_array($host, ['seasonvar.ru', 'www.seasonvar.ru'], true)
            && preg_match('~^/serial-\d+-[^/]+(?:-0*\d{1,4}-+(?:season|sezon))?\.html$~iu', $path) === 1;
    }

    private function catalogTitleForPage(SourcePage $page): ?CatalogTitle
    {
        return CatalogTitle::query()
            ->select([
                'id', 'source_id', 'source_page_id', 'external_id', 'title', 'original_title', 'type', 'year',
                'description', 'poster_url', 'source_url', 'source_url_hash', 'content_hash', 'provider_field_values',
            ])
            ->where('source_page_id', $page->id)
            ->orWhere(function ($query) use ($page): void {
                $query->where('source_id', $page->source_id)
                    ->where('source_url_hash', $page->url_hash);
            })
            ->first()
            ?? Season::query()
                ->with('catalogTitle:id,source_id,source_page_id,external_id,title,original_title,type,year,description,poster_url,source_url,source_url_hash,content_hash,provider_field_values')
                ->where('source_url_hash', $page->url_hash)
                ->first()
                ?->catalogTitle;
    }

    /**
     * @param  array{url: string, parsed: int, failed: int, media_attached: int, media_updated: int, media_skipped: int, media_failed: int}  $item
     */
    private function addParsedUrlCounters(SeasonvarImportRun $run, array $item): void
    {
        $this->addRunCounters($run, [
            'selected' => 1,
            'parsed' => $item['parsed'],
            'failed' => $item['failed'],
            'media_attached' => $item['media_attached'],
            'media_updated' => $item['media_updated'],
            'media_skipped' => $item['media_skipped'],
            'media_failed' => $item['media_failed'],
        ], [
            'last_url' => $item,
        ]);
    }

    /**
     * @param  array<string, int>  $counters
     * @param  array<string, mixed>  $summary
     */
    private function addRunCounters(SeasonvarImportRun $run, array $counters, array $summary = []): void
    {
        $run->refresh();
        $increments = array_filter([
            'cycles' => (int) ($counters['cycles'] ?? 0),
            'discovered' => (int) ($counters['discovered'] ?? 0),
            'stored' => (int) ($counters['stored'] ?? 0),
            'selected' => (int) ($counters['selected'] ?? 0),
            'parsed' => (int) ($counters['parsed'] ?? 0),
            'failed' => (int) ($counters['failed'] ?? 0),
            'media_attached' => (int) ($counters['media_attached'] ?? 0),
            'media_updated' => (int) ($counters['media_updated'] ?? 0),
            'media_skipped' => (int) ($counters['media_skipped'] ?? 0),
            'media_failed' => (int) ($counters['media_failed'] ?? 0),
        ], fn (int $amount): bool => $amount !== 0);

        SeasonvarImportRun::query()
            ->whereKey($run->id)
            ->incrementEach($increments, [
                'summary' => array_merge($run->summary ?? [], $summary),
                'last_progress_at' => now(),
                'last_heartbeat_at' => now(),
            ]);
        $run->refresh();
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function recordProgress(?SeasonvarImportRun $run, ?callable $progress, string $event, array $context = []): void
    {
        if ($run !== null) {
            $this->touchRunHeartbeat($run);
            $this->recordMediaSizeCounters($run, $event, $context);
            $this->recordImportEvent($run, $event, $context);
        }

        if ($progress !== null) {
            $progress($event, $context);
        }
    }

    /** @param array<string, mixed> $context */
    private function recordMediaSizeCounters(SeasonvarImportRun $run, string $event, array $context): void
    {
        $counters = match ($event) {
            'seasonvar-media-size-known' => [
                'media_sizes_checked' => 1,
                'media_sizes_known' => 1,
                'media_size_known_bytes' => max(0, (int) ($context['file_size_bytes'] ?? 0)),
            ],
            'seasonvar-media-size-unknown' => [
                'media_sizes_checked' => 1,
                'media_sizes_unknown' => 1,
            ],
            'seasonvar-media-size-unsupported' => [
                'media_sizes_checked' => 1,
                'media_sizes_unsupported' => 1,
            ],
            'seasonvar-media-size-check-failed' => [
                'media_sizes_checked' => 1,
                'media_size_checks_failed' => 1,
            ],
            default => [],
        };

        if ($counters !== []) {
            $this->runRecorder->addCounters((int) $run->id, $counters);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordImportEvent(SeasonvarImportRun $run, string $event, array $context): void
    {
        $this->eventRecorder->record(
            event: $event,
            context: $context,
            importRunId: (int) $run->id,
            sourcePageId: is_numeric($context['source_page_id'] ?? null)
                ? (int) $context['source_page_id']
                : null,
            catalogTitleId: is_numeric($context['catalog_title_id'] ?? null)
                ? (int) $context['catalog_title_id']
                : null,
            level: $this->eventLevel($event),
        );
    }

    private function touchRunHeartbeat(SeasonvarImportRun $run): void
    {
        $now = now();

        if ($this->lastRunHeartbeatAt !== null && $this->lastRunHeartbeatAt->greaterThan($now->copy()->subSeconds(30))) {
            return;
        }

        try {
            SeasonvarImportRun::query()
                ->whereKey($run->id)
                ->update([
                    'last_heartbeat_at' => $now,
                    'updated_at' => $now,
                ]);
            $this->lastRunHeartbeatAt = $now;
        } catch (Throwable) {
            // Отметка активности не должна останавливать обновление каталога.
        }
    }

    private function eventLevel(string $event): string
    {
        if (str_contains($event, 'failed') || str_contains($event, 'invalid') || str_contains($event, 'blocked')) {
            return 'warning';
        }

        return 'info';
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     */
    private function sleepBetweenCycles(int $sleepSeconds, callable $progress): void
    {
        $this->recordProgress(null, $progress, 'seasonvar-import-sleep-started', [
            'seconds' => $sleepSeconds,
        ]);

        for ($second = 0; $second < $sleepSeconds && ! $this->stopRequested; $second++) {
            sleep(1);
        }
    }
}
