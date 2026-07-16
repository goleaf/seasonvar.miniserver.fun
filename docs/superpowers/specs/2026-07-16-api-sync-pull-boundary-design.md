# API sync pull boundary design

## Context

`App\Http\Controllers\Api\V1\SyncController` owns four stable API endpoints. The catalog and authenticated-user pull methods currently duplicate cursor initialization, cursor decoding, bounded journal retrieval, and cursor encoding. The underlying query and codec are already canonical, but their HTTP orchestration is repeated in the controller.

The public contract is mature and covered by the existing offline-sync feature suite. This increment is an internal refactor: route names, middleware, authentication abilities, response fields, status codes, Russian error text, cursor encryption, retention behavior, limits, cache headers, schema, and storage remain unchanged.

## Options considered

1. Keep a private controller helper. This removes duplicate lines but leaves cursor/query orchestration in the HTTP boundary and does not give other API consumers a typed contract.
2. Add a typed pull service and result DTO. This is the selected approach because it follows the existing `App\Services\Api\V1\Sync` boundary, keeps the controller focused on HTTP concerns, and does not introduce route or persistence changes.
3. Split the four endpoints across multiple controllers. This would reduce class length but create routing and dependency churn without separating a meaningful domain capability.

## Design

Add `ApiSyncPullResult`, an immutable DTO containing the selected `ApiSyncChange` collection, encoded next cursor, and `hasMore` flag. Add `ApiSyncPullService`, which accepts the existing `ApiSyncPullQuery` and `ApiSyncCursorCodec` through constructor injection.

The service exposes one method:

```php
public function pull(
    string $scope,
    ?User $owner,
    ?string $encodedCursor,
    int $limit,
): ApiSyncPullResult
```

When no cursor is supplied, it creates the current scope checkpoint and returns an empty typed collection with `hasMore=false`. When a cursor is supplied, it decodes it against the exact scope and owner, delegates the bounded database read to `ApiSyncPullQuery`, and encodes the returned cursor. Invalid, mismatched, and expired cursor exceptions continue to propagate unchanged for the controller's existing safe HTTP mapping.

`SyncController::catalog()` and `SyncController::user()` retain readiness checks, authenticated-user resolution, resource serialization, metadata names, and `private, no-store`. They delegate only the repeated pull orchestration to the new service. Manifest and push behavior remain untouched.

## Error and security boundaries

- Scope and owner remain server-selected constants/objects; request input cannot choose either value.
- The encrypted cursor remains the only public cursor representation.
- `ApiSyncCursorException` reasons and the existing `422`/`410` safe responses remain unchanged.
- Readiness still fails closed before any sync table query.
- User pulls remain bound to the authenticated user and the existing `mobile:read` route ability.
- The DTO contains only already-selected public journal columns and no user, token, media URL, or source data.

## Verification

Test-first coverage adds a focused service test for catalog checkpoints, owner-bound pulls, and cursor exception propagation, then reruns the existing catalog/user offline-sync endpoint suites to prove response compatibility. Pint, focused Larastan, route inspection, PHP syntax, and `git diff --check` complete the gate. No migration, frontend build, browser run, queue, scheduler, production dependency, or environment change is required.

## Rollback

Rollback removes the service and DTO and restores the two controller methods. No database or serialized cursor migration is involved, so deployed clients and stored cursors remain compatible in either direction.
