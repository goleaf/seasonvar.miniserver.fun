# Диагностика качества просмотра — implementation plan

Дата: 26.07.2026.

Design:
[`2026-07-26-playback-quality-telemetry-design.md`](../specs/2026-07-26-playback-quality-telemetry-design.md).

## Expected changed files

### Новые

- `app/Http/Requests/StorePlaybackQualitySampleRequest.php`
- `app/Models/PlaybackQualitySession.php`
- `app/Services/Catalog/PlaybackQualityContext.php`
- `app/Services/Catalog/PlaybackQualityMetricsQuery.php`
- `app/Services/Catalog/PlaybackQualityNetworkTestResponder.php`
- `app/Services/Catalog/PlaybackQualityRecorder.php`
- `app/Services/Catalog/PlaybackQualityReportSnapshot.php`
- `app/Services/Catalog/PlaybackQualityResponder.php`
- `app/Services/Catalog/PlaybackQualitySchema.php`
- `database/factories/PlaybackQualitySessionFactory.php`
- `database/migrations/2026_07_26_233000_create_playback_quality_telemetry.php`
- `resources/js/client-diagnostics.js`
- `tests/Feature/PlaybackQualityTelemetryTest.php`
- `tests/Feature/PlaybackQualityTechnicalIssueTest.php`
- `tests/Feature/PlaybackQualityAdministrationTest.php`
- `tests/Unit/PlaybackQualityPlayerContractTest.php`
- `tests/browser/playback-quality-diagnostics.spec.js`
- этот design/plan.

### Изменяемые

- `app/Actions/TechnicalIssues/CreateTechnicalIssue.php`
- `app/Console/Commands/PruneTechnicalIssueData.php`
- `app/DTOs/TechnicalIssues/TechnicalIssueInput.php`
- `app/Livewire/CatalogTitlePlayer.php`
- `app/Livewire/TechnicalIssues/TechnicalIssueAdministrationManager.php`
- `app/Livewire/TechnicalIssues/TechnicalIssueFormPage.php`
- `app/Models/TechnicalIssueDiagnostic.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/TechnicalIssues/TechnicalIssuePresenter.php`
- `app/Services/TechnicalIssues/TechnicalIssueQuery.php`
- `app/Services/TechnicalIssues/TechnicalIssueTypeRegistry.php`
- `app/View/ViewData/CatalogPlayerCopy.php`
- `bootstrap/app.php`
- `config/playback.php`
- `lang/{ru,en}/catalog.php`
- `lang/{ru,en}/issues.php`
- `resources/js/issues.js`
- `resources/js/player.js`
- `resources/views/livewire/catalog-title-player.blade.php`
- `resources/views/livewire/technical-issues/administration-manager.blade.php`
- `resources/views/livewire/technical-issues/form-page.blade.php`
- `routes/web.php`
- `tests/Unit/CatalogPlayerCopyTest.php`
- applicable canonical docs, `README.md`, `CHANGELOG.md` and current plan.

## Protected public contracts

- `titles.show`, `playback.source`, media download and `/api/v1/playback/*`;
- current title query parameters and browser history;
- `CatalogTitlePlayer` public methods/events used by player navigation;
- signed source grants, entitlement, progress session and anonymous progress;
- `LicensedMedia` health/publication/audience state;
- technical issue routes, UUID/number, visibility, attachment and duplicate
  behavior;
- source-health staff workflow;
- RU/EN parity and no untranslated visible keys;
- cache keys/TTL/invalidation, sitemap/SEO and importer command;
- all foreign staged/unstaged files and hunks in shared working tree.

## Detailed live checklist

### Critical — preparation

1. `[completed]` Read root `AGENTS.md`, requirements index and every
   applicable canonical owner.
   - Why: mandatory source-of-truth order.
   - Files: docs only.
   - Dependencies: repository state.
   - Risk: stale historical assumptions.
   - Verify: record versions/contracts in current plan.
2. `[completed]` Verify PHP/Laravel/Livewire/PHPUnit/Pint/Tailwind/HLS/Plyr
   versions and SQLite runtime.
3. `[completed]` Inspect branch, remote, HEAD, staged/unstaged/untracked
   foreign work.
4. `[completed]` Trace player/HLS/fallback/progress/issue/admin/source-health
   implementations and existing tests.
5. `[completed]` Compare rewrite, ticket-only, third-party and first-party
   telemetry alternatives.
6. `[completed]` Approve bounded first-party design under explicit user
   autonomy.
7. `[completed]` List expected files, protected contracts, migration/routes/
   translations/cache/permissions/production risks.
8. `[completed]` Create task-specific requirement-compliance matrix.
9. `[completed]` Update canonical technical-issue/playback/privacy owner before
   application implementation because the request explicitly replaces the
   previous no-speed-test rule.
10. `[completed]` Reread this plan/design and applicable permanent rules.

### Critical — TDD RED

11. `[completed]` Add schema/migration contract test.
    - Why: additive/reversible SQLite-compatible data contract.
    - Files: migration, feature test.
    - Dependencies: existing catalog/media FKs.
    - Risks: nullable hierarchy, destructive down.
    - Verify: exact columns/indexes/FKs and rollback on disposable DB.
12. `[completed]` Add telemetry endpoint RED for guest/auth success,
    validation, hierarchy rejection, server-owned metadata and no secrets.
13. `[completed]` Add cumulative/idempotent/fallback RED.
14. `[completed]` Add report-token → issue diagnostic RED.
15. `[completed]` Add admin metrics RED for empty data, boundaries and grouped
    browser/provider/quality output.
16. `[completed]` Extend player copy/client lifecycle static RED for exact
    fallback strings, safe diagnostic module and no raw URL/UA persistence.
17. `[completed]` Run only new/focused tests and confirm failures are caused by
   missing requested behavior.

### Critical — database and backend

18. `[completed]` Add reversible migration and model/factory.
19. `[completed]` Add request-scoped schema guard.
20. `[completed]` Add encrypted capture/report contexts with TTL and strict
    payload parsing.
21. `[completed]` Add Form Request normalization/allowlists/bounds.
22. `[completed]` Add recorder:
    - resolve title/media hierarchy through existing availability scopes;
    - derive provider/variant/quality/translation/format on server;
    - monotonic cumulative upsert by UUID;
    - derive primary/fallback stage;
    - never mutate source health.
23. `[completed]` Add thin no-store telemetry and network-test responders.
24. `[completed]` Register dual rate limiters and routes.
25. `[completed]` Add report snapshot resolver/attachment to
    `CreateTechnicalIssue`.
26. `[completed]` Extend diagnostic model/presenter/private detail projection.
27. `[completed]` Extend retention command with bounded telemetry deletion.
28. `[completed]` Keep main player/ticket available while telemetry schema is
    absent.

### High — player and report UX

29. `[completed]` Extract shared browser/OS/device capability helper without
    exposing raw UA outside browser memory.
30. `[completed]` Add telemetry data attributes with encrypted title token and
    named same-origin routes; never output source URL into telemetry config.
31. `[completed]` Extend one `CatalogPlayerSession` with monotonic metrics,
    buffering intervals, HLS capability and stable error mapping.
32. `[completed]` Send bounded snapshots on ready/error/fallback/final lifecycle
    without retries and without blocking playback.
33. `[completed]` Preserve request ID/source ordinal only across fallback;
    rotate it on episode/source selection transition.
34. `[completed]` Implement sequential accessible fallback messages while
    preserving existing recovery speed and unavailable state.
35. `[completed]` Change visible action to «Видео не работает».
36. `[completed]` On explicit click run one timeout-bounded same-origin network
    test, submit report snapshot and navigate to fresh server issue URL.
37. `[completed]` Preserve ordinary href/position fallback if JS/telemetry
    fails.
38. `[completed]` Show attached diagnostic preview and preselected revocable
    consent in issue form.
39. `[completed]` Prevent double report click; restore accessible action after
    recoverable failure.

### High — administration and queries

40. `[completed]` Add allowlisted `1|7|30` day URL filter.
41. `[completed]` Build one overview aggregate and three limited group queries.
42. `[completed]` Define exact formulas:
    - startup = average non-null first-ready milliseconds;
    - rebuffer ratio = buffering / (playback + buffering);
    - playback error rate = terminal failed sessions / all sessions;
    - fallback success = succeeded / attempted.
43. `[completed]` Render textual responsive cards and accessible grouped tables
    with empty/unavailable/error states.
44. `[completed]` Authorize metrics with current staff gate; no row-level
    telemetry endpoint/UI.
45. `[completed]` Confirm Blade performs zero queries.

### High — validation/security/error handling

46. `[completed]` Reject cross-title media and unavailable targets.
47. `[completed]` Ignore client provider/translation/URL/user identity fields.
48. `[completed]` Ensure request/report tokens never appear in logs, admin
    aggregates, cache, README or CHANGELOG.
49. `[completed]` Verify CSRF, rate limiting, safe 202/422/429/503 responses and
    no stack/SQL/path details.
50. `[completed]` Verify Blade escaping and JS `textContent`/no dynamic HTML.
51. `[completed]` Scan diff for IP/raw UA/source URL/cookie/session/token/user ID
    persistence.
52. `[completed]` Verify no automatic source disable or client health verdict.

### Medium — performance and query plans

53. `[completed]` Compare indexes against actual aggregate SQL.
54. `[completed]` Run disposable SQLite `EXPLAIN QUERY PLAN` for retention,
    overview and three groups.
55. `[completed]` Measure focused query count; do not claim production SLA.
56. `[completed]` Confirm maximum grouped result 10 and period maximum 30 days.
57. `[completed]` Confirm player emits bounded requests and telemetry failure
    does not retry/reinitialize player.
58. `[completed]` Confirm no new cache/store/queue/scheduler/provider dependency.

### High — GREEN and regression

59. `[completed]` Run new schema/endpoint/issue/admin tests to GREEN.
60. `[completed]` Run player/transition/source/progress/technical issue tests.
61. `[completed]` Run route/translation/privacy/authorization regressions.
62. `[completed_exact_scope]` Run Pint on every Task 87 PHP file; global
    `--dirty` intentionally not used because it would rewrite foreign work.
63. `[completed_with_foreign_findings]` Task-scoped PHPStan GREEN; full Rector
    reported only two concurrent collection files; existing unrelated player
    nullsafe notice recorded without mixed-scope edit.
64. `[completed]` Run `npm run build`.
65. `[completed_with_foreign_failures]` Run full PHPUnit with temporary 1G
    config: 2 020 tests, 2 004 passed, 11 skipped, 3 failures and 1 error only
    in concurrent header/terminology/account/importer scope; source config
    remained unchanged and temporary file was removed.
    failures honestly.

### Medium — browser QA

66. `[completed]` Use Seasonvar Playwright QA workflow.
67. `[completed]` Verify authenticated report action on desktop/mobile:
    loading, network test, correct current episode/media and issue preview.
68. `[completed]` Verify fallback status sequence and no duplicate player.
69. `[completed_with_tablet_skipped]` Verify admin metrics desktop/mobile,
    long labels,
    keyboard focus, no horizontal overflow.
70. `[completed]` Check console/page/request/first-party response failures and
    JS errors.
71. `[completed]` Record emulation limitations honestly; no real-device claims.

### Medium — documentation and production

72. `[completed]` Update `technical-issues.md` and playback audit as canonical
    behavior owners.
73. `[completed]` Update security/performance/frontend/administration/
    DATA_RELATIONS/operations docs only where the contract actually changes.
74. `[completed]` Update `README.md` visitor capability and dated history.
75. `[completed]` Add dated Russian `CHANGELOG.md` entry without editing old
    history.
76. `[completed]` Record migration order, backup/data-safety, rollback and
    failure-recovery.
77. `[completed]` Run `php artisan project:docs-refresh --check`.

### Critical — final audit, commit and push

78. `[completed]` Reread applicable canonical requirements and this plan.
79. `[completed]` Update compliance matrix with `completed`,
    `already_compliant`, `not_applicable` or honest `unresolved`.
80. `[completed]` Search repository for legacy duplicate telemetry/player
    implementations, stale route/copy/cache paths, TODO/debug/dead controls.
81. `[completed]` Inspect `git status`, unstaged/staged/untracked, branch,
    remote, diff/stat and exact task-only diff.
82. `[completed]` Scan exact diff for secrets, raw provider URLs, logs,
    formatting noise, binaries, `.env`, vendor/node_modules/storage/cache.
83. `[completed]` Stage only task hunks/files through an isolated alternate Git
    index because shared working tree contains foreign changes.
84. `[completed]` Inspect `git diff --cached` from the isolated index.
85. `[completed]` Commit on existing `main` with exact factual message.
86. `[completed]` Confirm resulting branch/hash and that foreign shared changes
    remain untouched.
87. `[completed_with_unresolved_authentication]` Run non-force `git push` to
    configured current remote branch; GitHub HTTPS rejected it before data
    transfer because no username credential was available.
88. `[completed]` If authentication/network/protection rejects push, preserve
    local commit and report exact command/error/hash without claiming success.
