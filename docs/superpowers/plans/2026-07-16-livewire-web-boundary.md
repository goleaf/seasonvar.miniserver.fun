# Livewire Web Boundary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Перевести все HTML web routes Seasonvar на full-page Livewire, удалить прикладные non-API контроллеры и сохранить неизменными API, redirects, XML, JSON, file и streaming contracts.

**Architecture:** HTML routes указывают непосредственно на class-based Livewire-компоненты и повторно используют существующие page builders, queries и policies. Non-HTML routes вызывают доменные responders из `app/Services/*`; они не рендерят Blade и возвращают типизированные Laravel/Symfony responses. `Controller.php` и `Controllers/Api/**` сохраняются, а структурный тест запрещает возвращение других контроллеров.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3.3, Blade, SQLite, PHPUnit 12.5, Laravel Pint 1.29, Tailwind CSS 4.3, Vite 8.

## Global Constraints

- Работать только в существующей ветке `main`; не создавать ветки и worktree.
- API в `routes/api.php`, API Resources и JSON contracts не изменять.
- Новые HTML web routes реализовывать только как full-page Livewire.
- XML, plain text, JSON health-check, images, attachments, redirects, signed playback и streamed downloads не пропускать через Livewire.
- Сохранить URL, route names, methods, middleware, constraints, scoped bindings, statuses, headers, content types, SEO и cache policy.
- Сохранить bounded-buffer передачу прямого видео без storage/cache/database copy.
- Видимый текст и документацию писать по-русски; не добавлять вымышленный контент.
- Не выполнять queries в Blade и не ослаблять policies/Gates.
- После PHP-правок запускать `./vendor/bin/pint --dirty --format agent`.
- Каждый product commit обязан содержать осмысленное изменение `README.md`; управляемые `project-docs` блоки вручную не редактировать.

---

## File Structure

**Новые Livewire-компоненты**

- `app/Livewire/CatalogHomePage.php` — full-page владелец главной.
- `app/Livewire/GlobalSearchPage.php` — full-page владелец `/search` и localized alias.
- `app/Livewire/CatalogTopListPage.php` — full-page владелец top-100.

**Расширяемые Livewire-компоненты**

- `app/Livewire/CatalogTitleDetail.php` — route binding, canonical slug и page SEO.
- `app/Livewire/StatsDashboard.php` — page SEO/layout поверх snapshot.
- `app/Livewire/Collections/CatalogCollectionPage.php` — slug resolution, canonical redirect, SEO/layout.

**Transport responders**

- `app/Services/Auth/AccountDataExportResponder.php`
- `app/Services/Auth/AccountEmailVerificationResponder.php`
- `app/Services/Auth/AnonymousPreferencesMigrationResponder.php`
- `app/Services/Catalog/CatalogDirectoryRedirectResponder.php`
- `app/Services/Catalog/CatalogPlaybackSourceResponder.php`
- `app/Services/Operations/InfrastructureHealthResponder.php`
- `app/Services/Collections/CatalogCollectionCoverResponder.php`
- `app/Services/Collections/CatalogCollectionLegacyRedirectResponder.php`
- `app/Services/Comments/CommentDirectLinkResponder.php`
- `app/Services/Media/LicensedMediaDownloadResponder.php`
- `app/Services/Profiles/UserProfileMediaResponder.php`
- `app/Services/Reviews/ReviewDirectLinkResponder.php`
- `app/Services/TechnicalIssues/TechnicalIssueAttachmentResponder.php`

**Response middleware**

- `app/Http/Middleware/CatalogCollectionResponseHeaders.php` — private headers и динамический `X-Robots-Tag` full-page collection response.

**Tests**

- `tests/Feature/LivewireWebBoundaryTest.php` — route ownership и controller inventory.
- Текущие `CatalogPageTest`, `GlobalSearchPageTest`, `CatalogTopListPageTest`, collection, media, auth, sitemap и profile tests остаются поведенческими источниками истины.

---

### Task 1: Full-page главная и глобальный поиск

**Files:**
- Create: `app/Livewire/CatalogHomePage.php`
- Create: `app/Livewire/GlobalSearchPage.php`
- Create: `resources/views/livewire/catalog-home-page.blade.php`
- Delete: `resources/views/catalog/index.blade.php`
- Modify: `resources/views/search/index.blade.php`
- Modify: `routes/web.php`
- Delete: `app/Http/Controllers/GlobalSearchController.php`
- Test: `tests/Feature/CatalogPageTest.php`
- Test: `tests/Feature/GlobalSearchPageTest.php`
- Modify: `README.md`

**Interfaces:**
- Consumes: `CatalogHomePageBuilder::data(?User $user): array`, `GlobalSearchRequest::queryValue(): string`, `GlobalSearchPageQuery::search(string $query, ?User $user): array`.
- Produces: `CatalogHomePage::render(): View`, `GlobalSearchPage::mount(GlobalSearchRequest $request): void`, `GlobalSearchPage::render(GlobalSearchPageQuery $search): View`.

- [ ] **Step 1: Write failing route/component tests**

Add these assertions to the existing feature tests:

```php
use App\Livewire\CatalogHomePage;
use App\Livewire\GlobalSearchPage;
use Livewire\Livewire;

public function test_home_route_is_owned_by_full_page_livewire(): void
{
    $this->assertSame(CatalogHomePage::class, Route::getRoutes()->getByName('home')?->getActionName());
    $this->get(route('home'))->assertOk()->assertSee('wire:snapshot', false);
}

public function test_global_search_route_is_owned_by_full_page_livewire(): void
{
    $this->assertSame(GlobalSearchPage::class, Route::getRoutes()->getByName('search.index')?->getActionName());

    Livewire::withQueryParams(['q' => '  тест  '])
        ->test(GlobalSearchPage::class)
        ->assertSet('query', 'тест')
        ->assertSee('тест');
}
```

- [ ] **Step 2: Run RED tests**

Run:

```bash
php artisan test --filter='CatalogPageTest|GlobalSearchPageTest'
```

Expected: FAIL because the named routes still point to `CatalogController` and `GlobalSearchController`, and the new component classes do not exist.

- [ ] **Step 3: Implement `CatalogHomePage`**

Create:

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Services\Catalog\CatalogHomePageBuilder;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class CatalogHomePage extends Component
{
    public function render(CatalogHomePageBuilder $page): View
    {
        $user = auth()->user();
        $data = $page->data($user instanceof User ? $user : null);

        return view('livewire.catalog-home-page', $data)
            ->extends('layouts.app', [
                'title' => $data['seo']['title'] ?? __('home.title'),
                'seo' => $data['seo'] ?? [],
            ])
            ->section('content');
    }
}
```

Move `resources/views/catalog/index.blade.php` to `resources/views/livewire/catalog-home-page.blade.php`. Remove only `@extends`, `@section('content')` and the matching final `@endsection`; retain its single root `<div>` and all existing markup.

- [ ] **Step 4: Implement `GlobalSearchPage`**

Create:

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Http\Requests\GlobalSearchRequest;
use App\Services\Catalog\Search\GlobalSearchPageQuery;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class GlobalSearchPage extends Component
{
    #[Locked]
    public string $query = '';

    public function mount(GlobalSearchRequest $request): void
    {
        $this->query = $request->queryValue();
    }

    public function render(GlobalSearchPageQuery $search): View
    {
        $results = $search->search($this->query, auth()->user());
        $routeLocale = request()->route('locale');
        $localized = is_string($routeLocale)
            && in_array($routeLocale, (array) config('catalog-collections.supported_locales', []), true);
        $routeName = $localized ? 'localized.search.index' : 'search.index';
        $routeParameters = $localized ? ['locale' => $routeLocale] : [];
        $url = route($routeName, $routeParameters);
        $seo = [
            'title' => $this->query === ''
                ? __('catalog.global_search.title')
                : __('catalog.global_search.title_query', ['query' => $this->query]),
            'description' => __('catalog.global_search.description'),
            'canonical' => $url,
            'robots' => 'noindex,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1',
            'breadcrumbs' => [
                ['name' => __('catalog.navigation.home'), 'url' => route('home')],
                ['name' => __('catalog.global_search.title'), 'url' => $url],
            ],
        ];

        return view('search.index', [
            'query' => $this->query,
            'searchRouteName' => $routeName,
            'searchRouteParameters' => $routeParameters,
            'searchUrl' => $url,
            ...$results,
        ])->extends('layouts.app', ['title' => $seo['title'], 'seo' => $seo])->section('content');
    }
}
```

Remove the layout directives from `resources/views/search/index.blade.php`, route both default and localized search to `GlobalSearchPage::class`, and delete `GlobalSearchController.php`.

- [ ] **Step 5: Run GREEN tests and formatting**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter='CatalogPageTest|GlobalSearchPageTest'
```

Expected: PASS; both routes render Livewire snapshots and current search validation/SEO tests remain green.

- [ ] **Step 6: Update README and commit**

Add a visitor-history bullet stating that the home and global-search pages now keep their content and filters responsive through Livewire without changing public URLs.

```bash
git add app/Livewire/CatalogHomePage.php app/Livewire/GlobalSearchPage.php resources/views/catalog/index.blade.php resources/views/livewire/catalog-home-page.blade.php resources/views/search/index.blade.php routes/web.php app/Http/Controllers/GlobalSearchController.php tests/Feature/CatalogPageTest.php tests/Feature/GlobalSearchPageTest.php README.md
git status --short --branch
git commit -m "refactor: move home and search to livewire"
```

---

### Task 2: Full-page карточка тайтла и статистика

**Files:**
- Modify: `app/Livewire/CatalogTitleDetail.php`
- Modify: `app/Livewire/StatsDashboard.php`
- Modify: `routes/web.php`
- Delete: `resources/views/catalog/show.blade.php`
- Delete: `resources/views/catalog/stats.blade.php`
- Modify: `app/Http/Controllers/CatalogController.php`
- Test: `tests/Feature/CatalogPageTest.php`
- Test: `tests/Feature/CatalogLivewireBudgetTest.php`
- Modify: `README.md`

**Interfaces:**
- Consumes: `CatalogTitlePageBuilder::seo(CatalogTitle $title, ?User $user): array`, `CatalogStatsPageBuilder::seo(): array`.
- Produces: `CatalogTitleDetail::mount(CatalogTitle $catalogTitle): void`, full-page `render()` methods.

- [ ] **Step 1: Write failing tests**

```php
public function test_title_route_is_owned_by_livewire_and_preserves_canonical_redirect(): void
{
    $title = CatalogTitle::factory()->create(['slug' => 'canonical-title']);

    $this->assertSame(CatalogTitleDetail::class, Route::getRoutes()->getByName('titles.show')?->getActionName());
    $this->get('/titles/'.$title->slug)->assertOk()->assertSee('wire:snapshot', false);
}

public function test_stats_route_is_owned_by_livewire(): void
{
    $this->assertSame(StatsDashboard::class, Route::getRoutes()->getByName('stats')?->getActionName());
    $this->get(route('stats'))->assertOk()->assertSee('wire:snapshot', false);
}
```

- [ ] **Step 2: Run RED test**

```bash
php artisan test --filter='CatalogPageTest|CatalogLivewireBudgetTest'
```

Expected: FAIL because both page routes still use `CatalogController`.

- [ ] **Step 3: Make `CatalogTitleDetail` a page component**

Change `mount()` to accept route binding and preserve the permanent canonical redirect:

```php
public function mount(CatalogTitle $catalogTitle): void
{
    $requestedSlug = request()->route()?->originalParameter('catalogTitle');

    if (is_string($requestedSlug) && $requestedSlug !== $catalogTitle->slug) {
        throw new HttpResponseException(redirect()->route('titles.show', $catalogTitle, 301));
    }

    $this->catalogTitleId = $catalogTitle->id;
    $reviewId = request()->integer('review');
    $this->highlightedReviewId = $reviewId > 0 ? $reviewId : null;
}
```

At the end of `render()`, derive `$seo` from the resolved title, apply the existing review-query `noindex` rule, and return:

```php
return view('livewire.catalog-title-detail', $data)
    ->extends('layouts.app', [
        'title' => $seo['title'] ?? $data['title']->display_title,
        'seo' => $seo,
    ])
    ->section('content');
```

- [ ] **Step 4: Make `StatsDashboard` a page component**

Inject `CatalogStatsPageBuilder` into `render()` and return:

```php
$seo = $page->seo();

return view('livewire.stats-dashboard', [
    'stats' => $snapshot['data'],
    'snapshotMeta' => $snapshot['meta'],
])->extends('layouts.app', [
    'title' => $seo['title'] ?? 'Сводка каталога',
    'seo' => $seo,
])->section('content');
```

Route `titles.show` to `CatalogTitleDetail::class`, route `stats` to `StatsDashboard::class`, delete both wrapper views, and remove `index()`, `show()` and `stats()` from `CatalogController`; leave only `statsPoster()` until the transport task.

- [ ] **Step 5: Verify and commit**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter='CatalogPageTest|CatalogLivewireBudgetTest|CatalogTitleLiveRefreshTest'
git add app/Livewire/CatalogTitleDetail.php app/Livewire/StatsDashboard.php routes/web.php resources/views/catalog/show.blade.php resources/views/catalog/stats.blade.php app/Http/Controllers/CatalogController.php tests/Feature/CatalogPageTest.php tests/Feature/CatalogLivewireBudgetTest.php README.md
git status --short --branch
git commit -m "refactor: move title and stats pages to livewire"
```

Expected: PASS and the README history entry now lists home, search, title and stats pages.

---

### Task 3: Full-page top-100 и подборка

**Files:**
- Create: `app/Livewire/CatalogTopListPage.php`
- Modify: `app/Livewire/Collections/CatalogCollectionPage.php`
- Create: `app/Http/Middleware/CatalogCollectionResponseHeaders.php`
- Modify: `bootstrap/app.php`
- Create: `resources/views/livewire/catalog-top-list-page.blade.php`
- Delete: `resources/views/catalog/top-list.blade.php`
- Modify: `routes/web.php`
- Delete: `resources/views/collections/show.blade.php`
- Delete: `app/Http/Controllers/CatalogTopListController.php`
- Modify: `app/Http/Controllers/CatalogCollectionController.php`
- Test: `tests/Feature/CatalogTopListPageTest.php`
- Test: `tests/Feature/HdRezkaCollectionPresentationTest.php`
- Modify: `README.md`

**Interfaces:**
- Consumes: `CatalogTopListRequest::filters(): CatalogTopListFilters`, `CatalogCollectionResolver::resolve(string): array`, `CatalogCollectionSeoPresenter::collection(...): array`.
- Produces: `CatalogTopListPage`, full-page `CatalogCollectionPage`, `CatalogCollectionResponseHeaders::handle(Request, Closure): Response`.

- [ ] **Step 1: Write failing ownership and response-header tests**

```php
public function test_top_route_is_full_page_livewire(): void
{
    $this->assertSame(CatalogTopListPage::class, Route::getRoutes()->getByName('top.show')?->getActionName());
}

public function test_public_collection_page_is_full_page_livewire_and_private_no_store(): void
{
    $collection = CatalogCollection::factory()->public()->create();

    $this->assertSame(CatalogCollectionPage::class, Route::getRoutes()->getByName('collections.show')?->getActionName());
    $this->get(route('collections.show', ['collectionSlug' => $collection->slug]))
        ->assertOk()
        ->assertHeader('Cache-Control', 'private, no-store, max-age=0')
        ->assertSee('wire:snapshot', false);
}
```

- [ ] **Step 2: Run RED tests**

```bash
php artisan test --filter='CatalogTopListPageTest|HdRezkaCollectionPresentationTest'
```

Expected: FAIL because route ownership still points to controllers.

- [ ] **Step 3: Implement `CatalogTopListPage`**

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\CatalogTopListFilters;
use App\Enums\CatalogTopListCategory;
use App\Http\Requests\CatalogTopListRequest;
use App\Models\User;
use App\Services\Catalog\CatalogTopListPageBuilder;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class CatalogTopListPage extends Component
{
    #[Locked] public string $categoryValue;
    #[Locked] public ?int $yearFrom = null;
    #[Locked] public ?int $yearTo = null;
    #[Locked] public ?string $country = null;
    #[Locked] public ?string $genre = null;

    public function mount(CatalogTopListRequest $request, CatalogTopListCategory $category): void
    {
        $filters = $request->filters();
        $this->categoryValue = $category->value;
        $this->yearFrom = $filters->yearFrom;
        $this->yearTo = $filters->yearTo;
        $this->country = $filters->country;
        $this->genre = $filters->genre;
    }

    public function render(CatalogTopListPageBuilder $page): View
    {
        $category = CatalogTopListCategory::from($this->categoryValue);
        $localized = is_string(request()->route('locale'));
        $user = auth()->user();
        $data = $page->data($category, $user instanceof User ? $user : null, $localized, new CatalogTopListFilters(
            yearFrom: $this->yearFrom,
            yearTo: $this->yearTo,
            country: $this->country,
            genre: $this->genre,
        ));

        return view('livewire.catalog-top-list-page', $data)
            ->extends('layouts.app', ['title' => $data['seo']['title'], 'seo' => $data['seo']])
            ->section('content');
    }
}
```

Move `resources/views/catalog/top-list.blade.php` to `resources/views/livewire/catalog-top-list-page.blade.php`, strip its layout directives and route default/localized top routes to this component.

- [ ] **Step 4: Extend `CatalogCollectionPage`**

Change `mount()` to accept `collectionSlug`, resolve historical aliases and store the public ID:

```php
public function mount(string $collectionSlug, CatalogCollectionResolver $resolver): void
{
    $this->setCollectionLocale(app()->currentLocale());
    $resolved = $resolver->resolve($collectionSlug);
    $collection = $resolved['collection'];
    Gate::authorize('view', $collection);

    if ($resolved['historical']) {
        $localized = is_string(request()->route('locale'));
        $route = $localized ? 'localized.collections.show' : 'collections.show';
        $parameters = $localized
            ? ['locale' => app()->currentLocale(), 'collectionSlug' => $collection->slug]
            : ['collectionSlug' => $collection->slug];
        throw new HttpResponseException(redirect()->route($route, $parameters, 301));
    }

    $this->collectionPublicId = $collection->public_id;
    $this->sort = $this->sort === '' ? $collection->sort_mode->value : $this->sort;
    $this->normalizeFilters();
}
```

In `render()`, build `$seo` with `CatalogCollectionSeoPresenter`, pass it to `layouts.app`, and retain all current page data and actions.

- [ ] **Step 5: Preserve collection headers**

Create middleware that resolves the collection, calls the existing presenter and sets exact headers after the component response:

```php
public function handle(Request $request, Closure $next): Response
{
    $response = $next($request);
    $slug = $request->route('collectionSlug');

    if (! is_string($slug)) {
        return $response;
    }

    $collection = $this->query->summary($this->resolver->resolve($slug)['collection']);
    $viewer = $request->user();
    $seo = $this->seo->collection(
        $collection,
        $viewer instanceof User ? $viewer : null,
        is_string($request->route('locale')),
        $request->query() !== [],
    );
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('Pragma', 'no-cache');

    if (str_starts_with((string) ($seo['robots'] ?? ''), 'noindex')) {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    return $response;
}
```

Register alias `collection.response` in `bootstrap/app.php` and apply it to both collection show routes.

- [ ] **Step 6: Verify and commit**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter='CatalogTopListPageTest|HdRezkaCollectionPresentationTest|CatalogCollection'
git add app/Livewire/CatalogTopListPage.php app/Livewire/Collections/CatalogCollectionPage.php app/Http/Middleware/CatalogCollectionResponseHeaders.php bootstrap/app.php resources/views/catalog/top-list.blade.php resources/views/livewire/catalog-top-list-page.blade.php resources/views/collections/show.blade.php routes/web.php app/Http/Controllers/CatalogTopListController.php app/Http/Controllers/CatalogCollectionController.php tests/Feature/CatalogTopListPageTest.php tests/Feature/HdRezkaCollectionPresentationTest.php README.md
git status --short --branch
git commit -m "refactor: move top lists and collections to livewire"
```

Expected: PASS; README describes all six migrated HTML surfaces.

---

### Task 4: Simple non-HTML responders

**Files:**
- Create: `app/Services/Operations/InfrastructureHealthResponder.php`
- Create: `app/Services/Catalog/CatalogPlaybackSourceResponder.php`
- Modify: `routes/web.php`
- Delete: `app/Http/Controllers/InfrastructureHealthController.php`
- Delete: `app/Http/Controllers/PlaybackSourceController.php`
- Delete: `app/Http/Controllers/CatalogSitemapController.php`
- Delete: `app/Http/Controllers/CatalogController.php`
- Test: `tests/Feature/CatalogSitemapTest.php`
- Test: `tests/Unit/InfrastructureHealthCheckTest.php`
- Test: `tests/Feature/PlaybackSourceTest.php`
- Modify: `README.md`

**Interfaces:**
- Consumes: `InfrastructureHealthCheck::run(): array`, `CatalogPlaybackSourceResolver::response(LicensedMedia, ?User): Response`, all public methods of `CatalogSitemapResponder`, `CatalogStatsPosterResponder::response(CatalogTitle): Response`.
- Produces: responder methods called by thin route closures.

- [ ] **Step 1: Add failing transport ownership tests**

```php
public function test_machine_routes_do_not_use_livewire_or_non_api_controllers(): void
{
    foreach (['sitemap', 'feed', 'opensearch', 'llms', 'health.ready', 'stats.poster', 'playback.source'] as $name) {
        $action = Route::getRoutes()->getByName($name)?->getActionName() ?? '';
        $this->assertStringNotContainsString('App\\Livewire', $action);
        $this->assertStringNotContainsString('App\\Http\\Controllers', $action);
    }
}
```

- [ ] **Step 2: Run RED transport tests**

```bash
php artisan test --filter='CatalogSitemapTest|InfrastructureHealthCheckTest|PlaybackSource'
```

Expected: FAIL because routes still name controllers.

- [ ] **Step 3: Add exact responders**

Health responder:

```php
final class InfrastructureHealthResponder
{
    public function __construct(private readonly InfrastructureHealthCheck $health) {}

    public function response(): JsonResponse
    {
        $result = $this->health->run();

        return response()->json($result, $result['ready'] ? 200 : 503, [
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
```

Playback responder:

```php
final class CatalogPlaybackSourceResponder
{
    public function __construct(private readonly CatalogPlaybackSourceResolver $sources) {}

    public function response(Request $request, LicensedMedia $licensedMedia): Response
    {
        $user = $request->user();
        $user = $user instanceof User ? $user : null;
        abort_unless((int) $request->query('viewer', -1) === ($user?->getKey() ?? 0), 403);

        return $this->sources->response($licensedMedia, $user);
    }
}
```

Replace sitemap controller actions with thin closures that inject `CatalogSitemapResponder` and call the corresponding exact method: `index`, `staticPages`, `taxonomies`, `landings`, `collections`, `profiles`, `titles($page)`, `videos($page)`, `requests($page)`, `feed`, `openSearch`, `llms`. Replace poster with `CatalogStatsPosterResponder::response()`, health and playback with their responders. Keep all middleware and constraints unchanged.

- [ ] **Step 4: Verify transport parity and commit**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter='CatalogSitemapTest|InfrastructureHealthCheckTest|PlaybackSource|CatalogPageTest'
git add app/Services/Operations/InfrastructureHealthResponder.php app/Services/Catalog/CatalogPlaybackSourceResponder.php routes/web.php app/Http/Controllers/InfrastructureHealthController.php app/Http/Controllers/PlaybackSourceController.php app/Http/Controllers/CatalogSitemapController.php app/Http/Controllers/CatalogController.php tests README.md
git status --short --branch
git commit -m "refactor: move machine responses out of controllers"
```

Expected: XML/text/JSON/image/playback response tests PASS and no response contains a Livewire snapshot.

---

### Task 5: Auth, download и file responders

**Files:**
- Create the auth, media, collection-cover, technical-attachment and profile-media responders listed in File Structure.
- Modify: `routes/web.php`
- Delete: matching controller files.
- Test: existing auth/export/media/download/technical issue/profile/collection feature tests.
- Modify: `README.md`

**Interfaces:**
- Consumes: the exact services currently injected into each controller.
- Produces: one `response(...)` method per responder with the same return type as the removed controller `__invoke()`.

- [ ] **Step 1: Write failing route ownership tests for direct responses**

```php
#[DataProvider('directResponseRoutes')]
public function test_direct_response_route_has_no_non_api_controller(string $route): void
{
    $action = Route::getRoutes()->getByName($route)?->getActionName() ?? '';

    $this->assertStringNotContainsString('App\\Http\\Controllers', $action);
}

public static function directResponseRoutes(): array
{
    return array_map(static fn (string $name): array => [$name], [
        'profile.export',
        'verification.verify',
        'settings.preferences.migrate',
        'titles.media.download',
        'collections.cover',
        'issues.attachments.show',
        'profiles.media',
    ]);
}
```

- [ ] **Step 2: Run RED tests**

```bash
php artisan test --filter='LivewireWebBoundaryTest|WebAccountManagementTest|AuthorizationTest|TechnicalIssue|CatalogCollection|Profile'
```

Expected: ownership assertions FAIL while behavioral tests remain green.

- [ ] **Step 3: Move each exact controller contract to a responder**

Use this mandatory shape for every responder:

```php
final class LicensedMediaDownloadResponder
{
    public function __construct(private readonly StreamLicensedMediaDownload $downloads) {}

    public function response(
        Request $request,
        CatalogTitle $catalogTitle,
        LicensedMedia $licensedMedia,
    ): Response {
        abort_unless((int) $licensedMedia->catalog_title_id === $catalogTitle->id, 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(Gate::forUser($user)->allows('download', $licensedMedia), 404);

        return $this->downloads->response($request, $user, $catalogTitle, $licensedMedia);
    }
}
```

For the other responders, preserve the complete current method bodies and exact response types while changing only ownership:

- `AccountDataExportResponder::response(Request): StreamedResponse` retains JSON flags, filename and private headers.
- `AccountEmailVerificationResponder::response(int $id, string $hash): RedirectResponse` retains status and destination choice.
- `AnonymousPreferencesMigrationResponder::response(MigrateAnonymousPreferencesRequest): Response` retains DTO field mapping and `204`.
- `CatalogCollectionCoverResponder::response(Request, string $publicId, int $version): Response` retains path, MIME, disk and version checks.
- `TechnicalIssueAttachmentResponder::response(string $technicalIssue, string $attachment): StreamedResponse|Response` retains schema fallback, Gate and CSP.
- `UserProfileMediaResponder::response(Request, string $userPublicId, string $kind, int $version): Response` retains privacy, path and MIME checks.

Each route closure injects one responder and returns exactly one `response()` call. Keep all existing middleware, throttle, signature, `scopeBindings()` and constraints.

- [ ] **Step 4: Run focused parity tests**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter='WebAccountManagementTest|WebEmailVerificationTest|AuthorizationTest|TechnicalIssue|CatalogCollection|PublicProfile|Download'
```

Expected: PASS with unchanged statuses, content types, content dispositions, CSP, cache headers and streaming/range behavior.

- [ ] **Step 5: Commit**

Update the existing README history bullet to state that direct downloads, protected attachments and media URLs keep working while pages use Livewire.

```bash
git add app/Services/Auth app/Services/Media/LicensedMediaDownloadResponder.php app/Services/Collections/CatalogCollectionCoverResponder.php app/Services/TechnicalIssues/TechnicalIssueAttachmentResponder.php app/Services/Profiles/UserProfileMediaResponder.php routes/web.php app/Http/Controllers tests README.md
git status --short --branch
git commit -m "refactor: move direct responses out of controllers"
```

---

### Task 6: Redirect responders

**Files:**
- Create the directory, collection legacy, comment and review responders listed in File Structure.
- Modify: `routes/web.php`
- Delete: `CatalogDirectoryRedirectController.php`, `CommentRedirectController.php`, `ReviewDirectLinkController.php`, final `CatalogCollectionController.php`.
- Test: catalog directory, comments, reviews and collection tests.
- Modify: `README.md`

**Interfaces:**
- Consumes: `CatalogDirectoryRegistry`, `CatalogDirectoryQuery`, `TagResolver`, `CatalogCollectionResolver`, `CommentDirectLinkResolver`, `ReviewSchema`, `CatalogTitleQuery`, `CatalogTitleReviewQuery`.
- Produces: permanent or private redirects with current targets and headers.

- [ ] **Step 1: Write failing route ownership tests**

Assert that `comments.show`, `localized.comments.show`, `reviews.show`, `legacy.collections.show`, `legacy.selections.show` and every `CatalogDirectoryRegistry::routeMap()` detail route have actions outside `App\Http\Controllers`.

```php
foreach ($routes as $name) {
    $action = Route::getRoutes()->getByName($name)?->getActionName() ?? '';

    $this->assertStringNotContainsString('App\\Http\\Controllers', $action);
}
```

- [ ] **Step 2: Run RED tests**

```bash
php artisan test --filter='Comment|Review|CatalogDirectory|CatalogCollection'
```

Expected: ownership assertions FAIL.

- [ ] **Step 3: Implement responders with exact redirect semantics**

Comment responder:

```php
public function response(Request $request, string $comment): RedirectResponse
{
    abort_unless($this->schema->writable(), 404);
    abort_unless(ctype_digit($comment) && (int) $comment > 0, 404);
    $viewer = $request->user();
    $locale = $request->route('locale');

    return redirect()->to($this->links->resolve(
        (int) $comment,
        $viewer instanceof User ? $viewer : null,
        is_string($locale) ? $locale : null,
    ))->withHeaders([
        'Cache-Control' => 'private, no-store',
        'X-Robots-Tag' => 'noindex, nofollow',
    ]);
}
```

Collection legacy responder resolves the slug, authorizes `view` and returns `301` to `collections.show`. Directory responder retains year/tag/detail branches and RFC3986 query preservation. Review responder retains alias resolution, merge-cycle protection, published/deleted checks, viewer visibility, page calculation, anchor, `X-Robots-Tag` and private cache headers.

- [ ] **Step 4: Replace routes, verify and commit**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter='Comment|Review|CatalogDirectory|CatalogCollection'
git add app/Services/Catalog/CatalogDirectoryRedirectResponder.php app/Services/Collections/CatalogCollectionLegacyRedirectResponder.php app/Services/Comments/CommentDirectLinkResponder.php app/Services/Reviews/ReviewDirectLinkResponder.php routes/web.php app/Http/Controllers tests README.md
git status --short --branch
git commit -m "refactor: move web redirects out of controllers"
```

Expected: PASS with unchanged `301`, anchors, query strings and noindex/private headers.

---

### Task 7: Structural ban on non-API controllers

**Files:**
- Create: `tests/Feature/LivewireWebBoundaryTest.php`
- Delete: any remaining non-API controller file except `Controller.php`.
- Modify: `AGENTS.md`
- Modify: `README.md`

**Interfaces:**
- Consumes: Laravel route collection and repository file inventory.
- Produces: a permanent automated architecture gate.

- [ ] **Step 1: Write the complete structural test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class LivewireWebBoundaryTest extends TestCase
{
    public function test_controller_tree_contains_only_api_controllers_and_shared_base(): void
    {
        $unexpected = collect(File::allFiles(app_path('Http/Controllers')))
            ->map(fn ($file): string => str_replace('\\', '/', $file->getRelativePathname()))
            ->reject(fn (string $path): bool => $path === 'Controller.php' || str_starts_with($path, 'Api/'))
            ->values()
            ->all();

        $this->assertSame([], $unexpected);
    }

    public function test_no_web_route_uses_non_api_controller(): void
    {
        $unexpected = collect(Route::getRoutes()->getRoutes())
            ->reject(fn ($route): bool => str_starts_with($route->uri(), 'api/'))
            ->map(fn ($route): string => $route->getActionName())
            ->filter(fn (string $action): bool => str_starts_with($action, 'App\\Http\\Controllers\\'))
            ->reject(fn (string $action): bool => str_starts_with($action, 'App\\Http\\Controllers\\Api\\'))
            ->values()
            ->all();

        $this->assertSame([], $unexpected);
    }
}
```

- [ ] **Step 2: Run the structural test**

```bash
php artisan test --filter=LivewireWebBoundaryTest
```

Expected: PASS only when all non-API application controllers have been removed and routes no longer reference them.

- [ ] **Step 3: Add the permanent agent requirement**

Add under Laravel/architecture rules in `AGENTS.md`:

```markdown
- Новые HTML-маршруты `routes/web.php` реализовывать только как full-page Livewire-компоненты. Контроллеры допустимы только для `routes/api.php`; XML, JSON health-check, файлы, redirects, signed playback и streamed downloads должны использовать тонкие route handlers и доменные responders, а не маскироваться под Livewire.
```

- [ ] **Step 4: Verify routes and commit**

```bash
php artisan route:list --except-vendor
./vendor/bin/pint --dirty --format agent
php artisan test --filter=LivewireWebBoundaryTest
git add tests/Feature/LivewireWebBoundaryTest.php app/Http/Controllers AGENTS.md README.md
git status --short --branch
git commit -m "test: enforce livewire web boundary"
```

Expected: route list contains no non-API controller action; API actions remain unchanged.

---

### Task 8: Documentation, regression suite and final commit

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/views.md`
- Modify: `docs/frontend.md`
- Modify: `docs/CODE_STANDARDS.md`
- Modify: `docs/testing.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify additional owner documents only when `docs/README.md` maps the changed contract to them.

**Interfaces:**
- Consumes: final route inventory and implemented component/responder names.
- Produces: current Russian documentation and verified release history.

- [ ] **Step 1: Update canonical documentation**

Document these exact statements:

```markdown
- Все пользовательские HTML-страницы обслуживаются full-page Livewire-компонентами.
- `routes/api.php` остаётся отдельной stateless JSON-границей с API-контроллерами и Resources.
- Non-HTML web endpoints делегируют typed responses доменным responders и не используют Livewire snapshot.
- В `app/Http/Controllers` разрешены только `Controller.php` и `Api/**`; это проверяет `LivewireWebBoundaryTest`.
```

Remove obsolete documentation naming `CatalogController`, `CatalogSitemapController`, `CatalogTopListController`, `GlobalSearchController` or other deleted controllers as current owners.

- [ ] **Step 2: Update visitor and technical history**

In the final README section `История обновлений для посетителей`, add one dated Russian bullet explaining that pages now update through Livewire while public links, search, filters, media, downloads and service documents keep their addresses. Add a detailed Russian CHANGELOG entry naming components/responders and preserved transport contracts.

- [ ] **Step 3: Refresh managed docs when required**

```bash
php artisan project:docs-refresh
git diff --check
```

Expected: managed blocks match their source and no whitespace errors occur.

- [ ] **Step 4: Run focused and full verification**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter='LivewireWebBoundaryTest|CatalogPageTest|GlobalSearchPageTest|CatalogTopListPageTest|CatalogCollection|CatalogSitemap|PlaybackSource|TechnicalIssue|WebAccountManagementTest|WebEmailVerificationTest'
php artisan test
./vendor/bin/phpunit
npm run build
php artisan route:list --except-vendor
git diff --check
```

Expected: every command exits `0`; no route action references a non-API controller; frontend build completes without warning/error introduced by the migration.

- [ ] **Step 5: Inspect final diff and commit**

```bash
git status --short --branch
git diff --stat
git diff -- app/Http/Controllers routes/web.php app/Livewire app/Services resources/views tests AGENTS.md README.md CHANGELOG.md docs
git add -A
git status --short --branch
git commit -m "refactor: complete livewire web migration"
git status --short --branch
```

Expected: commit succeeds through project hooks. Before declaring completion, commit every authorized concurrent change and confirm that `git status --short --branch` is clean on `main`.
