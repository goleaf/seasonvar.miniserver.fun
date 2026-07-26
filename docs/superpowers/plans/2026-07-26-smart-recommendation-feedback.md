# План реализации умной обратной связи рекомендаций

Дата: 26.07.2026.

Design:
[`2026-07-26-smart-recommendation-feedback-design.md`](../specs/2026-07-26-smart-recommendation-feedback-design.md).

Правило: production code начинается только после наблюдаемого RED. Каждый
пункт фиксирует действие, причину, scope, зависимости/риски и проверку.
Живые статусы дублируются в
[`docs/plans/current-task-plan.md`](../../plans/current-task-plan.md).

## Critical — requirements, discovery и architecture

1. `[completed]` Прочитать root `AGENTS.md`, canonical index и owners.
   - Почему: permanent security/architecture/data/UI rules имеют precedence.
   - Файлы: `AGENTS.md`, `docs/requirements/*`, owners из `docs/README.md`.
   - Зависимости/риски: пропуск private/cache/mobile contract.
   - Проверка: task compliance matrix с exact evidence.
2. `[completed]` Проверить runtime/package/frontend/database versions.
   - Почему: Laravel/Livewire/migration syntax зависит от installed version.
   - Модули: Composer lock, npm lock, Boost, SQLite runtime.
   - Риск: unsupported API или ложное предположение о production.
   - Проверка: exact CLI + Boost application inventory.
3. `[completed]` Проверить branch/status/upstream и foreign changes.
   - Почему: рабочее дерево содержит незавершённые Tasks 61/62.
   - Модули: Git index, tracked/untracked docs/code/tests.
   - Риск: потерять/закоммитить чужой scope.
   - Проверка: baseline status manifest; alternate exact index delivery.
4. `[completed]` Проследить текущий recommendation flow.
   - Почему: новый store не должен конкурировать с canonical user/title state.
   - Файлы: service/context/profile/scorer/exclusions/diversity/exploration,
     Livewire, Blade, migrations, export/merge/tests.
   - Риск: double demotion, N+1, shared-cache leak.
   - Проверка: source-to-render and mutation-to-lifecycle map.
5. `[completed]` Проверить production-size schema/data read-only.
   - Почему: индексы/bounds должны опираться на фактический corpus.
   - Модули: user states, taxonomy pivots, ratings, episodes.
   - Риск: unbounded reset/event log.
   - Проверка: counts/index inventory and later EXPLAIN.
6. `[completed]` Сравнить inline-column, event-log и normalized current state.
   - Почему: выбрать минимальную privacy-safe architecture.
   - Риск: polymorphic ID либо новая analytics architecture.
   - Проверка: approved design с trade-offs/rollback.
7. `[completed]` Зафиксировать canonical design до PHP.
   - Почему: новая stable reason/preference identity — permanent contract.
   - Файлы: new design + recommendation owner amendment.
   - Проверка: self-review against prompt and canonical owners.
8. `[completed]` Обновить current-task plan/compliance/files/contracts/risks.
   - Почему: обязательный living checklist.
   - Файл: `docs/plans/current-task-plan.md`.
   - Риск: конфликт с foreign Task 62 append.
   - Проверка: append-only scoped diff.

## Critical — exact RED tests

9. `[completed]` RED enums и reason/subject mapping.
   - Почему: codes/mapping/prohibition являются persisted contract.
   - Файлы: new unit test.
   - Зависимость: классы пока отсутствуют.
   - Проверка: undefined enum/class failure.
10. `[completed]` RED schema/model/index test.
    - Почему: additive DDL/FK/unique/expiry indexes должны быть точными.
    - Файлы: new feature schema test.
    - Риск: SQLite FK/index naming.
    - Проверка: missing table failure before migration.
11. `[completed]` RED feedback service success matrix всех 11 reasons.
    - Почему: каждый requested signal должен реально сохраняться.
    - Файлы: new feature service test.
    - Риск: тест повторяет implementation.
    - Проверка: observable canonical state + detail row + subject only.
12. `[completed]` RED invalid/guest/unverified/invisible/foreign subject tests.
    - Почему: CSRF transport не заменяет authorization/validation.
    - Файлы: service/Livewire tests.
    - Проверка: no write and localized error/redirect.
13. `[completed]` RED reason-aware undo and overwrite.
    - Почему: one current detail and stale subject cleanup are invariants.
    - Проверка: exact one row, old columns null, undo deletes detail.
14. `[completed]` RED profile reset cutoff.
    - Почему: reset должен игнорировать старую evidence без удаления history.
    - Файлы: profile builder/preference service tests.
    - Проверка: old signals absent, post-reset activity present, library intact.
15. `[completed]` RED temporary genre lifecycle.
    - Почему: hide must affect query only until expiry.
    - Файлы: visibility/discovery tests.
    - Проверка: active excluded, expired restored, foreign owner unaffected.
16. `[completed]` RED diversity preference ordering.
    - Почему: control must change real server ranking.
    - Файлы: diversity unit/feature test.
    - Проверка: focused/balanced/varied deterministic limits.
17. `[completed]` RED freshness preference ordering.
    - Почему: newer/proven control cannot be fake UI.
    - Файлы: taste reranker test.
    - Проверка: same candidate set, deterministic different order.
18. `[completed]` RED detailed feature effect in legacy and v2 paths.
    - Почему: production rollout flag may select either algorithm.
    - Файлы: personalized query/service tests.
    - Проверка: disliked relation/trait candidate demoted, unaffected candidate
      preserved, no double penalty marker.
19. `[completed]` RED UI render contract.
    - Почему: “Не интересует” must ask a reason and no dead controls.
    - Файлы: component/list tests.
    - Проверка: 11 labels, subject choices, why heading, loading targets.
20. `[completed]` RED Livewire preference actions.
    - Почему: save/hide/restore/reset must be authenticated real mutations.
    - Файл: `CatalogDiscoveryInteractionTest`.
    - Проверка: DB, notices, validation and rerender state.
21. `[completed]` RED export/merge/delete lifecycle.
    - Почему: new private rows must survive title merge and leave on account
      delete; export must include them.
    - Файлы: account export, merger, deletion tests.
    - Проверка: stable codes/subject, deterministic merge, cascade.
22. `[completed]` Запустить exact RED set and record failures.
    - Почему: TDD proof before implementation.
    - Команда: focused `php artisan test --filter=...`.
    - Запрещено: production implementation до expected RED.

## Critical — database and domain GREEN

23. `[completed]` Создать additive migration через Artisan.
    - Что: preferences, feedback details, hidden genres в трёх таблицах.
    - Почему: deployed migrations immutable.
    - Риск: long names/rollback/data loss.
    - Проверка: migrate up/down/up on disposable SQLite.
24. `[completed]` Добавить reason/diversity/freshness enums.
    - Почему: stable codes centralized, translations separate.
    - Файлы: `app/Enums/*`.
    - Проверка: unit mapping tests.
25. `[completed]` Добавить three typed models/relations/casts/fillable.
    - Почему: Eloquent lifecycle/ownership/FK are explicit.
    - Файлы: models and `User`/`CatalogTitle` relations if useful.
    - Проверка: model/schema tests and PHPStan generics.
26. `[completed]` Добавить rolling schema readiness.
    - Почему: code-before-migration must fail closed without partial write.
    - Файл: focused schema service.
    - Проверка: dropped-table test returns unavailable.

## Critical — feedback mutation

27. `[completed]` Реализовать feedback option batch query.
    - Почему: exact title relation choices without N+1.
    - Файлы: query service, list DTO, page builder/discovery mapping.
    - Риск: actor fan-out.
    - Проверка: fixed query count small/large cards and option cap.
28. `[completed]` Реализовать feedback service.
    - Почему: policy, subject membership, canonical state/detail transaction.
    - Файлы: new service + existing state service composition.
    - Риск: nested transaction/rate-limit partial write.
    - Проверка: service matrix and rollback on validation.
29. `[completed]` Реализовать reason-aware undo/overwrite.
    - Почему: stale detail cannot affect later preferences.
    - Проверка: one row and null unused FKs.
30. `[completed]` Интегрировать both Livewire surfaces.
    - Почему: title/discovery behavior must match.
    - Файлы: `CatalogDiscoveryPage`, `CatalogTitleDetail`.
    - Риск: duplicated parsing/error behavior.
    - Проверка: parallel component tests.

## High — preference/profile backend

31. `[completed]` Реализовать preference query/default DTO.
    - Почему: one server-owned read contract for all consumers.
    - Файлы: DTO/query.
    - Риск: repeated owner queries.
    - Проверка: missing-row defaults and query count.
32. `[completed]` Реализовать save/hide/restore/reset service.
    - Почему: owner mutations, expiry, lock/version and reset semantics.
    - Файлы: service + repeat suppressor `forget`.
    - Риск: losing library/history or foreign rows.
    - Проверка: persistence/cascade/reset tests.
33. `[completed]` Передать preferences в server-only context.
    - Почему: all ranking/visibility layers need one consistent snapshot.
    - Файл: context/service.
    - Риск: withType loses preference.
    - Проверка: DTO unit and discovery tests.
34. `[completed]` Применить hidden genres in visibility query.
    - Почему: no 5k materialized exclusion limit.
    - Файлы: visibility service.
    - Риск: OR/filter grouping and explicit title related behavior.
    - Проверка: active/expired/owner tests + EXPLAIN.
35. `[completed]` Применить reset cutoff in v2 profile.
    - Почему: old evidence must not rebuild profile.
    - Файлы: profile/negative builders.
    - Риск: null legacy timestamps after reset.
    - Проверка: cutoff matrix and constant queries.
36. `[completed]` Применить reset cutoff in legacy signals.
    - Почему: feature flag fallback must match.
    - Файл: personalized query.
    - Риск: each signal source uses different semantic timestamp.
    - Проверка: source-specific tests.

## High — ranking quality

37. `[completed]` Расширить candidate feature extraction.
    - Что: country, actor and bounded trait features when needed.
    - Почему: exact reasons must influence actual candidates.
    - Файлы: feature extractor/config.
    - Риск: query expansion/false unfinished inference.
    - Проверка: feature unit tests and constant query count.
38. `[completed]` Расширить negative preference builder detailed semantics.
    - Почему: explicit subject can act from one verified signal, while legacy
      generic negatives keep 3-source threshold.
    - Файл: negative builder.
    - Риск: double demotion/over-broad feature.
    - Проверка: explicit vs legacy thresholds and caps.
39. `[completed]` Реализовать common taste reranker.
    - Почему: legacy, v2 personal and blended public rows need consistent
      detailed/freshness effect.
    - Файлы: new service, recommendation orchestration/scorer marker.
    - Риск: second sort breaks blend/page stability.
    - Проверка: deterministic ordering and pagination regression.
40. `[completed]` Параметризовать diversity service.
    - Почему: more/less diversity must change existing real logic.
    - Файлы: diversity service/config/context.
    - Риск: overly strict list becomes short.
    - Проверка: deferred refill and three preference tests.

## High — frontend/UX

41. `[completed]` Расширить reusable feedback component.
    - Почему: one accessible reason flow on both surfaces.
    - Файлы: class + Blade.
    - Риск: dynamic Livewire injection/nested disclosure.
    - Проверка: allowlisted action/options, escaped labels, exact target test.
42. `[completed]` Добавить personalized preference panel.
    - Почему: requested controls need visible owner UI.
    - Файлы: discovery Blade.
    - Риск: panel on guest/non-personalized pages.
    - Проверка: render state and browser matrix.
43. `[completed]` Добавить RU/EN translations with parity.
    - Почему: locale contract and no hardcoded UI.
    - Файлы: `lang/{ru,en}/recommendations.php`.
    - Проверка: translation parity and raw-key browser scan.
44. `[completed]` Проверить loading/error/empty/disabled/confirmation states.
    - Почему: no double submit/dead action.
    - Проверка: Livewire assertions and Playwright.
45. `[completed]` Проверить responsive/a11y.
    - Почему: long reasons/subjects must work on phone/keyboard.
    - Проверка: desktop/tablet/mobile, axe, focus, overflow.

## High — cross-feature lifecycle

46. `[completed]` Добавить export of private reason/preferences/hides.
    - Почему: data portability.
    - Файл: account export boundary.
    - Риск: internal feature keys/IDs leaking.
    - Проверка: stable codes and safe names only.
47. `[completed]` Reconcile feedback detail on title merge.
    - Почему: duplicate deletion cannot silently drop user intent.
    - Файл: user data merger.
    - Риск: unique collision/wrong winner.
    - Проверка: feedback precedence/timestamp merge case.
48. `[completed]` Проверить account deletion and taxonomy deletion.
    - Почему: privacy and FK behavior.
    - Проверка: cascade/nullOnDelete tests.
49. `[completed]` Проверить hidden library/calendar notifications/API sync.
    - Почему: canonical feedback remains owner; new detail must not alter old
      suppression/public contracts.
    - Файлы: adjacent tests/read-only scan.
    - Проверка: negativeValues behavior/API response unchanged.
50. `[completed]` Проверить cache/search/SEO/import/admin/premium/region/legal.
    - Почему: mandatory cross-feature matrix.
    - Expected: unaffected except private recommendation reads.
    - Проверка: code search and adjacent suites.

## Medium — SQL/performance/security review

51. `[completed]` EXPLAIN preference/detail/hidden genre queries.
    - Почему: each index must match real where/order.
    - Проверка: SQLite plans use PK/unique/composite/pivot indexes.
52. `[completed]` Measure query counts small/large feedback/card profiles.
    - Почему: no per-card/per-reason query.
    - Проверка: constant counts and documented bounds.
53. `[completed]` Inspect payload/Livewire public state.
    - Почему: no Eloquent graphs/private reason maps in snapshot.
    - Проверка: snapshot keys/size regression.
54. `[completed]` Active security scan.
    - Что: SQL injection, XSS, CSRF, mass assignment, IDOR, cache leakage,
      rate limit, logging, secrets.
    - Файлы: touched PHP/Blade/config/migration.
    - Проверка: tests + repository patterns + diff scan.
55. `[completed]` Architecture/code review.
    - Что: controller/component size, duplication, dead code/imports,
      static misuse, Blade query, types/null handling.
    - Проверка: manual diff review, PHPStan/Pint/Rector dry-run.

## High — verification

56. `[completed]` Run exact new GREEN tests after each slice.
57. `[completed]` Run recommendation/profile/library/export/merge/calendar/API
    adjacent suites.
58. `[completed]` Run task-scoped `./vendor/bin/pint --format agent`.
59. `[completed]` Run configured PHPStan/Larastan and task-scoped Rector dry-run.
60. `[completed]` Run migration up/down/up on disposable test database.
61. `[skipped]` Full `php artisan test` cannot finish inside tracked `256M`;
    exact related suite is green, while isolated full-suite blockers are
    cumulative memory plus two foreign collection-readiness assertions.
62. `[completed]` Run `npm run build`.
63. `[completed]` Run Playwright authenticated desktop/tablet/mobile,
    console/HTTP/overflow and visual screenshots; RU UI is the changed surface.
64. `[completed]` Inspect backend/browser logs without private payload.
65. `[completed]` Repository-wide legacy/dead scan:
    direct negative UI, stale reason lists, duplicate preference stores,
    TODO/debug, raw codes, Blade queries, unsafe dynamic actions.
66. `[completed]` Re-read requirements/design/prompt and finalize compliance.

## High — documentation and delivery

67. `[completed]` Update recommendation/data/security/performance/frontend/API
    owners with actual final behavior.
68. `[completed]` Update README visitor capability/history in Russian.
69. `[completed]` Add dated Russian CHANGELOG entry without altering prior text.
70. `[completed]` Update deployment/rollback only if final production contract
    differs from this design.
71. `[completed]` Review full/staged/unstaged/untracked diff/stat, branch,
    upstream, remotes, secrets, debug and mass formatting.
72. `[completed]` Stage only exact task files/hunks in alternate index.
73. `[in_progress]` Inspect `git diff --cached` and commit coherent feature in
    existing `main`.
74. `[pending]` Reconcile original foreign index without reset/stash/unstage.
75. `[pending]` Push current `main` without force; record exact external
    authentication/protection failure if any.
76. `[pending]` Report files, migrations/indexes, query/security fixes,
    tests/commands, skipped/unresolved, branch/hash/message/push/risks.

## Expected changed files

- enums/DTO/models for reason, diversity, freshness, preference/detail/hide;
- one additive migration with three tables;
- feedback/preference/query/reranker/schema services;
- recommendation context/service/visibility/diversity/feature/profile/legacy
  query/scorer/repeat suppression;
- page builder/list DTO/Livewire components/reusable Blade and discovery UI;
- account export and title merge;
- RU/EN recommendations translations and config;
- focused unit/feature/browser tests;
- canonical recommendation/data/security/performance/frontend docs,
  current plan, README and CHANGELOG.

## Protected compatible contracts

- current `main`, foreign Tasks 61/62 index/working tree;
- all web/API routes, route names, bindings and discovery URL state;
- public API JSON/OpenAPI field shape and mobile sync operations;
- existing feedback values, versions, hidden library and notifications;
- watchlist/status/rating/progress/history/collections/personal tags;
- recommendation types/sources/reasons, public explanation DTO and cache keys;
- v6 build/import/queue/shadow activation;
- visibility/entitlement/premium/region/legal rules;
- guest/shared-cache/SEO/service-worker behavior;
- existing migrations and production rows.
