# Premium Public Plan Bounds Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Project instructions require inline execution in the existing `main` for this user-approved run: no branch, worktree or sub-agent is created.

**Goal:** Ограничить и ускорить чтение публичных Premium-тарифов, отбрасывать некорректные коммерческие записи до показа и сохранить сумму/валюту исключительно как неизменяемый серверный snapshot.

**Architecture:** Существующий `PremiumPlanQuery` остаётся единственной read boundary. Один Eloquent builder применяет portable SQL-предикаты, явную проекцию, стабильный порядок и конечное candidate window; registry/locale/JSON/capability проверки остаются server-side после hydration, а итоговая выдача ограничивается двенадцатью валидными тарифами.

**Tech Stack:** PHP `8.5.8`, Laravel `13.22.0`, Laravel Boost `2.4.13`, SQLite, PHPUnit `12.5.31`, Pint `1.29.3`, Larastan `3.10.0`.

## Global Constraints

- Работать только в существующей `main`; не stage/reset/stash/delete чужие изменения общего checkout.
- Не добавлять provider SDK, dependency, fake provider, production plan, currency, price или payment row.
- Не редактировать migration `2026_07_18_070229_create_premium_domain_tables.php`.
- `PremiumPlanQuery`, `PremiumPaymentGatewayRegistry`, `PremiumFeatureRegistry` и `Money` остаются каноническими существующими boundaries.
- Browser передаёт только plan code; `amount_minor`, `currency`, provider mapping и entitlement codes повторно выбираются сервером.
- Locale влияет только на перевод и форматирование; он не выбирает цену или валюту.
- Public-plan cache не добавляется; provider API на render не вызывается.
- Возвращается не более `12` валидных публичных тарифов; hydration ограничен candidate window `48`.
- Стабильный порядок — `display_order ASC, id ASC`.
- TDD выполняется RED → GREEN → refactor; затем запускаются focused, related, static, docs и read-only query-plan gates.

---

## File map

- Modify: `app/Services/Premium/PremiumPlanQuery.php` — единый bounded SQL/read/presentation contract.
- Create: `tests/Feature/Premium/PremiumPlanQueryTest.php` — validity, projection, bounds, ordering и snapshot-price matrix.
- Create only if existing behavior fails: `tests/Unit/MoneyTest.php` — integer minor-unit equality/locale-format contract; production `Money` меняется только при воспроизведённом дефекте.
- Inspect, preserve unless a failing test proves a gap: `app/Models/PremiumPlan.php`, `app/ValueObjects/Money.php`, `app/Services/Premium/PremiumPaymentGatewayRegistry.php`, `app/Services/Premium/PremiumFeatureRegistry.php`.
- Modify: `docs/premium.md`, `docs/performance.md` — canonical limit, projection, SQL/candidate gate and measured evidence.
- Modify: `docs/superpowers/plans/2026-07-24-premium-improvement-master-plan.md`, `docs/plans/current-task-plan.md` — rolling status, scope, compliance and delivery state.
- Modify: `docs/README.md` only to link this detailed plan from the existing Premium documentation row.
- Modify: `README.md`, `CHANGELOG.md` — factual visitor/technical history after real implementation.

## Protected contracts

- Route names and URLs: `premium.index`, `localized.premium.index`, private return, webhook, settings and administration routes.
- Public DTO shape: `PremiumPlanData`.
- Checkout contract: `CreatePremiumCheckout::handle(User, string, string, string)` and server-owned immutable amount/currency copy.
- Persisted plan/payment/checkout identities, enum values, indexes and all 12 Premium tables.
- Empty provider/currency no-SQL fast path from Task 1.
- Entitlement resolver query/memo contract from Task 2.
- RU/EN translations, no-provider `noindex`, free catalog/player/download behavior and no shared private cache.

### Task 1: RED — зафиксировать bounded public-plan contract

**Files:**
- Create: `tests/Feature/Premium/PremiumPlanQueryTest.php`

**Interfaces:**
- Consumes: `PremiumPlanQuery::publicPlans(string): list<PremiumPlanData>`, `PremiumPlanQuery::purchasable(string): ?PremiumPlan`.
- Produces: regression contract for SQL projection, maximum `12`, candidate window `48`, stable order and server-owned price.

- [x] **Step 1: Add a valid gateway fixture and plan/translation builders**

The test class uses `RefreshDatabase`, an in-memory `PremiumPaymentGateway` test double, test-only `Lang::addLines()` for `ru|en`, and direct `PremiumPlan::query()->create()` fixtures. Every provider product/price identity is unique; no production configuration or seed data is written.

- [x] **Step 2: Write failing maximum/projection test**

Create thirteen valid rows, request `publicPlans('ru')`, assert exactly twelve results in `(display_order,id)` order, and inspect the single `premium_plans` SQL statement:

```php
self::assertCount(12, $result);
self::assertSame($expectedCodes, array_column($result, 'code'));
self::assertStringNotContainsString('select *', mb_strtolower($planSql));
self::assertStringContainsString('limit 48', mb_strtolower($planSql));
```

- [x] **Step 3: Write failing invalid-row matrix**

Insert active/public rows with invalid code, type, duration/interval combination, non-positive amount, non-allowlisted currency, missing/malformed provider product or price identity, unsupported/duplicate entitlement, invalid/mismatched region, missing RU/EN editorial text, and a gateway without the plan-type capability. Assert only the fully valid row is returned and invalid enum storage never reaches enum hydration.

- [x] **Step 4: Write immutable snapshot and locale test**

Read the same row through `ru` and `en`, assert code/type/duration/features are identical, only localized editorial/price presentation changes, then call `purchasable(code)` and assert exact persisted `amount_minor` and `currency`. Update the DB amount after the first read and assert the old DTO is unchanged while a new read reflects the new authoritative snapshot; no float conversion is used.

- [x] **Step 5: Run RED**

Run:

```bash
php artisan test --filter=PremiumPlanQueryTest
```

Expected: at least the maximum/projection assertions fail because current `publicPlans()` hydrates an unbounded `SELECT *` result without `LIMIT`.

### Task 2: GREEN — implement one bounded query boundary

**Files:**
- Modify: `app/Services/Premium/PremiumPlanQuery.php`
- Test: `tests/Feature/Premium/PremiumPlanQueryTest.php`

**Interfaces:**
- Produces unchanged public signatures:
  - `publicPlans(string $locale): array`
  - `purchasable(string $code): ?PremiumPlan`

- [x] **Step 1: Centralize explicit selected columns**

Add a private exact list containing:

```php
[
    'id', 'code', 'type', 'duration_days', 'billing_interval',
    'amount_minor', 'currency', 'entitlement_codes', 'provider_code',
    'provider_product_id', 'provider_price_id', 'region_codes',
    'display_order',
]
```

- [x] **Step 2: Build portable SQL-safe candidate query**

The private `publicCandidateQuery()` starts with `PremiumPlan::query()->select(...)`, reuses `purchasable()`, then applies:

```php
->where('amount_minor', '>', 0)
->whereIn('currency', $this->supportedCurrencies())
->whereIn('provider_code', $this->gateways->codes())
->whereNotNull('provider_product_id')
->whereNotNull('provider_price_id')
->where(function (Builder $query): void {
    $query
        ->where(function (Builder $query): void {
            $query->where('type', PremiumPlanType::OneTimeDuration->value)
                ->whereBetween('duration_days', [1, 3650])
                ->whereNull('billing_interval');
        })
        ->orWhere(function (Builder $query): void {
            $query->where('type', PremiumPlanType::RecurringSubscription->value)
                ->whereNull('duration_days')
                ->whereIn('billing_interval', ['month', 'quarter', 'year']);
        })
        ->orWhere(function (Builder $query): void {
            $query->where('type', PremiumPlanType::Lifetime->value)
                ->whereNull('duration_days')
                ->whereNull('billing_interval');
        });
});
```

The builder uses bound parameters only and no driver-specific JSON/regex SQL.

- [x] **Step 3: Apply finite windows and stable order**

`publicPlans()` validates locale before schema access, orders by `display_order` then `id`, applies `limit(48)`, performs existing server-side feature/editorial/region/gateway checks, then `take(12)`. `purchasable()` reuses the same candidate builder with validated code and `first()`.

- [x] **Step 4: Harden post-hydration commercial validation**

Require the stable plan-code format, valid/unique supported entitlement list, exact positive integer amount and allowlisted uppercase currency, type-consistent duration/interval, and valid `provider_product_id` plus `provider_price_id` identities. Preserve the existing region and gateway capability checks.

- [x] **Step 5: Run focused GREEN**

Run:

```bash
php artisan test --filter=PremiumPlanQueryTest
php artisan test --filter=PremiumQueryBudgetTest
php artisan test --filter=PremiumPricingPageTest
```

Expected: all tests pass; configured commerce still uses two framework schema statements plus one bounded plan query, and no-provider/invalid-code paths remain zero Premium SQL.

### Task 3: Refactor, query-plan evidence and regression matrix

**Files:**
- Modify only if required by observed tests: `app/Services/Premium/PremiumPlanQuery.php`
- Preserve: `app/Models/PremiumPlan.php`, `app/ValueObjects/Money.php`

- [x] **Step 1: Run code style**

```bash
./vendor/bin/pint --dirty --format agent
```

Expected: success.

- [x] **Step 2: Run Premium and related account/admin tests**

```bash
php artisan test tests/Feature/Premium
php artisan test --filter=Premium
php artisan test --filter=Administration
```

Expected: task-owned tests pass; unrelated framework failure, if still present in the full suite, remains reported separately.

- [x] **Step 3: Inspect SQLite plan read-only**

Run `EXPLAIN QUERY PLAN` for the compiled bounded query and verify use of `premium_plans_public_order_idx`, no full hydration, one plan statement and no additional index requirement. Production `premium_plans` is read-only inspected; no DML is executed.

- [x] **Step 4: Run static and broad checks**

```bash
composer analyse
composer rector:check
php artisan test
php artisan project:docs-refresh --check
```

Expected: scoped code/static/docs checks pass. The known external Laravel `13.22.0` browser-session failure is not hidden or patched in this Premium task if it remains reproducible before project code.

### Task 4: Documentation, compliance and delivery

**Files:**
- Modify: `docs/premium.md`
- Modify: `docs/performance.md`
- Modify: `docs/README.md`
- Modify: `docs/superpowers/plans/2026-07-24-premium-improvement-master-plan.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

- [x] **Step 1: Record exact verified result**

Document max public output `12`, candidate window `48`, selected columns, SQL-safe filters, stable order, immutable integer DB amount/currency, no plan cache, query count and actual SQLite plan. Do not claim production latency or provider readiness.

- [x] **Step 2: Update visitor and technical history**

Add a concise Russian README entry only for the real visitor-visible correctness/performance result and a separate dated Russian `CHANGELOG.md` item. Preserve all earlier history and the final README section order.

- [x] **Step 3: Final requirement reread and legacy scan**

Reread the canonical Premium/security/performance/cache/operations requirements, search the repository for duplicate public-plan queries, raw price/currency trust, unbounded `premium_plans` hydration, fake provider plans and plan cache. Mark each matrix item `completed`, `already_compliant`, `not_applicable` or `unresolved`.

- [x] **Step 4: Review task-owned diff and Git state**

```bash
git diff --check -- app/Services/Premium/PremiumPlanQuery.php tests/Feature/Premium/PremiumPlanQueryTest.php docs/premium.md docs/performance.md docs/README.md docs/superpowers/plans/2026-07-24-premium-improvement-master-plan.md docs/superpowers/plans/2026-07-25-premium-public-plan-bounds.md docs/plans/current-task-plan.md README.md CHANGELOG.md
git status --short --branch
```

- [ ] **Step 5: Commit and push only if isolation is safe**

If and only if the existing `main` is clean outside task-owned files:

```bash
git add -- app/Services/Premium/PremiumPlanQuery.php tests/Feature/Premium/PremiumPlanQueryTest.php docs/premium.md docs/performance.md docs/README.md docs/superpowers/plans/2026-07-24-premium-improvement-master-plan.md docs/superpowers/plans/2026-07-25-premium-public-plan-bounds.md docs/plans/current-task-plan.md README.md CHANGELOG.md
git commit -m "perf: ограничить публичные Premium-тарифы"
git push
```

Current discovery: shared `main` already contains many unrelated tracked and untracked changes, including shared documentation. Unless those changes are independently reconciled by their owners, delivery status must remain `unresolved_shared_worktree`; they must not be absorbed, reset, stashed or deleted.

## Rollback

- Revert only the bounded builder/projection/validation changes in `PremiumPlanQuery`.
- Remove only `PremiumPlanQueryTest` added by this task.
- Revert this task’s documentation entries without rewriting prior history.
- No schema/data/cache/provider/environment rollback is required because this task performs no migration, production DML, cache-key change, provider call or dependency update.
