<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

final class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = (array) config('catalog-collections.supported_locales', []);
        $fallback = (string) config('account-settings.default_locale', config('app.locale', 'ru'));
        $preferred = $request->getPreferredLanguage($supported);

        App::setLocale(is_string($preferred) && in_array($preferred, $supported, true)
            ? $preferred
            : $fallback);

        return $next($request);
    }
}
