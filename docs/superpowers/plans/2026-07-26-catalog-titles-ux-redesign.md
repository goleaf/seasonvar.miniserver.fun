# План реализации UX-редизайна `/titles`

Дата: 26.07.2026
Статус: completed

Статусы: `pending`, `in_progress`, `completed`, `skipped`, `unresolved`.

## Ожидаемые изменяемые файлы

- `app/Enums/CatalogView.php`;
- `app/Http/Requests/CatalogTitlesRequest.php`;
- `app/Http/Requests/Api/V1/CatalogTitleIndexRequest.php`;
- `app/Livewire/CatalogSeries.php`;
- `app/Livewire/Forms/CatalogSeriesFilters.php`;
- `app/DTOs/CatalogTitlesPageContext.php`;
- `app/Services/Catalog/CatalogTitlesPageBuilder.php`;
- `app/View/ViewModels/CatalogTitlesViewModel.php`;
- `app/View/Components/Ui/PosterCard.php`;
- `app/View/Components/Catalog/TitleCard.php`;
- `resources/views/catalog/titles.blade.php`;
- catalog filter/output/card Blade components;
- `resources/js/mobile-runtime.js`, при необходимости scoped CSS;
- `lang/ru/catalog.php`, `lang/en/catalog.php`;
- focused PHPUnit и Playwright tests;
- `docs/UI_STANDARDS.md`, `docs/frontend.md`, `docs/catalog-search.md`,
  `docs/views.md`, `docs/architecture.md`, `docs/performance.md`;
- `README.md`, `CHANGELOG.md`, task plan/compliance evidence.

## Защищённые contracts

- route names/paths `/titles`, taxonomy/year paths и route model binding;
- все прежние query names/values, filter boolean logic и canonicalization;
- `/api/v1/titles` response/validation/pagination;
- catalog visibility, authorization, SEO/noindex и cache contracts;
- lazy facets, server-side validation and escaped query-free Blade;
- importer/player/recommendations/notifications/private state;
- SQLite compatibility and existing indexes.

## Живой checklist

| Priority | Status | Что и зачем | Файлы/зависимости | Риски | Проверка |
| --- | --- | --- | --- | --- | --- |
| critical | completed | Заново прочитать root/index/canonical/applicable requirements и фактическую реализацию до замены | `AGENTS.md`, `docs/requirements/*`, catalog/UI docs, routes/components/query/tests | пропуск постоянного contract | reading evidence, repository search |
| critical | completed | Проверить runtime, packages, DB, frontend, branch, remote и foreign dirty scope | Composer/npm/Boost/Git | смешанный commit | version/status/remote evidence |
| critical | completed | Зафиксировать approved design, affected files, protected contracts, cross-feature/rollback strategy | design spec, этот план | scope drift | перечитать перед RED |
| critical | completed | Добавить RED tests для grid/list state, сорт-групп, мобильных/desktop landmarks, chips/count и compatibility | request/view-model/page tests | тест markup вместо поведения | focused RED зафиксировал отсутствующие contracts; GREEN входит в итоговые 218 + 2 теста |
| critical | completed | Ввести enum-backed `grid|list`, URL normalization, Livewire action и query preservation | enum, request, form, component, DTO/builder/view-model | API contract или canonical duplication | web request/Livewire/API regressions прошли |
| high | completed | Перестроить `/titles`: heading/search, desktop sticky sidebar, mobile trigger/filter page, output toolbar, active chips | main Blade + new components | nested scroll, duplicate H1, lost state | semantic assertions + Chromium desktop/mobile/tablet |
| high | completed | Сгруппировать 4 primary sort и остальные варианты, сохранив все enum values | view-model/output component/translations | скрытая active option | все прежние enum values сохранены; текущая подпись видима |
| high | completed | Свернуть alphabet и разделить Cyrillic/Latin; mobile alphabet inside filters | alphabet/output controls | letter state loss | unit/feature + 6 Playwright matrix |
| high | completed | Сделать filter sidebar: основные группы сразу, secondary disclosure, mobile apply/reset/full-width flow | unified/title filters | потеря редкого фильтра, stale facets | advanced-filter suite + browser flow |
| high | completed | Добавить реальные grid/list cards; grid без description и с poster 2:3/max 2 genres | title/poster components, builder select | N+1, layout overflow | select/card/query-budget tests + screenshots |
| critical | completed | Подтвердить validation: empty/zero/min/max/impossible ranges/combined filters/sort/page/view | Request/query tests | invalid state reaching SQL | итоговый catalog scope: 218 тестов, 1 871 утверждение |
| critical | completed | Проверить authorization/security/privacy/XSS/CSRF/IDOR/rate/resource boundaries | request/query/Blade/routes | contract regression | read-only boundary не расширен; escaped Blade, enum/Request input, API isolation |
| high | completed | Профилировать SQL/query count; подтвердить no N+1, bounded select, no unjustified index | query/builder/SQLite EXPLAIN | premature DDL/cache | query budgets и FTS EXPLAIN прошли; grid не выбирает description |
| high | completed | Проверить loading/empty/error/disabled/history/back-forward/reset/double-submit/accessibility | Livewire/Blade/JS | JS-only dead control | PHPUnit + Chromium/Axe; исправлены lazy open-intent и accessible name |
| medium | completed | Удалить legacy duplicate controls/dead markup/debug imports и проверить naming/types/PSR-12 | touched scope | unrelated refactor | repository scan, Pint, PHPStan и Rector |
| high | completed | Обновить canonical docs, README visitor history и русский CHANGELOG | docs/README/CHANGELOG | conflicting foreign hunks | task-specific documentation подготовлена |
| critical | completed | Выполнить focused tests, Pint, relevant wider/full tests, static analysis, build and browser QA; исправлять причины | test/build tooling | false green from skipped command | 218 + 2 scoped PHPUnit GREEN; PHPStan 0; Rector 0; Pint/build/6 browser GREEN; полный suite имеет отдельно классифицированные foreign failures |
| critical | completed | Повторно прочитать requirements/spec/plan; проверить diff/stat/status/untracked/staged/secrets/debug/mass formatting | repository + isolated index | foreign scope in commit | task-only 41-file index проверен без секретов/debug/unrelated paths |
| critical | completed | Commit разрешённых Task files только в existing `main` | Git hooks | dirty shared tree | `6660dac7f27b1cc22b31ffc1d87436811274c409` |
| critical | unresolved | Обычный push текущего `main`; внешний auth отказ записать `unresolved` | configured `origin` | auth/protected branch | exit 128: GitHub HTTPS username unavailable; передача не началась |

## Cross-feature impact

| Domain | Статус | Решение |
| --- | --- | --- |
| Public catalog UX/mobile/accessibility | affected | requested layout, single document scroll, 44px/focus contracts |
| Search/filter/sort/pagination/history | protected_critical | same request/query engine plus enum-backed view state |
| API | protected_critical | UI-only view is removed from API rules/input |
| SEO/canonical/noindex | protected_high | same semantic filter/query contracts; view is presentation-only |
| Queries/performance | affected | omit description in grid; retain eager/count/lazy facet boundaries |
| Authentication/authorization/privacy | already_compliant | read-only public visibility and user-aware loaders unchanged |
| Cache | compatible | no new cache key or invalidation owner |
| Database/schema/index/data | not_applicable | no DDL/DML or index justified |
| Translations | affected | RU/EN parity for controls |
| Importer/player/notifications/recommendations | not_applicable | no owner or public contract change |
| Production/build | affected | Vite/PHP code deploy only; rollback by revert |
| Dependencies/environment | not_applicable | no package or env change |
| Shared Git state | critical_risk | foreign staged/unstaged files preserved; stage only exact task hunks |

## Requirement-compliance matrix

| Requirement | Status | Evidence / gate |
| --- | --- | --- |
| Root/index/canonical fresh read | completed | 26.07.2026 before edits |
| Applicable Markdown fresh read | completed | catalog search, UI, frontend, views, architecture, security, performance, caching, maintenance/production/integration |
| Actual versions and official docs | completed | PHP 8.5, Laravel 13.22, Livewire 4.3, Tailwind 4.3, SQLite; Boost docs checked |
| Existing implementation first | completed | route, Livewire form/actions, Request, builder/query/facets, view-model, cards, JS and tests traced |
| Design/alternatives/risks/rollback | completed | linked design; full-page flow chosen over nested modal scroll |
| Prepared plan reread | completed | выполнено перед focused RED |
| TDD RED/GREEN | completed | новые request/view-model/page contracts сначала упали, затем вошли в 218 + 2 GREEN |
| Validation/query compatibility | completed | 218 связанных тестов / 1 871 утверждение; API state не изменён |
| Security/authorization/privacy | completed | read-only public policy scopes сохранены; Request/enum/escaped Blade; новых write boundary нет |
| Performance/EXPLAIN | completed | query budgets/FTS EXPLAIN прошли; grid select исключает `description`; новых индексов нет |
| Database migration/index | not_applicable | no new query predicate or schema needed |
| Browser/accessibility matrix | completed | 6/6 финальных desktop/mobile/tablet сценариев; без overflow/console/page/asset и серьёзных Axe ошибок |
| Canonical docs/README/CHANGELOG | completed | task-specific canonical и visitor/technical history обновлены |
| Final requirements/legacy scan | completed | requirements/spec/plan reread; task-only diff/secret/debug/legacy scan выполнен |
| Commit/push `main` | unresolved | commit `6660dac7f27b1cc22b31ffc1d87436811274c409`; ordinary push exit 128 из-за отсутствующей GitHub HTTPS-аутентификации |
