<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\Store;
use Illuminate\Support\Str;

final class AuthenticationRedirectService
{
    /** @var list<string> */
    private const FORBIDDEN_PATH_PREFIXES = [
        '/api/',
        '/confirm-password',
        '/email/verify',
        '/forgot-password',
        '/livewire/',
        '/login',
        '/register',
        '/reset-password',
    ];

    public function __construct(
        private readonly Router $router,
        private readonly Store $session,
        private readonly UrlGenerator $urls,
    ) {}

    /** @param array<string, scalar> $parameters */
    public function guestUrl(string $canonicalRoute, array $parameters = [], ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $localizedRoute = 'localized.'.$canonicalRoute;

        if ($this->supportedLocale($locale) && $this->router->has($localizedRoute)) {
            return $this->urls->route($localizedRoute, ['locale' => $locale, ...$parameters]);
        }

        return $this->urls->route($canonicalRoute, $parameters);
    }

    public function intended(string $fallbackRoute = 'library.index'): string
    {
        $candidate = $this->session->pull('url.intended');

        if (! is_string($candidate)) {
            return $this->urls->route($fallbackRoute);
        }

        return $this->safeInternalPath($candidate) ?? $this->urls->route($fallbackRoute);
    }

    private function safeInternalPath(string $candidate): ?string
    {
        $candidate = trim($candidate);

        if ($candidate === '' || str_contains($candidate, '\\') || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            return null;
        }

        $decoded = $candidate;

        for ($i = 0; $i < 2; $i++) {
            $decoded = rawurldecode($decoded);

            if (str_contains($decoded, '\\') || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1) {
                return null;
            }
        }

        if (Str::startsWith($decoded, '//')) {
            return null;
        }

        $parts = parse_url($candidate);

        if ($parts === false || isset($parts['user'], $parts['pass'], $parts['fragment'])) {
            return null;
        }

        if (isset($parts['scheme']) || isset($parts['host']) || isset($parts['port'])) {
            $application = parse_url((string) config('app.url'));

            if ($application === false
                || ! isset($parts['scheme'], $parts['host'])
                || ! isset($application['scheme'], $application['host'])
                || ! hash_equals(Str::lower((string) $application['scheme']), Str::lower((string) $parts['scheme']))
                || ! hash_equals(Str::lower((string) $application['host']), Str::lower((string) $parts['host']))
                || (int) ($parts['port'] ?? $this->defaultPort((string) $parts['scheme']))
                    !== (int) ($application['port'] ?? $this->defaultPort((string) $application['scheme']))) {
                return null;
            }
        }

        $path = (string) ($parts['path'] ?? '/');

        if (! Str::startsWith($path, '/') || Str::startsWith($path, self::FORBIDDEN_PATH_PREFIXES)) {
            return null;
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $path.$query;
    }

    private function supportedLocale(string $locale): bool
    {
        return in_array($locale, (array) config('catalog-collections.supported_locales', []), true);
    }

    private function defaultPort(string $scheme): int
    {
        return Str::lower($scheme) === 'https' ? 443 : 80;
    }
}
