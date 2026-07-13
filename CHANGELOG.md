# Changelog

## 2026-07-13

- Hardened sensitive Livewire actions with independent user/resource rate-limit buckets for catalog search, playback-session issuance, progress, ratings, watchlist, history and import administration; source-health probes now stop locally without recording a provider failure when their host budget is exhausted.
- Closed SSRF and credential-leak boundaries by pinning verified public DNS for stats posters and external playlists, refusing unsafe redirects/credentialed URLs, restricting Google service-account JWT exchange to the canonical token endpoint, and redacting targeted importer URLs from logs and queued mail notifications.
- Strengthened media-profile allowlists and private-upload path normalization, including absolute, drive, backslash, dot-segment and null-byte rejection, and added malformed/direct-access regression coverage in existing security, importer and storage tests.
- Reduced catalog page-builder queries from 20 to 11 by batching ten own-group-excluded relation facets into one bounded UNION, while keeping per-group limits, duplicate-free totals, fresh lifecycle semantics, and server-side actor/director search.
- Constrained catalog/title/home taxonomy eager loads to rendered columns, reduced episode playback resolution from six queries to two by reusing the authorized hierarchy, and kept direct signed playback independently authorized.
- Reduced importer dashboard polling to five bounded queries, combined health/due counters into one covering UNION round trip, and changed media-health backlog predicates so SQLite uses the existing health index instead of a full table scan; query plans did not justify new indexes.
- Added centralized licensed-media health states (`active`, `degraded`, `unavailable`, `disabled`) with atomic failure counters, last-success/error/latency metadata, bounded exponential retries, configurable failure thresholds, and automatic recovery into playback eligibility.
- Hardened source probes with HTTPS/provider allowlists, public-DNS validation and per-request DNS pinning, blocked credentials/private/link-local/metadata targets and redirects, strict connect/total timeouts, bounded streamed Range/manifest reads, safe error categories, and fully redacted operational events.
- Integrated health state into playback fallback, public episode/media counts, refresh planning, queued-import finalization, stats cache refresh, and an aggregate-only `/admin/imports` health panel; added an additive migration with safe legacy-state backfill and a due-check index.
- Added the authorized `/admin/imports` Livewire operations screen with duplicate-safe queued starts, active-only visible polling, cooperative cancellation, retry audit links, stale-running recovery, bounded counters, and sanitized errors.
- Added a scalar-payload unique coordinator job with explicit attempts/backoff/timeout, transient/permanent failure classification, queued/running/completed/partial/failed/cancelled status transitions, and heartbeat tracking across coordinator, page, and finalizer jobs.
- Added nullable requester/retry foreign keys and heartbeat/cancellation indexes to import runs, expanded queue diagnostics to include queued coordinators, and centralized credential/URL/path redaction for persisted and logged importer failures.
- Added a validated normalized Seasonvar DTO boundary, provider-ID/canonical-URL title identity resolution, stable person-URL disambiguation, and additive lookup indexes without unsafe name-only merging.
- Preserved editorial title/description/artwork through a three-way provider baseline, kept publication/audience/windows/soft deletes under local ownership, and prevented partial snapshots or repeat imports from restoring/deleting relationships and media.
- Centralized catalog and playback access in `CatalogEntitlementService`, with one SQL visibility boundary and one structured loaded-release decision reused by search, route binding, recommendations, policies, progress/history, source resolution, and direct signed playback rechecks.
- Added explicit authentication, plan, region, profile, and concurrency decision states without inventing profile, PIN, role, billing, territory, or stream-session storage; the authenticated `User` remains the only supported active profile.
- Consolidated favorites into the existing watchlist concept and replaced toggle read-modify-write with authorized desired-state conditional writes protected by the existing user/title unique key; repeated Livewire requests do not change timestamps, no-op removals create no rows, and browser user/profile IDs are never accepted.
- Centralized the configurable internal rating range, added one-query user watchlist/rating aggregates with immediate create/change/remove updates, and kept imported provider ratings fully separate from viewer averages.
- Added the authenticated `/watching` Livewire page with one continue-watching action per accessible series, real-playback-only paginated history, Russian loading/empty/unavailable states, profile-scoped removal, and typed full-clear confirmation.
- Derived continue/history state from canonical episode progress using fixed-query window ranking, deterministic release-lane navigation, batch hydration, and a new user/history ordering index; newly published episodes re-qualify completed series without cache invalidation.
- Hardened persistent episode progress around the existing unique user/episode record: trusted media duration, percentage, first-start time, source media, opaque expiring playback sessions, monotonic event sequences, transactional retry-safe updates, and non-regressing completion state now feed the same history and continue-watching query.
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
