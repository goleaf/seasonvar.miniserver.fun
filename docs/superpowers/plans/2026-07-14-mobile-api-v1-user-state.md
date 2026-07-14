# Mobile API v1 User State Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Открыть mobile endpoints для watchlist/избранного, личных оценок, состояния тайтла, Continue Watching и Viewing History с email-verified mutations и строгой user isolation.

**Architecture:** Existing `CatalogUserStateService`, `CatalogPrimaryActionResolver`, `CatalogViewingActivityQuery` и policies остаются write/read domain boundaries. Новые query services подготавливают owner-scoped paginators и eager-loaded presentation data; Resources только сериализуют. Custom verified middleware возвращает стабильный API code вместо web redirect.

**Tech Stack:** PHP 8.5, Laravel 13.19, Sanctum, Eloquent/API Resources, PHPUnit 12.5, SQLite.

## Global Constraints

- Выполнить foundation, public catalog и auth/account plans до этого плана.
- Private reads требуют `auth:sanctum`; mutations дополнительно требуют подтверждённый email.
- Неподтверждённый user после смены email всё ещё читает собственное state, но не меняет его.
- Watchlist и «избранное» — одна существующая `in_watchlist` модель; новую favorites table не создавать.
- Provider ratings не смешивать с user rating aggregate.
- Все title/release reads проходят `visibleTo($user)`/`availableTo($user)`; hidden rows не становятся доступными из private state.
- Cross-user numeric id отвечает 404/403 без изменения чужой строки; предпочтителен owner-scoped 404.
- Private responses всегда `Cache-Control: private, no-store`.
- Все новые маршруты вставлять перед named `api.fallback`, который остаётся последним statement в `routes/api.php`.
- No queries in Resources; TDD and frequent commits.

---

### Task 1: Add verified API middleware and title-state mutations

**Files:**
- Create: `app/Http/Middleware/EnsureMobileEmailIsVerified.php`
- Modify: `bootstrap/app.php`
- Create: `app/Http/Requests/Api/V1/SetRatingRequest.php`
- Create: `app/Http/Controllers/Api/V1/UserTitleStateController.php`
- Create: `app/Http/Resources/Api/V1/UserTitleStateResource.php`
- Create: `app/Services/Catalog/Api/V1/UserTitleStateQuery.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Api/V1/UserTitleStateTest.php`

**Interfaces:**
- Produces middleware alias `verified.api`.
- Produces GET state plus idempotent PUT/DELETE watchlist and rating routes.
- Produces `UserTitleStateQuery::get(User $user, CatalogTitle $title): array`.

- [ ] **Step 1: Write failing verification/state tests**

Create fixtures for verified user, unverified user, another user and hidden title. Assert:

```php
public function test_unverified_user_reads_existing_state_but_cannot_mutate_it(): void
{
    $user = User::factory()->unverified()->create();
    $title = CatalogTitle::factory()->create();
    CatalogTitleUserState::query()->create([
        'user_id' => $user->id,
        'catalog_title_id' => $title->id,
        'in_watchlist' => true,
        'rating' => 8,
    ]);

    Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

    $this->getJson("/api/v1/me/titles/{$title->slug}/state")
        ->assertOk()
        ->assertJsonPath('data.in_watchlist', true)
        ->assertJsonPath('data.rating', 8);

    $this->deleteJson("/api/v1/me/watchlist/{$title->slug}")
        ->assertForbidden()
        ->assertJsonPath('code', 'email_not_verified');
}
```

Assert verified PUT watchlist twice creates one row; DELETE twice succeeds and leaves false/no orphan row according to service behavior. Assert rating 1/10 works, 0/11/non-integer fails, DELETE clears rating. Assert another user sees no personal value and hidden title returns 404.

- [ ] **Step 2: Run RED**

```bash
php artisan test tests/Feature/Api/V1/UserTitleStateTest.php
```

Expected: FAIL with 404.

- [ ] **Step 3: Implement verified API middleware**

Create:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiErrorResponse;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureMobileEmailIsVerified
{
    public function __construct(private ApiErrorResponse $errors) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return $this->errors->make(
                $request,
                'email_not_verified',
                'Подтвердите email, чтобы изменить данные профиля.',
                403,
            );
        }

        return $next($request);
    }
}
```

Register `verified.api` in `bootstrap/app.php` aliases.

- [ ] **Step 4: Implement state query/resource**

`UserTitleStateQuery` injects `CatalogUserStateService` and `CatalogPrimaryActionResolver`; returns:

```php
return [
    'state' => $this->states->state($user, $title),
    'summary' => $this->states->summary($title),
    'rating_range' => $this->states->ratingRange(),
    'primary_action' => $this->actions->resolve($title, $user),
];
```

Resource explicitly returns `in_watchlist`, nullable personal `rating`, `aggregate` count/average, rating min/max, and primary action type/label/season_id/episode_id/media_id/position_seconds.

- [ ] **Step 5: Implement mutation controller and validation**

Controller calls only existing service methods:

```php
$states->setWatchlist($request->user(), $catalogTitle, true);
$states->setWatchlist($request->user(), $catalogTitle, false);
$states->setRating($request->user(), $catalogTitle, $request->rating());
$states->setRating($request->user(), $catalogTitle, null);
```

After each mutation, return fresh `UserTitleStateResource`. `SetRatingRequest` derives min/max from `CatalogUserStateService::ratingRange()` and validates required integer between them.

- [ ] **Step 6: Register routes with correct middleware**

Under `auth:sanctum,abilities:mobile:read`, register `GET /api/v1/me/titles/{catalogTitle:slug}/state`. Under `auth:sanctum,abilities:mobile:write,verified.api`, register PUT/DELETE for `/api/v1/me/watchlist/{catalogTitle:slug}` and `/api/v1/me/ratings/{catalogTitle:slug}`. Use route names under `api.v1.me.*`. Do not use shared-cache middleware.

- [ ] **Step 7: Run GREEN and existing Livewire state regressions**

```bash
php artisan test tests/Feature/Api/V1/UserTitleStateTest.php tests/Feature/CatalogPageTest.php --filter='watchlist|rating|user_state'
```

Expected: PASS.

- [ ] **Step 8: Commit**

Run Pint, inspect/stage exact task files and commit:

```bash
git commit -m "feat: expose mobile title user state"
```

---

### Task 2: Add paginated watchlist and ratings libraries

**Files:**
- Create: `app/Services/Catalog/Api/V1/UserLibraryQuery.php`
- Create: `app/Http/Controllers/Api/V1/UserLibraryController.php`
- Create: `app/Http/Requests/Api/V1/UserLibraryIndexRequest.php`
- Create: `app/Http/Resources/Api/V1/UserLibraryItemResource.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Api/V1/UserLibraryTest.php`

**Interfaces:**
- Produces `watchlist(User $user, int $perPage): LengthAwarePaginator`.
- Produces `ratings(User $user, int $perPage): LengthAwarePaginator`.

- [ ] **Step 1: Write failing library tests**

Create 25 states for user, several for another user, a hidden title and a soft-deleted/inaccessible title. Assert page/per_page metadata, descending state update order, only owner rows, only visible title cards, correct personal rating/watchlist values, and no raw user_id/source fields. Assert unverified user can read.

- [ ] **Step 2: Run RED**

```bash
php artisan test tests/Feature/Api/V1/UserLibraryTest.php
```

Expected: FAIL with 404.

- [ ] **Step 3: Implement owner-scoped library query**

Use a base query:

```php
return CatalogTitleUserState::query()
    ->whereBelongsTo($user)
    ->whereIn(
        'catalog_title_id',
        $this->titles->visibleTo($user)->select('id'),
    )
    ->select(['id', 'user_id', 'catalog_title_id', 'in_watchlist', 'rating', 'updated_at'])
    ->with(['catalogTitle' => fn (BelongsTo $query): BelongsTo => $query
        ->select(['id', 'slug', 'title', 'original_title', 'type', 'year', 'poster_url', 'indexed_at'])
        ->with($this->taxonomies->cardSummaryLoads())
        ->withCount($this->titles->publicCardCounts($user))])
    ->latest('updated_at')
    ->latest('id');
```

Watchlist adds `where('in_watchlist', true)`; ratings adds `whereNotNull('rating')`; both paginate 1–50 and preserve query string.

- [ ] **Step 4: Implement controller/resource/routes**

Resource nests `TitleCardResource` plus personal state only. Register `GET /api/v1/me/watchlist` and `GET /api/v1/me/ratings` under `auth:sanctum,abilities:mobile:read`, without `verified.api`.

- [ ] **Step 5: Run GREEN and commit**

```bash
php artisan test tests/Feature/Api/V1/UserLibraryTest.php
./vendor/bin/pint --dirty --format agent
git status --short --branch
git add app/Services/Catalog/Api/V1/UserLibraryQuery.php app/Http/Controllers/Api/V1/UserLibraryController.php app/Http/Requests/Api/V1/UserLibraryIndexRequest.php app/Http/Resources/Api/V1/UserLibraryItemResource.php routes/api.php tests/Feature/Api/V1/UserLibraryTest.php
git commit -m "feat: expose mobile watchlist and ratings"
```

---

### Task 3: Add Continue Watching and Viewing History

**Files:**
- Create: `app/Http/Controllers/Api/V1/ViewingActivityController.php`
- Create: `app/Http/Requests/Api/V1/ViewingHistoryIndexRequest.php`
- Create: `app/Http/Resources/Api/V1/ContinueWatchingResource.php`
- Create: `app/Http/Resources/Api/V1/ViewingHistoryResource.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Api/V1/ViewingActivityTest.php`

**Interfaces:**
- Consumes `CatalogViewingActivityQuery::continueWatching()` and `history()`.
- Consumes `CatalogViewingActivityService::clear()`; single remove is resolved owner-first in API controller.

- [ ] **Step 1: Write failing activity tests**

Use real `EpisodeViewProgress` fixtures. Assert Continue Watching selects current vs next action exactly like web, max limit 24, and omits another user. Assert history page size 1–48, stable ordering, accessibility flag, title/season/episode data and progress timestamps. Assert deleting another user's progress id is 404 and unchanged; own delete/clear removes only current user's activity.

- [ ] **Step 2: Run RED**

```bash
php artisan test tests/Feature/Api/V1/ViewingActivityTest.php
```

Expected: FAIL with 404.

- [ ] **Step 3: Implement activity Resources**

Continue Resource maps DTO fields:

```php
return [
    'action' => $this->resource->actionType,
    'label' => $this->resource->actionLabel,
    'position_seconds' => $this->resource->positionSeconds,
    'progress_percent' => $this->resource->progressPercent,
    'title' => new TitleCardResource($this->resource->title),
    'episode' => new EpisodeResource($this->resource->episode),
];
```

Add `public int $positionSeconds` to `CatalogContinueWatchingItem` immediately before `progressPercent`, and pass the already computed `$position` from the single constructor call in `CatalogViewingActivityQuery`. Cover existing web rendering before commit.

History Resource explicitly returns progress id/position/duration/percent/completed/first_started/last_watched/is_accessible and nested safe title/season/episode summaries.

- [ ] **Step 4: Implement controller owner boundaries**

`continueWatching()` returns Resource collection with validated `limit`. `history()` returns paginated Resource. `destroy()` resolves:

```php
$progress = EpisodeViewProgress::query()
    ->whereBelongsTo($request->user())
    ->whereKey($progressId)
    ->firstOrFail();

Gate::forUser($request->user())->authorize('delete', $progress);
$progress->delete();
```

`clear()` delegates to existing service and returns 204.

- [ ] **Step 5: Register routes and run GREEN**

Register `GET /api/v1/me/continue-watching` and `GET /api/v1/me/history` under `auth:sanctum,abilities:mobile:read`. Register `DELETE /api/v1/me/history/{episodeViewProgress}` and `DELETE /api/v1/me/history` under `auth:sanctum,abilities:mobile:write,verified.api`, because history mutation is prohibited before reverification.

Run:

```bash
php artisan test tests/Feature/Api/V1/ViewingActivityTest.php tests/Feature/CatalogPageTest.php --filter='viewing_history|continue_watching'
```

Expected: PASS.

- [ ] **Step 6: Commit**

Run Pint and commit:

```bash
git commit -m "feat: expose mobile viewing activity"
```

---

### Task 4: Harden, document, and verify user state

**Files:**
- Modify: `resources/api/openapi.json`
- Modify: `tests/Feature/Api/V1/UserTitleStateTest.php`
- Modify: `tests/Feature/Api/V1/UserLibraryTest.php`
- Modify: `tests/Feature/Api/V1/ViewingActivityTest.php`
- Modify: `docs/api.md`
- Modify: `docs/architecture.md`
- Modify: `docs/authorization.md`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/testing.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Produces complete private-state contract consumed by playback/progress.

- [ ] **Step 1: Add full user-isolation and cache matrix**

For every `/me` endpoint test guest 401, invalid token 401, owner success, other-user isolation, unverified read, unverified mutation `email_not_verified`, and `private, no-store` without ETag. Assert response never contains email/password/token/user_id/source URL.

- [ ] **Step 2: Add constant query-delta tests**

Measure watchlist/history at 1 and 20 items with `DB::enableQueryLog()` and assert the larger fixture does not add per-row queries. Fix only query service eager loads; Resources remain query-free.

- [ ] **Step 3: Expand OpenAPI and owner docs**

Document desired-state PUT/DELETE semantics, rating range, read-vs-verified-write boundary, Continue Watching action enum, history pagination/deletion and one-profile ownership.

- [ ] **Step 4: Verify focused and full suites**

```bash
php artisan project:docs-refresh --check
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/Api/V1 tests/Feature/CatalogPageTest.php tests/Feature/SecurityHardeningTest.php
php artisan test
```

Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git status --short --branch
git add resources/api/openapi.json tests/Feature/Api/V1 docs/api.md docs/architecture.md docs/authorization.md docs/DATA_RELATIONS.md docs/testing.md README.md CHANGELOG.md
git commit -m "test: harden mobile user state API"
```
