# Task 26 Administration Architecture Design

Date: 2026-07-19
Status: approved by the user's instruction to follow the recommended design without further questions.

## Objective

Create one secure, multilingual and backward-compatible administration architecture around the real Seasonvar domains. Preserve every existing public contract and administration route while replacing broad email-only access decisions and duplicate menus with stable role/permission codes, active memberships, shared navigation/dashboard/table foundations and a safe audit/operations surface.

## Non-goals

- Do not invent advertiser, rights-holder, payment-provider, external-index, impersonation, deployment, backup, log-browser or service-worker functionality.
- Do not implement full user-account merge without proof-of-control and all domain reconciliation boundaries.
- Do not expose arbitrary shell, Artisan, SQL, cache keys, environment values, storage paths, source URLs or raw diagnostics.
- Do not replace existing content/moderation/support/premium services with parallel mini-applications.

## Access model

### Stable definitions

`AdminPermission` is the canonical internal permission identity. `AdminRoleCode` is the canonical system-role identity. Translation catalogs map codes to labels; labels never become stored identities.

Database tables store roles, permissions, role-permission links and user-role memberships. System definitions are synchronized idempotently from the registry. Custom role labels are not required for the first delivery: role codes remain controlled, reviewable and translatable.

Membership has `active`, `suspended` and `revoked` states, optional expiry, actor, reason code and timestamps. Deleted users cannot retain effective membership because membership references the canonical user with cascade deletion. Inactive roles and expired/revoked memberships grant nothing.

### Compatibility

Existing `seasonvar.admin_emails` continues to grant exactly the legacy non-sensitive capabilities during migration. Existing Premium allowlists continue to grant only their exact sensitive capability. The adapter does not auto-create roles or assign every legacy admin sensitive permissions.

Existing gate names remain available and map to stable permission codes. New code calls the canonical resolver/gates; legacy components remain compatible while they are incrementally normalized.

### Superadministrator

`superadministrator` is a narrowly controlled system role. It can administer the portal and RBAC, but separate Premium billing/reconciliation and future legal-document permissions remain explicit rather than silently bypassed. Removing/suspending the final active superadministrator is rejected inside a transaction with row locking. Assignment/removal requires recent password confirmation, explicit confirmation and audit.

An explicit configuration-backed bootstrap identity may establish the first superadministrator. It is not inferred from matching email alone and is documented as a temporary deployment/bootstrap boundary.

## Request flow

```text
web auth + auth.session + verified
    -> account.private headers
    -> admin.access membership/legacy eligibility
    -> section gate/policy
    -> Livewire component
    -> validation + action/query object
    -> transaction/domain service
    -> audit + targeted invalidation after commit
```

Livewire mutations repeat authorization. Browser-supplied IDs are stable UUIDs/codes and are resolved server-side; raw role, ownership, price, premium, region or permission state is never trusted.

## Routes and shell

All routes live in one `/admin`/`admin.*` group. Existing names remain unchanged. New canonical entries:

- `admin.index` — permission-scoped real dashboard.
- `admin.users` — bounded user directory and safe restriction summary.
- `admin.access` — roles, permissions and administrator memberships.
- `admin.audit` — paginated safe audit viewer.
- `admin.operations` — truthful installed-capability/cache/search/health summary and only narrowly authorized actions.

The public layout remains the outer application shell for compatibility. On `admin.*`, it renders one server-prepared administration navigation component. The registry owns code, route, translation key, icon, group, order, required permission and availability. Public header navigation exposes one Administration entry rather than duplicating every staff destination.

## Dashboard

`AdministrationDashboardQuery` returns section DTOs, not models. Aggregates are grouped by domain and selected only when the viewer has the section permission. Each domain group is isolated so a failed optional schema/integration produces a translated unavailable state without taking down the entire dashboard. Counts are real, timestamps state when they were read, no real-time claim is made, and sensitive ticket/billing/legal details are omitted.

## Shared list architecture

The shared table definition owns stable column/sort/filter/action codes. Query objects map allowlisted codes to concrete columns; request input never becomes a raw column or SQL fragment. Page size is bounded, sorting deterministic, relations projected/eager-loaded, and Livewire stores scalar filters plus bounded public-ID selections only.

Filters have draft/applied state where complex, translated labels, clear/apply actions and safe `#[Url(history: true)]` state. Private search values are never cached.

Bulk definitions own code, permission, eligibility, maximum items, preview, confirmation, transaction/partial-failure behavior, audit and invalidation. There is no select-all-database mode. Per-item authorization is mandatory.

## Audit and internal notes

`admin_audit_events` remains the canonical generic audit store. It is extended for stable public identity, correlation identity and safe metadata while retaining existing fingerprints/field allowlist. New RBAC, login/restriction, export, cache and settings actions write stable events. Secrets, session IDs, source URLs, document contents, provider payloads and private notes are rejected/redacted.

Domain-specific notes stay in their current domain services. A shared internal-note contract may wrap presentation/authorization but does not move ticket/legal/billing content into a less restricted generic store.

## User and lifecycle administration

The user directory selects only public ID, display identity, verification state, profile moderation, role summary and authorized aggregate counts. Password hashes, remember/reset/OAuth/MFA/payment tokens, session IDs, raw IPs, documents and unrelated private history never enter the view model.

Account login restriction uses a stable restriction record and a canonical account-access resolver shared by web/mobile authentication and active sessions. Domain comment/review restrictions remain the owner of posting/review restrictions. Secure self-export/deletion remains owned by Task 15 services. Administration may start only workflows that have a complete coordinator; full account merge remains unavailable and is never triggered from email similarity.

## Existing feature integration

Catalog, collections, tags, comments, reviews, profiles, requests, issues, help, calendar, imports and Premium retain their current routes/components/actions/policies. They become registry destinations and use canonical permission resolution through preserved gate names. Recommendations/search/SEO/cache capabilities expose only real safe status/actions backed by current services. Advertiser and rights-holder sections remain absent until their domain models and separate permissions exist.

## Operations and settings

The operations capability registry reports only evidence-backed installed state. Cache operations bump a known `CacheDomain`/resource generation through existing registries; arbitrary key deletion and full application flush are unavailable. Search operations call the existing catalog database-index service only. Health summaries reuse `InfrastructureHealthCheck` safe output. No page-load diagnostic mutates state or calls external providers.

System settings use a registry of stable keys, explicit types, defaults, validation, scope, sensitivity, permission, cache behavior and audit behavior. Environment secrets are never registered or editable. No unrestricted key-value editor exists.

## Localization, responsive behavior and accessibility

New labels live in `lang/ru/administration.php` and `lang/en/administration.php` with identical keys/placeholders. Locale remains presentation state, never permission/role/cache identity. The admin shell is mobile-first: compact drawer/navigation on phones, productive sidebar/section navigation on larger screens, no horizontal page overflow, accessible table scroll/card fallback, 44px controls, visible focus, current-page semantics, live loading/success/error announcements and reduced-motion-safe transitions.

## Data safety, performance and rollback

- Additive SQLite-compatible migrations; no existing migration edits.
- Unique/index constraints match role lookup, effective-membership, audit list and restriction list queries.
- No public/global cache of permissions, navigation, user data, audit or dashboard overlays.
- Resolver loads role/permission graph once per request; navigation does not issue one permission query per item.
- Dashboard uses grouped aggregate queries; tables paginate and project fields.
- Role/membership mutation invalidates request/session authorization state and is effective on the next request/Livewire update.
- Rollback restores legacy gates/routes and drops only new unused schema after dependency review.

## Verification

PHPUnit covers migration shape, stable definitions, compatibility adapter, membership state/expiry, sensitive permission separation, final-superadministrator protection, assign-only-possessed, middleware, route headers, navigation visibility, dashboard grouping/failure state, table/filter bounds, bulk preview/per-item authorization, audit redaction/pagination, account restriction across web/mobile auth, cache/search operations, translation parity and sitemap exclusion. Pint, full tests, build, route/schema/query inspection and Playwright mobile/tablet/desktop/a11y smoke complete delivery.
