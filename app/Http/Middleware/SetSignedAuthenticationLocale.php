<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

final class SetSignedAuthenticationLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->query('locale');

        if (is_string($locale)
            && in_array($locale, (array) config('catalog-collections.supported_locales', []), true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
