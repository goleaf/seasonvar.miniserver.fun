<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\ApplyAccountPreferences;
use App\Http\Middleware\AssignApiRequestId;
use App\Http\Middleware\CachePublicPage;
use App\Http\Middleware\EnsureMobileEmailIsVerified;
use App\Http\Middleware\PrivateAccountResponse;
use App\Http\Middleware\PublicHttpCacheHeaders;
use App\Http\Middleware\ResolveCanonicalTagRoute;
use App\Http\Middleware\ResolveOptionalSanctumUser;
use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\SetInterfaceLocale;
use App\Http\Responses\ApiErrorResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(
            function (Request $request): ?string {
                if ($request->is('api/*')) {
                    return null;
                }

                $locale = $request->route('locale');

                return is_string($locale)
                    && in_array($locale, (array) config('catalog-collections.supported_locales', []), true)
                    ? route('localized.login', ['locale' => $locale])
                    : route('login');
            },
        );
        $middleware->redirectUsersTo(
            fn (): string => route('library.index'),
        );
        $middleware->trustHosts(
            at: fn (): array => array_filter([
                is_string($host = parse_url((string) config('app.url'), PHP_URL_HOST)) && $host !== ''
                    ? '^'.preg_quote($host, '/').'$'
                    : null,
            ]),
            subdomains: false,
        );
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'auth.optional.sanctum' => ResolveOptionalSanctumUser::class,
            'public.cache' => PublicHttpCacheHeaders::class,
            'public.page' => CachePublicPage::class,
            'canonical.tag' => ResolveCanonicalTagRoute::class,
            'collection.locale' => SetInterfaceLocale::class,
            'account.private' => PrivateAccountResponse::class,
            'verified.api' => EnsureMobileEmailIsVerified::class,
        ]);
        $middleware->web(append: [
            AddSecurityHeaders::class,
            ApplyAccountPreferences::class,
        ]);
        $middleware->api(
            append: [SetApiLocale::class, AddSecurityHeaders::class],
            prepend: [AssignApiRequestId::class],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $invalidVerificationLink = static fn () => response()->view('errors.403', [
            'title' => __('auth.errors.invalid_verification_link_title'),
            'message' => __('auth.errors.invalid_verification_link'),
        ], 403);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return app(ApiErrorResponse::class)->make(
                $request,
                'validation_failed',
                __('auth.errors.validation_failed'),
                422,
                $exception->errors(),
            );
        });
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return app(ApiErrorResponse::class)->make(
                $request,
                'unauthenticated',
                __('auth.errors.unauthenticated'),
                401,
            );
        });
        $exceptions->render(function (AuthorizationException $exception, Request $request) use ($invalidVerificationLink) {
            if (! $request->is('api/*')) {
                if ($request->routeIs('verification.verify') || $request->is('email/verify/*')) {
                    return $invalidVerificationLink();
                }

                return null;
            }

            return app(ApiErrorResponse::class)->make(
                $request,
                'forbidden',
                __('auth.errors.forbidden'),
                403,
            );
        });
        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request) use ($invalidVerificationLink) {
            if (! $request->is('api/*')) {
                if ($request->routeIs('verification.verify') || $request->is('email/verify/*')) {
                    return $invalidVerificationLink();
                }

                return null;
            }

            return app(ApiErrorResponse::class)->make(
                $request,
                'forbidden',
                __('auth.errors.forbidden'),
                403,
            );
        });
        $exceptions->render(function (InvalidSignatureException $exception, Request $request) use ($invalidVerificationLink) {
            if (! $request->is('api/*')) {
                if ($request->routeIs('verification.verify') || $request->is('email/verify/*')) {
                    return $invalidVerificationLink();
                }

                return null;
            }

            return app(ApiErrorResponse::class)->make(
                $request,
                'forbidden',
                __('auth.errors.forbidden'),
                403,
            );
        });
        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return app(ApiErrorResponse::class)->make(
                $request,
                'not_found',
                __('auth.errors.not_found'),
                404,
            );
        });
        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return app(ApiErrorResponse::class)->make(
                $request,
                'not_found',
                __('auth.errors.not_found'),
                404,
            );
        });
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return app(ApiErrorResponse::class)->make(
                $request,
                'rate_limited',
                __('auth.errors.rate_limited'),
                429,
            );
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return app(ApiErrorResponse::class)->make(
                $request,
                'server_error',
                __('auth.errors.server_error'),
                500,
            );
        });
    })->create();
