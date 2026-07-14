<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class ResolveOptionalSanctumUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null) {
            return $next($request);
        }

        Auth::forgetGuards();
        $user = Auth::guard('sanctum')->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        if (! $user->tokenCan('mobile:read')) {
            throw new AuthorizationException('Токен не разрешает чтение мобильного API.');
        }

        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
