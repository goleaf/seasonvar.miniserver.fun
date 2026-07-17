<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Actions\Media\InspectLicensedMediaFileSize;
use App\Enums\MediaHealthStatus;
use App\Enums\SeasonvarImportStatus;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\SeasonvarImportEvent;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use App\Services\Catalog\CatalogMetadataDeduplicator;
use App\Services\Catalog\CatalogRecommendationDirtyTitleTracker;
use App\Services\Catalog\CatalogRecommendationSignalPruner;
use App\Services\Catalog\CatalogTitleRecommendationBuilder;
use App\Services\ContentRequests\ContentRequestImportRunLinker;
use App\Services\Media\ExternalMediaMetadata;
use App\Services\Media\LicensedMediaFileSizeBackfillBudget;
use App\Services\Media\LicensedMediaFileSizeBacklog;
use App\Services\Media\MediaSourceHealthManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class SeasonvarImportPipeline
{
    private bool $stopRequested = false;

    private ?Carbon $lastRunHeartbeatAt = null;

    public function __construct(
        private readonly SeasonvarCatalogImporter $importer,
        private readonly SeasonvarSitemapMirror $sitemapMirror,
        private readonly SeasonvarTitleMerger $titleMerger,
        private readonly SeasonvarMediaAvailabilityChecker $mediaAvailabilityChecker,
        private readonly MediaSourceHealthManager $mediaHealth,
        private readonly ExternalMediaMetadata $mediaMetadata,
        private readonly SeasonvarRefreshPlanner $refreshPlanner,
        private readonly CatalogTitleRecommendationBuilder $recommendations,
        private readonly CatalogRecommendationDirtyTitleTracker $recommendationDirtyTitles,
        private readonly CatalogRecommendationSignalPruner $recommendationSignalPruner,
        private readonly CatalogMetadataDeduplicator $metadataDeduplicator,
        private readonly SeasonvarImportStorageMaintenance $storageMaintenance,
        private readonly SeasonvarSourceAvailabilityBackfill $sourceAvailabilityBackfill,
        private readonly SeasonvarCatalogMetadataBackfill $metadataBackfill,
        private readonly SeasonvarImportErrorSanitizer $errors,
        private readonly InspectLicensedMediaFileSize $inspectFileSize,
        private readonly LicensedMediaFileSizeBacklog $fileSizeBacklog,
        private readonly SeasonvarImportRunRecorder $runRecorder,
        private readonly ContentRequestImportRunLinker $contentRequests,
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
        $loggedProgress = fn (string $event, array $context = []) => $this->recordProgress(
            $run,
            $progress,
            $event,
            $context,
        );

        try {
            $storageMaintenanceResult = $this->storageMaintenance->prune();
            $sourceAvailabilityBackfillResult = $this->sourceAvailabilityBackfill->run($loggedProgress);
            $metadataBackfillResult = $this->metadataBackfill->run($loggedProgress);
            $sourceStatusBackfillResult = $this->backfillParsedSourcePageStatuses($loggedProgress);
            $mediaMetadataResult = $this->refreshMediaMetadataBacklog($loggedProgress);
            $mediaSourceKeyResult = $this->backfillMediaSourceKeys($loggedProgress);
            $mediaBacklogResult = $this->refreshMediaBacklog($loggedProgress);
            $mediaSizeBacklogResult = $this->refreshMediaFileSizeBacklog($loggedProgress);
            $relationCleanupResult = $this->metadataDeduplicator->run($loggedProgress);
            $mergeResult = $this->titleMerger->merge($loggedProgress);
            $recommendationResult = $this->recommendations->rebuildDirty($loggedProgress);
            $recommendationSignalPruneResult = $this->pruneRecommendationSignalsAfterActivation(
                $recommendationResult,
                $loggedProgress,
            );

            $this->addRunCounters($run, [
                'cycles' => 1,
                'media_updated' => $mediaBacklogResult['media_updated'],
                'media_failed' => $mediaBacklogResult['media_failed'],
            ], [
                'last_storage_maintenance' => $storageMaintenanceResult,
                'last_provider_availability_backfill' => $sourceAvailabilityBackfillResult,
                'last_metadata_backfill' => $metadataBackfillResult,
                'last_source_status_backfill' => $sourceStatusBackfillResult,
                'last_media_metadata_backlog' => $mediaMetadataResult,
                'last_media_source_key_backlog' => $mediaSourceKeyResult,
                'last_media_backlog' => $mediaBacklogResult,
                'last_media_size_backlog' => $mediaSizeBacklogResult,
                'last_relation_cleanup' => $relationCleanupResult,
                'last_merge' => $mergeResult,
                'last_recommendations' => $recommendationResult,
                'last_recommendation_signal_prune' => $recommendationSignalPruneResult,
            ]);

            $run->refresh()->fill([
                'status' => $run->completionStatus(),
                'finished_at' => now(),
                'last_heartbeat_at' => now(),
            ])->save();
            $this->contentRequests->link($run->refresh());

            return $run->refresh();
        } catch (Throwable $exception) {
            $run->fill([
                'status' => 'failed',
                'last_error' => $this->errors->fromException($exception),
                'finished_at' => now(),
                'last_heartbeat_at' => now(),
            ])->save();
            $this->contentRequests->link($run->refresh());

            throw $exception;
        }
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
    ): void {
        $progress('seasonvar-import-cycle-started', [
            'cycle' => $cycle,
        ]);

        if ($refreshMediaSizes) {
            $mediaSizeBacklogResult = $this->refreshMediaFileSizeBacklog(
                $progress,
                $forceMediaSizes,
                $mediaSizeLimit,
                $mediaSizeTimeBudgetSeconds,
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

        $storageMaintenanceResult = $this->storageMaintenance->prune();
        $progress('seasonvar-import-storage-pruned', $storageMaintenanceResult);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'storage_maintenance')) {
            return;
        }

        $sourceAvailabilityBackfillResult = $this->sourceAvailabilityBackfill->run($progress);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'provider_availability_backfill')) {
            return;
        }

        $metadataBackfillResult = $this->metadataBackfill->run($progress);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'metadata_backfill')) {
            return;
        }

        $earlyRelationCleanupResult = $this->metadataDeduplicator->run($progress);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'early_relation_cleanup')) {
            return;
        }

        $sourceStatusBackfillResult = $this->backfillParsedSourcePageStatuses($progress);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'source_status_backfill')) {
            return;
        }

        $cycleResult = $this->runSitemapCycle($run, $force, $discover, $progress, $pageTypes);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'sitemap', $cycleResult)) {
            return;
        }

        $mediaMetadataResult = $this->refreshMediaMetadataBacklog($progress);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'media_metadata_backlog')) {
            return;
        }

        $mediaSourceKeyResult = $this->backfillMediaSourceKeys($progress);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'media_source_key_backlog')) {
            return;
        }

        $mediaBacklogResult = $this->refreshMediaBacklog($progress);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'media_availability_backlog')) {
            return;
        }

        $mediaSizeBacklogResult = $this->refreshMediaFileSizeBacklog($progress);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'media_size_backlog')) {
            return;
        }

        $lateRelationCleanupResult = $this->metadataDeduplicator->run($progress);
        $relationCleanupResult = $this->mergeRelationCleanupResults($earlyRelationCleanupResult, $lateRelationCleanupResult);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'late_relation_cleanup')) {
            return;
        }

        $mergeResult = $this->titleMerger->merge($progress);

        if ($this->finishStoppedCycle($run, $cycle, $progress, 'title_merge')) {
            return;
        }

        $recommendationResult = $this->recommendations->rebuildDirty($progress);
        $recommendationSignalPruneResult = $this->pruneRecommendationSignalsAfterActivation(
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

    /**
     * @param  array<string, mixed>  $recommendationResult
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{executed: bool, checked: int, deleted: int, failure: string|null}
     */
    private function pruneRecommendationSignalsAfterActivation(
        array $recommendationResult,
        ?callable $progress,
    ): array {
        $activatedShadowV6 = ($recommendationResult['algorithm_version'] ?? null) === 'v6'
            && is_numeric($recommendationResult['build_id'] ?? null)
            && (int) $recommendationResult['build_id'] > 0
            && ($recommendationResult['activated'] ?? false) === true
            && ($recommendationResult['gate_passed'] ?? false) === true;

        if (! $activatedShadowV6) {
            $result = [
                'executed' => false,
                'checked' => 0,
                'deleted' => 0,
                'failure' => null,
            ];
            $progress?->__invoke('catalog-recommendation-signals-prune-skipped', $result);

            return $result;
        }

        try {
            $pruned = $this->recommendationSignalPruner->prune($progress);

            return [
                'executed' => true,
                ...$pruned,
                'failure' => null,
            ];
        } catch (Throwable $exception) {
            $result = [
                'executed' => false,
                'checked' => 0,
                'deleted' => 0,
                'failure' => $this->errors->fromException($exception),
            ];
            $progress?->__invoke('catalog-recommendation-signals-prune-failed', $result);

            return $result;
        }
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
     * @return array{selected: int, backfilled: int}
     */
    private function backfillParsedSourcePageStatuses(callable $progress): array
    {
        $chunkSize = $this->importChunkSize();
        $selected = 0;
        $backfilled = 0;

        $progress('source-pages-status-backfill-started', [
            'chunk_size' => $chunkSize,
        ]);

        SourcePage::query()
            ->where('parse_status', 'parsed')
            ->where('import_status', 'pending')
            ->lazyById($chunkSize)
            ->chunk($chunkSize)
            ->each(function ($pages) use (&$selected, &$backfilled, $progress): void {
                $pages = $pages->collect();
                $selected += $pages->count();
                $backfilled += SourcePage::query()->whereKey($pages->pluck('id')->all())->update([
                    'import_status' => 'parsed',
                    'retry_after_at' => null,
                    'last_imported_at' => DB::raw('COALESCE(last_imported_at, last_crawled_at, updated_at)'),
                    'updated_at' => now(),
                ]);

                $progress('source-pages-status-backfill-chunk-complete', [
                    'selected' => $selected,
                    'backfilled' => $backfilled,
                ]);
            });

        $result = [
            'selected' => $selected,
            'backfilled' => $backfilled,
        ];

        $progress('source-pages-status-backfill-complete', $result);

        return $result;
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

            $chunkResult = $this->importer->parsePages(
                pages: $pages,
                progress: $progress,
                force: $force,
                importRunId: $run->id,
                shouldStop: fn (): bool => $this->stopRequested,
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
     * @return array{media_checked: int, media_available: int, media_unavailable: int, media_updated: int, media_failed: int}
     */
    private function refreshMediaBacklog(callable $progress): array
    {
        if (! (bool) config('seasonvar.media_check.enabled', true)) {
            return [
                'media_checked' => 0,
                'media_available' => 0,
                'media_unavailable' => 0,
                'media_updated' => 0,
                'media_failed' => 0,
            ];
        }

        $chunkSize = $this->mediaCheckChunkSize();
        $maxPerCycle = $this->mediaCheckMaxPerCycle();
        $mediaQuery = LicensedMedia::query()
            ->whereIn('health_status', [
                MediaHealthStatus::Active->value,
                MediaHealthStatus::Degraded->value,
                MediaHealthStatus::Unavailable->value,
            ])
            ->where(function ($query): void {
                $query->whereNull('next_check_at')
                    ->orWhere('next_check_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNotNull('playback_url')
                    ->orWhereNotNull('path');
            });

        $progress('seasonvar-media-backlog-started', [
            'chunk_size' => $chunkSize,
            'max_per_cycle' => $maxPerCycle,
        ]);

        $result = [
            'selected' => 0,
            'media_checked' => 0,
            'media_available' => 0,
            'media_unavailable' => 0,
            'media_updated' => 0,
            'media_failed' => 0,
        ];

        foreach ($mediaQuery->lazyById($chunkSize)->take($maxPerCycle)->chunk($chunkSize) as $mediaItems) {
            $mediaItems = $mediaItems->collect();
            $result['selected'] += $mediaItems->count();

            foreach ($mediaItems as $media) {
                $url = $media->playback_url ?: $media->path;

                $availability = $this->mediaAvailabilityChecker->check($url, $progress);
                $media = $this->mediaHealth->record($media, $availability);
                $this->recommendationDirtyTitles->mark((int) $media->catalog_title_id, 'media-health');

                $result['media_checked']++;
                $result['media_updated']++;

                if ($availability->available) {
                    $result['media_available']++;
                } else {
                    $result['media_failed']++;

                    if ($media->health_status === MediaHealthStatus::Unavailable) {
                        $result['media_unavailable']++;
                    }
                }
            }

            $progress('seasonvar-media-backlog-chunk-complete', $result);
        }

        $progress('seasonvar-media-backlog-complete', [
            'media_checked' => $result['media_checked'],
            'media_available' => $result['media_available'],
            'media_unavailable' => $result['media_unavailable'],
            'media_updated' => $result['media_updated'],
            'media_failed' => $result['media_failed'],
            'selected' => $result['selected'],
        ]);

        return [
            'media_checked' => $result['media_checked'],
            'media_available' => $result['media_available'],
            'media_unavailable' => $result['media_unavailable'],
            'media_updated' => $result['media_updated'],
            'media_failed' => $result['media_failed'],
        ];
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array{selected: int, processed: int, changed: int, stopped: bool, time_budget_seconds: int|null, time_budget_exhausted: bool, elapsed_milliseconds: int}
     */
    private function refreshMediaFileSizeBacklog(
        callable $progress,
        bool $force = false,
        ?int $requestedLimit = null,
        ?int $requestedTimeBudgetSeconds = null,
    ): array {
        $budget = LicensedMediaFileSizeBackfillBudget::start($requestedTimeBudgetSeconds);

        if (! (bool) config('seasonvar.media_file_size.enabled', true)) {
            return [
                'selected' => 0,
                'processed' => 0,
                'changed' => 0,
                'stopped' => false,
                'time_budget_seconds' => $budget->seconds,
                'time_budget_exhausted' => false,
                'elapsed_milliseconds' => $budget->elapsedMilliseconds(),
            ];
        }

        $chunkSize = max(1, min(
            500,
            (int) config('seasonvar.media_file_size.backfill_chunk_size', 25),
        ));
        $limit = max(1, min(
            100_000,
            $requestedLimit ?? (int) config('seasonvar.media_file_size.max_checks_per_import_cycle', 20),
        ));
        $query = $this->fileSizeBacklog->query($force)
            ->select([
                'id',
                'catalog_title_id',
                'season_id',
                'episode_id',
                'path',
                'playback_url',
                'format',
                'file_size_bytes',
                'file_size_checked_at',
                'file_size_check_status',
                'file_size_source',
                'file_size_http_status',
                'file_size_check_error',
            ])
            ->with([
                'catalogTitle:id,title',
                'season:id,number',
                'episode:id,number',
            ]);

        $progress('seasonvar-media-size-backlog-started', [
            'chunk_size' => $chunkSize,
            'max_per_cycle' => $limit,
            'force' => $force,
            ...($budget->seconds === null ? [] : [
                'time_budget_seconds' => $budget->seconds,
            ]),
        ]);

        $result = [
            'selected' => 0,
            'processed' => 0,
            'changed' => 0,
            'stopped' => false,
            'time_budget_seconds' => $budget->seconds,
            'time_budget_exhausted' => false,
            'elapsed_milliseconds' => 0,
        ];

        foreach ($query->lazyById($chunkSize)->take($limit) as $media) {
            if ($this->stopRequested) {
                $result['stopped'] = true;

                break;
            }

            if ($budget->exhausted()) {
                $result['time_budget_exhausted'] = true;
                $result['elapsed_milliseconds'] = $budget->elapsedMilliseconds();

                $progress('seasonvar-media-size-backlog-time-budget-exhausted', [
                    ...$result,
                    'remaining_seconds' => $budget->remainingSeconds(),
                ]);

                break;
            }

            $result['selected']++;

            if (! $this->inspectFileSize->shouldInspect($media, $force)) {
                continue;
            }

            $result['processed']++;
            $changed = $this->inspectFileSize->execute($media, $progress, $force, [
                'catalog_title' => $media->catalogTitle?->title,
                'season_number' => $media->season?->number,
                'episode_number' => $media->episode?->number,
            ]);
            $result['changed'] += $changed ? 1 : 0;
        }

        $result['elapsed_milliseconds'] = $budget->elapsedMilliseconds();
        $progressResult = $result;

        if ($progressResult['time_budget_seconds'] === null) {
            unset($progressResult['time_budget_seconds']);
        }

        $progress('seasonvar-media-size-backlog-complete', $progressResult);

        return $result;
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array{media_checked: int, media_updated: int}
     */
    private function refreshMediaMetadataBacklog(callable $progress): array
    {
        $chunkSize = $this->mediaMetadataChunkSize();
        $mediaQuery = LicensedMedia::query()
            ->where(function ($query): void {
                $query->whereNull('quality')
                    ->orWhereNull('format')
                    ->orWhere('format', '')
                    ->orWhereNull('translation_name')
                    ->orWhereNull('variant_type')
                    ->orWhere('variant_type', '')
                    ->orWhereNull('variant_key')
                    ->orWhere('variant_key', '');
            })
            ->where(function ($query): void {
                $query->whereNotNull('playback_url')
                    ->orWhereNotNull('path');
            });

        $progress('seasonvar-media-metadata-backlog-started', [
            'chunk_size' => $chunkSize,
        ]);

        $result = [
            'selected' => 0,
            'media_checked' => 0,
            'media_updated' => 0,
        ];

        foreach ($mediaQuery->lazyById($chunkSize)->chunk($chunkSize) as $mediaItems) {
            $mediaItems = $mediaItems->collect();
            $result['selected'] += $mediaItems->count();

            foreach ($mediaItems as $media) {
                $url = $media->playback_url ?: $media->path;

                if (trim($url) === '') {
                    continue;
                }

                $updates = [];
                $quality = $this->mediaMetadata->quality($media->title, $url);
                $format = $this->mediaMetadata->format($url);
                $translationName = $this->mediaMetadata->translationName($media->title, $media->source_url);
                $variant = $this->mediaMetadata->playbackVariant($media->title, $media->source_url, $url);

                if ($quality !== null && $quality !== $media->quality) {
                    $updates['quality'] = $quality;
                }

                if ($format !== '' && $format !== $media->format) {
                    $updates['format'] = $format;
                }

                if (($translationName !== null || $variant['has_subtitles']) && $translationName !== $media->translation_name) {
                    $updates['translation_name'] = $translationName;
                }

                foreach (['variant_type', 'variant_name', 'variant_key', 'has_subtitles'] as $attribute) {
                    if ($variant[$attribute] !== $media->{$attribute}) {
                        $updates[$attribute] = $variant[$attribute];
                    }
                }

                $result['media_checked']++;

                if ($updates === []) {
                    continue;
                }

                $media->fill($updates)->save();
                $result['media_updated']++;
                $this->recommendationDirtyTitles->mark((int) $media->catalog_title_id, 'media-metadata');

                $progress('seasonvar-media-metadata-updated', [
                    'licensed_media_id' => $media->id,
                    'quality' => $media->quality,
                    'format' => $media->format,
                    'translation_name' => $media->translation_name,
                    'variant_type' => $media->variant_type,
                    'variant_name' => $media->variant_name,
                    'variant_key' => $media->variant_key,
                    'has_subtitles' => $media->has_subtitles,
                    'url' => $url,
                ]);
            }

            $progress('seasonvar-media-metadata-backlog-chunk-complete', $result);
        }

        $progress('seasonvar-media-metadata-backlog-complete', [
            'media_checked' => $result['media_checked'],
            'media_updated' => $result['media_updated'],
            'selected' => $result['selected'],
        ]);

        return [
            'media_checked' => $result['media_checked'],
            'media_updated' => $result['media_updated'],
        ];
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array{media_checked: int, media_updated: int, collisions: int}
     */
    private function backfillMediaSourceKeys(callable $progress): array
    {
        $chunkSize = $this->mediaIdentityChunkSize();
        $mediaQuery = LicensedMedia::query()
            ->with([
                'catalogTitle:id,source_url_hash,source_url',
                'season:id,number',
                'episode:id,number',
            ])
            ->where(function ($query): void {
                $query->whereNull('source_media_key')
                    ->orWhere('source_media_key', '');
            })
            ->where(function ($query): void {
                $query->whereNotNull('playback_url')
                    ->orWhereNotNull('path');
            });

        $progress('seasonvar-media-source-key-backlog-started', [
            'chunk_size' => $chunkSize,
        ]);

        $result = [
            'selected' => 0,
            'media_checked' => 0,
            'media_updated' => 0,
            'collisions' => 0,
        ];

        foreach ($mediaQuery->lazyById($chunkSize)->chunk($chunkSize) as $mediaItems) {
            $mediaItems = $mediaItems->collect();
            $result['selected'] += $mediaItems->count();

            foreach ($mediaItems as $media) {
                $url = $media->playback_url ?: $media->path;

                if (trim($url) === '') {
                    continue;
                }

                $quality = $media->quality ?: $this->mediaMetadata->quality($media->title, $url);
                $format = $media->format ?: $this->mediaMetadata->format($url);
                $source = $this->mediaIdentitySource($media);
                $sourceMediaKey = $this->mediaMetadata->sourceMediaKey(
                    $source,
                    $media->catalogTitle?->source_url_hash ?: $media->catalog_title_id,
                    $media->season?->number,
                    $media->episode?->number,
                    $media->source_url,
                    $url,
                    $media->title,
                    $quality,
                    $format,
                );

                if ($this->sourceMediaKeyAlreadyExists($media, $sourceMediaKey)) {
                    $sourceMediaKey = hash('sha256', implode('|', ['legacy_media_row', $media->id, $sourceMediaKey]));
                    $result['collisions']++;
                }

                $updates = [
                    'source_media_key' => $sourceMediaKey,
                ];

                if ($quality !== null && $quality !== $media->quality) {
                    $updates['quality'] = $quality;
                }

                if ($format !== '' && $format !== $media->format) {
                    $updates['format'] = $format;
                }

                $media->fill($updates)->save();
                $result['media_checked']++;
                $result['media_updated']++;

                $progress('seasonvar-media-source-key-updated', [
                    'licensed_media_id' => $media->id,
                    'source_media_key' => $sourceMediaKey,
                    'quality' => $media->quality,
                    'format' => $media->format,
                    'url' => $url,
                ]);
            }

            $progress('seasonvar-media-source-key-backlog-chunk-complete', $result);
        }

        $progress('seasonvar-media-source-key-backlog-complete', [
            'media_checked' => $result['media_checked'],
            'media_updated' => $result['media_updated'],
            'collisions' => $result['collisions'],
            'selected' => $result['selected'],
        ]);

        return [
            'media_checked' => $result['media_checked'],
            'media_updated' => $result['media_updated'],
            'collisions' => $result['collisions'],
        ];
    }

    private function mediaIdentitySource(LicensedMedia $media): string
    {
        return match ($media->storage_disk) {
            'seasonvar_parsed' => 'seasonvar',
            'external_playlist' => 'external_playlist',
            default => $media->storage_disk ?: 'legacy_media',
        };
    }

    private function sourceMediaKeyAlreadyExists(LicensedMedia $media, string $sourceMediaKey): bool
    {
        return LicensedMedia::query()
            ->where('catalog_title_id', $media->catalog_title_id)
            ->where('source_media_key', $sourceMediaKey)
            ->whereKeyNot($media->id)
            ->exists();
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

    private function mediaCheckChunkSize(): int
    {
        return max(1, (int) config('seasonvar.media_check.chunk_size', 25));
    }

    private function mediaCheckMaxPerCycle(): int
    {
        return max(1, (int) config('seasonvar.media_check.max_per_cycle', 20));
    }

    private function mediaMetadataChunkSize(): int
    {
        return max(1, (int) config('seasonvar.media_metadata.chunk_size', 100));
    }

    private function mediaIdentityChunkSize(): int
    {
        return max(1, (int) config('seasonvar.media_identity.chunk_size', 250));
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

        $run->incrementEachQuietly($increments, [
            'summary' => array_merge($run->summary ?? [], $summary),
        ]);
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
        try {
            $storedContext = $this->storageMaintenance->sanitizeEventContext($context);

            SeasonvarImportEvent::query()->create([
                'seasonvar_import_run_id' => $run->id,
                'source_page_id' => $context['source_page_id'] ?? null,
                'catalog_title_id' => $context['catalog_title_id'] ?? null,
                'event' => $event,
                'level' => $this->eventLevel($event),
                'context' => $storedContext,
            ]);
        } catch (Throwable) {
            // Журнал событий не должен останавливать обновление каталога.
        }
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
