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
        'seasonvar.net',
        'www.seasonvar.net',
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

    public function normalize(string $url, ?string $baseUrl = null): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new InvalidArgumentException('URL cannot be empty.');
        }

        if (Str::startsWith($url, '//')) {
            $url = 'https:'.$url;
        }

        if (! Str::startsWith($url, ['http://', 'https://'])) {
            if ($baseUrl === null) {
                throw new InvalidArgumentException('Relative URL requires a base URL.');
            }

            $url = rtrim($baseUrl, '/').'/'.ltrim($url, '/');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Invalid URL.');
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

        if (! is_array($parts) || ! isset($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        $path = strtolower($parts['path'] ?? '');

        if (! in_array($host, self::ALLOWED_HOSTS, true)) {
            return false;
        }

        foreach (self::BLOCKED_PATH_TOKENS as $token) {
            if (str_contains($path, $token)) {
                return false;
            }
        }

        return true;
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
}
