# Mobile API v1 Public Catalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Реализовать публичные `/api/v1` home, filters, directories, full title search, suggestions, title detail, seasons/episodes, recommendations и reviews с web-filter parity и безопасной optional Sanctum personalization.

**Architecture:** V1 получает собственные Form Requests и Resources, но передаёт критерии в существующие catalog query/page-builder services. Optional Sanctum middleware разрешает один route обслуживать гостя и Bearer-user; invalid Bearer не деградирует в гостя. Public responses остаются shared-cacheable только для реально анонимного запроса.

**Tech Stack:** PHP 8.5, Laravel 13.19, Sanctum, Eloquent/API Resources, SQLite FTS/catalog services, PHPUnit 12.5.

## Global Constraints

- Сначала выполнить `2026-07-14-mobile-api-v1-foundation.md`.
- Работать только на `main`; legacy API routes/resources не заменять v1 классами.
- Все входные фильтры нормализовать и валидировать до query; массивы с индексами и без них поддерживать одинаково.
- `per_page` v1: integer 1–50, default 20.
- Использовать `visibleTo()`/`availableTo()` и existing entitlement boundaries для guest/authenticated audience.
- Resources не выполняют queries; relations/counts eager-load в query services.
- Raw source/media/importer поля, algorithm weights и source review identity никогда не сериализуются.
- Публичный anonymous GET может быть cached; Authorization/cookie/user/error — `private, no-store`.
- Все новые маршруты вставлять перед named `api.fallback`, который остаётся последним statement в `routes/api.php`.
- TDD и небольшие commits обязательны.

---

### Task 1: Add optional Sanctum and full-filter title listing

**Files:**
- Create: `app/Http/Middleware/ResolveOptionalSanctumUser.php`
- Modify: `bootstrap/app.php`
- Create: `app/Http/Requests/Api/V1/CatalogTitleIndexRequest.php`
- Create: `app/Services/Catalog/Api/V1/CatalogTitleIndexQuery.php`
- Create: `app/Http/Controllers/Api/V1/CatalogTitleController.php`
- Create: `app/Http/Resources/Api/V1/TitleCardResource.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/Api/V1/CatalogTitleIndexTest.php`

**Interfaces:**
- Produces middleware alias `auth.optional.sanctum`.
- Produces `CatalogTitleIndexQuery::paginate(CatalogTitleIndexRequest $request): LengthAwarePaginator`.
- Produces route `GET /api/v1/titles`, name `api.v1.titles.index`.

- [ ] **Step 1: Write failing filter-parity and optional-auth tests**

Create `CatalogTitleIndexTest` using `RefreshDatabase`. Build public/hidden/authenticated fixtures with factories and taxonomy pivots. Add these focused methods:

```php
public function test_v1_titles_support_indexed_and_unindexed_filter_arrays(): void
{
    $turkey = Country::query()->create(['name' => 'Турция', 'slug' => 'turciia']);
    $matching = CatalogTitle::factory()->create(['slug' => 'turkish-title']);
    $other = CatalogTitle::factory()->create(['slug' => 'other-title']);
    $matching->countries()->attach($turkey);

    foreach (['country[]=turciia', 'country[0]=turciia'] as $query) {
        $this->getJson('/api/v1/titles?'.$query)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $matching->slug)
            ->assertJsonMissing(['slug' => $other->slug]);
    }
}

public function test_v1_titles_apply_the_complete_validated_filter_contract(): void
{
    $this->getJson('/api/v1/titles?'.http_build_query([
        'q' => 'API сериал',
        'year' => [2024],
        'year_from' => 2020,
        'year_to' => 2025,
        'seasons_min' => 1,
        'episodes_max' => 100,
        'rating_source' => 'imdb',
        'rating_min' => 7.5,
        'votes_min' => 100,
        'video' => 'available',
        'subtitles' => ['available'],
        'quality' => ['1080p'],
        'publication_type' => ['serial'],
        'updated' => 'month',
        'letter' => 'А',
        'sort' => 'year_desc',
        'per_page' => 20,
    ]))->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
}

public function test_v1_title_list_rejects_invalid_bearer_and_personalizes_authenticated_audience(): void
{
    $user = User::factory()->create();
    $authenticatedTitle = CatalogTitle::factory()->create(['audience' => 'authenticated']);

    $this->getJson('/api/v1/titles')->assertJsonMissing(['slug' => $authenticatedTitle->slug]);
    $this->withToken('invalid-token')->getJson('/api/v1/titles')->assertUnauthorized();
    Sanctum::actingAs($user, ['mobile:read']);
    $this->getJson('/api/v1/titles')->assertJsonFragment(['slug' => $authenticatedTitle->slug]);
}
```

Add validation assertions for `page=0`, `per_page=51`, invalid ranges, conflicting included/excluded slug, invalid sort/letter/quality, and more than 20 repeated selections.

- [ ] **Step 2: Run RED**

Run:

```bash
php artisan test tests/Feature/Api/V1/CatalogTitleIndexTest.php
```

Expected: FAIL with 404 for `/api/v1/titles`.

- [ ] **Step 3: Implement optional Sanctum middleware**

Create `ResolveOptionalSanctumUser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class ResolveOptionalSanctumUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null) {
            return $next($request);
        }

        $user = Auth::guard('sanctum')->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        if (! $user->tokenCan('mobile:read')) {
            abort(403, 'Токен не разрешает чтение мобильного API.');
        }

        Auth::setUser($user);
        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
```

Register alias in `bootstrap/app.php`:

```php
'auth.optional.sanctum' => ResolveOptionalSanctumUser::class,
```

Apply this middleware to every public v1 catalog/config/discovery route created by foundation and this plan. A valid token without `mobile:read` produces 403; an invalid token produces 401 instead of silently becoming a guest. Keep `/api/v1/health` token-agnostic and no-store.

- [ ] **Step 4: Implement v1 request by extending the proven web contract**

Create `CatalogTitleIndexRequest extends CatalogTitlesRequest`. Override `rules()` to call parent, remove web-only `view`, `title`, `type`, `taxonomy`, replace `per_page` with `['sometimes', 'integer', 'min:1', 'max:50']`, and add `page`. Override:

```php
public function perPage(): int
{
    return $this->integer('per_page', (int) config('mobile-api.default_per_page', 20));
}

public function view(): string
{
    return 'grid';
}
```

Do not duplicate normalization methods from `CatalogTitlesRequest`.

- [ ] **Step 5: Implement the query/controller/resource**

`CatalogTitleIndexQuery` injects `CatalogTitlesPageBuilder`; its `paginate()` calls:

```php
$page = $this->pages->data($request, includeFacets: false);

return $page['titles'];
```

`TitleCardResource` returns only `id`, `slug`, display titles, `type`, `year`, bounded description, poster, indexed timestamp, card counts, loaded summary taxonomies, and v1/web links. Use `whenLoaded()` and `whenCounted()` exactly like existing resources.

Controller:

```php
public function index(
    CatalogTitleIndexRequest $request,
    CatalogTitleIndexQuery $titles,
): AnonymousResourceCollection {
    return TitleCardResource::collection($titles->paginate($request));
}
```

Register the route inside v1 public group with middleware order. Use a raw `{titleSlug}` parameter for optional-auth title routes and resolve the title inside the query service after middleware has populated `$request->user()`; do not rely on implicit binding before optional auth.

```php
Route::middleware(['auth.optional.sanctum', 'public.cache:api'])
    ->get('/titles', [CatalogTitleController::class, 'index'])
    ->name('api.v1.titles.index');
```

- [ ] **Step 6: Run GREEN and web-filter regression**

Run:

```bash
php artisan test tests/Feature/Api/V1/CatalogTitleIndexTest.php tests/Feature/CatalogFilterQueryTest.php tests/Feature/ApiCatalogTitleTest.php
```

If `CatalogFilterQueryTest.php` is not the actual filename, use `rg -l 'year_from|exclude_country' tests/Feature` and run every returned catalog filter test file. Expected: v1 and existing web/API tests PASS.

- [ ] **Step 7: Commit**

Run:

```bash
./vendor/bin/pint --dirty --format agent
git status --short --branch
git add app/Http/Middleware/ResolveOptionalSanctumUser.php bootstrap/app.php app/Http/Requests/Api/V1/CatalogTitleIndexRequest.php app/Services/Catalog/Api/V1/CatalogTitleIndexQuery.php app/Http/Controllers/Api/V1/CatalogTitleController.php app/Http/Resources/Api/V1/TitleCardResource.php routes/api.php tests/Feature/Api/V1/CatalogTitleIndexTest.php
git commit -m "feat: expose full mobile catalog filtering"
```

---

### Task 2: Add home, filter schema, and directory endpoints

**Files:**
- Create: `app/Http/Controllers/Api/V1/CatalogHomeController.php`
- Create: `app/Http/Controllers/Api/V1/CatalogFilterSchemaController.php`
- Create: `app/Http/Controllers/Api/V1/CatalogDirectoryController.php`
- Create: `app/Http/Requests/Api/V1/CatalogDirectoryIndexRequest.php`
- Create: `app/Http/Resources/Api/V1/CatalogHomeResource.php`
- Create: `app/Http/Resources/Api/V1/CatalogDirectoryResource.php`
- Create: `app/Http/Resources/Api/V1/CatalogDirectoryItemResource.php`
- Create: `app/Http/Resources/Api/V1/LatestReleaseResource.php`
- Modify: `app/Services/Catalog/CatalogDirectoryQuery.php`
- Modify: `routes/api.php`
- Modify: `resources/api/openapi.json`
- Create: `tests/Feature/Api/V1/CatalogDiscoveryTest.php`

**Interfaces:**
- Produces `GET /api/v1/home`, `/catalog/filters`, `/catalog/directories`, `/catalog/directories/{directory}`.
- Extends `CatalogDirectoryQuery::paginate(..., ?int $total = null, ?int $perPage = null)` without changing web defaults.

- [ ] **Step 1: Write failing discovery tests**

Assert:

```php
$this->getJson('/api/v1/catalog/filters')
    ->assertOk()
    ->assertJsonPath('data.alphabet.latin.0', 'A')
    ->assertJsonPath('data.alphabet.latin.25', 'Z')
    ->assertJsonPath('data.alphabet.cyrillic.0', 'А')
    ->assertJsonPath('data.alphabet.other.0', '#')
    ->assertJsonFragment(['value' => 'year_desc']);

$this->getJson('/api/v1/catalog/directories/actors?letter=A&sort=count_desc&per_page=10')
    ->assertOk()
    ->assertJsonStructure(['data', 'links', 'meta' => ['alphabet' => ['cyrillic', 'latin', 'other']]]);
```

Home test creates one item per section, asserts bounded public cards, and asserts raw media/source strings are absent.

- [ ] **Step 2: Run RED**

Run:

```bash
php artisan test tests/Feature/Api/V1/CatalogDiscoveryTest.php
```

Expected: FAIL with 404.

- [ ] **Step 3: Make directory pagination accept v1 page size**

Extend the method signature:

```php
public function paginate(
    CatalogDirectoryDefinition $directory,
    string $search,
    string $letter,
    string $sort,
    ?int $decade,
    ?int $total = null,
    ?int $perPage = null,
): LengthAwarePaginator
```

Inside, use:

```php
$perPage = max(1, min(50, $perPage ?? $directory->perPage));
$items = $query->forPage($page, $perPage)->get();

return new LaravelLengthAwarePaginator($items, $total, $perPage, $page, [
    'path' => request()->url(),
    'query' => request()->query(),
    'pageName' => 'page',
]);
```

Existing web call omits the new argument and retains configured sizes.

- [ ] **Step 4: Implement directory validation and Resources**

`CatalogDirectoryIndexRequest` validates `q` string max 80, `letter` one Cyrillic/Latin letter or `#`, `sort` in `name_asc,count_desc`, `decade` four-digit multiple of 10, `page>=1`, `per_page=1..50`; it exposes typed accessors.

`CatalogDirectoryController::index()` returns registry definitions. `show()` resolves `$directories->find($directory)`, aborts 404 when null, calls `CatalogDirectoryQuery::paginate()` with validated values, adds `CatalogAlphabet::availableGroups($query->letters($definition))` and summary to collection `additional(['meta' => ...])` without overwriting Laravel pagination meta.

Directory item Resource returns:

```php
$slug = data_get($this->resource, 'slug');
$year = data_get($this->resource, 'year');

return [
    'id' => (int) data_get($this->resource, 'id'),
    'name' => (string) data_get($this->resource, 'name'),
    'slug' => is_string($slug) ? $slug : null,
    'year' => is_numeric($year) ? (int) $year : null,
    'titles_count' => (int) data_get($this->resource, 'published_titles_count', 0),
];
```

- [ ] **Step 5: Implement filter schema and home Resources**

Filter controller maps `CatalogSort::cases()`, `CatalogPublicationType::cases()`, `CatalogFilterType::cases()`, `CatalogAlphabet::titleGroups()`, rating/video/subtitle/updated options, configured qualities, and exact numeric bounds. Rename alphabet `symbols` to API key `other`.

Home controller injects `CatalogHomePageBuilder`, passes its `data()` to `CatalogHomeResource`, and Resource maps each loaded section through `TitleCardResource`/`LatestReleaseResource`. It must omit SEO, storage/source fields and internal cache metadata.

- [ ] **Step 6: Register routes and expand OpenAPI**

Add all four GETs under `['auth.optional.sanctum', 'public.cache:api']`; use route constraint:

```php
->whereIn('directory', array_keys(CatalogDirectoryRegistry::routeMap()))
```

Add schemas/parameters/operationIds to `resources/api/openapi.json`.

- [ ] **Step 7: Run GREEN and web-directory regression**

Run:

```bash
php artisan test tests/Feature/Api/V1/CatalogDiscoveryTest.php tests/Feature/CatalogDirectoryPageTest.php tests/Feature/CatalogPageTest.php --filter='home|directory'
```

Expected: PASS in `CatalogPageTest.php`, which owns current directory route behavior.

- [ ] **Step 8: Commit**

Run Pint, inspect `main`, stage the files above, and commit:

```bash
git commit -m "feat: expose mobile catalog discovery"
```

---

### Task 3: Add title detail, seasons, episodes, and safe media profiles

**Files:**
- Create: `app/Services/Catalog/Api/V1/CatalogTitleDetailQuery.php`
- Create: `app/Http/Resources/Api/V1/CatalogTitleResource.php`
- Create: `app/Http/Resources/Api/V1/CatalogRatingResource.php`
- Create: `app/Http/Resources/Api/V1/SeasonResource.php`
- Create: `app/Http/Resources/Api/V1/EpisodeResource.php`
- Create: `app/Http/Resources/Api/V1/MediaProfileResource.php`
- Modify: `app/Http/Controllers/Api/V1/CatalogTitleController.php`
- Modify: `routes/api.php`
- Modify: `resources/api/openapi.json`
- Create: `tests/Feature/Api/V1/CatalogTitleDetailTest.php`

**Interfaces:**
- Produces `CatalogTitleDetailQuery::title(CatalogTitle $title, ?User $user): CatalogTitle`.
- Produces `seasons()`, `episodes()` that enforce selected-title ownership.

- [ ] **Step 1: Write failing nested-resource and privacy tests**

Create two titles, each with season/episode/media. Assert:

```php
$this->getJson('/api/v1/titles/api-title')
    ->assertOk()
    ->assertJsonPath('data.slug', 'api-title')
    ->assertJsonStructure(['data' => ['aliases', 'ratings', 'taxonomies', 'counts', 'primary_action']])
    ->assertDontSee('playback_url', false)
    ->assertDontSee('source_url', false)
    ->assertDontSee('storage_disk', false);

$this->getJson("/api/v1/titles/api-title/seasons/{$foreignSeason->id}/episodes")
    ->assertNotFound();
```

Assert hidden/future/deleted releases are missing and authenticated audience appears only with valid token.

- [ ] **Step 2: Run RED**

Run:

```bash
php artisan test tests/Feature/Api/V1/CatalogTitleDetailTest.php
```

Expected: FAIL with 404.

- [ ] **Step 3: Implement detail query boundary**

Inject `CatalogTitlePageBuilder`, `CatalogTitlePlaybackQuery`, `CatalogPrimaryActionResolver`, and `CatalogUserStateService`. `title()` resolves the current title through `CatalogTitleQuery::visibleTo($user)`, loads aliases/ratings/taxonomy summaries, counts, and sets presentation relations/attributes for primary action and aggregate summary. `seasons()` delegates to `seasonSummaries()`. `episodes()` first finds the season through `$title->seasons()->availableTo($user)->whereKey($seasonId)->firstOrFail()`, then delegates to `episodesForSeason()` so foreign season ids cannot cross the parent.

- [ ] **Step 4: Implement Resources with no lazy loading**

`CatalogTitleResource` maps only preloaded values, including nullable current `user_state` when an optional Bearer user exists. `MediaProfileResource` returns:

```php
return [
    'id' => $this->id,
    'translation' => $this->translation_name,
    'variant' => $this->variant_name,
    'variant_key' => $this->variant_key,
    'quality' => $this->quality,
    'format' => $this->format,
    'duration_seconds' => $this->duration_seconds,
];
```

It must not expose model attributes by spreading `toArray()`. Season/Episode resources explicitly enumerate ids, kind/number/title/dates/summary/counts and loaded media profiles.

- [ ] **Step 5: Register controllers/routes and OpenAPI**

Add `show`, `seasons`, `episodes` methods returning Resources. Routes use raw validated `titleSlug`, numeric season constraint, optional Sanctum, public cache, and names `api.v1.titles.show`, `.seasons`, `.episodes`; `CatalogTitleDetailQuery` resolves the slug through `visibleTo($request->user())` after optional authentication.

- [ ] **Step 6: Run GREEN plus entitlement/privacy regressions**

Run:

```bash
php artisan test tests/Feature/Api/V1/CatalogTitleDetailTest.php tests/Feature/ApiCatalogTitleTest.php tests/Feature/SecurityHardeningTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

Run Pint, inspect/stage exact task files, commit:

```bash
git commit -m "feat: expose mobile title release details"
```

---

### Task 4: Add suggestions, recommendations, and imported reviews

**Files:**
- Create: `app/Http/Requests/Api/V1/SearchSuggestionRequest.php`
- Create: `app/Http/Controllers/Api/V1/SearchSuggestionController.php`
- Create: `app/Http/Controllers/Api/V1/CatalogRecommendationController.php`
- Create: `app/Http/Controllers/Api/V1/CatalogReviewController.php`
- Create: `app/Http/Requests/Api/V1/CatalogReviewIndexRequest.php`
- Create: `app/Services/Catalog/Api/V1/CatalogRecommendationQuery.php`
- Create: `app/Services/Catalog/Api/V1/CatalogReviewQuery.php`
- Create: `app/Http/Resources/Api/V1/SearchSuggestionResource.php`
- Create: `app/Http/Resources/Api/V1/CatalogRecommendationResource.php`
- Create: `app/Http/Resources/Api/V1/CatalogReviewResource.php`
- Modify: `routes/api.php`
- Modify: `resources/api/openapi.json`
- Create: `tests/Feature/Api/V1/CatalogRelatedContentTest.php`

**Interfaces:**
- Produces `GET /api/v1/search/suggestions`, `GET /api/v1/titles/{titleSlug}/recommendations`, and `GET /api/v1/titles/{titleSlug}/reviews`.

- [ ] **Step 1: Write failing related-content tests**

Assert query length 2–80, max bounded suggestions, recommendations ordered by rank and containing reason labels, reviews paginated and containing only id/author/body/published_at. Seed source id/hash/raw URL and assert none appears in response.

- [ ] **Step 2: Run RED**

```bash
php artisan test tests/Feature/Api/V1/CatalogRelatedContentTest.php
```

Expected: FAIL with 404.

- [ ] **Step 3: Implement suggestion orchestration**

Parse normalized query with `CatalogSearchQueryParser`; return exact/FTS title candidates from existing search query plus `CatalogPeopleLookup` actor/director results. Merge into a bounded list with stable `type`, `label`, `slug`, `title_slug` and `count`; never return search score/index state. Limit title suggestions to 5 and people options to 5 per type.

- [ ] **Step 4: Implement recommendation/review queries**

Recommendation query copies the visibility-safe relation ordering already used by `CatalogTitlePageBuilder`: `whereHas('recommendedTitle', constrainVisible)`, `orderBy('rank')`, `orderByDesc('score')`, configured max, eager-load card summaries. Resource returns `rank`, `reasons`, and `title`; omit numeric score/breakdown/signals.

Review query:

```php
return $title->reviews()
    ->select(['id', 'catalog_title_id', 'author', 'body', 'published_at'])
    ->latest('published_at')
    ->latest('id')
    ->paginate($request->perPage())
    ->withQueryString();
```

- [ ] **Step 5: Register routes/OpenAPI and run GREEN**

Register all three GET routes under `auth.optional.sanctum,public.cache:api`. Use raw `{titleSlug}` strings for recommendation/review routes and resolve them through `visibleTo($request->user())` after optional authentication. Name them `api.v1.search.suggestions`, `api.v1.titles.recommendations`, and `api.v1.titles.reviews`; add the exact operations and schemas to OpenAPI.

Run:

```bash
php artisan test tests/Feature/Api/V1/CatalogRelatedContentTest.php tests/Feature/CatalogPageTest.php --filter='recommendation'
```

Expected: PASS.

- [ ] **Step 6: Commit**

Run Pint, inspect/stage task files, commit:

```bash
git commit -m "feat: expose mobile catalog related content"
```

---

### Task 5: Enforce public-catalog privacy, query budgets, docs, and full regressions

**Files:**
- Modify: `tests/Feature/Api/V1/CatalogTitleIndexTest.php`
- Modify: `tests/Feature/Api/V1/CatalogDiscoveryTest.php`
- Modify: `tests/Feature/Api/V1/CatalogTitleDetailTest.php`
- Modify: `tests/Feature/Api/V1/CatalogRelatedContentTest.php`
- Modify: `tests/Feature/PublicHttpCacheHeadersTest.php`
- Modify: `docs/api.md`
- Modify: `docs/architecture.md`
- Modify: `docs/catalog-search.md`
- Modify: `docs/caching.md`
- Modify: `docs/testing.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Produces the complete documented public v1 contract consumed by auth/user/playback plans.

- [ ] **Step 1: Add one exhaustive sensitive-field test**

Build a title graph containing source URLs/hashes, import ids, local paths, provider URLs, failed media health values, recommendation breakdown and review source identity. Request every public v1 endpoint and assert serialized content excludes each unique secret marker.

- [ ] **Step 2: Add query-budget and cache assertions**

Use `DB::enableQueryLog()` around representative 1-card and 20-card index, title detail, directory and home requests. Assert the 20-item fixture executes at most two more queries than the 1-item fixture, which detects N+1 without a fragile absolute total. Assert guest GET has public ETag/304 and Sanctum GET is private/no-store with no validators.

- [ ] **Step 3: Run RED then remove discovered lazy loads/excess queries**

Run all v1 public tests. For any failing constant-delta test, move missing relations/counts into the corresponding query service; do not call `loadMissing()` from Resource classes. Re-run until GREEN.

- [ ] **Step 4: Update OpenAPI and owner docs**

Document every implemented query parameter, array syntax, separate alphabet groups, response schemas, caching, optional Bearer behavior and sensitive-field exclusions. Update OpenAPI operationIds/examples and ensure all v1 route paths appear.

- [ ] **Step 5: Verify focused and full suites**

Run:

```bash
php artisan project:docs-refresh --check
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/Api/V1 tests/Feature/ApiCatalogTitleTest.php tests/Feature/PublicHttpCacheHeadersTest.php tests/Feature/CatalogPageTest.php tests/Feature/SecurityHardeningTest.php
php artisan test
```

Expected: all PASS.

- [ ] **Step 6: Commit public API documentation and hardening**

Run:

```bash
git status --short --branch
git add tests/Feature/Api/V1 tests/Feature/PublicHttpCacheHeadersTest.php resources/api/openapi.json docs/api.md docs/architecture.md docs/catalog-search.md docs/caching.md docs/testing.md README.md CHANGELOG.md
git commit -m "test: harden mobile public catalog API"
```
