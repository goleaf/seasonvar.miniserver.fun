# Task 111 implementation plan

Every item records action, reason, files, dependencies, risk and verification.
Statuses are maintained in `docs/plans/current-task-plan.md`.

## Phase A — analysis and preparation

1. **[critical] Re-read permanent requirements and related feature docs.**
   Action: apply architecture, development, multilingual, security,
   performance/cache, UI, production and maintenance owners. Reason: these
   contracts outrank implementation convenience. Files: read-only canonical
   docs. Dependencies: none. Risk: stale assumptions after prior tasks.
   Verify: compliance matrix names every applicable boundary.
2. **[critical] Inventory runtime, packages, routes, models, relationships,
   migrations, policies, requests, services, UI, API, tests and CI.**
   Reason: continue current Laravel 13 patterns. Files: `composer.lock`,
   `package-lock.json`, `routes/*`, `app/*`, `database/*`, `tests/*`,
   workflows/docs. Dependencies: step 1. Risk: replacing working boundaries.
   Verify: recorded PHP/Laravel/frontend/database versions and protected
   contracts.
3. **[critical] Preserve foreign work and acquire workspace ownership.**
   Reason: staged/unstaged changes must not be mixed. Files: Git metadata only.
   Dependencies: clean `main`. Risk: corrupting another task. Verify: clean
   status, active task-specific lease and exact NUL manifest.
4. **[high] Review referenced external material and packages against project
   evidence.** Reason: articles are inputs, not automatic dependencies. Files:
   read-only sources and project skills. Risk: outdated Laravel/API advice or
   supply-chain expansion. Verify: adoption/rejection decisions are explicit.

## Phase B — RED tests

5. **[critical] Add guest discussion regression.** Action: create published
   root/reply, call guest comments/replies and rendered component. Reason:
   reproduce missing aggregate alias. Files:
   `tests/Feature/CommentDiscussionGuestTest.php`. Dependencies: comment schema.
   Risk: a high-level test could hide the service cause. Verify: test fails
   with `viewer_private_replies_count` before implementation.
6. **[high] Add homepage all-facet projection tests.** Action: seed more than
   old limits, assert web gets all genre/country/year rows, auth renders the
   section, API remains bounded, empty DB is stable. Files:
   `CatalogHomeFacetQueryTest`, `CatalogHomepageRedesignTest`,
   `CatalogHomeWebProjectionTest`. Dependencies: cache refresh helpers. Risk:
   polluted shared cache. Verify: `RefreshDatabase`, versioned cache and exact
   counts.
7. **[high] Add query-class architecture test.** Action: reflect new directory
   and enforce final/readonly/one public `handle()`. Reason: pattern must remain
   structural, not naming-only. Files:
   `tests/Unit/CatalogQueryClassArchitectureTest.php`. Dependencies: planned
   classes. Risk: constructor inherited methods counted incorrectly. Verify:
   count only methods declared by the class.
8. **[high] Add personal update index/query-plan test.** Action: assert schema
   columns and SQLite EXPLAIN uses the named index for the correlated release
   lookup. Files: `CatalogPersonalUpdateQueryPlanTest`, migration. Dependencies:
   release calendar schema. Risk: planner differs with empty tables. Verify:
   seed representative rows and inspect normalized plan text.
9. **[high] Add scoped-lifecycle test.** Action: resolve schema through
   multiple consumers/containers and flush scoped instances. Files:
   `CatalogTasteOnboardingSchemaTest`. Reason: prove same-scope reuse without
   stale process lifetime. Risk: test container global state. Verify: same
   instance before flush, different after.
10. **[high] Add Collection AST regression.** Action: parse `app` and `tests`,
    report every arrow first-argument to `each`/`eachSpread`. Files:
    `CollectionEachCallbackSafetyTest`. Dependencies: existing
    `nikic/php-parser`. Risk: matching unrelated function names. Verify:
    `MethodCall`/`NullsafeMethodCall` names only.
11. **[high] Add nested identifier validation tests.** Action: reject duplicate
    normalized pair, accept same identifier under different providers, reject
    unknown provider/extra row keys. Files:
    `ContentRequestExternalIdentifierValidationTest`. Dependencies: Livewire
    auth/policy and request schema. Risk: testing action errors instead of
    validation. Verify: field-level Russian validation assertions.

## Phase C — implementation

12. **[critical] Stabilize guest comment projection.** Action: select zero
    private count for guests and real aggregate for viewers. Reason: presenter
    DTO shape must not depend on authentication. Files:
    `CommentDiscussionQuery`. Risk: duplicate alias. Verify: guest and viewer
    focused tests.
13. **[high] Extract meaningful homepage Query Classes.** Action: move cache
    miss SQL for snapshot/metrics and combined facets into three named
    one-handle classes; keep cache public APIs. Files:
    `app/Services/Catalog/Queries/*`, caches, builder. Dependencies: existing
    title/facet/cache services. Risk: cache refresh result drift. Verify:
    existing snapshot/metrics/performance tests and architecture test.
14. **[high] Implement complete web facets.** Action: nullable grouped limits,
    all year snapshot, web/API slicing, shared Blade section, accessible
    scrolling. Files: facet query, snapshot query, builder, Blade. Dependencies:
    step 13. Risk: API expansion or excessive page height. Verify: exact data
    and rendered link counts at desktop/mobile.
15. **[high] Remove unused authenticated-home query.** Action: skip featured
    collections only for authenticated web projection. Reason: eliminate a
    query whose result is not rendered. Files: builder/performance test. Risk:
    changing API/user projection. Verify: API path still loads collections;
    auth web SQL does not.
16. **[high] Add release lookup index.** Action: additive reversible migration.
    Files: new migration and data/performance docs. Dependencies: measured
    query shape. Risk: production SQLite DDL lock/write cost. Verify:
    migrate/rollback/migrate on isolated test DB, schema and EXPLAIN.
17. **[high] Bind onboarding schema as scoped.** Action: add `scopedIf`.
    Files: `AppServiceProvider`. Dependencies: Laravel 13 container API. Risk:
    accidental singleton semantics. Verify: lifecycle test and current
    recommendation tests.
18. **[high] Replace `each()` arrow side effects.** Action: use explicit
    closures returning `void` at every AST finding. Files: listed app/test
    callbacks. Reason: prevent strict-false early termination. Risk:
    accidentally changing captures/types. Verify: focused affected tests and
    zero AST findings.
19. **[high] Enforce composite nested validation.** Action: strict row keys,
    enum provider, normalized composite duplicate failure, preserve cross-
    provider values. Files: Livewire component, identifier service,
    translations. Risk: breaking initially blank row. Verify: blank,
    duplicate, valid multi-provider and action tests.

## Phase D — audit and skills

20. **[high] Audit changed and related Laravel architecture.** Action: search
    oversized methods, duplicate validation, Blade queries/business logic,
    N+1, unused imports/dead code/static side effects. Files: changed domain
    slice. Dependencies: implementation green. Risk: unrelated refactor.
    Verify: only correctness/security/performance-related changes retained.
21. **[high] Audit SQL/security/operations.** Action: inspect bindings,
    allowlisted identifiers, CSRF/auth/IDOR, raw output, mass assignment,
    request limits, cache privacy, production migration recovery. Files:
    services/routes/models/docs. Risk: claiming infrastructure state without
    evidence. Verify: repository searches, external safe probes and honest
    `unresolved` backup rehearsal.
22. **[medium] Optimize project skills.** Action: correct unsafe generic
    Laravel rules, invalid frontmatter and Seasonvar QA/authoring procedures.
    Files: declared skill files and MCP docs. Dependencies: external source
    review. Risk: overwriting upstream skills or increasing trigger context.
    Verify: quick validator for every touched skill and all project skills;
    no unreviewed package install.

## Phase E — documentation and verification

23. **[high] Update canonical documentation.** Action: document Query Class
    boundary, full homepage facets, cache/version/index, discussion projection,
    validation, skills and operational rollout. Files: architecture,
    performance, caching, frontend/views, data relations, security/testing,
    MCP docs. Dependencies: final behavior. Risk: duplicate/stale contracts.
    Verify: docs owner map and link checks.
24. **[high] Update visitor/product history.** Action: meaningful Russian
    README visitor entry and dated Russian CHANGELOG item. Reason: product
    behavior changes. Files: README/CHANGELOG. Verify: managed blocks untouched,
    visitor history remains final H2.
25. **[critical] Run focused verification.** Commands: affected PHPUnit files,
    migration round trip, query plans/budgets, skill validators. Risk: SQLite
    parallel state. Verify: actual green output, no ignored failures.
26. **[high] Run quality gates.** Commands: Pint dirty, targeted Larastan,
    Rector dry-run, `composer validate`, `composer audit`, full PHPUnit,
    `npm audit`, `npm run build`, relevant CI profiles. Verify: record every
    result; fix root causes.
27. **[high] Run browser QA.** Action: guest/auth home desktop `1440x1200`,
    mobile `390x844`, all facet counts, keyboard-scroll regions, console/page/
    request/overflow checks. Files: browser spec and ignored screenshots.
    Risk: external media noise. Verify: separate first-party failures.
28. **[critical] Final audit and delivery.** Action: reread requirements,
    search legacy/unfinished/debug/secrets, review status/diff/stat/staged diff,
    exact stage, `approve-index`, commit on `main`, normal push. Risk: including
    unrelated or sensitive files. Verify: staged path equality, clean tree,
    commit hash and remote branch status.
