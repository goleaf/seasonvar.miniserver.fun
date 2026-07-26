# Homepage Continue Watching Season-Bound Implementation Plan

> **Для исполнителя:** выполнять пункты последовательно в существующей ветке
> `main`. Branch, worktree, PR и subagent не создавать. После discovery,
> меняющего scope, сразу обновить этот plan и
> `docs/plans/current-task-plan.md`.

**Цель:** устранить глобальную materialization published episodes в
авторизованной homepage-секции «Продолжить просмотр», сохранив canonical
owner/visibility/playability/current-next semantics.

**Архитектура:** существующий `CatalogViewingActivityQuery` остаётся
единственным owner activity query. Его bounded title batch дополнительно
проецируется в exact available season IDs внутри watchable episode subquery.
Общий playback service, DTO, routes, UI, cache и schema не меняются.

**Стек:** PHP 8.5, Laravel 13.22, Livewire 4.3, Eloquent, SQLite,
PHPUnit 12.5.

## Глобальные ограничения

- Не менять и не включать в commit foreign Tasks 92–98 hunks.
- Не выполнять reset, stash, restore, clean, force push или blind
  `git add .`.
- Не создавать branch/worktree/PR.
- Не добавлять migration/index/DML/cache/package/queue/route/config/env.
- Сохранить owner scope и все title/season/episode/media availability
  predicates.
- Сохранить exact Continue Watching payload, order, limit и completion rule.
- Timing values описывать только как local diagnostic evidence.
- Commit собирать из exact Task 99 paths/hunks через изолированный index при
  необходимости.

---

## Этап 1. Анализ текущего поведения

### Task 1.1 — Прочитать требования и применимые feature owners

**Приоритет:** critical
**Статус:** completed

**Что/почему:** заново прочитать root workflow, canonical index, architecture,
security, performance/cache, UI/frontend, production/maintenance/integration,
views, data-relations и playback/library contracts до edit.

**Файлы/модули:** `AGENTS.md`, `docs/requirements/*`, `docs/{architecture,
development,security,performance,caching,frontend,views,DATA_RELATIONS}.md`.

**Зависимости:** canonical precedence.

**Риски:** оптимизация нарушит private progress, visibility или shared cache.

**Проверка:** feature map и compliance matrix ниже содержат evidence/status.

### Task 1.2 — Зафиксировать stack, schema и shared Git state

**Приоритет:** critical
**Статус:** completed

**Что/почему:** подтвердить версии, фактическую database, branch/remote и
отделить чужие изменения.

**Файлы/модули:** Composer/npm locks, app info, schema/index inventory, Git
index/worktree.

**Зависимости:** Laravel Boost application/database tools, local CLI.

**Риски:** неверная version-dependent рекомендация; захват чужого diff.

**Проверка:** PHP `8.5.8`, Laravel `13.22.0`, Livewire `4.3.3`, SQLite;
`main` ahead remote; staged/unstaged/untracked inventory сохранён.

### Task 1.3 — Проследить guest/auth homepage request path

**Приоритет:** critical
**Статус:** completed

**Что/почему:** определить реальный owner bottleneck, а не оптимизировать
случайный запрос.

**Файлы/модули:** routes, middleware, `CatalogHomePage`,
`CatalogHomePageBuilder`, homepage cache/snapshot, personalized services,
Blade, API.

**Зависимости:** route/full-page Livewire/cache policies.

**Риски:** смешать быстрый guest cache hit и authenticated cold render.

**Проверка:** separate guest/auth builder и real browser/HTTP measurements.

### Task 1.4 — Профилировать SQL и EXPLAIN

**Приоритет:** critical
**Статус:** completed

**Что/почему:** найти statement с максимальной долей latency и проверить
planner.

**Файлы/модули:** `CatalogViewingActivityQuery`,
`CatalogTitlePlaybackQuery`, production-like read-only SQLite.

**Зависимости:** existing user/progress data and indexes.

**Риски:** active importer contention исказит wall timing; DML на production
snapshot.

**Проверка:** `PRAGMA query_only=ON`, DB listener, repeated samples, bound
`EXPLAIN QUERY PLAN`; фиксировать result parity отдельно от latency.

---

## Этап 2. Решение, риски и подготовка

### Task 2.1 — Сравнить архитектурные варианты

**Приоритет:** high
**Статус:** completed

**Что/почему:** выбрать минимальное решение с простым rollback.

**Варианты:** exact season semi-join (выбран); global playback rewrite; новый
index/materialized projection/private cache; N+1 navigation.

**Файлы/модули:** design spec, query/index/cache inventories.

**Зависимости:** baseline и EXPLAIN.

**Риски:** новый источник истины, shared-service regression, write
amplification.

**Проверка:** linked design фиксирует выбор, non-goals и отказ от альтернатив.

### Task 2.2 — Зафиксировать design, expected и protected files

**Приоритет:** critical
**Статус:** completed

**Что/почему:** выполнить mandatory design gate до production code.

**Файлы:** design spec и этот implementation plan.

**Зависимости:** Task 2.1.

**Риски:** неполный compatibility map.

**Проверка:** перечислены routes, DTO, auth, data, cache, deployment и rollback
contracts.

### Task 2.3 — Обновить current plan и compliance matrix

**Приоритет:** critical
**Статус:** completed

**Что/почему:** вести Task 99 как живой append-only checklist рядом с
parallel Tasks 96–98.

**Файлы:** `docs/plans/current-task-plan.md`.

**Зависимости:** design/implementation plan.

**Риски:** перезаписать чужие sections.

**Проверка:** отдельный Task 99 section; статус каждого requirement явный.

### Task 2.4 — Перечитать подготовленный план

**Приоритет:** critical
**Статус:** completed

**Что/почему:** критически проверить полноту до TDD.

**Файлы:** design, implementation plan, current-task Task 99.

**Зависимости:** Tasks 2.2–2.3.

**Риски:** тест будет закреплять неверный contract.

**Проверка:** нет незакрытого design concern; scope не требует вопроса
пользователю.

---

## Этап 3. TDD RED

### Task 3.1 — Создать isolated query-plan regression

**Приоритет:** critical
**Статус:** pending

**Что:** создать отдельный PHPUnit Feature test с двумя visible titles,
available seasons/episodes/media и owner progress.

**Почему:** не трогать foreign-modified `CatalogPageTest` и проверить реальное
поведение service.

**Файлы:** новый
`tests/Feature/CatalogViewingActivityQueryPlanTest.php`.

**Зависимости:** factories, `RefreshDatabase`, DB query listener.

**Риски:** fixture падает из-за missing playback fields; listener поймает
нерелевантный SQL.

**Проверка:** semantic assertions проходят; captured SQL однозначно содержит
alias `watchable_episode_sequence`.

### Task 3.2 — Проверить current/next/owner isolation

**Приоритет:** critical
**Статус:** pending

**Что:** незавершённый current episode остаётся current; завершённый episode
получает next; чужая activity и unavailable target не протекают.

**Почему:** performance change не может ослабить correctness/privacy.

**Файлы:** task-specific test.

**Зависимости:** completion rule and availability fixtures.

**Риски:** test повторяет implementation вместо observable DTO.

**Проверка:** assert DTO title/episode/action values, не private method calls.

### Task 3.3 — Доказать RED на SQL shape/plan

**Приоритет:** critical
**Статус:** pending

**Команда:**

```bash
php artisan test --filter=CatalogViewingActivityQueryPlanTest
```

**Ожидание:** semantic assertions проходят, а требование direct
`episodes.season_id in (select id from seasons ...)` или lookup-index plan
падает на current code.

**Риски:** false RED из-за hardcoded quoting.

**Проверка:** нормализовать SQL case/whitespace; EXPLAIN использовать exact
captured bindings.

---

## Этап 4. Backend и SQL implementation

### Task 4.1 — Добавить exact season-ID constraint

**Приоритет:** critical
**Статус:** pending

**Что:** после title constraint в `$watchableEpisodeIds` добавить
`whereIn(episodes.season_id, Season::availableTo($user)->whereIn(
catalog_title_id, $catalogTitleIds)->select(id))`.

**Почему:** дать planner bounded lookup до episode materialization.

**Файлы:** `CatalogViewingActivityQuery.php`.

**Зависимости:** уже imported `Episode`, `Season`; existing publication lookup
indexes.

**Риски:** потеря row при несовпадении season visibility; duplicate predicate.

**Проверка:** current availability query уже требует ту же видимость, поэтому
row sets exact; test + production snapshot hash.

### Task 4.2 — Не менять shared playback abstraction

**Приоритет:** high
**Статус:** pending

**Что/почему:** оставить `CatalogTitlePlaybackQuery` без diff, потому что
batch title IDs принадлежат caller-specific boundary.

**Файлы:** protected `CatalogTitlePlaybackQuery.php`.

**Зависимости:** Task 4.1.

**Риски:** случайный broad refactor.

**Проверка:** `git diff --` файла пуст.

### Task 4.3 — Format и syntax

**Приоритет:** high
**Статус:** pending

**Команды:**

```bash
./vendor/bin/pint --dirty --format agent
php -l app/Services/Catalog/CatalogViewingActivityQuery.php
php -l tests/Feature/CatalogViewingActivityQueryPlanTest.php
```

**Риски:** Pint форматирует чужой dirty PHP.

**Проверка:** сравнить changed-file inventory; commit включает exact Task 99
files/hunks.

---

## Этап 5. GREEN, query и performance evidence

### Task 5.1 — Получить focused GREEN

**Приоритет:** critical
**Статус:** pending

**Проверка:** Task 99 test полностью проходит и доказывает DTO semantics,
season-bound SQL и lookup indexes.

### Task 5.2 — Проверить exact production-snapshot parity

**Приоритет:** critical
**Статус:** pending

**Что:** выполнить old/new equivalent read-only queries либо сохранить
pre-change result и сравнить ordered row IDs/action targets.

**Почему:** timing без parity не является корректной оптимизацией.

**Риски:** live importer меняет snapshot между samples.

**Проверка:** выполнять paired reads в одном short read boundary или
фиксировать одинаковые входные title/episode IDs; сравнить exact rows/hash.

### Task 5.3 — Повторить SQL timing и EXPLAIN

**Приоритет:** high
**Статус:** pending

**Что:** минимум три fresh-process samples auth builder/target query.

**Почему:** подтвердить практический результат.

**Проверка:** target больше не начинает materialization с global
`episodes_recommendation_release_events_idx`; фиксировать SQL count, target
time, builder wall и indexes.

### Task 5.4 — Оценить secondary homepage queries

**Приоритет:** medium
**Статус:** pending

**Что/почему:** после primary fix повторно отсортировать statements по time.
Расширять scope только при отдельном material bottleneck и безопасном
доказанном решении.

**Риски:** бесконечный несвязанный refactor.

**Проверка:** если auth total уверенно ниже исходных 4 s и secondary query не
доминирует, зафиксировать как observation, а не добавлять speculative code.

---

## Этап 6. Validation, authorization, security, errors

### Task 6.1 — Validation/normalization review

**Приоритет:** high
**Статус:** pending

**Что:** подтвердить отсутствие нового request input; limit сохраняет clamp
`1..24`; IDs берутся из owner rows.

**Файлы:** query service и callers.

**Зависимости:** unchanged public methods.

**Риски:** спутать zero/missing filter semantics — фильтров в scope нет.

**Проверка:** mark `not_applicable` для новых Form Requests/frontend
validation с объяснением.

### Task 6.2 — Authorization/privacy/security audit

**Приоритет:** critical
**Статус:** pending

**Что:** проверить owner scope, IDOR, SQL injection, personal cache leak,
visibility/Premium/region predicates.

**Файлы:** activity/playback queries, home builder/cache policy.

**Зависимости:** existing policies/scopes.

**Риски:** optimization bypass доступности.

**Проверка:** owner isolation fixture, available/unavailable fixture, bound
SQL, no shared-cache change.

### Task 6.3 — Error/logging/concurrency review

**Приоритет:** medium
**Статус:** pending

**Что:** подтвердить отсутствие write/transaction/race/error-handling change.

**Почему:** read-only nested subquery не требует catch/log/transaction.

**Проверка:** no DML/external I/O/debug logging; mark transaction/rate limit
`not_applicable`.

---

## Этап 7. Backward compatibility и cross-feature regressions

### Task 7.1 — Continue Watching, history и player

**Приоритет:** critical
**Статус:** pending

**Что:** запустить существующие tests для owner activity, navigation,
completion и visibility.

**Риски:** next episode ordering/regression.

**Проверка:** focused filters/classes; exact failures расследовать, foreign
baseline не маскировать.

### Task 7.2 — Homepage guest/auth и library/API

**Приоритет:** critical
**Статус:** pending

**Что:** запустить homepage builder/render/cache, personalized homepage,
library summary и mobile Continue Watching regressions.

**Риски:** query budget foreign failures в shared tree.

**Проверка:** Task 99 test must pass; foreign failures записать exact.

### Task 7.3 — Routes, API, SEO, locale, cache

**Приоритет:** high
**Статус:** pending

**Что/почему:** доказать отсутствие public contract drift.

**Проверка:** route/API response shape and page cache tests; no changed route,
resource, translation or cache key.

### Task 7.4 — Database migration/index review

**Приоритет:** high
**Статус:** pending

**Что:** проверить duplicate/missing indexes и отказаться от migration при
выборе existing indexes.

**Проверка:** migration diff отсутствует; EXPLAIN evidence привязан к
конкретным existing indexes.

---

## Этап 8. Frontend и browser

### Task 8.1 — UI/UX code review

**Приоритет:** medium
**Статус:** pending

**Что:** подтвердить отсутствие Blade/Livewire/JS/CSS diff; current loading,
empty, responsive, localized labels и reset behavior не меняются.

**Проверка:** frontend changed-file diff пуст.

### Task 8.2 — Browser QA

**Приоритет:** high
**Статус:** pending

**Что:** desktop/mobile guest checks; authenticated check только через
безопасную existing test session без публикации credentials.

**Проверка:** 200, H1, Continue Watching if fixture/session available, no
console/page/request errors, no horizontal overflow; report TTFB/LCP as local
observation.

### Task 8.3 — Asset build applicability

**Приоритет:** low
**Статус:** pending

**Что:** frontend assets не меняются; `npm run build` всё равно выполнить как
wide verification, если shared tree build contract требует.

**Проверка:** exact command/result recorded; no generated artifact committed
unless already tracked and task-owned.

---

## Этап 9. Качество, tests и static verification

### Task 9.1 — Focused tests

**Приоритет:** critical
**Статус:** pending

**Команды:** task-specific test, Continue Watching exact test, personalized
homepage and library/API related classes.

**Проверка:** counts/assertions recorded.

### Task 9.2 — Full PHPUnit

**Приоритет:** critical
**Статус:** pending

**Команда:** `php artisan test`; если repository process limit завершит suite,
повторить только с безопасным temporary CLI memory limit и честно записать
оба результата.

**Риски:** known foreign Tasks 96–98 failures.

**Проверка:** не использовать `|| true`; каждый failure классифицировать по
точному test и relationship к Task 99.

### Task 9.3 — Static/style/toolchain

**Приоритет:** high
**Статус:** pending

**Что:** Pint, PHP syntax, Composer validate, installed PHPStan/Rector checks,
route/config checks по применимости.

**Риски:** отсутствующая команда.

**Проверка:** запускать только реально установленные scripts/tools; exact
exit/result.

### Task 9.4 — Code-review cleanup

**Приоритет:** high
**Статус:** pending

**Что:** проверить imports/types/names/dead code/TODO/debug/raw SQL/Blade
queries и diff scope.

**Проверка:** repository searches scoped к feature identity плюс full diff
review.

---

## Этап 10. Документация

### Task 10.1 — Обновить canonical performance owner

**Приоритет:** high
**Статус:** pending

**Что:** добавить фактические before/after/query-plan/parity observations.

**Файлы:** `docs/performance.md`.

**Риски:** заявить p95/SLA по одиночным samples.

**Проверка:** явно назвать observations и отсутствие migration/index/cache
change.

### Task 10.2 — Проверить README и visitor history

**Приоритет:** critical
**Статус:** pending

**Что:** добавить meaningful Russian visitor-facing result, поскольку
авторизованная homepage заметно меняется по скорости.

**Файлы:** `README.md`.

**Риски:** изменить managed project-docs block или положение последнего H2.

**Проверка:** visitor history остаётся последним H2; no internal jargon in
visitor copy.

### Task 10.3 — Обновить Russian CHANGELOG

**Приоритет:** critical
**Статус:** pending

**Что:** отдельная dated technical entry без сокращения старых записей.

**Файлы:** `CHANGELOG.md`.

**Проверка:** обычный текст русский; technical identifiers exact.

### Task 10.4 — Завершить plan/compliance evidence

**Приоритет:** critical
**Статус:** pending

**Что:** обновить statuses, commands, failures, commits/push.

**Файлы:** implementation plan и current Task 99.

**Проверка:** ни один `completed` без фактического evidence.

---

## Этап 11. Final audit, commit и push

### Task 11.1 — Перечитать применимые требования

**Приоритет:** critical
**Статус:** pending

**Проверка:** root/index/performance/security/data/playback/production/current
plan reread; compliance statuses reconciled.

### Task 11.2 — Проверить final diff и secrets/debug

**Приоритет:** critical
**Статус:** pending

**Команды:** branch/status, unstaged/staged diff/stat/name/status, untracked,
remote/upstream, secret/debug/TODO search, whitespace check.

**Риски:** foreign shared-tree hunks.

**Проверка:** exact task paths/hunks only; no `.env`, logs, dumps, generated
cache, credentials or unrelated formatting.

### Task 11.3 — Создать logical commits на `main`

**Приоритет:** critical
**Статус:** pending

**Что:** design/plan и verified performance implementation/docs разделить
логично; staged diff перечитать перед каждым commit.

**Риски:** hook stage чужого `CHANGELOG.md`.

**Проверка:** isolated temporary index при необходимости; commit tree contains
only Task 99; branch remains `main`; normal working index untouched.

### Task 11.4 — Выполнить ordinary push

**Приоритет:** critical
**Статус:** pending

**Что:** `git push origin main` либо existing upstream equivalent, без force.

**Риски:** known HTTPS authentication absence, remote rejection.

**Проверка:** exact command/exit/output; failure status `unresolved`, local
commit/hash сохранён.

---

## Task-specific compliance matrix

| Requirement/domain | Статус | Evidence / gate |
| --- | --- | --- |
| Root/index/canonical fresh read | `completed` | 26.07.2026 до Task 99 code |
| Relevant owners/Markdown | `completed` | Homepage, progress, library, playback, cache, production |
| Runtime/packages/database/frontend/Git | `completed` | CLI/Boost/locks/schema/status inventory |
| Existing implementation first | `completed` | Full route/builder/activity/playback/view/test trace |
| Official Laravel 13 docs | `completed` | Boost query builder/debug/eager-loading docs |
| Reproduction/measurement/EXPLAIN | `completed` | Auth query `1,42–1,90 s`; exact plan/index evidence |
| Alternatives/design/rollback | `completed` | Four approaches in linked design |
| Prepared-plan reread | `completed` | Design, implementation plan и Task 99 перечитаны; critical gaps нет |
| TDD RED | `pending` | Isolated semantics + SQL/EXPLAIN regression |
| Minimal implementation/GREEN | `pending` | One caller-local season semi-join |
| Input/validation | `not_applicable` | No new request input; existing limit clamp |
| Authorization/privacy/security | `pending` | Owner + availability + no shared personal cache |
| SQL parity/performance | `pending` | Exact rows/hash, repeated samples, selected indexes |
| Migration/index/data safety | `not_applicable` | No DDL/DML; existing indexes sufficient |
| Cache/invalidation | `already_compliant` | No key/policy/write-path change; final review pending |
| API/routes/SEO/locales | `already_compliant` | No public contract file change; regression pending |
| UI/mobile/accessibility | `already_compliant` | No frontend diff; browser verification pending |
| Error/logging/concurrency | `not_applicable` | Read-only query, no external I/O/write |
| Tests/static/build/browser | `pending` | Focused → related → full → tools/browser |
| Docs/README/CHANGELOG | `pending` | After verified measurements |
| Final legacy/debug/secret audit | `pending` | Before commit |
| Commit main | `pending` | Exact Task 99 scope |
| Push main | `pending` | Ordinary non-force; auth failure honest |

## Execution order

1. `[completed]` Requirements, skills, versions, Git/shared-tree audit.
2. `[completed]` Guest/auth route and architecture trace.
3. `[completed]` Browser/HTTP/SQL/EXPLAIN baseline.
4. `[completed]` Alternatives, approved design, production/rollback analysis.
5. `[completed]` Detailed plan and compliance matrix creation.
6. `[completed]` Prepared-plan reread.
7. `[pending]` Isolated behavioral/query-plan RED.
8. `[pending]` One caller-local season-ID constraint.
9. `[pending]` Focused GREEN and exact parity.
10. `[pending]` Repeated auth profile and EXPLAIN.
11. `[pending]` Security/cache/API/route/cross-feature review.
12. `[pending]` Related/full/static/build/browser verification.
13. `[pending]` Performance owner, README, CHANGELOG and final evidence.
14. `[pending]` Requirements/legacy/debug/secret/diff audit.
15. `[pending]` Exact logical commits on existing `main`.
16. `[pending]` Configured ordinary push.
