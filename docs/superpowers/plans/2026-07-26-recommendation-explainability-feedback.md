# План реализации объяснимости и feedback рекомендаций

Дата: 26.07.2026.

Design:
[`2026-07-26-recommendation-explainability-feedback-design.md`](../specs/2026-07-26-recommendation-explainability-feedback-design.md).

Правило выполнения: каждый production change начинается только после
соответствующего RED. Статусы живого выполнения ведутся в
[`docs/plans/current-task-plan.md`](../../plans/current-task-plan.md).

## Priority: critical — подготовка и contracts

1. `[completed]` Прочитать root `AGENTS.md`, requirements index и все
   применимые owners.
   - Почему: permanent requirements сильнее локальных предположений.
   - Файлы: `AGENTS.md`, `docs/requirements/*`, recommendation specs.
   - Риск: пропустить protected public/privacy contract.
   - Проверка: compliance matrix со ссылками на owners.
2. `[completed]` Проверить реальные PHP/Laravel/package/database/frontend
   версии через runtime, lock files и Boost.
   - Почему: Laravel/Livewire behavior зависит от версии.
   - Модули: Composer/npm/Boost/SQLite/Blade/Livewire/Tailwind.
   - Риск: устаревший API или несовместимый UI directive.
   - Проверка: exact version inventory и official docs evidence.
3. `[completed]` Инвентаризировать routes, Livewire, services, models,
   migrations, policy, resources, jobs/commands, tests, CI и документацию.
   - Почему: расширение должно встроиться в existing boundaries.
   - Риск: второй feedback store или обход canonical recommender.
   - Проверка: expected/protected file manifest.
4. `[completed]` Сравнить copy-only, current-state positive feedback и event
   analytics.
   - Почему: выбрать минимальную достаточную data/privacy boundary.
   - Риск: недореализация либо избыточная high-volume схема.
   - Проверка: approved design с trade-offs и rollback.
5. `[completed]` Сначала обновить канонический permanent personalization
   owner.
   - Почему: новое stable feedback value меняет постоянный contract.
   - Файл: personalization/exploration design.
   - Риск: код и источник истины расходятся.
   - Проверка: repo search по старому two-value statement.

## Priority: critical — RED tests

6. `[completed]` Добавить enum semantics unit test.
   - Проверяет: `more_like_this` есть в `values()`, но отсутствует в
     `negativeValues()` и `isNegative()` возвращает false.
   - Файлы: новый `tests/Unit/CatalogRecommendationFeedbackTest.php`.
   - Dependency: новый enum API пока отсутствует.
   - Ожидаемый RED: undefined case/method.
7. `[completed]` Добавить profile-builder test положительного evidence.
   - Проверяет: source signal, weight/recency, evidence/reason и отсутствие
     negative classification.
   - Файл: `CatalogPersonalPreferenceProfileBuilderTest`.
   - Риск: test повторит implementation.
   - Проверка: assert observable DTO contract, не internal query text.
8. `[completed]` Добавить negative-builder regression.
   - Проверяет: три `more_like_this` с общим genre не дают demotion, три
     отрицательных по-прежнему дают.
   - Файл: `CatalogPersonalNegativePreferenceBuilderTest`.
   - Ожидаемый RED: positive states ошибочно дают demotion.
9. `[completed]` Добавить personalized query test.
   - Проверяет: marked title не возвращается, его similar candidate
     возвращается с `UserFeedback` и
     `because_positive_feedback`.
   - Файл: `CatalogPersonalizedRecommendationQueryTest`.
   - Dependencies: active `v6` build and watchable candidate fixtures.
10. `[completed]` Добавить hidden-library/count regression.
    - Проверяет: positive feedback не показывается как hidden, exact
      negative rows и pagination остаются.
    - Файл: подходящий existing user-library feature test.
11. `[completed]` Добавить Livewire action tests для discovery и title detail.
    - Проверяет: authorized save, guest redirect, invalid enum, invisible
      title, success message, undo.
    - Файлы: `CatalogDiscoveryInteractionTest` и title Livewire test.
    - Риск: brittle ranking fixture.
    - Проверка: состояние БД и component errors/notices.
12. `[completed]` Добавить render/accessibility test.
    - Проверяет: «Почему это показано», три actions, effect descriptions,
      exact loading targets, один reusable component contract.
    - Файл: `CatalogRecommendationListTest` или focused view test.
13. `[completed]` Запустить только новые tests и сохранить полный RED.
    - Команда: `php artisan test --filter=...`.
    - Запрещено: менять production code до наблюдаемого expected failure.

## Priority: critical — minimal GREEN backend

14. `[completed]` Расширить `CatalogRecommendationFeedback`.
    - Добавить `MoreLikeThis`, `negativeCases()`, `negativeValues()`,
      `isNegative()`.
    - Риск: забытые `whereNotNull`.
    - Проверка: enum unit test + repository-wide semantic scan.
15. `[completed]` Добавить evidence/reason/source enums.
    - Файлы: `CatalogPersonalEvidence`,
      `CatalogRecommendationReason`, `CatalogRecommendationSource`.
    - Риск: публичная локализация/serialization.
    - Проверка: presenter/scorer tests и translation parity.
16. `[completed]` Добавить v2 evidence aggregation.
    - Файл: `CatalogPersonalPreferenceProfileBuilder`.
    - Логика: explicit positive before other state evidence, bounded weight,
      semantic timestamp, only exact negative short-circuit.
    - Риск: positive row пропускается как negative.
    - Проверка: profile test/query budget.
17. `[completed]` Добавить legacy positive source.
    - Файл: `CatalogPersonalizedRecommendationQuery`.
    - Логика: bounded query, source/reason; negative filter использует exact
      negative values.
    - Риск: duplicate source query/N+1.
    - Проверка: query test and query log.
18. `[completed]` Исправить negative preference builder.
    - Файл: `CatalogPersonalNegativePreferenceBuilder`.
    - Логика: `whereIn(negativeValues())`; activity берётся только от
      negative feedback или dropped.
    - Риск: OR grouping.
    - Проверка: both feedback-only/status-only schema branches и tests.
19. `[completed]` Обновить scorer mapping.
    - Файл: `CatalogPersonalizedCandidateScorer`.
    - Проверка: source/reason observable result.
20. `[completed]` Сохранить exact exclusion обработанного source title.
    - Файл: существующий exclusion service менять только при необходимости.
    - Почему: рекомендация не должна сразу повторять отмеченный title.
    - Проверка: candidate ID assertions.

## Priority: high — cross-feature data semantics

21. `[completed]` Ограничить hidden-library list/count двумя отрицательными
    значениями.
    - Файл: `UserLibraryQuery`.
    - Риск: positive intent ошибочно выглядит как скрытие.
    - Проверка: list/count/pagination test.
22. `[completed]` Обновить duplicate-title merge precedence.
    - Файл: `CatalogTitleUserDataMerger`.
    - Риск: nondeterministic collision.
    - Проверка: merge test with equal timestamps if adjacent suite exposes
      helper; otherwise focused new case.
23. `[completed]` Проверить release calendar/notification queries.
    - Ожидаемое изменение: none либо helper-only refactor, потому что они уже
      используют exact two negatives.
    - Проверка: repo search + adjacent release tests.
24. `[completed]` Обновить owner-only OpenAPI enum.
    - Файл: `resources/api/openapi.json`.
    - Риск: mobile client rejects undocumented value.
    - Проверка: OpenAPI/API test; response shape unchanged.
25. `[completed]` Проверить account export and deletion.
    - Ожидаемое изменение: export работает через enum cast, deletion cascade
      unchanged.
    - Проверка: focused export test or static contract inspection.
26. `[completed]` Reversible migration заменяет feedback index тем же
    именем.
    - Почему: production `EXPLAIN` показал temp B-tree на semantic activity
      order, а maximum per-user feedback corpus равен 1 156 rows.
    - Колонки:
      `(user_id, recommendation_feedback,
      recommendation_feedback_updated_at, id, catalog_title_id)`.
    - Риск: bounded index rebuild во время deploy.
    - Rollback: `down()` восстанавливает прежний three-column index; row/data
      backfill отсутствует.
    - Проверка: migration up/down/up, schema column assertion и повторный
      `EXPLAIN`.

## Priority: high — frontend/UX

27. `[completed]` Добавить visible explanation heading в recommendation card.
    - Файл: title-card recommendation Blade.
    - Риск: visual density/mobile wrapping.
    - Проверка: render test + browser screenshots at mobile/desktop.
28. `[completed]` Создать query-free feedback Blade component.
    - Props: positive integer `titleId`, allow-listed action value.
    - Файл: `resources/views/components/catalog/recommendation-feedback.blade.php`.
    - Риск: dynamic Livewire expression injection.
    - Решение: component validates action against two internal literal names.
    - Проверка: rendered exact wire targets.
29. `[completed]` Заменить три duplicate markup blocks.
    - Файлы: discovery and title-detail views.
    - Риск: foreign staged discovery work.
    - Решение: minimal hunk, preserve collection/navigation diff.
    - Проверка: scoped diff and both Livewire render tests.
30. `[completed]` Добавить RU/EN labels, descriptions, saved notice and reason.
    - Файлы: `lang/ru/recommendations.php`,
      `lang/en/recommendations.php`.
    - Риск: missing parity/hardcoded string.
    - Проверка: translation parity test/repo scan.
31. `[completed]` Scope loading to exact title/action call.
    - Почему: one card click must not disable every feedback control.
    - Проверка: exact `wire:target` render assertions and Livewire 4 docs.
32. `[completed]` Проверить 44px targets, keyboard, labels, live notices,
    loading/error/disabled/empty states.
    - Проверка: Playwright accessibility snapshot and browser logs.

## Priority: high — validation/security/errors

33. `[completed]` Сохранить scalar normalization/`tryFrom` in both Livewire
    actions.
    - Cases: empty, zero, negative, array/object, unknown enum.
    - Проверка: parameterized component tests.
34. `[completed]` Проверить guest, unverified, invisible and foreign audience
    access.
    - Boundary: canonical visibility + `CatalogTitlePolicy::interact`.
    - Проверка: no DB write and expected redirect/error.
35. `[completed]` Проверить CSRF/XSS/IDOR/mass assignment/cache leakage/rate
    limit.
    - Ожидаемое изменение: reuse existing service.
    - Проверка: focused policy/rate-limit/privacy tests and escaped render.
36. `[completed]` Сохранить distinct rate-limit/schema/validation/general error
    messages.
    - Запрещено: empty catch/custom PII logs.
    - Проверка: existing and new Livewire error tests.

## Priority: medium — SQL/performance

37. `[completed]` Зафиксировать production SQLite schema/index inventory.
    - Команда: Boost read-only schema/query or SQLite PRAGMA.
    - Проверка: exact feedback index columns.
38. `[completed]` Выполнить `EXPLAIN QUERY PLAN` для negative/profile/hidden
    predicates.
    - Риск: function/cast prevents index.
    - Проверка: query plan uses user/feedback index where applicable.
39. `[completed]` Измерить query counts small/large profiles.
    - Acceptance: constant bounded count; no per-title query.
40. `[completed]` Проверить result bounds, eager loading and serialization.
    - Ожидаемое изменение: no pagination/ranking query expansion.
    - Проверка: existing performance/budget tests.
41. `[completed]` Не добавлять cache.
    - Почему: private current-state mutation уже correctly invalidated,
      query itself bounded/indexed.

## Priority: high — verification and review

42. `[completed]` После каждого GREEN запустить focused tests.
43. `[completed]` Запустить `./vendor/bin/pint --dirty --format agent`.
44. `[completed]` Запустить recommendation, user-library, release-calendar,
    API/export/privacy adjacent suites.
45. `[completed]` Запустить полный `php artisan test`.
    - Результат: 1808 tests, 1795 passed, 11 skipped; один unrelated
      flash-session failure и один отсутствующий класс незавершённого
      importer scope остаются `unresolved`. Отдельный memory-heavy GD test
      прошёл с 1 GiB.
46. `[completed]` Запустить configured Larastan/PHPStan and Rector dry-run через
    project scripts, не добавляя packages.
    - PHPStan и task-scoped Rector прошли; full Rector предлагает только три
      unrelated `never` return types в collection classification.
47. `[completed]` Запустить docs/OpenAPI/translation checks.
48. `[completed]` Запустить `npm run build`.
49. `[completed]` Выполнить browser QA RU/EN, desktop/mobile, guest/auth,
    positive/negative/undo and console.
50. `[completed]` Проверить backend/browser logs без вывода private data.
51. `[completed]` Провести архитектурный review:
    duplicate validation/markup, dead imports/code, Blade queries/business
    logic, coupling, static misuse, long methods, missing types.
52. `[completed]` Провести repository-wide legacy scan:
    `whereNotNull(recommendation_feedback)`, stale two-value docs/OpenAPI,
    duplicate feedback controls, hardcoded UI strings, TODO/debug artifacts.
53. `[completed]` Перечитать канонические requirements/spec/current plan и
    обновить compliance statuses только по фактическим evidence.

## Priority: high — documentation/delivery

54. `[completed]` Обновить recommendation thematic docs and data/API docs.
    - Файлы: architecture/recommendation owner, `docs/api.md`, relevant
      UI/testing docs only where behavior changes.
55. `[completed]` Обновить русский `README.md` visitor capability/history.
    - Preserve managed block and last H2 history contract.
56. `[completed]` Добавить отдельный русский dated `CHANGELOG.md` item.
57. `[pending]` Проверить `git status`, branch/remote/upstream, unstaged,
    staged, untracked, diff/stat and secret/debug/mass-format scan.
58. `[pending]` Отделить task scope от pre-existing Task 57 staged work.
    - Запрещено: reset/stash/unstage/overwrite foreign changes.
    - Если foreign index остаётся, exact blocker записывается; чужой scope не
      включается ради чистого дерева.
59. `[pending]` Stage only task hunks/files, inspect `git diff --cached`.
60. `[pending]` Commit in existing `main`:
    `feat: improve recommendation explanations and feedback`.
61. `[pending]` Перед push повторить clean-tree/secret/branch/upstream checks.
62. `[pending]` Push current `main` without force.
63. `[pending]` Записать exact branch, commit hash, command/output or exact
    external/shared-tree blocker.

## Expected changed files

- enums:
  `CatalogRecommendationFeedback`, `CatalogPersonalEvidence`,
  `CatalogRecommendationReason`, `CatalogRecommendationSource`;
- services:
  `CatalogPersonalPreferenceProfileBuilder`,
  `CatalogPersonalNegativePreferenceBuilder`,
  `CatalogPersonalizedRecommendationQuery`,
  `CatalogPersonalizedCandidateScorer`,
  `CatalogRecommendationExclusionService`, `UserLibraryQuery`,
  `CatalogTitleUserDataMerger`;
- UI:
  recommendation card, new feedback component, discovery/title-detail views,
  RU/EN translations;
- contracts: `resources/api/openapi.json`;
- database: new reversible feedback activity index migration;
- tests: feedback enum, profile, negative builder, personalized query,
  library, Livewire/render/API/merge regressions;
- docs: canonical spec, task design/plan/current matrix, recommendation/API
  owners, README, CHANGELOG.

## Protected compatible files/contracts

- `routes/web.php`, `routes/api.php`, route names and binding;
- `CatalogRecommendationService` public methods/result DTO shapes;
- discovery type/filter/query-string/pagination codes;
- existing two negative feedback values and undo/version semantics;
- `CatalogTitlePolicy::interact`, verified-email and visibility boundaries;
- API field names and public recommendation response;
- cache domains/keys/TTL/shared-private split;
- import/media/player/premium/region/legal/admin/queue/schedule behavior;
- existing migrations and production data;
- current `main` history and foreign staged Task 57 scope.
