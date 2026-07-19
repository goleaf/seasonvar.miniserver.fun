<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Catalog\PublicPageCacheManifest;
use App\Services\Catalog\VisibleCatalogTitleCacheWarmScheduler;
use App\Support\Cache\CacheRebuildTimeout;
use App\Support\Cache\CacheTtlPolicy;
use App\Support\Cache\PublicPageCachePolicy;
use App\Support\Cache\PublicPageHtmlPayloadCodec;
use App\Support\Cache\PublicPageHtmlTransformer;
use App\Support\Cache\TieredCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class CachePublicPage
{
    private const HEADER = 'X-Seasonvar-Page-Cache';

    public function __construct(
        private readonly PublicPageCachePolicy $policy,
        private readonly PublicPageHtmlTransformer $html,
        private readonly PublicPageHtmlPayloadCodec $payloads,
        private readonly TieredCache $cache,
        private readonly CacheTtlPolicy $ttl,
        private readonly PublicPageCacheManifest $manifest,
        private readonly VisibleCatalogTitleCacheWarmScheduler $visibleTitleWarm,
    ) {}

    public function handle(Request $request, Closure $next, string $profile): Response
    {
        $context = $this->policy->context($request, $profile);

        if ($context === null || ! $request->acceptsHtml()) {
            $response = $next($request);
            $this->visibleTitleWarm->defer($this->visibleTitleWarm->captured($request));

            return $this->withStatus($response, 'BYPASS');
        }

        $uncachedResponse = null;

        try {
            $result = $this->cache->remember(
                $context->domain,
                'response-html',
                $context->dimensions,
                $this->ttl->for($context->domain),
                function () use ($next, $profile, $request, &$uncachedResponse): ?array {
                    $uncachedResponse = $next($request);

                    if (! $this->isCacheable($uncachedResponse, $request, $profile)) {
                        return null;
                    }

                    $body = $this->html->sanitize((string) $uncachedResponse->getContent());
                    $payload = $body !== null ? $this->payloads->encode($body) : null;

                    if ($payload === null) {
                        return null;
                    }

                    return [
                        ...$payload,
                        'content_type' => (string) $uncachedResponse->headers->get('Content-Type', 'text/html; charset=UTF-8'),
                        'visible_title_ids' => $this->visibleTitleWarm->captured($request),
                    ];
                },
                versionScope: $context->versionScope,
            );
        } catch (CacheRebuildTimeout) {
            $response = $uncachedResponse ?? $next($request);
            $this->visibleTitleWarm->defer($this->visibleTitleWarm->captured($request));

            return $this->withStatus($response, 'BYPASS');
        }

        $visibleTitleIds = is_array($result->value)
            ? ($result->value['visible_title_ids'] ?? [])
            : $this->visibleTitleWarm->captured($request);
        $this->visibleTitleWarm->defer(is_iterable($visibleTitleIds) ? $visibleTitleIds : []);

        if ($result->source === 'rebuild' && $uncachedResponse instanceof Response) {
            if ($result->value !== null) {
                $this->manifest->record($request->getRequestUri());
            }

            return $this->withStatus($uncachedResponse, $result->value === null ? 'BYPASS' : 'MISS');
        }

        if (! $this->isCachedPayload($result->value)
            || ($body = $this->payloads->decode($result->value)) === null) {
            return $this->withStatus($next($request), 'BYPASS');
        }

        $response = response(
            $this->html->restore($body),
            Response::HTTP_OK,
            ['Content-Type' => $result->value['content_type']],
        );

        return $this->withStatus($response, $result->stale ? 'STALE' : 'HIT');
    }

    private function isCacheable(Response $response, Request $request, string $profile): bool
    {
        $contentType = (string) $response->headers->get('Content-Type');

        return $response->getStatusCode() === Response::HTTP_OK
            && $this->policy->context($request, $profile) !== null
            && $this->hasOnlyPublicSessionCookies($response)
            && str_starts_with(strtolower($contentType), 'text/html');
    }

    private function hasOnlyPublicSessionCookies(Response $response): bool
    {
        $allowed = array_filter([
            'XSRF-TOKEN',
            config('session.cookie'),
        ], is_string(...));

        return collect($response->headers->getCookies())
            ->every(fn (Cookie $cookie): bool => in_array($cookie->getName(), $allowed, true));
    }

    private function isCachedPayload(mixed $payload): bool
    {
        return is_array($payload)
            && is_string($payload['body'] ?? null)
            && is_string($payload['content_type'] ?? null)
            && in_array($payload['encoding'] ?? 'identity', ['identity', 'gzip'], true)
            && str_starts_with(strtolower($payload['content_type']), 'text/html');
    }

    private function withStatus(Response $response, string $status): Response
    {
        $response->headers->set(self::HEADER, $status);

        return $response;
    }
}
