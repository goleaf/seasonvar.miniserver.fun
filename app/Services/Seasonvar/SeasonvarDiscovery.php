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
    ) {}

    /**
     * @return list<string>
     */
    public function discoverFromSitemap(string $sitemapUrl, int $limit = 500, int $crawlDelaySeconds = 3): array
    {
        $normalizedSitemapUrl = $this->seasonvarUrl->normalize($sitemapUrl);

        if (! $this->seasonvarUrl->isAllowed($normalizedSitemapUrl)) {
            throw new RuntimeException('Sitemap URL is outside the allowed Seasonvar hosts.');
        }

        $queue = [$normalizedSitemapUrl];
        $visited = [];
        $discovered = [];

        while ($queue !== [] && count($discovered) < $limit) {
            $currentUrl = array_shift($queue);

            if (isset($visited[$currentUrl])) {
                continue;
            }

            $visited[$currentUrl] = true;
            $response = $this->httpClient->get($currentUrl, $crawlDelaySeconds);

            if (! $response->successful()) {
                continue;
            }

            $xml = $this->parseXml($response->body(), $currentUrl);

            foreach ($xml->xpath('//*[local-name()="sitemap"]/*[local-name()="loc"]') ?: [] as $loc) {
                $childSitemap = $this->safeUrl((string) $loc, $currentUrl);

                if ($childSitemap !== null && ! isset($visited[$childSitemap])) {
                    $queue[] = $childSitemap;
                }
            }

            foreach ($xml->xpath('//*[local-name()="url"]/*[local-name()="loc"]') ?: [] as $loc) {
                $url = $this->safeUrl((string) $loc, $currentUrl);

                if ($url === null) {
                    continue;
                }

                $pageType = $this->seasonvarUrl->pageType($url);
                $path = strtolower(parse_url($url, PHP_URL_PATH) ?: '');

                if ($pageType === 'serial' || str_ends_with($path, '.html')) {
                    $discovered[$url] = $url;
                }

                if (count($discovered) >= $limit) {
                    break;
                }
            }
        }

        return array_values($discovered);
    }

    private function parseXml(string $body, string $url): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException("Unable to parse sitemap XML: {$url}");
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
}
