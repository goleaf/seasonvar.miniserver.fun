# Shared Poster Card System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace every public catalog poster surface with one tested poster atom, one tested card shell, and one class-based catalog title adapter so artwork always fills its frame, cards have one outline, and page-specific behavior remains intact.

**Architecture:** `x-ui.poster-frame` is the only Blade component allowed to emit catalog artwork `<img>` markup. `x-ui.poster-card` composes the frame into strict grid, horizontal, and compact shells. `x-catalog.title-card` prepares bounded title metadata without queries and becomes the sole title-collection component used by catalog, search, homepage, and recommendation screens.

**Tech Stack:** PHP 8.5, Laravel 13.19, class-based Blade components, Livewire 4, PHPUnit 12, Tailwind CSS 4.3, Vite 8, Playwright browser QA.

## Global Constraints

- Work only on the existing `main` branch and preserve every existing user change.
- Use Laravel 13 and class-based components; do not introduce Volt or implementation PHP in Blade.
- Keep visible interface text in Russian and keep poster URLs/alt text escaped.
- Components consume prepared models/scalars and never query the database, cache, Redis, filesystem, environment, or container.
- The poster image uses cover plus two-percent overscan and never owns a border, ring, padding, shadow, or independent rounded frame.
- The poster-card root owns the only border, outer rounding, clipping, background, and optional card shadow.
- Preserve routes, Livewire keys/actions, guarded stats proxy URLs, complete title wrapping, and player behavior.
- Do not replace the video player's HTML `poster` attribute or the administration poster URL field.

---

## Task 1: Lock the visual and architectural contracts with failing tests

**Files:**

- Modify: `tests/Unit/BladeTemplateTest.php`
- Modify: `tests/Feature/CatalogVisualSystemTest.php`
- Modify: `tests/Feature/CatalogBladeComponentTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`

**Step 1: Add the poster atom and card shell contract tests**

Add focused tests that render the new tags directly:

```php
public function test_poster_frame_covers_and_overscans_without_an_inner_outline(): void
{
    $view = $this->blade('<x-ui.poster-frame src="https://media.example.com/poster.jpg" alt="Постер сериала" class="aspect-[2/3]" />');

    $view
        ->assertSee('data-ui-poster-frame', false)
        ->assertSee('data-ui-poster-image', false)
        ->assertSee('absolute inset-0 h-full w-full scale-[1.02] object-cover object-center', false)
        ->assertDontSee('object-contain', false);
}

public function test_poster_card_exposes_one_shell_and_strict_layout_markers(): void
{
    foreach (['grid', 'horizontal', 'compact'] as $layout) {
        $html = $this->blade('<x-ui.poster-card :layout="$layout" alt="Постер"><p>Описание</p></x-ui.poster-card>', compact('layout'));

        $html
            ->assertSee('data-ui-poster-card', false)
            ->assertSee('data-ui-poster-card-media', false)
            ->assertSee('data-ui-poster-card-body', false)
            ->assertSee('data-ui-poster-layout="'.$layout.'"', false);
    }
}
```

Assert the image element has no `border-*`, `ring-*`, `shadow-*`, padding, or independent rounding classes; assert the missing-poster branch keeps the same frame and shows the Russian label.

**Step 2: Convert title-component expectations to the new public API**

Change direct renders from `x-title-card` and `x-title-list-row` to:

```blade
<x-catalog.title-card :title="$title" layout="grid" />
<x-catalog.title-card :title="$title" layout="horizontal" />
<x-catalog.title-card :title="$title" layout="compact" :show-description="false" />
```

Keep assertions for one main title link, taxonomy links, separated original titles, counts, descriptions, `data-catalog-card`, and the new shared poster markers.

**Step 3: Add the repository architecture guard**

Extend `BladeTemplateTest` so it:

- permits catalog artwork `<img>` only in `resources/views/components/ui/poster-frame.blade.php`;
- rejects `<x-title-poster`, `<x-title-card`, and `<x-title-list-row` in all Blade files;
- requires poster-bearing public views to compose `x-ui.poster-frame`, `x-ui.poster-card`, or `x-catalog.title-card`;
- excludes the video player's HTML `poster` attribute and the administrator's URL input because neither is catalog card artwork.

Use explicit file-path exceptions rather than a broad regular expression that could hide future violations.

**Step 4: Add page-level shared marker assertions**

Update or add tests proving that populated versions of `/`, `/titles`, a title page with recommendations, `/watching`, and `/stats` render `data-ui-poster-frame` and that poster-card surfaces render `data-ui-poster-card`. Keep the existing guarded poster proxy assertions for statistics.

**Step 5: Run the focused tests and record the expected RED state**

Run:

```bash
php artisan test tests/Unit/BladeTemplateTest.php tests/Feature/CatalogVisualSystemTest.php tests/Feature/CatalogBladeComponentTest.php tests/Feature/CatalogPageTest.php --filter='poster|title_card|title_components|shared_marker|equal_size'
```

Expected result: failures caused by the missing `x-ui.poster-frame`, `x-ui.poster-card`, and `x-catalog.title-card` implementations and remaining legacy component references. Confirm there are no unrelated bootstrap or database failures before writing production code.

## Task 2: Implement the generic poster atom and one-outline card shell

**Files:**

- Create: `app/View/Components/Ui/PosterFrame.php`
- Create: `resources/views/components/ui/poster-frame.blade.php`
- Create: `app/View/Components/Ui/PosterCard.php`
- Create: `resources/views/components/ui/poster-card.blade.php`
- Test: `tests/Unit/BladeTemplateTest.php`
- Test: `tests/Feature/CatalogVisualSystemTest.php`

**Step 1: Implement the class-based poster atom**

Create a typed `PosterFrame` component accepting nullable `src`, accessible `alt`, Russian `emptyLabel`, and `loading`. Normalize `loading` to `lazy` unless it is exactly `lazy` or `eager`; expose a `hasImage()` method and return `components.ui.poster-frame`.

The Blade root must merge caller dimensions with:

```text
relative isolate overflow-hidden
```

The image must use:

```text
absolute inset-0 h-full w-full scale-[1.02] object-cover object-center
```

and include escaped `src`/`alt`, `decoding="async"`, `referrerpolicy="no-referrer"`, and the normalized loading mode. The empty branch uses the shared icon component and a neutral background while preserving the root dimensions.

**Step 2: Implement strict layout maps in the card class**

Create `PosterCard` constants for `grid`, `horizontal`, and `compact`. Normalize an unsupported value to `grid`, and expose typed methods for root, media, and body classes. The root owns the only `border border-slate-200`, `rounded-panel`, background, overflow clipping, and shadow classes. The media classes own only sizing/aspect behavior and never add a border, ring, shadow, background, or nested rounding.

The Blade template renders:

```blade
<article data-ui-poster-card data-ui-poster-layout="{{ $layout }}" {{ $attributes->class($rootClasses()) }}>
    <div data-ui-poster-card-media class="{{ $mediaClasses() }}">
        <x-ui.poster-frame :src="$src" :alt="$alt" :empty-label="$emptyLabel" :loading="$loading" class="h-full w-full" />
    </div>
    <div data-ui-poster-card-body class="{{ $bodyClasses() }}">{{ $slot }}</div>
</article>
```

**Step 3: Run the atom and shell tests GREEN**

Run:

```bash
php artisan test tests/Unit/BladeTemplateTest.php tests/Feature/CatalogVisualSystemTest.php --filter='poster_frame|poster_card'
```

Expected result: the atom and shell contracts pass, including missing images, layout normalization, cover/overscan, and one-outline invariants.

## Task 3: Build the query-free catalog title adapter

**Files:**

- Create: `app/View/Components/Catalog/TitleCard.php`
- Create: `resources/views/components/catalog/title-card.blade.php`
- Create: `resources/views/components/catalog/title-card-grid.blade.php`
- Create: `resources/views/components/catalog/title-card-horizontal.blade.php`
- Test: `tests/Feature/CatalogBladeComponentTest.php`
- Test: `tests/Feature/CatalogVisualSystemTest.php`

**Step 1: Consolidate existing title-card preparation**

Move the safe aggregate logic from `App\View\Components\TitleCard` and `TitleListRow` into `App\View\Components\Catalog\TitleCard`:

- `seasons_count`, loaded `seasons`, `episodes_count`, and media count attributes;
- loaded `latestSeason` only;
- loaded genres, countries, age ratings, translations, and tags only;
- a bounded taxonomy collection of three items in grid layout and four elsewhere;
- strict layout normalization and `readable`/`showDescription` presentation flags.

Do not call `load`, `loadMissing`, a relationship method, `query`, cache, or a service from the component.

**Step 2: Implement the grid layout behind the shared shell**

Move current grid metadata, title/original title, count pills, and taxonomy links into the grid view. Compose `x-ui.poster-card layout="grid"`; keep one stretched title link and `data-catalog-card` on the article through forwarded attributes.

**Step 3: Implement horizontal and compact layouts behind the same API**

Move the current readable/list behavior into the horizontal view. Select `horizontal` or `compact` on `x-ui.poster-card`, preserve the latest-season label, count pills, optional description, taxonomy links, and one primary title link. Keep long text fully wrapping.

**Step 4: Run adapter tests GREEN**

Run:

```bash
php artisan test tests/Feature/CatalogBladeComponentTest.php tests/Feature/CatalogVisualSystemTest.php --filter='title_card|title_components|title_surfaces'
```

Expected result: all three layouts render the same semantic data, no query is triggered for absent relations, and existing title/taxonomy link behavior remains unchanged.

## Task 4: Migrate the catalog, homepage, search, and title recommendations

**Files:**

- Modify: `resources/views/catalog/index.blade.php`
- Modify: `resources/views/catalog/titles.blade.php`
- Modify: `resources/views/livewire/catalog-title-detail.blade.php`
- Modify: `app/Services/Catalog/CatalogHomePageBuilder.php`
- Test: `tests/Feature/CatalogPageTest.php`
- Test: `tests/Feature/CatalogVisualSystemTest.php`

**Step 1: Migrate all normal title collections**

Replace legacy calls as follows:

- homepage latest updates and playable titles: `x-catalog.title-card layout="grid"`;
- date feed and catalog list: `layout="horizontal"`, preserving the current `readable` density where used;
- catalog grid and search results: `layout="grid"`;
- recommendation hero/fallback grid: `layout="grid"`;
- recommendation rows: `layout="compact"` or `horizontal` based on the existing density.

**Step 2: Migrate the title hero artwork**

Replace the hero's `x-title-poster` with `x-ui.poster-frame`, pass scalar `poster_url`, complete Russian alt text, an eager loading mode for the primary title artwork, the existing responsive 2:3 dimensions, and caller-owned outer rounding/shadow only where the standalone hero requires them.

**Step 3: Move latest-media normalization out of Blade**

Prepare the latest-media subtitle and display metadata in the existing homepage builder/view model instead of calling collection transforms in Blade. Render each episode-specific entry with `x-ui.poster-card layout="horizontal"` so its player link and media badges remain page-specific while its frame and outline are shared.

**Step 4: Run catalog/home/title page tests**

Run:

```bash
php artisan test tests/Feature/CatalogPageTest.php tests/Feature/CatalogVisualSystemTest.php tests/Feature/CatalogBladeComponentTest.php
```

Expected result: home, grid/list/search, title hero, and every recommendation variant render new markers while all routes, labels, counts, links, and filters still pass.

## Task 5: Migrate viewing activity and statistics surfaces

**Files:**

- Modify: `resources/views/livewire/viewing-activity.blade.php`
- Modify: `resources/views/livewire/stats-dashboard.blade.php`
- Modify: `app/Livewire/ViewingActivity.php` only if a new scalar presentation field is required by the final Blade markup
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`

**Step 1: Migrate continue-watching and history records**

Use `x-ui.poster-card` directly because these records include progress and removal controls that do not belong to normal catalog title metadata. Preserve playback URLs, unavailable states, progress labels/bars, remove actions, `wire:key`, and `wire:confirm`; keep the stretched primary link from covering the independent remove action.

**Step 2: Remove raw statistics artwork markup**

Use `x-ui.poster-frame` for standalone guarded proxy previews and `x-ui.poster-card layout="compact"` for issue rows. Pass only the already prepared `poster_src`; do not weaken or duplicate the existing proxy guard.

**Step 3: Run focused page tests**

Run:

```bash
php artisan test tests/Feature/CatalogPageTest.php --filter='stats|watching|viewing|poster'
```

The viewing-activity behavior is covered by the scoped methods in `tests/Feature/CatalogPageTest.php`; do not invent a separate test file.

Expected result: actions and guarded proxy behavior pass, every artwork image is emitted through the shared frame, and no raw statistics poster image remains.

## Task 6: Remove the legacy component system and enforce the boundary

**Files:**

- Delete: `app/View/Components/TitleCard.php`
- Delete: `app/View/Components/TitleListRow.php`
- Delete: `resources/views/components/title-card.blade.php`
- Delete: `resources/views/components/title-list-row.blade.php`
- Delete: `resources/views/components/title-poster.blade.php`
- Modify: `tests/Unit/BladeTemplateTest.php`

**Step 1: Prove all internal call sites are migrated**

Run:

```bash
rg -n '<x-title-(poster|card|list-row)' app resources tests
```

Expected result before deletion: no production references; test fixtures intentionally checking rejection may contain escaped component-name strings only.

**Step 2: Delete obsolete classes and views**

Use a patch deletion so Git records the precise removals. Do not retain compatibility wrappers that allow the architecture to drift back to three APIs.

**Step 3: Run Blade architecture and component tests**

Run:

```bash
php artisan test tests/Unit/BladeTemplateTest.php tests/Feature/CatalogBladeComponentTest.php tests/Feature/CatalogVisualSystemTest.php
```

Expected result: no legacy references, no raw catalog poster `<img>`, no PHP implementation in Blade, and the new component contract passes.

## Task 7: Synchronize project documentation

**Files:**

- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: `CHANGELOG.md`

**Step 1: Document the final component responsibilities**

Replace legacy component references with the three-layer architecture. Document the 2% overscan, one-outline rule, 2:3 poster ratio, supported layout values, standalone title-hero exception, poster proxy boundary, query-free Blade/class components, and the player `poster` non-goal.

**Step 2: Record verification evidence without editing managed blocks manually**

Add concise maintenance/changelog entries and run:

```bash
php artisan project:docs-refresh
git diff --check
```

Expected result: documentation refresh succeeds and only intended managed-block changes appear.

## Task 8: Format, verify, benchmark, and browser-test the result

**Files:**

- Verify all files changed above

**Step 1: Format and run static repository guards**

Run:

```bash
./vendor/bin/pint --dirty --format agent
composer validate --strict
git diff --check
php artisan route:list --except-vendor
php artisan config:show view
```

**Step 2: Run focused and complete PHP verification**

Run:

```bash
php artisan test tests/Unit/BladeTemplateTest.php tests/Feature/CatalogVisualSystemTest.php tests/Feature/CatalogBladeComponentTest.php tests/Feature/CatalogPageTest.php
php artisan test
```

Expected result: all supported tests pass apart from repository-declared environmental skips.

**Step 3: Build production frontend assets**

Run:

```bash
npm run build
```

Expected result: Vite 8 production build completes without Tailwind class-generation or JavaScript errors.

**Step 4: Perform responsive Playwright QA**

Start the repository's supported local server if one is not already available. Check `/`, `/titles` in grid and list mode, a populated title page, `/watching`, and `/stats` at 390px, 768px, 1280px, and 1536px widths.

For each page:

- verify no horizontal document overflow;
- inspect console and failed network requests;
- assert poster image computed `object-fit` is `cover`;
- compare frame and image rectangles and confirm the image covers or slightly exceeds both dimensions;
- count borders between `data-ui-poster-card` and `data-ui-poster-image` and confirm only the card shell has a structural border;
- verify long Russian titles wrap completely;
- exercise catalog grid/list switching and viewing remove confirmation without losing actions.

Capture screenshots for mobile and desktop evidence in a temporary untracked directory outside the repository, then remove those temporary artifacts after inspection.

**Step 5: Compare the existing and final measurements**

Use the repository's existing controlled benchmark command or tests if present; otherwise collect repeatable local route timings with the same database state and warmed compiled views before and after the migration. Record page HTML size and poster/card counts for `/`, `/titles`, and one title page. Do not claim a speedup if results are within measurement noise; report structural consistency and payload deltas separately.

## Task 9: Final audit, commit, push, and clean-tree proof

**Files:**

- Audit all changed files

**Step 1: Run the required repository searches**

Run targeted `rg` checks for Blade PHP, raw catalog poster images, legacy components, `Volt`, cache/database calls in Blade, debug output, placeholder markers, secret patterns, and the prohibited component names. Confirm the player `poster` attribute and administrator URL input are the only intentional poster-adjacent exceptions.

**Step 2: Review the complete diff and repository state**

Run:

```bash
git diff --stat
git diff --check
git status --short --branch
git diff
```

Preserve the two pre-existing local `main` commits and ensure the new commit contains only the shared poster-system implementation and synchronized documentation.

**Step 3: Commit on the existing main branch**

After confirming `git branch --show-current` prints `main`, stage the intentional files and commit with:

```bash
git commit -m "refactor: unify catalog poster cards"
```

**Step 4: Push only the existing main branch**

Run:

```bash
git push origin main
```

If repository authentication is unavailable, preserve the clean committed state and report the exact authentication failure rather than changing the remote, force-pushing, or creating another branch.

**Step 5: Confirm final state**

Run:

```bash
git status --short --branch
git log -1 --oneline
```

Completion requires `main`, no staged/unstaged/untracked files, and local `main` synchronized with `origin/main` unless the documented external authentication blocker remains.
