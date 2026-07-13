<?php

namespace App\Services\Seasonvar;

use App\Services\Crawler\PoliteHttpClient;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

class SeasonvarDiscovery
{
    public function __construct(
        private readonly PoliteHttpClient $httpClient,
        private readonly SeasonvarUrl $seasonvarUrl,
        private readonly SeasonvarImportErrorSanitizer $errors,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     * @return list<string>
     */
    public function discoverFromSitemap(
        string $sitemapUrl,
        int $crawlDelaySeconds = 3,
        ?callable $progress = null,
    ): array {
        $normalizedSitemapUrl = $this->seasonvarUrl->normalize($sitemapUrl);

        $this->report($progress, 'sitemap-discovery-started', [
            'sitemap_url' => $normalizedSitemapUrl,
            'crawl_delay_seconds' => $crawlDelaySeconds,
        ]);

        if (! $this->seasonvarUrl->isAllowed($normalizedSitemapUrl)) {
            $this->report($progress, 'sitemap-discovery-blocked', [
                'sitemap_url' => $normalizedSitemapUrl,
            ]);

            throw new RuntimeException('Ссылка карты сайта находится вне разрешенных хостов Seasonvar.');
        }

        $queue = [$normalizedSitemapUrl];
        $visited = [];
        $discovered = [];

        while ($queue !== []) {
            $currentUrl = array_shift($queue);

            if (isset($visited[$currentUrl])) {
                $this->report($progress, 'sitemap-already-visited', [
                    'url' => $currentUrl,
                ]);

                continue;
            }

            $visited[$currentUrl] = true;

            $this->report($progress, 'sitemap-fetch-started', [
                'url' => $currentUrl,
                'queue_remaining' => count($queue),
                'visited' => count($visited),
                'discovered' => count($discovered),
            ]);

            $response = $this->httpClient->get($currentUrl, $crawlDelaySeconds, $progress);

            $this->report($progress, 'sitemap-fetch-complete', [
                'url' => $currentUrl,
                'http_status' => $response->status(),
                'successful' => $response->successful(),
                'body_bytes' => mb_strlen($response->body(), '8bit'),
            ]);

            if (! $response->successful()) {
                $this->report($progress, 'sitemap-fetch-failed', [
                    'url' => $currentUrl,
                    'http_status' => $response->status(),
                ]);

                continue;
            }

            try {
                $xml = $this->parseXml($response->body(), $currentUrl, $progress);
            } catch (RuntimeException $exception) {
                $this->report($progress, 'sitemap-xml-failed', [
                    'url' => $currentUrl,
                    'message' => $this->errors->fromException($exception),
                ]);

                if ($currentUrl === $normalizedSitemapUrl) {
                    throw $exception;
                }

                continue;
            }

            $childSitemapLocations = $xml->xpath('//*[local-name()="sitemap"]/*[local-name()="loc"]') ?: [];
            $urlLocations = $xml->xpath('//*[local-name()="url"]/*[local-name()="loc"]') ?: [];

            $this->report($progress, 'sitemap-xml-parsed', [
                'url' => $currentUrl,
                'child_sitemaps' => count($childSitemapLocations),
                'url_locations' => count($urlLocations),
            ]);

            foreach ($childSitemapLocations as $loc) {
                $rawUrl = (string) $loc;
                $childSitemap = $this->safeUrl($rawUrl, $currentUrl);

                if ($childSitemap === null) {
                    $this->report($progress, 'child-sitemap-skipped', [
                        'reason' => 'некорректная или заблокированная ссылка',
                        'raw_url' => $rawUrl,
                        'base_url' => $currentUrl,
                    ]);

                    continue;
                }

                if (! isset($visited[$childSitemap])) {
                    $queue[] = $childSitemap;

                    $this->report($progress, 'child-sitemap-queued', [
                        'url' => $childSitemap,
                        'queue_size' => count($queue),
                    ]);

                    continue;
                }

                $this->report($progress, 'child-sitemap-skipped', [
                    'reason' => 'уже посещена',
                    'url' => $childSitemap,
                ]);
            }

            foreach ($urlLocations as $loc) {
                $rawUrl = (string) $loc;
                $url = $this->safeUrl($rawUrl, $currentUrl);

                if ($url === null) {
                    $this->report($progress, 'catalog-url-skipped', [
                        'reason' => 'некорректная или заблокированная ссылка',
                        'raw_url' => $rawUrl,
                        'base_url' => $currentUrl,
                    ]);

                    continue;
                }

                $pageType = $this->seasonvarUrl->pageType($url);

                if ($pageType === 'serial') {
                    $isDuplicate = array_key_exists($url, $discovered);
                    $discovered[$url] = $url;

                    $this->report($progress, $isDuplicate ? 'catalog-url-duplicate' : 'catalog-url-discovered', [
                        'url' => $url,
                        'page_type' => $pageType,
                        'discovered' => count($discovered),
                    ]);
                } else {
                    $this->report($progress, 'catalog-url-skipped', [
                        'reason' => 'не сериал',
                        'page_type' => $pageType,
                        'url' => $url,
                    ]);
                }
            }
        }

        $this->report($progress, 'sitemap-discovery-complete', [
            'visited_sitemaps' => count($visited),
            'queued_sitemaps_remaining' => count($queue),
            'discovered' => count($discovered),
        ]);

        return array_values($discovered);
    }

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    private function parseXml(string $body, string $url, ?callable $progress = null): SimpleXMLElement
    {
        if (substr($body, 0, 2) === chr(31).chr(139)) {
            $this->report($progress, 'sitemap-xml-gzip-detected', [
                'url' => $url,
                'compressed_bytes' => mb_strlen($body, '8bit'),
            ]);

            $decoded = gzdecode($body);

            if (! is_string($decoded)) {
                throw new RuntimeException("Не удалось распаковать XML карты сайта: {$url}");
            }

            $body = $decoded;

            $this->report($progress, 'sitemap-xml-decompressed', [
                'url' => $url,
                'xml_bytes' => mb_strlen($body, '8bit'),
            ]);
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException("Не удалось разобрать XML карты сайта: {$url}");
        }

        return $xml;
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
