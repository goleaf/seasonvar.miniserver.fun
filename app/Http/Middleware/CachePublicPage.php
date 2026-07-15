<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Catalog\PublicPageCacheManifest;
use App\Support\Cache\CacheRebuildTimeout;
use App\Support\Cache\CacheTtlPolicy;
use App\Support\Cache\PublicPageCachePolicy;
use App\Support\Cache\PublicPageHtmlTransformer;
use App\Support\Cache\TieredCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CachePublicPage
{
    private const HEADER = 'X-Seasonvar-Page-Cache';

    public function __construct(
        private readonly PublicPageCachePolicy $policy,
        private readonly PublicPageHtmlTransformer $html,
        private readonly TieredCache $cache,
        private readonly CacheTtlPolicy $ttl,
        private readonly PublicPageCacheManifest $manifest,
    ) {}

    public function handle(Request $request, Closure $next, string $profile): Response
    {
        $context = $this->policy->context($request, $profile);

        if ($context === null || ! $request->acceptsHtml()) {
            return $this->withStatus($next($request), 'BYPASS');
        }

        $uncachedResponse = null;

        try {
            $result = $this->cache->remember(
                $context->domain,
                'response-html',
                $context->dimensions,
                $this->ttl->for($context->domain),
                function () use ($next, $request, &$uncachedResponse): ?array {
                    $uncachedResponse = $next($request);

                    if (! $this->isCacheable($uncachedResponse)) {
                        return null;
                    }

                    $body = $this->html->sanitize((string) $uncachedResponse->getContent());

                    if ($body === null
                        || strlen($body) > max(1, (int) config('cache-architecture.page_cache.max_payload_bytes', 850_000))) {
                        return null;
                    }

                    return [
                        'body' => $body,
                        'content_type' => (string) $uncachedResponse->headers->get('Content-Type', 'text/html; charset=UTF-8'),
                    ];
                },
                versionScope: $context->versionScope,
            );
        } catch (CacheRebuildTimeout) {
            return $this->withStatus($uncachedResponse ?? $next($request), 'BYPASS');
        }

        if ($result->source === 'rebuild' && $uncachedResponse instanceof Response) {
            if ($result->value !== null) {
                $this->manifest->record($request->getRequestUri());
            }

            return $this->withStatus($uncachedResponse, $result->value === null ? 'BYPASS' : 'MISS');
        }

        if (! $this->isCachedPayload($result->value)) {
            return $this->withStatus($next($request), 'BYPASS');
        }

        $response = response(
            $this->html->restore($result->value['body']),
            Response::HTTP_OK,
            ['Content-Type' => $result->value['content_type']],
        );

        return $this->withStatus($response, $result->stale ? 'STALE' : 'HIT');
    }

    private function isCacheable(Response $response): bool
    {
        $contentType = (string) $response->headers->get('Content-Type');

        return $response->getStatusCode() === Response::HTTP_OK
            && ! $response->headers->has('Set-Cookie')
            && str_starts_with(strtolower($contentType), 'text/html');
    }

    private function isCachedPayload(mixed $payload): bool
    {
        return is_array($payload)
            && is_string($payload['body'] ?? null)
            && is_string($payload['content_type'] ?? null)
            && str_starts_with(strtolower($payload['content_type']), 'text/html');
    }

    private function withStatus(Response $response, string $status): Response
    {
        $response->headers->set(self::HEADER, $status);

        return $response;
    }
}
