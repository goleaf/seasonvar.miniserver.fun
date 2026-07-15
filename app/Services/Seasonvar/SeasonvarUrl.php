<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\SeasonvarPageType;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SeasonvarUrl
{
    /**
     * @var list<string>
     */
    private const ALLOWED_HOSTS = [
        'seasonvar.ru',
        'www.seasonvar.ru',
    ];

    /**
     * @var list<string>
     */
    private const BLOCKED_PATH_TOKENS = [
        'player',
        'playlist',
        'video',
        'cdn',
        'm3u8',
        'mp4',
        'list.xml',
    ];

    /**
     * Query parameters confirmed as stable source-page identity dimensions.
     * Tracking, credentials, player tokens and arbitrary search text are discarded.
     *
     * @var list<string>
     */
    private const IDENTITY_QUERY_PARAMETERS = [
        'mod',
        'mode',
        'page',
        'time',
    ];

    public function __construct(private readonly SeasonvarSource $source) {}

    public function normalize(string $url, ?string $baseUrl = null): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new InvalidArgumentException('Ссылка не может быть пустой.');
        }

        if (Str::startsWith($url, '//')) {
            $url = 'https:'.$url;
        }

        if (preg_match('~^https?://~i', $url) !== 1) {
            if ($baseUrl === null) {
                throw new InvalidArgumentException('Для относительной ссылки нужна базовая ссылка.');
            }

            $baseParts = parse_url($baseUrl);

            if (! is_array($baseParts) || ! isset($baseParts['scheme'], $baseParts['host'])) {
                throw new InvalidArgumentException('Некорректная базовая ссылка.');
            }

            $origin = strtolower($baseParts['scheme']).'://'.$this->normalizeHost((string) $baseParts['host']);

            if (isset($baseParts['port'])) {
                $origin .= ':'.$baseParts['port'];
            }

            if (Str::startsWith($url, '/')) {
                $url = $origin.$url;
            } else {
                $basePath = $baseParts['path'] ?? '/';
                $baseDirectory = Str::beforeLast($basePath, '/');
                $url = $origin.'/'.trim($baseDirectory.'/'.$url, '/');
            }
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Некорректная ссылка.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Ссылка не должна содержать учетные данные.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = $this->normalizeHost((string) $parts['host']);
        $port = isset($parts['port']) && ! ($scheme === 'https' && (int) $parts['port'] === 443)
            ? ':'.(int) $parts['port']
            : '';
        $path = $this->normalizePath($parts['path'] ?? '/');
        $query = $this->normalizeQuery($parts['query'] ?? null);

        return $scheme.'://'.$host.$port.$path.$query;
    }

    public function isAllowed(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $path = strtolower($parts['path'] ?? '');

        $port = isset($parts['port']) ? (int) $parts['port'] : 443;

        if ($scheme !== 'https' || ! in_array($host, self::ALLOWED_HOSTS, true) || $port !== 443) {
            return false;
        }

        foreach (self::BLOCKED_PATH_TOKENS as $token) {
            if (str_contains($path, $token)) {
                return false;
            }
        }

        if ($this->isMalformedCatalogUrl($url)) {
            return false;
        }

        return true;
    }

    public function isMalformedCatalogUrl(string $url): bool
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?: '');

        return str_contains($path, '.html/');
    }

    public function pageType(string $url): SeasonvarPageType
    {
        $path = Str::lower(rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: '/')));
        $path = '/'.ltrim(rtrim($path, '/'), '/');

        return match (true) {
            preg_match('~^/rss(?:[._/-]|$)~u', $path) === 1 => SeasonvarPageType::Rss,
            preg_match('~\.xml(?:\.gz)?$~u', $path) === 1 => SeasonvarPageType::Sitemap,
            preg_match('~^/serial(?:-|/|$)~u', $path) === 1 => SeasonvarPageType::Serial,
            preg_match('~^/actor(?:/|$)~u', $path) === 1 => SeasonvarPageType::Actor,
            preg_match('~^/director(?:/|$)~u', $path) === 1 => SeasonvarPageType::Director,
            preg_match('~^/genre(?:/|$)~u', $path) === 1 => SeasonvarPageType::Genre,
            preg_match('~^/country(?:/|$)~u', $path) === 1 => SeasonvarPageType::Country,
            preg_match('~^/tag(?:/|$)~u', $path) === 1 => SeasonvarPageType::Tag,
            preg_match('~^/translation(?:/|$)~u', $path) === 1 => SeasonvarPageType::Translation,
            preg_match('~^/status(?:/|$)~u', $path) === 1 => SeasonvarPageType::Status,
            preg_match('~^/network(?:/|$)~u', $path) === 1 => SeasonvarPageType::Network,
            preg_match('~^/studio(?:/|$)~u', $path) === 1 => SeasonvarPageType::Studio,
            $path === '/' || preg_match('~^/st(?:/|$)~u', $path) === 1 => SeasonvarPageType::StaticPage,
            preg_match('~^/search(?:[._/-]|$)~u', $path) === 1 => SeasonvarPageType::Search,
            default => SeasonvarPageType::Unknown,
        };
    }

    public function sanitizedPath(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_PATH) ?: '/');
    }

    public function externalSerialId(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        if (preg_match('/serial[-\/](\d+)/i', $path, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    public function hash(string $url): string
    {
        return hash('sha256', $url);
    }

    public function baseUrl(): string
    {
        return $this->source->baseUrl();
    }

    public function sitemapUrl(): string
    {
        return $this->source->sitemapUrl();
    }

    private function normalizeHost(string $host): string
    {
        $host = Str::lower(rtrim($host, '.'));

        return $host === 'www.seasonvar.ru' ? 'seasonvar.ru' : $host;
    }

    private function normalizePath(string $path): string
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1) {
            throw new InvalidArgumentException('Путь ссылки содержит некорректное кодирование.');
        }

        $path = preg_replace('~/+~', '/', '/'.ltrim($path, '/')) ?? '/';
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }

            $segment = $this->normalizePathSegment($segment);

            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    private function normalizePathSegment(string $segment): string
    {
        $encoded = rawurlencode(rawurldecode($segment));

        return str_ireplace(
            ['%21', '%24', '%26', '%27', '%28', '%29', '%2A', '%2B', '%2C', '%3B', '%3D', '%3A', '%40'],
            ['!', '$', '&', "'", '(', ')', '*', '+', ',', ';', '=', ':', '@'],
            $encoded,
        );
    }

    private function normalizeQuery(?string $query): string
    {
        if ($query === null || $query === '') {
            return '';
        }

        $identity = [];

        foreach (explode('&', $query) as $pair) {
            [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $key = Str::lower(rawurldecode($rawKey));
            $value = rawurldecode($rawValue);

            if (! in_array($key, self::IDENTITY_QUERY_PARAMETERS, true)
                || preg_match('/\A[\pL\pN_-]{1,64}\z/u', $value) !== 1) {
                continue;
            }

            $identity[$key] = $value;
        }

        if ($identity === []) {
            return '';
        }

        ksort($identity);

        return '?'.http_build_query($identity, '', '&', PHP_QUERY_RFC3986);
    }
}
