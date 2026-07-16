<?php

declare(strict_types=1);

namespace App\Services\Localization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class LocalizedRouteResolver
{
    private const TRANSIENT_QUERY_KEYS = [
        '_token',
        'calls',
        'components',
        'snapshot',
        'updates',
    ];

    public function __construct(
        private readonly Router $router,
        private readonly UrlGenerator $urls,
    ) {}

    public function targetFor(Request $request, string $locale): string
    {
        if (! $this->supported($locale)) {
            return $this->localizedHome((string) config('app.fallback_locale', 'ru'));
        }

        $route = $request->route();
        $routeName = $route->getName();

        if (is_string($routeName) && $routeName !== '') {
            $localizedName = $routeName === 'home'
                ? 'localized.home'
                : (Str::startsWith($routeName, 'localized.') ? $routeName : 'localized.'.$routeName);

            if ($this->router->has($localizedName)) {
                $parameters = collect($route->parameters())
                    ->map(fn (mixed $value): mixed => $value instanceof Model ? $value->getRouteKey() : $value)
                    ->all();
                $parameters['locale'] = $locale;

                return $this->relativeUrl($this->urls->route($localizedName, $parameters)).$this->safeQuery($request);
            }
        }

        return $this->safePath($request->getPathInfo().$this->safeQuery($request), $locale);
    }

    public function homeFor(string $locale): string
    {
        return $this->localizedHome($locale);
    }

    public function safePath(string $candidate, string $locale): string
    {
        $fallback = $this->localizedHome($locale);
        $candidate = trim($candidate);

        if ($candidate === ''
            || ! Str::startsWith($candidate, '/')
            || Str::startsWith($candidate, '//')
            || str_contains($candidate, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $candidate) === 1) {
            return $fallback;
        }

        $parts = parse_url($candidate);

        if ($parts === false
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return $fallback;
        }

        $path = (string) ($parts['path'] ?? '/');
        $decodedPath = rawurldecode(rawurldecode($path));

        if (! Str::startsWith($decodedPath, '/')
            || str_contains($decodedPath, '//')
            || collect(explode('/', $decodedPath))->contains(fn (string $segment): bool => in_array($segment, ['.', '..'], true))
            || Str::startsWith($decodedPath, ['/api/', '/livewire-', '/logout'])) {
            return $fallback;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $query = Arr::except($query, self::TRANSIENT_QUERY_KEYS);
        $encodedQuery = $query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $path.$encodedQuery;
    }

    private function safeQuery(Request $request): string
    {
        $query = Arr::except($request->query(), self::TRANSIENT_QUERY_KEYS);

        return $query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function relativeUrl(string $url): string
    {
        $parts = parse_url($url);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '/') : '/';

        return Str::startsWith($path, '/') ? $path : '/'.$path;
    }

    private function localizedHome(string $locale): string
    {
        $locale = $this->supported($locale)
            ? $locale
            : (string) config('catalog-collections.default_locale', 'ru');

        return $this->relativeUrl($this->urls->route('localized.home', ['locale' => $locale]));
    }

    private function supported(string $locale): bool
    {
        return in_array($locale, (array) config('catalog-collections.supported_locales', []), true);
    }
}
