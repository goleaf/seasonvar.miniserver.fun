# Task 111 design — homepage query classes and reliability

## Context

The public home page is a full-page Livewire component backed by
`CatalogHomePageBuilder`. Its cache and builder boundaries currently mix cache
orchestration, SQL construction and presentation shaping. The discussion
component catches its guest query exception and shows a generic Russian error,
so the root database projection defect is hidden from the visitor.

The task also incorporates reviewed external material. Recommendations are
adopted only where they match the installed Laravel version and measured
project behavior:

- named Query Classes for complex reusable reads;
- explicit callbacks for `Collection::each()` side effects;
- request/worker-scoped service reuse instead of an unsafe process singleton;
- composite nested validation rather than field-only `distinct`;
- evidence-driven codebase/security audit and dependency decisions.

The large-project blueprint from the final referenced DEV article was also
reviewed against the repository rather than copied mechanically:

- thin HTTP/Livewire orchestration, domain services, API Resources, view
  models, config owners and reusable Blade components already exist and are
  retained;
- one interface per service, global view composers and a repository wrapper
  for every model are rejected because they would duplicate current typed
  boundaries without a second implementation or measurable testability gain;
- a wholesale domain-folder rewrite is outside the feature scope and would
  weaken the existing `Catalog`, `Comments`, `ContentRequests` and importer
  ownership;
- atomic/zero-downtime deployment is not claimed without production
  automation evidence. The additive index keeps an explicit SQLite
  backup/writer-pause/rollback requirement.

## Boundaries

### Discussion projection

`CommentPresenter` requires four aggregate aliases. Authenticated viewers need
their real private-reply count; guests need the same stable DTO shape with
zero. `CommentDiscussionQuery` remains the only presenter caller, so the fix
belongs in its presentation projection rather than a defensive presenter
fallback that could hide future incomplete queries.

### Query classes

New classes live under `app/Services/Catalog/Queries`, consistent with the
existing service namespace. Each class:

- is `final readonly` when its dependencies allow it;
- exposes exactly one public `handle()` method;
- owns a named business read and private SQL helpers;
- returns a typed result, not a generic repository abstraction.

`CatalogHomeSnapshotCache` and `CatalogHomeMetricsCache` remain compatibility
facades with their existing public methods. They delegate cache misses to
`CatalogHomeSnapshotQuery` and `CatalogHomeMetricsQuery`. A
`CatalogHomeFacetGroupsQuery` owns the combined genre/country read and URL
presentation metadata.

Simple model lookups and unrelated existing `*Query` services are not
mechanically rewritten.

### Web/API projection

The snapshot stores every valid year (`1900..current year + 1`). Web data uses
all buckets. API/full data explicitly slices back to twelve buckets and keeps
the existing 18-genre limit. Countries remain compatible.

The Blade facet section is rendered once after the guest/personal branch, so
both audiences receive it. No `take()` remains in the web template. Country
and year lists are bounded visually with keyboard-focusable scrolling while
all links remain server-rendered and discoverable.

### Query performance

Genre and country counts use one existing `taxonomyGroups()` union query with
nullable per-group limits. Cache keys include the requested limit map.
Authenticated web data does not load featured collections because its branch
never renders that section.

Personal update existence checks receive a new additive index:

`(catalog_title_id, status, released_at, id)`.

The existing `(catalog_title_id, status, starts_at, id)` calendar index is
retained because it serves a different query. Migration rollback drops only
the new index.

### Scoped schema memoization

`CatalogTasteOnboardingSchema` caches schema readiness in an instance field.
The container binds it with `scopedIf`, sharing one instance inside a request
or queue-job lifecycle and discarding it when scoped instances are flushed.
It is not a process-wide singleton.

### Laravel 13.22 session compatibility

Laravel 13.22.0 calls `CookieJar::hasQueued()` while logging out other browser
sessions. Its updated `Arr::last()` rejects the missing queued remember-cookie
as a scalar after the framework has already rehashed the current user's
password, but before it dispatches `OtherDeviceLogout`.

`BrowserSessionService` catches only that exact version, exception message and
framework call-stack signature, dispatches the skipped event, and then
continues the existing current-session synchronization and owner-scoped
database-session deletion. Any other exception remains fail-closed. The shim
automatically becomes inactive on the next framework version and must be
removed after an upstream-fixed version passes the regression.

### Collection safety

Arrow functions implicitly return their expression. Laravel `each()` stops
when the callback returns strict `false`, so side-effect callbacks become
explicit `void` closures. An AST test rejects arrow callbacks supplied as the
first argument to `each` or `eachSpread` in application and test PHP files.

### Nested identifiers

External identifier identity is `(provider, normalized_identifier)`.
Validation accepts the same identifier under different providers and rejects
duplicates only after provider-aware normalization. Row keys and provider enum
values are validated before the action. The service no longer silently drops
duplicates.

### Skills

The existing Laravel skill is corrected rather than adding overlapping
third-party skills:

- no blanket `whereHas` → `whereIn` replacement;
- no blind indexes/eager loads;
- Query Class, scoped binding, composite distinct and `each()` sentinel rules;
- project skill source/validator guidance.

The invalid `version` frontmatter key is removed from `impeccable`. All touched
skills and then all project skills are validated. External candidates that
conflict with Laravel 13/Vite or duplicate stronger project contracts are not
installed.

## Compatibility and rollback

- No route, policy, API resource name, query parameter or database relation is
  renamed.
- Cache key versions make rollout code-first and reversible.
- The new index is additive/reversible.
- The API projection keeps old limits explicitly.
- Rollback restores previous cache/query class delegations, removes the new
  index, and reverts the shared facet section; no data backfill is required.
- Production migration requires a verified backup and stopped SQLite writers.
