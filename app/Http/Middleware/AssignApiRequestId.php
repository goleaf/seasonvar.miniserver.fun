<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignApiRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = trim((string) $request->header('X-Request-ID'));
        $requestId = preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $incoming) === 1
            ? $incoming
            : (string) Str::ulid();

        $request->attributes->set('api_request_id', $requestId);
        Context::add('api_request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
