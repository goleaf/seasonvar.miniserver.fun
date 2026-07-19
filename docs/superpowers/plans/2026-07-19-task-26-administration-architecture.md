# Task 26 Administration Architecture Implementation Plan

> Execute on the existing `main` branch only. Use TDD for every changed behavior. Do not create a worktree or branch.

**Goal:** Add one canonical RBAC-backed administration shell, dashboard, user/access/audit/operations surfaces and shared list/action foundations while preserving and integrating every real existing administration feature.

**Architecture:** Stable enum/registry definitions feed additive role/permission/membership schema. A request-scoped resolver maps memberships and the narrow legacy allowlist to preserved Laravel gates. One route group, middleware, navigation registry and shared shell wrap existing domain Livewire managers. New query/action classes provide bounded dashboard, users, access, audit and operations behavior.

**Installed stack:** PHP 8.5, Laravel 13.20.0, Livewire 4.3.3, Tailwind CSS 4.3.2, Vite 8.1.4, PHPUnit 12.5, SQLite local/test.

## Task 1: Lock the schema and stable code contracts

**Files:**
- Create `app/Enums/AdminPermission.php`
- Create `app/Enums/AdminRoleCode.php`
- Create `app/Enums/AdminMembershipStatus.php`
- Create `app/Enums/AdminPermissionSensitivity.php`
- Create `app/Services/Admin/AdminAccessRegistry.php`
- Create `database/migrations/2026_07_19_240000_create_administration_access_tables.php`
- Create `database/migrations/2026_07_19_240100_seed_administration_access_definitions.php`
- Create `tests/Feature/Administration/AdminAccessSchemaTest.php`
- Create `tests/Unit/AdminAccessRegistryTest.php`

1. Write failing tests for stable unique codes, default role matrix, sensitive permission separation, SQLite schema, FKs/unique constraints/indexes and rollback-safe definitions.
2. Run `php artisan test --filter=AdminAccessSchemaTest` and `--filter=AdminAccessRegistryTest`; capture RED.
3. Implement typed enums/registry and additive schema for roles, permissions, role-permissions and user-role memberships.
4. Synchronize only stable system reference definitions idempotently; do not assign users or translate stored codes.
5. Run focused tests to GREEN and Pint.

## Task 2: Effective access resolver and legacy compatibility

**Files:**
- Create `app/Models/AdminRole.php`
- Create `app/Models/AdminPermissionRecord.php`
- Create `app/Models/AdminUserRole.php`
- Create `app/Services/Admin/AdminAccessResolver.php`
- Create `app/Services/Admin/AdminGateRegistrar.php`
- Modify `app/Models/User.php`
- Modify `app/Providers/AppServiceProvider.php`
- Modify `config/administration.php`
- Modify `.env.example`
- Create `tests/Feature/Administration/AdminAuthorizationTest.php`

1. Write failing tests for active/inactive/expired/suspended/revoked memberships, legacy exact capability mapping, no automatic sensitive grants, one resolver graph load and preserved legacy gate names.
2. Run the test and capture RED.
3. Implement relationships and request-scoped resolver. Map configured legacy email cohort only to the capabilities it already had; map each Premium allowlist only to its exact permission.
4. Register all gates through one registrar. Do not use translated labels or browser state.
5. Run focused tests to GREEN and Pint.

## Task 3: Administrator middleware and canonical route group

**Files:**
- Create `app/Http/Middleware/EnsureAdministrator.php`
- Modify `bootstrap/app.php`
- Modify `routes/web.php`
- Modify `app/Providers/AppServiceProvider.php`
- Create `tests/Feature/Administration/AdminRouteSecurityTest.php`

1. Write failing tests for guest redirect, unverified denial, ordinary-user denial, suspended/revoked denial, active member success, private/no-store/noindex headers, no destructive GET and unchanged existing route names.
2. Run focused test and capture RED.
3. Register `admin.access`, persist the middleware across Livewire requests and normalize all routes into one `/admin` group with `auth`, `auth.session`, `verified`, `account.private`, `admin.access` plus section gates.
4. Add `admin.index`, `admin.users`, `admin.access`, `admin.audit`, `admin.operations`; keep all 12 existing names/URIs.
5. Run focused tests, route inspection and Pint.

## Task 4: Role and administrator mutation actions

**Files:**
- Create `app/Actions/Administration/AssignAdminRole.php`
- Create `app/Actions/Administration/RevokeAdminRole.php`
- Create `app/Actions/Administration/SetAdminMembershipStatus.php`
- Create `app/Exceptions/AdministrationAccessException.php`
- Extend `app/Enums/AdminAuditAction.php`
- Extend `app/Services/Admin/AdminAuditRecorder.php`
- Create `tests/Feature/Administration/AdminRoleMutationTest.php`

1. Write failing tests for assign-only-possessed, inactive role rejection, recent-auth requirement, explicit reason, idempotency, audit, session/access refresh and final active superadministrator protection under transaction/lock.
2. Run focused test and capture RED.
3. Implement actions; no mass assignment or broad bypass. Superadministrator does not silently obtain explicit billing/legal permissions.
4. Record safe stable audit events without email/password/session/private note metadata.
5. Run focused tests and Pint.

## Task 5: Shared navigation shell

**Files:**
- Create `app/DTOs/Administration/AdminNavigationItemData.php`
- Create `app/Services/Admin/AdminNavigationRegistry.php`
- Create `app/Services/Admin/AdminNavigationQuery.php`
- Create `resources/views/components/administration/navigation.blade.php`
- Modify `app/View/ViewData/AppLayoutData.php`
- Modify `resources/views/layouts/app.blade.php`
- Create `tests/Feature/Administration/AdminNavigationTest.php`

1. Write failing tests for stable ordering/groups, translated labels, one permitted Administration entry in public navigation, permission-filtered destinations, active semantics, absent fake destinations and no one permission query per item.
2. Run focused test and capture RED.
3. Implement registry/query and passive Blade navigation. Use one prepared permission set and `route()` only.
4. Render mobile wrapping/drawer-compatible navigation and desktop section navigation with visible focus/current-page semantics.
5. Run tests, Pint and `npm run build`.

## Task 6: Real isolated dashboard

**Files:**
- Create `app/DTOs/Administration/AdminDashboardSectionData.php`
- Create `app/Services/Admin/AdministrationDashboardQuery.php`
- Create `app/Livewire/Administration/AdministrationDashboardPage.php`
- Create `resources/views/livewire/administration/dashboard.blade.php`
- Create `tests/Feature/Administration/AdminDashboardTest.php`

1. Write failing tests for real counts, grouped domain queries, permission-aware omission, no sensitive rows, freshness label, empty state and isolated optional-schema failure.
2. Run focused test and capture RED.
3. Implement projected aggregate DTOs for catalog, moderation, support, Premium and operations only when permitted. Load recent safe admin events separately and bounded.
4. Render textual summaries and accessible responsive lists; no fake charts or real-time claims.
5. Run tests, query-count assertions, Pint and build.

## Task 7: Shared table, filter, confirmation and bulk contracts

**Files:**
- Create `app/DTOs/Administration/AdminTableColumnData.php`
- Create `app/DTOs/Administration/AdminFilterData.php`
- Create `app/DTOs/Administration/AdminBulkActionData.php`
- Create `app/Support/Administration/AdminTableState.php`
- Create `resources/views/components/administration/table.blade.php`
- Create `resources/views/components/administration/filters.blade.php`
- Create `resources/views/components/administration/action-confirmation.blade.php`
- Create `resources/views/components/administration/state.blade.php`
- Create `tests/Unit/AdminTableStateTest.php`
- Create `tests/Feature/Administration/AdminSharedComponentsTest.php`

1. Write failing tests for allowlisted sort/filter codes, bounded page size/selection, deterministic fallback sort, URL-safe filters, preview-required bulk definitions and accessible state markup.
2. Run tests and capture RED.
3. Implement typed definitions and passive components; never map request values directly to columns.
4. Require stable public IDs, max 50 selections, explicit action code and preview before execution.
5. Run focused tests, Pint and build.

## Task 8: User directory and account restriction boundary

**Files:**
- Create `app/Enums/AccountRestrictionType.php`
- Create `app/Models/AccountRestriction.php`
- Create `database/migrations/2026_07_19_240200_create_account_restrictions_table.php`
- Create `app/Services/Auth/AccountAccessResolver.php`
- Create `app/Actions/Administration/ApplyAccountRestriction.php`
- Create `app/Actions/Administration/RevokeAccountRestriction.php`
- Create `app/Services/Admin/AdminUserQuery.php`
- Create `app/Livewire/Administration/AdminUserDirectoryPage.php`
- Create `resources/views/livewire/administration/users.blade.php`
- Modify `app/Services/Auth/WebAuthenticationService.php`
- Modify `app/Services/Auth/MobileAuthenticationService.php`
- Create `tests/Feature/Administration/AdminUserDirectoryTest.php`
- Create `tests/Feature/Administration/AccountRestrictionTest.php`

1. Write failing tests for bounded public-ID search, private-field omission, deterministic pagination, separate permission visibility and no password/token/session/payment/private-history output.
2. Write failing tests showing `login_suspended` and `account_disabled` reject web/mobile login and active sessions, cannot be bypassed by alternate auth path, revoke tokens/sessions when required, preserve Premium records and audit changes.
3. Run focused tests and capture RED.
4. Implement query/DTO, restriction schema/resolver/actions and authentication integration. Keep comment/review restrictions in their canonical services.
5. Render shared table/filter/confirmation states. Full account merge remains translated unavailable because its coordinator is absent; self-delete/export links retain Task 15 ownership.
6. Run focused auth/account/admin regressions and Pint/build.

## Task 9: Access management page

**Files:**
- Create `app/Services/Admin/AdminAccessManagementQuery.php`
- Create `app/Livewire/Administration/AdminAccessManagementPage.php`
- Create `resources/views/livewire/administration/access.blade.php`
- Create `tests/Feature/Administration/AdminAccessManagementPageTest.php`

1. Write failing tests for permission-scoped roles/memberships, no email enumeration for unrelated viewers, translated role labels, recent-auth mutation rejection, confirmation and partial unavailable states.
2. Run test and capture RED.
3. Implement bounded user lookup and role assignment/revocation through Task 4 actions only.
4. Show the compatibility source and sensitive permissions explicitly; never display config secrets.
5. Run tests, Pint and build.

## Task 10: Audit viewer and safe exports

**Files:**
- Create `database/migrations/2026_07_19_240300_extend_admin_audit_events.php`
- Modify `app/Models/AdminAuditEvent.php`
- Modify `app/Services/Admin/AdminAuditRecorder.php`
- Create `app/Services/Admin/AdminAuditQuery.php`
- Create `app/Services/Admin/AdminAuditCsvExporter.php`
- Create `app/Livewire/Administration/AdminAuditPage.php`
- Create `resources/views/livewire/administration/audit.blade.php`
- Create `tests/Feature/Administration/AdminAuditViewerTest.php`
- Create `tests/Unit/AdminAuditCsvExporterTest.php`

1. Write failing tests for stable public/correlation identity, metadata allowlist/redaction, immutable records, bounded filters/sorts/pagination, permission-aware export and CSV formula-injection protection.
2. Run tests and capture RED.
3. Extend the existing table additively; preserve old events and recorder contract. Export only allowlisted summary fields to a protected streamed response; no full dump.
4. Render actor/resource/action/time/safe field summary only. No raw before/after values, notes, IPs, attachments or payloads.
5. Run tests, migration rollback test, Pint and build.

## Task 11: Truthful operations, cache, search and SEO integration

**Files:**
- Create `app/Services/Admin/AdminCapabilityRegistry.php`
- Create `app/Services/Admin/AdminOperationsQuery.php`
- Create `app/Actions/Administration/InvalidateAdministeredCache.php`
- Create `app/Actions/Administration/ReindexCatalogResource.php`
- Create `app/Livewire/Administration/AdminOperationsPage.php`
- Create `resources/views/livewire/administration/operations.blade.php`
- Create `tests/Feature/Administration/AdminOperationsTest.php`

1. Write failing tests that installed capabilities reflect real classes/config/schema, absent providers/domains show no actions, safe health output contains no secret/path/raw error, cache actions are targeted/audited and arbitrary/full flush is rejected.
2. Write failing tests that database catalog search index reindex is bounded/authorized/idempotent and no external-index claim appears.
3. Run tests and capture RED.
4. Implement adapters over existing `CacheVersionRegistry`, catalog search indexer, sitemap/SEO and `InfrastructureHealthCheck`. No provider call or destructive diagnostic on render.
5. Render installed/unavailable summaries, recovery guidance and only real actions.
6. Run focused operations/cache/search/SEO tests, Pint and build.

## Task 12: Integrate all existing administration modules

**Files:**
- Modify existing administration Livewire components only where permission mapping/state contract requires it
- Modify existing administration Blade views only where shared shell/translation/state integration requires it
- Create `tests/Feature/Administration/AdminFeatureIntegrationTest.php`

1. Write a route/registry matrix test for catalog/collections/seasons/episodes/media/tags/comments/reviews/profiles/requests/issues/help/calendar/recommendations/imports/Premium.
2. Capture RED for destinations not yet registered or capability leakage.
3. Register every real module under one navigation group and stable permission. Preserve current routes/actions and existing policy checks.
4. Add recommendation/search/SEO status links only when backed by the operations page. Keep advertiser/rightsholder/notification-template/provider controls absent when no domain exists.
5. Run all affected domain tests and Pint/build.

## Task 13: Localization, security, performance and accessibility closure

**Files:**
- Create `lang/ru/administration.php`
- Create `lang/en/administration.php`
- Modify relevant canonical test/docs files
- Create `tests/Unit/AdministrationTranslationParityTest.php`
- Create `tests/Feature/Administration/AdminSecurityRegressionTest.php`
- Create `tests/Feature/Administration/AdminQueryBudgetTest.php`

1. Write failing recursive locale-key/placeholder parity tests and UI raw-key regression tests.
2. Write security tests for IDOR, mass assignment, XSS escaping, open redirect/path/cache-key rejection, private cache, CSRF/write methods, sensitive-field redaction and permission changes taking effect.
3. Write query-budget/EXPLAIN tests for navigation, dashboard, users and audit at scale; justify/add only required indexes.
4. Implement fixes until tests pass. Scan Blade for forbidden patterns and hardcoded new user-facing strings.
5. Run Pint, focused tests, `npm run build`, then full `php artisan test`.

## Task 14: Documentation, browser QA and delivery

**Files:**
- Modify `docs/administration.md`, `docs/authorization.md`, `docs/security.md`, `docs/performance.md`, `docs/caching.md`, `docs/UI_STANDARDS.md`, `docs/system-integration.md`, `docs/DATA_RELATIONS.md`, `docs/README.md`
- Modify `docs/plans/current-task-plan.md`
- Modify `README.md`
- Modify `CHANGELOG.md`

1. Re-run route/schema/link/docs-refresh checks; update owners without duplicating contracts.
2. Use Playwright against guest, ordinary user and configured admin on phone/tablet/desktop: navigation, dashboard, users, roles, audit, operations, loading/empty/error, keyboard/focus/zoom and no horizontal overflow.
3. Re-read `AGENTS.md`, requirement index, every canonical owner and Task 26; reconcile the compliance matrix honestly.
4. Run `./vendor/bin/pint --dirty --format agent`, focused tests, full `php artisan test`, `./vendor/bin/phpunit` when useful, `npm run build`, static forbidden-pattern/translation/route/sitemap/cache/security scans.
5. Inspect `git diff --check`, exact staged scope, branch/status and unrelated work. Update Russian README visitor history only for real visible functionality and Russian `CHANGELOG.md` without altering old entries.
6. Commit authorized Task 26 work to `main`, push the configured remote, record the SHA/push result in the current plan and provide the final compliance report.
