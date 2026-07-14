# Mobile API v1 Offline Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add durable cursor-based public/private pull synchronization and idempotent offline mutation replay to the existing mobile API v1.

**Architecture:** A payload-free append-only journal records title-aggregate and owner-scoped invalidations. Encrypted cursors bind a monotonic journal id to public or user scope; bootstrap continues to use existing paginated Resources. Batch push validates at most 50 mutations, reuses current domain services and stores only safe idempotency receipts.

**Tech Stack:** PHP 8.5, Laravel 13.19, Sanctum, Eloquent, SQLite, Laravel API Resources, PHPUnit 12.5.

## Global Constraints

- Work only on the existing `main`; do not create branches or worktrees.
- Use TDD for every production behavior and PHPUnit, not Pest.
- Keep controllers thin and Resources query-free.
- Do not add production dependencies.
- Do not expose raw media/source URLs, playback grants, tokens, importer state, user IDs, secrets or stack traces.
- Do not run the additive migration while Seasonvar imports, pending/delayed/reserved jobs or live claims are active.
- Before production migration, sync endpoints return `sync_unavailable`/503 and publishers safely no-op.
- Every PHP change is formatted with `./vendor/bin/pint --dirty --format agent`.

---

### Task 1: Add the durable sync schema and domain models

**Files:**
- Create: `database/migrations/2026_07_14_180000_create_api_sync_tables.php`
- Create: `app/Models/ApiSyncChange.php`
- Create: `app/Models/ApiSyncMutation.php`
- Modify: `app/Models/CatalogTitleUserState.php`
- Test: `tests/Feature/Api/V1/OfflineSyncSchemaTest.php`

**Interfaces:**
- Produces: `ApiSyncChange` with `SCOPE_CATALOG`, `SCOPE_USER`, `OPERATION_UPSERT`, `OPERATION_DELETE`.
- Produces: `ApiSyncMutation` owner relationship and safe `result` array cast.
- Produces: integer `watchlist_version` and `rating_version` on `CatalogTitleUserState`.

- [ ] **Step 1: Write the failing schema test**

Assert both tables, exact columns, unique `(user_id, mutation_id)`, indexes needed by `(scope,id)`, `(user_id,id)`, retention timestamps, cascade deletion for user-owned rows, and default version `0` on a factory-created user state.

- [ ] **Step 2: Run the schema test and verify RED**

Run: `php artisan test tests/Feature/Api/V1/OfflineSyncSchemaTest.php`

Expected: FAIL because sync tables and version columns do not exist.

- [ ] **Step 3: Add the reversible migration**

Create `api_sync_changes` with this logical schema:

```php
$table->id();
$table->string('scope', 16);
$table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
$table->string('resource_type', 32);
$table->string('resource_key', 191)->nullable();
$table->string('operation', 16);
$table->timestamp('changed_at');
$table->timestamps();
$table->index(['scope', 'id'], 'api_sync_changes_scope_cursor_idx');
$table->index(['user_id', 'id'], 'api_sync_changes_user_cursor_idx');
$table->index('changed_at', 'api_sync_changes_retention_idx');
```

Create `api_sync_mutations` with user FK, UUID string, payload hash, safe JSON result, status, timestamps, unique owner/mutation and retention index. Add unsigned big integer version columns to `catalog_title_user_states`. `down()` removes the columns before dropping both sync tables.

- [ ] **Step 4: Add typed models and casts**

`ApiSyncChange` fillable fields are scope/user/resource/operation/changed time; casts only `changed_at`. `ApiSyncMutation` casts `result` to array. Add versions to `CatalogTitleUserState` fillable/casts.

- [ ] **Step 5: Run the schema test and commit GREEN**

Run: `php artisan test tests/Feature/Api/V1/OfflineSyncSchemaTest.php`

Expected: PASS.

Commit: `feat: add mobile sync journal schema`

---

### Task 2: Implement opaque cursor encoding, readiness and bounded pull

**Files:**
- Create: `app/DTOs/ApiSyncCursor.php`
- Create: `app/Services/Api/V1/Sync/ApiSyncReadiness.php`
- Create: `app/Services/Api/V1/Sync/ApiSyncCursorCodec.php`
- Create: `app/Services/Api/V1/Sync/ApiSyncPullQuery.php`
- Create: `app/Exceptions/ApiSyncCursorException.php`
- Test: `tests/Unit/ApiSyncCursorCodecTest.php`
- Test: `tests/Feature/Api/V1/OfflineSyncPullQueryTest.php`

**Interfaces:**
- Produces: `ApiSyncCursor(scope: string, ownerId: ?int, changeId: int)`.
- Produces: `ApiSyncCursorCodec::encode(ApiSyncCursor): string` and `decode(string, string, ?int): ApiSyncCursor`.
- Produces: `ApiSyncPullQuery::checkpoint(string, ?User): ApiSyncCursor` and `pull(ApiSyncCursor, int): array{changes: Collection, cursor: ApiSyncCursor, has_more: bool}`.

- [ ] **Step 1: Write failing cursor tests**

Cover public round-trip, private owner round-trip, tampering, scope mismatch, owner mismatch, malformed decrypted JSON and negative id. Exceptions expose a stable reason enum/string but never decrypted payload.

- [ ] **Step 2: Run cursor tests and verify RED**

Run: `php artisan test tests/Unit/ApiSyncCursorCodecTest.php`

Expected: FAIL because the codec does not exist.

- [ ] **Step 3: Implement encrypted cursors**

Use `Crypt::encryptString(json_encode([...], JSON_THROW_ON_ERROR))` with version `1`. Decode with `Crypt::decryptString`, validate exact scalar shape, scope and owner, and convert every failure into `ApiSyncCursorException`.

- [ ] **Step 4: Write failing pull query tests**

Seed ordered public and two-user changes. Assert keyset `id > cursor`, limit+one `has_more`, stable order, owner isolation, empty checkpoint at current max, and an expired cursor when its id predates the oldest retained row for that scope/owner.

- [ ] **Step 5: Implement readiness and pull query**

`ApiSyncReadiness::available()` checks both sync tables and both version columns with `Schema`. Pull selects only serialization fields, limits 1–200, and never hydrates users/titles. Cursor expiry is checked against minimum retained id only when a positive cursor was supplied.

- [ ] **Step 6: Run cursor and pull tests and commit GREEN**

Run: `php artisan test tests/Unit/ApiSyncCursorCodecTest.php tests/Feature/Api/V1/OfflineSyncPullQueryTest.php`

Expected: PASS.

Commit: `feat: add bounded mobile sync cursors`

---

### Task 3: Expose public manifest and catalog changes

**Files:**
- Create: `app/Http/Requests/Api/V1/SyncPullRequest.php`
- Create: `app/Http/Controllers/Api/V1/SyncController.php`
- Create: `app/Http/Resources/Api/V1/SyncChangeResource.php`
- Modify: `routes/api.php`
- Modify: `app/Http/Controllers/Api/ApiDiscoveryController.php`
- Test: `tests/Feature/Api/V1/OfflineCatalogSyncTest.php`

**Interfaces:**
- Produces: `GET /api/v1/sync/manifest` (`api.v1.sync.manifest`).
- Produces: `GET /api/v1/sync/changes` (`api.v1.sync.changes`).
- Consumes: cursor codec/readiness/pull query from Task 2.

- [ ] **Step 1: Write failing endpoint tests**

Assert guest access, manifest version/limits/URLs/checkpoint, empty checkpoint without cursor, bounded upsert/delete changes, null link for delete, `private, no-store`, no validators, tampered cursor 422, expired cursor 410 and schema-not-ready 503. Assert no importer/search/source/media fields.

- [ ] **Step 2: Run endpoints and verify RED**

Run: `php artisan test tests/Feature/Api/V1/OfflineCatalogSyncTest.php`

Expected: FAIL with missing routes.

- [ ] **Step 3: Add validation and thin controller methods**

`SyncPullRequest` validates nullable string cursor up to 2048 chars and integer limit 1–200 with Russian messages. `SyncController::manifest()` returns current public checkpoint. `catalog()` decodes the cursor or creates a checkpoint response, maps cursor exceptions to `ApiErrorResponse`, and always adds `private, no-store`.

- [ ] **Step 4: Add the query-free Resource and routes**

Serialize only `type`, `key`, `operation`, ISO timestamp and canonical title link for upserts. Put routes outside `public.cache:api` to prevent high-cardinality shared cache. Add `offline_sync` to discovery capabilities.

- [ ] **Step 5: Run endpoint/foundation tests and commit GREEN**

Run: `php artisan test tests/Feature/Api/V1/OfflineCatalogSyncTest.php tests/Feature/Api/V1/ApiFoundationEndpointTest.php`

Expected: PASS.

Commit: `feat: expose mobile catalog sync feed`

---

### Task 4: Publish catalog aggregate invalidations

**Files:**
- Create: `app/Services/Api/V1/Sync/CatalogSyncChangePublisher.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogMetadataBackfill.php`
- Modify: `app/Services/Catalog/CatalogAdministrationService.php`
- Modify: `app/Services/Seasonvar/SeasonvarTitleMerger.php`
- Test: `tests/Feature/Api/V1/OfflineCatalogSyncPublishingTest.php`

**Interfaces:**
- Produces: `publishUpsert(CatalogTitle|int, ?string): void` and `publishDelete(string): void`.
- Guarantees: insert after commit, safe no-op before migration, sanitized report on secondary failure.

- [ ] **Step 1: Write failing publishing tests**

Cover rollback/no event, commit/one event, schema absent/no domain failure, changed import one aggregate event, unchanged import no event, admin title/relation change upsert, merge canonical upsert plus duplicate tombstone.

- [ ] **Step 2: Run publishing tests and verify RED**

Run: `php artisan test tests/Feature/Api/V1/OfflineCatalogSyncPublishingTest.php`

Expected: FAIL because no publisher exists.

- [ ] **Step 3: Implement after-commit publisher**

Use `DB::afterCommit()` and `Schema::hasTable()`. Resolve current public visibility and slug inside the callback: visible titles emit upsert; missing/hidden titles emit delete when the prior slug is known. Catch/report secondary journal failures without exposing source context or changing the completed domain write.

- [ ] **Step 4: Wire existing write boundaries**

Publish once after a changed title aggregate, not per child row. Reuse the changed ID sets already produced by importer/backfill/admin/merger. Preserve unchanged fast paths and current search synchronization.

- [ ] **Step 5: Run publishing/import/admin/merge regressions and commit GREEN**

Run: `php artisan test tests/Feature/Api/V1/OfflineCatalogSyncPublishingTest.php tests/Feature/CatalogSearchSynchronizationTest.php tests/Feature/SeasonvarCatalogMetadataBackfillTest.php`

Expected: PASS.

Commit: `feat: publish catalog sync invalidations`

---

### Task 5: Add owner-scoped pull and state versions

**Files:**
- Create: `app/Services/Api/V1/Sync/UserSyncChangePublisher.php`
- Modify: `app/Services/Catalog/CatalogUserStateService.php`
- Modify: `app/Services/Catalog/CatalogViewingActivityService.php`
- Modify: `app/Http/Controllers/Api/V1/ViewingActivityController.php`
- Modify: `app/Http/Resources/Api/V1/UserTitleStateResource.php`
- Modify: `app/Services/Catalog/Api/V1/UserLibraryQuery.php`
- Modify: `app/Http/Controllers/Api/V1/SyncController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/V1/OfflineUserSyncTest.php`

**Interfaces:**
- Produces: `GET /api/v1/me/sync` protected by `auth:sanctum` and `mobile:read`.
- Produces: state resource `versions.watchlist` and `versions.rating`.
- Produces owner events `title_state`, `progress`, `history` with upsert/delete/clear operations.

- [ ] **Step 1: Write failing owner sync tests**

Assert guest/invalid token 401, wrong ability 403, owner isolation, cursor owner binding, watchlist/rating/progress changes, delete/clear invalidations, additive versions and `private, no-store` without sensitive fields.

- [ ] **Step 2: Run owner sync tests and verify RED**

Run: `php artisan test tests/Feature/Api/V1/OfflineUserSyncTest.php`

Expected: FAIL with missing route/events/versions.

- [ ] **Step 3: Increment versions only on effective desired-state changes**

Within the existing locked transaction, update the selected value and its version using `version = version + 1` only when the value changes. Existing idempotent PUT/DELETE remains idempotent. Select versions in library queries and serialize them in state/library resources where state is present.

- [ ] **Step 4: Publish owner events from domain services**

Publish after successful state/progress/history writes. Move direct controller deletion through `CatalogViewingActivityService::remove()` so both web/API and future batch push share owner authorization and publishing. Clear emits one `history.clear`, not one event per deleted row.

- [ ] **Step 5: Add private pull method/route**

Decode with scope `user` and authenticated owner id. Return the same cursor metadata shape and owner-safe links; no initial cursor returns only a checkpoint.

- [ ] **Step 6: Run user state/history/progress regressions and commit GREEN**

Run: `php artisan test tests/Feature/Api/V1/OfflineUserSyncTest.php tests/Feature/Api/V1/UserTitleStateTest.php tests/Feature/Api/V1/UserLibraryTest.php tests/Feature/Api/V1/ViewingActivityTest.php tests/Feature/Api/V1/PlaybackProgressTest.php`

Expected: PASS.

Commit: `feat: expose owner mobile sync changes`

---

### Task 6: Implement validated idempotent batch push

**Files:**
- Create: `app/DTOs/ApiSyncMutationResult.php`
- Create: `app/Http/Requests/Api/V1/SyncPushRequest.php`
- Create: `app/Services/Api/V1/Sync/ApiSyncMutationService.php`
- Modify: `app/Services/Catalog/CatalogUserStateService.php`
- Modify: `app/Http/Controllers/Api/V1/SyncController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Api/V1/OfflineSyncPushTest.php`

**Interfaces:**
- Produces: `POST /api/v1/me/sync` protected by read/write abilities and `verified.api`.
- Produces: `ApiSyncMutationService::apply(User, array): ApiSyncMutationResult`.
- Produces: version-checked watchlist/rating methods returning applied/current version/current state.

- [ ] **Step 1: Write failing Form Request tests**

Cover missing/empty/>50 operations, duplicate UUIDs, invalid UUID/type, exact allowed keys for each operation, title/episode/progress ranges, rating range and unknown operation. Messages use the standard 422 envelope.

- [ ] **Step 2: Write failing mutation behavior tests**

Cover watchlist/rating applied, expected-version conflict, duplicate same payload, UUID payload collision, partial batch success, owner-scoped history desired delete/clear, valid/expired/tampered progress grant and absence of grant/token/source/media markers in receipts/JSON.

- [ ] **Step 3: Run push tests and verify RED**

Run: `php artisan test tests/Feature/Api/V1/OfflineSyncPushTest.php`

Expected: FAIL because push is absent.

- [ ] **Step 4: Implement strict operation normalization**

`SyncPushRequest::operations()` returns only validated canonical arrays. Use `Rule::in`, `distinct`, per-type required/prohibited rules and an `after()` exact-key check. Hash canonical JSON with sorted associative keys; never store that JSON.

- [ ] **Step 5: Implement per-operation transactions and receipts**

Inside one transaction per operation, lock an existing owner receipt before domain work. Same hash returns its safe result with `duplicate`; different hash returns `conflict`. Otherwise run the existing service, create the safe receipt and return it. Map expected domain failures to operation statuses; rethrow infrastructure failures for the standard sanitized 500.

- [ ] **Step 6: Implement optimistic state methods**

Under `lockForUpdate`, compare `expected_version`; mismatch returns current state without write. Match applies desired state, increments exactly one field version, publishes one owner event and returns the new version. Existing direct endpoints remain unconditional but keep incrementing effective changes.

- [ ] **Step 7: Add push route/controller and run GREEN**

Run: `php artisan test tests/Feature/Api/V1/OfflineSyncPushTest.php tests/Feature/Api/V1/UserTitleStateTest.php tests/Feature/Api/V1/PlaybackProgressTest.php tests/Feature/Api/V1/ViewingActivityTest.php`

Expected: PASS.

Commit: `feat: replay offline mobile mutations`

---

### Task 7: Retention, OpenAPI, documentation and rollout guards

**Files:**
- Create: `app/Console/Commands/PruneApiSync.php`
- Modify: `routes/console.php`
- Modify: `resources/api/openapi.json`
- Modify: `docs/api.md`
- Modify: `docs/architecture.md`
- Modify: `docs/authorization.md`
- Modify: `docs/security.md`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/testing.md`
- Modify: `docs/development.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Test: `tests/Feature/Api/V1/OfflineSyncRetentionTest.php`
- Test: `tests/Feature/Api/V1/CatalogRelatedContentTest.php`

**Interfaces:**
- Produces: `api:sync-prune` scheduled daily with one-server/overlap protection.
- Produces complete OpenAPI paths/schemas/errors for manifest, catalog pull, owner pull and batch push.

- [ ] **Step 1: Write failing retention tests**

Seed changes inside/outside 30 days and receipts inside/outside 90 days. Assert bounded deletion, preservation of fresh rows, successful no-op without schema and scheduled command registration.

- [ ] **Step 2: Implement bounded prune command and schedule**

Delete ordered primary-key chunks (maximum 500) until no expired rows remain. Use config-backed clamped retention defaults and `withoutOverlapping()->onOneServer()` in `routes/console.php`.

- [ ] **Step 3: Extend OpenAPI and route coverage tests**

Document exact request/response schemas, 401/403/410/422/503, mutation statuses, security and no raw playback URL. Add path assertions to the existing complete-v1 route test.

- [ ] **Step 4: Update owner documentation**

Document bootstrap race closure, retention recovery, cursor privacy, optimistic versions, safe idempotency, aggregate invalidation, deployment preflight and the explicit absence of downloadable/offline video.

- [ ] **Step 5: Run focused verification**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/Api/V1/OfflineSyncSchemaTest.php \
  tests/Unit/ApiSyncCursorCodecTest.php \
  tests/Feature/Api/V1/OfflineSyncPullQueryTest.php \
  tests/Feature/Api/V1/OfflineCatalogSyncTest.php \
  tests/Feature/Api/V1/OfflineCatalogSyncPublishingTest.php \
  tests/Feature/Api/V1/OfflineUserSyncTest.php \
  tests/Feature/Api/V1/OfflineSyncPushTest.php \
  tests/Feature/Api/V1/OfflineSyncRetentionTest.php
php artisan project:docs-refresh --check
```

Expected: all focused tests pass, Pint passes and docs are current.

- [ ] **Step 6: Run full verification**

Run:

```bash
php artisan test
git diff --check
git status --short --branch
```

Expected: full suite passes, no whitespace errors, only offline-sync files remain dirty, branch is `main`.

- [ ] **Step 7: Commit documentation/retention and inspect rollout**

Commit: `docs: complete mobile offline sync contract`

Run `php artisan seasonvar:import --status` and the documented migration preflight. If imports/jobs/claims remain active, do not migrate; verify live sync endpoints return sanitized `sync_unavailable`/503 while every existing v1 endpoint remains healthy. If the preflight is clean, back up SQLite, migrate, run smoke requests for manifest/public pull/private pull/push, then verify no pending migrations and a clean worktree.
