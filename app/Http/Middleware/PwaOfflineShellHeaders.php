<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PwaOfflineShellHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->routeIs('pwa.offline')) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'public, max-age=300');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->remove('Expires');
        $response->headers->remove('Pragma');

        return $response;
    }
}
