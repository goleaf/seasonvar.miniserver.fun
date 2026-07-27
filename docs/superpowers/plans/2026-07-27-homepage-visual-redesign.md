# Homepage Visual Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Полностью обновить визуальную композицию главной и сделать постеры
всех домашних title cards кликабельными, сохранив data/API/route contracts.

**Architecture:** Существующий `CatalogHomePage` и
`CatalogHomePageBuilder::webData()` остаются единственными page/data
boundaries. Blade получает только section presentation, три homepage-layout
варианта `PosterCard` получают белую card surface, а основная title-ссылка
использует существующий CSS overlay pattern. Guest cache ротируется exact
response contract без flush.

**Tech Stack:** PHP 8.5, Laravel 13.22, Livewire 4.3, Blade, Tailwind CSS
4.3, Vite 8, PHPUnit 12.5, Playwright 1.61.

## Global Constraints

- Работать только в существующей `main`; branch/worktree не создавать.
- Новые dependencies, migrations, data writes и external assets запрещены.
- Guest/auth section order, full facets, RU/EN routes и `/api/v1/home`
  сохраняются.
- Blade остаётся passive: без `@php`, queries, inline CSS и business JS.
- Светлая тема; emerald action, amber trend, sky information, slate neutral,
  red only error.
- Essential controls не меньше `44px`; no horizontal overflow.
- Каждый production change начинается с доказанного RED.

---

### Task 1: Зафиксировать кликабельный homepage-card contract

**Files:**
- Modify: `tests/Feature/CatalogBladeComponentTest.php`
- Modify: `tests/Feature/CatalogHomepageRedesignTest.php`
- Modify: `resources/views/components/catalog/title-card-home.blade.php`
- Modify: `resources/views/components/catalog/title-card-trend.blade.php`
- Modify: `resources/views/components/catalog/latest-media-card.blade.php`
- Modify: `resources/views/livewire/catalog-home-page.blade.php`

**Interfaces:**
- Consumes: existing `route('titles.show', $title)` and relative
  `x-ui.poster-card` root.
- Produces: one `data-home-title-link` overlay per rendered homepage title;
  nested controls remain `relative z-10`.

- [ ] **Step 1: Write failing component and homepage tests**

Add assertions that `home`, `trend` and latest-media markup contains:

```php
$this->assertStringContainsString('data-home-title-link', $html);
$this->assertStringContainsString('after:absolute', $html);
$this->assertStringContainsString('after:inset-0', $html);
$this->assertStringContainsString('focus-visible:after:ring-4', $html);
```

For authenticated Continue Watching, assert the overlay href contains the
exact episode query and `#player`, while the CTA remains
`class="... relative z-10 ..."`.

- [ ] **Step 2: Run RED**

Run:

```bash
php artisan test tests/Feature/CatalogBladeComponentTest.php tests/Feature/CatalogHomepageRedesignTest.php
```

Expected: FAIL because `data-home-title-link` and full overlay focus classes
do not exist.

- [ ] **Step 3: Add minimal overlay links**

Use this class contract on the existing title anchor:

```blade
data-home-title-link
class="cursor-pointer break-words after:absolute after:inset-0 after:rounded-panel
focus-visible:outline-none focus-visible:after:ring-4
focus-visible:after:ring-emerald-200 focus-visible:after:ring-inset"
```

Keep taxonomy/action containers above the overlay with `relative z-10`.

- [ ] **Step 4: Run GREEN**

Run the same focused command. Expected: all tests PASS.

### Task 2: Создать новую section composition

**Files:**
- Modify: `tests/Feature/CatalogHomepageRedesignTest.php`
- Modify: `app/View/Components/Ui/PosterCard.php`
- Modify: `resources/views/livewire/catalog-home-page.blade.php`
- Modify: `resources/views/components/catalog/home-trending-grid.blade.php`

**Interfaces:**
- Consumes: current `data-home-section` order and existing translated labels.
- Produces: stable `data-home-surface` and `data-home-section-action`
  presentation markers; `PosterCard` layouts `home|spotlight|trend` render a
  white bordered interactive card.

- [ ] **Step 1: Write failing presentation tests**

Assert guest HTML contains:

```php
->assertSee('data-home-surface="amber"', false)
->assertSee('data-home-surface="sky"', false)
->assertSee('data-home-surface="emerald"', false)
->assertSee('data-home-surface="slate"', false)
->assertSee('data-home-section-action', false);
```

Assert authenticated HTML contains emerald, sky, amber and slate surfaces
without changing its required section order.

- [ ] **Step 2: Run RED**

```bash
php artisan test tests/Feature/CatalogHomepageRedesignTest.php
```

Expected: FAIL on the new surface/action markers.

- [ ] **Step 3: Implement approved layout**

Apply large section classes:

```text
rounded-panel border p-4 sm:p-5 lg:p-6
amber: border-amber-200 bg-amber-50/70
sky: border-sky-200 bg-sky-50/70
emerald: border-emerald-200 bg-emerald-50/70
slate: border-slate-200 bg-slate-100
```

Section actions use `inline-flex min-h-11 items-center rounded-control` with
visible border and `focus-visible:ring-4`. Do not add new UI copy.

Update only `PosterCard::ROOT_CLASSES` keys:

```php
'home' => 'flex h-full flex-col rounded-panel border border-slate-200 bg-white p-2.5 hover:border-emerald-300 focus-within:border-emerald-500',
'spotlight' => 'flex h-full flex-col rounded-panel border border-slate-200 bg-white p-3 hover:border-emerald-300 focus-within:border-emerald-500 lg:grid ...',
'trend' => 'flex h-full flex-col rounded-panel border border-slate-200 bg-white p-2.5 hover:border-emerald-300 focus-within:border-emerald-500 lg:grid ...',
```

- [ ] **Step 4: Run GREEN and regression tests**

```bash
php artisan test tests/Feature/CatalogHomepageRedesignTest.php tests/Unit/BladeTemplateTest.php
```

Expected: PASS.

### Task 3: Ротировать guest homepage cache

**Files:**
- Modify: `tests/Unit/PublicPageCachePolicyTest.php`
- Modify: `app/Support/Cache/PublicPageCachePolicy.php`

**Interfaces:**
- Consumes: homepage cache context dimensions.
- Produces: exact `response_contract=3`; all other profiles unchanged.

- [ ] **Step 1: Change the unit expectation to 3**

```php
$this->assertSame(3, $homepage->dimensions['response_contract']);
```

- [ ] **Step 2: Run RED**

```bash
php artisan test tests/Unit/PublicPageCachePolicyTest.php
```

Expected: FAIL with actual value `2`.

- [ ] **Step 3: Apply the exact contract bump**

```php
if ($profile === 'homepage') {
    $dimensions['translations'] = $this->homepageTranslationFingerprint();
    $dimensions['response_contract'] = 3;
}
```

- [ ] **Step 4: Run GREEN**

Run the same unit test. Expected: PASS and other profile values unchanged.

### Task 4: Добавить browser acceptance

**Files:**
- Create: `tests/browser/homepage-redesign.spec.js`

**Interfaces:**
- Consumes: browser fixture `browser-smoke`, `browser@example.com` and
  `data-home-*` markers.
- Produces: poster-click, keyboard-focus, responsive surface and overflow
  regression.

- [ ] **Step 1: Add Playwright scenarios**

Cover guest `/`, localized `/en`, authenticated `/`, and click the center of
`[data-ui-poster-card-media]` inside a card containing `Browser Smoke`.
Expected URL matches `/titles/browser-smoke` or exact episode player URL.
Assert all required surfaces visible, section actions have height at least
44px, focused overlay has a visible outline/ring, and
`scrollWidth <= clientWidth`.

- [ ] **Step 2: Build and run focused browser matrix**

```bash
npm run build
npx playwright test tests/browser/homepage-redesign.spec.js \
  --project="Desktop Chromium" --project="Mobile Chromium" \
  --project="Tablet Chromium"
PLAYWRIGHT_DEVICE_MATRIX=extended \
  npx playwright test tests/browser/homepage-redesign.spec.js
```

Expected: all scenarios PASS with no local console/network errors.

### Task 5: Verification, documentation and delivery

**Files:**
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `docs/caching.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `docs/plans/task-119-homepage-redesign-compliance.md`
- Create: `docs/plans/archive/2026-07-27-task-119-homepage-redesign-evidence.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: verified test/build/browser evidence.
- Produces: canonical visitor/product documentation and exact delivery
  evidence.

- [ ] **Step 1: Run focused and broad gates**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=CatalogHome
php artisan test tests/Feature/CatalogBladeComponentTest.php \
  tests/Feature/CatalogVisualSystemTest.php \
  tests/Unit/BladeTemplateTest.php \
  tests/Unit/PublicPageCachePolicyTest.php
npm run build
bash scripts/ci-check.sh backend
bash scripts/ci-check.sh frontend
```

- [ ] **Step 2: Re-read requirements and search legacy paths**

Search homepage templates/tests/docs for non-clickable title poster variants,
old cache contract `2`, missing `data-home-title-link`, stale surface rules,
dead controls and duplicate homepage implementations.

- [ ] **Step 3: Update canonical docs**

Record only verified behavior, counts and unresolved external signals.
README visitor history remains the final H2 section.

- [ ] **Step 4: Review exact staged scope and commit**

Use workspace lease `verify-paths`, staged name/diff checks,
`approve-index`, commit in `main`, then attempt `git push origin main`.

