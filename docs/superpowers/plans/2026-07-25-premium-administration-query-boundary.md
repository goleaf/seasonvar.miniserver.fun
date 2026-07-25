# Premium Task 5: граница административных read-запросов

**Дата:** 25.07.2026

**Статус:** `implemented_verified_local_delivery_unresolved`

**Ветка:** только существующая `main`

## Цель

Вынести подготовку данных `/admin/premium` из full-page Livewire-компонента в
один `PremiumAdministrationQuery`, сократить объём гидратации и сохранить без
изменений все route, authorization, mutation, privacy и UI contracts.

## Утверждённое решение

- `PremiumAdministrationManager` остаётся единственным full-page Livewire
  owner маршрута и продолжает выполнять все gates, validation, rate limiting
  и mutations.
- Новый `PremiumAdministrationQuery` становится единственной границей чтения
  пользователя, выдач, промоакций и Premium-аудита для этой страницы.
- Query возвращает только подготовленные safe arrays и paginator с
  подготовленными safe arrays; Eloquent models и private billing fields не
  попадают в публичное состояние Livewire или Blade.
- Поиск UUID и email разделяется до SQL. UUID использует
  `users_public_id_unique`. Нормальный email сначала ищется точным
  нормализованным значением через `users_email_unique`; только при отсутствии
  результата выполняется совместимый fallback `lower(email)`.
- Production-like snapshot содержит `102` users и `0` ненормализованных email.
  `EXPLAIN QUERY PLAN` подтвердил index search для точного UUID/email и full
  scan для `lower(email)`. При таком объёме additive expression index или
  новая identity column не обоснованы.
- Выдачи ограничены `30`, промоакции — `20`, аудит — full paginator по `20`
  с прежним page name `premiumAuditPage`.
- Все builders получают explicit projection и стабильный secondary
  `id DESC`; audit eager load выбирает только `id,name`.
- При неготовой Premium schema query fail-closed возвращает пустые prepared
  collections/paginator и не обращается к Premium domain tables.
- Cache, provider HTTP, DDL, DML production-базы, dependencies, environment,
  queues, workers и frontend assets не меняются.

## Ожидаемые изменяемые файлы

- Create: `app/Services/Premium/PremiumAdministrationQuery.php`.
- Create: `tests/Feature/Premium/PremiumAdministrationQueryTest.php`.
- Modify: `app/Livewire/Premium/PremiumAdministrationManager.php`.
- Modify: `docs/premium.md`.
- Modify: `docs/performance.md`.
- Modify: `docs/administration.md`.
- Modify: `docs/README.md`.
- Modify:
  `docs/superpowers/plans/2026-07-24-premium-improvement-master-plan.md`.
- Modify: `docs/plans/current-task-plan.md`.
- Modify: `README.md`.
- Modify: `CHANGELOG.md`.
- Managed refresh может изменить только управляемые `project-docs` блоки.

`resources/views/livewire/premium/administration-manager.blade.php`, migrations,
models, routes, gates, config и translations проверяются, но меняются только
при воспроизведённом RED-дефекте. Визуальная переделка Task 5 не требуется.

## Защищённые contracts

- Route `admin.premium`, URI `/admin/premium` и полный canonical admin
  middleware stack.
- Gates `view-premium-administration`, `manage-premium-grants`,
  `manage-premium-promotions`, `view-premium-billing-audit` и
  `reconcile-premium`.
- Livewire actions `findUser`, `grant`, `revoke`, `createPromotion`,
  `createCoupon`; их validation, rate limit и mutation services.
- Locked `selectedUserPublicId`; никакого доверия client-owned numeric ID,
  entitlement, provider или access state.
- Blade variables `schemaReady`, `selectedUser`, `entitlements`,
  `promotions`, `audits`, `reasonOptions`, `providerCodes`,
  `canManageGrants`, `canManagePromotions`, `canViewAudit`.
- Shape ключей prepared arrays и paginator page name
  `premiumAuditPage`.
- Русские видимые строки, RU/EN translation parity, noindex/private/no-store,
  shared administration layout/navigation/pagination island.
- Empty commerce, external provider registry и отсутствие provider HTTP на
  render.

## Риски и совместимость

- **Legacy email:** точный indexed read не должен потерять старые mixed-case
  строки; второй bound fallback сохраняет прежний контракт.
- **N+1:** audit actor загружается одним projected eager-load query;
  promotion redemption count остаётся aggregate subquery.
- **Ordering:** новый `id DESC` только детерминирует строки с одинаковым
  временем и не меняет основной порядок.
- **Privacy:** query не выбирает entitlement private notes, audit context,
  resource public IDs, target user email, provider IDs или idempotency keys.
- **Authorization:** query не принимает решение о permissions; Livewire
  вычисляет gates и передаёт только server-side capability flags.
- **Schema rollout:** `PremiumSchema::ready()` остаётся fail-closed boundary.
- **Database:** текущие пустые Premium tables не дают material evidence для
  нового time-order index. Migration запрещена в этой задаче.
- **Rollback:** code-only — вернуть прежние read builders в component,
  удалить service/test и documentation delta. Schema/data/cache rollback не
  требуется.

## Cross-feature impact

| Domain | Статус | Решение |
| --- | --- | --- |
| Premium administration | `affected` | Один prepared read boundary |
| Authorization/audit | `compatibility_required` | Gates в Livewire, safe audit shape |
| Authentication/user identity | `affected_read_only` | Indexed normalized lookup + legacy fallback |
| Database/indexes | `affected_read_only` | Projection/order/limits only, без DDL |
| Cache/privacy/security | `affected` | Shared/user cache не добавляется; private fields не выбираются |
| Livewire/UI/mobile/a11y | `compatibility_required` | Existing props, island, controls и shapes сохраняются |
| Locale/translations | `compatibility_required` | Locale только форматирует labels/date |
| Billing/providers | `compatibility_required` | Registry code list only; HTTP отсутствует |
| Routes/API/SEO/sitemap | `unaffected` | Route/noindex/private contracts неизменны |
| Catalog/player/import/region/legal | `unaffected` | Content privileges и public data не меняются |
| Production operations | `code_only` | No migration/cache/service/worker step |

## Task-specific compliance matrix

| Требование | Статус | Evidence / gate |
| --- | --- | --- |
| Root/index/canonical read order | `completed` | Обязательные requirements и Premium/admin owners перечитаны |
| Related Markdown | `completed` | Design, master, Premium, administration, views, performance, data relations прочитаны |
| Installed versions | `completed` | Boost: PHP 8.5, Laravel 13.22.0, Livewire 4.3.3, SQLite |
| Official framework guidance | `completed` | Laravel 13 projection/eager-load/named paginator/query measurement docs |
| Existing implementation | `completed` | Component, Blade, models, schema, gates, routes, services and tests inspected |
| Production data safety | `completed` | Только read-only counts/schema/EXPLAIN; Premium tables empty |
| Expected/protected files | `completed` | Списки и contracts зафиксированы выше |
| TDD RED | `completed` | `3` tests ожидаемо упали: target query class отсутствовал |
| TDD GREEN | `completed` | Query/Livewire matrix: `4/4`, `63` assertions |
| Focused/static/full verification | `completed_with_unrelated_failure` | Premium `33/239`, administration `67/2698`, full PHPStan/Pint/docs policies; full suite имеет один известный auth failure |
| Final reread/legacy scan | `completed` | Requirements перечитаны; read builders остались только в query, mutation lookups — в component |
| README/CHANGELOG | `completed` | Canonical owners, docs map, Russian technical и visitor histories обновлены |
| Commit/push | `unresolved_shared_worktree` | `main` содержит многочисленные чужие tracked/untracked изменения; clean-tree hook запрещает безопасный selective commit |

## TDD-порядок

### Шаг 1. RED: query behavior

Создать `tests/Feature/Premium/PremiumAdministrationQueryTest.php`:

1. UUID и normalized email находят один prepared user; legacy mixed-case
   email остаётся совместимым.
2. Page snapshot возвращает только ожидаемые safe keys, ограничивает
   entitlements/promotions и сохраняет audit paginator/page name/order.
3. Отключённые capability flags не запускают соответствующие domain reads и
   возвращают пустые sections.
4. SQL projections не содержат private notes, audit context,
   idempotency/provider identities или `select *`.

Запустить:

```bash
php artisan test tests/Feature/Premium/PremiumAdministrationQueryTest.php
```

Ожидаемый RED: класс `PremiumAdministrationQuery` отсутствует.

Фактический RED: `3` tests, `0` passed, `0` assertions; все три ошибки —
`Target class [App\Services\Premium\PremiumAdministrationQuery] does not
exist`.

### Шаг 2. GREEN: query service

Создать `app/Services/Premium/PremiumAdministrationQuery.php`:

- `findUser(string $identity): ?array`;
- `page(string $selectedUserPublicId, bool $canManageGrants,
  bool $canManagePromotions, bool $canViewAudit): array`;
- private prepared mapping и empty paginator helpers;
- explicit selects, limits and stable ordering;
- никаких authorization, mutations, cache или provider calls.

Повторить focused test до GREEN.

Фактический GREEN: `4` tests и `63` assertions, включая query budget `8`
запросов для полностью разрешённой страницы, `2` framework schema-inventory
запроса для полностью запрещённых sections и отсутствие N+1 actor reads.

### Шаг 3. GREEN: Livewire integration

Изменить `PremiumAdministrationManager`:

- `findUser()` получает prepared result из query service;
- `render()` делегирует все четыре read sections query service;
- gates, mutation lookups/services, validation and rate limiter не менять;
- gateway code list and reason options остаются server-side.

Запустить focused service и administration route/security tests.

### Шаг 4. Производительность и regression

- Зафиксировать query log/count для enabled и denied sections.
- Повторить read-only `EXPLAIN QUERY PLAN` UUID, exact normalized email и
  legacy fallback.
- Проверить отсутствие migration/index justification.
- Запустить Premium filter, administration filter, полный PHPStan, Pint и
  полный PHPUnit.
- `npm run build` нужен только если фактически изменён Blade/frontend.

### Шаг 5. Документация и delivery

- Обновить canonical Premium/performance/administration owners, docs map и
  master Task 5 evidence.
- Обновить `README.md` и отдельную русскую запись `CHANGELOG.md`.
- Запустить `php artisan project:docs-refresh` при затронутом managed
  documentation contract и проверить полученный diff.
- Повторно перечитать применимые requirements, выполнить legacy/duplicate
  search и закрыть compliance matrix.
- Проверить `git status --short --branch`; commit/push только в `main` и
  только без поглощения чужих изменений. Внешний/shared blocker отметить
  `unresolved`.

## Итоговая проверка

- Query/Livewire matrix: `4/4`, `63` assertions.
- Premium filter: `33/33`, `239` assertions.
- Administration filter: `67/67`, `2698` assertions.
- Полный `PHPStan` с memory limit `1G` прошёл без ошибок.
- Обязательный `Pint --dirty --format agent` прошёл без изменений чужих
  файлов.
- `project:docs-refresh --check`, docs profile, README policy, русский
  CHANGELOG policy и `git diff --check` прошли.
- Полный PHPUnit после реализации выполнил `1613` tests: `1601` passed, `11`
  skipped и один известный unrelated
  `WebAccountManagementTest::test_logout_other_browser_sessions_preserves_current_session`
  failure; он воспроизведён отдельно `1/1` failure.
- Frontend/Blade/assets в Task 5 не менялись, поэтому новый Vite build не
  требовался.
- Read-only SQLite: `102` users, `0` non-normalized email, по `0` rows в
  entitlement/promotion/redemption/audit tables. UUID и exact email plans
  используют существующие unique indexes; migration не обоснована.
- Current-plan policy продолжает останавливаться на историческом лишнем H1 в
  строке `1759`, до Task 5.
- Clean-tree pre-commit требует отсутствия всех unstaged/untracked файлов.
  Shared `main` содержит многочисленные чужие изменения, поэтому commit/push
  без их поглощения остаются `unresolved_shared_worktree`.
