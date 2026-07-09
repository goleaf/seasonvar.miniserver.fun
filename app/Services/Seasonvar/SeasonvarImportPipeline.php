<?php

namespace App\Services\Seasonvar;

use App\Models\CatalogTitle;
use App\Models\Season;
use App\Models\SeasonvarImportEvent;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use Illuminate\Support\Collection;
use Throwable;

class SeasonvarImportPipeline
{
    private bool $stopRequested = false;

    public function __construct(
        private readonly SeasonvarCatalogImporter $importer,
        private readonly SeasonvarSitemapMirror $sitemapMirror,
        private readonly SeasonvarTitleMerger $titleMerger,
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

        $cycleResult = $argument === null
            ? $this->runSitemapCycle($run, $force, $discover, $progress)
            : $this->runUrlCycle($run, $argument, $force, $progress);
        $mergeResult = $this->titleMerger->merge($progress);

        $this->addRunCounters($run, [
            'cycles' => 1,
            ...$cycleResult,
        ], [
            'last_merge' => $mergeResult,
        ]);

        $progress('seasonvar-import-cycle-complete', [
            'cycle' => $cycle,
            ...$cycleResult,
            'merged_titles' => $mergeResult['titles'],
            'merged_seasons' => $mergeResult['seasons'],
            'merged_episodes' => $mergeResult['episodes'],
        ]);
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array{discovered: int, stored: int, selected: int, parsed: int, failed: int, media_attached: int, media_updated: int, media_skipped: int, media_failed: int}
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
        ];
    }

    /**
     * @param  callable(string, array<string, mixed>): void  $progress
     * @return array{discovered: int, stored: int, selected: int, parsed: int, failed: int, media_attached: int, media_updated: int, media_skipped: int, media_failed: int}
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
