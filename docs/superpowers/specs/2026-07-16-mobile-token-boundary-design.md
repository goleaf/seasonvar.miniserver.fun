# Mobile token service boundary design

**Date:** 2026-07-16  
**Status:** approved for implementation through the user's standing instruction to continue with the recommended approach

## Context

Phase 5.3 of the Laravel modernization plan audits controllers for domain orchestration that belongs behind typed services. The mobile authentication routes already use Sanctum, dedicated abilities, `MobileTokenService`, API Resources, private no-store responses, and focused feature coverage. The remaining boundary leak is small but concrete:

- `TokenController::index()` constructs the owner-scoped token query directly;
- `MobileTokenService::rotate()` returns an undocumented array shape;
- `TokenController::refresh()` knows the service result keys and date serialization details.

The controller and its direct dependencies are clean in the shared working tree. Existing unrelated staged and unstaged work must remain untouched.

## Decision

Extend the existing `MobileTokenService` instead of introducing another authentication architecture.

1. Add an immutable `MobileTokenRotationResult` DTO containing the new plain-text token and its expiry time.
2. Move the owner-scoped, newest-first device-token query into `MobileTokenService::devices()`.
3. Change `MobileTokenService::rotate()` to return the DTO while preserving its database transaction, token name, abilities, 90-day expiry policy, and old-token revocation.
4. Keep `TokenController` responsible for resolving the authenticated Laravel user/current Sanctum token, selecting HTTP responses, invoking `DeviceTokenResource`, serializing the DTO, and applying `private, no-store`.

No base controller, trait, repository, new guard, provider, token model, route, middleware, or database migration is introduced.

## Alternatives considered

### Generic authenticated API context

A shared authenticated-user/current-token resolver could remove repetition from several API controllers. It has a much larger blast radius and would couple this focused audit to unrelated account and user-state endpoints. It is deferred until repository evidence demonstrates a repeated boundary worth standardizing.

### Leave the controller unchanged

The current behavior is covered and secure, but the controller would continue to own a database query and depend on an implicit service array shape. That would not advance the controller-boundary audit.

## Components and responsibilities

### `MobileTokenRotationResult`

- immutable transport-neutral DTO;
- exposes the plain-text replacement token only to the immediate authenticated response path;
- exposes expiry as `CarbonInterface` so the HTTP layer chooses JSON serialization;
- contains no user, token hash, abilities, or database model graph.

### `MobileTokenService`

- returns only the authenticated owner's personal access tokens, ordered by descending stable token ID;
- keeps rotation atomic and owner-scoped;
- preserves the current token's name and abilities;
- owns token revocation and authentication audit events as it does today;
- does not build HTTP responses or translate labels.

### `TokenController`

- resolves the authenticated `User` and current `PersonalAccessToken` from the request;
- delegates device listing, rotation, and revocation to `MobileTokenService`;
- renders token collections through `DeviceTokenResource`;
- maps the typed rotation result to the existing `token`, `token_type`, and `expires_at` JSON fields;
- preserves `private, no-store` on every response.

## Data and security flow

For device listing, Sanctum authenticates the bearer token, route middleware enforces `mobile:read`, the controller resolves the owner, and the service loads only that owner's tokens. The resource continues to expose safe device metadata and marks the current token from request context. Token hashes and abilities remain absent from the response.

For rotation, route middleware enforces `mobile:write`. The service locks and reloads the current token through the owner's relationship, deletes it, and creates the replacement in the same database transaction. A missing or non-owned token still raises an authentication failure. Only the newly generated plain-text token crosses the service boundary in the typed result; it is never stored or logged.

Revocation behavior, not-found isolation for another user's token, audit events, token pruning, and rate-limit behavior are unchanged.

## Error handling and compatibility

- Laravel authentication failures remain `401` through the existing API exception mapping.
- Revoking a token outside the authenticated owner's relationship remains `404`.
- Sanctum ability failures remain `403` before controller execution.
- No JSON field, ordering rule, route name, method, middleware, OpenAPI contract, cache key, cookie, session, or database column changes.
- No existing access token is rewritten during deployment.

## Verification

Implementation follows RED-GREEN-REFACTOR:

1. Add a focused service-boundary test proving owner isolation, newest-first ordering, and the typed rotation result while verifying old-token replacement and preserved device metadata.
2. Run it before production changes and confirm failure because the new DTO/service contract does not exist.
3. Implement the minimum DTO/service/controller changes.
4. Run the new test and the existing `DeviceTokenManagementTest` characterization suite together.
5. Run Pint on dirty PHP files, PHP syntax checks, and scoped Larastan with an explicit memory limit.

The increment is complete only if the existing HTTP payloads and authorization behavior remain unchanged and no unrelated working-tree path enters its commits.
