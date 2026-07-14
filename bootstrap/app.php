<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AssignApiRequestId;
use App\Http\Middleware\PublicHttpCacheHeaders;
use App\Http\Middleware\ResolveOptionalSanctumUser;
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
        ]);
        $middleware->web(append: [
            AddSecurityHeaders::class,
        ]);
        $middleware->api(prepend: [
            AssignApiRequestId::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
                'Переданные данные некорректны.',
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
                'Требуется аутентификация.',
                401,
            );
        });
        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return app(ApiErrorResponse::class)->make(
                $request,
                'forbidden',
                'Доступ запрещён.',
                403,
            );
        });
        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return app(ApiErrorResponse::class)->make(
                $request,
                'forbidden',
                'Доступ запрещён.',
                403,
            );
        });
        $exceptions->render(function (InvalidSignatureException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return app(ApiErrorResponse::class)->make(
                $request,
                'forbidden',
                'Доступ запрещён.',
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
                'Ресурс не найден.',
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
                'Ресурс не найден.',
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
                'Слишком много запросов. Повторите попытку позже.',
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
                'Внутренняя ошибка сервера.',
                500,
            );
        });
    })->create();
