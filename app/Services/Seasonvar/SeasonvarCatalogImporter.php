<?php

namespace App\Services\Seasonvar;

use App\Actions\Seasonvar\RecordSeasonvarPageFailure;
use App\DTOs\MediaHealthCheckResultData;
use App\DTOs\Seasonvar\SeasonvarCatalogData;
use App\DTOs\Seasonvar\SeasonvarPageHandlerResult;
use App\DTOs\Seasonvar\SeasonvarPreparedCatalogPage;
use App\Enums\ContentAudience;
use App\Enums\MediaHealthErrorCategory;
use App\Enums\MediaHealthStatus;
use App\Enums\PublicationStatus;
use App\Enums\ReleaseKind;
use App\Enums\SeasonvarImportFailureType;
use App\Enums\SeasonvarPageType;
use App\Exceptions\Seasonvar\SeasonvarSourceRequestException;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleAlias;
use App\Models\CatalogTitleRating;
use App\Models\CatalogTitleRecommendationSignal;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleSlug;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\SeasonvarImportEvent;
use App\Models\SourcePage;
use App\Models\SourcePageSnapshot;
use App\Services\Api\V1\Sync\CatalogSyncChangePublisher;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use App\Services\Crawler\PoliteHttpClient;
use App\Services\Media\ExternalMediaMetadata;
use App\Services\Media\ExternalPlaylistImporter;
use App\Services\Media\MediaSourceHealthManager;
use App\Services\Seasonvar\PageHandlers\SeasonvarPageHandler;
use App\Services\Seasonvar\PageHandlers\SeasonvarPassivePageHandler;
use App\Services\Seasonvar\PageHandlers\SeasonvarSerialPageHandler;
use App\Support\CatalogTitleDisplayName;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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
        private readonly SeasonvarCatalogIdentityResolver $identityResolver,
        private readonly SeasonvarEditorialFieldResolver $editorialFields,
        private readonly ExternalPlaylistImporter $playlistImporter,
        private readonly SeasonvarMediaAvailabilityChecker $mediaAvailabilityChecker,
        private readonly MediaSourceHealthManager $mediaHealth,
        private readonly ExternalMediaMetadata $mediaMetadata,
        private readonly SeasonvarCatalogRelationSyncer $relationSyncer,
        private readonly SeasonvarRelationMetadataNormalizer $relationMetadata,
        private readonly SeasonvarDatabaseTransaction $databaseTransaction,
        private readonly RecordSeasonvarPageFailure $recordPageFailure,
        private readonly SeasonvarTitlePageStateSynchronizer $titlePageStateSynchronizer,
        private readonly SeasonvarImportErrorSanitizer $errors,
        private readonly CatalogSearchIndexer $searchIndexer,
        private readonly CatalogSyncChangePublisher $syncChanges,
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
                ->with('source')
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
        $page->load('source');

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
            ->with('source')
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
     * @return array{parsed: int, failed: int, media_attached: int, media_updated: int, media_skipped: int, media_failed: int, failures: list<string>}
     */
    public function parsePages(
        Collection $pages,
        ?callable $progress = null,
        bool $force = false,
        ?int $importRunId = null,
        bool $retryTransient = false,
    ): array {
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
            $position++;

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
            'parsed' => $parsed,
            'failed' => $failed,
            'media_attached' => $mediaAttached,
            'media_updated' => $mediaUpdated,
            'media_skipped' => $mediaSkipped,
            'media_failed' => $mediaFailed,
        ]);

        return [
            'parsed' => $parsed,
            'failed' => $failed,
            'media_attached' => $mediaAttached,
            'media_updated' => $mediaUpdated,
            'media_skipped' => $mediaSkipped,
            'media_failed' => $mediaFailed,
            'failures' => $failures,
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
     * Apply a network-free prepared payload to one canonical catalog title.
     *
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{catalog_title: CatalogTitle, media_attached: int, media_updated: int, media_skipped: int, media_failed: int}
     */
    public function applyPreparedPage(
        SourcePage $page,
        SeasonvarPreparedCatalogPage $prepared,
        ?CatalogTitle $preferredCatalogTitle = null,
        ?int $importRunId = null,
        ?callable $progress = null,
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
            $catalogTitle = $this->upsertCatalogTitle($page, $data, $prepared->contentHash, $progress, $preferredCatalogTitle);
            $this->relationSyncer->sync($catalogTitle, $data->taxonomies, $progress);
            $this->syncCatalogAliases($catalogTitle, $data->aliases, $progress);
            $this->syncCatalogRatings($catalogTitle, $data->ratings, $progress);

            if ($data->hasCompleteMetadataSnapshot()) {
                $this->syncCatalogRecommendationSignals($catalogTitle, $data->recommendationSignals, $progress);
            }

            $this->syncCatalogReviews($catalogTitle, $page, $data->reviews, $progress);
            $seasons = $this->syncSeasons($catalogTitle, $page, $data->seasons, $progress);
            $this->syncEpisodes($seasons, $page, $data->episodes, $progress);

            $page->update([
                'content_hash' => $prepared->contentHash,
                'parse_status' => 'parsed',
                'error_message' => null,
                'metadata_parser_version' => $prepared->parserVersion,
                'metadata_attempted_version' => $prepared->parserVersion,
                'metadata_parsed_at' => now(),
                'metadata_presence' => $this->parser->metadataPresence($data->taxonomies, $data->parseMeta),
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
        $mediaResult = $this->syncParsedMedia(
            $catalogTitle,
            $transactionResult['seasons'],
            $data->media,
            $progress,
            allowNetwork: false,
        );
        $this->syncMediaTranslations($catalogTitle, $progress);
        $catalogTitle->update([
            'relation_metadata_version' => $prepared->parserVersion,
        ]);
        $missingDataFlags = $this->titlePageStateSynchronizer
            ->synchronize($catalogTitle, $page, $importRunId);
        $this->searchIndexer->synchronizeTitleIds([$catalogTitle->id]);
        $this->syncChanges->publishUpsert($catalogTitle);

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

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    private function upsertCatalogTitle(
        SourcePage $page,
        SeasonvarCatalogData $data,
        string $contentHash,
        ?callable $progress = null,
        ?CatalogTitle $preferredCatalogTitle = null,
    ): CatalogTitle {
        $sourceUrlHash = $this->seasonvarUrl->hash($page->url);
        $catalogTitle = $this->identityResolver->resolve($page, $data, $sourceUrlHash, $preferredCatalogTitle)
            ?? new CatalogTitle([
                'source_id' => $page->source_id,
                'source_url_hash' => $sourceUrlHash,
            ]);
        $wasExisting = $catalogTitle->exists;

        $this->report($progress, 'catalog-title-upsert-started', [
            'source_page_id' => $page->id,
            'source_id' => $page->source_id,
            'existing' => $wasExisting,
            'source_url_hash' => $sourceUrlHash,
            'title' => $data->title,
        ]);

        if (! $catalogTitle->exists) {
            $catalogTitle->slug = $this->uniqueSlug($data->title, $data->externalId, $sourceUrlHash, $catalogTitle->id);

            $this->report($progress, 'catalog-title-slug-prepared', [
                'source_page_id' => $page->id,
                'catalog_title_id' => $catalogTitle->id,
                'slug' => $catalogTitle->slug,
            ]);
        }

        $isCanonicalSourcePage = ! $catalogTitle->exists
            || $catalogTitle->source_page_id === null
            || (int) $catalogTitle->source_page_id === (int) $page->id;

        $editorial = $this->editorialFields->resolve($catalogTitle, $data);

        $catalogTitle->fill([
            'source_page_id' => $catalogTitle->source_page_id ?? $page->id,
            'external_id' => $catalogTitle->external_id ?? $data->externalId,
            ...$editorial['values'],
            'original_title' => $editorial['values']['original_title'],
            'year' => $this->earliestYear($catalogTitle->year, $data->year),
            'source_url' => $catalogTitle->source_url ?: $page->url,
            'source_url_hash' => $catalogTitle->source_url_hash ?: $sourceUrlHash,
            'content_hash' => $isCanonicalSourcePage ? $contentHash : $catalogTitle->content_hash,
            'provider_field_values' => $editorial['provider_field_values'],
            'indexed_at' => now(),
        ]);

        if (! $catalogTitle->exists) {
            $catalogTitle->fill([
                'is_published' => true,
                'publication_status' => PublicationStatus::Published,
                'audience' => ContentAudience::Public,
            ]);
        }

        $catalogTitle->save();

        $this->report($progress, $wasExisting ? 'catalog-title-updated' : 'catalog-title-created', [
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $page->id,
            'slug' => $catalogTitle->slug,
            'title' => $catalogTitle->title,
            'year' => $catalogTitle->year,
            'external_id' => $catalogTitle->external_id,
            'content_hash' => $catalogTitle->content_hash,
        ]);

        return $catalogTitle;
    }

    private function findCatalogTitleBySourceUrlHash(SourcePage $page, string $sourceUrlHash): ?CatalogTitle
    {
        return CatalogTitle::withTrashed()
            ->where('source_id', $page->source_id)
            ->where('source_url_hash', $sourceUrlHash)
            ->first();
    }

    private function earliestYear(?int $currentYear, ?int $incomingYear): ?int
    {
        return collect([$currentYear, $incomingYear])
            ->filter()
            ->min();
    }

    private function uniqueSlug(string $title, ?string $externalId, string $sourceUrlHash, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($title);

        if ($baseSlug === '') {
            $baseSlug = 'title-'.($externalId ?: Str::substr($sourceUrlHash, 0, 12));
        }

        $slug = $baseSlug;
        $counter = 2;

        while (
            CatalogTitle::query()
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists()
            || CatalogTitleSlug::query()->where('slug', $slug)->exists()
        ) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * @param  list<array{name: string, type: string, source: string}>  $aliases
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    private function syncCatalogAliases(CatalogTitle $catalogTitle, array $aliases, ?callable $progress = null): void
    {
        $now = now();
        $displayName = CatalogTitleDisplayName::from($catalogTitle->title, $catalogTitle->original_title);
        $rows = collect($aliases)
            ->filter(fn (array $alias): bool => $this->isValidAliasName($alias['name']))
            ->map(function (array $alias) use ($catalogTitle, $displayName, $now): ?array {
                $name = Str::squish($alias['name']);

                if ($displayName->contains($name)) {
                    return null;
                }

                $type = Str::substr(Str::slug($alias['type']) ?: 'alternative', 0, 32);
                $nameHash = CatalogTitleDisplayName::nameHash($name);

                return [
                    'catalog_title_id' => $catalogTitle->id,
                    'name' => $name,
                    'name_hash' => $nameHash,
                    'type' => $type,
                    'source' => Str::substr($alias['source'], 0, 64),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->filter()
            ->reduce(function (Collection $rows, array $row): Collection {
                $existing = $rows->get($row['name_hash']);
                $priority = ['original' => 3, 'alternative' => 2, 'source-title' => 1];

                if (! is_array($existing)
                    || ($priority[$row['type']] ?? 0) > ($priority[$existing['type']] ?? 0)) {
                    $rows->put($row['name_hash'], $row);
                }

                return $rows;
            }, collect());

        if ($rows->isNotEmpty()) {
            CatalogTitleAlias::query()->upsert(
                $rows->values()->all(),
                ['catalog_title_id', 'name_hash'],
                ['name', 'type', 'source', 'updated_at'],
            );
        }

        $this->report($progress, 'catalog-title-aliases-synced', [
            'catalog_title_id' => $catalogTitle->id,
            'aliases' => $rows->count(),
        ]);
    }

    private function isValidAliasName(string $name): bool
    {
        $name = Str::squish($name);

        return $name !== ''
            && Str::length($name) <= 160
            && preg_match('/(?:главн|добро пожаловать|смотреть онлайн|seasonvar)/iu', $name) !== 1;
    }

    /**
     * @param  list<array{provider: string, rating: float|null, votes: int|null, raw_value: string}>  $ratings
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    private function syncCatalogRatings(CatalogTitle $catalogTitle, array $ratings, ?callable $progress = null): void
    {
        $now = now();
        $rows = collect($ratings)
            ->filter(fn (array $rating): bool => in_array($rating['provider'], ['imdb', 'kinopoisk'], true))
            ->mapWithKeys(function (array $rating) use ($catalogTitle, $now): array {
                return [$rating['provider'] => [
                    'catalog_title_id' => $catalogTitle->id,
                    'provider' => $rating['provider'],
                    'rating' => $rating['rating'],
                    'votes' => $rating['votes'],
                    'raw_value' => Str::substr($rating['raw_value'], 0, 255),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]];
            });

        if ($rows->isNotEmpty()) {
            CatalogTitleRating::query()->upsert(
                $rows->values()->all(),
                ['catalog_title_id', 'provider'],
                ['rating', 'votes', 'raw_value', 'updated_at'],
            );
        }

        $this->report($progress, 'catalog-title-ratings-synced', [
            'catalog_title_id' => $catalogTitle->id,
            'ratings' => $rows->count(),
        ]);
    }

    /**
     * @param  list<array{source: string, signal_type: string, signal_key: string, signal_value: string|null, weight: int}>  $signals
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    private function syncCatalogRecommendationSignals(CatalogTitle $catalogTitle, array $signals, ?callable $progress = null): void
    {
        $now = now();
        $managedSources = ['seasonvar_info'];
        $rows = collect($signals)
            ->filter(fn (array $signal): bool => in_array($signal['source'], $managedSources, true))
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

        CatalogTitleRecommendationSignal::query()
            ->where('catalog_title_id', $catalogTitle->id)
            ->whereIn('source', $managedSources)
            ->when($rows->isNotEmpty(), function (Builder $query) use ($rows): void {
                $query->whereNot(function (Builder $query) use ($rows): void {
                    foreach ($rows as $row) {
                        $query->orWhere(function (Builder $query) use ($row): void {
                            $query
                                ->where('source', $row['source'])
                                ->where('signal_type', $row['signal_type'])
                                ->where('signal_key', $row['signal_key']);
                        });
                    }
                });
            })
            ->delete();

        if ($rows->isNotEmpty()) {
            CatalogTitleRecommendationSignal::query()->upsert(
                $rows->values()->all(),
                ['catalog_title_id', 'source', 'signal_type', 'signal_key'],
                ['signal_value', 'weight', 'observed_at', 'updated_at'],
            );
        }

        $this->report($progress, 'catalog-title-recommendation-signals-synced', [
            'catalog_title_id' => $catalogTitle->id,
            'signals' => $rows->count(),
        ]);
    }

    /**
     * @param  list<array{author: string|null, body: string, published_at: string|null}>  $reviews
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    private function syncCatalogReviews(CatalogTitle $catalogTitle, SourcePage $page, array $reviews, ?callable $progress = null): void
    {
        $now = now();
        $rows = collect($reviews)
            ->filter(fn (array $review): bool => trim($review['body']) !== '')
            ->mapWithKeys(function (array $review) use ($catalogTitle, $page, $now): array {
                $body = Str::squish($review['body']);
                $bodyHash = hash('sha256', Str::lower($body));

                return [$bodyHash => [
                    'catalog_title_id' => $catalogTitle->id,
                    'source_page_id' => $page->id,
                    'author' => $review['author'] ? Str::substr(Str::squish($review['author']), 0, 120) : null,
                    'body' => $body,
                    'body_hash' => $bodyHash,
                    'published_at' => $review['published_at'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]];
            });

        if ($rows->isNotEmpty()) {
            CatalogTitleReview::query()->upsert(
                $rows->values()->all(),
                ['catalog_title_id', 'body_hash'],
                ['source_page_id', 'author', 'body', 'published_at', 'updated_at'],
            );
        }

        $this->report($progress, 'catalog-title-reviews-synced', [
            'catalog_title_id' => $catalogTitle->id,
            'reviews' => $rows->count(),
        ]);
    }

    /**
     * @param  list<array{number: int, title: string|null, source_url: string|null, latest_episode_released_at: string|null, episodes_released: int|null, episodes_total: int|null, translation_name: string|null, release_status_text: string|null}>  $seasons
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array<int, Season>
     */
    private function syncSeasons(CatalogTitle $catalogTitle, SourcePage $page, array $seasons, ?callable $progress = null): array
    {
        $this->report($progress, 'season-sync-started', [
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $page->id,
            'total' => count($seasons),
        ]);

        $now = now();
        $rowsByNumber = collect($seasons)
            ->filter(fn (array $season): bool => (int) $season['number'] > 0)
            ->mapWithKeys(function (array $season) use ($catalogTitle, $page, $now): array {
                $number = (int) $season['number'];

                return [$number => [
                    'catalog_title_id' => $catalogTitle->id,
                    'number' => $number,
                    'kind' => ReleaseKind::Regular->value,
                    'sort_order' => $number,
                    'source_page_id' => $page->id,
                    'title' => $season['title'],
                    'source_url' => $season['source_url'],
                    'source_url_hash' => $season['source_url'] ? $this->seasonvarUrl->hash($season['source_url']) : null,
                    'latest_episode_released_at' => $season['latest_episode_released_at'] ?? null,
                    'episodes_released' => $season['episodes_released'] ?? null,
                    'episodes_total' => $season['episodes_total'] ?? null,
                    'translation_name' => $season['translation_name'] ?? null,
                    'release_status_text' => $season['release_status_text'] ?? null,
                    'publication_status' => PublicationStatus::Published->value,
                    'audience' => ContentAudience::Public->value,
                    'deleted_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]];
            });

        $existingSeasons = $rowsByNumber->isNotEmpty()
            ? Season::withTrashed()
                ->where('catalog_title_id', $catalogTitle->id)
                ->where('kind', ReleaseKind::Regular->value)
                ->whereIn('number', $rowsByNumber->keys())
                ->get()
                ->keyBy(fn (Season $season): int => (int) $season->number)
            : collect();
        $rowsByNumber = $rowsByNumber->map(function (array $row, int $number) use ($existingSeasons): array {
            $existing = $existingSeasons->get($number);

            if ($existing === null) {
                return $row;
            }

            foreach (['title', 'source_url', 'source_url_hash', 'translation_name', 'release_status_text'] as $field) {
                if ($row[$field] === null) {
                    $row[$field] = $existing->{$field};
                }
            }

            return $row;
        });
        $rowsForUpsert = $rowsByNumber
            ->filter(fn (array $row, int $number): bool => $this->seasonRowChanged($existingSeasons->get($number), $row));

        if ($rowsForUpsert->isNotEmpty()) {
            Season::query()->upsert(
                $rowsForUpsert->values()->all(),
                ['catalog_title_id', 'kind', 'number'],
                [
                    'source_page_id',
                    'title',
                    'source_url',
                    'source_url_hash',
                    'latest_episode_released_at',
                    'episodes_released',
                    'episodes_total',
                    'translation_name',
                    'release_status_text',
                    'sort_order',
                    'updated_at',
                ],
            );
        }

        $syncedSeasons = Season::query()
            ->where('catalog_title_id', $catalogTitle->id)
            ->where('kind', ReleaseKind::Regular->value)
            ->whereIn('number', $rowsByNumber->keys())
            ->get()
            ->keyBy(fn (Season $season): int => (int) $season->number)
            ->all();

        $this->report($progress, 'season-sync-complete', [
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $page->id,
            'synced' => count($syncedSeasons),
            'changed' => $rowsForUpsert->count(),
        ]);

        return $syncedSeasons;
    }

    /**
     * @param  array<int, Season>  $seasons
     * @param  list<array{season_number: int, number: int, title: string|null, source_url: string|null}>  $episodes
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    private function syncEpisodes(array $seasons, SourcePage $page, array $episodes, ?callable $progress = null): void
    {
        $this->report($progress, 'episode-sync-started', [
            'source_page_id' => $page->id,
            'total' => count($episodes),
        ]);

        $now = now();
        $skipped = 0;
        $rowsByKey = collect($episodes)->mapWithKeys(function (array $episode) use ($seasons, $page, $now, &$skipped): array {
            $season = $seasons[$episode['season_number']] ?? null;

            if ($season === null) {
                $skipped++;

                return [];
            }

            $number = (int) $episode['number'];

            if ($number <= 0) {
                $skipped++;

                return [];
            }

            return [$season->id.'|'.$number => [
                'season_id' => $season->id,
                'number' => $number,
                'kind' => ReleaseKind::Regular->value,
                'sort_order' => $number,
                'source_page_id' => $page->id,
                'title' => $episode['title'],
                'source_url' => $episode['source_url'],
                'source_url_hash' => $episode['source_url'] ? $this->seasonvarUrl->hash($episode['source_url']) : null,
                'publication_status' => PublicationStatus::Published->value,
                'audience' => ContentAudience::Public->value,
                'deleted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]];
        });

        $seasonIds = $rowsByKey->pluck('season_id')->unique()->values();
        $existingEpisodes = $rowsByKey->isNotEmpty()
            ? Episode::withTrashed()
                ->whereIn('season_id', $seasonIds)
                ->where('kind', ReleaseKind::Regular->value)
                ->get()
                ->keyBy(fn (Episode $episode): string => $episode->season_id.'|'.$episode->number)
            : collect();
        $rowsByKey = $rowsByKey->map(function (array $row, string $key) use ($existingEpisodes): array {
            $existing = $existingEpisodes->get($key);

            if ($existing === null) {
                return $row;
            }

            foreach (['title', 'source_url', 'source_url_hash'] as $field) {
                if ($row[$field] === null) {
                    $row[$field] = $existing->{$field};
                }
            }

            return $row;
        });
        $rowsForUpsert = $rowsByKey
            ->filter(fn (array $row, string $key): bool => $this->episodeRowChanged($existingEpisodes->get($key), $row));

        foreach ($rowsForUpsert->values()->chunk(50) as $rows) {
            Episode::query()->upsert(
                $rows->all(),
                ['season_id', 'kind', 'number'],
                [
                    'source_page_id',
                    'title',
                    'source_url',
                    'source_url_hash',
                    'sort_order',
                    'updated_at',
                ],
            );
        }

        $this->report($progress, 'episode-sync-complete', [
            'source_page_id' => $page->id,
            'synced' => $rowsByKey->count(),
            'changed' => $rowsForUpsert->count(),
            'skipped' => $skipped,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function seasonRowChanged(?Season $season, array $row): bool
    {
        if ($season === null) {
            return true;
        }

        foreach ([
            'source_page_id',
            'title',
            'source_url',
            'source_url_hash',
            'latest_episode_released_at',
            'episodes_released',
            'episodes_total',
            'translation_name',
            'release_status_text',
            'sort_order',
        ] as $field) {
            if ($this->comparableImportValue($field, $season->{$field}) !== $this->comparableImportValue($field, $row[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function episodeRowChanged(?Episode $episode, array $row): bool
    {
        if ($episode === null) {
            return true;
        }

        foreach (['source_page_id', 'title', 'source_url', 'source_url_hash', 'sort_order'] as $field) {
            if ($this->comparableImportValue($field, $episode->{$field}) !== $this->comparableImportValue($field, $row[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function comparableImportValue(string $field, mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        if (in_array($field, ['source_page_id', 'episodes_released', 'episodes_total'], true)) {
            return $value === null ? null : (int) $value;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function mediaAttributesChanged(LicensedMedia $media, array $updates): bool
    {
        foreach ($updates as $field => $value) {
            if ($this->comparableMediaValue($field, $media->{$field}) !== $this->comparableMediaValue($field, $value)) {
                return true;
            }
        }

        return false;
    }

    private function comparableMediaValue(string $field, mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        if (in_array($field, ['catalog_title_id', 'season_id', 'episode_id', 'duration_seconds'], true)) {
            return $value === null ? null : (int) $value;
        }

        return $value;
    }

    private function mediaAvailabilityCheckDue(LicensedMedia $media): bool
    {
        if (! (bool) config('seasonvar.media_check.enabled', true)) {
            return false;
        }

        if (! $media->exists) {
            return true;
        }

        if ($media->health_status === MediaHealthStatus::Disabled) {
            return false;
        }

        if ($media->checked_at === null || $media->check_status === null || $media->next_check_at === null) {
            return true;
        }

        return $media->next_check_at->isPast();
    }

    /**
     * @param  array<int, Season>  $seasons
     * @param  list<array{url: string, title: string|null, season_number: int|null, episode_number: int|null, source_url: string|null, kind: string}>  $mediaItems
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{attached: int, updated: int, skipped: int, failed: int}
     */
    private function syncParsedMedia(
        CatalogTitle $catalogTitle,
        array $seasons,
        array $mediaItems,
        ?callable $progress = null,
        bool $allowNetwork = true,
    ): array {
        $result = $this->emptyMediaResult();

        $this->report($progress, 'seasonvar-media-sync-started', [
            'catalog_title_id' => $catalogTitle->id,
            'candidates' => count($mediaItems),
        ]);

        if ($mediaItems === [] || $seasons === []) {
            $this->report($progress, 'seasonvar-media-sync-complete', [
                'catalog_title_id' => $catalogTitle->id,
                'attached' => 0,
                'updated' => 0,
                'skipped' => count($mediaItems),
                'failed' => 0,
            ]);

            return [
                'attached' => 0,
                'updated' => 0,
                'skipped' => count($mediaItems),
                'failed' => 0,
            ];
        }

        $seasonIds = collect($seasons)
            ->map(fn (Season $season): int => (int) $season->id)
            ->values();
        $episodesBySeasonAndNumber = Episode::query()
            ->whereIn('season_id', $seasonIds)
            ->where('kind', ReleaseKind::Regular->value)
            ->get()
            ->keyBy(fn (Episode $episode): string => $episode->season_id.'|'.$episode->number);

        foreach ($mediaItems as $item) {
            if (! $this->isDirectPlayerMediaUrl($item['url'])) {
                $result['skipped']++;

                continue;
            }

            try {
                $playbackUrl = $this->playlistImporter->safeExternalUrl($item['url']);
            } catch (Throwable $exception) {
                $result['skipped']++;
                $this->report($progress, 'seasonvar-media-skipped', [
                    'catalog_title_id' => $catalogTitle->id,
                    'url' => '[redacted-url]',
                    'reason' => app(SeasonvarImportErrorSanitizer::class)->fromException($exception),
                ]);

                continue;
            }

            $seasonNumber = $item['season_number'];
            $episodeNumber = $item['episode_number'];
            $season = $seasonNumber !== null ? ($seasons[$seasonNumber] ?? null) : null;
            $episode = $season !== null && $episodeNumber !== null
                ? $episodesBySeasonAndNumber->get($season->id.'|'.$episodeNumber)
                : null;

            $isTrailer = $this->mediaMetadata->isTrailer($item['title'], $playbackUrl);

            if (($season === null || $episode === null) && ! $isTrailer) {
                $result['skipped']++;
                $this->report($progress, 'seasonvar-media-skipped', [
                    'catalog_title_id' => $catalogTitle->id,
                    'url' => '[redacted-url]',
                    'season_number' => $seasonNumber,
                    'episode_number' => $episodeNumber,
                    'reason' => 'серия для медиа не найдена',
                ]);

                continue;
            }

            $quality = $this->mediaQuality($item['title'], $playbackUrl);
            $format = $this->parsedMediaExtension($playbackUrl);
            $variant = $this->mediaMetadata->playbackVariant($item['title'], $item['source_url'], $playbackUrl);
            $sourceMediaKey = $this->sourceMediaKey($catalogTitle, $season, $episode, $item, $playbackUrl, $quality, $format);
            $media = LicensedMedia::withTrashed()
                ->where('catalog_title_id', $catalogTitle->id)
                ->where('source_media_key', $sourceMediaKey)
                ->first()
                ?? LicensedMedia::withTrashed()
                    ->where('catalog_title_id', $catalogTitle->id)
                    ->where('playback_url', $playbackUrl)
                    ->first()
                ?? new LicensedMedia([
                    'catalog_title_id' => $catalogTitle->id,
                    'source_media_key' => $sourceMediaKey,
                ]);
            $wasExisting = $media->exists;

            $mediaUpdates = [
                'catalog_title_id' => $catalogTitle->id,
                'season_id' => $season?->id,
                'episode_id' => $episode?->id,
                'title' => $item['title'] ?: $this->mediaTitle($catalogTitle, $season, $episode, $isTrailer),
                'storage_disk' => ($item['storage_disk'] ?? null) === 'external_playlist'
                    ? 'external_playlist'
                    : 'seasonvar_parsed',
                'path' => $playbackUrl,
                'playback_url' => $playbackUrl,
                'source_media_key' => $sourceMediaKey,
                'source_url' => $item['source_url'],
                'quality' => $quality,
                'translation_name' => $this->mediaTranslationName($item['title'], $item['source_url']),
                'variant_type' => $variant['variant_type'],
                'variant_name' => $variant['variant_name'],
                'variant_key' => $variant['variant_key'],
                'has_subtitles' => $variant['has_subtitles'],
                'format' => $format,
                'published_at' => $media->published_at ?? now(),
            ];

            if ($wasExisting
                && ! $this->mediaAttributesChanged($media, $mediaUpdates)
                && ! $this->mediaAvailabilityCheckDue($media)
            ) {
                $result['skipped']++;
                $this->report($progress, 'seasonvar-media-skipped', [
                    'catalog_title_id' => $catalogTitle->id,
                    'licensed_media_id' => $media->id,
                    'season_number' => $season?->number,
                    'episode_number' => $episode?->number,
                    'playback_url' => '[redacted-url]',
                    'reason' => 'медиа уже актуально',
                ]);

                continue;
            }

            $media->fill([
                ...$mediaUpdates,
                'status' => $media->status ?: 'published',
            ])->save();

            if ($media->health_status !== MediaHealthStatus::Disabled) {
                $availability = $this->preparedMediaAvailability($item);

                if ($availability !== null || $allowNetwork) {
                    $media = $this->mediaHealth->record(
                        $media,
                        $availability ?? $this->checkMediaUrl($playbackUrl, $progress),
                    );
                }
            }

            $result[$wasExisting ? 'updated' : 'attached']++;
            $this->report($progress, $media->wasRecentlyCreated ? 'seasonvar-media-attached' : 'seasonvar-media-updated', [
                'catalog_title_id' => $catalogTitle->id,
                'licensed_media_id' => $media->id,
                'season_number' => $season?->number,
                'episode_number' => $episode?->number,
                'playback_url' => '[redacted-url]',
                'quality' => $media->quality,
                'format' => $media->format,
                'check_status' => $media->check_status,
                'http_status' => $media->last_http_status,
            ]);
        }

        $this->report($progress, 'seasonvar-media-sync-complete', [
            'catalog_title_id' => $catalogTitle->id,
            'attached' => $result['attached'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
        ]);

        return $result;
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    private function syncMediaTranslations(CatalogTitle $catalogTitle, ?callable $progress = null): void
    {
        $taxonomies = $catalogTitle->licensedMedia()
            ->whereIn('variant_type', ['voiceover', 'original'])
            ->get(['variant_name', 'translation_name'])
            ->flatMap(fn (LicensedMedia $media): array => [$media->variant_name, $media->translation_name])
            ->map(fn (mixed $name): ?string => is_string($name) ? $this->relationMetadata->translation($name) : null)
            ->filter()
            ->unique(fn (string $name): string => Str::lower($name))
            ->map(fn (string $name): array => [
                'type' => 'translation',
                'name' => $name,
                'source_url' => null,
            ])
            ->values()
            ->all();

        if ($taxonomies !== []) {
            $this->relationSyncer->sync($catalogTitle, $taxonomies, $progress);
        }
    }

    /**
     * @param  list<array{url: string, title: string|null, season_number: int|null, episode_number: int|null, source_url: string|null, kind: string}>  $mediaItems
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{attached: int, updated: int, skipped: int, failed: int}
     */
    private function importParsedPlaylists(CatalogTitle $catalogTitle, array $mediaItems, ?callable $progress = null): array
    {
        $result = $this->emptyMediaResult();
        $playlistUrls = collect($mediaItems)
            ->filter(fn (array $item): bool => $item['kind'] === 'playlist' && $this->parsedMediaExtension($item['url']) === 'm3u')
            ->pluck('url')
            ->unique(fn (string $url): string => Str::lower($url))
            ->values();

        foreach ($playlistUrls as $playlistUrl) {
            try {
                $playlistResult = $this->playlistImporter->importFromUrl(
                    (string) $playlistUrl,
                    $catalogTitle->loadMissing(['seasons.episodes']),
                );

                $result['attached'] += $playlistResult['imported'];
                $result['updated'] += $playlistResult['updated'];
                $result['skipped'] += $playlistResult['skipped'] + $playlistResult['unmatched'];

                $this->report($progress, 'seasonvar-media-playlist-import-complete', [
                    'catalog_title_id' => $catalogTitle->id,
                    'url' => $playlistUrl,
                    'imported' => $playlistResult['imported'],
                    'updated' => $playlistResult['updated'],
                    'skipped' => $playlistResult['skipped'],
                    'unmatched' => $playlistResult['unmatched'],
                ]);
            } catch (Throwable $exception) {
                $result['failed']++;
                $this->report($progress, 'seasonvar-media-playlist-import-failed', [
                    'catalog_title_id' => $catalogTitle->id,
                    'url' => $playlistUrl,
                    'exception' => $exception::class,
                    'message' => $this->errors->fromException($exception),
                ]);
            }
        }

        $hlsPlaylistItems = collect($mediaItems)
            ->filter(fn (array $item): bool => $item['kind'] === 'playlist' && $this->parsedMediaExtension($item['url']) === 'm3u8')
            ->unique(fn (array $item): string => Str::lower($item['url']))
            ->values();

        foreach ($hlsPlaylistItems as $playlistItem) {
            try {
                $parsedMediaItems = $this->parseExternalPlaylistItem($playlistItem, $progress);
                $seasons = $catalogTitle
                    ->loadMissing(['seasons.episodes'])
                    ->seasons
                    ->keyBy('number')
                    ->all();
                $playlistResult = $this->syncParsedMedia($catalogTitle, $seasons, $parsedMediaItems, $progress);
                $result = $this->mergeMediaResult($result, $playlistResult);

                $this->report($progress, 'seasonvar-media-playlist-import-complete', [
                    'catalog_title_id' => $catalogTitle->id,
                    'url' => $playlistItem['url'],
                    'imported' => $playlistResult['attached'],
                    'updated' => $playlistResult['updated'],
                    'skipped' => $playlistResult['skipped'],
                    'unmatched' => 0,
                ]);
            } catch (Throwable $exception) {
                $result['failed']++;
                $this->report($progress, 'seasonvar-media-playlist-import-failed', [
                    'catalog_title_id' => $catalogTitle->id,
                    'url' => $playlistItem['url'],
                    'exception' => $exception::class,
                    'message' => $this->errors->fromException($exception),
                ]);
            }
        }

        $seasonvarPlaylistItems = collect($mediaItems)
            ->filter(fn (array $item): bool => $item['kind'] === 'seasonvar_playlist')
            ->unique(fn (array $item): string => Str::lower($item['url']))
            ->values();

        foreach ($seasonvarPlaylistItems as $playlistItem) {
            try {
                $parsedMediaItems = $this->parseSeasonvarPlaylistItem($playlistItem, $progress);
                $seasons = $catalogTitle
                    ->loadMissing(['seasons.episodes'])
                    ->seasons
                    ->keyBy('number')
                    ->all();
                $playlistResult = $this->syncParsedMedia($catalogTitle, $seasons, $parsedMediaItems, $progress);
                $result = $this->mergeMediaResult($result, $playlistResult);

                $this->report($progress, 'seasonvar-media-playlist-import-complete', [
                    'catalog_title_id' => $catalogTitle->id,
                    'url' => $playlistItem['url'],
                    'imported' => $playlistResult['attached'],
                    'updated' => $playlistResult['updated'],
                    'skipped' => $playlistResult['skipped'],
                    'unmatched' => 0,
                ]);
            } catch (Throwable $exception) {
                $result['failed']++;
                $this->report($progress, 'seasonvar-media-playlist-import-failed', [
                    'catalog_title_id' => $catalogTitle->id,
                    'url' => $playlistItem['url'],
                    'exception' => $exception::class,
                    'message' => $this->errors->fromException($exception),
                ]);
            }
        }

        return $result;
    }

    /**
     * @param  array{url: string, title: string|null, season_number: int|null, episode_number: int|null, source_url: string|null, kind: string}  $playlistItem
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return list<array{url: string, title: string|null, season_number: int|null, episode_number: int|null, source_url: string|null, kind: string}>
     */
    private function parseExternalPlaylistItem(array $playlistItem, ?callable $progress = null): array
    {
        $playlistUrl = $this->playlistImporter->safeExternalUrl($playlistItem['url']);
        $response = $this->httpClient->get($playlistUrl, 0, $progress);

        if (! $response->successful()) {
            throw new RuntimeException('Плейлист вернул HTTP '.$response->status().'.');
        }

        return collect($this->playlistImporter->parse($response->body(), $playlistUrl))
            ->map(function (array $entry) use ($playlistItem, $playlistUrl): array {
                $title = collect([$playlistItem['title'], $entry['title'] ?? null])
                    ->filter()
                    ->unique()
                    ->implode(' ');

                return [
                    'url' => $entry['url'],
                    'title' => $title !== '' ? $title : null,
                    'season_number' => $playlistItem['season_number'] ?? $entry['season_number'],
                    'episode_number' => $playlistItem['episode_number'] ?? $entry['episode_number'],
                    'source_url' => $playlistUrl,
                    'kind' => $this->parsedMediaExtension($entry['url']) === 'm3u8' ? 'playlist' : 'file',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{url: string, title: string|null, season_number: int|null, episode_number: int|null, source_url: string|null, kind: string}  $playlistItem
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return list<array{url: string, title: string|null, season_number: int|null, episode_number: int|null, source_url: string|null, kind: string}>
     */
    private function parseSeasonvarPlaylistItem(array $playlistItem, ?callable $progress = null): array
    {
        $playlistUrl = $this->safeSeasonvarPlaylistUrl($playlistItem['url']);
        $playlistSourceUrl = $this->stableSeasonvarPlaylistSourceUrl($playlistUrl);
        $response = $this->httpClient->get($playlistUrl, 0, $progress);

        if (! $response->successful()) {
            throw new RuntimeException('Плейлист Seasonvar вернул HTTP '.$response->status().'.');
        }

        $decoded = json_decode($response->body(), true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw new RuntimeException('Плейлист Seasonvar вернул некорректный JSON.');
        }

        $seasonNumber = $playlistItem['season_number'];
        $items = [];

        foreach ($this->flattenSeasonvarPlaylistEntries($decoded) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $file = $entry['file'] ?? null;

            if (! is_string($file)) {
                continue;
            }

            $url = $this->decodeSeasonvarPlaylistFile($file);

            if ($url === null) {
                continue;
            }

            $title = $this->cleanSeasonvarPlaylistTitle($entry['title'] ?? null);
            $episodeNumber = $this->seasonvarPlaylistEpisodeNumber($entry, $title);

            $items[] = [
                'url' => $url,
                'title' => $title,
                'season_number' => $seasonNumber,
                'episode_number' => $episodeNumber,
                'source_url' => $playlistSourceUrl,
                'kind' => $this->parsedMediaExtension($url) === 'm3u8' ? 'playlist' : 'file',
            ];
        }

        return $items;
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return list<array<string, mixed>>
     */
    private function flattenSeasonvarPlaylistEntries(array $entries, int $depth = 0): array
    {
        if ($depth > 16) {
            return [];
        }

        $items = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (isset($entry['file']) && is_string($entry['file'])) {
                $items[] = $entry;
            }

            if (isset($entry['folder']) && is_array($entry['folder'])) {
                array_push($items, ...$this->flattenSeasonvarPlaylistEntries($entry['folder'], $depth + 1));
            }
        }

        return $items;
    }

    private function safeSeasonvarPlaylistUrl(string $url): string
    {
        $normalizedUrl = $this->seasonvarUrl->normalize($url, $this->seasonvarUrl->baseUrl());
        $host = Str::lower((string) parse_url($normalizedUrl, PHP_URL_HOST));
        $path = (string) parse_url($normalizedUrl, PHP_URL_PATH);

        if (! in_array($host, ['seasonvar.ru', 'www.seasonvar.ru'], true)) {
            throw new RuntimeException('Плейлист Seasonvar должен быть на seasonvar.ru.');
        }

        if (preg_match('~/playls2/.+?/plist\.txt$~iu', $path) !== 1) {
            throw new RuntimeException('Некорректная ссылка плейлиста Seasonvar.');
        }

        return $normalizedUrl;
    }

    private function stableSeasonvarPlaylistSourceUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        unset($query['time']);
        ksort($query);

        $stableUrl = Str::lower((string) $parts['scheme']).'://'.Str::lower((string) $parts['host']);

        if (isset($parts['port'])) {
            $stableUrl .= ':'.$parts['port'];
        }

        $stableUrl .= $parts['path'] ?? '/';

        if ($query !== []) {
            $stableUrl .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $stableUrl;
    }

    private function decodeSeasonvarPlaylistFile(string $file): ?string
    {
        $value = html_entity_decode($file, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(['\/', '\u002F', '\x2F'], '/', $value);
        $value = trim($value, " \t\n\r\0\x0B\"'()[]{};,");

        if (Str::startsWith($value, ['http://', 'https://', '//'])) {
            return $this->normalizeDecodedSeasonvarMediaUrl($value);
        }

        $value = ltrim($value, '#');
        $value = str_replace('//b2xvbG8=', '', $value);
        $candidates = array_filter([
            $value,
            mb_substr($value, 1),
        ]);

        foreach ($candidates as $candidate) {
            $decoded = base64_decode($candidate, true);

            if (! is_string($decoded) || $decoded === '') {
                continue;
            }

            $url = $this->normalizeDecodedSeasonvarMediaUrl($decoded);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function normalizeDecodedSeasonvarMediaUrl(string $url): ?string
    {
        $url = trim($url);

        if (Str::startsWith($url, '//')) {
            $url = 'https:'.$url;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! $this->isDirectPlayerMediaUrl($url)) {
            return null;
        }

        return $url;
    }

    private function cleanSeasonvarPlaylistTitle(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $title = strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $title = (string) Str::of($title)->replace("\xc2\xa0", ' ')->squish();

        return $title !== '' ? $title : null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function seasonvarPlaylistEpisodeNumber(array $entry, ?string $title): ?int
    {
        if (isset($entry['id']) && is_numeric($entry['id']) && (int) $entry['id'] > 0) {
            return (int) $entry['id'];
        }

        if ($title !== null && preg_match('/(?<episode>\d{1,3})\s*(?:серия|seriya|episode|ep)\b/iu', $title, $matches) === 1) {
            return (int) $matches['episode'];
        }

        return null;
    }

    private function mediaTitle(CatalogTitle $catalogTitle, ?Season $season, ?Episode $episode, bool $isTrailer = false): string
    {
        if ($isTrailer) {
            return collect([
                $catalogTitle->title,
                $season !== null ? $season->number.' сезон' : null,
                'трейлер',
            ])->filter()->implode(' - ');
        }

        if ($season === null || $episode === null) {
            return $catalogTitle->title.' - видео';
        }

        return sprintf('%s - %d сезон %d серия', $catalogTitle->title, $season->number, $episode->number);
    }

    /**
     * @param  array{url: string, title: string|null, season_number: int|null, episode_number: int|null, source_url: string|null, kind: string}  $item
     */
    private function sourceMediaKey(
        CatalogTitle $catalogTitle,
        ?Season $season,
        ?Episode $episode,
        array $item,
        string $playbackUrl,
        ?string $quality,
        string $format,
    ): string {
        return $this->mediaMetadata->sourceMediaKey(
            'seasonvar',
            $catalogTitle->source_url_hash ?: $catalogTitle->id,
            $season?->number,
            $episode?->number,
            $item['source_url'],
            $playbackUrl,
            $item['title'],
            $quality,
            $format,
        );
    }

    private function mediaQuality(?string $title, string $url): ?string
    {
        return $this->mediaMetadata->quality($title, $url);
    }

    private function mediaTranslationName(?string $title, ?string $sourceUrl): ?string
    {
        return $this->mediaMetadata->translationName($title, $sourceUrl);
    }

    private function checkMediaUrl(string $url, ?callable $progress = null): MediaHealthCheckResultData
    {
        return $this->mediaAvailabilityChecker->check($url, $progress);
    }

    /** @param array<string, mixed> $item */
    private function preparedMediaAvailability(array $item): ?MediaHealthCheckResultData
    {
        $availability = $item['availability'] ?? null;

        if (! is_array($availability)
            || ! isset($availability['available'], $availability['check_status'], $availability['checked_at'])
            || ! is_bool($availability['available'])
            || ! is_string($availability['check_status'])
            || ! is_string($availability['checked_at'])) {
            return null;
        }

        try {
            $checkedAt = Carbon::parse($availability['checked_at']);
        } catch (Throwable) {
            return null;
        }

        $errorCategory = isset($availability['error_category']) && is_string($availability['error_category'])
            ? MediaHealthErrorCategory::tryFrom($availability['error_category'])
            : null;

        return new MediaHealthCheckResultData(
            available: $availability['available'],
            checkStatus: $availability['check_status'],
            httpStatus: isset($availability['http_status']) && is_numeric($availability['http_status'])
                ? (int) $availability['http_status']
                : null,
            checkedAt: $checkedAt,
            latencyMs: isset($availability['latency_ms']) && is_numeric($availability['latency_ms'])
                ? (int) $availability['latency_ms']
                : null,
            errorCategory: $errorCategory,
            permanentFailure: (bool) ($availability['permanent_failure'] ?? false),
        );
    }

    private function isDirectPlayerMediaUrl(string $url): bool
    {
        return in_array($this->parsedMediaExtension($url), ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'm3u8'], true);
    }

    private function parsedMediaExtension(string $url): string
    {
        return Str::lower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
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
            ->whereDoesntHave('licensedMedia', fn (Builder $query): Builder => $query->published());
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
            ->whereDoesntHave('licensedMedia', fn (Builder $query): Builder => $query->published());
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
        SeasonvarImportEvent::query()->create([
            'seasonvar_import_run_id' => $importRunId,
            'source_page_id' => $page->id,
            'event' => $event,
            'level' => str_contains($event, 'failure') ? 'warning' : 'info',
            'context' => $context,
        ]);
    }

    /**
     * @return array{attached: int, updated: int, skipped: int, failed: int}
     */
    private function emptyMediaResult(): array
    {
        return [
            'attached' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
    }

    /**
     * @param  array{attached: int, updated: int, skipped: int, failed: int}  $left
     * @param  array{attached: int, updated: int, skipped: int, failed: int}  $right
     * @return array{attached: int, updated: int, skipped: int, failed: int}
     */
    private function mergeMediaResult(array $left, array $right): array
    {
        return [
            'attached' => $left['attached'] + $right['attached'],
            'updated' => $left['updated'] + $right['updated'],
            'skipped' => $left['skipped'] + $right['skipped'],
            'failed' => $left['failed'] + $right['failed'],
        ];
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
