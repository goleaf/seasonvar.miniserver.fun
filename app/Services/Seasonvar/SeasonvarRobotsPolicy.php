<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarRobotsRules;
use App\Services\Crawler\PoliteHttpClient;
use RuntimeException;

final class SeasonvarRobotsPolicy
{
    private const MAX_ROBOTS_BYTES = 262_144;

    public function __construct(
        private readonly SeasonvarSource $source,
        private readonly SeasonvarUrl $urls,
    ) {}

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $progress
     */
    public function fetch(
        PoliteHttpClient $httpClient,
        int $configuredCrawlDelaySeconds,
        ?callable $progress = null,
    ): SeasonvarRobotsRules {
        $robotsUrl = $this->urls->normalize('/robots.txt', $this->source->baseUrl());

        if (! $this->urls->isAllowed($robotsUrl)) {
            throw new RuntimeException('Файл robots.txt находится вне разрешённой границы Seasonvar.');
        }

        $response = $httpClient->get($robotsUrl, $configuredCrawlDelaySeconds, $progress);

        if (! $response->successful()) {
            throw new RuntimeException('Не удалось проверить robots.txt Seasonvar: HTTP '.$response->status());
        }

        $body = $response->body();

        if (mb_strlen($body, '8bit') > self::MAX_ROBOTS_BYTES) {
            throw new RuntimeException('Файл robots.txt Seasonvar превышает безопасный размер.');
        }

        return $this->parse($body, $configuredCrawlDelaySeconds);
    }

    public function parse(string $body, int $configuredCrawlDelaySeconds = 0): SeasonvarRobotsRules
    {
        $groups = [];
        $agents = [];
        $rulesStarted = false;

        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s*#.*$/u', '', $line));

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$directive, $value] = array_map('trim', explode(':', $line, 2));
            $directive = mb_strtolower($directive);

            if ($directive === 'user-agent') {
                if ($rulesStarted) {
                    $agents = [];
                    $rulesStarted = false;
                }

                $agents[] = mb_strtolower($value);

                continue;
            }

            if ($agents === []) {
                continue;
            }

            if (in_array($directive, ['allow', 'disallow'], true)) {
                $rulesStarted = true;

                if ($value === '') {
                    continue;
                }

                foreach ($agents as $agent) {
                    $groups[$agent]['rules'][] = [
                        'pattern' => $value,
                        'allow' => $directive === 'allow',
                    ];
                }

                continue;
            }

            if ($directive === 'crawl-delay' && is_numeric($value)) {
                foreach ($agents as $agent) {
                    $groups[$agent]['crawl_delay'] = max(0, (int) ceil((float) $value));
                }
            }
        }

        $group = $groups['seasonvarcatalog'] ?? $groups['*'] ?? [];

        return new SeasonvarRobotsRules(
            rules: array_values($group['rules'] ?? []),
            crawlDelaySeconds: max($configuredCrawlDelaySeconds, (int) ($group['crawl_delay'] ?? 0)),
        );
    }
}
