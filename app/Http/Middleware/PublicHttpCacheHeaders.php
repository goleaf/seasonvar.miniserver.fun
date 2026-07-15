<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use App\Support\Cache\CacheVersionUnavailable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class PublicHttpCacheHeaders
{
    public function __construct(private readonly CacheVersionRegistry $versions) {}

    public function handle(Request $request, Closure $next, string $profile = 'api'): Response
    {
        $response = $next($request);

        if (! $request->isMethodSafe()
            || $request->headers->has('Authorization')
            || $request->user() !== null
            || $response->getStatusCode() !== Response::HTTP_OK
            || $response->headers->has('Set-Cookie')) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->remove('ETag');
            $response->headers->remove('Last-Modified');

            return $response;
        }

        $policy = config('cache-architecture.http.'.$profile);

        if (! is_array($policy)) {
            return $response;
        }

        $domain = in_array($profile, ['documents', 'collection_documents'], true)
            ? CacheDomain::Sitemap
            : CacheDomain::Api;
        $response->headers->set('Cache-Control', implode(', ', [
            'public',
            'max-age='.max(0, (int) ($policy['max_age'] ?? 0)),
            's-maxage='.max(0, (int) ($policy['shared_max_age'] ?? 0)),
            'stale-while-revalidate='.max(0, (int) ($policy['stale_while_revalidate'] ?? 0)),
            'stale-if-error='.max(0, (int) ($policy['stale_if_error'] ?? 0)),
        ]));
        $response->headers->set('Vary', 'Accept, Accept-Encoding');
        try {
            $response->setLastModified($this->versions->lastModified($domain));
            $response->setEtag(hash('sha256', implode('|', [
                $domain->value,
                (string) $this->versions->version($domain),
                Str::lower($request->getSchemeAndHttpHost()),
                $request->getRequestUri(),
                (string) $request->headers->get('Accept'),
            ])));
        } catch (CacheVersionUnavailable) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->remove('ETag');
            $response->headers->remove('Last-Modified');

            return $response;
        }

        if ($response->isNotModified($request)) {
            $response->setNotModified();
        }

        return $response;
    }
}
