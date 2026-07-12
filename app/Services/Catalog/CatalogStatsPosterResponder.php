<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CatalogStatsPosterResponder
{
    /**
     * @var list<string>
     */
    private const IMAGE_CONTENT_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    private const MAX_IMAGE_BYTES = 5_242_880;

    public function response(CatalogTitle $catalogTitle): Response
    {
        $url = $this->safePosterUrl($catalogTitle->poster_url);

        if ($url === null) {
            abort(404);
        }

        try {
            $remote = Http::timeout(4)
                ->connectTimeout(2)
                ->withoutRedirecting()
                ->accept('image/*')
                ->get($url);
        } catch (Throwable) {
            abort(404);
        }

        if (! $remote->successful()) {
            abort(404);
        }

        $contentType = Str::lower(Str::before((string) $remote->header('Content-Type'), ';'));

        if (! in_array($contentType, self::IMAGE_CONTENT_TYPES, true)) {
            abort(404);
        }

        $contentLengthHeader = $remote->header('Content-Length');
        if (is_string($contentLengthHeader)) {
            $contentLength = (int) trim($contentLengthHeader);
            if ($contentLength === 0 || $contentLength > self::MAX_IMAGE_BYTES) {
                abort(404);
            }
        }

        $body = $remote->body();

        if ($body === '' || strlen($body) > self::MAX_IMAGE_BYTES) {
            abort(404);
        }

        return response($body, 200)
            ->header('Content-Type', $contentType)
            ->header('Cache-Control', 'private, max-age=3600')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function safePosterUrl(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        $host = Str::lower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '' || $this->blockedHost($host)) {
            return null;
        }

        return $url;
    }

    private function blockedHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
