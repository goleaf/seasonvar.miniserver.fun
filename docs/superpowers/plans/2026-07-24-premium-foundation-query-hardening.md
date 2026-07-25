# Premium Foundation Query Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Current project rules require inline execution on existing `main`.

**Goal:** Сделать текущую `/premium` в честном no-provider режиме database-free для guest pricing, отклонять невалидный plan code до schema/plan SQL и заменить 12 отдельных schema probes одной memoized framework inventory operation, закрепив поведение отдельными Premium tests.

**Architecture:** `PremiumPlanQuery` получает fail-fast commerce readiness до schema/DB, а `PremiumSchema` сохраняет прежний полный 12-table contract через один Laravel 13 `Schema::getTableListing(schemaQualified: false)`. UI/routes/DTO/provider contracts не меняются.

**Tech Stack:** PHP `8.5.8`, Laravel `13.21.1`, Livewire `4.3.3`, SQLite in-memory PHPUnit `12.5.31`, Pint `1.29.3`, Larastan `3.10.0`, Playwright `1.61.1`.

## Global Constraints

- No provider, price, currency, plan, route, migration, cache key, package,
  environment or production data change.
- TDD RED must be observed before production code.
- Query tests use the real migrated SQLite schema and `DB::enableQueryLog()`.
- Guest/auth/locale/noindex/canonical/free fallback remain compatible.
- Do not edit or stage unrelated shared-worktree files.

---

### Task 1: RED query contracts

**Files:**
- Create: `tests/Feature/Premium/PremiumQueryBudgetTest.php`

**Interfaces:**
- Consumes: `PremiumSchema::ready(): bool`,
  `PremiumPlanQuery::publicPlans(string): array`,
  `PremiumPlanQuery::purchasable(string): ?PremiumPlan`.
- Produces: executable query budgets for Tasks 2–3.

- [x] **Step 1: Create the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Premium;

use App\Services\Premium\PremiumPlanQuery;
use App\Services\Premium\PremiumSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PremiumQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_provider_plan_lookup_executes_no_database_queries(): void
    {
        config(['premium.providers' => [], 'premium.supported_currencies' => ['USD']]);
        $plans = app(PremiumPlanQuery::class);

        self::assertSame(0, $this->countQueries(function () use ($plans): void {
            self::assertSame([], $plans->publicPlans('ru'));
            self::assertNull($plans->purchasable('monthly'));
        }));
    }

    public function test_schema_readiness_uses_one_memoized_framework_inventory_operation(): void
    {
        $schema = new PremiumSchema;

        self::assertSame(2, $this->countQueries(function () use ($schema): void {
            self::assertTrue($schema->ready());
            self::assertTrue($schema->ready());
        }));
    }

    private function countQueries(callable $operation): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $operation();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
```

- [x] **Step 2: Run RED**

Run:

```bash
php artisan test tests/Feature/Premium/PremiumQueryBudgetTest.php
```

Observed: two assertion failures. Existing plan lookup performed 14
schema/plan queries, and existing readiness performed 12 `hasTable()` queries
instead of the two statements used by one Laravel SQLite inventory operation.

### Task 2: GREEN no-provider fast path

**Files:**
- Modify: `app/Services/Premium/PremiumPlanQuery.php`
- Test: `tests/Feature/Premium/PremiumQueryBudgetTest.php`

**Interfaces:**
- Produces private `commerceConfigured(): bool`.
- Preserves public signatures and return types.

- [x] **Step 1: Implement the minimal fast path**

Add a private readiness method which validates that the registry has at least
one gateway and `premium.supported_currencies` contains at least one unique
`[A-Z]{3}` code. Call it before `PremiumSchema::ready()` in both public
methods:

```php
if (! $this->commerceConfigured() || ! $this->schema->ready()) {
    return [];
}
```

and:

```php
if (preg_match('/\A[a-z0-9][a-z0-9_-]{1,63}\z/', $code) !== 1
    || ! $this->commerceConfigured()
    || ! $this->schema->ready()) {
    return null;
}
```

The external code check runs before schema/plan access. The helper returns
false for an empty gateway registry, empty/invalid currency
allowlist or duplicate-invalid normalized configuration. It does not query
the database and does not inspect secrets.

- [x] **Step 2: Run the focused test**

```bash
php artisan test tests/Feature/Premium/PremiumQueryBudgetTest.php --filter=no_provider
```

Expected: PASS with zero database queries.

### Task 3: GREEN one-operation schema inventory

**Files:**
- Modify: `app/Services/Premium/PremiumSchema.php`
- Test: `tests/Feature/Premium/PremiumQueryBudgetTest.php`

**Interfaces:**
- Preserves `PremiumSchema::ready(): bool`.
- Uses Laravel 13 `Schema::getTableListing(schemaQualified: false)`.
- Allows the two SQL statements Laravel's SQLite inspector uses for its
  capability probe and `pragma_table_list`.

- [x] **Step 1: Replace repeated probes**

Define a private table constant containing the exact existing 12 names.
Resolve them with:

```php
if ($this->ready !== null) {
    return $this->ready;
}

try {
    $tables = Schema::getTableListing(schemaQualified: false);

    return $this->ready = array_diff(self::REQUIRED_TABLES, $tables) === [];
} catch (Throwable) {
    return $this->ready = false;
}
```

The explicit early return ensures that a second call does not execute another
inventory operation. Framework inspection failures preserve fail-closed
readiness.

- [x] **Step 2: Run all query tests**

```bash
php artisan test tests/Feature/Premium/PremiumQueryBudgetTest.php
```

Expected: two tests PASS.

### Task 4: Characterize public pricing

**Files:**
- Create: `tests/Feature/Premium/PremiumPricingPageTest.php`

**Interfaces:**
- Consumes existing route names and translations.
- Produces guest RU/EN empty-state/SEO regression coverage.

- [x] **Step 1: Add the public contract tests**

Cover:

- `/premium` and `/en/premium` return `200`;
- Russian and English empty-state translations render;
- canonical points to the matching route;
- robots are `noindex, follow`;
- checkout form/button/real-plan price are absent;
- help link and free-access fallback remain present;
- invalid `?plan=` input cannot create a checkout control.

- [x] **Step 2: Run the public contract**

```bash
php artisan test tests/Feature/Premium/PremiumPricingPageTest.php
```

Expected: PASS against preserved behavior.

### Task 5: Refactor and verify

**Files:**
- Modify if evidence requires: the two production services and two test files.

- [x] **Step 1: Format**

```bash
./vendor/bin/pint --dirty --format agent
```

- [x] **Step 2: Run focused Premium and related administration tests**

```bash
php artisan test tests/Feature/Premium
php artisan test --filter='Premium|AdminAuthorization|AdminFeatureIntegration|AccountRestriction'
```

- [x] **Step 3: Run static analysis and build-independent checks**

```bash
./vendor/bin/phpstan analyse app/Services/Premium/PremiumPlanQuery.php app/Services/Premium/PremiumSchema.php tests/Feature/Premium --memory-limit=1G
php artisan route:list -v --path=premium
php artisan project:docs-refresh --check
```

- [x] **Step 4: Browser smoke**

Verify RU/EN desktop/mobile status, canonical, robots, no checkout, overflow,
console/page/network failures and save ignored screenshots under
`output/playwright`.

- [x] **Step 5: Documentation and delivery**

Update `docs/premium.md`, documentation map, current-task compliance,
`CHANGELOG.md` and `README.md` only for actual roadmap/performance result.
Review exact diff and stale Premium implementations. Commit/push only when the
shared worktree can satisfy canonical clean-tree hooks without foreign files.

Observed delivery status: documentation was updated from actual GREEN
evidence; commit/push is `unresolved_shared_worktree` because canonical hooks
require a clean tree and foreign scopes cannot be staged, reset, stashed or
absorbed.

## Verification evidence

- Query RED: no-provider `14` SQL, schema readiness `12` SQL, invalid
  configured plan code `2` SQL.
- Query GREEN: no-provider `0` SQL, memoized SQLite framework inventory `2`
  statements, invalid configured plan code `0` SQL; configured empty catalog
  uses the inventory plus one plan query.
- Missing-table readiness additionally stays fail-closed and memoized within
  the scoped instance.
- Premium: `9` tests, `49` assertions.
- Related administration: `31` tests, `373` assertions.
- Scoped PHPStan: `0` errors.
- Rector read-only check: `0` changed files, `0` errors.
- Full PHPUnit: `1581` tests, `1570` passed, `11` skipped, `123983`
  assertions.
- Live RU desktop and EN mobile: `200`, matching canonical,
  `noindex, follow`, no checkout, no horizontal overflow or console errors.
- Six diagnostic HTTPS TTFB samples: `71–116 ms`; this is not an SLA.
- Managed docs, docs profile, README policy and diff checks passed. The
  CHANGELOG policy reached a pre-existing foreign planning entry and rejected
  ordinary `read-only` on line 8; the new Premium entry passed before that
  point and the foreign line was not changed.

## Rollback

Restore the previous two service implementations and remove the two new test
files. No schema, data, cache, queue, provider, dependency, environment or
asset rollback is required.
