# Isolated `/discover/popular` + collections consolidation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Чисто объединить публичные рекомендации и коллекции в `/discover/popular`, объединить управление ими в `/admin/catalog`, удалить отдельные legacy directory boundaries без редиректов и сохранить рабочие detail/profile/cover/API/owner contracts.

**Architecture:** `CatalogDiscoveryPage` остаётся единственным full-page Livewire owner публичного discovery и вкладывает самостоятельный `CatalogCollectionExplorer` только для типа `popular`. `CatalogAdministrationPage` становится единственным full-page owner административного каталога и переключает два существующих manager-fragment без копирования их business logic. Popularity строится четырьмя grouped aggregates и обслуживается существующим public page cache; отдельные directory routes/classes/views/responder удаляются после repository-wide dependency audit.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3, SQLite, Blade, Tailwind CSS 4.3, Vite 8.1.4, PHPUnit 12.5, Playwright/Chromium.

## Global Constraints

- Работать только в существующей ветке `main`; не создавать branch, worktree или pull request.
- Этот файл является отдельным checklist: не переносить его задачи в другие параллельные планы. В `docs/plans/current-task-plan.md` при исполнении допустима только одна ссылка на этот документ.
- Не добавлять dependencies, migrations, новые tables/columns, `.env` values или destructive data commands.
- Не создавать redirects для `/collections`, `/admin/collections`, `/discover`, `/recommendations`, `/lists`, `/selections` и `/my/lists`; после удаления они должны отвечать `404`.
- Сохранить `/collections/{collectionSlug}`, localized detail, cover responder, `/my/collections`, profile collections и read-only `/api/v1/collections*`.
- Новые HTML boundaries реализовывать только full-page Livewire-компонентами; вложенные manager/explorer components рендерят fragments без второго layout и `<h1>`.
- Сохранить server-side policies/gates, collection visibility/moderation rules, private `no-store` behavior и current-user isolation.
- Видимый интерфейс — русский; RU/EN translation catalogs должны сохранять parity.
- Не добавлять `@php`, inline CSS, inline business JavaScript, query/model/service calls из Blade, Volt или text truncation utilities.
- Сначала RED test, затем минимальная реализация, focused verification, Pint, Vite build, route/view cache, полный suite и Chromium desktop/mobile.

---

### Task 1: Зафиксировать новый route и compatibility contract тестами

**Files:**
- Modify: `tests/Feature/UnifiedDiscoveryCollectionsTest.php`
- Modify: `tests/Feature/RouteFallbackTest.php`
- Modify: `tests/Feature/CatalogTopListPageTest.php`

**Interfaces:**
- Consumes: route names `discover.index`, `localized.discover.index`, `admin.catalog`, `collections.show`.
- Produces: executable contract одного public/admin owner, сохранённого detail route и `404` без redirects.

- [ ] **Step 1: Добавить failing HTTP/route assertions**

```php
public function test_removed_directory_and_legacy_urls_return_404_without_redirects(): void
{
    foreach ([
        '/collections',
        '/ru/collections',
        '/lists',
        '/lists/old-list',
        '/selections/old-selection',
        '/discover',
        '/ru/discover',
        '/recommendations',
        '/ru/recommendations',
        '/admin/collections',
    ] as $uri) {
        $this->get($uri)->assertNotFound();
    }
}

public function test_unknown_web_paths_return_not_found_without_redirecting(): void
{
    $this->get('/nesushchestvuyushchaya-stranica')->assertNotFound();
}
```

- [ ] **Step 2: Запустить RED tests**

Run:

```bash
php artisan test --filter='UnifiedDiscoveryCollectionsTest|RouteFallbackTest|CatalogTopListPageTest'
```

Expected: FAIL, пока отдельные routes/redirect fallback или прежние owners ещё зарегистрированы.

- [ ] **Step 3: Зафиксировать baseline inventory без изменения кода**

Run:

```bash
php artisan route:list --path=discover
php artisan route:list --path=collections
php artisan route:list --path=admin/catalog
php artisan route:list --path=admin/collections
rg -n 'CatalogCollectionDirectory|CatalogCollectionLegacyRedirectResponder|admin\.collections|/admin/collections' app resources routes tests docs
```

Expected: inventory перечисляет каждую legacy dependency, которую нужно проверить до удаления.

- [ ] **Step 4: Commit RED contract**

```bash
git add tests/Feature/UnifiedDiscoveryCollectionsTest.php tests/Feature/RouteFallbackTest.php tests/Feature/CatalogTopListPageTest.php
git commit -m "test: define unified discovery route contract"
```

---

### Task 2: Устранить per-title popularity subqueries

**Files:**
- Modify: `app/Services/Catalog/CatalogPopularityQuery.php`
- Modify: `tests/Feature/CatalogPopularityQueryTest.php`

**Interfaces:**
- Consumes: `Builder<CatalogTitle>`, rating provider `kinopoisk|imdb`, existing recommendation signal tables.
- Produces: `CatalogPopularityQuery::apply(Builder $query, string $ratingProvider): Builder` с прежними весами `35/45/8` и vote buckets `20/40/60/80`.

- [ ] **Step 1: Добавить SQL-shape regression test**

```php
public function test_popularity_signals_are_aggregated_once_instead_of_correlated_per_title(): void
{
    $sql = app(CatalogPopularityQuery::class)->apply(CatalogTitle::query())->toSql();

    $this->assertGreaterThanOrEqual(4, substr_count(strtolower($sql), 'left join ('));
    $this->assertStringNotContainsString(
        'where "catalog_title_id" = "catalog_titles"."id"',
        strtolower($sql),
    );
    $this->assertStringContainsString('popularity_watchlist_count', $sql);
    $this->assertStringContainsString('popularity_watcher_count', $sql);
    $this->assertStringContainsString('popularity_review_count', $sql);
    $this->assertStringContainsString('popularity_provider_votes', $sql);
}
```

- [ ] **Step 2: Подтвердить RED**

```bash
php artisan test --filter=CatalogPopularityQueryTest
```

Expected: FAIL на прежнем correlated SQL shape.

- [ ] **Step 3: Перевести signals на grouped subqueries**

```php
$watchlists = CatalogTitleUserState::query()
    ->select('catalog_title_id')
    ->selectRaw('COUNT(*) AS popularity_watchlist_count')
    ->where('in_watchlist', true)
    ->groupBy('catalog_title_id');

$watchers = EpisodeViewProgress::query()
    ->select('catalog_title_id')
    ->selectRaw('COUNT(DISTINCT user_id) AS popularity_watcher_count')
    ->where(function (Builder $query): void {
        $query->where('position_seconds', '>=', max(1, (int) config('recommendations.meaningful_progress_seconds', 180)))
            ->orWhere('progress_percent', '>=', max(1, (int) config('recommendations.meaningful_progress_percent', 10)))
            ->orWhereNotNull('completed_at');
    })
    ->groupBy('catalog_title_id');

$reviews = CatalogTitleReview::query()
    ->select('catalog_title_id')
    ->selectRaw('COUNT(*) AS popularity_review_count')
    ->where('status', ReviewStatus::Published->value)
    ->whereNull('deleted_at')
    ->whereNull('merged_into_id')
    ->groupBy('catalog_title_id');

$ratings = CatalogTitleRating::query()
    ->select('catalog_title_id')
    ->selectRaw('MAX(votes) AS popularity_provider_votes')
    ->where('provider', $ratingProvider)
    ->groupBy('catalog_title_id');
```

Join каждый aggregate через `leftJoinSub`, выбрать `COALESCE(..., 0)` aliases и сохранить прежнюю формулу сортировки без изменения business weights.

- [ ] **Step 4: Проверить SQL и фактическое время**

```bash
php artisan test --filter=CatalogPopularityQueryTest
curl -sS --max-time 30 -o /dev/null -w 'status=%{http_code} ttfb=%{time_starttransfer} total=%{time_total}\n' https://seasonvar.miniserver.fun/discover/popular
curl -sS --max-time 30 -o /dev/null -D - https://seasonvar.miniserver.fun/discover/popular
```

Expected: test PASS; cold rebuild конечен; повторный ответ содержит `X-Seasonvar-Page-Cache: HIT` и существенно меньший TTFB.

- [ ] **Step 5: Commit query optimization**

```bash
git add app/Services/Catalog/CatalogPopularityQuery.php tests/Feature/CatalogPopularityQueryTest.php
git commit -m "perf: aggregate discovery popularity signals once"
```

---

### Task 3: Встроить collection explorer в popular discovery

**Files:**
- Create: `app/Livewire/Collections/CatalogCollectionExplorer.php`
- Create: `resources/views/livewire/collections/catalog-collection-explorer.blade.php`
- Modify: `app/Livewire/CatalogDiscoveryPage.php`
- Modify: `resources/views/livewire/catalog-discovery-page.blade.php`
- Modify: `app/View/Components/Collections/CollectionCard.php`
- Modify: `resources/views/components/collections/collection-card.blade.php`
- Test: `tests/Feature/UnifiedDiscoveryCollectionsTest.php`

**Interfaces:**
- Consumes: `CatalogCollectionQuery::publicDirectory(string $search, string $sort, int $perPage)`, `collections.mine`, `login`.
- Produces: nested Livewire component `collections.catalog-collection-explorer` с URL keys `collections_q`, `collections_sort`, `collectionsPage` и fragment anchor `#collections`.

- [ ] **Step 1: Добавить failing explorer response assertions**

```php
$this->get(route('discover.index', ['type' => 'popular']))
    ->assertOk()
    ->assertSeeLivewire('catalog-discovery-page')
    ->assertSeeLivewire('collections.catalog-collection-explorer')
    ->assertSee('id="collections"', false)
    ->assertSee('name="collections_q"', false)
    ->assertSee('name="collections_sort"', false);
```

- [ ] **Step 2: Создать bounded explorer component**

```php
final class CatalogCollectionExplorer extends Component
{
    use WithPagination;

    #[Url(as: 'collections_q', history: true, except: '')]
    public string $search = '';

    #[Url(as: 'collections_sort', history: true, except: 'featured')]
    public string $sort = 'featured';

    public function applySearch(): void
    {
        $this->normalize();
        $this->resetPage(pageName: 'collectionsPage');
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage(pageName: 'collectionsPage');
    }

    public function render(CatalogCollectionQuery $collections): View
    {
        return view('livewire.collections.catalog-collection-explorer', [
            'collections' => $collections->publicDirectory($this->search, $this->sort, 12),
            'sortOptions' => [
                'featured' => __('collections.directory.sort_featured'),
                'recent' => __('collections.directory.sort_recent'),
                'title' => __('collections.directory.sort_title'),
            ],
            'collectionAction' => [
                'url' => Auth::check() ? route('collections.mine') : route('login'),
                'label' => Auth::check()
                    ? __('collections.navigation.my_collections')
                    : __('collections.actions.create'),
            ],
        ]);
    }

    private function normalize(): void
    {
        $this->search = Str::limit(Str::squish($this->search), 100, '');
        $this->sort = in_array($this->sort, ['featured', 'recent', 'title'], true)
            ? $this->sort
            : 'featured';
    }
}
```

- [ ] **Step 3: Подключить explorer только для popular**

Передать из `CatalogDiscoveryPage::render()` passive key:

```php
'collectionExplorerKey' => 'discovery-collections-'.app()->currentLocale(),
```

Добавить в конце discovery Blade:

```blade
@if ($type === 'popular')
    <livewire:collections.catalog-collection-explorer :key="$collectionExplorerKey" />
@endif
```

- [ ] **Step 4: Сделать compact card без потери текста**

```blade
@if ($compact)
    <h3 class="mt-3 break-words text-base font-black leading-snug text-slate-800">
        <a href="{{ $card->url }}" class="hover:text-emerald-700">{{ $card->name }}</a>
    </h3>
@else
    <h2 class="mt-3 break-words text-lg font-black leading-snug text-slate-800">
        <a href="{{ $card->url }}" class="hover:text-emerald-700">{{ $card->name }}</a>
    </h2>
@endif
```

Использовать `aspect-[3/2]`, grid `sm:2 / lg:3 / xl:4`, полный escaped description и не применять `line-clamp-*`/`truncate`.

- [ ] **Step 5: Проверить explorer и Blade boundaries**

```bash
php artisan test --filter='UnifiedDiscoveryCollectionsTest|BladeTemplateTest|HdRezkaCollectionPresentationTest'
```

Expected: popular содержит explorer; остальные discovery types не содержат его; Blade infrastructure/truncation checks PASS.

- [ ] **Step 6: Commit public composition**

```bash
git add app/Livewire/Collections/CatalogCollectionExplorer.php app/Livewire/CatalogDiscoveryPage.php app/View/Components/Collections/CollectionCard.php resources/views/livewire/collections/catalog-collection-explorer.blade.php resources/views/livewire/catalog-discovery-page.blade.php resources/views/components/collections/collection-card.blade.php tests/Feature/UnifiedDiscoveryCollectionsTest.php
git commit -m "refactor: compose collections into popular discovery"
```

---

### Task 4: Создать единый административный shell

**Files:**
- Create: `app/Livewire/CatalogAdministrationPage.php`
- Create: `resources/views/livewire/catalog-administration-page.blade.php`
- Modify: `app/Livewire/CatalogAdministrationManager.php`
- Modify: `resources/views/livewire/catalog-administration-manager.blade.php`
- Modify: `app/Livewire/Collections/CatalogCollectionAdministrationManager.php`
- Modify: `resources/views/livewire/collections/catalog-collection-administration-manager.blade.php`
- Modify: `lang/ru/collections.php`
- Modify: `lang/en/collections.php`
- Test: `tests/Feature/UnifiedDiscoveryCollectionsTest.php`

**Interfaces:**
- Consumes: existing `manage-catalog` gate and both manager components/actions.
- Produces: full-page `CatalogAdministrationPage` на `/admin/catalog` с URL state `section=catalog|collections`; managers становятся fragments.

- [ ] **Step 1: Добавить failing admin owner assertion**

```php
$this->assertSame(
    CatalogAdministrationPage::class,
    Route::getRoutes()->getByName('admin.catalog')?->getActionName(),
);
$this->assertNull(Route::getRoutes()->getByName('admin.collections'));

$this->actingAs($admin)
    ->get(route('admin.catalog', ['section' => 'collections']))
    ->assertOk()
    ->assertSeeLivewire('catalog-administration-page')
    ->assertSeeLivewire('collections.catalog-collection-administration-manager');
```

- [ ] **Step 2: Создать shell component**

```php
final class CatalogAdministrationPage extends Component
{
    #[Url(history: true, except: 'catalog')]
    public string $section = 'catalog';

    public function mount(): void
    {
        $this->normalize();
    }

    public function setSection(string $section): void
    {
        $this->section = $section;
        $this->normalize();
    }

    public function render(): View
    {
        return view('livewire.catalog-administration-page')
            ->extends('layouts.app', ['title' => __('collections.admin.catalog_page_title')])
            ->section('content');
    }

    private function normalize(): void
    {
        $this->section = in_array($this->section, ['catalog', 'collections'], true)
            ? $this->section
            : 'catalog';
    }
}
```

- [ ] **Step 3: Render ровно один manager fragment**

```blade
<div class="space-y-5" data-admin-catalog-page>
    <header class="rounded-panel bg-white p-4 shadow-panel sm:p-6">
        <h1 class="text-2xl font-black text-slate-900">{{ __('collections.admin.catalog_page_title') }}</h1>
        <nav class="mt-4 flex flex-wrap gap-2" aria-label="{{ __('collections.admin.sections') }}">
            <button type="button" wire:click="setSection('catalog')">{{ __('collections.admin.catalog_section') }}</button>
            <button type="button" wire:click="setSection('collections')">{{ __('collections.admin.collections_section') }}</button>
        </nav>
    </header>

    @if ($section === 'collections')
        <livewire:collections.catalog-collection-administration-manager />
    @else
        <livewire:catalog-administration-manager />
    @endif
</div>
```

Удалить из обоих manager views `@extends`, `@section`, duplicate page header и duplicate `<h1>`; actions, policies, optimistic version и audit calls не менять.

- [ ] **Step 4: Проверить guest/admin behavior**

```bash
php artisan test --filter='UnifiedDiscoveryCollectionsTest|LivewireWebBoundaryTest'
```

Expected: guest не получает admin content; разрешённый admin видит оба workflow через один route; nested managers не являются full-page owners.

- [ ] **Step 5: Commit admin composition**

```bash
git add app/Livewire/CatalogAdministrationPage.php app/Livewire/CatalogAdministrationManager.php app/Livewire/Collections/CatalogCollectionAdministrationManager.php resources/views/livewire/catalog-administration-page.blade.php resources/views/livewire/catalog-administration-manager.blade.php resources/views/livewire/collections/catalog-collection-administration-manager.blade.php lang/ru/collections.php lang/en/collections.php tests/Feature/UnifiedDiscoveryCollectionsTest.php
git commit -m "refactor: unify catalog and collection administration"
```

---

### Task 5: Удалить legacy directory code и routes

**Files:**
- Delete: `app/Livewire/Collections/CatalogCollectionDirectory.php`
- Delete: `resources/views/livewire/collections/catalog-collection-directory.blade.php`
- Delete: `app/Services/Collections/CatalogCollectionLegacyRedirectResponder.php`
- Modify: `routes/web.php`
- Modify: `config/help-center.php`
- Modify: `app/View/ViewData/AppLayoutData.php`
- Modify: `app/Services/Catalog/Search/HeaderPortalSectionRegistry.php`
- Modify: `resources/views/components/layout/site-header.blade.php`
- Modify: `resources/views/components/layout/site-footer.blade.php`

**Interfaces:**
- Consumes: new `CatalogDiscoveryPage`, `CatalogAdministrationPage`.
- Produces: one public directory link, one admin link, global unknown `404`, no redirect adapters.

- [ ] **Step 1: Replace route owners**

```php
Route::get('/discover/{type}', CatalogDiscoveryPage::class)
    ->whereIn('type', $discoveryRouteTypes)
    ->middleware('public.page:discovery')
    ->name('discover.index');

Route::get('/admin/catalog', CatalogAdministrationPage::class)
    ->middleware(['auth', 'auth.session', 'account.private', 'can:manage-catalog'])
    ->name('admin.catalog');

Route::fallback(static fn () => abort(404));
```

Не регистрировать exact/default/alias routes для `/collections`, `/admin/collections`, `/discover`, `/recommendations`, `/lists`, `/selections` и `/my/lists`.

- [ ] **Step 2: Сохранить действующие collection routes**

Перед удалением проверить, что `routes/web.php` и `routes/api.php` продолжают регистрировать:

```text
collections.show
localized.collections.show
collections.cover
collections.mine
collections.edit
profiles.collections
localized.profiles.collections
api.v1.collections.index
api.v1.collections.show
api.v1.titles.collections.index
```

- [ ] **Step 3: Удалить три legacy files через apply_patch**

Удалить только перечисленные class/view/responder после подтверждения, что `rg` не находит runtime consumers.

- [ ] **Step 4: Перенаправить navigation/search links в секцию**

Использовать один URL builder:

```php
route('discover.index', ['type' => 'popular']).'#collections'
```

Search handoff должен сохранять запрос:

```php
route('discover.index', [
    'type' => 'popular',
    'collections_q' => $search,
]).'#collections'
```

- [ ] **Step 5: Проверить route inventory**

```bash
php artisan route:cache
php artisan route:list --path=discover
php artisan route:list --path=collections
php artisan route:list --path=admin/catalog
php artisan route:list --path=admin/collections
php artisan test --filter='UnifiedDiscoveryCollectionsTest|RouteFallbackTest'
```

Expected: два discovery routes, один admin catalog route, сохранённые detail/private/API routes, отсутствие admin collections route, все removed URLs `404`.

- [ ] **Step 6: Commit legacy removal**

```bash
git add routes/web.php config/help-center.php app/View/ViewData/AppLayoutData.php app/Services/Catalog/Search/HeaderPortalSectionRegistry.php resources/views/components/layout/site-header.blade.php resources/views/components/layout/site-footer.blade.php
git add -u app/Livewire/Collections/CatalogCollectionDirectory.php app/Services/Collections/CatalogCollectionLegacyRedirectResponder.php resources/views/livewire/collections/catalog-collection-directory.blade.php
git commit -m "refactor: remove standalone collection directory boundaries"
```

---

### Task 6: Синхронизировать cache, SEO, sitemap, warm и cover contracts

**Files:**
- Modify: `app/Support/Cache/PublicPageCachePolicy.php`
- Modify: `app/Services/Collections/CatalogCollectionCacheInvalidator.php`
- Modify: `app/Services/Catalog/PublicCatalogWarmTargetSource.php`
- Modify: `app/Services/Catalog/CatalogSitemapResponder.php`
- Modify: `app/Services/Collections/CatalogCollectionSeoPresenter.php`
- Modify: `app/Services/Collections/CatalogCollectionCoverService.php`
- Modify: `app/Services/DemoData/DemoRasterAsset.php`
- Modify: `app/Services/DemoData/Stages/DemoOrganizationStage.php`
- Test: `tests/Feature/PublicCacheRouteSafetyTest.php`
- Test: `tests/Feature/PublicPageResponseCacheTest.php`
- Test: `tests/Feature/FullPublicCacheWarmJobTest.php`
- Test: `tests/Feature/SitemapAndRobotsTest.php`
- Test: `tests/Feature/CatalogCollectionCoverFallbackTest.php`

**Interfaces:**
- Consumes: `CacheDomain::CatalogPages`, `CacheDomain::Collections`, discovery route and collection cover responder.
- Produces: no stale embedded collections, no broken generated cover URL, one sitemap/discovery target.

- [ ] **Step 1: Define shared-cache query boundary**

```php
if (array_key_exists('collections_q', $query)) {
    return null;
}
```

Allowlist only `collections_sort` and `collectionsPage` for discovery dimensions; authenticated requests and arbitrary query keys continue to bypass shared HTML cache.

- [ ] **Step 2: Invalidate embedded discovery after collection mutation**

В `CatalogCollectionCacheInvalidator` bump-ить `CatalogPages` вместе с существующими collection/home/sitemap/API domains после material commit. Не использовать `Cache::flush()`, wildcard scan или deletion private data.

- [ ] **Step 3: Remove standalone directory from sitemap/warm targets**

`PublicCatalogWarmTargetSource` должен выдавать только:

```php
['route' => 'discover.index', 'parameters' => ['type' => 'popular']]
```

и localized equivalent. `CatalogSitemapResponder`/LLM text должны ссылаться на `/discover/popular#collections`; detail collection sitemap сохраняется.

- [ ] **Step 4: Fail closed for invalid cover metadata**

```php
if ($disk !== $expectedDisk
    || ! str_starts_with($path, "catalog-collections/{$collection->public_id}/")
    || str_contains($path, '..')
    || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    return null;
}
```

Generated demo WebP сохранять под `catalog-collections/{publicId}/demo/`, чтобы URL соответствовал responder allowlist.

- [ ] **Step 5: Run integration tests**

```bash
php artisan test --filter='PublicCacheRouteSafetyTest|PublicPageResponseCacheTest|FullPublicCacheWarmJobTest|SitemapAndRobotsTest|CatalogCollectionCoverFallbackTest|DemoCatalogCorpusStageTest'
```

Expected: PASS; standalone directory отсутствует в warm/sitemap; cover fallback не генерирует broken URL; private state не попадает в shared cache.

- [ ] **Step 6: Commit integration convergence**

```bash
git add app/Support/Cache/PublicPageCachePolicy.php app/Services/Collections/CatalogCollectionCacheInvalidator.php app/Services/Catalog/PublicCatalogWarmTargetSource.php app/Services/Catalog/CatalogSitemapResponder.php app/Services/Collections/CatalogCollectionSeoPresenter.php app/Services/Collections/CatalogCollectionCoverService.php app/Services/DemoData/DemoRasterAsset.php app/Services/DemoData/Stages/DemoOrganizationStage.php tests/Feature/PublicCacheRouteSafetyTest.php tests/Feature/PublicPageResponseCacheTest.php tests/Feature/FullPublicCacheWarmJobTest.php tests/Feature/SitemapAndRobotsTest.php tests/Feature/CatalogCollectionCoverFallbackTest.php
git commit -m "refactor: converge collection discovery integrations"
```

---

### Task 7: Убрать зелёный outline страницы без accessibility regression

**Files:**
- Modify: `resources/css/app.css`
- Modify: `tests/Feature/AppShellFocusTest.php`

**Interfaces:**
- Consumes: programmatic focus `<main id="main-content" class="app-shell-main">` из `resources/js/mobile-runtime.js`.
- Produces: no decorative page-container outline; all actual controls retain global `:focus-visible`.

- [ ] **Step 1: Add failing CSS contract**

```php
public function test_programmatically_focused_main_container_has_no_decorative_green_outline(): void
{
    $css = file_get_contents(resource_path('css/app.css'));

    $this->assertIsString($css);
    $this->assertMatchesRegularExpression(
        '/\.app-shell-main:focus-visible\s*\{[^}]*outline:\s*none;[^}]*box-shadow:\s*none;/s',
        $css,
    );
}
```

- [ ] **Step 2: Apply exact scoped CSS**

```css
.app-shell-main:focus-visible {
    outline: none;
    box-shadow: none;
}
```

Не изменять общий rule для `a`, `button`, `input`, `select`, `textarea`, `summary` и остальных `[tabindex]` controls.

- [ ] **Step 3: Verify test and production asset build**

```bash
php artisan test --filter=AppShellFocusTest
npm run build
```

Expected: test PASS; Vite completes with hashed CSS/JS manifest.

- [ ] **Step 4: Commit focus fix**

```bash
git add resources/css/app.css tests/Feature/AppShellFocusTest.php
git commit -m "fix: remove decorative focus outline from page shell"
```

---

### Task 8: Финальный legacy audit, documentation и acceptance

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/requirements/system-wide-integration.md`
- Modify: `docs/architecture.md`
- Modify: `docs/administration.md`
- Modify: `docs/views.md`
- Modify: `docs/performance.md`
- Modify: `docs/caching.md`
- Modify: `docs/frontend.md`
- Modify: `docs/maintenance/compatibility-adapters.md`
- Modify: `docs/audits/current-state-audit.md`
- Modify: `docs/audits/verification-report.md`
- Modify: this plan file

**Interfaces:**
- Consumes: verified implementation, test/build/browser outputs.
- Produces: canonical contract, visitor-facing history, honest compliance matrix and rollback evidence.

- [ ] **Step 1: Run repository-wide legacy scan**

```bash
rg -n --hidden \
  --glob '!vendor/**' \
  --glob '!node_modules/**' \
  --glob '!storage/**' \
  --glob '!bootstrap/cache/**' \
  'CatalogCollectionDirectory|CatalogCollectionLegacyRedirectResponder|admin\.collections|/admin/collections|/my/lists|/lists/|/selections/' \
  app bootstrap config database resources routes tests docs README.md CHANGELOG.md
```

Expected: production code has zero legacy implementations. Remaining matches are limited to tests proving `404` and documentation recording removal.

- [ ] **Step 2: Run focused and broad verification**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter='UnifiedDiscoveryCollectionsTest|CatalogPopularityQueryTest|AppShellFocusTest|CatalogCollectionCoverFallbackTest|HdRezkaCollectionPresentationTest|PublicCacheRouteSafetyTest|SitemapAndRobotsTest'
php artisan test
npm run build
php artisan route:cache
php artisan view:cache
```

Expected: task-focused tests PASS. Full-suite failures, если они существуют из-за параллельных незавершённых областей, перечислить по exact test names и не маскировать как task success.

- [ ] **Step 3: Run HTTPS contract smoke**

```bash
curl -sS --max-time 30 -o /dev/null -w 'popular=%{http_code} ttfb=%{time_starttransfer} total=%{time_total}\n' https://seasonvar.miniserver.fun/discover/popular
curl -sS --max-time 20 -o /dev/null -w 'collections=%{http_code} redirect=%{redirect_url}\n' https://seasonvar.miniserver.fun/collections
curl -sS --max-time 20 -o /dev/null -w 'admin-collections=%{http_code} redirect=%{redirect_url}\n' https://seasonvar.miniserver.fun/admin/collections
```

Expected: `popular=200`; removed routes `404`; `redirect=` пустой.

- [ ] **Step 4: Run Chromium desktop/mobile acceptance**

Проверить `1440×1100` и `390×844`:

```text
HTTP 200
exactly one h1
#collections exists
12 cards on first page when fixture contains at least 12 public collections
collections_q search and empty state work
document.scrollWidth equals document.clientWidth
focused main computed outlineStyle is none
zero broken images
zero console errors
zero page errors
zero first-party responses >= 400
```

- [ ] **Step 5: Complete documentation matrix**

В этом файле отметить каждую задачу `completed`, `already_compliant`, `not_applicable` или `unresolved` только после проверки. README visitor history должна описывать одну страницу и отсутствие redirects без внутренних class names; CHANGELOG — exact technical changes, verification и rollback.

- [ ] **Step 6: Commit documentation only**

```bash
git add README.md CHANGELOG.md docs/requirements/system-wide-integration.md docs/architecture.md docs/administration.md docs/views.md docs/performance.md docs/caching.md docs/frontend.md docs/maintenance/compatibility-adapters.md docs/audits/current-state-audit.md docs/audits/verification-report.md docs/plans/recommended-discovery-popular-clean-consolidation.md
git commit -m "docs: record clean discovery consolidation"
```

- [ ] **Step 7: Attempt configured delivery**

```bash
git status --short --branch
git push origin main
```

Expected: branch is `main`. Record the exact remote result; absent credentials or remote rejection remain `unresolved`, and remote/secrets must not be changed to hide the failure.

## Rollback Strategy

1. Revert only the scoped commits from this plan in reverse order; do not reset unrelated parallel work.
2. Rebuild Vite assets, routes and views:

```bash
npm run build
php artisan route:cache
php artisan view:cache
```

3. No database restore is required because this plan adds no migration and rewrites no persistent rows.
4. Verify restored routes and public detail/API behavior before reopening traffic.

## Completion Criteria

- `/discover/popular` is the only public recommendation/collection directory.
- `/admin/catalog` is the only catalog/collection administration page.
- Removed directory/default/alias URLs return `404` without redirect.
- Collection detail, cover, profile, private owner and API contracts remain operational.
- Popularity cold rebuild is finite and cache HIT is fast.
- The page shell has no green outline; controls retain keyboard focus indication.
- Repository scan finds no runtime legacy directory implementation or duplicate navigation/cache/SEO/warm path.
- Focused tests, Pint, build, route/view cache and desktop/mobile browser acceptance pass.
- Full-suite and Git delivery results are documented honestly.
