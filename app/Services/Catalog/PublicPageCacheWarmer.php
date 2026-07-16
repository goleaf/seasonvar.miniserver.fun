<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\CatalogRecommendationType;
use App\Models\CatalogTitle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;

final class PublicPageCacheWarmer
{
    public function __construct(private readonly PublicPageCacheManifest $manifest) {}

    public function titleCapacity(): int
    {
        $urlLimit = max(1, (int) config('cache-architecture.page_cache.warm_url_limit', 100));
        $criticalCount = count(array_unique($this->criticalUrls()));

        return max(0, min(
            (int) config('cache-architecture.page_cache.warm_title_limit', 250),
            $urlLimit - $criticalCount,
        ));
    }

    /**
     * @param  iterable<int, int|string>  $titleIds
     * @return array{attempted: int, succeeded: int}
     */
    public function warm(iterable $titleIds = []): array
    {
        if (! (bool) config('cache-architecture.page_cache.warming_enabled', true)) {
            return ['attempted' => 0, 'succeeded' => 0];
        }

        $baseUrl = $this->baseUrl();
        $limit = max(1, (int) config('cache-architecture.page_cache.warm_url_limit', 100));
        $targets = collect($this->criticalUrls())
            ->concat($this->titleUrls($titleIds))
            ->concat($this->manifest->recent($limit))
            ->filter(fn (string $url): bool => str_starts_with($url, '/')
                && ! str_starts_with($url, '//'))
            ->unique()
            ->take($limit)
            ->values();
        $http = Http::accept('text/html')
            ->withHeaders(['X-Seasonvar-Cache-Warm' => '1'])
            ->withUserAgent('Seasonvar-Cache-Warmer/1.0')
            ->connectTimeout(max(1, (int) config('cache-architecture.page_cache.warm_connect_timeout_seconds', 2)))
            ->timeout(max(1, (int) config('cache-architecture.page_cache.warm_timeout_seconds', 10)))
            ->retry(
                max(1, (int) config('cache-architecture.page_cache.warm_retry_times', 2)),
                max(0, (int) config('cache-architecture.page_cache.warm_retry_milliseconds', 100)),
                throw: false,
            );
        $succeeded = 0;

        foreach ($targets as $relativeUrl) {
            try {
                $response = $http->get($baseUrl.$relativeUrl);
            } catch (ConnectionException $exception) {
                throw new RuntimeException('Self-прогрев публичной страницы не смог подключиться к приложению.', previous: $exception);
            }

            if (! $response->successful()) {
                throw new RuntimeException("Self-прогрев публичной страницы вернул HTTP {$response->status()}.");
            }

            $succeeded++;
        }

        return ['attempted' => $targets->count(), 'succeeded' => $succeeded];
    }

    /** @return list<string> */
    private function criticalUrls(): array
    {
        return [
            route('home', [], false),
            route('stats', [], false),
            route('titles.index', [], false),
            ...collect(CatalogDirectoryRegistry::routeMap())
                ->keys()
                ->map(fn (string $directory): string => route($directory.'.index', [], false))
                ->all(),
            ...collect(CatalogRecommendationType::publicCases())
                ->filter(fn (CatalogRecommendationType $type): bool => $type->isIndexable())
                ->map(fn (CatalogRecommendationType $type): string => route(
                    'discover.index',
                    ['type' => $type->value],
                    false,
                ))
                ->all(),
        ];
    }

    /**
     * @param  iterable<int, int|string>  $titleIds
     * @return list<string>
     */
    private function titleUrls(iterable $titleIds): array
    {
        $ids = collect($titleIds)
            ->filter(fn (int|string $id): bool => is_int($id) || ctype_digit($id))
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->take(max(1, (int) config('cache-architecture.page_cache.warm_title_limit', 250)))
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $slugs = CatalogTitle::query()
            ->availableTo(null)
            ->whereKey($ids)
            ->pluck('slug', 'id');

        return $ids
            ->map(fn (int $id): ?string => is_string($slug = $slugs->get($id))
                ? route('titles.show', ['catalogTitle' => $slug], false)
                : null)
            ->filter()
            ->values()
            ->all();
    }

    private function baseUrl(): string
    {
        $configured = rtrim((string) config('cache-architecture.page_cache.warm_base_url', config('app.url')), '/');
        $application = rtrim((string) config('app.url'), '/');
        $configuredOrigin = $this->origin($configured);
        $applicationOrigin = $this->origin($application);

        if ($configuredOrigin === null || $applicationOrigin === null || $configuredOrigin !== $applicationOrigin) {
            throw new LogicException('URL self-прогрева должен использовать origin приложения.');
        }

        return $configuredOrigin;
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $defaultPort = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$host.($port !== null && $port !== $defaultPort ? ':'.$port : '');
    }
}
