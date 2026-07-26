# Реализация умных личных подборок

Дата: 26.07.2026.

Design:
[`2026-07-26-smart-collections-design.md`](../specs/2026-07-26-smart-collections-design.md).

Статусы: `pending`, `in_progress`, `completed`, `skipped: причина`.

## Этап 1 — анализ и границы

1. `[completed][critical]` Прочитать root/index и каждый обязательный
   requirement owner. Причина: permanent contracts имеют приоритет. Файлы:
   `AGENTS.md`, `docs/requirements/*`. Зависимости: none. Риск: пропустить
   protected domain. Проверка: compliance matrix.
2. `[completed][critical]` Проверить PHP/Laravel/Livewire/PHPUnit/Tailwind,
   DB engine и package versions через runtime/Boost. Причина:
   version-specific API. Файлы: `composer.lock`, `package-lock.json`.
   Риск: устаревший приём. Проверка: application info.
3. `[completed][critical]` Зафиксировать branch/status/remotes и foreign
   changes. Причина: не смешать параллельную работу. Файлы: Git metadata.
   Риск: чужой diff в commit. Проверка: exact alternate-index review.
4. `[completed][high]` Трассировать collection model/schema/service/query,
   policies, Livewire, API, sitemap, account lifecycle, recommendations,
   calendar feeds и tests. Причина: compatibility. Проверка: repository
   search и direct file review.
5. `[completed][high]` Проверить user state/progress/release/media/rating/
   taxonomy schema и indexes через Boost. Причина: правила должны строиться
   на canonical data. Риск: неверная semantics/N+1. Проверка: schema evidence.
6. `[completed][high]` Сравнить materialized, hybrid и dynamic варианты.
   Причина: auto-update без stale state. Проверка: approved design.

## Этап 2 — подготовка task contract

7. `[completed][critical]` Записать design с invariants, privacy, query,
   cache, rollout/rollback. Файл: design spec. Проверка: reread.
8. `[completed][critical]` Обновить current task plan, expected files,
   protected contracts и cross-feature matrix. Файл:
   `docs/plans/current-task-plan.md`. Проверка: Task 76 section.
9. `[completed][critical]` Создать compliance matrix. Причина: каждый
   requirement получает evidence. Проверка: Task 76 matrix.
10. `[completed][critical]` Перечитать design и plan перед production edit.
    Проверка: no unresolved design question.

## Этап 3 — TDD RED: schema/domain

11. `[completed][critical]` Добавить schema test для `mode`, `smart_rules`,
    `smart_rules_version`, defaults и отсутствия лишнего index. Файл:
    `tests/Feature/CatalogSmartCollectionSchemaTest.php`. Риск: SQLite
    incompatibility. Проверка: focused RED.
12. `[completed][critical]` Добавить unit test rule normalization: empty,
    zero, boolean, locale-independent decimals, ranges, enum, unknown keys.
    Файл: `tests/Unit/CatalogSmartCollectionRulesTest.php`. Проверка: RED.
13. `[completed][critical]` Добавить service tests для owner-only private
    create/update, optimistic version, presets and reset. Файл:
    `tests/Feature/CatalogSmartCollectionServiceTest.php`. Проверка: RED.
14. `[completed][critical]` Добавить item-service denial test. Причина:
    browser-hidden control is not authorization. Проверка: RED.

## Этап 4 — additive database/domain implementation

15. `[completed][critical]` Создать additive reversible migration через
    Artisan. Файл: new migration. Зависимость: schema RED. Риск:
    production rollback after smart data. Проверка: migrate/rollback test.
16. `[completed][critical]` Добавить `CatalogCollectionMode` и completion enum.
    Причина: stable DB identities, not translations. Проверка: unit tests.
17. `[completed][critical]` Добавить typed `CatalogSmartCollectionRules`.
    Причина: allowlist/normalization/testability. Проверка: unit GREEN.
18. `[completed][high]` Добавить preset enum/value mapping. Причина:
    reusable six templates without persisted localized labels. Проверка:
    exact rule assertions.
19. `[completed][critical]` Обновить model fillable/casts/properties/scopes.
    Причина: manual default and public smart exclusion. Проверка: schema/model
    tests.
20. `[completed][critical]` Расширить create DTO/service с mode/rules invariant
    under owner transaction. Риск: public smart or editorial smart. Проверка:
    service tests.
21. `[completed][critical]` Расширить существующий
    `CatalogCollectionService::update()` атомарной записью metadata/rules с
    policy, lock, expected version и cache invalidation; отдельный
    дублирующий service после review удалён. Проверка: stale/concurrent tests.
22. `[completed][critical]` Запретить add/remove/reorder/batch membership для
    smart mode в item service. Проверка: direct service tests.

## Этап 5 — TDD RED/GREEN query

23. `[completed][critical]` Создать factories/helpers для representative
    catalog/user/media/release fixtures. Причина: readable stable tests.
24. `[completed][critical]` RED для country + genre + IMDb + completed +
    unwatched + subtitles + duration combination. Проверка: only exact title.
25. `[completed][high]` RED по отдельности для year/episode count/library/
    new episodes/actor/watch status age/video availability.
26. `[completed][high]` RED для empty DB, nulls, zero boundaries, impossible
    combinations, unknown version and stale references.
27. `[completed][high]` RED для sorting, pagination, detail secondary filters
    and reset.
28. `[completed][critical]` Реализовать `CatalogSmartCollectionQuery` на
    canonical visible title/media/personal update boundaries. Риск:
    N+1/private leak. Проверка: query tests.
29. `[completed][high]` Встроить smart branch в collection items/filter
    options, сохранив manual implementation unchanged.
30. `[completed][high]` Hydrate card relations/counts/state bounded и
    deterministic. Проверка: query count/eager-load tests.
31. `[completed][high]` Проверить SQLite EXPLAIN для самой сложной комбинации.
    Добавить index только при доказанном missing path. Риск: write overhead.
    Проверка: plan evidence.

## Этап 6 — Livewire/frontend

32. `[completed][critical]` RED dashboard create manual/smart mode, required
    preset and forced private state. Файл:
    `tests/Feature/CatalogSmartCollectionLivewireTest.php`.
33. `[completed][critical]` RED editor rule validation, preset apply, actor
    lookup, save/reset, loading/error and stale version.
34. `[completed][critical]` RED detail owner access, guest/other-user 404,
    dynamic count and absence of remove/reorder/share/report controls.
35. `[completed][high]` Добавить dashboard mode/preset controls с mobile
    layout и no-double-submit loading.
36. `[completed][critical]` Добавить editor rule builder и bounded actor
    autocomplete. Причина: favorite actor without unbounded option load.
37. `[completed][high]` Добавить active-rule summary and reset with Russian
    error messages.
38. `[completed][critical]` Обновить detail to smart query, badges/explanation
    and owner-only controls.
39. `[completed][high]` Обновить collection card view model: smart badge and
    automatic-count label without per-card count query.
40. `[completed][critical]` Добавить RU/EN translations with exact parity.
41. `[completed][high]` Проверить Blade: no query/config/service/`@php`,
    escaped output, labels/errors/a11y/44px.

## Этап 7 — cross-feature integration

42. `[completed][critical]` Поддержать smart title subquery в collection ICS
    feed. Файлы: release calendar feed query/tests. Риск: private token
    leakage or unbounded event query. Проверка: exact owner/result/limit.
43. `[completed][high]` Добавить mode/rules to account export, not current
    materialized results. Проверка: export test.
44. `[completed][critical]` Проверить public directory/profile/API/search/
    sitemap/related/recommendations exclude smart mode. Проверка: regression
    tests and repository search.
45. `[completed][high]` Проверить account deletion/merge, title merge,
    importer, media health and personal update behavior. Решение ожидается
    `already_compliant` because rules use slugs/scalars and no cache.
46. `[completed][high]` Проверить no shared cache/private cache key. Причина:
    dynamic personal results. Проверка: cache assertions/search.

## Этап 8 — security/performance/error review

47. `[completed][critical]` Review IDOR, policy, locked identity, CSRF, mass
    assignment, arbitrary JSON/key/operator, XSS and private API/cache.
48. `[completed][critical]` Review invalid/legacy rules fail-close and
    user-safe errors without exception/raw query disclosure.
49. `[completed][high]` Review SQL queries, selected columns, subqueries,
    counts, N+1, paginator bound and no provider call.
50. `[completed][high]` Inspect actual query count and EXPLAIN. Record why each
    existing/new index is used or why none is added.
51. `[completed][medium]` Search changed/related code for dead imports,
    TODO/debug/duplicate validation/commented legacy.

## Этап 9 — verification

52. `[completed][critical]` Run focused rules/schema/service/query/Livewire/
    calendar/export tests and fix each real failure.
53. `[skipped: shared working tree содержит чужие PHP changes][critical]`
    Не форматировать чужой diff через global `--dirty`; exact task-file
    `./vendor/bin/pint --format agent ...` прошёл.
54. `[completed][high]` Run related collection/library/calendar/API/account
    test matrix.
55. `[completed][critical]` Run full `php artisan test`; record any foreign
    failure exactly and fix every task-related failure.
56. `[completed][high]` Run available PHPStan/scoped Rector if project command
    is confirmed; never invent Pest/npm lint.
57. `[completed][critical]` Run `npm run build` because Blade/Tailwind changes.
58. `[completed][high]` Run `php artisan project:docs-refresh --check`.
59. `[completed][critical]` Run Playwright desktop/mobile owner workflow,
    keyboard/a11y snapshot, console and browser logs.

## Этап 10 — documentation/completion

60. `[completed][critical]` Update canonical `architecture.md` and
    `DATA_RELATIONS.md`; remove unsupported smart boundary.
61. `[completed][high]` Update performance/caching/security/frontend owners
    only where actual behavior changes.
62. `[completed][critical]` Update docs map, audit, README visitor capability
    and last visitor history section.
63. `[completed][critical]` Add dated Russian CHANGELOG entry without
    modifying previous records.
64. `[completed][critical]` Reread applicable requirements, task, design and
    compliance matrix; close each status honestly.
65. `[completed][critical]` Repository-wide legacy/duplicate/stale/dead/
    unfinished search for smart collection domain.

## Этап 11 — Git delivery

66. `[completed][critical]` Check branch `main`, status, unstaged/staged/
    untracked, remote/upstream, diff/stat and task file inventory.
67. `[completed][critical]` Scan task diff for secrets, debug, binary,
    formatter noise and foreign files.
68. `[completed][critical]` Stage exact task hunks/files only through isolated
    index; review `git diff --cached`.
69. `[completed][critical]` Commit logical smart collection implementation
    and docs in `main` with factual message.
70. `[completed_unresolved_authentication][critical]` Attempt configured non-force push to current remote
    branch; preserve local commit and exact error if authentication/network/
    protection rejects.
71. `[in_progress][critical]` Final report: analyzed problems, exact files,
    migration/index/query/security/tests/commands/plan statuses, branch,
    hashes/messages, push and remaining risks.

## Verification evidence

- Smart focused suite: `31` tests, `114` assertions, all passed after final
  browser-discovered badge regression.
- Related collection/calendar/API/translation/wire-sort matrix: `90` tests,
  `2 594` assertions, all passed.
- Every one of `359` Feature/Unit test files was launched in an isolated
  `php artisan test <file>` process because monolithic PHPUnit exceeds the
  project-enforced `256M` after accumulated application bootstraps. All
  files passed except two pre-existing unrelated contracts:
  `SeasonvarImportDispatchBatcherTest` has one error because its documented
  importer class is absent from `HEAD`; `WebAccountManagementTest` has one
  persistent stale flash assertion. Browser/environment tests reported
  `11` expected skips. The large response-cache test passed separately with
  `1` test and `9` assertions.
- Exact task Pint passed; PHPStan passed with `0` errors; scoped Rector
  dry-run reported `0` changes; Composer validation and PHP syntax passed.
- Vite `8.1.4` built `25` modules in `3.73 s`. Managed docs were refreshed
  and repeated `project:docs-refresh --check` passed.
- Disposable full-migration SQLite and Chromium verified desktop `1440x1000`
  and mobile `390x844`: create-from-preset, editor, detail, automatic label,
  private guest `404`, Livewire `200`, no horizontal overflow and no console
  errors. Browser QA found duplicate smart badges; a RED count assertion
  reproduced `2`, implementation now renders one smart badge plus the normal
  uncategorized label, and focused/browser checks are GREEN.
- Exact 48-file hook-enabled commit `1dc2313`
  (`feat: add dynamic smart collections`) создан в существующей `main`.
  `GIT_TERMINAL_PROMPT=0 git push origin main` завершился с кодом `128` до
  передачи данных: `fatal: could not read Username for
  'https://github.com': terminal prompts disabled`.
