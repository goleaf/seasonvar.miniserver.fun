# План Premium Task 2 — матрица корректности доступа

Дата: 25.07.2026.
Статус: `completed_local_delivery_unresolved`.

**Цель:** доказать и усилить корректность единственного
`PremiumAccessResolver` для всех уже поддерживаемых entitlement-состояний,
сохранив bounded read, request-scoped memoization и отсутствие общего
пользовательского кеша.

**Архитектура:** существующие `PremiumEntitlement::activeAt()` и
`PremiumAccessResolver` остаются единственной read boundary. Новый
feature-test строит проверяемые Premium rows только в SQLite in-memory,
покрывает жизненный цикл и сочетание источников и измеряет фактические SQL.
Новый индекс допустим только при доказанной необходимости; сначала удаляется
необязательная сортировка, если результат summary от неё не зависит.

## Ограничения

- Работа только в существующей `main`, без branch/worktree.
- Нельзя stage/reset/stash/delete посторонние изменения общего checkout.
- Старая migration не редактируется.
- Production rows, provider configuration, routes, translations, cache keys,
  permissions, dependencies и environment не меняются.
- Browser, subscription status, payment status или кеш не заменяют explicit
  активный entitlement.
- Времена фиксируются в UTC; `starts_at <= now`, `ends_at > now`, lifetime,
  revoke и provider grace проверяются на точных границах.

## Ожидаемые файлы

- Modify: `app/Services/Premium/PremiumAccessResolver.php`
- Modify when justified by test fixtures:
  `app/Models/PremiumEntitlement.php`
- Create when justified:
  `database/factories/PremiumEntitlementFactory.php`
- Create: `tests/Feature/Premium/PremiumAccessResolverTest.php`
- Modify: `docs/premium.md`
- Modify:
  `docs/superpowers/plans/2026-07-24-premium-improvement-master-plan.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`, `CHANGELOG.md`

Фактическое решение: production model и factory не понадобились. Направленные
fixture builders остались внутри feature-test и не расширили mass-assignment
или публичную поверхность `PremiumEntitlement`.

## Защищённые контракты

- `PremiumAccessSummary`, `PremiumFeature` и
  `PremiumEntitlementSource` identities.
- `PremiumEntitlementService`, billing reconciliation, audit и notification
  side effects.
- Manual, promotion, subscription, one-time, lifetime и migration sources
  сосуществуют и не перезаписывают друг друга.
- Full refund/chargeback отзывает только entitlement связанного payment;
  unrelated sources и payments сохраняются.
- Первый resolver read выполняет один bounded entitlement query и не более
  одного projected subscription eager-load query; повторный read того же
  пользователя в scoped instance выполняет ноль SQL.

## TDD-порядок

1. Создать `PremiumAccessResolverTest`.
2. Зафиксировать inactive/future/expired/revoked и точные временные границы.
3. Зафиксировать lifetime и manual/promotion/subscription overlap.
4. Зафиксировать explicit grace и cancellation-at-period-end.
5. Зафиксировать duration extension и payment-scoped revoke.
6. Зафиксировать memoization/`forget()`.
7. Зафиксировать query budget, projection и отсутствие ненужной сортировки.
8. Запустить RED и подтвердить ожидаемую причину.
9. Реализовать минимальный GREEN; индекс не добавлять без material evidence.
10. Выполнить focused Premium, related administration, Pint, PHPStan,
    `EXPLAIN QUERY PLAN`, docs policies и scoped diff checks.

## Результат TDD и решения

- RED: `10` tests, `9` passed, единственный ожидаемый отказ подтвердил
  присутствие ненужного `ORDER BY starts_at`.
- GREEN: удалена только сортировка, от которой summary не зависит; focused
  набор прошёл `10` tests и `59` assertions.
- SQL budget: первый read с subscription выполняет ровно один projected
  entitlement query и один projected eager-load query; memoized read — `0`.
- Read-only production evidence: `0` entitlement rows, `0` subscription rows;
  `EXPLAIN QUERY PLAN` использует существующий
  `premium_entitlements_user_feature_active_idx` без temporary B-tree.
- Migration/index не добавлены: существующий индекс обслуживает lookup, а
  material dataset evidence для нового индекса отсутствует.
- Routes, translations, cache keys, permissions, provider configuration,
  production rows, dependencies, assets и runtime services не менялись.

## Verification evidence

- Focused resolver matrix: `10` tests, `59` assertions.
- Premium и связанная administration matrix: `42` tests, `435` assertions.
- Help Center consumer: `1` test, `2` assertions.
- Scoped Pint/PHPStan, полный PHPStan и scoped Rector: passed, `0` errors.
- Official PHPStan `nullsafe.neverNull` guidance применён через явные
  `assertNotNull`, без suppressions.
- Full PHPUnit выполнил все `1601` tests: `1589` passed, `11` skipped и один
  unrelated failure. Он воспроизводится отдельно в прежнем
  `WebAccountManagementTest` из-за foreign dependency update до
  `laravel/framework 13.22.0`: upstream
  [`ae66e4c`](https://github.com/laravel/framework/commit/ae66e4c9b85e4f17ba9a6332aaa5809079f9f717)
  вызывает
  `Arr::last(null)` из `CookieJar::hasQueued()` до project code. Этот
  dependency/authentication scope не изменялся Task 2 и остаётся
  `unresolved_external_dependency`.
- Managed docs, docs profile, README/CHANGELOG policies и scoped diff check
  прошли; current-plan policy сохраняет прежний лишний H1 в строке `1759`.

## Откат

Удалить новый тест и восстановить прежний resolver query. Schema, данные,
provider state, cache, queue и deployment recovery не требуются.
