# Task 103: новая карточка сериала

> Выполнять последовательно в existing `main` под lease
> `task-103-catalog-title-card-actions`. Статусы синхронизируются с
> `docs/plans/current-task-plan.md`.

## 1. Анализ текущего поведения

- [x] **Critical — requirements, stack и Git baseline.** Что: прочитать
  mandatory owners, версии, status/branch/upstream и shared index. Почему:
  нельзя присвоить чужие изменения или использовать неподдерживаемый API.
  Файлы: read-only inventory. Зависимости: Task 102 handoff. Риски:
  concurrent drift. Проверка: lease/status/version evidence в current plan.
- [x] **Critical — пройти call graph карточки.** Что: route → Livewire →
  page builder → relation/count/state loaders → Blade component → UI
  component, policies/services/tests. Почему: redesign должен расширить один
  owner. Риски: сломать compact/home/recommendation. Проверка: protected
  contracts и reuse decision в design.

## 2. Подготовка и дизайн

- [x] **Critical — объявить exact paths.** Что: NUL-safe manifest до edits.
  Почему: shared `main` содержит foreign state. Проверка: lease status
  `paths_declared=yes`.
- [x] **High — записать design/data/error/rollback.** Что: выбрать canonical
  component + bounded union + existing writes. Почему: зафиксировать решение
  до implementation. Файлы: Task 103 spec/current plan. Риски: query budget,
  hover-only actions, IDOR. Проверка: перечитать spec/plan.

## 3. TDD presentation

- [x] **Critical — RED grid contract.** Что: title 2 lines, original 1,
  one rating, two genres, no description, poster overlays, visible focusable
  actions. Файлы: `CatalogCompactTitleCardTest`, Blade/visual contracts.
  Зависимости: prepared attributes. Риски: brittle copy checks. Проверка:
  semantic `data-*`, class and text assertions.
- [x] **Critical — RED list contract.** Что: country/count/rating metadata,
  two genres, three-line excerpt and three primary actions. Риски: compact
  regression. Проверка: standalone list and compact isolation assertions.
- [x] **High — GREEN canonical component/templates.** Что: extend
  `TitleCard`, add compact/actions partials and overlay slot. Почему:
  eliminate duplicate markup while preserving consumers. Проверка: focused
  tests and component render without lazy loading.

## 4. Bounded metadata / database

- [x] **Critical — RED metadata correctness.** Что: IMDb/KP, deterministic
  country, exact 18+, seven-day inclusive recent flag, old/future/draft
  exclusion and empty collection. Файлы: new metadata loader test. Риски:
  date/visibility leak. Проверка: boundary fixtures.
- [x] **Critical — RED constant query contract.** Что: one union for one or
  96 titles, no per-card relations, existing indexes visible in `EXPLAIN`.
  Почему: protect catalog latency. Проверка: DB listener + actual plan.
- [x] **Critical — GREEN loader integration.** Что: replace only `/titles`
  rating eager query with union and attach scalar prepared attributes. Файлы:
  loader/page builder. Риски: ranked pagination order and SEO projection.
  Проверка: old count-sort/search tests and query budget.
- [x] **Low — migration decision.** Status: `not_applicable`; actual
  `EXPLAIN QUERY PLAN` использует существующие title-first rating, pivot и
  season indexes, поэтому DDL не добавляется.

## 5. Backend actions, validation and authorization

- [x] **Critical — RED library matrix.** Guest redirect; verified add/remove;
  unverified/denied no write; invalid ID/boolean no write; hidden ID 404.
  Файлы: new Livewire action test. Проверка: DB state and response/errors.
- [x] **Critical — RED feedback matrix.** Valid non-subject reason persists;
  invalid/subject reason rejected; hidden title cannot mutate. Риски: IDOR,
  wrong recommendation identity. Проверка: user/title-scoped rows only.
- [x] **Critical — GREEN Livewire orchestration.** Что: normalize scalar
  inputs, visible re-resolution, canonical services/policy, localized notice.
  Почему: browser state is never authority. Риски: caught authorization or
  stale computed cards. Проверка: render round-trip and policy regressions.

## 6. Frontend, mobile and accessibility

- [x] **High — responsive action layout.** Что: always-visible primary
  actions, 44 px targets, native `details` dropdown/bottom sheet, safe-area
  offset and no hover-only functionality. Файлы: action partial/card styles.
  Риски: overflow and nested interactive overlay. Проверка: 320/390/mobile,
  desktop keyboard and touch.
- [x] **High — focus/loading/error states.** Что: visible focus rings,
  exact `wire:target`, disabled/loading, live notice/error. Проверка:
  keyboard tab order, screen-reader names and duplicate-submit behavior.
- [x] **Medium — browser state regression.** Что: grid/list toggle,
  pagination and back/forward retain query string. Проверка: existing plus
  extended Playwright.

## 7. Security and error handling

- [x] **Critical — inspect XSS/CSRF/IDOR/mass assignment.** Что: escaped Blade,
  Livewire CSRF, scalar IDs only, no `$request->all()`, no raw client state.
  Проверка: malicious title copy render and tampered action tests.
- [x] **High — predictable errors.** Что: no empty catch/debug log; report
  unexpected exception without sensitive context; localized user error.
  Проверка: failure tests and repository debug scan.

## 8. Performance and compatibility

- [x] **Critical — preserve query/byte budgets.** Что: initial/update no query
  growth, bounded HTML/Livewire payload. Проверка: `CatalogLivewireBudgetTest`.
- [x] **High — cross-feature regression.** Что: compact/recommendation/home,
  SEO, filter/sort/pagination, visibility and user-state loaders. Проверка:
  focused suites then broad PHPUnit.
- [x] **Medium — cache decision.** Status: `not_applicable`; query is
  page-bounded and existing mutations own invalidation, so no new cache key.

## 9. Translations and documentation

- [x] **High — RU/EN keys.** Что: action, notice, error and aria labels with
  recursive key/order parity. Проверка: translation parity/lint.
- [x] **High — canonical owners.** Что: update UI, views, frontend,
  performance, architecture/catalog-search map where materially changed.
  Риски: duplicate requirement owner. Проверка: docs map and link gate.
- [x] **Critical — README/CHANGELOG/evidence.** Что: visitor result in final
  README history, separate Russian technical CHANGELOG entry, completed
  compliance evidence. Проверка: policies and exact diff.

## 10. Verification, commit and push

- [x] **Critical — focused verification.** PHPUnit RED/GREEN, Pint, relevant
  static/architecture/query checks.
- [x] **Critical — broad verification.** Full PHPUnit where environment
  permits, npm build, docs checks and Playwright desktop/mobile/tablet.
  Any foreign failure is recorded with exact command/output.
- [x] **Critical — final audit.** Reread requirements/spec/plan, scan legacy
  cards, Blade queries, hover-only controls, TODO/debug/dead code, secrets and
  accidental formatting.
- [x] **Critical — exact isolated staging.** Start alternate index at current
  HEAD, stage only Task 103 hunks, verify manifest/diff/security, approve
  snapshot. Shared index remains unchanged.
- [ ] **Critical — commit/push.** Commit exact snapshot in `main`, ordinary
  push configured remote, then release lease. No force/bypass. Factual hash,
  subject and push result go to evidence/final report.

## Skipped/not applicable expectations

- New route/controller/API Resource/middleware/repository/DTO: not needed.
- Migration/index/schema/backfill/transaction outside existing services:
  not needed unless measured plan contradicts current evidence.
- New dependency/framework/cache/queue/job/command/JavaScript framework:
  not applicable.
- Production DML/cache flush/worker restart/provider call: prohibited and not
  required.
