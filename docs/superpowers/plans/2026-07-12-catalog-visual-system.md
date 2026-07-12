# Catalog Visual System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a coherent light futuristic visual system for the Seasonvar shell, home page, title cards, title page, pagination, and local frontend assets without changing catalog data behavior.

**Architecture:** Keep server-rendered Blade and the existing page builders. Build the upgrade from CSS-first Tailwind tokens and explicit anonymous Blade components, then let shared surfaces improve `/titles` while the concurrent search plan owns that page's behavior. Guard asset locality, landmarks, card keyboard behavior, pagination language, and page order with focused PHPUnit tests.

**Tech Stack:** PHP 8.5, Laravel 13.19, Blade, Tailwind CSS 4.3, Vite 8.1, FontAwesome 7.3, Plyr 3.8, HLS.js 1.6, PHPUnit 12.5.

## Global Constraints

- The interface remains light-only and all visible UI copy remains Russian.
- No production or development dependency is added.
- No database migration, destructive command, importer change, search change, or public API change is allowed.
- `resources/views/catalog/titles.blade.php`, `CatalogTitleQuery`, `CatalogTitlesPageBuilder`, and search tests belong to concurrent search work and are not edited by this plan.
- Blade contains no `@php`, database query, inline CSS, inline JavaScript, or text truncation utility.
- Poster images retain `object-contain`, lazy loading, async decoding, and untrusted output escaping.
- Existing season, media, recommendation, query-string, route-model-binding, and SEO contracts remain unchanged.
- The active production-like SQLite database is read-only for this work; browser QA uses an isolated temporary SQLite database while `seasonvar:import` is running.
- PHP edits are formatted with `./vendor/bin/pint --dirty --format agent`.
- Only focused tests are run during implementation; the broad suite is deferred until the importer is no longer active.
- Do not stage, commit, or overwrite concurrent search files in the shared worktree.

---

## File Structure

- Create `tests/Unit/FrontendAssetContractTest.php`: local asset and font contract.
- Create `tests/Feature/CatalogVisualSystemTest.php`: shell, page order, landmarks, card tab-stop, title hero, and pagination behavior.
- Create `resources/images/plyr.svg`: local copy of the installed Plyr sprite.
- Create `resources/views/components/layout/site-header.blade.php`: responsive header and named search/navigation landmarks.
- Create `resources/views/components/layout/site-footer.blade.php`: real catalog/sitemap/feed footer navigation.
- Create `resources/views/vendor/pagination/tailwind.blade.php`: Russian light-only paginator override.
- Create `lang/ru/pagination.php`: previous/next translations.
- Modify `resources/css/app.css`: CSS-first tokens, focus, reduced motion, canvas, card containment, and Plyr palette.
- Modify `resources/js/app.js`: import only FontAwesome core, solid, and regular styles.
- Modify `resources/js/player.js`: use the local Vite-built Plyr sprite and avoid HLS download where native HLS works.
- Modify `vite.config.js`: remove the Latin-only Bunny font bundle.
- Modify `.npmrc` and `package-lock.json`: pin clean installs to the official npm registry without changing package versions.
- Modify `resources/views/layouts/app.blade.php`: remove unused font preload, render skip-link/header/footer, and keep one main landmark.
- Modify shared Blade components: `ui/panel`, `ui/taxonomy-chip`, `ui/status-pill`, `form/search-field`, `title-card`, `title-list-row`, `title-poster`, and `stat`.
- Modify `resources/views/catalog/index.blade.php`: search-first hero, compact update feed, collapsible long country list, and valid landmarks.
- Modify `resources/views/catalog/show.blade.php`: compact title hero, player before secondary reference metadata, and valid landmarks.
- Modify `docs/frontend.md`, `docs/views.md`, and `docs/UI_STANDARDS.md`: document the implemented asset and component contracts without touching managed blocks.

### Task 1: Lock Frontend Assets To Local, Cyrillic-Safe Sources

**Files:**

- Create: `tests/Unit/FrontendAssetContractTest.php`
- Create: `resources/images/plyr.svg`
- Modify: `resources/js/app.js`
- Modify: `resources/js/player.js`
- Modify: `resources/css/app.css`
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `vite.config.js`

**Interfaces:**

- Consumes: Vite `?url` asset import and the installed `node_modules/plyr/dist/plyr.svg`.
- Produces: local hashed `plyr.svg`, only solid/regular FontAwesome fonts, system Cyrillic typography, and unchanged `initializeCatalogPlayers(): Promise<void>`.

- [ ] **Step 1: Write the failing asset contract test**

```php
<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FrontendAssetContractTest extends TestCase
{
    public function test_frontend_assets_are_local_and_cyrillic_safe(): void
    {
        $app = File::get(resource_path('js/app.js'));
        $player = File::get(resource_path('js/player.js'));
        $styles = File::get(resource_path('css/app.css'));
        $vite = File::get(base_path('vite.config.js'));
        $layout = File::get(resource_path('views/layouts/app.blade.php'));
        $npmConfig = File::get(base_path('.npmrc'));
        $npmLock = File::get(base_path('package-lock.json'));

        $this->assertStringNotContainsString('all.min.css', $app);
        $this->assertStringContainsString('fontawesome.min.css', $app);
        $this->assertStringContainsString('solid.min.css', $app);
        $this->assertStringContainsString('regular.min.css', $app);
        $this->assertStringContainsString("../images/plyr.svg?url", $player);
        $this->assertStringContainsString('iconUrl: plyrIconUrl', $player);
        $this->assertStringNotContainsString('cdn.plyr.io', $player);
        $this->assertStringNotContainsString('Instrument Sans', $styles);
        $this->assertStringNotContainsString("bunny('Instrument Sans'", $vite);
        $this->assertStringNotContainsString("Vite::fonts('instrument-sans')", $layout);
        $this->assertStringContainsString('registry=https://registry.npmjs.org/', $npmConfig);
        $this->assertStringNotContainsString('registry.npmmirror.com', $npmLock);
    }
}
```

- [ ] **Step 2: Run the test and verify RED**

Run: `php artisan test tests/Unit/FrontendAssetContractTest.php`

Expected: FAIL because `all.min.css`, Instrument Sans, the mirror-bound lock, and missing local Plyr configuration violate the new contract.

- [ ] **Step 3: Copy the installed Plyr sprite with the patch tool**

Create `resources/images/plyr.svg` with the exact contents of `node_modules/plyr/dist/plyr.svg`. Do not edit the SVG and do not reference `node_modules` from public HTML.

- [ ] **Step 4: Replace global asset imports and player defaults**

`resources/js/app.js` begins with:

```js
import '@fortawesome/fontawesome-free/css/fontawesome.min.css';
import '@fortawesome/fontawesome-free/css/solid.min.css';
import '@fortawesome/fontawesome-free/css/regular.min.css';
import '../css/app.css';
```

`resources/js/player.js` adds:

```js
import 'plyr/dist/plyr.css';
import plyrIconUrl from '../images/plyr.svg?url';
```

Use native HLS before importing HLS.js:

```js
const needsHlsLibrary = videos.some((video) => (
    video.dataset.hlsSrc
    && video.canPlayType('application/vnd.apple.mpegurl') === ''
    && video.canPlayType('application/x-mpegURL') === ''
));
```

Pass the local sprite without changing controls or translations:

```js
new Plyr(video, {
    controls: playerControls,
    i18n: playerTranslations,
    iconUrl: plyrIconUrl,
});
```

- [ ] **Step 5: Remove the ineffective Latin-only font**

Remove the `bunny` import and `fonts` option from `vite.config.js`, remove `@use('Illuminate\Support\Facades\Vite')` and `Vite::fonts(...)` from the layout, and set:

```css
@theme {
    --font-sans: ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol',
        'Noto Color Emoji';
}
```

Append `registry=https://registry.npmjs.org/` to `.npmrc` and replace only the hostname `registry.npmmirror.com` with `registry.npmjs.org` in `package-lock.json`. Keep every package version, integrity hash, dependency edge, and lockfile version unchanged.

- [ ] **Step 6: Verify GREEN and the emitted bundle**

Run: `php artisan test tests/Unit/FrontendAssetContractTest.php`

Expected: PASS, 1 test.

Run: `npm run build`

Expected: exit 0; manifest contains a local Plyr SVG; emitted assets do not include `fa-brands`, `fa-v4compatibility`, or Instrument Sans files.

Run: `rg -n "fa-brands|fa-v4compatibility|instrument-sans" public/build/manifest.json public/build/assets public/build/fonts-manifest.json`

Expected: no matches; `fonts-manifest.json` may be absent after removing the font plugin configuration.

Run: `npm audit --registry=https://registry.npmjs.org/`

Expected: 0 vulnerabilities.

### Task 2: Build The Accessible Shell And Visual Tokens

**Files:**

- Modify: `tests/Feature/CatalogVisualSystemTest.php`
- Create: `resources/views/components/layout/site-header.blade.php`
- Create: `resources/views/components/layout/site-footer.blade.php`
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `resources/css/app.css`

**Interfaces:**

- Consumes: `$siteName`, `$layoutSearchQuery`, named routes, and `#main-content`.
- Produces: `data-site-header`, `data-site-footer`, one `<main>`, named search/nav landmarks, active navigation, and global focus/reduced-motion behavior.

- [ ] **Step 1: Write failing shell tests**

```php
<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogVisualSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_shell_has_accessible_landmarks_and_current_navigation(): void
    {
        $response = $this->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('href="#main-content"', false)
            ->assertSee('data-site-header', false)
            ->assertSee('aria-label="Поиск по всему каталогу"', false)
            ->assertSee('aria-label="Основная навигация"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('data-site-footer', false);

        $this->assertSame(1, substr_count($response->getContent(), '<main'));
    }
}
```

- [ ] **Step 2: Run the shell test and verify RED**

Run: `php artisan test --filter=CatalogVisualSystemTest::test_public_shell_has_accessible_landmarks_and_current_navigation`

Expected: FAIL because skip-link, named landmarks, active state, footer, and single-main contract are missing.

- [ ] **Step 3: Create the explicit header component**

The component starts with:

```blade
@props(['siteName', 'searchQuery' => ''])

<header data-site-header class="border-b border-slate-200 bg-white shadow-panel lg:sticky lg:top-0 lg:z-50">
    <div class="mx-auto grid max-w-[1760px] grid-cols-[minmax(0,1fr)_auto] items-center gap-3 px-3 py-3 sm:px-6 lg:grid-cols-[auto_minmax(280px,1fr)_auto] lg:px-8">
        <a href="{{ route('home') }}" class="order-1 flex min-w-0 items-center gap-3 rounded-control">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-emerald-50 text-lg text-emerald-700 ring-1 ring-emerald-100">
                <i class="fa-solid fa-film" aria-hidden="true"></i>
            </span>
            <span class="min-w-0 break-words text-lg font-black tracking-tight text-slate-800">{{ $siteName }}</span>
        </a>

        <form action="{{ route('titles.index') }}" method="GET" role="search" aria-label="Поиск по всему каталогу" class="order-3 col-span-2 flex min-w-0 items-start gap-2 lg:order-2 lg:col-span-1 lg:mx-6">
            <x-form.search-field
                id="site-search"
                name="q"
                :value="$searchQuery"
                label="Поиск по всему каталогу"
                placeholder="Название, актер или жанр"
                container-class="min-w-0 flex-1"
                input-class="min-h-11 min-w-0 flex-1 border-0 bg-transparent px-3 py-2.5 text-sm text-slate-700 outline-none placeholder:text-slate-500"
            />
            <button type="submit" class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-600">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <span class="sr-only sm:not-sr-only">Найти</span>
            </button>
        </form>

        <nav aria-label="Основная навигация" class="order-2 flex items-center gap-1.5 text-sm font-bold lg:order-3">
            <a href="{{ route('home') }}" @class([
                'inline-flex min-h-11 items-center gap-2 rounded-control px-3 py-2',
                'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100' => request()->routeIs('home'),
                'text-slate-600 hover:bg-slate-50 hover:text-emerald-700' => ! request()->routeIs('home'),
            ]) @if (request()->routeIs('home')) aria-current="page" @endif>
                <i class="fa-solid fa-house" aria-hidden="true"></i>
                <span class="sr-only xl:not-sr-only">Главная</span>
            </a>
            <a href="{{ route('titles.index') }}" @class([
                'inline-flex min-h-11 items-center gap-2 rounded-control px-3 py-2',
                'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100' => request()->routeIs('titles.*'),
                'text-slate-600 hover:bg-slate-50 hover:text-emerald-700' => ! request()->routeIs('titles.*'),
            ]) @if (request()->routeIs('titles.*')) aria-current="page" @endif>
                <i class="fa-solid fa-table-cells-large" aria-hidden="true"></i>
                <span class="sr-only xl:not-sr-only">Каталог</span>
            </a>
        </nav>
    </div>
</header>
```

The home link uses `aria-current="page"` only for `request()->routeIs('home')`; the catalog link uses it for `request()->routeIs('titles.*')`. The search form uses `role="search"`, `aria-label="Поиск по всему каталогу"`, and the existing `x-form.search-field`.

- [ ] **Step 4: Create the real-route footer component**

```blade
@props(['siteName'])

<footer data-site-footer class="mt-8 border-t border-slate-200 bg-white">
    <div class="mx-auto flex max-w-[1760px] flex-col gap-4 px-3 py-6 text-sm text-slate-600 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
        <div class="inline-flex items-center gap-2 font-bold text-slate-700">
            <i class="fa-solid fa-film text-emerald-700" aria-hidden="true"></i>
            <span>{{ $siteName }}</span>
        </div>
        <nav aria-label="Техническая навигация" class="flex flex-wrap gap-2">
            <a href="{{ route('titles.index') }}" class="inline-flex min-h-11 items-center rounded-control px-3 py-2 font-semibold hover:bg-emerald-50 hover:text-emerald-700">Каталог</a>
            <a href="{{ route('sitemap') }}" class="inline-flex min-h-11 items-center rounded-control px-3 py-2 font-semibold hover:bg-emerald-50 hover:text-emerald-700">Карта сайта</a>
            <a href="{{ route('feed') }}" class="inline-flex min-h-11 items-center rounded-control px-3 py-2 font-semibold hover:bg-emerald-50 hover:text-emerald-700">RSS</a>
        </nav>
    </div>
</footer>
```

- [ ] **Step 5: Wire the shell and tokens**

The first body child is:

```blade
<a href="#main-content" class="fixed left-3 top-3 z-[100] -translate-y-24 rounded-control bg-emerald-700 px-4 py-3 font-bold text-white shadow-lg transition focus:translate-y-0">
    Перейти к содержанию
</a>
```

Render `x-layout.site-header`, keep the existing `main#main-content`, render `x-layout.site-footer` after main, and keep conditional Livewire scripts after the footer.

Extend `@theme`:

```css
--color-aurora-50: oklch(0.98 0.02 183);
--color-aurora-100: oklch(0.95 0.045 183);
--color-aurora-600: oklch(0.55 0.13 183);
--radius-control: 0.875rem;
--radius-panel: 1.25rem;
--shadow-panel: 0 18px 45px -34px oklch(0.42 0.08 183 / 0.45), 0 1px 2px oklch(0.2 0.02 250 / 0.06);
--shadow-panel-hover: 0 24px 55px -34px oklch(0.5 0.13 183 / 0.55), 0 8px 20px -16px oklch(0.2 0.02 250 / 0.18);
```

Add base focus, selection, canvas, and motion rules:

```css
@layer base {
    :root { color-scheme: light; }
    body { background-color: var(--color-slate-50); background-image: radial-gradient(circle at top left, var(--color-emerald-50), transparent 34rem), radial-gradient(circle at top right, var(--color-cyan-50), transparent 30rem); }
    :where(a, button, input, select, summary, [tabindex]):focus-visible { outline: 3px solid var(--color-emerald-500); outline-offset: 3px; }
    ::selection { background: var(--color-emerald-100); color: var(--color-slate-800); }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { scroll-behavior: auto !important; transition-duration: 0.01ms !important; animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; }
}
```

- [ ] **Step 6: Verify GREEN**

Run: `php artisan test --filter=CatalogVisualSystemTest::test_public_shell_has_accessible_landmarks_and_current_navigation`

Expected: PASS.

### Task 3: Upgrade Shared Surfaces And Use One Title Tab-Stop

**Files:**

- Modify: `tests/Feature/CatalogVisualSystemTest.php`
- Modify: `resources/views/components/ui/panel.blade.php`
- Modify: `resources/views/components/ui/taxonomy-chip.blade.php`
- Modify: `resources/views/components/ui/status-pill.blade.php`
- Modify: `resources/views/components/form/search-field.blade.php`
- Modify: `resources/views/components/title-card.blade.php`
- Modify: `resources/views/components/title-list-row.blade.php`
- Modify: `resources/views/components/title-poster.blade.php`
- Modify: `resources/views/components/stat.blade.php`
- Modify: `resources/css/app.css`

**Interfaces:**

- Consumes: the existing public component props and class-component computed values.
- Produces: unchanged component APIs, responsive row cards below `sm`, vertical cards at `sm+`, one show-route link per title card, and overlay-safe taxonomy links.

- [ ] **Step 1: Add the failing card keyboard test**

```php
public function test_title_card_uses_one_title_link_and_keeps_relation_links_accessible(): void
{
    $title = CatalogTitle::factory()->create();
    $html = $this->blade('<x-title-card :title="$title" />', ['title' => $title])->render();
    $showUrl = route('titles.show', $title);

    $this->assertSame(1, substr_count($html, 'href="'.$showUrl.'"'));
    $this->assertStringContainsString('data-catalog-card', $html);
    $this->assertStringContainsString('catalog-card', $html);
}
```

- [ ] **Step 2: Run the test and verify RED**

Run: `php artisan test --filter=CatalogVisualSystemTest::test_title_card_uses_one_title_link_and_keeps_relation_links_accessible`

Expected: FAIL because the poster and title currently create two show-route links.

- [ ] **Step 3: Make the card responsive and overlay-safe**

Use this structure:

```blade
<article data-catalog-card class="catalog-card group relative grid min-w-0 grid-cols-[5.5rem_minmax(0,1fr)] overflow-hidden rounded-panel border border-slate-200 bg-white shadow-panel transition sm:flex sm:h-full sm:flex-col motion-safe:hover:-translate-y-0.5 motion-safe:hover:shadow-panel-hover">
    <div class="relative bg-slate-50 sm:w-full">
        <x-title-poster :title="$title" class="aspect-[2/3] w-full rounded-none border-0" image-class="h-full w-full object-contain" />
    </div>
    <div class="flex min-w-0 flex-1 flex-col p-3 sm:p-4">
        <div class="flex min-w-0 flex-wrap items-center gap-2 text-xs font-semibold text-slate-500">
            <span class="inline-flex min-w-0 items-center gap-1">
                <i class="fa-solid fa-tv shrink-0 text-[0.85em] text-slate-400" aria-hidden="true"></i>
                <span>{{ $title->type === 'serial' ? 'сериал' : $title->type }}</span>
            </span>
            @if ($title->year)
                <span class="inline-flex items-center gap-1">
                    <i class="fa-solid fa-calendar-days shrink-0 text-[0.85em] text-slate-400" aria-hidden="true"></i>
                    <span>{{ $title->year }}</span>
                </span>
            @endif
        </div>
        <h3 class="mt-2 text-base font-bold leading-6">
            <a href="{{ route('titles.show', $title) }}" class="break-words text-slate-800 after:absolute after:inset-0 hover:text-emerald-700">
                {{ $title->title }}
            </a>
        </h3>
        <div class="mt-3 flex flex-wrap gap-1.5 text-xs font-bold">
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-emerald-700 ring-1 ring-emerald-100">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <span>{{ $seasonsCount }} сезон(ов)</span>
            </span>
            <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-1 text-sky-700 ring-1 ring-sky-100">
                <i class="fa-solid fa-circle-play" aria-hidden="true"></i>
                <span>{{ $episodesCount }} серий</span>
            </span>
            @if ($mediaCount > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-1 text-amber-700 ring-1 ring-amber-100">
                    <i class="fa-solid fa-file-video" aria-hidden="true"></i>
                    <span>{{ $mediaCount }} видео</span>
                </span>
            @endif
        </div>
        <div class="relative z-10 mt-3 flex flex-wrap gap-1.5">
            @foreach ($cardRelations as $taxonomy)
                <x-ui.taxonomy-chip :taxonomy="$taxonomy" />
            @endforeach
        </div>
    </div>
</article>
```

Preserve every existing count and label. Only the show-route link is consolidated; taxonomy links remain above the overlay with `relative z-10`.

- [ ] **Step 4: Restyle the shared primitives without changing props**

Use `rounded-panel shadow-panel` for panels, `rounded-control` for inputs and list rows, `text-slate-500` instead of visible `text-slate-400`, and `min-h-11` on clickable chips/buttons. Decorative icons may remain `text-slate-400`.

Add:

```css
@layer components {
    .catalog-card {
        content-visibility: auto;
        contain-intrinsic-size: 24rem;
    }
}
```

- [ ] **Step 5: Verify shared component GREEN**

Run: `php artisan test tests/Feature/CatalogVisualSystemTest.php tests/Feature/CatalogBladeComponentTest.php tests/Unit/BladeTemplateTest.php`

Expected: PASS; existing relation, media, anchor, no-inline-PHP, and no-truncation assertions remain green.

### Task 4: Put Search First On Home And Playback First On Title Pages

**Files:**

- Modify: `tests/Feature/CatalogVisualSystemTest.php`
- Modify: `resources/views/catalog/index.blade.php`
- Modify: `resources/views/catalog/show.blade.php`

**Interfaces:**

- Consumes: existing home and show view data only.
- Produces: `data-home-hero`, `data-home-metrics`, `data-title-hero`, valid single-main HTML, compact update rows, all countries available through disclosure, and player before secondary metadata.

- [ ] **Step 1: Add failing page-order tests**

```php
public function test_home_starts_with_search_hero_before_metrics(): void
{
    $response = $this->get(route('home'));

    $response
        ->assertOk()
        ->assertSee('data-home-hero', false)
        ->assertSee('aria-label="Поиск на главной"', false)
        ->assertSee('data-home-metrics', false)
        ->assertSeeInOrder(['data-home-hero', 'data-home-metrics'], false);
}

public function test_title_page_places_player_before_secondary_reference_metadata(): void
{
    $title = CatalogTitle::factory()->create();
    $response = $this->get(route('titles.show', $title));

    $response
        ->assertOk()
        ->assertSee('data-title-hero', false)
        ->assertSeeInOrder(['data-title-hero', 'id="player"', 'data-title-reference'], false);
}
```

- [ ] **Step 2: Run both tests and verify RED**

Run: `php artisan test --filter='CatalogVisualSystemTest::test_(home_starts|title_page_places)'`

Expected: FAIL because the markers and required order do not exist.

- [ ] **Step 3: Reorder the home page**

Replace the beginning of `@section('content')` with this order:

```blade
<div class="space-y-5">
    <x-ui.panel data-home-hero :pad="false" class="overflow-hidden border-emerald-100">
        <div class="grid gap-5 bg-gradient-to-br from-white via-emerald-50 to-cyan-50 p-4 sm:p-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
            <div class="min-w-0">
                <h1 class="flex items-start gap-3 text-3xl font-black tracking-tight text-slate-800 sm:text-4xl">
                    <span class="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-white text-lg text-emerald-700 shadow-sm ring-1 ring-emerald-100">
                        <i class="fa-solid fa-clapperboard" aria-hidden="true"></i>
                    </span>
                    <span>Сериалы онлайн</span>
                </h1>
                <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600 sm:text-base">
                    Поиск по названиям, актерам, жанрам, странам и другим связям каталога.
                </p>

                <form action="{{ route('titles.index') }}" method="GET" role="search" aria-label="Поиск на главной" class="mt-5 flex max-w-3xl items-start gap-2">
                    <x-form.search-field
                        id="home-search"
                        name="q"
                        value=""
                        label="Поиск на главной"
                        placeholder="Название, актер или жанр"
                        container-class="min-w-0 flex-1"
                        input-class="min-h-12 min-w-0 flex-1 border-0 bg-transparent px-4 py-3 text-base text-slate-700 outline-none placeholder:text-slate-500"
                    />
                    <button type="submit" class="inline-flex min-h-12 shrink-0 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-3 font-bold text-white hover:bg-emerald-600 sm:px-6">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <span class="sr-only sm:not-sr-only">Найти</span>
                    </button>
                </form>
            </div>

            <nav aria-label="Быстрые переходы" class="flex flex-wrap gap-2 lg:max-w-sm lg:justify-end">
                <a href="{{ route('titles.index') }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-emerald-700 shadow-sm ring-1 ring-emerald-100 hover:bg-emerald-100">
                    <i class="fa-solid fa-table-cells-large" aria-hidden="true"></i>
                    <span>Все сериалы</span>
                </a>
                <a href="{{ route('titles.year', ['year' => now()->year]) }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-sky-700 shadow-sm ring-1 ring-sky-100 hover:bg-sky-50">
                    <i class="fa-solid fa-sparkles" aria-hidden="true"></i>
                    <span>Новинки</span>
                </a>
                @if (($subtitleTag?->catalog_titles_count ?? 0) > 0)
                    <a href="{{ route('titles.index', ['tag' => 'subtitry']) }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-amber-700 shadow-sm ring-1 ring-amber-100 hover:bg-amber-50">
                        <i class="fa-solid fa-closed-captioning" aria-hidden="true"></i>
                        <span>С субтитрами</span>
                    </a>
                @endif
            </nav>
        </div>
    </x-ui.panel>

    <div data-home-metrics class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <x-stat label="Сериалов" :value="$stats['titles']" icon="fa-solid fa-clapperboard" />
        <x-stat label="Серий" :value="$stats['episodes']" icon="fa-solid fa-circle-play" />
        <x-stat label="Видео" :value="$stats['videos']" icon="fa-solid fa-file-video" />
        <x-stat label="Жанров" :value="$stats['genres']" icon="fa-solid fa-masks-theater" />
        <x-stat label="Стран" :value="$stats['countries']" icon="fa-solid fa-earth-europe" />
    </div>
```

Replace nested `<main>` with `<div>`. Render update feed rows with `:show-description="false"`. Keep the first twelve countries in the visible grid and render `skip(12)` inside:

```blade
@if ($countries->count() > 12)
    <details class="group mt-3 rounded-control border border-slate-200 bg-slate-50">
        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-3 py-2 font-bold text-slate-700">
            <span>Показать все страны</span>
            <i class="fa-solid fa-chevron-down transition group-open:rotate-180" aria-hidden="true"></i>
        </summary>
        <div class="grid gap-2 border-t border-slate-200 p-3 sm:grid-cols-2 xl:grid-cols-1">
            @foreach ($countries->skip(12) as $country)
                <a href="{{ route('titles.taxonomy', ['type' => $country->filterType(), 'taxonomy' => $country->slug]) }}" class="flex min-h-11 min-w-0 items-center justify-between gap-2 rounded-control bg-white px-3 py-2 text-sm text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                    <span class="inline-flex min-w-0 items-center gap-2">
                        <i class="fa-solid fa-earth-europe text-slate-400" aria-hidden="true"></i>
                        <span class="min-w-0 break-words">{{ $country->name }}</span>
                    </span>
                    <span class="shrink-0 text-xs text-slate-500">{{ $country->catalog_titles_count }}</span>
                </a>
            @endforeach
        </div>
    </details>
@endif
```

- [ ] **Step 4: Reorder the title page**

Replace nested `<main>` with `<div>` and replace its first panel with:

```blade
<x-ui.panel data-title-hero :pad="false" class="overflow-hidden border-emerald-100">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3">
        <a href="{{ route('titles.index') }}" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
            <span>К каталогу</span>
        </a>
        <nav aria-label="Навигация по сериалу" class="flex flex-wrap gap-2">
            <a href="#player" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600">
                <i class="fa-solid fa-circle-play" aria-hidden="true"></i>
                <span>Смотреть</span>
            </a>
            <a href="#seasons" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <span>Сезоны</span>
            </a>
        </nav>
    </div>

    <article class="grid gap-5 bg-gradient-to-br from-white via-white to-emerald-50 p-4 md:grid-cols-[minmax(150px,220px)_minmax(0,1fr)] md:p-5">
        <x-title-poster :title="$title" class="mx-auto aspect-[2/3] w-44 max-w-full border border-slate-200 shadow-panel sm:w-52 md:w-full" empty-class="grid h-full place-items-center px-6 text-center text-sm text-slate-500" />

        <div class="min-w-0">
            <h1 class="flex items-start gap-3 text-2xl font-black tracking-tight text-slate-800 sm:text-3xl">
                <i class="fa-solid fa-clapperboard mt-1 text-emerald-700" aria-hidden="true"></i>
                <span>{{ $title->title }}</span>
            </h1>
            @if ($title->original_title)
                <div class="mt-2 break-words text-sm font-semibold text-slate-500">{{ $title->original_title }}</div>
            @endif

            <div class="mt-4 flex flex-wrap gap-2 text-xs font-bold">
                @if ($title->year)
                    <x-ui.taxonomy-chip :href="route('titles.year', ['year' => $title->year])" active icon="fa-solid fa-calendar-days">{{ $title->year }}</x-ui.taxonomy-chip>
                @endif
                @foreach ($ageRatings as $ageRating)
                    <x-ui.taxonomy-chip :taxonomy="$ageRating" active />
                @endforeach
                <x-ui.taxonomy-chip icon="fa-solid fa-layer-group">{{ $seasons->count() }} сезонов</x-ui.taxonomy-chip>
                <x-ui.taxonomy-chip icon="fa-solid fa-list-ol">{{ $episodeCount }} серий</x-ui.taxonomy-chip>
                <x-ui.taxonomy-chip icon="fa-solid fa-file-video">{{ $mediaCount }} видео</x-ui.taxonomy-chip>
            </div>

            <section class="mt-5 rounded-control border border-slate-200 bg-white p-4">
                <h2 class="flex items-center gap-2 text-sm font-bold text-slate-700">
                    <i class="fa-solid fa-book-open text-slate-400" aria-hidden="true"></i>
                    <span>Описание</span>
                </h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $title->description ?: 'Описание пока отсутствует.' }}</p>
            </section>

            @if ($seasons->isNotEmpty())
                <nav aria-label="Сезоны сериала" class="mt-5 flex flex-wrap gap-2">
                    @foreach ($seasons as $season)
                        <a href="#season-{{ $season->number }}" @class([
                            'inline-flex min-h-11 items-center gap-2 rounded-control px-3 py-2 text-sm font-bold ring-1',
                            'bg-emerald-50 text-emerald-700 ring-emerald-100' => $showView->isSelectedSeason($season, $loop->first),
                            'bg-white text-slate-600 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700' => ! $showView->isSelectedSeason($season, $loop->first),
                        ])>
                            <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                            <span>{{ $season->number }} сезон</span>
                        </a>
                    @endforeach
                </nav>
            @endif
        </div>
    </article>
</x-ui.panel>
```

Move actors, taxonomy rows, year row, and top taxonomy links into a new panel after `#player`:

```blade
<x-ui.panel data-title-reference title="О сериале" icon="fa-solid fa-circle-info">
    @if ($actors->isNotEmpty())
        <div>
            <div class="inline-flex items-center gap-2 text-sm font-bold text-slate-700">
                <i class="fa-solid fa-user-group text-slate-400" aria-hidden="true"></i>
                <span>В ролях</span>
            </div>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($actors->take(12) as $actor)
                    <x-ui.taxonomy-chip :taxonomy="$actor" />
                @endforeach
            </div>
        </div>
    @endif

    <dl class="mt-4 divide-y divide-slate-200 text-sm">
        @foreach ($taxonomyRows as $row)
            @if ($row['items']->isNotEmpty())
                <div class="grid gap-2 py-3 sm:grid-cols-[120px_minmax(0,1fr)]">
                    <dt class="inline-flex items-center gap-2 font-bold text-slate-500">
                        <i class="{{ $row['icon'] ?? 'fa-solid fa-tag' }} text-slate-400" aria-hidden="true"></i>
                        <span>{{ $row['label'] }}</span>
                    </dt>
                    <dd class="flex flex-wrap gap-1.5">
                        @foreach ($row['items'] as $taxonomy)
                            <x-ui.taxonomy-chip :taxonomy="$taxonomy" />
                        @endforeach
                    </dd>
                </div>
            @endif
        @endforeach
        @if ($title->year)
            <div class="grid gap-2 py-3 sm:grid-cols-[120px_minmax(0,1fr)]">
                <dt class="font-bold text-slate-500">Вышел</dt>
                <dd><a href="{{ route('titles.year', ['year' => $title->year]) }}" class="font-bold text-emerald-700">{{ $title->year }}</a></dd>
            </div>
        @endif
    </dl>

    @if ($topTaxonomies->isNotEmpty())
        <div class="mt-4 flex flex-wrap gap-2">
            @foreach ($topTaxonomies as $taxonomy)
                <x-ui.taxonomy-chip :taxonomy="$taxonomy" />
            @endforeach
        </div>
    @endif
</x-ui.panel>
```

Do not change player, playback option, season anchor, recommendation, FAQ, or aside code.

- [ ] **Step 5: Verify GREEN**

Run: `php artisan test tests/Feature/CatalogVisualSystemTest.php tests/Feature/CatalogPageTest.php tests/Feature/CatalogBladeComponentTest.php`

Expected: PASS; title state and recommendation assertions remain unchanged.

### Task 5: Replace Vendor Pagination With A Russian Light-Only View

**Files:**

- Modify: `tests/Feature/CatalogVisualSystemTest.php`
- Create: `lang/ru/pagination.php`
- Create: `resources/views/vendor/pagination/tailwind.blade.php`

**Interfaces:**

- Consumes: Laravel's default `pagination::tailwind` view namespace and paginator `$elements` contract.
- Produces: Russian previous/next/result copy, no `dark:` utilities, `aria-current="page"`, and 44 px controls without changing `/titles`.

- [ ] **Step 1: Add the failing pagination test**

```php
public function test_catalog_pagination_is_russian_and_light_only(): void
{
    CatalogTitle::factory()->count(30)->create();

    $response = $this->get(route('titles.index'));

    $response
        ->assertOk()
        ->assertSeeText('Назад')
        ->assertSeeText('Вперед')
        ->assertDontSeeText('pagination.previous')
        ->assertDontSeeText('pagination.next')
        ->assertDontSee('dark:', false);
}
```

- [ ] **Step 2: Run and verify RED**

Run: `php artisan test --filter=CatalogVisualSystemTest::test_catalog_pagination_is_russian_and_light_only`

Expected: FAIL with untranslated keys and vendor `dark:` classes.

- [ ] **Step 3: Add Russian translation keys**

```php
<?php

return [
    'previous' => 'Назад',
    'next' => 'Вперед',
];
```

- [ ] **Step 4: Create the project paginator override**

```blade
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Страницы каталога" class="flex flex-col gap-3 rounded-panel border border-slate-200 bg-white p-3 shadow-panel sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm font-semibold text-slate-600">
            Показано
            <span class="font-black text-slate-800">{{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }}</span>
            из <span class="font-black text-slate-800">{{ $paginator->total() }}</span>
        </p>

        <div class="flex flex-wrap items-center gap-1.5">
            @if ($paginator->onFirstPage())
                <span aria-disabled="true" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-400 ring-1 ring-slate-200">
                    <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                    <span class="sr-only sm:not-sr-only">{{ __('pagination.previous') }}</span>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                    <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                    <span class="sr-only sm:not-sr-only">{{ __('pagination.previous') }}</span>
                </a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span aria-disabled="true" class="inline-flex min-h-11 min-w-11 items-center justify-center text-sm font-bold text-slate-500">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page === $paginator->currentPage())
                            <span aria-current="page" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control bg-emerald-700 px-3 py-2 text-sm font-black text-white">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" aria-label="Страница {{ $page }}" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-white px-3 py-2 text-sm font-bold text-slate-600 ring-1 ring-slate-200 hover:bg-emerald-50 hover:text-emerald-700">
                    <span class="sr-only sm:not-sr-only">{{ __('pagination.next') }}</span>
                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                </a>
            @else
                <span aria-disabled="true" class="inline-flex min-h-11 items-center gap-2 rounded-control bg-slate-50 px-3 py-2 text-sm font-bold text-slate-400 ring-1 ring-slate-200">
                    <span class="sr-only sm:not-sr-only">{{ __('pagination.next') }}</span>
                    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                </span>
            @endif
        </div>
    </nav>
@endif
```

- [ ] **Step 5: Verify GREEN**

Run: `php artisan test --filter=CatalogVisualSystemTest::test_catalog_pagination_is_russian_and_light_only`

Expected: PASS.

### Task 6: Document, Format, Build, And QA In Isolation

**Files:**

- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `docs/UI_STANDARDS.md`
- Verify all files from Tasks 1–5.

**Interfaces:**

- Consumes: completed implementation and the isolated QA fixture database.
- Produces: accurate project documentation, formatted PHP, production frontend build, focused green suite, and desktop/tablet/mobile evidence.

- [ ] **Step 1: Update factual documentation**

Document the system font stack, split FontAwesome imports, local Vite Plyr sprite, CSS-first token names, one-tab-stop title cards, search-first home order, player-first title order, skip-link/current navigation, and Russian pagination. Do not rewrite managed `project-docs` blocks.

- [ ] **Step 2: Format changed PHP**

Run: `./vendor/bin/pint --dirty --format agent`

Expected: exit 0 and only this plan's PHP files formatted.

- [ ] **Step 3: Run focused tests**

Run: `php artisan test tests/Unit/FrontendAssetContractTest.php tests/Unit/BladeTemplateTest.php tests/Feature/CatalogVisualSystemTest.php tests/Feature/CatalogBladeComponentTest.php tests/Feature/CatalogPageTest.php`

Expected: all selected tests pass with zero failures.

- [ ] **Step 4: Build frontend**

Run: `npm run build`

Expected: exit 0, no FontAwesome brands/v4 fonts, no Instrument Sans assets, local Plyr sprite present.

- [ ] **Step 5: Create an isolated QA database**

Create `output/playwright/catalog-visual-qa.sqlite` as an empty file via the patch tool. Run migrations only against it:

Run: `DB_CONNECTION=sqlite DB_DATABASE=$PWD/output/playwright/catalog-visual-qa.sqlite php artisan migrate --force`

Expected: migration success against the isolated file; the configured project database is untouched.

Create the ignored `output/playwright/seed-visual-qa.php` with:

```php
<?php

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use Illuminate\Contracts\Console\Kernel;

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$playableTitle = CatalogTitle::factory()->create([
    'title' => 'Визуальная проверка',
    'slug' => 'vizualnaia-proverka',
    'poster_url' => '/favicon.ico',
    'description' => 'Описание для проверки переноса текста и порядка блоков страницы.',
    'year' => 2026,
]);
$season = Season::factory()->create([
    'catalog_title_id' => $playableTitle->id,
    'number' => 1,
    'title' => 'Сезон 1',
]);
$episode = Episode::factory()->create([
    'season_id' => $season->id,
    'number' => 1,
    'title' => 'Первая серия',
]);
LicensedMedia::factory()->create([
    'catalog_title_id' => $playableTitle->id,
    'season_id' => $season->id,
    'episode_id' => $episode->id,
    'title' => 'Локальное видео QA',
    'path' => 'data:video/mp4;base64,',
    'playback_url' => 'data:video/mp4;base64,',
    'format' => 'mp4',
    'status' => 'published',
    'published_at' => now(),
]);

CatalogTitle::factory()->create([
    'title' => 'Карточка без видео',
    'slug' => 'kartocka-bez-video',
    'poster_url' => null,
]);

CatalogTitle::factory()->count(30)->create();

echo "vizualnaia-proverka\nkartocka-bez-video\n";
```

Run: `DB_CONNECTION=sqlite DB_DATABASE=$PWD/output/playwright/catalog-visual-qa.sqlite php output/playwright/seed-visual-qa.php`

Expected: the two deterministic slugs are printed.

Start the isolated server:

Run: `DB_CONNECTION=sqlite DB_DATABASE=$PWD/output/playwright/catalog-visual-qa.sqlite php artisan serve --host=127.0.0.1 --port=8014`

Expected: local server listens on `http://127.0.0.1:8014`; the existing server and importer are untouched.

- [ ] **Step 6: Run Playwright browser QA**

Capture `/`, `/titles`, a populated title, and an empty-media title at `320x720`, `390x844`, `768x1024`, and `1440x1200`. Record HTTP status, H1, headings, `documentElement.scrollWidth <= clientWidth`, console/page errors, failed local assets, main landmark count, and screenshots under `output/playwright/after-*`.

Expected: status 200, one main and one H1, no horizontal overflow, no console/page errors, no failed local assets, Russian pagination, player before reference metadata, and search hero before metrics.

- [ ] **Step 7: Review the final diff against the specification**

Run: `git diff --check`

Run: `git status --short`

Run: `git diff -- resources/css resources/js resources/views vite.config.js lang tests docs/frontend.md docs/views.md docs/UI_STANDARDS.md docs/superpowers/specs/2026-07-12-catalog-visual-system-design.md docs/superpowers/plans/2026-07-12-catalog-visual-system.md`

Expected: no whitespace errors; no concurrent search files in the visual diff; every implemented requirement maps to Tasks 1–6.
