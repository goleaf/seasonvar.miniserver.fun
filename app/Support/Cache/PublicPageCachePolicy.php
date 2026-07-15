<?php

declare(strict_types=1);

namespace App\Support\Cache;

use App\Livewire\Forms\CatalogSeriesFilters;
use App\Models\CatalogTitle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class PublicPageCachePolicy
{
    private const CATALOG_QUERY_KEYS = [
        'page',
        'year',
        'exclude_country',
        'exclude_genre',
        'quality',
        'publication_type',
        'subtitles',
        'year_from',
        'year_to',
        'seasons_min',
        'seasons_max',
        'episodes_min',
        'episodes_max',
        'rating_source',
        'rating_min',
        'votes_min',
        'video',
        'updated',
        'letter',
        'sort',
        'per_page',
        'decade',
    ];

    private const TITLE_QUERY_KEYS = [
        'season',
        'episode',
        'media',
        'variant',
        'quality',
        'format',
    ];

    private const COLLECTION_QUERY_KEYS = [
        'page',
        'collectionsPage',
        'profileCollectionsPage',
        'sort',
    ];

    public function __construct(private readonly CacheVersionRegistry $versions) {}

    public function context(Request $request, string $profile): ?PublicPageCacheContext
    {
        if (! (bool) config('cache-architecture.page_cache.enabled', true)
            || ! $request->isMethod('GET')
            || $request->headers->has('Authorization')
            || $request->headers->has('X-Livewire')
            || $this->hasTransientSessionState($request)
            || $request->user() !== null) {
            return null;
        }

        $routeName = $request->route()?->getName();

        if (! is_string($routeName) || $routeName === '') {
            return null;
        }

        $query = $this->query($request, $profile);

        if ($query === null) {
            return null;
        }

        $parameters = $this->parameters($request);
        $dimensions = [
            'audience' => 'public',
            'locale' => app()->getLocale(),
            'route' => $routeName,
            'parameters' => $parameters,
            'query' => hash('sha256', json_encode($query, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        ];

        return match ($profile) {
            'homepage' => $parameters === [] && $query === []
                ? new PublicPageCacheContext(CacheDomain::Homepage, $dimensions)
                : null,
            'catalog' => new PublicPageCacheContext(CacheDomain::CatalogPages, $dimensions),
            'stats' => $parameters === [] && $query === []
                ? new PublicPageCacheContext(CacheDomain::CatalogStats, $dimensions)
                : null,
            'title' => $this->titleContext($request, $dimensions),
            'collections' => new PublicPageCacheContext(CacheDomain::Collections, $dimensions),
            default => null,
        };
    }

    /** @param array<string, mixed> $dimensions */
    private function titleContext(Request $request, array $dimensions): ?PublicPageCacheContext
    {
        $title = $request->route('catalogTitle');

        if (! $title instanceof CatalogTitle || ! is_int($title->getKey())) {
            return null;
        }

        $dimensions['global_title_version'] = $this->versions->version(CacheDomain::TitleDetail);

        return new PublicPageCacheContext(
            CacheDomain::TitleDetail,
            $dimensions,
            'title:'.$title->getKey(),
        );
    }

    /** @return array<string, mixed>|null */
    private function query(Request $request, string $profile): ?array
    {
        if (mb_strlen((string) $request->server('QUERY_STRING', '')) > max(1, (int) config('cache-architecture.page_cache.max_query_length', 2_048))) {
            return null;
        }

        $query = $request->query();

        if (array_intersect(['q', 'title'], array_keys($query)) !== []) {
            return null;
        }

        $allowed = match ($profile) {
            'homepage', 'stats' => [],
            'catalog' => array_values(array_unique([
                ...self::CATALOG_QUERY_KEYS,
                ...array_keys(CatalogSeriesFilters::TAXONOMY_PROPERTIES),
            ])),
            'title' => self::TITLE_QUERY_KEYS,
            'collections' => self::COLLECTION_QUERY_KEYS,
            default => null,
        };

        if ($allowed === null || array_diff(array_keys($query), $allowed) !== []) {
            return null;
        }

        if (count($query) > max(1, (int) config('cache-architecture.page_cache.max_query_fields', 24))) {
            return null;
        }

        $normalized = $this->normalize($query);

        return is_array($normalized) ? $normalized : null;
    }

    /** @return array<string, scalar|null> */
    private function parameters(Request $request): array
    {
        return collect($request->route()?->parameters() ?? [])
            ->map(function (mixed $value): mixed {
                if ($value instanceof Model) {
                    return $value->getRouteKey();
                }

                return is_scalar($value) || $value === null ? $value : null;
            })
            ->filter(fn (mixed $value): bool => $value !== null)
            ->sortKeys()
            ->all();
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (count($value) > max(1, (int) config('cache-architecture.page_cache.max_query_values', 24))) {
                return null;
            }

            $normalized = [];

            foreach ($value as $key => $item) {
                $item = $this->normalize($item);

                if ($item === null && $value[$key] !== null) {
                    return null;
                }

                $normalized[$key] = $item;
            }

            if (array_is_list($normalized)) {
                sort($normalized);

                return $normalized;
            }

            ksort($normalized);

            return $normalized;
        }

        if (! is_scalar($value) && $value !== null) {
            return null;
        }

        $normalized = is_string($value) ? trim($value) : $value;

        if (is_string($normalized)
            && mb_strlen($normalized) > max(1, (int) config('cache-architecture.page_cache.max_query_value_length', 160))) {
            return null;
        }

        return $normalized;
    }

    private function hasTransientSessionState(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $session = $request->session();
        $flash = $session->get('_flash', []);

        return $session->has('_old_input')
            || $session->has('errors')
            || $session->has('status')
            || (is_array($flash) && collect($flash)->flatten()->filter()->isNotEmpty());
    }
}
