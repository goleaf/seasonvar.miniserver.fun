# Дизайн безлимитной программы улучшения Premium

Дата: 24.07.2026  
Статус: утверждён пользователем; первый конечный инкремент разрешён к реализации.

## Цель

Развивать существующий Premium-домен как непрерывную evidence-driven
программу без искусственного верхнего номера задач, сохраняя каждое отдельное
изменение малым, конечным, проверяемым и обратимым. Программа не создаёт
фиктивные цены, планы, преимущества или платёжного провайдера.

## Проверенный baseline

- Историческая реализация принадлежит Task 22 в
  `docs/plans/laravel-video-portal-modernization.md`.
- Единственный владелец постоянных Premium-контрактов —
  `docs/premium.md`.
- `/premium` возвращает `200`, использует full-page Livewire, имеет
  `noindex, follow`, корректно отображается при `1440×1000` и `390×844`,
  не имеет console/page/network errors и horizontal overflow.
- Три последовательных live-запроса дали TTFB `72–116 ms`; это snapshot
  24.07.2026, а не production SLA.
- `premium.providers`, `premium.supported_currencies` и public plans пусты.
  Все 12 Premium-таблиц в текущей SQLite также пусты.
- Guest pricing request всё равно проходит `PremiumSchema::ready()` и
  `PremiumPlanQuery`, хотя empty gateway/currency registry уже доказывает,
  что purchasable plan невозможен.
- `PremiumSchema::ready()` проверяет 12 таблиц отдельными `hasTable()`.
- Отдельного comprehensive Premium test suite нет; существующие проверки
  покрывают только административную интеграцию и сохранение entitlement при
  account restriction.
- `PremiumAccountQuery` повторно читает entitlement/subscription state, а
  `PremiumAdministrationManager` содержит собственные read queries.
- `EXPLAIN QUERY PLAN` использует
  `premium_entitlements_user_feature_active_idx` для active entitlement, но
  создаёт temporary B-tree для `ORDER BY starts_at`.

## Архитектурное решение

`docs/premium.md` остаётся единственным владельцем доменных требований.
Исторический Task 22 не переписывается и не превращается в бесконечный
working document. Исполнение принадлежит одному
`premium-improvement-master-plan.md`, а текущий конечный change set получает
отдельный подробный TDD-план.

Безлимитность означает:

- номера задач только растут и не переиспользуются;
- новое подтверждение создаёт следующий Task ID;
- completed history не перенумеровывается и не переписывается;
- одновременно исполняется только первый `ready` task dependency graph;
- каждая задача имеет exact scope, RED, rollback, cross-feature matrix,
  production gate и раздельные статусы code, verification, delivery и
  activation;
- отсутствие provider/business/legal input остаётся честным `blocked`, а не
  заменяется демонстрационной интеграцией.

## Первый конечный инкремент

Первый инкремент укрепляет текущий безопасный no-provider режим:

1. `PremiumPlanQuery` проверяет server-owned commerce readiness до schema/DB.
2. Пустой gateway registry или валидный currency allowlist без значений
   возвращает `[]`/`null` без Premium SQL.
3. Некорректный внешний plan code отклоняется до schema/plan queries, в том
   числе при настроенном commerce.
4. `PremiumSchema` получает список таблиц одной поддерживаемой Laravel 13
   schema-inspection операцией и memoize-ит результат на экземпляр/request.
   На SQLite framework выполняет два SQL statements: capability probe и
   `pragma_table_list`.
5. Feature tests закрепляют guest RU/EN empty state, canonical/noindex и
   отсутствие checkout form.
6. Query-budget tests сначала воспроизводят лишние schema queries, затем
   подтверждают нулевой Premium DB path для текущего no-provider режима и
   одну framework schema-inventory operation для readiness.

Shared/public HTML cache не добавляется: страница auth-aware, содержит
Livewire/CSRF state, а текущий TTFB не доказывает необходимость нового
full-response cache. User entitlement, billing history и checkout state
остаются private/no-store и никогда не попадают в shared cache.

## Следующие доменные потоки

После первого инкремента программа последовательно покрывает:

- access resolver и entitlement combination matrix;
- plan validation, bounded public list и immutable pricing;
- settings/account read model и history pagination;
- administration query boundary и normalized identity lookup;
- promotion/coupon concurrency;
- checkout idempotency и pending recovery;
- webhook verification и normalized provider event contract;
- complete reconciliation matrix, refund/dispute/chargeback;
- notifications, audit, privacy export и deletion;
- provider readiness и только затем отдельный approved adapter;
- реальные plans/currencies/mappings;
- provider-capability-gated self-service lifecycle;
- поштучные Premium feature integrations с free fallback;
- mobile/a11y/localization/SEO;
- observability, reconciliation, deployment, backup и rollback.

## Защищённые контракты

- `PremiumAccessResolver` остаётся единственным entitlement resolver.
- `PremiumPaymentGateway`/registry остаются единственной provider boundary.
- Browser return не подтверждает payment; только verified provider event
  может изменить billing/entitlement.
- `premium_access` остаётся единственным feature code, пока реальная функция
  не получит server authorization и free fallback.
- `/premium`, localized alias, return, webhook, settings и admin route
  identities не меняются.
- Integer minor units, ISO currency, UTC periods, idempotency, exact-source
  revoke, refund/chargeback isolation и no-secret logging сохраняются.
- Free catalog/player/download/progress/comments/reviews/recommendations,
  region/legal restrictions и public/API/SEO contracts не меняются.
- Старую Premium migration нельзя редактировать; будущие indexes/columns
  создаются только additive migration после measured `EXPLAIN`.

## Production и rollback

Первый инкремент не меняет schema, data, cache keys, dependencies,
environment, workers или provider state. Rollback возвращает два query
класса и удаляет только новые tests/docs. Следующие provider/schema tasks
обязаны иметь отдельные backup, migration, canary, retry, outage и
roll-forward решения.

## Acceptance

- no-provider guest pricing выполняет ноль Premium-owned database queries;
- schema readiness выполняет одну inventory operation (не больше двух SQL
  statements на SQLite) и не повторяет её в том же instance/request;
- guest RU/EN показывают truthful unavailable state без checkout;
- active/authenticated entitlement path не кэшируется между users;
- focused PHPUnit, Pint, scoped PHPStan, docs checks и browser smoke проходят;
- README меняется только при фактическом visitor/product/roadmap результате;
- commit/push выполняются только на `main` и только без поглощения foreign
  dirty worktree.
