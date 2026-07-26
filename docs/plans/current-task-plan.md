# Текущая задача — Task 103: новая карточка сериала

Дата: 26.07.2026.

Owner: `task-103-catalog-title-card-actions`.

Текущий этап: `in_progress` — реализация и доступные проверки завершены;
подготовлен exact isolated delivery в `main`.

## Реестр активных workstreams

| Workstream | Status | Evidence |
| --- | --- | --- |
| Task 103 — grid/list карточка, действия и metadata projection | `in_progress: ready for delivery` | [Design](../superpowers/specs/2026-07-26-catalog-title-card-actions-design.md), [план](../superpowers/plans/2026-07-26-catalog-title-card-actions.md), [evidence](archive/2026-07-26-catalog-title-card-actions-evidence.md) |

## Реестр blocked/unresolved

| Workstream | Status | Evidence |
| --- | --- | --- |
| Shared worktree чужих задач | `unresolved: preserved` | Task 103 использует owner lease, declared paths и изолированный Git index; чужие staged/unstaged/untracked изменения не входят в scope |
| Remote delivery накопленной `main` | `unresolved: ahead 112 до Task 103` | Обычный push выполняется только после exact commit; результат фиксируется фактически в финальном отчёте |
| Production activation | `not_applicable` | Нет migration, package, env, cache-key, queue, scheduler или production DML |

## Task-specific compliance matrix

| Requirement | Status | Evidence |
| --- | --- | --- |
| Root `AGENTS.md` и requirements index прочитаны до edits | `completed` | Повторно прочитаны 26.07.2026 после commit Task 102 |
| Applicable canonical и feature Markdown прочитаны | `completed` | Architecture, development, multilingual, security, performance/cache, UI/frontend, authorization, production, maintenance, integration, catalog-search, views и прежний card design |
| Фактический stack и version-specific docs проверены | `completed` | PHP 8.5, Laravel 13.22.0, Livewire 4.3.3, Boost 2.4.13, PHPUnit 12.5.32, Tailwind CSS 4.3.2; Laravel 13/Livewire 4 docs проверены через Boost |
| Branch/status/upstream baseline | `completed` | `main`, HEAD `ab9aa3e`, `origin/main`, ahead 112; shared changes инвентаризированы без reset/stash/restore |
| Один task owner и exact path manifest | `completed` | Lease `task-103-catalog-title-card-actions`; final manifest содержит 35 NUL-safe paths после исключения двух unchanged verification-only tests |
| Existing implementation audited before replacement | `completed` | Canonical `TitleCard`, `PosterCard`, `/titles` Livewire owner, builders/loaders, policy, library and recommendation services, tests and browser contract traced |
| Alternatives, data flow, errors и rollback | `completed` | [Task 103 design](../superpowers/specs/2026-07-26-catalog-title-card-actions-design.md) |
| Detailed prioritized plan reread | `completed` | [Task 103 implementation plan](../superpowers/plans/2026-07-26-catalog-title-card-actions.md) |
| TDD presentation | `completed` | Focused RED → GREEN: 15 tests, 118 assertions for grid/list density, ratings, overlays, metadata and actions |
| Bounded metadata query | `completed` | One page-ID-bounded `UNION ALL`; SQLite title-first rating index and publication visibility index confirmed through `EXPLAIN QUERY PLAN` |
| Library and feedback actions | `completed` | Existing services/policy plus server-side scalar normalization and visible-title re-resolution |
| Accessibility and responsive browser QA | `completed` | 31 Playwright passed, 2 intentional reduced-motion skips across desktop/mobile/tablet; Axe, focus, 44 px targets, geometry, console/network and reviewed screenshots |
| Security and authorization review | `completed` | Guest redirect, verified add/remove and feedback, unverified denial, invalid scalar/reason and hidden-title IDOR regression |
| Query plan and performance verification | `completed` | One query for 1/96 cards; existing rating/pivot/season indexes confirmed through `EXPLAIN`; catalog initial/deferred/update budget passed |
| Translations RU/EN parity | `completed` | New visible/action/error labels in `catalog.php`; `TranslationCatalogParityTest`: 3 passed, 80 162 assertions |
| Canonical docs/README/CHANGELOG | `completed` | UI, views, frontend, performance, architecture, catalog-search, docs map, visitor README and Russian CHANGELOG updated and reread |
| Final requirements/legacy scan | `completed` | Applicable owners reread; related repository scan found no Blade queries, debug/TODO, hover-only Task 103 actions or duplicate metadata loader |
| Exact commit/push in `main` | `unresolved` | Alternate isolated index, exact staged manifest and ordinary push remain delivery actions after this evidence snapshot |

## Последнее подтверждённое evidence

- Focused Task 103 suite после final DI correction: 100 passed,
  980 assertions. Итоговый связанный catalog набор: 172 passed,
  81 689 assertions.
- Browser matrix: 31 passed, 2 intentional reduced-motion skips, 0 failed;
  desktop/mobile/tablet screenshots просмотрены.
- Vite build, exact-scope Pint и production-scope PHPStan passed.
- Full diagnostic PHPUnit выполнил 2162 tests: 2130 passed, 11 skipped,
  18 failed и 3 errors. Ни один focused Task 103 test не упал; failures
  принадлежат foreign PWA, collection quality, auth/import/shared-workflow
  workstreams и известному чужому title-detail schema-query росту.
- Стандартный full run упёрся в project `memory_limit=256M`; повторный
  diagnostic с 1 GiB завершился приведённой выше полной матрицей.
- `project:docs-refresh --check` остаётся `unresolved` только из-за чужого
  unstaged `docs/MAINTENANCE_LOG.md`; этот файл не изменялся Task 103.
- Подробный журнал: [Task 103 evidence](archive/2026-07-26-catalog-title-card-actions-evidence.md).

## Exact declared files

1. `app/Livewire/CatalogSeries.php`
2. `app/Services/Catalog/CatalogTitleCardMetadataLoader.php`
3. `app/Services/Catalog/CatalogTitlesPageBuilder.php`
4. `app/View/Components/Catalog/TitleCard.php`
5. `app/View/Components/Ui/PosterCard.php`
6. `resources/views/catalog/titles.blade.php`
7. `resources/views/components/ui/poster-card.blade.php`
8. `resources/views/components/catalog/title-card-actions.blade.php`
9. `resources/views/components/catalog/title-card-compact.blade.php`
10. `resources/views/components/catalog/title-card-grid.blade.php`
11. `resources/views/components/catalog/title-card-list.blade.php`
12. `resources/views/components/catalog/title-card-personal-state.blade.php`
13. `lang/ru/catalog.php`
14. `lang/en/catalog.php`
15. `tests/Feature/CatalogCompactTitleCardTest.php`
16. `tests/Feature/CatalogVisualSystemTest.php`
17. `tests/Feature/CatalogTitleCardActionTest.php`
18. `tests/Feature/CatalogTitleCardMetadataLoaderTest.php`
19. `tests/browser/catalog.spec.js`
20. `docs/plans/current-task-plan.md`
21. `docs/plans/archive/2026-07-26-catalog-title-card-actions-evidence.md`
22. `docs/superpowers/specs/2026-07-26-catalog-title-card-actions-design.md`
23. `docs/superpowers/plans/2026-07-26-catalog-title-card-actions.md`
24. `docs/UI_STANDARDS.md`
25. `docs/views.md`
26. `docs/frontend.md`
27. `docs/performance.md`
28. `docs/architecture.md`
29. `docs/catalog-search.md`
30. `docs/README.md`
31. `README.md`
32. `CHANGELOG.md`
33. `resources/css/app.css`
34. `tests/Feature/CatalogPageTest.php`
35. `tests/Unit/BladeTemplateTest.php`

Discovery, меняющее список, немедленно обновляет plan и повторную declaration.

## Protected compatibility contracts

- ветка `main`, existing `/titles` routes/names, localized URLs, query
  parameters, pagination, sort/filter/view state и browser back/forward;
- один full-page Livewire owner `CatalogSeries`; новые HTML routes,
  controllers, frontend framework и dependencies не создаются;
- `CatalogTitle` route binding по `slug`, canonical visibility/entitlement,
  `CatalogTitlePolicy::interact`, user-state and recommendation services;
- layouts `compact`, `recommendation`, `home`, `spotlight`, `trend` и их
  existing consumers; redesign ограничен canonical `grid`/`list`;
- prepared Blade data only: queries, authorization и writes отсутствуют в
  templates;
- scalar Livewire state, current payload/query budgets, no source/private URL
  serialization и no client-trusted access decision;
- RU/EN key parity, Russian visible UI, local icons/assets, 44 px controls,
  visible keyboard focus и отсутствие hover-only actions;
- SQLite schema, migrations, indexes, cache keys, API Resources, sitemap,
  importer, player, Premium, regional/legal and administration contracts;
- чужие staged/unstaged/untracked изменения и shared index baseline.

## Cross-feature impact

| Domain | Status | Strategy / verification |
| --- | --- | --- |
| Catalog `/titles` UI | `affected` | New grid/list templates, one visual hierarchy and responsive actions |
| Search/filter/sort/pagination | `affected-compatible` | Public state unchanged; focused old-regression and browser back/forward checks |
| Personal library | `affected` | Idempotent desired-state write through existing service/policy |
| Recommendations | `affected` | Existing non-subject feedback reasons/service only; no new persisted identity |
| Authentication/authorization/privacy | `affected` | Guest login redirect, visible-title re-resolution, policy-enforced mutation, no user ID from client |
| Database/query performance | `affected` | One bounded metadata union; no DDL/index until measured plan evidence |
| Mobile/accessibility | `affected` | Visible actions or native `details` sheet, safe-area-aware placement, keyboard/touch/focus tests |
| Translations | `affected` | RU/EN parity in existing catalog domain; existing reason labels reused |
| SEO/sitemap/API/routes | `already_compliant` | No public contract or response-shape change |
| Caching/notifications/audit | `already_compliant` | Existing service invalidation/event behavior preserved; no new cache owner |
| Importer/player/Premium/region/legal/admin | `unaffected` | No source, playback, entitlement schema or admin boundary change |
| Migrations/dependencies/env/assets | `not_applicable` | No DDL/package/config/build-tool addition |
| Production operations | `not_applicable` | Code/assets/docs rollback only; ordinary deploy/build contract unchanged |

## Verification and rollback

- RED → GREEN focused PHPUnit for presentation, actions, metadata and budgets;
- Pint after PHP edits, translation parity, relevant static/architecture
  tests, full PHPUnit where environment permits, Vite build;
- SQLite `EXPLAIN QUERY PLAN` on actual generated bounded metadata SQL;
- Playwright desktop/mobile/tablet with keyboard, touch, Axe, console/network
  and screenshot review;
- exact diff/stat/status/untracked/secrets/debug/formatting review;
- rollback is revert of Task 103 commit: no schema/data/cache/provider action is
  needed, existing card/service contracts remain available.
