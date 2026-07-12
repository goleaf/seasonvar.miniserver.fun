# Changelog

## 2026-07-13

- Rebuilt the Plyr/HLS browser lifecycle around one guarded session per signed source: async initialization is generation-safe, listeners/timers/resources share AbortController cleanup, progress uses a playing-only heartbeat plus bounded lifecycle flushes, stale Livewire session events are rejected, and fixed Russian loading/retry/error states reveal no provider details. Cleanup also clears markers from the original media node restored by Plyr so Livewire navigation and the browser back-forward cache can initialize it exactly once again.
- Centralized playback authorization and source selection in `CatalogPlaybackSourceResolver`: Blade/Livewire now receive a short-lived signed, viewer-bound internal URL instead of the stored provider URL, direct access rechecks the complete publication hierarchy and source ownership, and video sitemaps publish only internal player locations.
- Added provider/format/quality preference ranking, known-failure exclusion from public episode media/counts, explicit Russian playback availability states, an HTTPS/DNS provider allowlist, redirect-free streamed availability probes, bounded response metadata, redacted probe events, and playback endpoint throttling.
- Added publication-aware previous/next episode navigation to the Livewire title player. Navigation stays inside the current regular/special release lane, crosses into the next or previous visible season, skips inaccessible or source-less episodes, and keeps season state shareable in the URL.
- Added defensive labels and null-safe keyset ordering for missing release numbers while treating `sort_order` as the provider sequence. Livewire season changes retain watchlist/rating state, browser-supplied season/episode IDs are revalidated against the visible title hierarchy, and each multi-field playback change now creates one coherent browser-history entry.

## 2026-07-12

- Reworked catalog totals and facets around unique visible title IDs: relation, year, publication-type, and subtitle counts now use own-group-excluded context, bounded grouped aggregates, selected zero-count retention, and no stale cross-request cache.
- Applied actor/director top-N limits after contextual filtering, removed misleading global count denominators from `/titles`, and added lifecycle/query-budget regression coverage without introducing per-option queries.
- Hardened catalog search, sorting, pagination, and Livewire URL state as one validated flow: NFKC/whitespace normalization, safe fallbacks for malformed scalar values and unsupported sort keys, 650 ms search debounce, stable browser-history state, and automatic recovery from out-of-range pages.
- Added indexed exact external-provider-ID search and the additive `catalog_title_aliases(name_hash, catalog_title_id)` lookup index while preserving visibility-first SQL subqueries, duplicate-free totals, and deterministic allowlisted sorting.
- Added consistent OR-within-group and AND-between-group catalog matching for years, all existing taxonomy relations, publication types, video qualities, and subtitle availability, with normalized bounded URL arrays and per-value/group resets.
- Added bounded debounced server-side actor/director option search, canonical publication-type aliases, duplicate-free grouped pivot subqueries, and regression coverage for invalid/missing URL values, exact paginator totals, Livewire state preservation, and multi-group combinations.
- Hardened Livewire browser-history hydration for empty URL fields and corrected the visible active-filter count to include fixed list and advanced scalar groups.
- Isolated the PHPUnit rate-limiter store in memory so prior browser QA cannot make otherwise independent `/stats` feature tests fail with HTTP 429.
- Refactored the main `/titles` catalog into a full-page Livewire 4.3 component with bounded URL-synchronized state, debounced server search, multi-select and advanced filters, deterministic sorting, stable pagination, group/full reset actions, loading/error/empty feedback, and no Eloquent collections in the public snapshot.
- Preserved the existing GET form fallback and centralized `CatalogTitlesRequest` validation plus the shared `CatalogTitlesPageBuilder`/`CatalogTitleQuery` data path; added Russian Livewire pagination and regression coverage in existing feature tests.
- Centralized public title visibility, normalized catalog filtering, facet counts, API queries, sitemap/feed selection, public statistics, and recommendation candidates in the reusable `CatalogTitleQuery` layer.
- Kept pivot and search candidates in grouped SQL subqueries to prevent duplicate titles, accurate paginator totals, and full ID collection materialization; all mapped sorts now share a deterministic title ID tie-breaker.

## 2026-07-09

- Added an optional queued email notification for failed queued Seasonvar imports, with safe env configuration, dispatch/content tests, and notification documentation.
- Added a private upload storage foundation with explicit image validation rules, generated filenames, fake-storage tests, and storage documentation.
- Improved catalog search form UX with reusable Blade form components, visible validation errors, and preserved old input after validation redirects.
- Cleaned up Eloquent relationship inverses for catalog source pages and Seasonvar import runs, added schema-aligned casts, and documented model query rules in `docs/models.md`.
- Synchronized Markdown documentation with the current Laravel 13 routes, setup, MCP, deployment, testing, API, Blade, and CI conventions.
- Added a GitHub Actions CI workflow for Composer, Pint, Laravel tests, PHP syntax linting, npm audit/build, and dependency audits.
- Documented deployment environment requirements, expanded non-secret `.env.example` defaults, and added a regression test that keeps `env()` calls inside config files.
- Improved the Vite frontend build by using one app entry, lazy-loading Plyr/HLS player assets, loading generated Vite fonts, and documenting frontend commands.
- Added a read-only catalog titles JSON API with explicit Laravel API Resources, eager-loaded relationships, pagination metadata, and sensitive-field regression tests.
- Added a lock-aware queued Seasonvar import job with explicit timeout, retries, backoff, uniqueness, failure logging, and aligned local queue worker settings.
- Added conservative web security headers for Laravel responses.
- Added a named rate limiter for the public read-only `/stats` diagnostics route.
- Disabled Laravel local temporary storage routes by default via `LOCAL_FILESYSTEM_SERVE=false`.
- Documented security rules for secrets, external URLs, Blade output, and dependency audits.
- Added regression tests for security headers, stats throttling, disabled storage routes, and private-host playlist URL blocking.
