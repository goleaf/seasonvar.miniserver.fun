<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\AccountSettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

final class ApplyAccountPreferences
{
    public function __construct(private readonly AccountSettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $supportedLocales = (array) config('catalog-collections.supported_locales', []);
        $routeLocale = $request->route('locale');
        $user = $request->user();
        $resolved = $this->settings->resolve($user instanceof User ? $user : null);
        $sessionLocale = $request->hasSession()
            ? $request->session()->get('interface_locale_route')
            : null;
        $livewireLocale = $request->routeIs('livewire.update')
            && is_string($sessionLocale)
            && in_array($sessionLocale, $supportedLocales, true)
                ? $sessionLocale
                : null;

        if ($request->hasSession() && $routeLocale === null && ! $request->routeIs('livewire.update')) {
            $request->session()->forget('interface_locale_route');
        }

        $locale = is_string($routeLocale) && in_array($routeLocale, $supportedLocales, true)
            ? $routeLocale
            : ($livewireLocale ?? ($user instanceof User
                ? $resolved->locale
                : (is_string($sessionLocale) && in_array($sessionLocale, $supportedLocales, true)
                ? $sessionLocale
                : $resolved->locale)));

        App::setLocale($locale);
        View::share([
            'accountReducedMotion' => $resolved->reducedMotion,
            'accountSettingsVersion' => $resolved->version,
            'accountAnonymousStorageKey' => $user instanceof User
                ? (string) config('account-settings.anonymous_storage_key')
                : null,
            'accountPreferenceMigrationUrl' => $user instanceof User && Route::has('settings.preferences.migrate')
                ? route('settings.preferences.migrate')
                : null,
            'accountPreferenceMigrationScope' => $user instanceof User
                ? hash_hmac('sha256', (string) $user->getKey(), (string) config('app.key'))
                : null,
        ]);

        return $next($request);
    }
}
