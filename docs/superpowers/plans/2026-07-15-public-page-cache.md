# Public Catalog Page Cache Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Serve repeated guest catalog pages from the existing Redis/Memcached cache without catalog SQL and coalesce post-import warming into bounded, recoverable work.

**Architecture:** Add an explicit route middleware that stores sanitized HTML through `TieredCache`, with CSRF and signed playback URLs treated as dynamic holes. Extend versioned invalidation with a `catalog-pages` domain and global/title-scoped generations, then persist one bounded warm intent that drives critical/recent/title HTTP warm targets while legacy duplicate jobs become no-ops.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4.3, Redis, Memcached, SQLite, PHPUnit 12.5.

## Global Constraints

- Work only on the existing `main`; do not create a branch or worktree.
- SQLite is authoritative; shared cache stores only arrays, scalars, IDs and sanitized HTML.
- Never cache authenticated state, CSRF, raw playback targets, source HTML, tokens or Eloquent graphs.
- Invalidation occurs after commit through `CatalogCacheInvalidator`; never use `Cache::flush()`, Redis `KEYS` or wildcard deletion.
- `php artisan seasonvar:import` remains the only public importer command.
- No production dependency or `.env` mutation.
- Preserve unrelated user changes already present in the worktree and stage only task files.

---

### Task 1: Define the response-cache security contract

**Files:**
- Create: `tests/Unit/PublicPageHtmlTransformerTest.php`
- Create: `tests/Unit/PublicPageCachePolicyTest.php`
- Create: `app/Support/Cache/PublicPageCacheContext.php`
- Create: `app/Support/Cache/PublicPageCachePolicy.php`
- Create: `app/Support/Cache/PublicPageHtmlTransformer.php`

**Interfaces:**
- `PublicPageCachePolicy::context(Request $request, string $profile): ?PublicPageCacheContext`
- `PublicPageHtmlTransformer::sanitize(string $html): ?string`
- `PublicPageHtmlTransformer::restore(string $html): string`

- [ ] **Step 1: Write failing transformer tests**

Cover CSRF masking, valid `playback.source` replacement, invalid/external signed-looking URL preservation, current-session restoration and refreshed playback signatures after time travel.

- [ ] **Step 2: Verify RED**

Run: `php artisan test tests/Unit/PublicPageHtmlTransformerTest.php`

Expected: FAIL because the transformer class does not exist.

- [ ] **Step 3: Implement the minimal transformer**

Use permanent non-secret markers, `Request::hasValidSignature()`, `URL::temporarySignedRoute()`, numeric media IDs and HTML escaping. Return `null` when the CSRF marker cannot be established for an HTML document containing Livewire assets.

- [ ] **Step 4: Write and verify failing policy tests**

Cover profile/domain mapping, title scope/global generation, recursively canonical query ordering, bounds and bypasses for auth, `Authorization`, `X-Livewire`, `q`, `title`, unknown keys and non-GET requests.

Run: `php artisan test tests/Unit/PublicPageCachePolicyTest.php`

Expected: FAIL because policy/context are missing.

- [ ] **Step 5: Implement policy/context and run GREEN**

Run: `php artisan test tests/Unit/PublicPageHtmlTransformerTest.php tests/Unit/PublicPageCachePolicyTest.php`

Expected: PASS.

### Task 2: Cache successful guest HTML responses

**Files:**
- Create: `app/Http/Middleware/CachePublicPage.php`
- Modify: `app/Support/Cache/CacheDomain.php`
- Modify: `config/cache-architecture.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php`
- Modify: `tests/TestCase.php`
- Create: `tests/Feature/PublicPageResponseCacheTest.php`

**Interfaces:**
- Middleware alias: `public.page`
- Header: `X-Seasonvar-Page-Cache: HIT|STALE|MISS|BYPASS`
- Tiered resource: `response-html`

- [ ] **Step 1: Write failing feature tests**

Prove first guest request is `MISS`, second is `HIT`, second home request executes zero catalog-table SQL, authenticated responses bypass, free search bypasses, non-200 responses are not stored, and invalidation exposes updated content.

- [ ] **Step 2: Verify RED**

Run: `php artisan test tests/Feature/PublicPageResponseCacheTest.php`

Expected: FAIL because routes do not have response caching.

- [ ] **Step 3: Implement middleware and route profiles**

Call `TieredCache::remember()` with sanitized arrays only. Cache status 200 `text/html` bodies within `page_cache.max_payload_bytes`. Reconstruct a fresh response on hit, restore dynamic holes, retain only allowlisted content-type metadata, and let outer web middleware add session/security headers.

- [ ] **Step 4: Add domain/config contract**

Add `CacheDomain::CatalogPages`, a 300/1800/120 TTL window, query bounds, payload bound, enabled flag and per-profile allowlists. In tests keep the feature enabled with array stores, but disable outbound warming.

- [ ] **Step 5: Run GREEN and regression tests**

Run: `php artisan test tests/Feature/PublicPageResponseCacheTest.php tests/Feature/CatalogPageTest.php --filter='home|title|directory'`

Expected: PASS.

### Task 3: Track and warm bounded public URLs

**Files:**
- Create: `app/Services/Catalog/PublicPageCacheManifest.php`
- Create: `app/Services/Catalog/PublicPageCacheWarmer.php`
- Create: `tests/Feature/PublicPageCacheWarmerTest.php`
- Modify: `app/Http/Middleware/CachePublicPage.php`
- Modify: `app/Services/Catalog/CatalogCacheWarmer.php`
- Modify: `config/cache-architecture.php`

**Interfaces:**
- `PublicPageCacheManifest::record(string $relativeUrl): void`
- `PublicPageCacheManifest::recent(int $limit): array`
- `PublicPageCacheWarmer::warm(array $titleIds = []): array`

- [ ] **Step 1: Write failing manifest/warmer tests**

Cover exact-host enforcement, relative URL normalization, exclusion of `q`/private routes, LRU bound, fixed critical routes, directory routes, changed-title slugs, deduplication, per-run URL limit and HTTP timeout/retry configuration through `Http::fake()`.

- [ ] **Step 2: Verify RED**

Run: `php artisan test tests/Feature/PublicPageCacheWarmerTest.php`

Expected: FAIL because manifest/warmer do not exist.

- [ ] **Step 3: Implement bounded manifest**

Store a compact `relative_url => last_seen_timestamp` array in the critical cache store under an atomic lock. Update it only after a successful new shared entry, cap it by configured LRU size and never store raw search terms.

- [ ] **Step 4: Implement self HTTP warmer**

Build the fixed URL set plus recent manifest and changed titles with one grouped slug query. Reject hosts differing from configured base URL. Use `Http::connectTimeout()->timeout()->retry(..., throw: false)` and fail the warm batch on non-2xx or connection error.

- [ ] **Step 5: Run GREEN**

Run: `php artisan test tests/Feature/PublicPageCacheWarmerTest.php tests/Feature/CacheWarmJobTest.php`

Expected: PASS.

### Task 4: Coalesce invalidations and make legacy warm jobs harmless

**Files:**
- Create: `app/DTOs/CatalogCacheWarmWork.php`
- Create: `app/Services/Catalog/CatalogCacheWarmRequestStore.php`
- Create: `tests/Feature/CatalogCacheWarmRequestStoreTest.php`
- Modify: `app/Jobs/WarmCatalogCaches.php`
- Modify: `app/Services/Catalog/CatalogCacheInvalidator.php`
- Modify: `app/Console/Commands/WarmCatalogCache.php`
- Modify: `tests/Feature/CatalogCacheInvalidatorTest.php`
- Modify: `tests/Feature/CacheWarmJobTest.php`

**Interfaces:**
- `CatalogCacheWarmRequestStore::request(iterable $titleIds = [], bool $refresh = false): int`
- `CatalogCacheWarmRequestStore::claim(int $titleLimit): ?CatalogCacheWarmWork`
- `CatalogCacheWarmRequestStore::complete(CatalogCacheWarmWork $work): bool`

- [ ] **Step 1: Write failing store tests**

Prove IDs merge and deduplicate, one bounded batch is stable until completion, failed/unacknowledged work remains pending, a newer generation survives an older completion, and oversized input remains bounded.

- [ ] **Step 2: Verify RED**

Run: `php artisan test tests/Feature/CatalogCacheWarmRequestStoreTest.php`

Expected: FAIL because request store/work DTO are missing.

- [ ] **Step 3: Implement request store with critical locks**

Use the configured locks store and exact keys from `CacheKeyFactory`/bounded project prefix. Keep generation, refresh flag and integer IDs only. Do not clear claimed state before successful completion.

- [ ] **Step 4: Update job/invalidation tests and verify RED**

Assert `ShouldBeUniqueUntilProcessing`, seven-day uniqueness, request recorded after version bumps, no-op when no pending work, global title version bump when IDs are unknown, and tail dispatch when pending work remains.

- [ ] **Step 5: Implement job and invalidator changes**

Keep the legacy `refresh` constructor property for queued payload compatibility but drive work exclusively from the request store. Claim, warm, complete, then dispatch a tail job only when state remains pending. Existing serialized jobs with no intent return immediately.

- [ ] **Step 6: Run GREEN**

Run: `php artisan test tests/Feature/CatalogCacheWarmRequestStoreTest.php tests/Feature/CatalogCacheInvalidatorTest.php tests/Feature/CacheWarmJobTest.php tests/Feature/CacheWarmScheduleTest.php`

Expected: PASS.

### Task 5: Verify import/admin integration and query budgets

**Files:**
- Modify: `tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php`
- Modify: `tests/Feature/RunSeasonvarImportJobTest.php` if required by changed dispatch contract
- Modify: `tests/Feature/CatalogLivewireBudgetTest.php`
- Modify: `tests/Feature/PublicPageResponseCacheTest.php`

- [ ] **Step 1: Add failing integration assertions**

Cover title-scoped warm intent from queued title finalization, global title generation from sync/unknown-ID invalidation, transaction rollback not creating intent, and warm home/title response query budgets.

- [ ] **Step 2: Verify RED and implement only missing glue**

Run: `php artisan test --filter='SeasonvarImportTitleGroupFinalizerTest|RunSeasonvarImportJobTest|PublicPageResponseCacheTest|CatalogLivewireBudgetTest'`

Expected: new assertions fail before glue, then pass after the smallest integration changes.

- [ ] **Step 3: Run importer/cache regression group**

Run: `php artisan test --filter='Cache|SeasonvarImport|CatalogPage|CatalogLivewireBudget'`

Expected: PASS.

### Task 6: Operations and documentation

**Files:**
- Modify: `.env.example`
- Modify: `deploy/systemd/seasonvar-cache-warm-worker.service`
- Modify: `docs/caching.md`
- Modify: `docs/performance.md`
- Modify: `docs/queues.md`
- Modify: `docs/deployment.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Document exact configuration and safe rollout**

Add page-cache enable/payload/manifest/warm URL limits and self-warm base URL placeholders. Document that the worker is installed only after deploying no-op legacy handling, observing a dry health snapshot and confirming importer contention.

- [ ] **Step 2: Isolate the worker unit**

Listen only to `cache-warm`, retain one conservative process, bounded memory/time/jobs and graceful restart. Do not enable or start the production unit as part of repository implementation.

- [ ] **Step 3: Run documentation contracts**

Run: `php artisan test --filter='ProjectDocumentation|ProductionOperationsDocumentation|CacheArchitecture|QueueWorkerObservability'`

Expected: PASS.

### Task 7: Format, verify and commit

**Files:** all task files only; do not stage the pre-existing layout navigation work.

- [ ] **Step 1: Format PHP**

Run: `./vendor/bin/pint --dirty --format agent`

Expected: exit 0.

- [ ] **Step 2: Run focused verification**

Run: `php artisan test --filter='PublicPage|CacheWarm|CatalogCacheInvalidator|TieredCache|CacheArchitecture|SeasonvarImportTitleGroupFinalizer'`

Expected: PASS.

- [ ] **Step 3: Run full backend verification**

Run: `php artisan test`

Expected: all PHPUnit tests pass with zero failures/errors.

- [ ] **Step 4: Run frontend build**

Run: `npm run build`

Expected: Vite build exits 0.

- [ ] **Step 5: Inspect scope and commit only authorized files**

Run: `git status --short --branch`, `git diff --check`, and inspect staged diff.

Expected: current branch `main`; no whitespace errors; pre-existing user files remain unstaged.

Commit in logical `main` commits: design/plan, response cache, coalesced warming, docs/operations. Do not push unless separately requested.
