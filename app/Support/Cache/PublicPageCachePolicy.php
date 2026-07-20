<?php

declare(strict_types=1);

namespace App\Support\Cache;

use App\Enums\CatalogRecommendationType;
use App\Livewire\Forms\CatalogSeriesFilters;
use App\Models\CatalogTitle;
use App\Models\ContentRequest;
use App\Services\ReleaseCalendar\ReleaseCalendarTimezone;
use BackedEnum;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;

final class PublicPageCachePolicy
{
    private ?string $assetBuildFingerprint = null;

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

    private const CONTENT_REQUEST_QUERY_KEYS = [
        'requestsPage',
        'type',
        'status',
        'sort',
    ];

    private const HOMEPAGE_TRANSLATION_GROUPS = [
        'auth',
        'catalog',
        'collections',
        'home',
        'recommendations',
        'requests',
        'tags',
    ];

    public function __construct(
        private readonly CacheVersionRegistry $versions,
        private readonly Translator $translator,
        private readonly ReleaseCalendarTimezone $releaseCalendarTimezone,
        private readonly Vite $vite,
    ) {}

    public function context(Request $request, string $profile): ?PublicPageCacheContext
    {
        $canonicalOrigin = $this->canonicalOrigin();

        if ($canonicalOrigin === null
            || ! hash_equals($canonicalOrigin, strtolower($request->getSchemeAndHttpHost()))
            || ! (bool) config('cache-architecture.page_cache.enabled', true)
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
        $dimensions = $this->dimensions($canonicalOrigin, $routeName, $parameters, $query);

        if ($profile === 'homepage') {
            $dimensions['translations'] = $this->homepageTranslationFingerprint();
        }

        if ($profile === 'calendar') {
            $dimensions['timezone'] = $this->releaseCalendarTimezone->public();
        }

        if ($profile === 'catalog') {
            $dimensions['response_contract'] = 2;
        }

        return match ($profile) {
            'homepage' => $this->homepageParametersAreValid($parameters) && $query === []
                ? new PublicPageCacheContext(CacheDomain::Homepage, $dimensions)
                : null,
            'catalog' => new PublicPageCacheContext(CacheDomain::CatalogPages, $dimensions),
            'stats' => $parameters === [] && $query === []
                ? new PublicPageCacheContext(CacheDomain::CatalogStats, $dimensions)
                : null,
            'title' => $this->titleContext($request, $dimensions),
            'requests' => $this->contentRequestContext($request, $dimensions),
            'discovery' => $this->discoveryContext($request, $dimensions),
            'calendar' => new PublicPageCacheContext(CacheDomain::ReleaseCalendar, $dimensions),
            default => null,
        };
    }

    public function canonicalTitleContext(CatalogTitle $title): ?PublicPageCacheContext
    {
        $canonicalOrigin = $this->canonicalOrigin();
        $titleId = $title->getKey();
        $slug = $title->getRouteKey();

        if ($canonicalOrigin === null
            || ! (bool) config('cache-architecture.page_cache.enabled', true)
            || ! is_int($titleId)
            || ! is_string($slug)
            || $slug === '') {
            return null;
        }

        return $this->titleContextFor($title, $this->dimensions(
            $canonicalOrigin,
            'titles.show',
            ['catalogTitle' => $slug],
            [],
            (string) config('cache-architecture.page_cache.canonical_locale', 'ru'),
        ));
    }

    /**
     * @param  array<string, scalar|null>  $parameters
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function dimensions(
        string $origin,
        string $route,
        array $parameters,
        array $query,
        ?string $locale = null,
    ): array {
        return [
            'audience' => 'public',
            'assets' => $this->assetBuildFingerprint(),
            'origin' => $origin,
            'locale' => $locale ?? app()->getLocale(),
            'route' => $route,
            'parameters' => $parameters,
            'query' => hash('sha256', json_encode($query, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        ];
    }

    /** @param array<string, scalar|null> $parameters */
    private function homepageParametersAreValid(array $parameters): bool
    {
        if ($parameters === []) {
            return true;
        }

        return array_keys($parameters) === ['locale']
            && is_string($parameters['locale'])
            && in_array($parameters['locale'], (array) config('catalog-collections.supported_locales', []), true);
    }

    /** @param array<string, mixed> $dimensions */
    private function titleContext(Request $request, array $dimensions): ?PublicPageCacheContext
    {
        $title = $request->route('catalogTitle');

        if (! $title instanceof CatalogTitle || ! is_int($title->getKey())) {
            return null;
        }

        return $this->titleContextFor($title, $dimensions);
    }

    /** @param array<string, mixed> $dimensions */
    private function titleContextFor(CatalogTitle $title, array $dimensions): PublicPageCacheContext
    {
        $dimensions['parameters']['catalogTitle'] = $title->getKey();
        $dimensions['global_title_version'] = $this->versions->version(CacheDomain::TitleDetail);

        return new PublicPageCacheContext(
            CacheDomain::TitleDetail,
            $dimensions,
            'title:'.$title->getKey(),
        );
    }

    /** @param array<string, mixed> $dimensions */
    private function contentRequestContext(Request $request, array $dimensions): PublicPageCacheContext
    {
        $contentRequest = $request->route('contentRequest');

        if (! $contentRequest instanceof ContentRequest) {
            return new PublicPageCacheContext(CacheDomain::ContentRequests, $dimensions);
        }

        $dimensions['global_request_version'] = $this->versions->version(CacheDomain::ContentRequests);

        return new PublicPageCacheContext(
            CacheDomain::ContentRequests,
            $dimensions,
            'request:'.$contentRequest->public_id,
        );
    }

    /** @param array<string, mixed> $dimensions */
    private function discoveryContext(Request $request, array $dimensions): ?PublicPageCacheContext
    {
        $type = $request->route('type');
        $recommendationType = is_string($type) ? CatalogRecommendationType::tryFrom($type) : null;

        return $recommendationType?->isIndexable() === true
            ? new PublicPageCacheContext(CacheDomain::CatalogPages, $dimensions)
            : null;
    }

    /** @return array<string, mixed>|null */
    private function query(Request $request, string $profile): ?array
    {
        if (mb_strlen((string) $request->server('QUERY_STRING', '')) > max(1, (int) config('cache-architecture.page_cache.max_query_length', 2_048))) {
            return null;
        }

        $query = $request->query();

        if (array_key_exists('q', $query)
            || array_key_exists('collections_q', $query)
            || ($profile !== 'calendar' && array_key_exists('title', $query))) {
            return null;
        }

        $allowed = match ($profile) {
            'homepage', 'stats' => [],
            'catalog' => array_values(array_unique([
                ...self::CATALOG_QUERY_KEYS,
                ...array_keys(CatalogSeriesFilters::TAXONOMY_PROPERTIES),
            ])),
            'title' => self::TITLE_QUERY_KEYS,
            'requests' => self::CONTENT_REQUEST_QUERY_KEYS,
            'discovery' => ['page', 'collectionsPage', 'collections_sort'],
            'calendar' => ['calendarPage', 'type', 'status', 'sort', 'title'],
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

                if ($value instanceof BackedEnum) {
                    return $value->value;
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

    private function homepageTranslationFingerprint(): string
    {
        $locales = collect([
            app()->getLocale(),
            (string) config('app.fallback_locale', 'ru'),
        ])->filter(fn (string $locale): bool => $locale !== '')
            ->unique()
            ->values();
        $catalogs = [];

        foreach ($locales as $locale) {
            foreach (self::HOMEPAGE_TRANSLATION_GROUPS as $group) {
                $catalogs[$locale][$group] = $this->translator->get($group, [], $locale);
            }
        }

        return hash('sha256', json_encode($catalogs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function assetBuildFingerprint(): string
    {
        return $this->assetBuildFingerprint ??= $this->vite->manifestHash() ?? 'manifest-unavailable';
    }

    private function canonicalOrigin(): ?string
    {
        $parts = parse_url(trim((string) config('app.url')));

        if (! is_array($parts)
            || ! is_string($parts['scheme'] ?? null)
            || ! is_string($parts['host'] ?? null)) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;

        if (! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || ($port !== null && $port < 1)) {
            return null;
        }

        $portSuffix = $port !== null
            && ! (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))
                ? ':'.$port
                : '';

        return $scheme.'://'.$host.$portSuffix;
    }
}
