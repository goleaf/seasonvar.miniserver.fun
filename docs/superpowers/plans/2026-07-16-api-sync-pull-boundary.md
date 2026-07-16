# API Sync Pull Boundary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move duplicated offline-sync cursor/checkpoint/pull orchestration out of `SyncController` into one typed service without changing the API contract.

**Architecture:** `ApiSyncPullService` composes the existing cursor codec and pull query and returns an immutable `ApiSyncPullResult`. The controller retains readiness, authentication, safe error mapping, API Resource serialization, headers, and routes. Existing encrypted cursors and endpoint payloads remain compatible.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent collections, PHPUnit 12.5, Larastan, Laravel Pint.

## Global Constraints

- Work only on the existing `main`; do not create a branch or worktree.
- Preserve all unrelated staged, unstaged, and untracked files in the shared working tree.
- Do not change routes, middleware, ability names, JSON fields, error codes/text, cursor format, schema, config values, or cache headers.
- Do not add a dependency, migration, queue, scheduler, frontend asset, or environment change.
- Follow RED→GREEN for the new service and use existing endpoint tests as the controller characterization gate.
- Keep `ApiSyncCursorException` propagation and existing controller mapping unchanged.

---

### Task 1: Typed pull orchestration

**Files:**
- Create: `tests/Feature/Api/V1/OfflineSyncPullServiceTest.php`
- Create: `app/DTOs/ApiSyncPullResult.php`
- Create: `app/Services/Api/V1/Sync/ApiSyncPullService.php`

**Interfaces:**
- Consumes: `ApiSyncPullQuery::checkpoint(string, ?User): ApiSyncCursor`, `ApiSyncPullQuery::pull(ApiSyncCursor, int): array`, `ApiSyncCursorCodec::decode(string, string, ?int): ApiSyncCursor`, and `ApiSyncCursorCodec::encode(ApiSyncCursor): string`.
- Produces: `ApiSyncPullService::pull(string $scope, ?User $owner, ?string $encodedCursor, int $limit): ApiSyncPullResult`.

- [ ] **Step 1: Write the failing service tests**

Create `OfflineSyncPullServiceTest` with `RefreshDatabase` and three focused cases:

```php
public function test_missing_cursor_returns_an_encoded_scope_checkpoint(): void
{
    $change = $this->change(ApiSyncChange::SCOPE_CATALOG, null, 'catalog-title');

    $result = app(ApiSyncPullService::class)->pull(
        ApiSyncChange::SCOPE_CATALOG,
        null,
        null,
        100,
    );

    $cursor = app(ApiSyncCursorCodec::class)->decode(
        $result->cursor,
        ApiSyncChange::SCOPE_CATALOG,
        null,
    );

    $this->assertTrue($result->changes->isEmpty());
    $this->assertFalse($result->hasMore);
    $this->assertSame($change->id, $cursor->changeId);
}

public function test_owner_pull_is_bound_and_encodes_the_next_cursor(): void
{
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $expected = $this->change(ApiSyncChange::SCOPE_USER, $owner, 'owner-title');
    $this->change(ApiSyncChange::SCOPE_USER, $other, 'other-title');
    $encoded = app(ApiSyncCursorCodec::class)->encode(
        new ApiSyncCursor(ApiSyncChange::SCOPE_USER, $owner->id, 0),
    );

    $result = app(ApiSyncPullService::class)->pull(
        ApiSyncChange::SCOPE_USER,
        $owner,
        $encoded,
        100,
    );

    $cursor = app(ApiSyncCursorCodec::class)->decode(
        $result->cursor,
        ApiSyncChange::SCOPE_USER,
        $owner->id,
    );

    $this->assertSame([$expected->id], $result->changes->pluck('id')->all());
    $this->assertFalse($result->hasMore);
    $this->assertSame($expected->id, $cursor->changeId);
}

public function test_owner_mismatch_exception_is_not_hidden(): void
{
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $encoded = app(ApiSyncCursorCodec::class)->encode(
        new ApiSyncCursor(ApiSyncChange::SCOPE_USER, $other->id, 0),
    );

    $this->expectException(ApiSyncCursorException::class);

    app(ApiSyncPullService::class)->pull(
        ApiSyncChange::SCOPE_USER,
        $owner,
        $encoded,
        100,
    );
}
```

Use a private `change(string $scope, ?User $user, string $key): ApiSyncChange` helper matching the existing sync test factory shape.

- [ ] **Step 2: Run RED**

Run:

```bash
php artisan test tests/Feature/Api/V1/OfflineSyncPullServiceTest.php
```

Expected: FAIL because `App\Services\Api\V1\Sync\ApiSyncPullService` does not exist.

- [ ] **Step 3: Add the immutable result DTO**

Create:

```php
final readonly class ApiSyncPullResult
{
    /** @param Collection<int, ApiSyncChange> $changes */
    public function __construct(
        public Collection $changes,
        public string $cursor,
        public bool $hasMore,
    ) {}
}
```

Use `Illuminate\Database\Eloquent\Collection` and `App\Models\ApiSyncChange` for the generic PHPDoc.

- [ ] **Step 4: Add the service**

Create `ApiSyncPullService` with constructor-injected `ApiSyncPullQuery` and `ApiSyncCursorCodec`. For a missing cursor, encode the exact scope checkpoint and return `new Collection()`. For a supplied cursor, decode against `$scope` and `$owner?->id`, delegate to `pull()`, and return the existing selected Eloquent collection, encoded next cursor, and `has_more` boolean. Do not catch `ApiSyncCursorException`.

- [ ] **Step 5: Run GREEN**

Run:

```bash
php artisan test tests/Feature/Api/V1/OfflineSyncPullServiceTest.php
```

Expected: 3 tests pass.

- [ ] **Step 6: Format and commit the service slice**

Run:

```bash
./vendor/bin/pint app/DTOs/ApiSyncPullResult.php app/Services/Api/V1/Sync/ApiSyncPullService.php tests/Feature/Api/V1/OfflineSyncPullServiceTest.php --format agent
```

Commit only these three files with message `refactor: add typed API sync pull service`.

### Task 2: Thin controller integration

**Files:**
- Modify: `app/Http/Controllers/Api/V1/SyncController.php`
- Test: `tests/Feature/Api/V1/OfflineCatalogSyncTest.php`
- Test: `tests/Feature/Api/V1/OfflineUserSyncTest.php`

**Interfaces:**
- Consumes: `ApiSyncPullService::pull()` and `ApiSyncPullResult` from Task 1.
- Produces: the unchanged `api.v1.sync.changes` and `api.v1.me.sync.show` HTTP contracts.

- [ ] **Step 1: Run the characterization endpoints before editing**

Run:

```bash
php artisan test tests/Feature/Api/V1/OfflineCatalogSyncTest.php tests/Feature/Api/V1/OfflineUserSyncTest.php
```

Expected: all existing tests pass and establish the preserved response contract.

- [ ] **Step 2: Delegate catalog and user pulls**

Replace the duplicated cursor/checkpoint/pull blocks with:

```php
$result = $sync->pull(
    ApiSyncChange::SCOPE_CATALOG,
    null,
    $request->cursor(),
    $request->limit(),
);
```

and:

```php
$result = $sync->pull(
    ApiSyncChange::SCOPE_USER,
    $user,
    $request->cursor(),
    $request->limit(),
);
```

Retain the existing `try/catch` and `cursorError()` mapping. Add one private HTTP-only response helper that serializes `$result->changes` with `SyncChangeResource`, emits `$result->cursor`, `$result->hasMore`, the validated request limit, and `private, no-store`.

- [ ] **Step 3: Run the service and endpoint suites**

Run:

```bash
php artisan test \
  tests/Feature/Api/V1/OfflineSyncPullServiceTest.php \
  tests/Feature/Api/V1/OfflineCatalogSyncTest.php \
  tests/Feature/Api/V1/OfflineUserSyncTest.php
```

Expected: all tests pass with unchanged endpoint assertions.

- [ ] **Step 4: Format and statically inspect**

Run:

```bash
./vendor/bin/pint app/Http/Controllers/Api/V1/SyncController.php --format agent
vendor/bin/phpstan analyse \
  app/DTOs/ApiSyncPullResult.php \
  app/Services/Api/V1/Sync/ApiSyncPullService.php \
  app/Http/Controllers/Api/V1/SyncController.php \
  --no-progress
php -l app/Http/Controllers/Api/V1/SyncController.php
```

Expected: Pint clean, Larastan reports zero errors, and PHP syntax is valid.

- [ ] **Step 5: Commit controller integration**

Commit only `SyncController.php` with message `refactor: thin offline sync controller`.

### Task 3: Documentation and final gate

**Files:**
- Modify: `docs/plans/laravel-video-portal-modernization.md`
- Modify: `docs/api.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/superpowers/plans/2026-07-16-api-sync-pull-boundary.md`

**Interfaces:**
- Consumes: verified Task 1 and Task 2 behavior.
- Produces: an auditable Phase 5.3 completion record with no duplicate API contract documentation.

- [ ] **Step 1: Document the boundary**

Record in the API owner document that offline cursor/checkpoint/pull orchestration is centralized in `ApiSyncPullService`, while controllers own HTTP readiness/auth/error/resource concerns. Mark the focused Phase 5.3 controller-audit increment in the living plan without claiming every controller has been audited. Add an English changelog bullet under the current unreleased section.

- [ ] **Step 2: Complete final verification**

Run:

```bash
php artisan route:list --path=api/v1/sync
php artisan route:list --path=api/v1/me/sync
php artisan test \
  tests/Feature/Api/V1/OfflineSyncPullServiceTest.php \
  tests/Feature/Api/V1/OfflineCatalogSyncTest.php \
  tests/Feature/Api/V1/OfflineUserSyncTest.php
./vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse \
  app/DTOs/ApiSyncPullResult.php \
  app/Services/Api/V1/Sync/ApiSyncPullService.php \
  app/Http/Controllers/Api/V1/SyncController.php \
  --no-progress
git diff --check
```

Expected: both route families retain their names/middleware, focused tests pass, formatter/static analysis report no errors, and the scoped diff has no whitespace errors.

- [ ] **Step 3: Inspect and commit documentation**

Inspect every changed file and directly related sync request/query/codec/resource. Commit only this plan, the living plan's focused status hunk, the API owner-document hunk, and the changelog hunk with message `docs: record API sync controller refactor`.

- [ ] **Step 4: Push and confirm**

Confirm `main`, push the scoped commits to `origin/main`, verify the configured remote SHA, and leave all unrelated staged/unstaged/untracked work untouched.
