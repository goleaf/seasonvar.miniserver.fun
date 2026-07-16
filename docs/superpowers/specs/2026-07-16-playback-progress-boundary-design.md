# Playback progress controller boundary design

**Date:** 2026-07-16
**Status:** approved for implementation through the user's standing instruction to continue with the recommended approach

## Context

Phase 5.3 of the Laravel modernization plan audits controllers for application orchestration that belongs behind focused services. The mobile API already has one canonical playback-progress writer in `CatalogUserStateService`: it authorizes title interaction, validates event ordering and bounded positions, resolves the encrypted playback session, rechecks episode/media ownership and availability, trusts only server media duration, writes progress transactionally, publishes sync changes, and invalidates recommendation signals when progress becomes meaningful.

`PlaybackProgressController` still owns two application concerns:

- it resolves a viewer-visible title by raw route slug;
- it expands one validated playback event into seven scalar arguments for the canonical writer.

The transport remains secure, but this leaves title resolution and event assembly in the HTTP adapter and makes the writer call easy to misorder when reused.

## Decision

Introduce one immutable `PlaybackProgressInput` DTO and one focused `PlaybackProgressRecorder` in the existing `App\Services\Catalog\Api\V1` namespace.

1. The DTO contains only the validated request event: playback-session token, event sequence, position, reported duration, and ended state.
2. The recorder accepts the authenticated `User`, raw title slug, numeric episode ID, and typed input.
3. It resolves the title through the existing viewer-aware `CatalogTitleQuery::visibleTo()` boundary and delegates to `CatalogUserStateService::recordProgress()` without changing its domain contract.
4. The controller keeps Laravel authentication extraction, Form Request-to-DTO mapping, stable `422` error-envelope selection, Resource serialization, and private/no-store response headers.

The existing `PlaybackProgressSessionData` is not reused because it represents a trusted decrypted server session (`userId`, title, episode, media and expiry), not an untrusted client progress event. Combining these concepts would weaken type meaning and risk accepting server-owned identity from request data.

No route, route name, middleware, guard, rate limiter, validation rule, API field, error code, status code, model, migration, cache key, token format, progress rule, sync publication, or database record changes.

## Alternatives considered

### Keep the controller unchanged

This has the smallest immediate diff, but preserves direct query orchestration in the controller and a long positional domain call. It does not advance the controller-boundary audit.

### Move response construction into a service

Returning `JsonResponse` or `ApiErrorResponse` from the recorder would shorten the controller, but would couple catalog application logic to the HTTP transport and duplicate ownership of API resources, request IDs, status codes, and cache headers.

### Change `CatalogUserStateService::recordProgress()` to accept the new DTO

The canonical writer is also used by offline sync, whose operation schema uses different field names and already resolves the title through its own batch boundary. Changing the domain signature would expand this increment into another integration path without improving the controller boundary. The recorder therefore adapts the HTTP input while the proven writer remains unchanged.

## Components and responsibilities

### `PlaybackProgressInput`

- immutable validated transport data only;
- no user, title, episode, media, route, Request, token decryption, or response behavior;
- named properties prevent positional mistakes at the controller/application boundary.

### `PlaybackProgressRecorder`

- resolves the viewer-visible title by canonical slug query;
- delegates all authorization, session validation, trusted-duration validation, event ordering, transaction, sync, and cache behavior to `CatalogUserStateService`;
- returns the existing nullable `EpisodeViewProgress` result;
- does not catch safe `404` failures or create HTTP responses.

### `PlaybackProgressController`

- requires the authenticated Laravel `User` already enforced by Form Request and route middleware;
- maps validated accessors to `PlaybackProgressInput` with named arguments;
- delegates to the recorder;
- preserves `invalid_playback_progress`/`422` for rejected events;
- preserves `EpisodeProgressResource` and `Cache-Control: private, no-store` for success.

## Data and security flow

Sanctum, `mobile:write`, verified-email, and the existing playback-progress throttle run before the controller. `RecordProgressRequest` authorizes an authenticated `User` and validates the token shape, positive monotonic sequence, bounded non-negative positions, duration, and boolean ended flag.

The recorder accepts no client user ID, title ID, media ID, target class, destination, permission, or verification flag. It resolves only a viewer-visible title from the server query, then the canonical writer reauthorizes the interaction and resolves the encrypted token against that exact user, title and episode. Media identity and trusted duration continue to come only from the decrypted server session and current available-media query.

The application boundary neither logs nor serializes the playback token. It introduces no cache. Failed title visibility and missing episode behavior remain safe `404`; invalid, expired, foreign, stale, replayed, or inconsistent events retain the stable private `422` envelope.

## Compatibility

- The mobile route and OpenAPI-visible payload/response remain byte-for-byte compatible in field names and meanings.
- Offline sync continues calling the same canonical writer and is not changed.
- Web Plyr/Livewire progress, viewing history, Continue Watching, verified-watching evidence, recommendation invalidation, account export/deletion, and anonymous state are untouched.
- Existing progress rows, playback-session tokens, event sequence semantics, completion state, timestamps, and remember/auth sessions are preserved.
- No database or deployment action is required; rollback removes the DTO and recorder and restores the direct controller call.

## Verification

Task 12 explicitly prohibits creating or running automated tests for this work, so verification remains non-mutating and focused:

1. exact-file PHP syntax checks;
2. scoped Pint and Larastan;
3. uncached inspection of the named progress route, middleware and constraints;
4. booted-container resolution of the controller, recorder and DTO dependencies;
5. static comparison of validation accessors, writer arguments, nullable rejection mapping, Resource serialization and cache headers;
6. changed-file and related-file review for authentication, title visibility, token secrecy, trusted duration, event replay, cache privacy and API compatibility.

The increment is complete only if the controller contains no title query or long scalar writer call and no unrelated working-tree path enters its commits.
