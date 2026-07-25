# Premium Improvement Implementation Master Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Project `AGENTS.md` forbids a new branch/worktree and permits sub-agents only after a new explicit user request, so the current execution remains inline on existing `main`.

**Goal:** Довести существующий Premium entitlement и billing boundary до полностью тестируемого, быстрого и production-ready состояния, подключая только реальные согласованные provider capabilities и сохраняя честный free/no-provider fallback.

**Architecture:** `docs/premium.md` владеет постоянным доменным контрактом, historical Task 22 остаётся baseline, а этот файл владеет единственной rolling execution queue. Существующие resolver, query, action, gateway, reconciler, audit, notification, Livewire и administration boundaries расширяются без второго Premium-домена.

**Tech Stack:** PHP `8.5.8`, Laravel `13.22.0`, Laravel Boost `2.4.13`, Livewire `4.3.3`, SQLite, PHPUnit `12.5.32`, Pint `1.29.3`, Larastan `3.10.0`, Node.js `26.4.0`, Vite `8.1.4`, Tailwind CSS `4.3.2`, Playwright `1.61.1`.

## Global Constraints

- Работать только в существующей `main`; не создавать branch/worktree и не
  stage/reset/stash/delete foreign changes shared checkout.
- Не добавлять provider SDK/dependency без отдельного явного согласования.
- Не редактировать `.env`, deployed Premium migration или production rows.
- Не создавать fake provider, plan, price, currency, checkout, invoice,
  discount, renewal, benefit, health или success state.
- `PremiumAccessResolver` остаётся единственным entitlement resolver;
  `PremiumPaymentGateway`/registry — единственной provider boundary;
  `PremiumBillingReconciler` — единственным trusted event processor.
- Browser/user input не владеет user, provider, amount, currency, status,
  period, entitlement, refund или dispute.
- User/payment/provider state не попадает в shared cache; targeted
  invalidation и request memoization сохраняются.
- Все интерфейсные ключи имеют exact `ru|en` parity; stable identities не
  переводятся.
- Free catalog/player/download/progress/library/comments/reviews,
  region/legal restrictions, API/routes/SEO и import сохраняются.
- Каждая implementation task проходит TDD RED → GREEN → refactor, focused
  tests, Pint, applicable static/browser/docs gates и отдельный rollback.
- Code, verification, Git delivery, production activation и real-provider
  verification имеют разные статусы.

---

## 1. Plan status и verified baseline

**Plan status:** rolling implementation без верхнего лимита задач. Tasks 1–6
реализованы и проверены локально; их Git delivery остаётся отдельно
`unresolved_shared_worktree`. Task 7 является следующим `ready`. Tasks 7–20
остаются первым evidence-backed проходом. Tasks 21+
принимаются без верхнего номера только по monotonic rolling protocol;
выполнение без проверяемых checkpoints запрещено.

Live/browser baseline:

- `/premium`: `200`, TTFB `72–116 ms` в трёх последовательных GET;
- desktop `1440×1000` и mobile `390×844`: no overflow/errors/failed requests;
- canonical `/premium`, `noindex, follow`, Livewire page;
- provider/currency/public plan отсутствуют, fake checkout отсутствует.

Database baseline:

- production-size SQLite: около `28 GB`;
- все 12 Premium-таблиц существуют и содержат `0` rows;
- plan query использует `premium_plans_public_order_idx`;
- payment history использует `premium_payments_user_time_idx`;
- active entitlement использует существующий индекс; Task 2 удалил
  необязательный `ORDER BY`, поэтому временная B-tree больше не строится.

Delivery baseline:

- core Premium был добавлен в commit `8b9df18`;
- administration integration продолжена в `eb4e7f9`;
- текущая `main` содержит foreign dirty scopes, поэтому новый focused
  commit/push остаётся отдельным delivery gate.

## 2. Dependency graph

| Фаза | Tasks | Gate |
| --- | --- | --- |
| 0. Current safe state | 1–5 | Truthful no-provider UI, tests и bounded reads |
| 1. Domain correctness | 6–9 | Authorization, promotion, checkout и event invariants |
| 2. Provider readiness | 10–13 | Adapter-independent matrix/operations pass |
| 3. Real commerce | 14–16 | Explicit provider/business/legal approval |
| 4. Product integrations | 17–20 | One feature/capability at a time |
| Rolling intake | 21+ | Next monotonic evidence-backed task only |

## 3. Task 1 — no-provider query fast path и Premium characterization

**Priority:** P0.  
**Status:** `completed_local_delivery_unresolved`.  
**Detailed TDD plan:** [`2026-07-24-premium-foundation-query-hardening.md`](2026-07-24-premium-foundation-query-hardening.md).

**Produces:**

- zero Premium DB queries for guest/no-provider pricing read;
- zero schema/plan queries for an invalid external plan code;
- one memoized framework schema inventory operation when readiness is
  actually required (two SQL statements on SQLite);
- dedicated RU/EN pricing and query-budget tests;
- canonical query-budget documentation and fresh browser evidence.

**Evidence:** query RED `14/12/2` → GREEN `0/2/0`; Premium tests `9/9`,
related administration tests `31/31`, scoped PHPStan `0` errors, full PHPUnit
`1581` tests with `1570` passed and `11` skipped. RU desktop/EN mobile live
smoke preserved canonical, `noindex, follow`, zero checkout controls, zero
overflow and zero console errors. No provider, plan, currency, migration,
route, translation, asset, cache-key or production-data change was made.

## 4. Task 2 — access resolver correctness matrix

**Priority:** P0.
**Status:** `completed_local_delivery_unresolved`.
**Detailed TDD plan:**
[`2026-07-25-premium-access-resolver-correctness.md`](2026-07-25-premium-access-resolver-correctness.md).

**Files:** `PremiumAccessResolver`, новый
`tests/Feature/Premium/PremiumAccessResolverTest.php` и owner/evidence docs.
`PremiumEntitlement` и factory не изменялись после проверки достаточности
локальных typed fixture builders.

Cover inactive/future/expired/revoked, duration extension, lifetime,
manual+promotion+subscription overlap, provider grace, cancellation, unrelated
refund/chargeback and request memo invalidation. Add an additive active-read
index only after the test dataset and SQLite `EXPLAIN QUERY PLAN` demonstrate
the current temporary sort is material.

**Gate:** no shared user cache; one bounded active-entitlement query plus one
projected subscription eager load; old migration unchanged.

**Evidence:** TDD RED `9/10` с единственным ожидаемым отказом из-за
`ORDER BY starts_at` → GREEN `10/10`, `59` assertions. Матрица подтверждает
inactive/future/expired/revoked/exact boundaries, lifetime и пересекающиеся
sources, explicit grace, cancellation, non-shortening duration extension,
payment-scoped revoke, `forget()` и request memo. Первый read выполняет ровно
два projected SQL с subscription row и повторный — ноль. Production read-only
`EXPLAIN QUERY PLAN` использует
`premium_entitlements_user_feature_active_idx` без temporary B-tree; таблицы
entitlement/subscription пусты, поэтому migration/index не добавлялись.
`PremiumEntitlement` и factory не менялись: локальные typed fixture builders
дали достаточную изоляцию без расширения production model surface.
Premium/admin regressions прошли `42` tests и `435` assertions, Help Center
consumer — `1/2`, полный/scoped PHPStan, Pint и Rector — без замечаний.
Полный PHPUnit выполнил все `1601` tests (`1589` passed, `11` skipped), но
сохранил один unrelated external blocker: foreign upgrade
`laravel/framework 13.22.0` включает `ae66e4c`, из-за которого
`CookieJar::hasQueued()` вызывает `Arr::last(null)` в тесте выхода из других
браузерных сессий до project code. Task 2 не изменяет dependency/authentication
scope и не патчит `vendor`.

## 5. Task 3 — bounded public plans и immutable pricing

**Priority:** P0.
**Status:** `completed_local_delivery_unresolved`.
**Detailed TDD plan:**
[`2026-07-25-premium-public-plan-bounds.md`](2026-07-25-premium-public-plan-bounds.md).

**Files:** `PremiumPlanQuery`, `PremiumPlan`, `Money`, provider registry,
factories and `PremiumPlanQueryTest`.

Push SQL-safe predicates before hydration, select only required columns,
enforce a documented maximum public plan count, reject invalid
currency/provider/price/feature/region/editorial mappings and verify stable
ordering. Preserve database snapshot as amount authority and locale-only
formatting.

**Gate:** no plan cache until measured traffic justifies it; any later scalar
cache must include locale/region/currency/provider/feature versions.

**Evidence:** RED зафиксировал unbounded `13` rows, SQL для unsupported locale
и enum hydration error; GREEN прошёл `4` теста и `28` утверждений.
Public read теперь использует `13` выбранных полей, candidate window `48`,
итоговый предел `12` и порядок `(display_order,id)`. Premium suite прошёл
`23` теста и `136` утверждений, administration — `63` теста и `2635`
утверждений, направленный PHPStan — без ошибок. Read-only SQLite plan выбирает
`premium_plans_public_order_idx`; migration, cache и provider HTTP не
добавлялись. Commit/push остаются отдельным delivery gate общего рабочего
дерева.

## 6. Task 4 — account/settings read model и history pagination

**Priority:** P0.
**Status:** `completed_local_delivery_unresolved`.
**Detailed TDD plan:**
[`2026-07-25-premium-account-read-model.md`](2026-07-25-premium-account-read-model.md).

**Files:** `PremiumAccountQuery`, `PremiumAccessResolver`,
`PremiumPaymentHistoryData`, `AccountSettingsPage`, settings Blade and
`PremiumAccountQueryTest`.

Remove overlapping entitlement/subscription reads through one prepared
account snapshot, keep private history bounded, select explicit columns and
verify deterministic `(user_id, created_at, id)` ordering. Choose
`paginate`, `simplePaginate` or `cursorPaginate` only from measured UI/count
requirements; preserve existing page name and URL compatibility.

**Evidence:** RED зафиксировал отсутствующий `snapshot()` и unbounded
`perPage=1000`. GREEN выполняет один top-level entitlement SELECT и одну
projected subscription-выборку для active/recent account state, сохраняет
старую активную выдачу за пределами видимых `25` строк и передаёт payment rows
immutable DTO. Full paginator сохраняет `premiumPaymentsPage`, default `15`,
maximum `50` и `(created_at,id) DESC`. Focused account/resolver matrix прошла
`14` тестов и `86` утверждений, Premium filter — `29` тестов и `176`
утверждений, направленный PHPStan — без ошибок. Read-only production snapshot
содержит `0` entitlement/subscription/payment/refund rows, а payment
`EXPLAIN QUERY PLAN` использует `premium_payments_user_time_idx`; migration,
cache и provider HTTP не добавлялись. Commit/push остаются отдельным delivery
gate общего рабочего дерева.

## 7. Task 5 — administration query boundary

**Priority:** P1.

**Status:** `implemented_verified_local_delivery_unresolved`.

Один `PremiumAdministrationQuery` теперь готовит user, entitlement, promotion
и audit sections как bounded safe arrays/paginator. Livewire сохранил все
action gates и mutations. UUID и exact normalized email разделены на
index-backed paths; legacy `lower(email)` остаётся только fallback. Read-only
snapshot содержит `102` users, `0` ненормализованных email и пустые
затрагиваемые Premium tables, поэтому schema/index change не обоснован.
Focused query/Livewire test прошёл `4` tests и `63` assertions; Premium filter
— `33/239`, administration filter — `67/2698`, full PHPStan/Pint/docs gates —
успешно. Full PHPUnit выполнил `1613` tests: `1601` passed, `11` skipped и один
известный unrelated browser-session failure, повторённый отдельно. Commit/push
остаётся `unresolved_shared_worktree`.
Исполнимый план и evidence:
[`2026-07-25-premium-administration-query-boundary.md`](2026-07-25-premium-administration-query-boundary.md).

## 8. Task 6 — authorization, validation and Livewire state

**Priority:** P0.
**Status:** `implemented_verified_local_delivery_unresolved`.
**Detailed TDD plan:**
[`2026-07-25-premium-authorization-validation-livewire-state.md`](2026-07-25-premium-authorization-validation-livewire-state.md).

Test guest/verified/owner/admin boundaries, locked IDs, IDOR, invalid UUID,
plan/coupon/user input, rate limits, double-click loading and no GET mutation.
Reuse current gates and `AdminPermission`; do not create a parallel role
system. Official Livewire 4 documentation confirms that route-level
middleware outside its default persistent list must be registered explicitly.
RED подтвердил отсутствие `EnsureEmailIsVerified` в persistent list Livewire.
Минимальный GREEN зарегистрировал framework middleware рядом с session,
account и administrator boundaries; он повторяется только для исходных
маршрутов с `verified`. Dedicated matrix прошла `15` tests/`96` assertions,
Premium filter — `48/335`, administration — `71/2733`, scoped/full PHPStan и
Pint — без ошибок. Full PHPUnit выполнил `1635` tests: `1622` passed, `11`
skipped, единичный foreign PHP process failure прошёл отдельно, один прежний
browser-session failure повторился отдельно. Routes, gates, locked IDs, UUID,
IDOR, input/rate/loading/no-GET-mutation contracts подтверждены; migrations,
data, provider/config/HTTP, cache, translations, dependencies и assets не
менялись.

## 9. Task 7 — promotion/coupon concurrency

**Priority:** P0.

Test HMAC identity, Unicode/control/bidi rejection, active windows,
total/per-user/coupon limits, duplicate submission and concurrent redemption.
Keep full code one-time display only and preserve exact-source entitlement.

## 10. Task 8 — checkout idempotency и pending recovery

**Priority:** P0.

Test verified account, plan revalidation, duplicate lifetime/recurring guard,
same request token, concurrent pending session, exact snapshot and checkout
host/TTL validation. Define a truthful resume/expired UX only if the chosen
adapter can recover or recreate a hosted session safely.

## 11. Task 9 — webhook ingress boundary

**Priority:** P0.

Test unknown provider, exact CSRF exclusion, throttle, 256 KiB limit,
raw-body signature handoff, environment mismatch, invalid identities,
no-store JSON and exception redaction. No provider SDK is selected here.

## 12. Task 10 — reconciliation event matrix

**Priority:** P0.

Build table-driven tests for all normalized event types, duplicate same/different
payload, missing dependencies, retry, ignored events, event-time ordering,
equal-time terminal precedence and transaction rollback. Provider event,
payment, entitlement, audit and notification identities must stay
deterministic.

## 13. Task 11 — refunds, disputes и chargebacks

**Priority:** P0.

Test cumulative partial refunds, over-refund rejection, failed-after-success,
full refund, dispute open/close and chargeback. Only entitlements linked to
the affected payment may be revoked.

## 14. Task 12 — privacy, notification и account lifecycle

**Priority:** P1.

Verify owner-only payloads, export redaction, recurring deletion blocker,
nullable retained legal history, deterministic database notifications,
account restrictions and no Premium data in public profile/search/cache.

## 15. Task 13 — provider readiness и operations

**Priority:** P1.

Add read-only readiness evidence for registry/config/hosts/currencies/plan
mappings/webhook route/environment without exposing secrets. No dead
reconciliation or provider control appears in UI. Document outage, delayed
webhook, stale event and partial deployment recovery.

## 16. Task 14 — real provider adapter

**Priority:** blocked external decision.

Requires explicit provider choice, business/legal policy, official SDK
dependency approval, sandbox credentials outside Git, exact signature docs,
checkout hosts, event fixtures and refund/cancellation capability matrix.
One provider adapter is one separate change set.

## 17. Task 15 — real plans, currencies и staged activation

**Priority:** blocked by Task 14.

Create plans through an authorized operational/domain workflow, not seeder or
migration production data. Verify real amount/currency/product/price
mappings, all locales, region policy, checkout/webhook/refund canary and
rollback before `is_public`.

## 18. Task 16 — provider-capability self-service

**Priority:** blocked by Tasks 14–15.

Cancellation, resume, refund request, payment method, hosted portal and
invoice/receipt are independent child tasks. Each control requires provider
capability, ownership/recent-auth policy, POST/action mutation, pending/error
state, audit and end-to-end sandbox evidence.

## 19. Task 17 — Premium feature integration protocol

**Priority:** product decision.

Add one stable feature code only after its real server-side policy/source
boundary and free fallback exist. Player quality/source/download, ads,
profile, comments and support remain separate tasks; Premium never bypasses
publication, region or legal restrictions.

## 20. Task 18 — UI, mobile, accessibility и localization

Keep one full-page Livewire tree, existing light portal system and prepared
DTOs. Verify RU/EN parity, long labels, price/currency formatting, keyboard,
focus, touch, zoom, reduced motion, loading/error/empty states and real
checkout return on desktop/tablet/mobile. No marketing claims or disabled
fake controls.

## 21. Task 19 — SEO и public cache policy

Keep `noindex`/sitemap exclusion until a real purchasable plan exists.
After activation verify canonical/hreflang, accurate Offer values and removal
of checkout/customer/user state from structured data. Full HTML caching stays
off for auth-aware/private state unless a separately reviewed guest fragment
architecture proves safe.

## 22. Task 20 — production rollout и recovery

Perform backup assessment, additive migration rehearsal, sandbox/live
isolation, canary purchase/refund/cancellation, webhook retry, provider outage,
stale cache, partial deploy, asset rollback and entitlement discrepancy
reconciliation. No destructive auto-repair; roll-forward is preferred once
financial history exists.

## 23. Безлимитный rolling protocol — Tasks 21+

Tasks 1–20 являются первым измеренным проходом, не потолком. Для каждого
нового discovery:

1. сохранить дату, source/evidence и severity;
2. проверить, не принадлежит ли работа существующему Task;
3. присвоить следующий монотонный ID только новому independent outcome;
4. записать exact files/interfaces, RED, query/payload budget, rollback,
   cross-feature impact и production gate;
5. выбрать только первый `ready` task dependency graph;
6. после исполнения раздельно обновить code, verification, delivery,
   activation и provider/device evidence;
7. не перенумеровывать completed tasks и не объявлять отсутствие внешнего
   prerequisite дефектом приложения.

## 24. Definition of Ready

- конкретное подтверждение, а не пожелание «сделать лучше»;
- business/security/correctness/performance reason;
- exact scope и protected contracts;
- test-first воспроизведение;
- migration/cache/provider/permission/translation impact;
- data-safety, rollback и production authorization;
- один reviewable outcome.

## 25. Definition of Done

- RED наблюдался по ожидаемой причине;
- минимальный GREEN и refactor проверены;
- focused и applicable broad/static/browser gates имеют fresh output;
- query/route/schema/security/privacy/localization/cross-feature evidence
  записано;
- canonical docs, README при реальном visitor/roadmap change и русский
  `CHANGELOG.md` актуальны;
- stale/duplicate Premium implementations просканированы;
- commit/push выполнены на `main` либо честно отмечены `unresolved`;
- production/provider/device state не приписано local verification.

## 26. Master compliance matrix

| Requirement | Status | Evidence / gate |
| --- | --- | --- |
| Canonical owner | `already_compliant` | `docs/premium.md`; второй domain contract не создаётся |
| Historical compatibility | `already_compliant` | Task 22/commits `8b9df18`, `eb4e7f9` сохранены |
| Unlimited intake | `completed` | Tasks 21+ monotonic, no maximum ID |
| No fake commerce | `completed` | Provider/currency/plan remain empty until explicit real onboarding |
| Query optimization | `completed_first_pass` | Tasks 1–5 measured public/access/account/admin reads |
| Automated Premium coverage | `in_progress` | Tasks 1–6 expanded suite to 48 Premium tests; Tasks 7–12 continue it |
| Real provider | `unresolved` | Requires explicit provider/business/legal input |
| Real device/payment | `unresolved` | Cannot be claimed from local/browser emulation |
| Git delivery | `unresolved` | Shared dirty `main` must be reconciled without absorbing foreign work |
