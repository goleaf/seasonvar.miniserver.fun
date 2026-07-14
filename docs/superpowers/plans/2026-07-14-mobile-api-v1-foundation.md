# Mobile API v1 Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Создать версионированную `/api/v1` основу, Sanctum token storage, единый JSON error/request-id contract, discovery/config/health/OpenAPI endpoints и безопасные cache semantics без изменения legacy API.

**Architecture:** `routes/api.php` сохраняет legacy group и добавляет v1 group. Request-scoped middleware присваивает ULID request id, а централизованный responder в `bootstrap/app.php` формирует одинаковые API errors. Небольшие invokable controllers возвращают только presentation-safe arrays; Sanctum становится единственной mobile token boundary.

**Tech Stack:** PHP 8.5, Laravel 13.19, Laravel Sanctum 4.x, PHPUnit 12.5, SQLite, Laravel API Resources.

## Global Constraints

- Работать только в существующей ветке `main`; перед каждым commit проверять `git status --short --branch`.
- Разрешена одна новая production dependency: `laravel/sanctum`; другие production packages не добавлять.
- Не запускать `migrate:fresh`, `db:wipe`, `cache:clear` и другие destructive команды.
- Не редактировать `.env`; новые environment defaults добавлять только в `.env.example` и config files.
- Существующие `/api/titles`, `/api/titles/{slug}` и `/api/catalog/people` не менять breaking образом.
- Видимый текст и validation messages — на русском; machine codes — стабильные английские строки.
- Не раскрывать framework/database versions, importer/source state, raw media URL/path, tokens, secrets или stack traces.
- Любой PHP behavior создаётся через RED → GREEN → REFACTOR; сначала focused test, затем implementation.

---

### Task 1: Install Sanctum and establish expiring token storage

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Create: `config/sanctum.php`
- Create via Sanctum publish: `database/migrations/*_create_personal_access_tokens_table.php`
- Modify: `.env.example`
- Modify: `app/Models/User.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/console.php`
- Create: `tests/Feature/Api/V1/MobileTokenFoundationTest.php`

**Interfaces:**
- Produces: `User::createToken(string $name, array $abilities, ?DateTimeInterface $expiresAt)` through `Laravel\Sanctum\HasApiTokens`.
- Produces: global `sanctum.expiration=129600` minutes and scheduled `sanctum:prune-expired --hours=24`.

- [ ] **Step 1: Install and publish the approved dependency**

Run:

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
```

Expected: `composer.json`/`composer.lock`, `config/sanctum.php`, and one additive `create_personal_access_tokens_table` migration are created; existing `routes/api.php` is untouched.

- [ ] **Step 2: Write the failing token foundation tests**

Create `tests/Feature/Api/V1/MobileTokenFoundationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\HasApiTokens;
use Tests\TestCase;

final class MobileTokenFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_uses_sanctum_and_issues_a_hashed_expiring_mobile_token(): void
    {
        $this->assertContains(HasApiTokens::class, class_uses_recursive(User::class));

        $user = User::factory()->create();
        $token = $user->createToken(
            'Pixel 9',
            ['mobile:read', 'mobile:write'],
            now()->addDays(90),
        );

        $this->assertNotSame($token->plainTextToken, $token->accessToken->token);
        $this->assertSame(['mobile:read', 'mobile:write'], $token->accessToken->abilities);
        $this->assertTrue($token->accessToken->expires_at?->isFuture());
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'Pixel 9',
        ]);
    }

    public function test_expired_sanctum_tokens_are_scheduled_for_daily_pruning(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('sanctum:prune-expired --hours=24')
            ->assertSuccessful();
    }
}
```

- [ ] **Step 3: Run RED and confirm the missing model trait/schedule**

Run:

```bash
php artisan test tests/Feature/Api/V1/MobileTokenFoundationTest.php
```

Expected: FAIL because `User` does not use `HasApiTokens` and the prune command is not scheduled.

- [ ] **Step 4: Add the token trait, expiration config, and scheduler**

Modify `app/Models/User.php` imports and trait list:

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
}
```

Set `config/sanctum.php` expiration:

```php
'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 129600),
```

Add to `.env.example`:

```dotenv
SANCTUM_TOKEN_EXPIRATION_MINUTES=129600
```

Register Sanctum ability middleware aliases in `bootstrap/app.php`:

```php
'abilities' => CheckAbilities::class,
'ability' => CheckForAnyAbility::class,
```

Append to `routes/console.php`:

```php
Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('03:41')
    ->name('sanctum-prune-expired')
    ->withoutOverlapping(10)
    ->onOneServer();
```

- [ ] **Step 5: Run GREEN and inspect the additive migration**

Run:

```bash
php artisan test tests/Feature/Api/V1/MobileTokenFoundationTest.php
php artisan migrate:status
```

Expected: both tests PASS; migration status lists the Sanctum migration without running destructive operations.

- [ ] **Step 6: Format and commit**

Run:

```bash
./vendor/bin/pint --dirty --format agent
git status --short --branch
git add composer.json composer.lock config/sanctum.php database/migrations .env.example app/Models/User.php bootstrap/app.php routes/console.php tests/Feature/Api/V1/MobileTokenFoundationTest.php
git commit -m "feat: add Sanctum mobile token foundation"
```

Expected: commit succeeds on `main`.

---

### Task 2: Add request IDs and the stable API error envelope

**Files:**
- Create: `app/Http/Middleware/AssignApiRequestId.php`
- Create: `app/Http/Responses/ApiErrorResponse.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/api.php`
- Modify: `tests/Feature/RouteFallbackTest.php`
- Modify: `tests/Feature/ApiCatalogTitleTest.php`

**Interfaces:**
- Produces: `AssignApiRequestId::id(Request $request): string` via request attribute `api_request_id` and response header `X-Request-ID`.
- Produces: `ApiErrorResponse::make(Request $request, string $code, string $message, int $status, array $errors = []): JsonResponse`.

- [ ] **Step 1: Write failing error-envelope assertions**

Extend `tests/Feature/RouteFallbackTest.php`:

```php
public function test_unknown_api_paths_return_the_stable_error_envelope(): void
{
    $response = $this->getJson('/api/nesushchestvuyushchii-endpoint');

    $response->assertNotFound()
        ->assertHeader('X-Request-ID')
        ->assertJsonPath('code', 'not_found')
        ->assertJsonPath('message', 'Ресурс не найден.')
        ->assertJsonStructure(['code', 'message', 'request_id']);

    $this->assertSame(
        $response->headers->get('X-Request-ID'),
        $response->json('request_id'),
    );
}
```

Extend `tests/Feature/ApiCatalogTitleTest.php` validation test:

```php
$this->getJson('/api/titles?per_page=200&page=0')
    ->assertUnprocessable()
    ->assertHeader('X-Request-ID')
    ->assertJsonPath('code', 'validation_failed')
    ->assertJsonPath('message', 'Переданные данные некорректны.')
    ->assertJsonValidationErrors(['per_page', 'page'])
    ->assertJsonStructure(['code', 'message', 'errors', 'request_id']);
```

- [ ] **Step 2: Run RED**

Run:

```bash
php artisan test tests/Feature/RouteFallbackTest.php tests/Feature/ApiCatalogTitleTest.php --filter='stable_error_envelope|validates_pagination'
```

Expected: FAIL because `code`, `request_id`, and `X-Request-ID` do not exist.

- [ ] **Step 3: Implement request-id middleware**

Create `app/Http/Middleware/AssignApiRequestId.php`:

```php
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
```

- [ ] **Step 4: Implement the responder and exception renderers**

Create `app/Http/Responses/ApiErrorResponse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApiErrorResponse
{
    /** @param array<string, list<string>> $errors */
    public function make(
        Request $request,
        string $code,
        string $message,
        int $status,
        array $errors = [],
    ): JsonResponse {
        $body = [
            'code' => $code,
            'message' => $message,
        ];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        $body['request_id'] = (string) $request->attributes->get('api_request_id');

        return response()->json($body, $status, [
            'Cache-Control' => 'private, no-store',
            'X-Request-ID' => (string) $request->attributes->get('api_request_id'),
        ]);
    }
}
```

In `bootstrap/app.php`, prepend middleware and register API-only renderers for `ValidationException`, `AuthenticationException`, `AuthorizationException`, `ModelNotFoundException`, `NotFoundHttpException`, `ThrottleRequestsException`, and a final `Throwable`. Map them respectively to `validation_failed`/422, `unauthenticated`/401, `forbidden`/403, `not_found`/404, `rate_limited`/429, and `server_error`/500. The final renderer must return `null` for non-API requests and the generic Russian message `Внутренняя ошибка сервера.` without `$exception->getMessage()`.

Add `Route::fallback(static fn () => abort(404))->name('api.fallback');` as the final statement of `routes/api.php`. This ensures unknown `/api/*` requests pass through the API middleware (and receive a request id) instead of reaching the web fallback.

Use this exact middleware registration inside the existing callback:

```php
$middleware->api(prepend: [AssignApiRequestId::class]);
```

Use this exact validation renderer shape:

```php
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
```

- [ ] **Step 5: Run GREEN and legacy web regression**

Run:

```bash
php artisan test tests/Feature/RouteFallbackTest.php tests/Feature/ApiCatalogTitleTest.php
```

Expected: PASS; unknown web route still redirects to home while `/api/*` errors have the stable JSON envelope.

- [ ] **Step 6: Format and commit**

Run:

```bash
./vendor/bin/pint --dirty --format agent
git status --short --branch
git add app/Http/Middleware/AssignApiRequestId.php app/Http/Responses/ApiErrorResponse.php bootstrap/app.php routes/api.php tests/Feature/RouteFallbackTest.php tests/Feature/ApiCatalogTitleTest.php
git commit -m "feat: standardize API errors and request IDs"
```

---

### Task 3: Add discovery, safe config, health, and OpenAPI endpoints

**Files:**
- Create: `config/mobile-api.php`
- Create: `app/Http/Controllers/Api/ApiDiscoveryController.php`
- Create: `app/Http/Controllers/Api/V1/ApiConfigController.php`
- Create: `app/Http/Controllers/Api/V1/ApiHealthController.php`
- Create: `app/Http/Controllers/Api/OpenApiController.php`
- Create: `resources/api/openapi.json`
- Modify: `routes/api.php`
- Create: `tests/Feature/Api/V1/ApiFoundationEndpointTest.php`

**Interfaces:**
- Produces named routes `api.discovery`, `api.openapi`, `api.v1.config`, `api.v1.health`.
- Produces config keys `mobile-api.version`, `minimum_supported_version`, `default_per_page`, `maximum_per_page`, `progress_heartbeat_seconds`.

- [ ] **Step 1: Write failing endpoint tests**

Create `tests/Feature/Api/V1/ApiFoundationEndpointTest.php` with `RefreshDatabase` and these assertions:

```php
public function test_api_root_discovers_v1_and_openapi_without_infrastructure_details(): void
{
    $response = $this->getJson('/api');

    $response->assertOk()
        ->assertJsonPath('data.current_version', 'v1')
        ->assertJsonPath('data.base_url', url('/api/v1'))
        ->assertJsonPath('data.openapi_url', url('/api/openapi.json'))
        ->assertJsonMissingPath('data.framework_version')
        ->assertJsonMissingPath('data.database');
}

public function test_v1_config_and_health_are_safe_and_stable(): void
{
    $this->getJson('/api/v1/config')
        ->assertOk()
        ->assertJsonPath('data.locale', 'ru')
        ->assertJsonPath('data.pagination.maximum_per_page', 50)
        ->assertJsonPath('data.user_rating.minimum', 1)
        ->assertJsonStructure(['data' => ['playback' => ['formats', 'qualities']]]);

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertHeader('Cache-Control', 'private, no-store')
        ->assertExactJson([
            'data' => [
                'status' => 'ok',
                'server_time' => now()->utc()->toISOString(),
                'api_version' => 'v1',
            ],
        ]);
}

public function test_openapi_document_is_valid_json_and_describes_bearer_auth(): void
{
    $this->getJson('/api/openapi.json')
        ->assertOk()
        ->assertJsonPath('openapi', '3.1.0')
        ->assertJsonPath('components.securitySchemes.bearerAuth.scheme', 'bearer')
        ->assertJsonPath('paths./api/v1/health.get.operationId', 'getApiHealth');
}
```

Freeze time with `$this->travelTo(now()->startOfSecond())` in the health test so the exact timestamp is deterministic.

- [ ] **Step 2: Run RED**

Run:

```bash
php artisan test tests/Feature/Api/V1/ApiFoundationEndpointTest.php
```

Expected: FAIL with 404 for the four new endpoints.

- [ ] **Step 3: Implement config and invokable controllers**

Create `config/mobile-api.php`:

```php
<?php

return [
    'version' => 'v1',
    'minimum_supported_version' => 'v1',
    'default_per_page' => 20,
    'maximum_per_page' => 50,
    'progress_heartbeat_seconds' => 15,
];
```

`ApiDiscoveryController` returns one `data` object containing `service`, `current_version`, `minimum_supported_version`, `base_url`, `openapi_url`, and capability strings `catalog`, `authentication`, `user_state`, `playback`.

`ApiConfigController` reads only `config('mobile-api.*')`, `config('catalog.user_rating.*')`, `config('playback.allowed_formats')`, `config('playback.supported_qualities')`, and bounded playback TTLs. It must not return allowed hosts/disks/provider priority.

`ApiHealthController` returns:

```php
return response()->json(['data' => [
    'status' => 'ok',
    'server_time' => now()->utc()->toISOString(),
    'api_version' => (string) config('mobile-api.version'),
]], headers: ['Cache-Control' => 'private, no-store']);
```

`OpenApiController` reads `resource_path('api/openapi.json')`, decodes with `JSON_THROW_ON_ERROR`, and returns the decoded array as JSON. Seed `resources/api/openapi.json` with valid OpenAPI 3.1 info, server `/api/v1`, `bearerAuth`, common `ApiError`, and the four foundation paths; later plans add their paths and schemas.

- [ ] **Step 4: Register routes without touching legacy names**

Add to `routes/api.php` before the existing legacy group:

```php
Route::middleware('public.cache:api')->group(function (): void {
    Route::get('/', ApiDiscoveryController::class)->name('api.discovery');
    Route::get('/openapi.json', OpenApiController::class)->name('api.openapi');

    Route::prefix('v1')->name('api.v1.')->group(function (): void {
        Route::get('/config', ApiConfigController::class)->name('config');
    });
});

Route::get('/v1/health', ApiHealthController::class)->name('api.v1.health');
```

- [ ] **Step 5: Run GREEN and route inspection**

Run:

```bash
php artisan test tests/Feature/Api/V1/ApiFoundationEndpointTest.php
php artisan route:list --path=api
```

Expected: tests PASS; new names exist together with all three legacy route names.

- [ ] **Step 6: Commit**

Run:

```bash
./vendor/bin/pint --dirty --format agent
git status --short --branch
git add config/mobile-api.php app/Http/Controllers/Api resources/api/openapi.json routes/api.php tests/Feature/Api/V1/ApiFoundationEndpointTest.php
git commit -m "feat: add mobile API v1 discovery contract"
```

---

### Task 4: Close shared-cache behavior for Authorization requests

**Files:**
- Modify: `app/Http/Middleware/PublicHttpCacheHeaders.php`
- Modify: `tests/Feature/PublicHttpCacheHeadersTest.php`
- Modify: `tests/Feature/LocalRateLimitRemovalTest.php`

**Interfaces:**
- Consumes: public API routes from Task 3.
- Produces: every request containing `Authorization` is `private, no-store` and has no ETag/Last-Modified even when authentication is unresolved.

- [ ] **Step 1: Write failing cache tests**

Add to `PublicHttpCacheHeadersTest`:

```php
public function test_api_request_with_authorization_header_is_never_shared_cacheable(): void
{
    $response = $this->withToken('invalid-mobile-token')->getJson('/api/v1/config');

    $response->assertHeader('Cache-Control', 'private, no-store');
    $this->assertFalse($response->headers->has('ETag'));
    $this->assertFalse($response->headers->has('Last-Modified'));
}
```

Extend `LocalRateLimitRemovalTest` route list with `api.discovery`, `api.openapi`, `api.v1.config`, and `api.v1.health` to prove public discovery still has no general throttle.

- [ ] **Step 2: Run RED**

Run:

```bash
php artisan test tests/Feature/PublicHttpCacheHeadersTest.php tests/Feature/LocalRateLimitRemovalTest.php
```

Expected: cache test FAIL because current middleware ignores unresolved Authorization headers.

- [ ] **Step 3: Fail closed before applying shared-cache headers**

In `PublicHttpCacheHeaders::handle()`, extend the early non-cacheable branch:

```php
if (! $request->isMethodSafe()
    || $request->headers->has('Authorization')
    || $request->user() !== null
    || $response->getStatusCode() !== Response::HTTP_OK
    || $response->headers->has('Set-Cookie')) {
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->remove('ETag');
    $response->headers->remove('Last-Modified');

    return $response;
}
```

- [ ] **Step 4: Run GREEN and full current API regressions**

Run:

```bash
php artisan test tests/Feature/PublicHttpCacheHeadersTest.php tests/Feature/LocalRateLimitRemovalTest.php tests/Feature/ApiCatalogTitleTest.php tests/Feature/RouteFallbackTest.php
```

Expected: PASS; anonymous safe GET retains public validators, Authorization never does.

- [ ] **Step 5: Commit**

Run:

```bash
./vendor/bin/pint --dirty --format agent
git status --short --branch
git add app/Http/Middleware/PublicHttpCacheHeaders.php tests/Feature/PublicHttpCacheHeadersTest.php tests/Feature/LocalRateLimitRemovalTest.php
git commit -m "fix: keep authorized API responses private"
```

---

### Task 5: Document and verify the foundation

**Files:**
- Modify: `docs/api.md`
- Modify: `docs/architecture.md`
- Modify: `docs/authorization.md`
- Modify: `docs/security.md`
- Modify: `docs/testing.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Produces: documented v1 discovery/error/Sanctum foundation consumed by all later plans.

- [ ] **Step 1: Update owner documentation with exact implemented behavior**

Add `/api`, `/api/openapi.json`, `/api/v1/config`, `/api/v1/health`, request-id/error envelope, approved Sanctum dependency, 90-day expiration, prune schedule, and Authorization cache rule. Keep admin/import explicitly out of mobile API and do not duplicate route tables outside `docs/api.md`.

- [ ] **Step 2: Run documentation and dependency verification**

Run:

```bash
php artisan project:docs-refresh --check
composer audit
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/Api/V1 tests/Feature/ApiCatalogTitleTest.php tests/Feature/PublicHttpCacheHeadersTest.php tests/Feature/RouteFallbackTest.php tests/Feature/LocalRateLimitRemovalTest.php
```

Expected: docs check current, no known dependency advisories, focused suite PASS.

- [ ] **Step 3: Run the broad suite**

Run:

```bash
php artisan test
```

Expected: full PHPUnit suite PASS with no warnings/errors.

- [ ] **Step 4: Commit foundation documentation**

Run:

```bash
git status --short --branch
git add docs/api.md docs/architecture.md docs/authorization.md docs/security.md docs/testing.md README.md CHANGELOG.md
git commit -m "docs: document mobile API v1 foundation"
```

Expected: clean `main` worktree after commit.
