# Реализация рейтинга качества и очистки подборок

Дата: 26.07.2026.

Design:
[`2026-07-26-collection-quality-rating-design.md`](../specs/2026-07-26-collection-quality-rating-design.md).

Статусы: `pending`, `in_progress`, `completed`, `skipped: причина`.

## Этап 1 — анализ

1. `[completed][critical]` Прочитать root/index и применимые canonical
   requirements. Причина: permanent contracts имеют приоритет. Проверка:
   task matrix.
2. `[completed][critical]` Проверить runtime/packages/frontend/DB через
   Boost/CLI. Причина: Laravel 13/SQLite version-specific behavior.
3. `[completed][critical]` Зафиксировать `main`, status, staged/unstaged/
   untracked и remote. Риск: смешать Tasks 96–100. Проверка: exact diff.
4. `[completed][critical]` Трассировать collection schema/model/policy/
   services/queries/Livewire/API/sitemap/recommendations/cache/import/tests.
5. `[completed][critical]` Выполнить read-only census current collection
   counts/sizes/categories/reports/duplicate groups.
6. `[completed][high]` Проверить реальные watchlist/progress/report signals и
   отсутствие likes/follows/collaborators.
7. `[completed][high]` Проверить Laravel 13 docs по aggregate queries,
   transactions/locks, migrations/indexes, validation и schedule.

## Этап 2 — contract и план

8. `[completed][critical]` Обновить canonical collection requirement.
9. `[completed][critical]` Записать design с scoring, privacy, rollout,
   rollback и non-goals.
10. `[completed][critical]` Создать task compliance matrix.
11. `[completed][critical]` Перечислить expected files и protected public
    contracts.
12. `[completed][critical]` Актуализировать current task plan и перечитать
    prepared artifacts до production edit.

## Этап 3 — TDD RED schema/domain

13. `[completed][critical]` RED schema test для additive columns, tables,
    foreign keys, defaults, casts и indexes.
14. `[completed][critical]` RED unit tests Unicode text normalization,
    signatures, exact duplicate canonical choice и fuzzy threshold.
15. `[completed][critical]` RED evaluator tests для `0..100`, every component,
    empty/oversized/template/duplicate/report and boundary score.
16. `[completed][critical]` RED theme matcher tests для genre/country/platform/
    type/text/source/manual/smart and `0|100`.
17. `[completed][high]` RED engagement aggregate tests для saves, reports,
    completions, returns, zero state и privacy-safe output.

## Этап 4 — database и domain implementation

18. `[completed][critical]` Создать additive reversible migration без DML.
19. `[completed][critical]` Добавить quality issue/run/reason/status enums.
20. `[completed][critical]` Добавить models, relations, fillable/casts и typed
    properties.
21. `[completed][critical]` Расширить `CatalogCollectionSchema` rolling
    capability.
22. `[completed][high]` Добавить config thresholds/batch/stale/similarity.
23. `[completed][critical]` Реализовать Unicode normalizer/signature service.
24. `[completed][critical]` Реализовать bounded engagement aggregate query.
25. `[completed][critical]` Реализовать theme matcher на существующих category
    rules.
26. `[completed][critical]` Реализовать evaluator с explainable components.
27. `[completed][critical]` Реализовать assessor: transaction, upsert,
    issue reconciliation, version-safe item update.

## Этап 5 — refresh, cleanup и publication

28. `[completed][critical]` RED command dry-run/write/batch/idempotency/failure
    tests.
29. `[completed][critical]` Реализовать `catalog-collections:quality-refresh`
    с bounded/default/all/dry-run режимами и safe counters.
30. `[completed][high]` Подключить schedule with overlap/server guards.
31. `[completed][critical]` Сохранить exact demo/HDRezka repair как отдельную
    provenance boundary; не расширять destructive criteria.
32. `[completed][critical]` Обновить public scope current-score/minimum/stale/
    legacy rollout semantics.
33. `[completed][critical]` Обновить moderation approval: locked synchronous
    assessment и threshold.
34. `[completed][critical]` Обновить featured/recommendation signals: только
    current score, bounded size и readiness.
35. `[completed][high]` Проверить item/metadata/category/source mutations:
    content version автоматически инвалидирует score/verification.

## Этап 6 — editorial verification и admin

36. `[completed][critical]` RED policy/service tests verify/unverify,
    insufficient/stale/non-editorial denial, idempotency and audit.
37. `[completed][critical]` Добавить audit action и moderation service method.
38. `[completed][critical]` RED quality queue filters/combinations/search/
    pagination/empty/stale tests.
39. `[completed][critical]` Добавить bounded quality query и eager projections.
40. `[completed][high]` Добавить Livewire URL filter/action/loading/error state.
41. `[completed][high]` Добавить score/components/signals/issues/verification
    admin presentation без Blade queries.

## Этап 7 — public UX

42. `[completed][critical]` RED card/detail badges current/stale/dynamic/
    verified tests.
43. `[completed][critical]` Выбирать item match/reason columns в manual query и
    exact smart-rule presentation в smart query.
44. `[completed][high]` Добавить translated match label в list card; не
    ухудшить cards без reason.
45. `[completed][high]` Добавить score, dynamic и verified badges на card/detail.
46. `[completed][critical]` Добавить RU/EN keys с parity/placeholders/plurals.
47. `[completed][high]` Проверить mobile layout, 44px, wrapping, a11y и
    loading/empty/error.

## Этап 8 — SQL, security, compatibility

48. `[completed][critical]` Проверить N+1/query count, selected columns,
    batch memory, aggregates и pagination.
49. `[completed][high]` Выполнить SQLite EXPLAIN для public scope, dirty queue,
    signature lookup, issue queue и batch aggregate.
50. `[completed][critical]` Проверить IDOR/CSRF/mass assignment/XSS/SQLi,
    forged score/verification/issues и privacy aggregates.
51. `[completed][critical]` Проверить public routes/names/API shape/sitemap/
    SEO/profile/title relations/search/recommendations.
52. `[completed][high]` Проверить cache invalidation, warm targets, importer,
    title merge, account export/delete, comments/reports.
53. `[completed][high]` Проверить no likes/follows/collaborator/smart-public
    regression и отсутствие duplicate legacy implementation.
54. `[completed][medium]` Найти dead imports/TODO/debug/commented code.

## Этап 9 — verification

55. `[completed][critical]` Focused schema/evaluator/command/service/query/
    Livewire tests.
56. `[completed][critical]` Related CatalogCollection/API/sitemap/recommendation/
    cache/import suites.
57. `[completed][critical]` Migration fresh/rollback/fresh SQLite checks.
58. `[completed][critical]` Exact task PHP files Pint.
59. `[completed][high]` Scoped PHPStan/Rector dry-run если project commands
    подтверждены.
60. `[completed][critical]` Exact staged-tree full suite с диагностическим
    memory limit: 2 132 tests, 2 113 passed, 11 skipped, 205 522 assertions;
    все Task 101 tests прошли, оставшиеся 7 unrelated failures/1 error
    принадлежат snapshot/search/legacy-tag/auth/import shared boundaries и
    записаны в evidence.
61. `[completed][critical]` `npm run build`.
62. `[completed][high]` Translation/current-plan/static contracts и exact
    staged-tree `project:docs-refresh --check` прошли; managed migration
    inventory создан без чужих working-copy hunks.
63. `[completed][critical]` Playwright desktop/mobile/tablet public/admin
    прошёл 3/3 с `PWA_ENABLED=false`; production-like PWA-on run прошёл все
    Task 101 assertions и обнаружил чужой poster endpoint `404`.

## Этап 10 — docs и delivery

64. `[completed][critical]` Обновить architecture/DATA_RELATIONS/performance/
    caching/security/frontend/administration/development/deployment.
65. `[completed][critical]` Обновить README visitor behavior и отдельную русскую
    CHANGELOG запись.
66. `[completed][critical]` Перечитать requirements/design/plan/task и закрыть
    compliance statuses evidence.
67. `[completed][critical]` Проверить status/diff/stat/staged/unstaged/untracked/
    branch/remote и exact task manifest.
68. `[completed][critical]` Проверить secrets/debug/binary/formatter/foreign
    scope.
69. `[completed][critical]` Staging exact files/hunks через isolated index и
    cached diff review.
70. `[pending][critical]` Создать логический commit(s) только в `main`.
71. `[pending][critical]` Выполнить ordinary configured push; auth/network
    refusal сохранить как `unresolved`.
72. `[pending][critical]` Дать factual final report по каждому требованию.

## Expected implementation files

Новые: migration, quality enums/models/services/command, focused tests,
task design/plan/compliance. Изменяемые: collection model/item/schema/query/
moderation/admin/page/card/config/schedule/translations и canonical docs.
Точный список уточняется после RED; discovery немедленно обновляет этот plan.

## Protected contracts

- foreign Tasks 96–100 staged/unstaged/untracked changes;
- existing `main`, route names, URLs/query keys, API envelope/resources;
- private/unlisted/public, smart owner-only, category/readiness, reports,
  source sync, ordering and exact quarantine;
- catalog/title/media visibility, Premium/region/legal and personal state;
- cache domains/keys, sitemap/SEO/search/recommendation generation;
- likes/follows/collaborators remain unsupported;
- no production DML, delete, backup claim or external provider call.
