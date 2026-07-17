<?php

declare(strict_types=1);

namespace App\Services\Collections\Import;

use InvalidArgumentException;

final class HdRezkaCollectionUrlGuard
{
    public const string PURPOSE_INDEX = 'index';

    public const string PURPOSE_COLLECTION = 'collection';

    public const string PURPOSE_COVER = 'cover';

    public const string PURPOSE_DETAIL = 'detail';

    private const string BASE_URL = 'https://hdrezka.my';

    public function absolute(string $urlOrPath, string $purpose): string
    {
        $urlOrPath = trim($urlOrPath);

        if ($urlOrPath === '' || ! mb_check_encoding($urlOrPath, 'UTF-8') || str_starts_with($urlOrPath, '//')) {
            throw new InvalidArgumentException('Некорректный URL источника коллекций.');
        }

        $absolute = str_starts_with($urlOrPath, '/') ? self::BASE_URL.$urlOrPath : $urlOrPath;
        $parts = parse_url($absolute);

        if (! is_array($parts)
            || mb_strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || mb_strtolower((string) ($parts['host'] ?? '')) !== 'hdrezka.my'
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('URL источника коллекций находится вне разрешённой границы.');
        }

        $path = (string) ($parts['path'] ?? '');
        $this->assertSafePath($path);

        $decodedPath = rawurldecode($path);
        $allowed = match ($purpose) {
            self::PURPOSE_INDEX => $decodedPath === '/collections.html',
            self::PURPOSE_COLLECTION => preg_match(
                '~^/xfsearch/collections/[\p{L}\p{M}\p{N} +()&.,:;!_-]+/(?:page/[1-9][0-9]*/)?$~u',
                $decodedPath,
            ) === 1,
            self::PURPOSE_COVER => preg_match(
                '~^/uploads/mini/(?:[a-z0-9_-]+/){1,4}[a-z0-9._-]+\.(?:jpe?g|png|webp)$~i',
                $decodedPath,
            ) === 1,
            self::PURPOSE_DETAIL => preg_match(
                '~^/[1-9][0-9]*-(?:[\p{L}\p{N}][\p{L}\p{N}-]*)?\.html$~iu',
                $decodedPath,
            ) === 1,
            default => false,
        };

        if (! $allowed) {
            throw new InvalidArgumentException('Путь не разрешён для указанного типа ресурса.');
        }

        return self::BASE_URL.$path;
    }

    private function assertSafePath(string $path): void
    {
        if ($path === ''
            || preg_match('/%(?![a-f0-9]{2})/i', $path) === 1
            || preg_match('/%(?:2f|5c)/i', $path) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidArgumentException('Некорректный путь источника коллекций.');
        }

        $decodedPath = rawurldecode($path);

        if (! mb_check_encoding($decodedPath, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/', $decodedPath) === 1
            || str_contains($decodedPath, '%')
            || str_contains($decodedPath, '\\')) {
            throw new InvalidArgumentException('Некорректная кодировка пути источника коллекций.');
        }

        foreach (explode('/', $decodedPath) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Переход между каталогами запрещён.');
            }
        }
    }
}
