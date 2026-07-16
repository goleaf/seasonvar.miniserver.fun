# Comment direct-link boundary design

**Date:** 2026-07-16
**Status:** approved for implementation through the user's standing instruction to continue with the recommended approach

## Context

Phase 5.3 of the Laravel modernization plan audits controllers for application orchestration that belongs behind focused services. The canonical Task 12 comment domain already has stable comment IDs, a target allowlist, `CommentPolicy`, `CommentTargetResolver`, `CommentDiscussionQuery`, canonical/localized direct-comment routes, moderator fallback, and private no-store/noindex redirects.

`CommentRedirectController` still owns several application decisions:

- it loads the soft-deleted comment row directly;
- it evaluates comment and moderator authorization;
- it resolves inaccessible-target moderator fallback;
- it resolves the structural root and oldest page;
- it assembles the canonical target query and fragment.

That behavior is secure, but keeping it in the HTTP adapter makes the controller the second source of truth for direct-link resolution.

## Decision

Introduce one `CommentDirectLinkResolver` in the existing comments service namespace.

1. The resolver accepts a positive stable comment ID, an optional authenticated viewer, and an optional interface locale.
2. It loads the canonical comment with trashed rows, reauthorizes `view`, and converts denial to the same safe not-found result.
3. It preserves the existing private moderator destination for non-public/deleted comments and for targets unavailable through the public target resolver.
4. For an eligible public destination it reuses `CommentTargetResolver` and `CommentDiscussionQuery`, computes the oldest page and structural root, and returns the existing canonical target URL with the same query fields and `#comment-{id}` fragment.
5. The controller keeps request parsing, the fail-closed schema gate, Laravel user/locale extraction, redirect response creation, and security headers.

The resolver returns a trusted internal URL string. A one-field DTO or HTTP-aware action would add indirection without strengthening the boundary.

No route, route name, policy, guard, model, schema, query parameter, cache key, public anchor, response code, translation, or database record changes.

## Alternatives considered

### Keep the controller unchanged

This has the smallest immediate diff, but preserves direct database access and comment-link policy in the controller. It does not advance the controller-boundary audit.

### Return `RedirectResponse` from an action

This would make the controller shorter, but would couple comments application logic to HTTP response construction and duplicate ownership of no-store/noindex headers. HTTP response selection remains in the controller.

### Generic redirect builder

A generic canonical redirect abstraction could be shared by comments, reviews, or profiles, but their authorization, pagination, tombstone, alias, and moderator rules differ. No repeated contract justifies that broader abstraction.

## Components and responsibilities

### `CommentDirectLinkResolver`

- owns comment lookup and safe view authorization;
- owns moderator/public destination selection;
- reuses the canonical target resolver and discussion query;
- assembles only URLs generated from server-owned routes, target identities, and integer pagination/thread/comment values;
- throws `ModelNotFoundException` for malformed, unauthorized, or publicly inaccessible records;
- does not read the request or return an HTTP response.

### `CommentRedirectController`

- rejects unavailable schema and non-positive decimal route values with `404`;
- normalizes the optional Laravel `User` and interface locale from the request;
- delegates all direct-link resolution to `CommentDirectLinkResolver`;
- returns the existing redirect with `Cache-Control: private, no-store` and `X-Robots-Tag: noindex, nofollow`.

### Existing canonical services

- `CommentTargetResolver` remains the only allowlisted target/visibility/canonical URL boundary;
- `CommentDiscussionQuery` remains the only structural-root and oldest-page boundary;
- `CommentPolicy` and the `manage-comments` gate remain the only authorization sources;
- `CommentSchema` continues to fail closed when the complete Task 12 schema is unavailable.

## Data and security flow

The route accepts only a positive decimal stable comment ID. The resolver loads the row through `withTrashed()` so deleted-thread context remains available, then applies `Gate::forUser($viewer)->allows('view', $comment)`. It never accepts a client target type, target ID, page, root, destination, route name, or URL.

For normal viewers, target resolution must succeed through the existing allowlist and visibility rules. The resolver then validates the structural root, computes the oldest deterministic page, removes any fragment already present on the target canonical URL, and appends only server-derived query values. This prevents open redirects, arbitrary morph resolution, private target disclosure, and forged thread positioning.

Authorized moderators retain the existing private admin fallback when the comment is not public/deleted or its public target is unavailable. Moderator status and notes never enter the URL or public response body.

## Error handling and compatibility

- Invalid route values, missing comments, denied comments, invalid roots, and inaccessible targets remain safe `404` responses for ordinary viewers.
- Authorized moderator fallback remains `admin.comments?comment={id}`.
- Public destinations retain `discussion_scope`, `discussion_sort=oldest`, optional `comments_page`, optional `thread`, `comment`, and the stable fragment.
- Localized collection targets continue to use the locale-aware target resolver; usernames or user data are never introduced into URLs.
- Every redirect remains private, non-cacheable, and non-indexable.
- No comment, reply, reaction, report, notification, moderation, block/mute, export, deletion, sitemap, or SEO behavior changes.

## Verification

Task 12 explicitly prohibits creating or running automated tests for this work, so verification remains non-mutating and focused:

1. exact-file PHP syntax checks;
2. scoped Pint and Larastan;
3. uncached route inspection for canonical/localized direct-comment and moderator routes;
4. booted-container probes for public root, public reply, localized target, unauthorized/missing target, and moderator fallback where fixtures permit;
5. static comparison of old and new query/fragment/header behavior;
6. changed-file and related-file security review for authorization, open redirects, cache privacy, and target allowlisting.

The increment is complete only if the controller contains no Eloquent query, Gate decision, root/page calculation, or destination assembly and no unrelated working-tree path enters its commits.
