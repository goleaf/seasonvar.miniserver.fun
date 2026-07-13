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

    public function __construct(
        private readonly CatalogStatsPosterUrlGuard $posterUrls,
    ) {}

    public function response(CatalogTitle $catalogTitle): Response
    {
        $target = $this->posterUrls->verifiedUrl($catalogTitle->poster_url);

        if ($target === null) {
            abort(404);
        }

        try {
            $remote = Http::timeout(4)
                ->connectTimeout(2)
                ->withoutRedirecting()
                ->withOptions($target->httpOptions())
                ->accept('image/*')
                ->get($target->url);
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
        if (is_string($contentLengthHeader) && trim($contentLengthHeader) !== '') {
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
}
