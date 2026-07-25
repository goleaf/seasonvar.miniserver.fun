# Premium Task 6 Authorization, Validation and Livewire State Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Project `AGENTS.md` requires the existing `main`, forbids a new branch/worktree without a new explicit user instruction and requires TDD RED → GREEN before production changes.

**Goal:** Закрыть проверяемыми server-side контрактами границы гостя, подтверждённого пользователя и Premium-администратора, защитить Livewire state от подмены, подтвердить IDOR/UUID/input/rate-limit/loading/no-GET invariants и повторно применять `verified` middleware к последующим Livewire-запросам.

**Architecture:** Сохраняются существующие full-page Livewire-компоненты, `AdminPermission`/gates, `PremiumPlanQuery`, `CreatePremiumCheckout`, mutation services и locked public IDs. Единственное заранее подтверждённое production-изменение — регистрация framework `EnsureEmailIsVerified` как persistent middleware: Livewire применит его только к компонентам, чей исходный маршрут уже содержит `verified`.

**Tech Stack:** PHP `8.5.8`, Laravel `13.22.0`, Laravel Boost `2.4.13`, Livewire `4.3.3`, SQLite, PHPUnit `12.5.32`, Pint `1.29.3`, Larastan `3.10.0`, Node.js `26.4.0`, Vite `8.1.4`, Tailwind CSS `4.3.2`.

**Execution status:** `implemented_verified_local_delivery_unresolved`.
Focused matrix: `15` tests/`96` assertions; Premium:
`48`/`335`; Administration: `71`/`2733`; scoped/full PHPStan and Pint passed.
Full PHPUnit: `1635` tests, `1622` passed, `11` skipped, one unrelated PHP
process failure passed in isolation and one pre-existing unrelated
browser-session failure repeated in isolation. No migration, data, provider,
cache, dependency, translation or asset change was required. Git delivery is
blocked by the pre-existing shared dirty worktree.

---

## Scope, files and protected contracts

**Expected changes:**

- Create: `tests/Feature/Premium/PremiumAuthorizationAndLivewireStateTest.php`.
- Modify: `app/Providers/AppServiceProvider.php`.
- Modify only if a separate RED proves a defect:
  `app/Livewire/Premium/PremiumPricingPage.php`,
  `app/Livewire/Premium/PremiumAdministrationManager.php`.
- Modify: `docs/premium.md`.
- Modify: `docs/development.md` only if the verified persistent-middleware
  contract is not already documented by the canonical Premium owner.
- Modify: `docs/superpowers/plans/2026-07-24-premium-improvement-master-plan.md`.
- Modify: `docs/plans/current-task-plan.md`.
- Modify: `README.md` only when visitor-visible behavior or roadmap actually
  changes; do not add a fictitious product update for test-only hardening.
- Modify: `CHANGELOG.md` with a separate dated Russian entry.

**Inspect-only unless RED:**

- `routes/web.php`, `bootstrap/app.php`, `config/premium.php`;
- `app/Services/Admin/*`, `app/Actions/Premium/CreatePremiumCheckout.php`;
- `app/Services/Premium/PremiumPlanQuery.php`,
  `PremiumPromotionService.php`, `PremiumEntitlementService.php`;
- Premium models, migrations, translations and Blade views.

**Contracts that must remain compatible:**

- route names and verbs for `premium.index`, `localized.premium.index`,
  `premium.return`, `localized.premium.return`, `premium.webhook`,
  `admin.premium`;
- public `/premium` truthful no-provider fallback and no fake checkout;
- `PremiumPaymentGateway`/registry, plan snapshots, provider identities and
  checkout idempotency semantics;
- `PremiumAccessResolver` as the only access authority;
- existing Premium gates, `AdminPermission`, roles and legacy allowlists;
- Livewire action names, Blade property names, RU/EN translation parity,
  private/no-store/noindex administration response;
- free catalog/player/download/progress/library behavior and all
  region/legal restrictions.

**Risks and rollback:**

- Persistent `EnsureEmailIsVerified` affects only Livewire components loaded
  through routes that already carry `verified`; this intentionally closes
  stale verification state and must not be registered as global middleware.
- Test gateway/plan fixtures exist only in PHPUnit memory and never enter
  production configuration or documentation as real offers.
- No migration, production DML, cache key, dependency, provider HTTP,
  environment, queue, worker or asset change is planned.
- Rollback is code-only: remove the one persistent middleware registration
  and its RED/GREEN contract. No data/cache rollback is required.

## Cross-feature impact

| Domain | Status | Evidence / decision |
| --- | --- | --- |
| Premium pricing/checkout | `affected_test_boundary` | Guest, verified account, plan input, throttling and duplicate-submit controls |
| Premium administration | `affected` | Gate, verified middleware, locked selected user, UUID and IDOR checks |
| Authentication/verification | `affected` | Route-level `verified` is re-applied to Livewire updates |
| Authorization/audit | `compatibility_required` | Existing gates and mutation services remain authoritative |
| Privacy/security | `affected` | No client-owned identity; owner/admin scopes remain server-side |
| Livewire/UI/mobile/a11y | `affected_test_boundary` | Existing disabled/loading controls and public method surface verified |
| Database/index | `test_only_reads_writes` | In-memory fixtures only; no schema/index change |
| Cache/rate limiter | `affected_test_boundary` | Existing per-user keys and configured limits verified; no shared cache |
| Locale/translations | `compatibility_required` | RU/EN errors and localized login route remain unchanged |
| Provider/billing | `compatibility_required` | Test stub only; no real provider or commercial activation |
| SEO/routes/sitemap | `compatibility_required` | No GET mutation; canonical/noindex contract preserved |
| Catalog/import/player/legal/region | `unaffected` | No content privilege or source behavior change |
| Production operations | `code_only` | No deployment state mutation; standard code rollback |

## Task 1: Freeze routes, verbs and loading contract

**Files:**

- Create: `tests/Feature/Premium/PremiumAuthorizationAndLivewireStateTest.php`
- Inspect: `routes/web.php`
- Inspect: `resources/views/livewire/premium/pricing-page.blade.php`
- Inspect: `resources/views/livewire/premium/administration-manager.blade.php`

- [x] Add a PHPUnit test that resolves all Premium routes by name and asserts
  public/admin page routes are GET-only while webhook is POST-only.
- [x] Assert no Premium GET route name/URI represents grant, revoke, coupon,
  checkout creation or another mutation.
- [x] Render configured test plans and assert checkout form uses
  `wire:submit="startCheckout"`, the submit button has
  `wire:loading.attr="disabled"` and a targeted loading state.
- [x] Run:
  `php artisan test tests/Feature/Premium/PremiumAuthorizationAndLivewireStateTest.php`
  and record characterization results.

## Task 2: RED — persist email verification on Livewire updates

**Files:**

- Modify: `tests/Feature/Premium/PremiumAuthorizationAndLivewireStateTest.php`
- Modify after RED: `app/Providers/AppServiceProvider.php`

- [x] Add a test asserting `Illuminate\Auth\Middleware\EnsureEmailIsVerified`
  is present in `Livewire::getPersistentMiddleware()`.
- [x] Run the focused test and verify it fails because this exact middleware
  is absent, not because of fixture or boot errors.
- [x] Import `EnsureEmailIsVerified` in `AppServiceProvider`.
- [x] Register it with `Livewire::addPersistentMiddleware(...)` next to
  `AuthenticateSession`, `EnsureAccountAccess` and `EnsureAdministrator`.
- [x] Re-run the focused test and verify GREEN.

## Task 3: Guest, verified account and plan-input boundary

**Files:**

- Modify: `tests/Feature/Premium/PremiumAuthorizationAndLivewireStateTest.php`
- Modify production component/action only after a distinct expected RED

- [x] Create an in-memory commercially valid plan and a PHPUnit stub
  `PremiumPaymentGateway`; bind it through the existing registry.
- [x] Verify a guest action with a valid server-side plan redirects to the
  correct localized login route and stores only the intended pricing URL.
- [x] Verify an unverified user receives the existing Russian
  `verified_account_required` validation error and creates no checkout row.
- [x] Verify invalid/oversized/unknown `selectedPlan` input creates no
  checkout and never calls the gateway.
- [x] Verify a verified user can reach the existing hosted redirect only with
  a locked server-generated checkout token and an allowlisted HTTPS host.
- [x] For every newly found defect: keep the failing test, make the smallest
  production change, re-run GREEN before proceeding.

## Task 4: Administration gates, locked identity and IDOR

**Files:**

- Modify: `tests/Feature/Premium/PremiumAuthorizationAndLivewireStateTest.php`
- Inspect: `app/Livewire/Premium/PremiumAdministrationManager.php`
- Inspect: `app/Services/Admin/*`

- [x] Verify an ordinary verified user cannot mount the Premium administration
  component.
- [x] Verify capability-specific roles cannot call grant/promotion actions
  outside their existing gates.
- [x] Select a user through `findUser`, attempt client mutation of
  `selectedUserPublicId` and assert Livewire rejects the locked-property
  update.
- [x] Select user A and pass user B's entitlement public UUID to `revoke`;
  assert 404 and no entitlement is revoked.
- [x] Pass invalid UUIDs to `revoke` and `createCoupon`; assert fail-closed
  responses and no mutation.
- [x] Confirm existing public IDs remain UUIDs while internal IDs/private
  notes/audit context are not accepted as action authority.

## Task 5: Input validation and rate limits

**Files:**

- Modify: `tests/Feature/Premium/PremiumAuthorizationAndLivewireStateTest.php`
- Inspect: `config/premium.php`
- Modify component/translations only after a distinct expected RED

- [x] Cover empty/oversized user lookup, invalid grant duration/reason/private
  note, invalid promotion code/date/limits and invalid coupon UUID.
- [x] Configure administration limit `1`, perform one accepted attempt and
  assert the next action receives the existing Russian rate-limit error
  without mutation.
- [x] Configure checkout limit `1`, use independent test users/tokens and
  verify excess checkout attempts fail closed without a second provider call.
- [x] Assert rate-limit keys are user-scoped and do not expose email, IP,
  provider payload or coupon secret.

## Task 6: Refactor and focused verification

**Files:**

- Modify only files already justified by RED

- [x] Remove duplicated test setup through private typed helpers; do not add
  production test hooks.
- [x] Run:
  `./vendor/bin/pint --dirty --format agent`.
- [x] Run:
  `php artisan test tests/Feature/Premium/PremiumAuthorizationAndLivewireStateTest.php`.
- [x] Run:
  `php artisan test --filter=Premium`.
- [x] Run:
  `php artisan test --filter=Administration`.
- [x] Run scoped PHPStan for changed PHP files, then the repository PHPStan
  command documented in `docs/development.md`.
- [x] Run `npm run build` only if Blade/assets changed.

## Task 7: Documentation, final compliance and delivery

**Files:**

- Modify: `docs/premium.md`
- Modify: Premium master/current plans
- Modify when applicable: `README.md`
- Modify: `CHANGELOG.md`

- [x] Document that `verified` route middleware is a persistent Livewire
  boundary and that IDs/action parameters remain untrusted.
- [x] Update master status/evidence without marking provider activation or
  Git delivery successful.
- [x] Re-read every applicable canonical requirement and update the
  task-specific compliance matrix with
  `completed|already_compliant|not_applicable|unresolved`.
- [x] Search the repository for duplicate Premium gates, client-trusted IDs,
  unvalidated action UUIDs, GET mutations, stale rate-limit keys and
  unfinished Task 6 code.
- [x] Check `README.md`; update only for a real visitor/product/roadmap change.
- [x] Add a dated Russian `CHANGELOG.md` entry without changing old entries.
- [x] Run documentation/README/CHANGELOG/whitespace policy checks.
- [x] Run the broad PHPUnit suite proportionate to the changed global
  middleware boundary.
- [x] Check `git status --short --branch`; commit only on existing `main` when
  the shared worktree can satisfy the clean-tree hook, then push. Otherwise
  report `unresolved_shared_worktree` without staging/resetting/stashing
  unrelated changes.
