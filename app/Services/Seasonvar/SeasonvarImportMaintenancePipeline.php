<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Actions\Media\InspectLicensedMediaFileSize;
use App\Enums\MediaHealthStatus;
use App\Enums\SeasonvarImportFinalizationStage;
use App\Models\LicensedMedia;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use App\Services\Catalog\CatalogMetadataDeduplicator;
use App\Services\Catalog\CatalogRecommendationDirtyTitleTracker;
use App\Services\Catalog\CatalogRecommendationSignalPruner;
use App\Services\Catalog\CatalogTitleRecommendationBuilder;
use App\Services\Media\ExternalMediaMetadata;
use App\Services\Media\LicensedMediaFileSizeBackfillBudget;
use App\Services\Media\LicensedMediaFileSizeBacklog;
use App\Services\Media\LicensedMediaFileSizeScheduleProjection;
use App\Services\Media\MediaSourceHealthManager;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final readonly class SeasonvarImportMaintenancePipeline
{
    public function __construct(
        private SeasonvarImportStorageMaintenance $storageMaintenance,
        private SeasonvarSourceAvailabilityBackfill $sourceAvailability,
        private SeasonvarCatalogMetadataBackfill $metadataBackfill,
        private CatalogMetadataDeduplicator $metadataDeduplicator,
        private SeasonvarTitleMerger $titleMerger,
        private CatalogTitleRecommendationBuilder $recommendations,
        private CatalogRecommendationSignalPruner $recommendationSignals,
        private SeasonvarMediaAvailabilityChecker $mediaAvailabilityChecker,
        private MediaSourceHealthManager $mediaHealth,
        private ExternalMediaMetadata $mediaMetadata,
        private CatalogRecommendationDirtyTitleTracker $recommendationDirtyTitles,
        private SeasonvarImportErrorSanitizer $errors,
        private InspectLicensedMediaFileSize $inspectFileSize,
        private LicensedMediaFileSizeBacklog $fileSizeBacklog,
        private LicensedMediaFileSizeScheduleProjection $fileSizeProjection,
        private SeasonvarImportFinalizationCoordinator $finalization,
    ) {}

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array<string, mixed>
     */
    public function executeQueuedStage(
        SeasonvarImportRun $run,
        SeasonvarImportFinalizationStage $stage,
        callable $progress,
    ): array {
        return match ($stage) {
            SeasonvarImportFinalizationStage::StorageMaintenance => $this->pruneStorage(),
            SeasonvarImportFinalizationStage::ProviderAvailability => $this->backfillProviderAvailability($progress),
            SeasonvarImportFinalizationStage::MetadataBackfill => $this->backfillMetadata($progress),
            SeasonvarImportFinalizationStage::SourceStatus => $this->backfillSourceStatuses($progress),
            SeasonvarImportFinalizationStage::MediaMetadata => $this->refreshMediaMetadata($progress),
            SeasonvarImportFinalizationStage::MediaSourceKey => $this->backfillMediaSourceKeys($progress),
            SeasonvarImportFinalizationStage::MediaAvailability => $this->refreshMediaAvailability($progress),
            SeasonvarImportFinalizationStage::MediaFileSize => $this->refreshMediaFileSizes($progress),
            SeasonvarImportFinalizationStage::RelationCleanup => $this->cleanRelations($progress),
            SeasonvarImportFinalizationStage::Merge => $this->mergeTitles($progress),
            SeasonvarImportFinalizationStage::Recommendations => $this->rebuildRecommendations(
                $progress,
                allowFullRebuild: false,
            ),
            SeasonvarImportFinalizationStage::RecommendationSignalPrune => $this->pruneRecommendationSignals(
                $this->finalization->stageResults($run)['recommendations'] ?? [],
                $progress,
            ),
            SeasonvarImportFinalizationStage::Terminal => throw new LogicException(
                'Terminal finalization belongs to the import run lifecycle.',
            ),
        };
    }

    /** @return array<string, mixed> */
    public function pruneStorage(): array
    {
        return $this->storageMaintenance->prune();
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array<string, mixed>
     */
    public function backfillProviderAvailability(callable $progress): array
    {
        return $this->sourceAvailability->run($progress);
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array<string, mixed>
     */
    public function backfillMetadata(callable $progress): array
    {
        return $this->metadataBackfill->run($progress);
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array<string, int>
     */
    public function cleanRelations(callable $progress): array
    {
        return $this->metadataDeduplicator->run($progress);
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array<string, int>
     */
    public function mergeTitles(callable $progress): array
    {
        return $this->titleMerger->merge($progress);
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array<string, mixed>
     */
    public function rebuildRecommendations(
        callable $progress,
        bool $allowFullRebuild = true,
    ): array {
        return $this->recommendations->rebuildDirty($progress, $allowFullRebuild);
    }

    /**
     * @param  array<string, mixed>  $recommendationResult
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{executed: bool, checked: int, deleted: int, failure: string|null}
     */
    public function pruneRecommendationSignals(
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

            if ($progress !== null) {
                $progress('catalog-recommendation-signals-prune-skipped', $result);
            }

            return $result;
        }

        try {
            return [
                'executed' => true,
                ...$this->recommendationSignals->prune($progress),
                'failure' => null,
            ];
        } catch (Throwable $exception) {
            $result = [
                'executed' => false,
                'checked' => 0,
                'deleted' => 0,
                'failure' => $this->errors->fromException($exception),
            ];

            if ($progress !== null) {
                $progress('catalog-recommendation-signals-prune-failed', $result);
            }

            return $result;
        }
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array{selected: int, backfilled: int}
     */
    public function backfillSourceStatuses(callable $progress): array
    {
        $chunkSize = $this->importChunkSize();
        $selected = 0;
        $backfilled = 0;

        $progress('source-pages-status-backfill-started', ['chunk_size' => $chunkSize]);
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
                    'last_imported_at' => DB::raw(
                        'COALESCE(last_imported_at, last_crawled_at, updated_at)',
                    ),
                    'updated_at' => now(),
                ]);
                $progress('source-pages-status-backfill-chunk-complete', [
                    'selected' => $selected,
                    'backfilled' => $backfilled,
                ]);
            });

        $result = ['selected' => $selected, 'backfilled' => $backfilled];
        $progress('source-pages-status-backfill-complete', $result);

        return $result;
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array{media_checked: int, media_available: int, media_unavailable: int, media_updated: int, media_failed: int}
     */
    public function refreshMediaAvailability(callable $progress): array
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
        $query = LicensedMedia::query()
            ->whereIn('health_status', [
                MediaHealthStatus::Active->value,
                MediaHealthStatus::Degraded->value,
                MediaHealthStatus::Unavailable->value,
            ])
            ->where(fn ($query) => $query
                ->whereNull('next_check_at')
                ->orWhere('next_check_at', '<=', now()))
            ->where(fn ($query) => $query
                ->whereNotNull('playback_url')
                ->orWhereNotNull('path'));
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

        foreach ($query->lazyById($chunkSize)->take($maxPerCycle)->chunk($chunkSize) as $items) {
            $items = $items->collect();
            $result['selected'] += $items->count();

            foreach ($items as $media) {
                $availability = $this->mediaAvailabilityChecker->check(
                    $media->playback_url ?: $media->path,
                    $progress,
                );
                $media = $this->mediaHealth->record($media, $availability);
                $this->recommendationDirtyTitles->mark(
                    (int) $media->catalog_title_id,
                    'media-health',
                );
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

        $progress('seasonvar-media-backlog-complete', $result);
        unset($result['selected']);

        return $result;
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @param  (callable(): bool)|null  $shouldStop
     * @return array{selected: int, processed: int, changed: int, stopped: bool, time_budget_seconds: int|null, time_budget_exhausted: bool, elapsed_milliseconds: int}
     */
    public function refreshMediaFileSizes(
        callable $progress,
        bool $force = false,
        ?int $requestedLimit = null,
        ?int $requestedTimeBudgetSeconds = null,
        ?callable $shouldStop = null,
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

        $chunkSize = max(
            1,
            min(500, (int) config('seasonvar.media_file_size.backfill_chunk_size', 25)),
        );
        $limit = max(
            1,
            min(
                100_000,
                $requestedLimit
                    ?? (int) config('seasonvar.media_file_size.max_checks_per_import_cycle', 20),
            ),
        );
        $this->fileSizeProjection->reconcileChunk(max(
            1,
            min(
                5_000,
                (int) config(
                    'seasonvar.media_file_size.projection_backfill_chunk_size',
                    500,
                ),
            ),
        ));
        $candidateIds = $this->fileSizeBacklog
            ->query($force)
            ->limit($limit)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $columns = [
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
        ];
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

        foreach (array_chunk($candidateIds, $chunkSize) as $candidateChunk) {
            $mediaById = LicensedMedia::query()
                ->whereKey($candidateChunk)
                ->select($columns)
                ->with([
                    'catalogTitle:id,title',
                    'season:id,number',
                    'episode:id,number',
                ])
                ->get()
                ->keyBy('id');

            foreach ($candidateChunk as $candidateId) {
                $media = $mediaById->get($candidateId);

                if (! $media instanceof LicensedMedia) {
                    continue;
                }

                if ($shouldStop !== null && $shouldStop()) {
                    $result['stopped'] = true;

                    break 2;
                }

                if ($budget->exhausted()) {
                    $result['time_budget_exhausted'] = true;
                    $result['elapsed_milliseconds'] = $budget->elapsedMilliseconds();
                    $progress('seasonvar-media-size-backlog-time-budget-exhausted', [
                        ...$result,
                        'remaining_seconds' => $budget->remainingSeconds(),
                    ]);

                    break 2;
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
        }

        $result['elapsed_milliseconds'] = $budget->elapsedMilliseconds();
        $progressResult = $result;

        if ($progressResult['time_budget_seconds'] === null) {
            unset($progressResult['time_budget_seconds']);
        }

        $progress('seasonvar-media-size-backlog-complete', $progressResult);
        $this->fileSizeBacklog->captureStatus();

        return $result;
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array{media_checked: int, media_updated: int}
     */
    public function refreshMediaMetadata(callable $progress): array
    {
        $chunkSize = $this->mediaMetadataChunkSize();
        $query = LicensedMedia::query()
            ->where(fn ($query) => $query
                ->whereNull('format')
                ->orWhere('format', '')
                ->orWhereNull('variant_type')
                ->orWhere('variant_type', '')
                ->orWhereNull('variant_key')
                ->orWhere('variant_key', ''))
            ->where(fn ($query) => $query
                ->whereNotNull('playback_url')
                ->orWhereNotNull('path'));
        $progress('seasonvar-media-metadata-backlog-started', ['chunk_size' => $chunkSize]);
        $result = ['selected' => 0, 'media_checked' => 0, 'media_updated' => 0];

        foreach ($query->lazyById($chunkSize)->chunk($chunkSize) as $items) {
            $items = $items->collect();
            $result['selected'] += $items->count();

            foreach ($items as $media) {
                $url = $media->playback_url ?: $media->path;

                if (trim($url) === '') {
                    continue;
                }

                $updates = [];
                $quality = $this->mediaMetadata->quality($media->title, $url);
                $format = $this->mediaMetadata->format($url);
                $translation = $this->mediaMetadata->translationName(
                    $media->title,
                    $media->source_url,
                );
                $variant = $this->mediaMetadata->playbackVariant(
                    $media->title,
                    $media->source_url,
                    $url,
                );

                if ($quality !== null && $quality !== $media->quality) {
                    $updates['quality'] = $quality;
                }

                if ($format !== '' && $format !== $media->format) {
                    $updates['format'] = $format;
                }

                if (($translation !== null || $variant['has_subtitles'])
                    && $translation !== $media->translation_name
                ) {
                    $updates['translation_name'] = $translation;
                }

                foreach (
                    ['variant_type', 'variant_name', 'variant_key', 'has_subtitles', 'subtitle_language'] as $attribute
                ) {
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
                $this->recommendationDirtyTitles->mark(
                    (int) $media->catalog_title_id,
                    'media-metadata',
                );
                $progress('seasonvar-media-metadata-updated', [
                    'licensed_media_id' => $media->id,
                    'quality' => $media->quality,
                    'format' => $media->format,
                    'translation_name' => $media->translation_name,
                    'variant_type' => $media->variant_type,
                    'variant_name' => $media->variant_name,
                    'variant_key' => $media->variant_key,
                    'has_subtitles' => $media->has_subtitles,
                    'subtitle_language' => $media->subtitle_language,
                    'url' => $url,
                ]);
            }

            $progress('seasonvar-media-metadata-backlog-chunk-complete', $result);
        }

        $progress('seasonvar-media-metadata-backlog-complete', $result);

        return [
            'media_checked' => $result['media_checked'],
            'media_updated' => $result['media_updated'],
        ];
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array{media_checked: int, media_updated: int, collisions: int}
     */
    public function backfillMediaSourceKeys(callable $progress): array
    {
        $chunkSize = $this->mediaIdentityChunkSize();
        $query = LicensedMedia::query()
            ->with([
                'catalogTitle:id,source_url_hash,source_url',
                'season:id,number',
                'episode:id,number',
            ])
            ->where(fn ($query) => $query
                ->whereNull('source_media_key')
                ->orWhere('source_media_key', ''))
            ->where(fn ($query) => $query
                ->whereNotNull('playback_url')
                ->orWhereNotNull('path'));
        $progress('seasonvar-media-source-key-backlog-started', ['chunk_size' => $chunkSize]);
        $result = [
            'selected' => 0,
            'media_checked' => 0,
            'media_updated' => 0,
            'collisions' => 0,
        ];

        foreach ($query->lazyById($chunkSize)->chunk($chunkSize) as $items) {
            $items = $items->collect();
            $result['selected'] += $items->count();

            foreach ($items as $media) {
                $url = $media->playback_url ?: $media->path;

                if (trim($url) === '') {
                    continue;
                }

                $quality = $media->quality
                    ?: $this->mediaMetadata->quality($media->title, $url);
                $format = $media->format ?: $this->mediaMetadata->format($url);
                $sourceMediaKey = $this->mediaMetadata->sourceMediaKey(
                    $this->mediaIdentitySource($media),
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
                    $sourceMediaKey = hash(
                        'sha256',
                        implode('|', ['legacy_media_row', $media->id, $sourceMediaKey]),
                    );
                    $result['collisions']++;
                }

                $updates = ['source_media_key' => $sourceMediaKey];

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

        $progress('seasonvar-media-source-key-backlog-complete', $result);

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

    private function sourceMediaKeyAlreadyExists(
        LicensedMedia $media,
        string $sourceMediaKey,
    ): bool {
        return LicensedMedia::query()
            ->where('catalog_title_id', $media->catalog_title_id)
            ->where('source_media_key', $sourceMediaKey)
            ->whereKeyNot($media->id)
            ->exists();
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
}
