<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OutputsSeasonvarProgress;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarCatalogImporter;
use App\Services\Seasonvar\SeasonvarSitemapMirror;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

#[Signature('seasonvar:full-sync {--discover= : Сколько ссылок из карты сайта добавлять за цикл} {--parse= : Сколько страниц из очереди разбирать за цикл} {--sleep= : Пауза между циклами в секундах} {--once : Выполнить один цикл и остановиться} {--no-media : Не подключать медиа автоматически из настроенного хранилища}')]
#[Description('Постоянно находит, сохраняет и разбирает каталог seasonvar.ru из sitemap_index.xml')]
class FullSyncSeasonvar extends Command
{
    use OutputsSeasonvarProgress;

    private bool $stopRequested = false;

    /**
     * Execute the console command.
     */
    public function handle(SeasonvarCatalogImporter $importer, SeasonvarSitemapMirror $sitemapMirror): int
    {
        $this->registerSignalHandlers();

        $discoverLimit = $this->positiveOption('discover', (int) config('seasonvar.full_sync.discover_limit', 500));
        $parseLimit = $this->positiveOption('parse', (int) config('seasonvar.full_sync.parse_limit', 25));
        $sleepSeconds = $this->positiveOption('sleep', (int) config('seasonvar.full_sync.sleep_seconds', 60));
        $runOnce = (bool) $this->option('once');
        $cycle = 0;

        $this->writeSeasonvarProgress('full-sync-started', [
            'discover_limit' => $discoverLimit,
            'parse_limit' => $parseLimit,
            'sleep_seconds' => $sleepSeconds,
            'once' => $runOnce,
        ]);

        do {
            $cycle++;

            try {
                $this->runCycle($importer, $sitemapMirror, $cycle, $discoverLimit, $parseLimit);
            } catch (Throwable $exception) {
                $this->writeSeasonvarProgress('full-sync-cycle-failed', [
                    'cycle' => $cycle,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                if ($runOnce) {
                    return self::FAILURE;
                }
            }

            if ($runOnce || $this->stopRequested) {
                break;
            }

            $this->sleepBetweenCycles($sleepSeconds);
        } while (! $this->stopRequested);

        $this->writeSeasonvarProgress('full-sync-stopped', [
            'cycles' => $cycle,
        ]);

        return self::SUCCESS;
    }

    private function runCycle(
        SeasonvarCatalogImporter $importer,
        SeasonvarSitemapMirror $sitemapMirror,
        int $cycle,
        int $discoverLimit,
        int $parseLimit,
    ): void {
        $progress = $this->seasonvarProgress();

        $this->writeSeasonvarProgress('full-sync-cycle-started', [
            'cycle' => $cycle,
        ]);

        $mirror = $sitemapMirror->mirror($progress);
        $urls = $discoverLimit > 0 ? array_slice($mirror['urls'], 0, $discoverLimit) : $mirror['urls'];
        $stored = $importer->storeDiscoveredUrls($urls);
        $pages = $this->pagesForParseCycle($importer, $parseLimit, $progress);
        $parseResult = $importer->parsePages($pages, $progress);
        $mediaAttached = (bool) $this->option('no-media') ? 0 : $this->attachLicensedMedia($parseLimit);

        foreach ($parseResult['failures'] as $failure) {
            $this->warn("Ошибка: {$failure}");
        }

        $this->writeSeasonvarProgress('full-sync-cycle-complete', [
            'cycle' => $cycle,
            'archives' => $mirror['archive_count'],
            'total_urls' => count($mirror['urls']),
            'stored_urls_this_cycle' => count($urls),
            'counts' => $mirror['counts'],
            'discovered' => count($urls),
            'stored' => $stored,
            'selected_for_parse' => $pages->count(),
            'parsed' => $parseResult['parsed'],
            'failed' => $parseResult['failed'],
            'media_attached' => $mediaAttached,
        ]);
    }

    private function pagesForParseCycle(SeasonvarCatalogImporter $importer, int $parseLimit, callable $progress): Collection
    {
        $refreshReserve = $parseLimit > 1 ? max(1, intdiv($parseLimit, 5)) : 0;
        $pendingLimit = max(1, $parseLimit - $refreshReserve);
        $pages = $importer->pendingPages($pendingLimit, $progress);
        $remaining = max(0, $parseLimit - $pages->count());

        if ($remaining === 0) {
            return $pages;
        }

        $refreshPages = SourcePage::query()
            ->with('source')
            ->where('page_type', 'serial')
            ->where('parse_status', 'parsed')
            ->whereNotIn('id', $pages->pluck('id'))
            ->oldest('last_crawled_at')
            ->oldest()
            ->limit($remaining)
            ->get();

        foreach ($refreshPages as $page) {
            $this->writeSeasonvarProgress('source-page-refresh-selected', [
                'source_page_id' => $page->id,
                'page_type' => $page->page_type,
                'parse_status' => $page->parse_status,
                'http_status' => $page->http_status,
                'last_crawled_at' => $page->last_crawled_at,
                'url' => $page->url,
            ]);
        }

        return $pages->concat($refreshPages)->values();
    }

    private function attachLicensedMedia(int $limit): int
    {
        $baseUrl = trim((string) config('licensed_media.remote_base_url'));

        if ($baseUrl === '') {
            $this->writeSeasonvarProgress('licensed-media-auto-attach-skipped', [
                'reason' => 'базовая ссылка медиа не настроена',
            ]);

            return 0;
        }

        if (! $this->isSafeRemoteMediaBaseUrl($baseUrl)) {
            $this->writeSeasonvarProgress('licensed-media-auto-attach-skipped', [
                'reason' => 'базовая ссылка медиа заблокирована',
                'base_url' => $baseUrl,
            ]);

            return 0;
        }

        $extension = trim((string) config('licensed_media.default_extension', 'mp4'), '. ');

        if ($extension === '') {
            $extension = 'mp4';
        }

        $titles = CatalogTitle::query()
            ->whereDoesntHave('licensedMedia')
            ->latest('indexed_at')
            ->limit(max(1, $limit))
            ->get();

        $attached = 0;

        foreach ($titles as $catalogTitle) {
            $playbackUrl = Str::finish($baseUrl, '/').$catalogTitle->slug.'.'.$extension;

            LicensedMedia::query()->updateOrCreate(
                [
                    'catalog_title_id' => $catalogTitle->id,
                    'playback_url' => $playbackUrl,
                ],
                [
                    'title' => $catalogTitle->title,
                    'storage_disk' => 'remote',
                    'path' => $playbackUrl,
                    'status' => 'published',
                    'published_at' => now(),
                ],
            );

            $attached++;

            $this->writeSeasonvarProgress('licensed-media-attached', [
                'catalog_title_id' => $catalogTitle->id,
                'slug' => $catalogTitle->slug,
                'playback_url' => $playbackUrl,
            ]);
        }

        return $attached;
    }

    private function positiveOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return max(1, $default);
        }

        return max(1, (int) $value);
    }

    private function isSafeRemoteMediaBaseUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = Str::lower((string) parse_url($url, PHP_URL_SCHEME));
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        return ! Str::is([
            'seasonvar.ru',
            '*.seasonvar.ru',
            'angrycdn.net',
            '*.angrycdn.net',
            'bigsv.ru',
            '*.bigsv.ru',
        ], $host);
    }

    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, function () use ($signal): void {
                $this->stopRequested = true;
                $this->writeSeasonvarProgress('full-sync-stop-requested', [
                    'signal' => $signal,
                ]);
            });
        }
    }

    private function sleepBetweenCycles(int $sleepSeconds): void
    {
        $this->writeSeasonvarProgress('full-sync-sleep-started', [
            'seconds' => $sleepSeconds,
        ]);

        for ($second = 0; $second < $sleepSeconds && ! $this->stopRequested; $second++) {
            sleep(1);
        }
    }
}
