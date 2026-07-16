<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SwitchInterfaceLocaleRequest;
use App\Models\User;
use App\Services\Auth\AccountSettingsService;
use App\Services\Localization\LocalizedRouteResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\App;

final class SwitchInterfaceLocaleController extends Controller
{
    public function __invoke(
        SwitchInterfaceLocaleRequest $request,
        AccountSettingsService $settings,
        LocalizedRouteResolver $routes,
    ): RedirectResponse {
        $validated = $request->validated();
        $locale = (string) $validated['locale'];
        $user = $request->user();

        if ($user instanceof User) {
            $settings->updateLocaleIfAvailable($user, $locale);
        }

        $request->session()->put('interface_locale_route', $locale);
        App::setLocale($locale);

        return redirect()->to($routes->safePath((string) $validated['return_to'], $locale));
    }
}
