<?php

namespace App\Services\Seasonvar;

use App\Services\Crawler\PoliteHttpClient;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use XMLReader;

class SeasonvarSitemapMirror
{
    public function __construct(
        private readonly PoliteHttpClient $httpClient,
        private readonly SeasonvarSource $seasonvarSource,
        private readonly SeasonvarUrl $seasonvarUrl,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{
     *     index_url: string,
     *     archive_count: int,
     *     archives: list<array{url: string, archive_path: string, xml_path: string, url_count: int}>,
     *     urls: list<string>,
     *     counts: array<string, int>
     * }
     */
    public function mirror(?callable $progress = null): array
    {
        $this->ensureDirectories();

        $indexUrl = $this->seasonvarSource->sitemapUrl();
        $source = $this->seasonvarSource->current();
        $indexResponse = $this->httpClient->get($indexUrl, (int) $source->crawl_delay_seconds, $progress);

        if (! $indexResponse->successful()) {
            throw new RuntimeException('Не удалось скачать индекс карты сайта: HTTP '.$indexResponse->status());
        }

        $indexPath = $this->basePath('sitemap_index.xml');
        file_put_contents($indexPath, $indexResponse->body());

        $archiveUrls = $this->archiveUrls($indexResponse->body(), $indexUrl);

        $this->report($progress, 'sitemap-mirror-index-ready', [
            'index_url' => $indexUrl,
            'index_path' => $indexPath,
            'archive_count' => count($archiveUrls),
        ]);

        $allUrls = [];
        $archives = [];

        foreach ($archiveUrls as $archiveUrl) {
            $archive = $this->downloadArchive($archiveUrl, (int) $source->crawl_delay_seconds, $progress);
            $urlCount = 0;

            foreach ($this->urlsFromXmlFile($archive['xml_path'], $archiveUrl) as $url) {
                $allUrls[$url] = $url;
                $urlCount++;
            }

            $archives[] = [
                'url' => $archiveUrl,
                'archive_path' => $archive['archive_path'],
                'xml_path' => $archive['xml_path'],
                'url_count' => $urlCount,
            ];

            $this->report($progress, 'sitemap-mirror-archive-ready', [
                'url' => $archiveUrl,
                'archive_path' => $archive['archive_path'],
                'xml_path' => $archive['xml_path'],
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
            $type = $this->seasonvarUrl->pageType($url);
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function archiveUrls(string $body, string $baseUrl): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException('Не удалось разобрать XML индекса карты сайта.');
        }

        $urls = [];

        foreach ($xml->xpath('//*[local-name()="sitemap"]/*[local-name()="loc"]') ?: [] as $loc) {
            $url = $this->safeUrl((string) $loc, $baseUrl);

            if ($url !== null && str_ends_with($url, '.xml.gz')) {
                $urls[$url] = $url;
            }
        }

        return array_values($urls);
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return array{archive_path: string, xml_path: string}
     */
    private function downloadArchive(string $url, int $crawlDelaySeconds, ?callable $progress = null): array
    {
        $response = $this->httpClient->get($url, $crawlDelaySeconds, $progress);

        if (! $response->successful()) {
            throw new RuntimeException("Не удалось скачать архив карты сайта {$url}: HTTP ".$response->status());
        }

        $archiveName = basename(parse_url($url, PHP_URL_PATH) ?: md5($url).'.xml.gz');
        $archivePath = $this->basePath('archives/'.$archiveName);
        $xmlPath = $this->basePath('xml/'.preg_replace('/\.gz$/', '', $archiveName));
        $body = $response->body();

        file_put_contents($archivePath, $body);

        if (substr($body, 0, 2) === chr(31).chr(139)) {
            $decoded = gzdecode($body);

            if (! is_string($decoded)) {
                throw new RuntimeException("Не удалось распаковать архив карты сайта {$url}");
            }

            file_put_contents($xmlPath, $decoded);
        } else {
            file_put_contents($xmlPath, $body);
        }

        return [
            'archive_path' => $archivePath,
            'xml_path' => $xmlPath,
        ];
    }

    /**
     * @return \Generator<int, string>
     */
    private function urlsFromXmlFile(string $path, string $baseUrl): \Generator
    {
        $reader = new XMLReader;

        if (! $reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException("Не удалось открыть XML-файл карты сайта: {$path}");
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'loc') {
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
            return null;
        }

        return $this->seasonvarUrl->isAllowed($normalized) ? $normalized : null;
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
        return storage_path('app/seasonvar/sitemaps'.($path === '' ? '' : '/'.$path));
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
