<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheKeyFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class PublicPageCacheManifest
{
    public function __construct(
        private readonly CacheKeyFactory $keys,
        private readonly Router $router,
    ) {}

    public function record(string $relativeUrl): void
    {
        $relativeUrl = $this->normalize($relativeUrl);

        if ($relativeUrl === null) {
            return;
        }

        $lock = null;

        try {
            $store = Cache::store($this->store());
            $lock = $store->lock($this->keys->lock($this->key()), 5);

            if (! $lock->get()) {
                return;
            }

            $entries = $store->get($this->key(), []);
            $entries = is_array($entries) ? $entries : [];
            $latest = collect($entries)
                ->filter(fn (mixed $seenAt, mixed $url): bool => is_string($url) && is_int($seenAt))
                ->map(fn (int $seenAt): int => $seenAt)
                ->all();
            $seenAt = max((int) floor(microtime(true) * 1_000_000), ((int) max($latest ?: [0])) + 1);
            $latest[$relativeUrl] = $seenAt;
            arsort($latest, SORT_NUMERIC);
            $latest = array_slice($latest, 0, $this->manifestLimit(), true);

            $store->put(
                $this->key(),
                $latest,
                max(60, (int) config('cache-architecture.page_cache.manifest_retention_seconds', 2_592_000)),
            );
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            try {
                $lock?->release();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /** @return list<string> */
    public function recent(int $limit): array
    {
        try {
            $entries = Cache::store($this->store())->get($this->key(), []);

            if (! is_array($entries)) {
                return [];
            }

            $entries = collect($entries)
                ->filter(fn (mixed $seenAt, mixed $url): bool => is_string($url) && is_int($seenAt))
                ->map(fn (int $seenAt): int => $seenAt)
                ->all();
            arsort($entries, SORT_NUMERIC);

            return array_slice(
                array_keys($entries),
                0,
                max(0, min($limit, $this->manifestLimit())),
            );
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    private function normalize(string $relativeUrl): ?string
    {
        if ($relativeUrl === ''
            || strlen($relativeUrl) > max(1, (int) config('cache-architecture.page_cache.max_manifest_url_length', 2_048))
            || preg_match('/[\x00-\x1F\x7F]/', $relativeUrl) === 1) {
            return null;
        }

        $parts = parse_url($relativeUrl);

        if (! is_array($parts)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return null;
        }

        $path = $parts['path'] ?? null;

        if (! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);

        if (array_intersect(['q', 'title'], array_keys($query)) !== []) {
            return null;
        }

        $request = Request::create($path, 'GET', $query);

        try {
            $route = $this->router->getRoutes()->match($request);
            $middleware = collect($route->gatherMiddleware())
                ->first(fn (string $name): bool => str_starts_with($name, 'public.page:'));
        } catch (Throwable) {
            return null;
        }

        if (! is_string($middleware)) {
            return null;
        }

        $profile = substr($middleware, strlen('public.page:'));

        if (in_array($profile, ['homepage', 'stats'], true) && $query !== []) {
            return null;
        }

        $query = $this->canonicalize($query);
        $queryString = $query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $path.$queryString;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);

        if (array_is_list($value)) {
            sort($value);
        } else {
            ksort($value);
        }

        return $value;
    }

    private function manifestLimit(): int
    {
        return max(1, (int) config('cache-architecture.page_cache.manifest_limit', 250));
    }

    private function key(): string
    {
        return $this->keys->data(CacheDomain::Operational, 'public-page-manifest', [], 1);
    }

    private function store(): string
    {
        return (string) config('cache-architecture.stores.locks', 'redis-locks');
    }
}
