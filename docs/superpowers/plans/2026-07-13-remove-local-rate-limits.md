# Remove Local Rate Limits Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove every HTTP `429 Too Many Requests` response produced locally by the Seasonvar application while preserving authorization, validation, signed URLs, importer locks, and handling of remote `429` responses.

**Architecture:** Route and Livewire transport throttles are deleted at their registration boundaries. Action-level buckets are removed from Livewire components and the media checker instead of being bypassed through oversized configuration. The unused playback concurrency status and all `RATE_LIMIT_*` configuration are removed, while external provider retry classification remains unchanged.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4, PHPUnit 12.5, Laravel Pint.

## Global Constraints

- Work only on the existing `main` branch; do not create branches or worktrees.
- Do not edit `.env`; only remove obsolete documented variables from `.env.example` and config files.
- Do not add Composer or npm dependencies.
- Keep authentication, gates/policies, signed playback URLs, CSRF, validation, importer locks, queue uniqueness, URL allowlists, timeouts, and bounded external retries unchanged.
- Keep visible UI text in Russian.
- Use PHPUnit, run focused tests before the broad suite, and run `./vendor/bin/pint --dirty --format agent` after PHP changes.
- Do not clear queues, failed jobs, caches, or production data and do not stop the running importer.

---

### Task 1: Remove HTTP and Livewire transport throttles

**Files:**
- Create: `tests/Feature/LocalRateLimitRemovalTest.php`
- Modify: `routes/web.php`
- Modify: `routes/api.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `config/livewire.php`

**Interfaces:**
- Consumes: Laravel route middleware registration and `Livewire::setUpdateRoute(callable $callback)`.
- Produces: web, API, and Livewire routes with no middleware string beginning with `throttle:` and no named local limiter registrations.

- [x] **Step 1: Write the failing route-boundary test**

Create `tests/Feature/LocalRateLimitRemovalTest.php` with route assertions for every currently throttled endpoint and the Livewire update route:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class LocalRateLimitRemovalTest extends TestCase
{
    public function test_public_web_and_api_routes_have_no_throttle_middleware(): void
    {
        foreach ([
            'health.ready',
            'stats',
            'stats.poster',
            'playback.source',
            'titles.index',
            'titles.year',
            'titles.taxonomy',
            'api.catalog.people',
            'api.titles.index',
            'api.titles.show',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Route [{$routeName}] is not registered.");
            $this->assertSame(
                [],
                array_values(array_filter(
                    $route->gatherMiddleware(),
                    static fn (string $middleware): bool => str_starts_with($middleware, 'throttle:'),
                )),
                "Route [{$routeName}] still has throttle middleware.",
            );
        }
    }

    public function test_livewire_update_route_has_no_throttle_middleware(): void
    {
        foreach (['livewire.update', 'livewire.upload-file'] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Route [{$routeName}] is not registered.");
            $this->assertSame(
                [],
                array_values(array_filter(
                    $route->gatherMiddleware(),
                    static fn (string $middleware): bool => str_starts_with($middleware, 'throttle:')
                        || str_contains($middleware, 'ThrottleRequests'),
                )),
                "Route [{$routeName}] still has throttle middleware.",
            );
            $this->assertContains('web', $route->gatherMiddleware());
        }
    }

    public function test_application_registers_no_named_local_rate_limiters(): void
    {
        foreach ([
            'catalog-stats',
            'catalog-query',
            'livewire-action',
            'catalog-api',
            'infrastructure-health',
            'playback-source',
        ] as $limiter) {
            $this->assertNull(RateLimiter::limiter($limiter));
        }
    }
}
```

- [x] **Step 2: Run the test and verify RED**

Run:

```bash
php artisan test tests/Feature/LocalRateLimitRemovalTest.php
```

Expected: failures list existing `throttle:*` middleware or registered named limiters.

- [x] **Step 3: Remove route middleware and limiter registrations**

In `routes/web.php`, keep route constraints, names, `signed`, authorization, and cache middleware, but remove only these throttle calls:

```php
Route::get('/health/ready', InfrastructureHealthController::class)
    ->withoutMiddleware($publicDocumentMiddleware)
    ->name('health.ready');
Route::get('/stats', [CatalogController::class, 'stats'])->name('stats');
Route::get('/stats/poster/{catalogTitle:slug}', [CatalogController::class, 'statsPoster'])->name('stats.poster');
Route::get('/playback/{licensedMedia}', PlaybackSourceController::class)
    ->middleware('signed')
    ->whereNumber('licensedMedia')
    ->name('playback.source');
Route::get('/titles', CatalogSeries::class)->name('titles.index');
Route::get('/titles/year/{year}', CatalogSeries::class)
    ->where('year', '(?:19|20)\d{2}')
    ->name('titles.year');
Route::get('/titles/{type}/{taxonomy}', CatalogSeries::class)
    ->where('type', CatalogFilterType::routePattern())
    ->where('taxonomy', '[a-z0-9][a-z0-9-]*')
    ->name('titles.taxonomy');
```

In `routes/api.php`, retain only public cache middleware:

```php
Route::middleware('public.cache:api')->group(function (): void {
    Route::get('/catalog/people', CatalogPeopleLookupController::class)->name('api.catalog.people');
    Route::get('/titles', [CatalogTitleController::class, 'index'])->name('api.titles.index');
    Route::get('/titles/{catalogTitle:slug}', [CatalogTitleController::class, 'show'])->name('api.titles.show');
});
```

In `AppServiceProvider`, delete imports for `RequestRateLimitKey`, `Limit`, `Request`, and `RateLimiter`; delete all six `RateLimiter::for(...)` blocks. Preserve the Livewire route with only the web stack:

```php
Livewire::setUpdateRoute(function ($handle, string $path) {
    return Route::post($path, $handle)->middleware('web');
});
```

Create `config/livewire.php` so Livewire's temporary upload endpoint also uses only the web stack instead of its package-default `throttle:60,1`:

```php
<?php

return [
    'temporary_file_upload' => [
        'middleware' => 'web',
    ],
];
```

- [x] **Step 4: Run the focused test and verify GREEN**

Run:

```bash
php artisan test tests/Feature/LocalRateLimitRemovalTest.php
```

Expected: all three tests pass.

- [x] **Step 5: Commit the route boundary**

```bash
git add tests/Feature/LocalRateLimitRemovalTest.php routes/web.php routes/api.php app/Providers/AppServiceProvider.php config/livewire.php docs/superpowers/specs/2026-07-13-remove-local-rate-limits-design.md docs/superpowers/plans/2026-07-13-remove-local-rate-limits.md
git commit -m "feat: remove HTTP request throttles"
```

### Task 2: Remove action-level buckets from Livewire components

**Files:**
- Modify: `tests/Feature/SecurityHardeningTest.php`
- Modify: `app/Livewire/CatalogSeries.php`
- Modify: `app/Livewire/CatalogTitlePlayer.php`
- Modify: `app/Livewire/ViewingActivity.php`
- Modify: `app/Livewire/SeasonvarImportManager.php`
- Modify: `app/Livewire/CatalogAdministrationManager.php`
- Delete: `app/Services/Security/SensitiveActionRateLimiter.php`

**Interfaces:**
- Consumes: existing Livewire action validation, authentication, gates, policies, and domain services.
- Produces: the same public method signatures and action results without calls to `SensitiveActionRateLimiter`.

- [ ] **Step 1: Replace the old limiter behavior test with a structural acceptance test**

Remove `test_sensitive_action_limits_are_independent_and_resource_scoped()` and its `SensitiveActionRateLimiter` import from `SecurityHardeningTest`. Add this test to `LocalRateLimitRemovalTest`:

```php
public function test_application_code_has_no_action_rate_limiter(): void
{
    $this->assertFileDoesNotExist(app_path('Services/Security/SensitiveActionRateLimiter.php'));

    $files = collect([
        app_path('Livewire/CatalogSeries.php'),
        app_path('Livewire/CatalogTitlePlayer.php'),
        app_path('Livewire/ViewingActivity.php'),
        app_path('Livewire/SeasonvarImportManager.php'),
        app_path('Livewire/CatalogAdministrationManager.php'),
    ]);

    $this->assertFalse($files->contains(
        static fn (string $file): bool => str_contains(file_get_contents($file), 'SensitiveActionRateLimiter'),
    ));
}
```

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```bash
php artisan test tests/Feature/LocalRateLimitRemovalTest.php tests/Feature/SecurityHardeningTest.php
```

Expected: the new test fails because the limiter class and component dependencies still exist.

- [ ] **Step 3: Remove the component dependencies and calls**

Delete the `SensitiveActionRateLimiter` import, `$rateLimits` property, boot parameter, and boot assignment from all five components. Keep every other boot dependency in its current order.

Delete these calls from component actions:

```php
$this->rateLimits->enforce('catalog_search', auth()->user());
$this->rateLimits->enforce('watchlist', $user, $this->catalogTitleId);
$this->rateLimits->enforce('rating', $user, $this->catalogTitleId);
$this->rateLimits->enforce('progress', $user, $episodeId);
$this->rateLimits->enforce('history', $this->user(), $progressId);
$this->rateLimits->enforce('history', $this->user());
$this->rateLimits->enforce('import_admin', $this->user());
$this->rateLimits->enforce('import_admin', $this->user(), $runId);
$this->rateLimits->enforce('catalog_admin', $user, $title->id);
```

The catalog administration call appears in each write action and must be removed from every occurrence. Validation, `Gate::authorize()`, policy calls, positive-ID normalization, and optimistic version checks remain.

Replace the playback-session ternary condition:

```php
$progressSessionToken = $user !== null
    && $selectedEpisode !== null
    && $selectedMedia !== null
    && $playbackSource->isPlayable()
        ? $this->progressSessions->issue($user, $title, $selectedEpisode, $selectedMedia)
        : '';
```

Delete `app/Services/Security/SensitiveActionRateLimiter.php` after no production class imports it.

- [ ] **Step 4: Verify components and security tests pass**

Run:

```bash
php artisan test tests/Feature/LocalRateLimitRemovalTest.php tests/Feature/SecurityHardeningTest.php tests/Feature/CatalogSearchPageTest.php tests/Feature/CatalogPageTest.php tests/Feature/AuthorizationTest.php tests/Feature/SeasonvarImportMaintenanceTest.php
```

Expected: all listed component, authorization, playback, catalog, and importer behavior passes without local action buckets.

- [ ] **Step 5: Commit action limiter removal**

```bash
git add tests/Feature/LocalRateLimitRemovalTest.php tests/Feature/SecurityHardeningTest.php app/Livewire app/Services/Security/SensitiveActionRateLimiter.php
git commit -m "feat: remove action rate limits"
```

### Task 3: Remove media-health, playback, and configuration remnants

**Files:**
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`
- Modify: `tests/Feature/LocalRateLimitRemovalTest.php`
- Modify: `app/Services/Seasonvar/SeasonvarMediaAvailabilityChecker.php`
- Modify: `app/Services/Catalog/CatalogPlaybackSourceResolver.php`
- Modify: `app/Enums/PlaybackAvailability.php`
- Modify: `app/Services/Operations/InfrastructureHealthCheck.php`
- Modify: `config/security.php`
- Modify: `config/catalog.php`
- Modify: `config/cache.php`
- Modify: `config/database.php`
- Modify: `.env.example`
- Modify: `tests/Unit/CacheArchitectureTest.php`
- Modify: `tests/Feature/CacheInfrastructureIntegrationTest.php`
- Modify: `tests/Feature/RedisWorkloadIntegrationTest.php`
- Delete: `app/Support/RateLimiting/RequestRateLimitKey.php`
- Delete: `tests/Unit/RequestRateLimitKeyTest.php`

**Interfaces:**
- Consumes: `SeasonvarMediaAvailabilityChecker::check(string $url, ?callable $progress = null): MediaHealthCheckResultData` and external HTTP fake responses.
- Produces: media checks that always reach the existing URL validation/HTTP boundary when enabled, and a playback enum with no local `429` status.

- [ ] **Step 1: Change the source-health test to require both HTTP checks**

In `SeasonvarImportMaintenanceTest`, replace the test that configures `security.rate_limits.source_health` and expects the second result to be `not_checked`. Use an HTTP sequence and assert both checks execute:

```php
public function test_media_health_checks_are_not_skipped_by_a_local_request_budget(): void
{
    Http::preventStrayRequests();
    Http::fake([
        'https://video.example/*' => Http::sequence()
            ->push('', 200, ['Content-Type' => 'application/vnd.apple.mpegurl'])
            ->push('', 200, ['Content-Type' => 'application/vnd.apple.mpegurl']),
    ]);

    $checker = app(SeasonvarMediaAvailabilityChecker::class);

    $this->assertSame('active', $checker->check('https://video.example/one.m3u8')->status);
    $this->assertSame('active', $checker->check('https://video.example/two.m3u8')->status);

    Http::assertSentCount(2);
}
```

Use the existing allowlisted host/config setup from the replaced test verbatim so URL validation remains part of the test.

Add to `LocalRateLimitRemovalTest`:

```php
public function test_playback_has_no_local_too_many_requests_status(): void
{
    $statuses = array_map(
        static fn (\App\Enums\PlaybackAvailability $status): int => $status->httpStatus(),
        \App\Enums\PlaybackAvailability::cases(),
    );

    $this->assertNotContains(429, $statuses);
}
```

In `CacheArchitectureTest`, change the named-store test so the desired configuration has no limiter workload:

```php
$this->assertNull(config('cache.limiter'));
$this->assertNull(config('cache.stores.redis-limiter'));
$this->assertNull(config('database.redis.limiter'));

foreach (['cache', 'sessions', 'queues', 'locks', 'broadcasting'] as $connection) {
    $this->assertIsArray(config('database.redis.'.$connection));
}
```

Delete `test_throttle_middleware_uses_the_dedicated_limiter_store()` and the two `ThrottleRequests` imports. In the readiness integration test, remove `redis_limiter` from `assertJsonStructure()` and add:

```php
$response->assertJsonMissingPath('components.redis_limiter');
```

- [ ] **Step 2: Run the new expectations and verify RED**

Run:

```bash
php artisan test tests/Feature/SeasonvarImportMaintenanceTest.php --filter=media_health_checks_are_not_skipped_by_a_local_request_budget
php artisan test tests/Feature/LocalRateLimitRemovalTest.php --filter=playback_has_no_local_too_many_requests_status
php artisan test tests/Unit/CacheArchitectureTest.php --filter=named_stores_and_redis_workload_connections_are_explicit
php artisan test tests/Feature/CacheInfrastructureIntegrationTest.php --filter=readiness_endpoint
```

Expected: the old source-health limiter skips the second request, `ConcurrencyExceeded` maps to `429`, limiter config still exists, and readiness still exposes `redis_limiter`.

- [ ] **Step 3: Remove the final runtime and configuration remnants**

Change the media checker constructor to:

```php
public function __construct(
    private readonly SeasonvarUrl $seasonvarUrl,
    private readonly PlaybackSourceUrlGuard $urls,
) {}
```

Delete the `SensitiveActionRateLimiter` import and the entire `attemptForSystem('source_health', $target->host)` early-return block.

Delete `case ConcurrencyExceeded`, its Russian message, and its `429` mapping from `PlaybackAvailability`. Delete `PlaybackAvailability::ConcurrencyExceeded` from the candidate priority list in `CatalogPlaybackSourceResolver`.

Reduce `config/security.php` to the non-rate-limit setting:

```php
<?php

return [
    'external_playlist_enforce_public_dns' => filter_var(env('EXTERNAL_PLAYLIST_ENFORCE_PUBLIC_DNS', true), FILTER_VALIDATE_BOOL),
];
```

Delete `query_rate_limit` from `config/catalog.php`. From `config/cache.php`, delete the top-level `limiter` option and the `redis-limiter` store. From `config/database.php`, delete the Redis `limiter` connection. From `InfrastructureHealthCheck`, delete the `redis_limiter` component and remove it from the critical component list.

Delete `.env.example` lines beginning with `RATE_LIMIT_`, `CACHE_LIMITER_`, or `REDIS_LIMITER_`. Delete the now-unused `RequestRateLimitKey` class and its unit test.

Delete `RedisWorkloadIntegrationTest::test_dedicated_redis_limiter_store_is_atomic_and_isolated_from_domain_cache()`. Rename `CacheInfrastructureIntegrationTest::test_session_queue_and_limiter_connections_are_isolated()` to `test_session_and_queue_connections_are_isolated()` and retain only the `sessions` and `queues` writes/assertions/cleanup.

Verify no local limiter remains while remote-response handling is retained:

```bash
rg -n "RateLimiter|SensitiveActionRateLimiter|throttle:|abort\(429|ConcurrencyExceeded|RATE_LIMIT_|CACHE_LIMITER|REDIS_LIMITER|redis-limiter|redis_limiter|connection\('limiter'\)" app bootstrap config routes .env.example tests
rg -n "\[408, 425, 429\]|status === 429" app/Services/Seasonvar
```

Expected: the first command has no application/config/route matches; the second still finds importer/media external-response classification.

- [ ] **Step 4: Run focused behavior tests and Pint**

Run:

```bash
php artisan test tests/Feature/LocalRateLimitRemovalTest.php tests/Feature/SecurityHardeningTest.php tests/Feature/SeasonvarImportMaintenanceTest.php tests/Unit/SeasonvarImportFailureClassifierTest.php tests/Unit/CacheArchitectureTest.php tests/Feature/CacheInfrastructureIntegrationTest.php tests/Feature/RedisWorkloadIntegrationTest.php
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/LocalRateLimitRemovalTest.php tests/Feature/SecurityHardeningTest.php tests/Feature/SeasonvarImportMaintenanceTest.php tests/Unit/SeasonvarImportFailureClassifierTest.php tests/Unit/CacheArchitectureTest.php tests/Feature/CacheInfrastructureIntegrationTest.php tests/Feature/RedisWorkloadIntegrationTest.php
```

Expected: focused tests pass before and after formatting; the external `429` classifier test remains green.

- [ ] **Step 5: Commit configuration and playback cleanup**

```bash
git add .env.example app config tests
git commit -m "refactor: remove rate limit infrastructure"
```

### Task 4: Align current documentation and run project verification

**Files:**
- Modify: `docs/security.md`
- Modify: `docs/architecture.md`
- Modify: `docs/administration.md`
- Modify: `docs/deployment.md`
- Modify: `docs/authorization.md`
- Modify: `docs/api.md`
- Modify: `docs/catalog-search.md`
- Modify: `docs/caching.md`
- Modify: `docs/environment.md`
- Modify: `docs/performance.md`
- Modify: `docs/testing.md`
- Modify: `docs/audit.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: documentation ownership map in `docs/README.md` and the behavior implemented in Tasks 1-3.
- Produces: current documentation that states local rate limits are absent and distinguishes them from remote provider `429` retry behavior.

- [ ] **Step 1: Write documentation acceptance assertions**

Extend `LocalRateLimitRemovalTest` with:

```php
public function test_current_documentation_does_not_advertise_local_rate_limits(): void
{
    foreach ([
        base_path('docs/security.md'),
        base_path('docs/architecture.md'),
        base_path('docs/administration.md'),
        base_path('docs/deployment.md'),
        base_path('docs/authorization.md'),
        base_path('docs/api.md'),
        base_path('docs/catalog-search.md'),
        base_path('docs/caching.md'),
        base_path('docs/environment.md'),
        base_path('docs/performance.md'),
        base_path('docs/testing.md'),
        base_path('docs/audit.md'),
    ] as $path) {
        $contents = file_get_contents($path);

        $this->assertStringNotContainsString('RATE_LIMIT_', $contents, $path);
        $this->assertStringNotContainsString('throttle:', $contents, $path);
        $this->assertStringNotContainsString('redis-limiter', $contents, $path);
        $this->assertStringNotContainsString('redis_limiter', $contents, $path);
    }
}
```

- [ ] **Step 2: Run documentation assertion and verify RED**

Run:

```bash
php artisan test tests/Feature/LocalRateLimitRemovalTest.php --filter=current_documentation
```

Expected: current security, architecture, administration, or deployment docs still contain old limiter contracts.

- [ ] **Step 3: Update owned documentation**

In current thematic docs, remove local budget variables and middleware claims. Add one explicit statement to `docs/security.md`:

```markdown
Локальные HTTP- и action-level rate limits отключены по решению владельца проекта. Authentication, authorization, CSRF, signed playback URL, validation, URL allowlists и bounded importer retries остаются обязательными границами. HTTP 429 от Seasonvar/CDN считается внешней transient-ошибкой и не является ограничением входящих запросов приложения.
```

Update `docs/architecture.md` so `/stats`, Livewire, API, catalog query, health, and playback route descriptions no longer claim a limiter. Update `docs/administration.md` and `docs/deployment.md` to remove `RATE_LIMIT_CATALOG_ADMIN` deployment guidance. Remove the unused limiter workload from `docs/authorization.md`, `docs/api.md`, `docs/catalog-search.md`, `docs/caching.md`, `docs/environment.md`, `docs/performance.md`, `docs/testing.md`, and current `docs/audit.md`. Add a dated `CHANGELOG.md` entry summarizing removal of local request/action limits and preservation of remote `429` retry behavior.

Do not edit historical files under `docs/superpowers/specs` or `docs/superpowers/plans` other than checkbox progress in this plan.

- [ ] **Step 4: Run documentation, focused, broad, and build checks**

Run:

```bash
php artisan project:docs-refresh --check
php artisan test tests/Feature/LocalRateLimitRemovalTest.php tests/Feature/SecurityHardeningTest.php tests/Feature/SeasonvarImportMaintenanceTest.php tests/Unit/SeasonvarImportFailureClassifierTest.php tests/Unit/CacheArchitectureTest.php
./vendor/bin/pint --dirty --format agent
npm run build
php artisan test
```

Expected: docs check, focused tests, Pint, build, and full PHPUnit suite pass. The running importer remains untouched. If the full suite exposes a shared-runtime conflict, preserve its output and rerun the smallest failing tests without clearing shared state.

- [ ] **Step 5: Perform the final no-429 audit and commit**

Run:

```bash
rg -n "RateLimiter|SensitiveActionRateLimiter|throttle:|abort\(429|ConcurrencyExceeded|RATE_LIMIT_|CACHE_LIMITER|REDIS_LIMITER|redis-limiter|redis_limiter" app bootstrap config routes .env.example tests docs/security.md docs/architecture.md docs/administration.md docs/deployment.md docs/authorization.md docs/api.md docs/catalog-search.md docs/caching.md docs/environment.md docs/performance.md docs/testing.md docs/audit.md
rg -n "429" app/Services/Seasonvar tests/Unit/SeasonvarImportFailureClassifierTest.php docs/importer.md docs/performance.md
git diff --check
git status --short --branch
```

Expected: the first search returns no local limiter contracts; the second returns only remote provider/media retry semantics; diff check is clean and branch is `main`.

Commit:

```bash
git add CHANGELOG.md docs/security.md docs/architecture.md docs/administration.md docs/deployment.md docs/authorization.md docs/api.md docs/catalog-search.md docs/caching.md docs/environment.md docs/performance.md docs/testing.md docs/audit.md tests/Feature/LocalRateLimitRemovalTest.php docs/superpowers/plans/2026-07-13-remove-local-rate-limits.md
git commit -m "docs: document unrestricted request handling"
```

After the commit, run `git status --short --branch` and require a clean working tree before moving to the separate importer-hardening plan.
