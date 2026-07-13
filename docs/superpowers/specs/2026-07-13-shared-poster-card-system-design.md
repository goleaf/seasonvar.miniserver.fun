# Shared Poster Card System Design

## Problem

Catalog artwork is rendered through several related but inconsistent Blade structures. The existing `x-title-poster` already applies `object-cover`, but callers add their own background wrappers, dimensions, rounding, borders, and card shells. On narrow or unusually sized artwork this produces visible background strips, nested rounded rectangles, and a double-frame effect. The home page, catalog search, title recommendations, viewing history, and statistics dashboard do not all share the same structural component.

The visual rule must be global: when artwork exists, it fills the complete media frame and may be cropped slightly; the frame must not expose a background strip above the image; and a card must have only one structural outline. The same component boundary must cover all public title/movie grids and every other poster-bearing portal block as far as their different data contracts allow.

## Goals

- Establish one poster rendering atom for every catalog image.
- Establish one poster-card shell for grid, horizontal, and compact card layouts.
- Establish one domain title-card entry point for catalog title collections.
- Make artwork cover and slightly overscan its frame so rounding and subpixel layout cannot expose a seam.
- Keep the poster flush with the card edge and remove nested border/ring/rounded-frame combinations.
- Migrate the home page, catalog and search results, title recommendations, viewing activity, and statistics poster blocks.
- Preserve current routes, metadata, actions, Livewire state, responsive information hierarchy, and accessibility.
- Keep all database access and normalization outside Blade.

## Non-goals

- The HTML `poster` attribute on the video player is not a card image and is not replaced.
- The administration poster URL input is not changed.
- Player/media authorization, importer behavior, poster proxy validation, and external image storage are not redesigned.
- This work does not introduce an image CDN, server-side crop generation, or downloaded poster files.
- Taxonomy chips, status pills, and non-catalog decorative images are outside the component contract.

## Approaches Considered

### Patch only `x-title-poster`

This is the smallest change, but caller-owned background wrappers and card borders would remain inconsistent. It cannot guarantee one frame across the home page, statistics arrays, viewing cards, and title collections.

### One universal component with every data shape and behavior

A single component could accept titles, media records, statistics arrays, progress rows, actions, badges, and arbitrary links. This maximizes nominal reuse but creates a large conditional component with weak typing and unrelated responsibilities.

### Composed poster system

The chosen approach uses a generic poster atom, a structural poster-card shell, and a domain title-card adapter. This centralizes the visual invariant without forcing unrelated page data into one giant API.

## Chosen Architecture

### `x-ui.poster-frame`

`App\View\Components\Ui\PosterFrame` and `resources/views/components/ui/poster-frame.blade.php` become the only boundary that emits an `<img>` for catalog artwork.

Inputs:

- `src`: validated or presentation-safe poster URL, nullable;
- `alt`: complete accessible alternative text;
- `empty-label`: Russian placeholder label, nullable;
- `loading`: `lazy` by default and `eager` only for the SEO-critical title hero when justified;
- caller attributes for dimensions, aspect ratio, rounding, and responsive placement.

Behavior:

- The root is a single `relative isolate overflow-hidden` frame.
- When `src` exists, no slate background layer is placed between the image and the frame.
- The image is absolutely centered, covers the complete frame with `h-full w-full object-cover object-center`, and uses a small approximately two-percent scale overscan. The overscan removes subpixel seams and allows the slight crop requested by the user.
- The image has no border, ring, shadow, padding, or independent rounded corners.
- The root itself has no border or ring. A standalone caller may provide rounding, while a card shell provides the single structural border.
- When `src` is missing, the same root receives the neutral placeholder background and renders the local FontAwesome image icon plus the optional Russian label.
- The component performs no URL resolution, model lookup, caching, database query, or service resolution.
- It exposes `data-ui-poster-frame` and the image exposes `data-ui-poster-image` for tests and browser QA.

### `x-ui.poster-card`

`App\View\Components\Ui\PosterCard` and `resources/views/components/ui/poster-card.blade.php` own the structural relationship between artwork and content.

Inputs:

- `src`, `alt`, `empty-label`, and loading behavior forwarded to `x-ui.poster-frame`;
- `layout`: strict `grid`, `horizontal`, or `compact` value;
- the default body slot;
- optional caller attributes such as `wire:key`, `data-*`, and page-specific state classes.

Behavior:

- The root `article` owns the only border, outer rounding, background, overflow clipping, and optional card shadow.
- The poster area is flush with the root top and side edges. It does not add a second background wrapper or border.
- `grid` keeps the current mobile horizontal scanning layout and switches to a vertical 2:3 poster above the body at `sm` and wider.
- `horizontal` provides a stable portrait column and flexible content column for lists, recommendations, latest episodes, and history.
- `compact` provides the smaller portrait column used by dense status and statistics rows.
- The shell does not create a wrapping anchor. The body supplies the primary stretched link or its own actions, preventing nested interactive elements.
- Layout class maps live in the PHP component class so Blade remains presentation-only and arbitrary layout names are rejected or normalized to `grid`.
- It exposes `data-ui-poster-card`, `data-ui-poster-card-media`, and `data-ui-poster-card-body`.

### `x-catalog.title-card`

`App\View\Components\Catalog\TitleCard` becomes the single domain entry point for `CatalogTitle` cards. Callers use the same tag with a `layout` option instead of choosing between `x-title-card` and `x-title-list-row`.

Inputs:

- a server-resolved `CatalogTitle`;
- `layout`: `grid`, `horizontal`, or `compact`;
- `show-description` and the current readable-density option where needed;
- optional attributes and `wire:key`.

Responsibilities:

- Prepare counts from selected aggregate attributes or already-loaded relations only.
- Prepare the latest season and bounded taxonomy preview only when those relations are loaded.
- Select the appropriate presentation view for the requested layout while preserving the one public component API.
- Render `display_title` as the primary name and `display_original_title` as the secondary name.
- Preserve one main title link, stable Livewire keys, taxonomy links, counts, and current Russian pluralization.
- Never issue a query or trigger lazy loading.

The implementation may use small layout-specific Blade views selected by the component class. They remain private implementation details behind the single `x-catalog.title-card` API and all compose `x-ui.poster-card`.

## Migration Inventory

### Home page

- `Последние обновления` and `Сейчас можно смотреть` use `x-catalog.title-card layout="grid"`.
- `Лента обновлений по датам` uses the horizontal or compact title-card layout.
- `Новые серии` retains episode-specific content but moves its card structure and artwork to `x-ui.poster-card`.

### Catalog, filters, and search

- Grid results use `x-catalog.title-card layout="grid"`.
- List results use `x-catalog.title-card layout="horizontal"`.
- Search, year, taxonomy, and filtered routes automatically receive the same behavior because they share the catalog Livewire view.

### Title page

- The main title artwork uses `x-ui.poster-frame` directly because it is not a card.
- Featured, grouped, fallback, and row recommendations use the appropriate `x-catalog.title-card` layout.
- The video player `poster` attribute remains unchanged.

### Viewing activity

- Continue-watching cards use `x-ui.poster-card` because their progress bar and playback action are not normal title-card metadata.
- History rows use the horizontal or compact poster-card shell while preserving the remove action and unavailable state.

### Statistics

- Statistics poster previews and issue rows use `x-ui.poster-card` or `x-ui.poster-frame` with their existing guarded proxy `poster_src` values.
- Raw `<img>` markup is removed from the statistics Blade view.
- The proxy guard remains the source of whether an image is allowed; the component does not revalidate it.

### Compatibility cleanup

- After every call site is migrated, the legacy `x-title-poster`, `x-title-card`, and `x-title-list-row` views and their obsolete PHP classes are removed.
- No compatibility wrapper remains unless a repository search finds a non-migratable external call site. The final audit must show no internal references to the legacy tags.

## Responsive and Visual Rules

- Portrait artwork uses a 2:3 frame wherever the layout is poster-led.
- Horizontal layouts use an explicit poster column and `minmax(0, 1fr)` content column.
- The image covers or slightly exceeds the frame bounds at every breakpoint.
- Card and image rectangles may share outer clipping, but there is never a separate image ring inside a bordered card.
- Missing artwork uses the exact same dimensions as available artwork so layout does not jump.
- Long Russian and original titles wrap fully without `truncate` or line clamping.
- Interactive targets remain at least 44 pixels high where they are actions.
- No horizontal page overflow is introduced at 390px, tablet, desktop, or wide desktop widths.

## Data and Security Boundaries

- Controllers, Livewire components, page builders, and view models continue to preload every relationship required by a card.
- Shared components consume only passed models or scalar presentation data and never call the database, cache, Redis, service container, filesystem, or environment.
- Poster output stays escaped through Blade.
- Public title artwork keeps `referrerpolicy="no-referrer"`; guarded same-origin statistics proxy URLs remain compatible with the same component.
- The card system does not expose raw source pages, media URLs, importer errors, or private state.

## Testing Strategy

Implementation follows red-green-refactor.

1. Add component contract tests that fail until `x-ui.poster-frame`, `x-ui.poster-card`, and `x-catalog.title-card` exist with their required data attributes and class invariants.
2. Add an architectural test that rejects raw poster `<img>` tags outside `components/ui/poster-frame.blade.php` and rejects internal references to the legacy title component tags after migration.
3. Extend page tests for the home page, catalog grid/list/search, title recommendations, viewing activity, and statistics dashboard to assert the shared component markers.
4. Assert that the poster image uses cover plus overscan and contains no `border-*`, `ring-*`, shadow, padding, or independent frame class.
5. Run affected feature/component tests, Pint, the complete PHP test suite, and `npm run build`.
6. Use Playwright on `/`, `/titles` grid and list/search states, one title with recommendations, `/watching`, and `/stats` at mobile, tablet, and desktop sizes.
7. Browser assertions compare poster and image rectangles, computed `object-fit`, horizontal overflow, console errors, and the number of structural borders between card root and image.

## Documentation

Update `docs/UI_STANDARDS.md`, `docs/frontend.md`, `docs/views.md`, `CHANGELOG.md`, and `docs/MAINTENANCE_LOG.md` with the final shared component names, migration boundary, one-frame rule, and verification results. Run `php artisan project:docs-refresh` without clearing application caches.

## Completion Criteria

- Every portal poster image is emitted by `x-ui.poster-frame`.
- Every poster-bearing card uses `x-ui.poster-card` directly or through `x-catalog.title-card`.
- Every normal `CatalogTitle` grid/list/search/recommendation block uses `x-catalog.title-card`.
- Available images fill and slightly exceed their frame; no top background strip is visible.
- Cards have one structural outline and no nested image outline.
- Legacy title poster/card/list-row components have no internal call sites and are removed.
- Existing routes, actions, counts, links, Livewire keys, accessibility, poster security, and responsive behavior remain intact.
- Focused and complete tests pass, the frontend production build succeeds, browser QA passes, changes are committed on `main`, and the final worktree is clean.
