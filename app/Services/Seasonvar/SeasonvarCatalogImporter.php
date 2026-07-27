<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Actions\Seasonvar\RecordSeasonvarPageFailure;
use App\DTOs\Seasonvar\SeasonvarPageHandlerResult;
use App\DTOs\Seasonvar\SeasonvarPreparedCatalogPage;
use App\Enums\SeasonvarImportFailureType;
use App\Enums\SeasonvarPageType;
use App\Exceptions\Seasonvar\SeasonvarSourceRequestException;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendationSignal;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\SourcePage;
use App\Models\SourcePageSnapshot;
use App\Services\Api\V1\Sync\CatalogSyncChangePublisher;
use App\Services\Catalog\CatalogRecommendationDirtyTitleTracker;
use App\Services\Catalog\Quality\CatalogTitleQualityDirtyTracker;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use App\Services\Crawler\PoliteHttpClient;
use App\Services\Seasonvar\PageHandlers\SeasonvarPageHandler;
use App\Services\Seasonvar\PageHandlers\SeasonvarPassivePageHandler;
use App\Services\Seasonvar\PageHandlers\SeasonvarSerialPageHandler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SeasonvarCatalogImporter
{
    public function __construct(
        private readonly SeasonvarSource $seasonvarSource,
        private readonly SeasonvarDiscovery $discovery,
        private readonly SeasonvarUrl $seasonvarUrl,
        private readonly SeasonvarPageHandlerRegistry $pageHandlers,
        private readonly SeasonvarDiscoveredPageStore $discoveredPages,
        private readonly PoliteHttpClient $httpClient,
        private readonly SeasonvarSourcePageFetcher $pageFetcher,
        private readonly SeasonvarCatalogPagePreparer $pagePreparer,
        private readonly SeasonvarCatalogParser $parser,
        private readonly SeasonvarCatalogTitleWriter $titleWriter,
        private readonly SeasonvarCatalogMediaSynchronizer $mediaSynchronizer,
        private readonly SeasonvarCatalogRelationSyncer $relationSyncer,
        private readonly SeasonvarDatabaseTransaction $databaseTransaction,
        private readonly SeasonvarReleaseObservationSynchronizer $releaseObservations,
        private readonly RecordSeasonvarPageFailure $recordPageFailure,
        private readonly SeasonvarTitlePageStateSynchronizer $titlePageStateSynchronizer,
        private readonly SeasonvarImportErrorSanitizer $errors,
        private readonly CatalogSearchIndexer $searchIndexer,
        private readonly SeasonvarCatalogMetadataProvenance $metadataProvenance,
        private readonly CatalogTitleQualityDirtyTracker $quality,
        private readonly CatalogSyncChangePublisher $syncChanges,
        private readonly CatalogRecommendationDirtyTitleTracker $recommendationDirtyTitles,
        private readonly SeasonvarImportEventRecorder $eventRecorder,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return list<string>
     */
    public function discover(?callable $progress = null): array
    {
        $source = $this->seasonvarSource->current();

        $this->report($progress, 'source-ready', [
            'source_id' => $source->id,
            'code' => $source->code,
            'base_url' => $source->base_url,
            'sitemap_url' => $this->seasonvarSource->sitemapUrl(),
            'crawl_delay_seconds' => (int) $source->crawl_delay_seconds,
        ]);

        return $this->discovery->discoverFromSitemap(
            $this->seasonvarSource->sitemapUrl(),
            (int) $source->crawl_delay_seconds,
            $progress,
        );
    }

    /**
     * @param  list<string>  $urls
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function storeDiscoveredUrls(array $urls, ?callable $progress = null): int
    {
        return $this->discoveredPages->store(
            $urls,
            $this->seasonvarSource->sitemapUrl(),
            progress: $progress,
        );
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return Collection<int, SourcePage>
     */
    public function pagesForArgument(mixed $argument, ?callable $progress = null): Collection
    {
        if ($argument === null) {
            $this->report($progress, 'page-selection-started', [
                'mode' => 'pending',
            ]);

            return $this->pendingPages($progress);
        }

        if (is_numeric($argument)) {
            $pages = SourcePage::query()
                ->with('source:id,code,base_url,crawl_delay_seconds')
                ->whereKey((int) $argument)
                ->get();

            $this->report($progress, 'page-selection-complete', [
                'mode' => 'id',
                'argument' => $argument,
                'selected' => $pages->count(),
            ]);

            foreach ($pages as $page) {
                $this->report($progress, 'source-page-selected', [
                    'source_page_id' => $page->id,
                    'page_type' => $page->page_type,
                    'parse_status' => $page->parse_status,
                    'http_status' => $page->http_status,
                    'url' => $page->url,
                ]);
            }

            return $pages;
        }

        try {
            $url = $this->seasonvarUrl->normalize((string) $argument);
        } catch (Throwable $exception) {
            $this->report($progress, 'url-invalid', [
                'argument' => $argument,
                'exception' => $exception::class,
                'message' => $this->errors->fromException($exception),
            ]);

            return collect();
        }

        $this->report($progress, 'url-normalized', [
            'argument' => $argument,
            'url' => $url,
        ]);

        if (! $this->seasonvarUrl->isAllowed($url)) {
            $this->report($progress, 'url-blocked', [
                'url' => $url,
            ]);

            return collect();
        }

        $source = $this->seasonvarSource->current();
        $urlHash = $this->seasonvarUrl->hash($url);

        $page = SourcePage::query()->firstOrNew(['url_hash' => $urlHash]);
        $wasExisting = $page->exists;
        $page->fill([
            'source_id' => $source->id,
            'url' => $url,
            'page_type' => $this->seasonvarUrl->pageType($url)->value,
            'discovered_from_url' => $this->seasonvarSource->sitemapUrl(),
        ]);

        if (! $wasExisting) {
            $page->parse_status = 'pending';
        }

        $page->save();
        $page->load('source:id,code,base_url,crawl_delay_seconds');

        $this->report($progress, $wasExisting ? 'source-page-updated' : 'source-page-created', [
            'mode' => 'url-argument',
            'source_page_id' => $page->id,
            'page_type' => $page->page_type,
            'parse_status' => $page->parse_status,
            'url_hash' => $urlHash,
            'url' => $url,
        ]);

        return collect([$page]);
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return Collection<int, SourcePage>
     */
    public function pendingPages(?callable $progress = null): Collection
    {
        $pageTypes = $this->pageHandlers->processingTypes();
        $this->report($progress, 'pending-pages-query-started', [
            'parse_status' => 'pending',
            'page_types' => $pageTypes,
        ]);

        $pages = SourcePage::query()
            ->with('source:id,code,base_url,crawl_delay_seconds')
            ->where('parse_status', 'pending')
            ->whereIn('page_type', $pageTypes)
            ->where(function (Builder $query): void {
                $query->whereNull('retry_after_at')
                    ->orWhere('retry_after_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(max(1, (int) config('seasonvar.import.chunk_size', 100)))
            ->get();

        $this->report($progress, 'pending-pages-query-complete', [
            'selected' => $pages->count(),
        ]);

        foreach ($pages as $page) {
            $this->report($progress, 'source-page-selected', [
                'source_page_id' => $page->id,
                'page_type' => $page->page_type,
                'parse_status' => $page->parse_status,
                'http_status' => $page->http_status,
                'last_crawled_at' => $page->last_crawled_at,
                'url' => $page->url,
            ]);
        }

        return $pages;
    }

    /**
     * @param  Collection<int, SourcePage>  $pages
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  (callable(): bool)|null  $shouldStop
     * @return array{selected: int, parsed: int, failed: int, media_attached: int, media_updated: int, media_skipped: int, media_failed: int, failures: list<string>, stopped: bool}
     */
    public function parsePages(
        Collection $pages,
        ?callable $progress = null,
        bool $force = false,
        ?int $importRunId = null,
        bool $retryTransient = false,
        ?callable $shouldStop = null,
    ): array {
        $selected = 0;
        $parsed = 0;
        $failed = 0;
        $mediaAttached = 0;
        $mediaUpdated = 0;
        $mediaSkipped = 0;
        $mediaFailed = 0;
        $failures = [];
        $total = $pages->count();

        $this->report($progress, 'parse-batch-started', [
            'total' => $total,
        ]);

        $position = 0;

        foreach ($pages as $page) {
            if ($shouldStop !== null && $shouldStop()) {
                break;
            }

            $position++;
            $selected++;

            $this->report($progress, 'parse-batch-item-started', [
                'index' => $position,
                'total' => $total,
                'source_page_id' => $page->id,
                'url' => $page->url,
            ]);

            try {
                $pageResult = $this->parsePage($page, $progress, $force, $importRunId);
                $mediaAttached += $pageResult['media_attached'];
                $mediaUpdated += $pageResult['media_updated'];
                $mediaSkipped += $pageResult['media_skipped'];
                $mediaFailed += $pageResult['media_failed'];
                $parsed++;

                $this->report($progress, 'parse-batch-item-complete', [
                    'index' => $position,
                    'total' => $total,
                    'source_page_id' => $page->id,
                    'parsed' => $parsed,
                    'failed' => $failed,
                    'media_attached' => $mediaAttached,
                    'media_updated' => $mediaUpdated,
                ]);
            } catch (Throwable $exception) {
                $failureType = $this->recordPageFailure->handle($page, $exception, $importRunId);
                $failed++;
                $failures[] = "{$page->url} ({$this->errors->fromException($exception)})";

                $this->report($progress, 'parse-batch-item-failed', [
                    'index' => $position,
                    'total' => $total,
                    'source_page_id' => $page->id,
                    'exception' => $exception::class,
                    'message' => $this->errors->fromException($exception),
                    'parsed' => $parsed,
                    'failed' => $failed,
                    'url' => $page->url,
                ]);

                if ($retryTransient && $failureType === SeasonvarImportFailureType::Transient) {
                    throw $exception;
                }
            }
        }

        $this->report($progress, 'parse-batch-complete', [
            'total' => $total,
            'selected' => $selected,
            'parsed' => $parsed,
            'failed' => $failed,
            'media_attached' => $mediaAttached,
            'media_updated' => $mediaUpdated,
            'media_skipped' => $mediaSkipped,
            'media_failed' => $mediaFailed,
            'stopped' => $selected < $total,
        ]);

        return [
            'selected' => $selected,
            'parsed' => $parsed,
            'failed' => $failed,
            'media_attached' => $mediaAttached,
            'media_updated' => $mediaUpdated,
            'media_skipped' => $mediaSkipped,
            'media_failed' => $mediaFailed,
            'failures' => $failures,
            'stopped' => $selected < $total,
        ];
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{catalog_title: CatalogTitle|null, media_attached: int, media_updated: int, media_skipped: int, media_failed: int}
     */
    public function parsePage(
        SourcePage $page,
        ?callable $progress = null,
        bool $force = false,
        ?int $importRunId = null,
        ?CatalogTitle $preferredCatalogTitle = null,
    ): array {
        $pageType = SeasonvarPageType::tryFrom((string) $page->page_type) ?? SeasonvarPageType::Unknown;
        $handler = $this->pageHandlers->handler($pageType);
        $definition = $this->pageHandlers->definition($pageType);

        $this->report($progress, 'seasonvar-page-handler-selected', [
            'source_page_id' => $page->id,
            'page_type' => $pageType->value,
            'parser' => $definition->parserClass,
            'importer' => $definition->importerClass,
            'metadata_only' => $definition->metadataOnly,
            'retry_behavior' => $definition->retryBehavior,
            'expected_result_type' => $definition->expectedResultType,
            'source_access' => $definition->sourceAccess,
            'publication_authorized' => $definition->publicationAuthorized,
        ]);

        if ($handler instanceof SeasonvarPassivePageHandler
            || ! $this->pageHandlers->isEnabled($pageType)
            || $definition->parserClass === null
            || $definition->importerClass === null) {
            $page->update([
                'import_status' => 'skipped',
                'last_import_run_id' => $importRunId,
                'last_imported_at' => now(),
            ]);
            $this->recordPageEvent($page, $importRunId, 'seasonvar-page-skipped', [
                'page_type' => $pageType->value,
                'reason' => $handler instanceof SeasonvarPassivePageHandler ? 'unsupported_type' : 'page_type_disabled',
            ]);

            return (new SeasonvarPageHandlerResult)->toLegacyResult();
        }

        if (! $handler instanceof SeasonvarSerialPageHandler) {
            return $this->parseMetadataPage($page, $handler, $progress, $importRunId);
        }

        $source = $page->source;
        $crawlDelaySeconds = (int) $source->crawl_delay_seconds;

        $this->report($progress, 'page-parse-started', [
            'source_page_id' => $page->id,
            'source_id' => $source->id,
            'page_type' => $page->page_type,
            'parse_status' => $page->parse_status,
            'crawl_delay_seconds' => $crawlDelaySeconds,
            'url' => $page->url,
        ]);

        $fetched = $this->pageFetcher->fetch($page, $importRunId, $progress);
        $contentHash = $fetched->contentHash;
        $contentChanged = $fetched->contentChanged;

        $existingCatalogTitle = $this->findCatalogTitleBySourceUrlHash($page, $this->seasonvarUrl->hash($page->url));

        if ($fetched->notModified && $existingCatalogTitle === null) {
            throw new RuntimeException('Seasonvar вернул 304 для страницы без ранее импортированного тайтла.');
        }

        $needsMediaRefresh = $existingCatalogTitle !== null
            && $this->catalogTitleNeedsMediaRefresh($existingCatalogTitle);

        if (! $force
            && ! $contentChanged
            && $page->parse_status === 'parsed'
            && $page->metadata_parser_version >= SeasonvarCatalogParser::METADATA_VERSION
            && $existingCatalogTitle !== null
            && ! $needsMediaRefresh) {
            $this->titlePageStateSynchronizer->synchronize($existingCatalogTitle, $page, $importRunId);
            $this->quality->mark([(int) $existingCatalogTitle->id]);

            if ($fetched->notModified) {
                $this->report($progress, 'page-parse-skipped-not-modified', [
                    'source_page_id' => $page->id,
                    'page_type' => SeasonvarPageType::Serial->value,
                    'catalog_title_id' => $existingCatalogTitle->id,
                ]);
            } else {
                $this->report($progress, 'page-parse-skipped-unchanged', [
                    'source_page_id' => $page->id,
                    'catalog_title_id' => $existingCatalogTitle->id,
                    'slug' => $existingCatalogTitle->slug,
                    'content_hash' => $contentHash,
                    'url' => $page->url,
                ]);
            }

            return [
                'catalog_title' => $existingCatalogTitle,
                'media_attached' => 0,
                'media_updated' => 0,
                'media_skipped' => 0,
                'media_failed' => 0,
            ];
        }

        $prepared = $this->pagePreparer->prepareFetched($page, $fetched, $progress);

        return $this->applyPreparedPage(
            $page,
            $prepared,
            $preferredCatalogTitle,
            $importRunId,
            $progress,
        );
    }

    /**
     * Apply a prepared payload to one canonical title without provider HTTP requests.
     *
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  (callable(CatalogTitle): void)|null  $afterCatalogCommit
     * @return array{catalog_title: CatalogTitle, media_attached: int, media_updated: int, media_skipped: int, media_failed: int}
     */
    public function applyPreparedPage(
        SourcePage $page,
        SeasonvarPreparedCatalogPage $prepared,
        ?CatalogTitle $preferredCatalogTitle = null,
        ?int $importRunId = null,
        ?callable $progress = null,
        bool $publishSyncChange = true,
        ?callable $afterCatalogCommit = null,
        bool $syncMediaTranslations = true,
    ): array {
        if ((int) $page->id !== $prepared->sourcePageId) {
            throw new RuntimeException('Подготовленная страница не соответствует исходной странице Seasonvar.');
        }

        $data = $prepared->catalogData;

        $this->report($progress, 'html-parse-started', [
            'source_page_id' => $page->id,
            'url' => $page->url,
        ]);

        $this->report($progress, 'html-parse-complete', [
            'source_page_id' => $page->id,
            'title' => $data->title,
            'original_title' => $data->originalTitle,
            'type' => $data->type,
            'year' => $data->year,
            'external_id' => $data->externalId,
            'poster_url' => $data->posterUrl,
            'seasons' => count($data->seasons),
            'episodes' => count($data->episodes),
            'media_candidates' => count($data->media),
            'taxonomies' => count($data->taxonomies),
            'ratings' => count($data->ratings),
            'aliases' => count($data->aliases),
            'reviews' => count($data->reviews),
            'info_labels' => $data->parseMeta['info_labels'] ?? [],
            'snapshot_complete' => [
                'metadata' => $data->hasCompleteMetadataSnapshot(),
                'seasons' => $data->hasCompleteSeasonSnapshot(),
                'episodes' => $data->hasCompleteEpisodeSnapshot(),
            ],
            'provider_availability_status' => $data->parseMeta['provider_availability_status'] ?? null,
        ]);

        $transactionResult = $this->databaseTransaction->run(function () use ($page, $data, $prepared, $progress, $preferredCatalogTitle): array {
            $catalogTitle = $this->titleWriter->upsertTitle(
                $page,
                $data,
                $prepared->contentHash,
                $progress,
                $preferredCatalogTitle,
            );
            $this->relationSyncer->sync(
                $catalogTitle,
                $data->taxonomies,
                $progress,
                sectionPresence: $data->sectionPresence(),
            );
            $this->metadataProvenance->record(
                $catalogTitle,
                $page,
                $data,
            );
            $this->titleWriter->syncAliases($catalogTitle, $data->aliases, $progress);
            $this->titleWriter->syncRatings($catalogTitle, $data->ratings, $progress);

            if ($data->hasCompleteMetadataSnapshot()) {
                $this->syncCatalogRecommendationSignals($catalogTitle, $data->recommendationSignals, $progress);
            }

            $this->titleWriter->syncReviews($catalogTitle, $page, $data->reviews, $progress);
            $seasons = $this->titleWriter->syncSeasons(
                $catalogTitle,
                $page,
                $data->seasons,
                $progress,
            );
            $this->titleWriter->syncEpisodes($seasons, $page, $data->episodes, $progress);
            $currentSeason = $seasons[$data->currentSeasonNumber] ?? null;

            if ($currentSeason instanceof Season) {
                $this->releaseObservations->synchronize($catalogTitle, $currentSeason, $page);
            }

            $page->update([
                'content_hash' => $prepared->contentHash,
                'parse_status' => 'parsed',
                'error_message' => null,
                'metadata_parser_version' => $prepared->parserVersion,
                'metadata_attempted_version' => $prepared->parserVersion,
                'metadata_parsed_at' => now(),
                'metadata_presence' => [
                    ...$this->parser->metadataPresence($data->taxonomies, $data->parseMeta),
                    '_semantic_fingerprint' => $prepared->semanticFingerprint,
                ],
                'provider_availability_status' => $data->parseMeta['provider_availability_status'] ?? null,
                'provider_availability_checked_at' => now(),
            ]);

            return [
                'catalog_title' => $catalogTitle,
                'seasons' => $seasons,
            ];
        },
            attempts: $this->importTransactionAttempts(),
            baseDelayMilliseconds: $this->transactionRetryDelayMilliseconds(),
            progress: $progress,
        );
        $catalogTitle = $transactionResult['catalog_title'];

        if ($afterCatalogCommit !== null) {
            $afterCatalogCommit($catalogTitle);
        }

        $mediaResult = $this->mediaSynchronizer->synchronize(
            $catalogTitle,
            $transactionResult['seasons'],
            $data->media,
            $progress,
        )->toArray();

        if ($syncMediaTranslations) {
            $this->mediaSynchronizer->syncTranslationsForTitle($catalogTitle, $progress);
        }
        $catalogTitle->update([
            'relation_metadata_version' => $prepared->parserVersion,
        ]);
        $missingDataFlags = $this->titlePageStateSynchronizer
            ->synchronize($catalogTitle, $page, $importRunId);
        $this->searchIndexer->synchronizeTitleIds([$catalogTitle->id]);

        if ($publishSyncChange) {
            $this->syncChanges->publishUpsert($catalogTitle);
        }

        $this->recommendationDirtyTitles->mark($catalogTitle->id, 'seasonvar-import');
        $this->quality->mark([(int) $catalogTitle->id]);

        $this->report($progress, 'page-parse-complete', [
            'source_page_id' => $page->id,
            'catalog_title_id' => $catalogTitle->id,
            'title' => $catalogTitle->title,
            'slug' => $catalogTitle->slug,
            'media_attached' => $mediaResult['attached'],
            'media_updated' => $mediaResult['updated'],
            'media_skipped' => $mediaResult['skipped'],
            'media_failed' => $mediaResult['failed'],
            'missing_data_flags' => $missingDataFlags,
            'url' => $page->url,
        ]);

        return [
            'catalog_title' => $catalogTitle,
            'media_attached' => $mediaResult['attached'],
            'media_updated' => $mediaResult['updated'],
            'media_skipped' => $mediaResult['skipped'],
            'media_failed' => $mediaResult['failed'],
        ];
    }

    private function findCatalogTitleBySourceUrlHash(SourcePage $page, string $sourceUrlHash): ?CatalogTitle
    {
        return CatalogTitle::withTrashed()
            ->where('source_id', $page->source_id)
            ->where('source_url_hash', $sourceUrlHash)
            ->first();
    }

    /**
     * @param  list<array{source: string, signal_type: string, signal_key: string, signal_value: string|null, weight: int}>  $signals
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    private function syncCatalogRecommendationSignals(CatalogTitle $catalogTitle, array $signals, ?callable $progress = null): void
    {
        $now = now();
        $managedSources = ['seasonvar_info'];
        $managedTypes = ['provider_recommendation', 'related_title'];
        $rows = collect($signals)
            ->filter(fn (array $signal): bool => in_array($signal['source'], $managedSources, true))
            ->filter(fn (array $signal): bool => in_array($signal['signal_type'], $managedTypes, true))
            ->filter(fn (array $signal): bool => trim($signal['signal_type']) !== '' && trim($signal['signal_key']) !== '' && (int) $signal['weight'] > 0)
            ->mapWithKeys(function (array $signal) use ($catalogTitle, $now): array {
                $source = Str::substr($signal['source'], 0, 64);
                $signalType = Str::substr(Str::slug($signal['signal_type'], '_') ?: 'source', 0, 64);
                $signalKey = Str::substr(Str::slug($signal['signal_key']) ?: Str::substr(hash('sha256', $signalType.'|'.$signal['signal_key']), 0, 24), 0, 128);

                return [$source.'|'.$signalType.'|'.$signalKey => [
                    'catalog_title_id' => $catalogTitle->id,
                    'source' => $source,
                    'signal_type' => $signalType,
                    'signal_key' => $signalKey,
                    'signal_value' => $signal['signal_value'] !== null ? Str::substr(Str::squish($signal['signal_value']), 0, 255) : null,
                    'weight' => min(1000, max(0, (int) $signal['weight'])),
                    'observed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]];
            });

        if ($rows->isEmpty()) {
            $this->report($progress, 'catalog-title-recommendation-signals-synced', [
                'catalog_title_id' => $catalogTitle->id,
                'signals' => 0,
            ]);

            return;
        }

        CatalogTitleRecommendationSignal::query()
            ->where('catalog_title_id', $catalogTitle->id)
            ->whereIn('source', $managedSources)
            ->whereIn('signal_type', $managedTypes)
            ->whereNot(function (Builder $query) use ($rows): void {
                foreach ($rows as $row) {
                    $query->orWhere(function (Builder $query) use ($row): void {
                        $query
                            ->where('source', $row['source'])
                            ->where('signal_type', $row['signal_type'])
                            ->where('signal_key', $row['signal_key']);
                    });
                }
            })
            ->delete();

        CatalogTitleRecommendationSignal::query()->upsert(
            $rows->values()->all(),
            ['catalog_title_id', 'source', 'signal_type', 'signal_key'],
            ['signal_value', 'weight', 'observed_at', 'updated_at'],
        );

        $this->report($progress, 'catalog-title-recommendation-signals-synced', [
            'catalog_title_id' => $catalogTitle->id,
            'signals' => $rows->count(),
        ]);
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function syncMediaTranslationsForTitle(CatalogTitle $catalogTitle, ?callable $progress = null): void
    {
        $this->mediaSynchronizer->syncTranslationsForTitle($catalogTitle, $progress);
    }

    private function importTransactionAttempts(): int
    {
        return min(10, max(1, (int) config('seasonvar.import.transaction_attempts', 5)));
    }

    private function transactionRetryDelayMilliseconds(): int
    {
        return min(5000, max(0, (int) config('seasonvar.import.transaction_retry_delay_ms', 250)));
    }

    private function catalogTitleNeedsMediaRefresh(CatalogTitle $catalogTitle): bool
    {
        return ! $catalogTitle->licensedMedia()->published()->exists()
            || $this->seasonsWithoutEpisodes($catalogTitle)->exists()
            || $this->seasonsWithoutPublishedMedia($catalogTitle)->exists()
            || $this->episodesWithoutPublishedMedia($catalogTitle)->exists()
            || $this->unavailableMedia($catalogTitle)->exists();
    }

    /**
     * @return Builder<Season>
     */
    private function seasonsWithoutEpisodes(CatalogTitle $catalogTitle): Builder
    {
        return Season::query()
            ->where('catalog_title_id', $catalogTitle->id)
            ->whereDoesntHave('episodes');
    }

    /**
     * @return Builder<Season>
     */
    private function seasonsWithoutPublishedMedia(CatalogTitle $catalogTitle): Builder
    {
        return Season::query()
            ->where('catalog_title_id', $catalogTitle->id)
            ->whereNotIn('id', LicensedMedia::query()
                ->published()
                ->whereNotNull('season_id')
                ->select('season_id'));
    }

    /**
     * @return Builder<Episode>
     */
    private function episodesWithoutPublishedMedia(CatalogTitle $catalogTitle): Builder
    {
        return Episode::query()
            ->whereHas('season', function (Builder $query) use ($catalogTitle): void {
                $query->where('catalog_title_id', $catalogTitle->id);
            })
            ->whereNotIn('id', LicensedMedia::query()
                ->published()
                ->whereNotNull('episode_id')
                ->select('episode_id'));
    }

    /**
     * @return Builder<LicensedMedia>
     */
    private function unavailableMedia(CatalogTitle $catalogTitle): Builder
    {
        return LicensedMedia::query()
            ->where('catalog_title_id', $catalogTitle->id)
            ->where(function (Builder $query): void {
                $query->where('status', 'unavailable')
                    ->orWhereIn('health_status', ['unavailable', 'disabled']);
            });
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{catalog_title: CatalogTitle|null, media_attached: int, media_updated: int, media_skipped: int, media_failed: int}
     */
    private function parseMetadataPage(
        SourcePage $page,
        SeasonvarPageHandler $handler,
        ?callable $progress,
        ?int $importRunId,
    ): array {
        $source = $page->source;
        $response = $this->httpClient->get(
            $page->url,
            (int) $source->crawl_delay_seconds,
            $progress,
            $this->conditionalRequestHeaders($page),
        );

        if ($response->status() === 304) {
            $page->update([
                'http_status' => 304,
                'last_crawled_at' => now(),
                'last_imported_at' => now(),
                'last_import_run_id' => $importRunId,
                'retry_after_at' => now()->addHours($this->pageHandlers->refreshHours(
                    SeasonvarPageType::tryFrom((string) $page->page_type) ?? SeasonvarPageType::Unknown,
                )),
                'failure_count' => 0,
                'error_message' => null,
            ]);
            $this->recordPageEvent($page, $importRunId, 'seasonvar-page-not-modified', [
                'page_type' => $page->page_type,
            ]);

            return (new SeasonvarPageHandlerResult)->toLegacyResult();
        }

        $body = $response->body();
        $contentHash = hash('sha256', $body);
        $contentChanged = $page->content_hash !== $contentHash;
        $page->update([
            'http_status' => $response->status(),
            'content_hash' => $contentHash,
            'etag' => $response->header('ETag') ?: $page->etag,
            'last_modified_header' => $response->header('Last-Modified') ?: $page->last_modified_header,
            'last_crawled_at' => now(),
            'last_changed_at' => $contentChanged ? now() : $page->last_changed_at,
            'last_import_run_id' => $importRunId,
        ]);

        if (! $response->successful()) {
            throw SeasonvarSourceRequestException::forStatus($response->status());
        }

        $result = $handler->handle($page, $body, $importRunId, $progress);
        $pageType = SeasonvarPageType::tryFrom((string) $page->page_type) ?? SeasonvarPageType::Unknown;
        $page->update([
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'missing_data_flags' => $result->missingDataFlags,
            'retry_after_at' => now()->addHours($this->pageHandlers->refreshHours($pageType)),
            'failure_count' => 0,
            'error_message' => null,
            'last_imported_at' => now(),
            'metadata_parser_version' => 1,
            'metadata_attempted_version' => 1,
            'metadata_parsed_at' => now(),
            'metadata_presence' => collect($result->structuredFields)->mapWithKeys(fn (string $field): array => [$field => 'present'])->all(),
        ]);
        $this->storeMetadataSnapshot($page, $result, $contentHash, $response->status(), $importRunId);
        $this->recordPageEvent($page, $importRunId, 'seasonvar-metadata-page-parsed', [
            'page_type' => $pageType->value,
            'parser' => $handler->definition()->parserClass,
            'structured_fields' => $result->structuredFields,
            'linked_serial_urls_found' => $result->linkedSerialUrls,
            'taxonomy_created' => $result->taxonomiesCreated,
            'taxonomy_updated' => $result->taxonomiesUpdated,
            'duplicate_prevented' => $result->duplicatesPrevented,
        ]);

        if ($handler->definition()->canGenerateLocalPublicPage
            && ! $this->pageHandlers->definition($pageType)->publicationAuthorized) {
            $this->recordPageEvent($page, $importRunId, 'seasonvar-unauthorized-content-skipped', [
                'page_type' => $pageType->value,
                'scope' => 'local_publication',
                'reason' => 'publication_authorization_required',
            ]);
        }

        return $result->toLegacyResult();
    }

    /** @return array<string, string> */
    private function conditionalRequestHeaders(SourcePage $page): array
    {
        if ($page->parse_status !== 'parsed' || $page->content_hash === null) {
            return [];
        }

        return collect([
            'If-None-Match' => $page->etag,
            'If-Modified-Since' => $page->last_modified_header,
        ])->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')->all();
    }

    private function storeMetadataSnapshot(
        SourcePage $page,
        SeasonvarPageHandlerResult $result,
        string $contentHash,
        int $httpStatus,
        ?int $importRunId,
    ): void {
        $summary = json_encode([
            'page_type' => $page->page_type,
            'structured_fields' => $result->structuredFields,
            'linked_serial_urls_found' => $result->linkedSerialUrls,
            'missing_data_flags' => $result->missingDataFlags,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        SourcePageSnapshot::query()->updateOrCreate(
            ['source_page_id' => $page->id, 'content_hash' => $contentHash],
            [
                'seasonvar_import_run_id' => $importRunId,
                'url' => $page->url,
                'http_status' => $httpStatus,
                'body_bytes' => mb_strlen($summary, '8bit'),
                'html' => $summary,
                'captured_at' => now(),
            ],
        );
    }

    /** @param array<string, mixed> $context */
    private function recordPageEvent(SourcePage $page, ?int $importRunId, string $event, array $context): void
    {
        $this->eventRecorder->record(
            event: $event,
            context: $context,
            importRunId: $importRunId,
            sourcePageId: (int) $page->id,
        );
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function report(?callable $progress, string $event, array $context = []): void
    {
        if ($progress === null) {
            return;
        }

        $progress($event, $context);
    }
}
