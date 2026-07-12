# Livewire Series Detail Implementation Plan

> Living plan: checkboxes reflect implementation and verification progress in the existing `main` worktree.

**Goal:** Make the public title page load only one season's episodes at a time, enforce the existing publication boundary on every interaction, and provide authenticated watchlist, rating, viewing progress, and deterministic continue/next/start actions.

**Architecture:** Keep `CatalogController` and slug route binding for the static metadata shell. Reduce `CatalogTitlePageBuilder` to metadata, visible season summaries/counts, and accessible recommendations; place playback, active-season episodes, and private user state in an embedded `CatalogTitlePlayer` Livewire component backed by focused query and primary-action services. Persist user state in additive tables keyed to the authenticated user and validate every browser-supplied identifier against the visible title hierarchy.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4.3, Eloquent, SQLite, PHPUnit 12.5, Blade, Tailwind CSS 4.3, Vite 8.

## Global Constraints

- Work only on the existing `main` branch and preserve unrelated work.
- Do not add production dependencies or create new test files.
- Keep visible copy Russian and database access out of Blade.
- Current access primitives are publication status, availability windows, audience and soft deletes. Region, profile, user age and entitlement models do not exist and must not be faked.
- Load season summaries/counts first; load episodes and media only for the active season.
- Use existing deterministic `kind`, `sort_order`, `number`, `id` ordering and existing media availability scopes.

---

### Task 1: Lock Behavior With Existing Feature Tests

**Files:**

- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `tests/Feature/CatalogVisualSystemTest.php`

**Interfaces:**

- `CatalogTitlePlayer` receives only a locked `catalogTitleId` and normalized URL state.
- `CatalogPrimaryActionResolver::resolve(CatalogTitle $title, ?User $user)` returns continue, next, start, replay, title-media or unavailable state.

- [x] Add tests proving the initial title request renders visible season summaries but only active-season episode titles.
- [x] Add anonymous start, authenticated unfinished continue, completed-next, completed-last replay, empty-season and unavailable-media assertions.
- [x] Add hidden season/episode/media and authenticated-audience assertions; inaccessible records must not affect counts or actions.
- [x] Add watchlist/rating/progress Livewire action assertions and browser-tampered title/episode rejection.
- [x] Run focused RED tests before implementing the component and state services.

---

### Task 2: Add Authenticated Catalog State Storage

**Files:**

- Create: `database/migrations/2026_07_12_235500_create_catalog_user_state_tables.php`
- Create: `app/Models/CatalogTitleUserState.php`
- Create: `app/Models/EpisodeViewProgress.php`
- Create: `app/Policies/CatalogTitlePolicy.php`
- Modify: `app/Models/User.php`
- Modify: `app/Models/CatalogTitle.php`
- Modify: `app/Models/Episode.php`

**Interfaces:**

- `catalog_title_user_states` has unique `(user_id, catalog_title_id)`, `in_watchlist`, nullable integer `rating`, and timestamps.
- `episode_view_progress` has unique `(user_id, episode_id)`, indexed `(user_id, catalog_title_id, last_watched_at)`, bounded second counters, nullable `completed_at`, and timestamps.
- `CatalogTitlePolicy::interact(User $user, CatalogTitle $title): bool` rechecks `availableTo($user)`.

- [x] Create reversible foreign keys with cascade cleanup and indexes matching user/title and recent-progress reads.
- [x] Add typed relationships/casts/fillable attributes without exposing user IDs to Livewire actions.
- [x] Run the focused tests and confirm storage/policy behavior.

---

### Task 3: Implement Visible Playback Queries and Primary Action

**Files:**

- Create: `app/Services/Catalog/CatalogTitlePlaybackQuery.php`
- Create: `app/Services/Catalog/CatalogPrimaryActionResolver.php`
- Create: `app/DTOs/CatalogPrimaryAction.php`
- Modify: `app/View/ViewModels/CatalogShowViewModel.php`

**Interfaces:**

- `seasonSummaries(CatalogTitle $title, ?User $user)` returns visible seasons with visible episode/media counts and no episode collections.
- `episodesForSeason(CatalogTitle $title, Season $season, ?User $user)` returns one validated season's visible episodes and playable media.
- `recordProgress(User $user, CatalogTitle $title, Episode $episode, int $position, int $duration, bool $completed)` upserts only after visibility/media validation.
- Primary action target always references an accessible episode with accessible media, or returns an explicit unavailable state.

- [x] Use grouped/subquery existence checks; never materialize every title episode to choose a target.
- [x] Continue unfinished progress, otherwise find the deterministic next episode after completed progress, otherwise start the first playable episode.
- [x] Exclude zero-location media and zero-playable-media episodes from public counts, choices and actions.
- [x] Run focused tests for bounded active-season loads; SQL/EXPLAIN verification remains in Task 6.

---

### Task 4: Add the Livewire Playback Island

**Files:**

- Create: `app/Livewire/CatalogTitlePlayer.php`
- Create: `resources/views/livewire/catalog-title-player.blade.php`
- Modify: `resources/js/player.js`

**Interfaces:**

- Locked property: `catalogTitleId`.
- URL properties: `season`, `episode`, `media`, `variant`, `quality`, `format` with history enabled and empty values omitted.
- Actions: `selectSeason`, `selectEpisode`, `selectMedia`, `toggleWatchlist`, `setRating`, `recordProgress`.

- [x] Resolve the title through `CatalogTitleQuery::visibleTo()` on every request and validate all selected IDs inside its visible hierarchy.
- [x] Keep public properties scalar; return Eloquent collections only as render data.
- [x] Render stable `wire:key` values, loading feedback, empty/error states, primary action, watchlist/rating state, one active season, player and variants.
- [x] Dispatch throttled progress from the existing local player JavaScript and persist only for authenticated users.
- [x] Run focused Livewire tests until GREEN.

---

### Task 5: Slim the Static Page and Preserve Recommendations

**Files:**

- Modify: `app/Services/Catalog/CatalogTitlePageBuilder.php`
- Modify: `resources/views/catalog/show.blade.php`
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `tests/Feature/CatalogVisualSystemTest.php`

**Interfaces:**

- Static page data contains title metadata, taxonomy groups, aliases/ratings/reviews, visible season summaries/counts and accessible recommendation cards.
- Playback/season episode markup exists only in the nested Livewire view.

- [x] Remove eager loading of all season episodes and all title media from the static builder.
- [x] Preserve publication-aware fallback and precomputed recommendation queries.
- [x] Replace the old player/season blocks with `<livewire:catalog-title-player :catalog-title-id="$title->id" />` and keep one page `<h1>`/layout `<main>`.
- [x] Run catalog page, recommendation and visual tests (47 tests, 370 assertions); frontend build remains in Task 6.

---

### Task 6: Verify, Document, Commit and Push

**Files:**

- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/architecture.md`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/performance.md`
- Modify: `docs/testing.md`
- Modify: this plan

- [x] Inspect the 1228-episode title: 38 total HTTP queries including static recommendations/session, only active-season episode/media collections, 394-byte scalar-only Livewire snapshot, indexed SQL and `EXPLAIN QUERY PLAN`.
- [x] Run Pint, 76 relevant PHPUnit tests (667 assertions), syntax lint, migration/schema checks, docs refresh check, audits and `npm run build`. Full suite is intentionally deferred while ten live importer workers are running, per `docs/testing.md`.
- [x] Run desktop/mobile Playwright QA with managed Chromium fallback; verify season URL/Back restoration, zero horizontal overflow, no page errors or failed local assets. External poster/video requests were intentionally blocked.
- [x] Inspect full diff, ensure branch is `main`, commit all authorized changes, push without force and confirm clean status (`a326b15`, with follow-up documentation in `7d362f3`).

---

### Task 7: Add Release-Lane Episode Navigation

**Files:**

- Create: `app/DTOs/CatalogEpisodeNavigation.php`
- Modify: `app/Services/Catalog/CatalogTitlePlaybackQuery.php`
- Modify: `app/Livewire/CatalogTitlePlayer.php`
- Modify: `resources/views/livewire/catalog-title-player.blade.php`
- Modify: `tests/Feature/CatalogPageTest.php`

**Interfaces:**

- `episodeNavigation(CatalogTitle $title, Season $season, ?User $user, Episode $episode)` returns at most one previous and one next accessible episode.
- The navigation lane is the pair `(season.kind, episode.kind)`; regular playback never falls through into specials.
- Provider ordering uses `sort_order`, then a null-safe release number and stable ID. URL identifiers remain scalar and are resolved again through the visible playback query.

- [x] Cover first, middle, last, cross-season, hidden, expired, source-less, special-season and provider-sequence cases in the existing catalog feature test.
- [x] Add stable previous/next `wire:key` links without storing episode collections in public Livewire state.
- [x] Collapse multi-property URL updates into one browser-history entry while retaining refresh and Back/Forward hydration.
- [x] Run final formatting, focused/broad verification, inspect the authorized diff, commit and push on `main`.
