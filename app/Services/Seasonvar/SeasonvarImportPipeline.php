<?php

namespace App\Services\Seasonvar;

use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\SeasonvarImportEvent;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use App\Services\Media\ExternalMediaMetadata;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class SeasonvarImportPipeline
{
    private bool $stopRequested = false;

    public function __construct(
        private readonly SeasonvarCatalogImporter $importer,
        private readonly SeasonvarSitemapMirror $sitemapMirror,
        private readonly SeasonvarTitleMerger $titleMerger,
        private readonly SeasonvarMediaAvailabilityChecker $mediaAvailabilityChecker,
        private readonly ExternalMediaMetadata $mediaMetadata,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function run(
        ?string $argument = null,
        bool $force = false,
        bool $forever = false,
        ?int $sleepSeconds = null,
        bool $discover = true,
        ?callable $progress = null,
    ): SeasonvarImportRun {
        $run = SeasonvarImportRun::query()->create([
            'mode' => $argument === null ? 'sitemap' : 'url',
            'status' => 'running',
            'argument' => $argument,
            'force' => $force,
            'forever' => $forever,
            'cycles' => 0,
            'started_at' => now(),
        ]);
        $loggedProgress = fn (string $event, array $context = []) => $this->recordProgress($run, $progress, $event, $context);
        $sleepSeconds = max(1, $sleepSeconds ?? (int) config('seasonvar.import.sleep_seconds', 60));

        $this->recordProgress($run, $progress, 'seasonvar-import-started', [
            'mode' => $run->mode,
            'argument' => $argument,
            'force' => $force,
            'forever' => $forever,
            'sleep_seconds' => $sleepSeconds,
        ]);

        try {
            do {
                $cycle = ((int) $run->cycles) + 1;
                $this->runCycle($run, $cycle, $argument, $force, $discover, $loggedProgress);
                $run->refresh();

                if (! $forever || $this->stopRequested) {
                    break;
                }

                $this->sleepBetweenCycles($sleepSeconds, $loggedProgress);
            } while (! $this->stopRequested);

            $run->fill([
                'status' => 'completed',
                'finished_at' => now(),
            ])->save();

            $this->recordProgress($run, $progress, 'seasonvar-import-complete', [
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
            ]);
        } catch (Throwable $exception) {
            $run->fill([
                'status' => 'failed',
                'last_error' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            $this->recordProgress($run, $progress, 'seasonvar-import-failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
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
     * @param  callable(string, array<string, mixed>): void  $progress
     */
    private function runCycle(
        SeasonvarImportRun $run,
        int $cycle,
        ?string $argument,
        bool $force,
        bool $discover,
        callable $progress,
    ): void {
        $progress('seasonvar-import-cycle-started', [
            'cycle' => $cycle,
        ]);

        $sourceStatusBackfillResult = $this->backfillParsedSourcePageStatuses($progress);
        $cycleResult = $argument === null
            ? $this->runSitemapCycle($run, $force, $discover, $progress)
            : $this->runUrlCycle($run, $argument, $force, $progress);
        $mediaMetadataResult = $this->refreshMediaMetadataBacklog($progress);
        $mediaBacklogResult = $this->refreshMediaBacklog($progress);
        $mergeResult = $this->titleMerger->merge($progress);

        $this->addRunCounters($run, [
            'cycles' => 1,
            ...$cycleResult,
            'media_updated' => $cycleResult['media_updated'] + $mediaBacklogResult['media_updated'],
            'media_failed' => $cycleResult['media_failed'] + $mediaBacklogResult['media_failed'],
        ], [
            'last_merge' => $mergeResult,
            'last_source_status_backfill' => $sourceStatusBackfillResult,
            'last_media_metadata_backlog' => $mediaMetadataResult,
            'last_media_backlog' => $mediaBacklogResult,
        ]);

        $progress('seasonvar-import-cycle-complete', [
            'cycle' => $cycle,
            ...$cycleResult,
            'source_status_backfilled' => $sourceStatusBackfillResult['backfilled'],
            'media_metadata_checked' => $mediaMetadataResult['media_checked'],
            'media_metadata_updated' => $mediaMetadataResult['media_updated'],
            'media_checked' => $mediaBacklogResult['media_checked'],
            'media_check_available' => $mediaBacklogResult['media_available'],
            'media_check_unavailable' => $mediaBacklogResult['media_unavailable'],
            'merged_titles' => $mergeResult['titles'],
            'merged_seasons' => $mergeResult['seasons'],
            'merged_episodes' => $mergeResult['episodes'],
        ]);
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array{selected: int, backfilled: int}
     */
    private function backfillParsedSourcePageStatuses(callable $progress): array
    {
        $limit = max(0, (int) config('seasonvar.import.source_status_backfill_per_cycle', 1000));

        if ($limit === 0) {
            return [
                'selected' => 0,
                'backfilled' => 0,
            ];
        }

        $ids = SourcePage::query()
            ->where('parse_status', 'parsed')
            ->where('import_status', 'pending')
            ->oldest('last_imported_at')
            ->oldest()
            ->limit($limit)
            ->pluck('id');

        $progress('source-pages-status-backfill-started', [
            'selected' => $ids->count(),
            'limit' => $limit,
        ]);

        if ($ids->isEmpty()) {
            $result = [
                'selected' => 0,
                'backfilled' => 0,
            ];

            $progress('source-pages-status-backfill-complete', $result);

            return $result;
        }

        $backfilled = SourcePage::query()
            ->whereKey($ids)
            ->update([
                'import_status' => 'parsed',
                'retry_after_at' => null,
                'last_imported_at' => DB::raw('COALESCE(last_imported_at, last_crawled_at, updated_at)'),
                'updated_at' => now(),
            ]);

        $result = [
            'selected' => $ids->count(),
            'backfilled' => $backfilled,
        ];

        $progress('source-pages-status-backfill-complete', $result);

        return $result;
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array{discovered: int, stored: int, selected: int, parsed: int, failed: int, media_attached: int, media_updated: int, media_skipped: int, media_failed: int, cleaned: int}
     */
    private function runSitemapCycle(SeasonvarImportRun $run, bool $force, bool $discover, callable $progress): array
    {
        $discovered = 0;
        $stored = 0;

        if ($discover) {
            $mirror = $this->sitemapMirror->mirror($progress);
            $urls = $mirror['urls'];
            $discovered = count($urls);
            $stored = $this->importer->storeDiscoveredUrls($urls, $progress);
        }

        $cleaned = $this->cleanupMalformedSourcePages($progress);
        $pages = $this->pagesForImportCycle($force, $progress);
        $parseResult = $this->importer->parsePages($pages, $progress, $force, $run->id);

        return [
            'discovered' => $discovered,
            'stored' => $stored,
            'selected' => $pages->count(),
            'parsed' => $parseResult['parsed'],
            'failed' => $parseResult['failed'],
            'media_attached' => $parseResult['media_attached'],
            'media_updated' => $parseResult['media_updated'],
            'media_skipped' => $parseResult['media_skipped'],
            'media_failed' => $parseResult['media_failed'],
            'cleaned' => $cleaned,
        ];
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array{discovered: int, stored: int, selected: int, parsed: int, failed: int, media_attached: int, media_updated: int, media_skipped: int, media_failed: int, cleaned: int}
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
            $progress('seasonvar-import-url-failed', [
                'url' => $argument,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
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
        ];
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return Collection<int, SourcePage>
     */
    private function pagesForImportCycle(bool $force, callable $progress): Collection
    {
        $batchSize = max(1, (int) config('seasonvar.import.parse_batch_size', 25));
        $refreshAfter = now()->subHours(max(1, (int) config('seasonvar.import.refresh_after_hours', 168)));

        $pages = SourcePage::query()
            ->with('source')
            ->where('page_type', 'serial')
            ->when(! $force, function ($query) use ($refreshAfter): void {
                $query->where(function ($query) use ($refreshAfter): void {
                    $query->where('parse_status', 'pending')
                        ->orWhere('import_status', 'missing_data')
                        ->orWhere(function ($query): void {
                            $query->where('parse_status', 'failed')
                                ->where(function ($query): void {
                                    $query->whereNull('retry_after_at')
                                        ->orWhere('retry_after_at', '<=', now());
                                });
                        })
                        ->orWhere(function ($query) use ($refreshAfter): void {
                            $query->where('parse_status', 'parsed')
                                ->where(function ($query) use ($refreshAfter): void {
                                    $query->whereNull('last_imported_at')
                                        ->orWhere('last_imported_at', '<=', $refreshAfter);
                                });
                        });
                });
            })
            ->orderByRaw("CASE import_status WHEN 'pending' THEN 0 WHEN 'missing_data' THEN 1 WHEN 'failed' THEN 2 ELSE 3 END")
            ->oldest('last_imported_at')
            ->oldest()
            ->limit($batchSize)
            ->get();

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

        return $pages;
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
        $limit = max(0, (int) config('seasonvar.media_check.backfill_per_cycle', 25));

        if ($limit === 0 || ! (bool) config('seasonvar.media_check.enabled', true)) {
            return [
                'media_checked' => 0,
                'media_available' => 0,
                'media_unavailable' => 0,
                'media_updated' => 0,
                'media_failed' => 0,
            ];
        }

        $refreshAfter = now()->subHours(max(1, (int) config('seasonvar.media_check.refresh_after_hours', 168)));
        $mediaItems = LicensedMedia::query()
            ->where(function ($query) use ($refreshAfter): void {
                $query->whereNull('check_status')
                    ->orWhereNull('checked_at')
                    ->orWhere('checked_at', '<=', $refreshAfter)
                    ->orWhereIn('check_status', ['check_failed', 'unavailable']);
            })
            ->where(function ($query): void {
                $query->whereNotNull('playback_url')
                    ->orWhereNotNull('path');
            })
            ->oldest('checked_at')
            ->oldest()
            ->limit($limit)
            ->get();

        $progress('seasonvar-media-backlog-started', [
            'selected' => $mediaItems->count(),
            'limit' => $limit,
        ]);

        $result = [
            'media_checked' => 0,
            'media_available' => 0,
            'media_unavailable' => 0,
            'media_updated' => 0,
            'media_failed' => 0,
        ];

        foreach ($mediaItems as $media) {
            $url = $media->playback_url ?: $media->path;

            if (! is_string($url) || trim($url) === '') {
                $media->fill([
                    'check_status' => 'invalid_url',
                    'status' => 'unavailable',
                    'checked_at' => now(),
                ])->save();

                $result['media_failed']++;

                continue;
            }

            $availability = $this->mediaAvailabilityChecker->check($url, $progress);
            $media->fill([
                'status' => $availability['available'] ? 'published' : 'unavailable',
                'check_status' => $availability['status'],
                'last_http_status' => $availability['http_status'],
                'checked_at' => $availability['checked_at'],
                'published_at' => $availability['available'] ? ($media->published_at ?? now()) : $media->published_at,
            ])->save();

            $result['media_checked']++;
            $result['media_updated']++;

            if ($availability['available']) {
                $result['media_available']++;
            } else {
                $result['media_unavailable']++;
                $result['media_failed']++;
            }
        }

        $progress('seasonvar-media-backlog-complete', $result);

        return $result;
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array{media_checked: int, media_updated: int}
     */
    private function refreshMediaMetadataBacklog(callable $progress): array
    {
        $limit = max(0, (int) config('seasonvar.media_metadata.backfill_per_cycle', 100));

        if ($limit === 0) {
            return [
                'media_checked' => 0,
                'media_updated' => 0,
            ];
        }

        $mediaItems = LicensedMedia::query()
            ->where(function ($query): void {
                $query->whereNull('quality')
                    ->orWhereNull('format')
                    ->orWhere('format', '')
                    ->orWhereNull('translation_name');
            })
            ->where(function ($query): void {
                $query->whereNotNull('playback_url')
                    ->orWhereNotNull('path');
            })
            ->oldest('updated_at')
            ->oldest()
            ->limit($limit)
            ->get();

        $progress('seasonvar-media-metadata-backlog-started', [
            'selected' => $mediaItems->count(),
            'limit' => $limit,
        ]);

        $result = [
            'media_checked' => 0,
            'media_updated' => 0,
        ];

        foreach ($mediaItems as $media) {
            $url = $media->playback_url ?: $media->path;

            if (! is_string($url) || trim($url) === '') {
                continue;
            }

            $updates = [];
            $quality = $this->mediaMetadata->quality($media->title, $url);
            $format = $this->mediaMetadata->format($url);
            $translationName = $this->mediaMetadata->translationName($media->title);

            if ($quality !== null && $quality !== $media->quality) {
                $updates['quality'] = $quality;
            }

            if ($format !== '' && $format !== $media->format) {
                $updates['format'] = $format;
            }

            if ($translationName !== null && $translationName !== $media->translation_name) {
                $updates['translation_name'] = $translationName;
            }

            $result['media_checked']++;

            if ($updates === []) {
                continue;
            }

            $media->fill($updates)->save();
            $result['media_updated']++;

            $progress('seasonvar-media-metadata-updated', [
                'licensed_media_id' => $media->id,
                'quality' => $media->quality,
                'format' => $media->format,
                'translation_name' => $media->translation_name,
                'url' => $url,
            ]);
        }

        $progress('seasonvar-media-metadata-backlog-complete', $result);

        return $result;
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @param  Collection<int, array<string, mixed>>  $parsedUrls
     */
    private function parseUrl(SeasonvarImportRun $run, string $url, bool $force, callable $progress, Collection $parsedUrls): ?CatalogTitle
    {
        $pages = $this->importer->pagesForArgument($url, 1, $progress);
        $page = $pages->first();

        if ($page === null) {
            return null;
        }

        if ($parsedUrls->contains('url', $page->url)) {
            return $this->catalogTitleForPage($page);
        }

        $result = $this->importer->parsePage($page, $progress, $force, $run->id);
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

        return $result['catalog_title'] ?? $this->catalogTitleForPage($page);
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @param  Collection<int, array<string, mixed>>  $parsedUrls
     */
    private function parseSeasonUrls(SeasonvarImportRun $run, CatalogTitle $catalogTitle, bool $force, callable $progress, Collection $parsedUrls): void
    {
        $seasonLimit = max(1, (int) config('seasonvar.import.season_url_limit', 200));
        $seasonUrls = $catalogTitle->fresh(['seasons'])?->seasons
            ->pluck('source_url')
            ->filter()
            ->unique()
            ->filter(fn (string $seasonUrl): bool => $this->isDirectSeasonvarSeasonUrl($seasonUrl))
            ->take($seasonLimit)
            ->values() ?? collect();

        $progress('seasonvar-import-season-urls-selected', [
            'catalog_title_id' => $catalogTitle->id,
            'title' => $catalogTitle->title,
            'selected' => $seasonUrls->count(),
        ]);

        foreach ($seasonUrls as $seasonUrl) {
            try {
                $this->parseUrl($run, (string) $seasonUrl, $force, $progress, $parsedUrls);
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
                $progress('seasonvar-import-season-url-failed', [
                    'catalog_title_id' => $catalogTitle->id,
                    'url' => (string) $seasonUrl,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
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
            && preg_match('~^/serial-\d+-[^/]+-\d+-season\.html$~iu', $path) === 1;
    }

    private function catalogTitleForPage(SourcePage $page): ?CatalogTitle
    {
        return CatalogTitle::query()
            ->where('source_page_id', $page->id)
            ->orWhere(function ($query) use ($page): void {
                $query->where('source_id', $page->source_id)
                    ->where('source_url_hash', $page->url_hash);
            })
            ->first()
            ?? Season::query()
                ->with('catalogTitle')
                ->where('source_url_hash', $page->url_hash)
                ->first()
                ?->catalogTitle;
    }

    /**
     * @param  array<string, int>  $counters
     * @param  array<string, mixed>  $summary
     */
    private function addRunCounters(SeasonvarImportRun $run, array $counters, array $summary = []): void
    {
        $run->refresh();
        $run->fill([
            'cycles' => ((int) $run->cycles) + ($counters['cycles'] ?? 0),
            'discovered' => ((int) $run->discovered) + ($counters['discovered'] ?? 0),
            'stored' => ((int) $run->stored) + ($counters['stored'] ?? 0),
            'selected' => ((int) $run->selected) + ($counters['selected'] ?? 0),
            'parsed' => ((int) $run->parsed) + ($counters['parsed'] ?? 0),
            'failed' => ((int) $run->failed) + ($counters['failed'] ?? 0),
            'media_attached' => ((int) $run->media_attached) + ($counters['media_attached'] ?? 0),
            'media_updated' => ((int) $run->media_updated) + ($counters['media_updated'] ?? 0),
            'media_skipped' => ((int) $run->media_skipped) + ($counters['media_skipped'] ?? 0),
            'media_failed' => ((int) $run->media_failed) + ($counters['media_failed'] ?? 0),
            'summary' => array_merge($run->summary ?? [], $summary),
        ])->save();
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @param  array<string, mixed>  $context
     */
    private function recordProgress(?SeasonvarImportRun $run, ?callable $progress, string $event, array $context = []): void
    {
        if ($run !== null) {
            SeasonvarImportEvent::query()->create([
                'seasonvar_import_run_id' => $run->id,
                'source_page_id' => $context['source_page_id'] ?? null,
                'catalog_title_id' => $context['catalog_title_id'] ?? null,
                'event' => $event,
                'level' => $this->eventLevel($event),
                'context' => $context,
            ]);
        }

        if ($progress !== null) {
            $progress($event, $context);
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
