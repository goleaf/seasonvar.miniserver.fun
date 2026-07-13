<?php

namespace App\Services\Seasonvar;

use Illuminate\Support\Str;
use InvalidArgumentException;

class SeasonvarUrl
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

        if (! Str::startsWith($url, ['http://', 'https://'])) {
            if ($baseUrl === null) {
                throw new InvalidArgumentException('Для относительной ссылки нужна базовая ссылка.');
            }

            $baseParts = parse_url($baseUrl);

            if (! is_array($baseParts) || ! isset($baseParts['scheme'], $baseParts['host'])) {
                throw new InvalidArgumentException('Некорректная базовая ссылка.');
            }

            $origin = strtolower($baseParts['scheme']).'://'.strtolower($baseParts['host']);

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

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$path.$query;
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

        if ($scheme !== 'https' || ! in_array($host, self::ALLOWED_HOSTS, true)) {
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

    public function pageType(string $url): string
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?: '');

        return match (true) {
            str_contains($path, 'sitemap') => 'sitemap',
            preg_match('/serial[-\/]/', $path) === 1 => 'serial',
            str_contains($path, '/actor') => 'actor',
            str_contains($path, '/genre') => 'genre',
            str_contains($path, '/country') => 'country',
            str_contains($path, '/tag') => 'tag',
            str_contains($path, '/st/') => 'static',
            str_contains($path, 'rss') => 'rss',
            str_contains($path, 'search') => 'search',
            default => 'unknown',
        };
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
}
