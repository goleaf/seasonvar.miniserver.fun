<?php

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarRobotsRules;
use App\Enums\SeasonvarPageType;
use App\Services\Crawler\PoliteHttpClient;
use RuntimeException;
use Throwable;
use XMLReader;

class SeasonvarSitemapMirror
{
    private int $malformedUrlCount = 0;

    private int $blockedUrlCount = 0;

    private int $duplicateUrlCount = 0;

    /** @var list<string> */
    private array $warnings = [];

    private ?SeasonvarRobotsRules $robotsRules = null;

    public function __construct(
        private readonly PoliteHttpClient $httpClient,
        private readonly SeasonvarSource $seasonvarSource,
        private readonly SeasonvarUrl $seasonvarUrl,
        private readonly SeasonvarRobotsPolicy $robotsPolicy,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{
     *     index_url: string,
     *     archive_count: int,
     *     archives: list<array{url: string, archive_path: string, xml_path: string, url_count: int}>,
     *     urls: list<string>,
     *     counts: array<string, int>,
     *     malformed_url_count: int,
     *     blocked_url_count: int,
     *     duplicate_url_count: int,
     *     warnings: list<string>
     * }
     */
    public function mirror(?callable $progress = null): array
    {
        $this->resetDiagnostics();
        $this->ensureDirectories();

        $source = $this->seasonvarSource->current();
        $this->robotsRules = $this->robotsPolicy->fetch(
            $this->httpClient,
            (int) $source->crawl_delay_seconds,
            $progress,
        );
        $crawlDelaySeconds = $this->robotsRules->crawlDelaySeconds;
        $indexUrl = $this->normalizeSitemapUrl($this->seasonvarSource->sitemapUrl());

        if (! $this->robotsRules->allows($indexUrl)) {
            throw new RuntimeException('robots.txt запрещает обход настроенного индекса карты сайта Seasonvar.');
        }

        $indexResponse = $this->httpClient->get($indexUrl, $crawlDelaySeconds, $progress);

        if (! $indexResponse->successful()) {
            throw new RuntimeException('Не удалось скачать индекс карты сайта: HTTP '.$indexResponse->status());
        }

        $indexFile = $this->writeSitemapFile($indexUrl, $indexResponse->body(), 'sitemap_index.xml');
        $indexPath = $indexFile['xml_path'];

        $this->report($progress, 'sitemap-mirror-index-ready', [
            'index_url' => $indexUrl,
            'index_path' => $indexPath,
        ]);

        $allUrls = [];
        $archives = [];
        $visited = [];
        $queue = [$indexUrl => $indexFile];

        while ($queue !== []) {
            $currentUrl = array_key_first($queue);
            $currentFile = array_shift($queue);

            if ($currentUrl === null || isset($visited[$currentUrl])) {
                continue;
            }

            $currentXmlPath = $currentFile['xml_path'];
            $visited[$currentUrl] = true;
            $urlCount = 0;

            foreach ($this->urlsFromXmlFile($currentXmlPath, $currentUrl) as $url) {
                if (isset($allUrls[$url])) {
                    $this->duplicateUrlCount++;
                }

                $allUrls[$url] = $url;
                $urlCount++;
            }

            foreach ($this->sitemapUrlsFromXmlFile($currentXmlPath, $currentUrl) as $sitemapUrl) {
                if (isset($visited[$sitemapUrl]) || array_key_exists($sitemapUrl, $queue)) {
                    $this->report($progress, 'child-sitemap-skipped', [
                        'reason' => 'уже посещена или уже в очереди',
                        'url' => $sitemapUrl,
                    ]);

                    continue;
                }

                $sitemapFile = $this->downloadSitemap($sitemapUrl, $crawlDelaySeconds, $progress);
                $queue[$sitemapUrl] = $sitemapFile;

                $this->report($progress, 'child-sitemap-queued', [
                    'url' => $sitemapUrl,
                    'queue_size' => count($queue),
                ]);
            }

            $archives[] = [
                'url' => $currentUrl,
                'archive_path' => $currentFile['archive_path'],
                'xml_path' => $currentXmlPath,
                'url_count' => $urlCount,
            ];

            $this->report($progress, 'sitemap-mirror-archive-ready', [
                'url' => $currentUrl,
                'archive_path' => $currentFile['archive_path'],
                'xml_path' => $currentXmlPath,
                'url_count' => $urlCount,
            ]);
        }

        $urls = array_values($allUrls);
        $counts = $this->countsByType($urls);
        $allUrlsPath = $this->basePath('all_urls.txt');
        file_put_contents($allUrlsPath, implode(PHP_EOL, $urls).PHP_EOL);

        $countsPath = $this->basePath('counts.json');
        file_put_contents($countsPath, json_encode($counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->report($progress, 'sitemap-mirror-complete', [
            'archive_count' => count($archives),
            'unique_urls' => count($urls),
            'visited_sitemaps' => count($visited),
            'all_urls_path' => $allUrlsPath,
            'counts_path' => $countsPath,
            'counts' => $counts,
        ]);

        return [
            'index_url' => $indexUrl,
            'archive_count' => count($archives),
            'archives' => $archives,
            'urls' => $urls,
            'counts' => $counts,
            'malformed_url_count' => $this->malformedUrlCount,
            'blocked_url_count' => $this->blockedUrlCount,
            'duplicate_url_count' => $this->duplicateUrlCount,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param  list<string>  $urls
     * @return array<string, int>
     */
    private function countsByType(array $urls): array
    {
        $counts = [];

        foreach ($urls as $url) {
            $type = $this->seasonvarUrl->pageType($url)->value;
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{archive_path: string, xml_path: string}
     */
    private function downloadSitemap(string $url, int $crawlDelaySeconds, ?callable $progress = null): array
    {
        $response = $this->httpClient->get($url, $crawlDelaySeconds, $progress);

        if (! $response->successful()) {
            throw new RuntimeException("Не удалось скачать архив карты сайта {$url}: HTTP ".$response->status());
        }

        return $this->writeSitemapFile($url, $response->body());
    }

    /**
     * @return array{archive_path: string, xml_path: string}
     */
    private function writeSitemapFile(string $url, string $body, ?string $preferredName = null): array
    {
        $fileName = $preferredName ?? basename(parse_url($url, PHP_URL_PATH) ?: md5($url).'.xml');
        $archivePath = $this->basePath('archives/'.$fileName);
        $xmlPath = $this->basePath('xml/'.preg_replace('/\.gz$/', '', $fileName));

        file_put_contents($archivePath, $body);

        if (substr($body, 0, 2) === chr(31).chr(139)) {
            $decoded = gzdecode($body);

            if (! is_string($decoded)) {
                throw new RuntimeException("Не удалось распаковать карту сайта {$url}");
            }

            file_put_contents($xmlPath, $decoded);

            return [
                'archive_path' => $archivePath,
                'xml_path' => $xmlPath,
            ];
        }

        file_put_contents($xmlPath, $body);

        return [
            'archive_path' => $archivePath,
            'xml_path' => $xmlPath,
        ];
    }

    /**
     * @return \Generator<int, string>
     */
    private function sitemapUrlsFromXmlFile(string $path, string $baseUrl): \Generator
    {
        yield from $this->locationsFromXmlFile($path, $baseUrl, 'sitemap');
    }

    /**
     * @return \Generator<int, string>
     */
    private function urlsFromXmlFile(string $path, string $baseUrl): \Generator
    {
        yield from $this->locationsFromXmlFile($path, $baseUrl, 'url');
    }

    /**
     * @return \Generator<int, string>
     */
    private function locationsFromXmlFile(string $path, string $baseUrl, string $parentElement): \Generator
    {
        $reader = new XMLReader;
        $insideParent = false;

        if (! $reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException("Не удалось открыть XML-файл карты сайта: {$path}");
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === $parentElement) {
                    $insideParent = true;
                }

                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === $parentElement) {
                    $insideParent = false;
                }

                if (! $insideParent || $reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'loc') {
                    continue;
                }

                $url = $this->safeUrl($reader->readString(), $baseUrl);

                if ($url !== null) {
                    yield $url;
                }
            }
        } finally {
            $reader->close();
        }
    }

    private function safeUrl(string $url, string $baseUrl): ?string
    {
        try {
            $normalized = $this->seasonvarUrl->normalize($url, $baseUrl);
        } catch (Throwable) {
            $this->malformedUrlCount++;
            $this->addWarning('Карта сайта содержит ссылку с некорректным синтаксисом.');

            return null;
        }

        if ($this->seasonvarUrl->isMalformedCatalogUrl($normalized)) {
            $this->malformedUrlCount++;
            $this->addWarning('Карта сайта содержит вложенный путь после суффикса .html.');

            return null;
        }

        if (! $this->seasonvarUrl->isAllowed($normalized)) {
            $this->blockedUrlCount++;
            $this->addWarning('Карта сайта содержит ссылку за пределами разрешённой границы Seasonvar.');

            return null;
        }

        if ($this->robotsRules !== null && ! $this->robotsRules->allows($normalized)) {
            $this->blockedUrlCount++;
            $this->addWarning('robots.txt запрещает обход части URL из карты сайта.');

            return null;
        }

        return $normalized;
    }

    private function normalizeSitemapUrl(string $url): string
    {
        try {
            $normalized = $this->seasonvarUrl->normalize($url);
        } catch (Throwable $exception) {
            throw new RuntimeException('Настроена некорректная ссылка индекса карты сайта.', previous: $exception);
        }

        if (! $this->seasonvarUrl->isAllowed($normalized)
            || $this->seasonvarUrl->pageType($normalized) !== SeasonvarPageType::Sitemap) {
            throw new RuntimeException('Индекс карты сайта находится вне разрешённой границы Seasonvar.');
        }

        return $normalized;
    }

    private function resetDiagnostics(): void
    {
        $this->malformedUrlCount = 0;
        $this->blockedUrlCount = 0;
        $this->duplicateUrlCount = 0;
        $this->warnings = [];
        $this->robotsRules = null;
    }

    private function addWarning(string $warning): void
    {
        if (count($this->warnings) >= 20 || in_array($warning, $this->warnings, true)) {
            return;
        }

        $this->warnings[] = $warning;
    }

    private function ensureDirectories(): void
    {
        foreach (['', 'archives', 'xml'] as $directory) {
            $path = $this->basePath($directory);

            if (! is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    private function basePath(string $path = ''): string
    {
        $directory = trim((string) config('seasonvar.sitemap_storage_directory', 'seasonvar/sitemaps'), '/');

        if ($directory === ''
            || str_contains($directory, "\0")
            || str_contains($directory, '\\')
            || preg_match('~(?:^|/)\.\.?(/|$)~', $directory) === 1) {
            throw new RuntimeException('Настроен небезопасный каталог хранения карт сайта.');
        }

        return storage_path('app/'.$directory.($path === '' ? '' : '/'.$path));
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
