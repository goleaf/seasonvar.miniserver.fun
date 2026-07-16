<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\PublicCacheWarmTarget;
use App\Enums\CatalogRecommendationType;
use App\Models\CatalogTitle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

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
     * @return array{
     *     attempted: int,
     *     succeeded: int,
     *     failed: int,
     *     skipped: int,
     *     limited: bool,
     *     errors: list<array{fingerprint: string, status: int|null, exception: string|null}>
     * }
     */
    public function warm(iterable $titleIds = []): array
    {
        if (! (bool) config('cache-architecture.page_cache.warming_enabled', true)) {
            return [
                'attempted' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'skipped' => 0,
                'limited' => false,
                'errors' => [],
            ];
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
        $result = $this->executeTargets(
            $targets->map(fn (string $relativeUrl): PublicCacheWarmTarget => new PublicCacheWarmTarget($relativeUrl)),
            failFast: false,
            baseUrl: $baseUrl,
            budgetSeconds: max(1, (int) config(
                'cache-architecture.page_cache.warm_budget_seconds',
                240,
            )),
        );

        return $result;
    }

    /**
     * @param  iterable<array-key, PublicCacheWarmTarget>  $targets
     * @return array{
     *     attempted: int,
     *     succeeded: int,
     *     failed: int,
     *     skipped: int,
     *     limited: bool,
     *     errors: list<array{fingerprint: string, status: int|null, exception: string|null}>
     * }
     */
    public function warmTargets(iterable $targets): array
    {
        if (! (bool) config('cache-architecture.page_cache.warming_enabled', true)) {
            return [
                'attempted' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'skipped' => 0,
                'limited' => false,
                'errors' => [],
            ];
        }

        return $this->executeTargets($targets, failFast: false, baseUrl: $this->baseUrl());
    }

    /** @return list<string> */
    private function criticalUrls(): array
    {
        return [
            route('home', [], false),
            ...collect((array) config('catalog-collections.supported_locales', []))
                ->filter(fn (mixed $locale): bool => is_string($locale) && $locale !== '')
                ->map(fn (string $locale): string => route('localized.home', ['locale' => $locale], false))
                ->all(),
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
            ...collect((array) config('catalog-collections.supported_locales', []))
                ->filter(fn (mixed $locale): bool => is_string($locale) && $locale !== '')
                ->flatMap(fn (string $locale): array => collect(CatalogRecommendationType::publicCases())
                    ->filter(fn (CatalogRecommendationType $type): bool => $type->isIndexable())
                    ->map(fn (CatalogRecommendationType $type): string => route('localized.discover.index', [
                        'locale' => $locale,
                        'type' => $type->value,
                    ], false))
                    ->all())
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

    /**
     * @param  iterable<array-key, PublicCacheWarmTarget>  $targets
     * @return array{
     *     attempted: int,
     *     succeeded: int,
     *     failed: int,
     *     skipped: int,
     *     limited: bool,
     *     errors: list<array{fingerprint: string, status: int|null, exception: string|null}>
     * }
     */
    private function executeTargets(
        iterable $targets,
        bool $failFast,
        string $baseUrl,
        ?int $budgetSeconds = null,
    ): array {
        $attempted = 0;
        $succeeded = 0;
        $errors = [];
        $skipped = 0;
        $targetList = collect($targets)->values();
        $deadline = $budgetSeconds !== null
            ? hrtime(true) + (max(1, $budgetSeconds) * 1_000_000_000)
            : null;

        foreach ($targetList as $index => $target) {
            if (! $failFast && $index > 0) {
                usleep(max(0, (int) config(
                    'cache-architecture.warming.full_request_delay_milliseconds',
                    100,
                )) * 1_000);
            }

            if ($deadline !== null && hrtime(true) >= $deadline) {
                $skipped = $targetList->count() - $index;

                break;
            }

            $attempted++;

            if (! $this->validRelativeUrl($target->relativeUrl)) {
                $exception = new InvalidArgumentException('Цель публичного прогрева должна быть безопасным relative URL.');

                if ($failFast) {
                    throw $exception;
                }

                $errors[] = $this->error($target->relativeUrl, null, $exception);

                continue;
            }

            try {
                $response = $this->http($target->accept)->get($baseUrl.$target->relativeUrl);
            } catch (ConnectionException $exception) {
                if ($failFast) {
                    throw new RuntimeException(
                        'Self-прогрев публичной страницы не смог подключиться к приложению.',
                        previous: $exception,
                    );
                }

                $errors[] = $this->error($target->relativeUrl, null, $exception);

                continue;
            }

            if (! $response->successful()) {
                if ($failFast) {
                    throw new RuntimeException("Self-прогрев публичной страницы вернул HTTP {$response->status()}.");
                }

                $errors[] = $this->error($target->relativeUrl, $response->status(), null);

                continue;
            }

            $succeeded++;
        }

        return [
            'attempted' => $attempted,
            'succeeded' => $succeeded,
            'failed' => count($errors),
            'skipped' => $skipped,
            'limited' => $skipped > 0,
            'errors' => $errors,
        ];
    }

    private function http(string $accept): PendingRequest
    {
        return Http::accept($accept)
            ->withHeaders(['X-Seasonvar-Cache-Warm' => '1'])
            ->withUserAgent('Seasonvar-Cache-Warmer/1.0')
            ->connectTimeout(max(1, (int) config('cache-architecture.page_cache.warm_connect_timeout_seconds', 2)))
            ->timeout(max(1, (int) config('cache-architecture.page_cache.warm_timeout_seconds', 10)))
            ->withOptions(['allow_redirects' => false])
            ->retry(
                max(1, (int) config('cache-architecture.page_cache.warm_retry_times', 2)),
                max(0, (int) config('cache-architecture.page_cache.warm_retry_milliseconds', 100)),
                throw: false,
            );
    }

    /** @return array{fingerprint: string, status: int|null, exception: string|null} */
    private function error(string $relativeUrl, ?int $status, ?Throwable $exception): array
    {
        return [
            'fingerprint' => hash('sha256', $relativeUrl),
            'status' => $status,
            'exception' => $exception !== null ? $exception::class : null,
        ];
    }

    private function validRelativeUrl(string $url): bool
    {
        return str_starts_with($url, '/')
            && ! str_starts_with($url, '//')
            && ! str_contains($url, "\n")
            && ! str_contains($url, "\r");
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
