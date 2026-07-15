<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

final class SetInterfaceLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route('locale');
        abort_unless(is_string($locale) && in_array($locale, config('catalog-collections.supported_locales', []), true), 404);
        App::setLocale($locale);

        return $next($request);
    }
}
