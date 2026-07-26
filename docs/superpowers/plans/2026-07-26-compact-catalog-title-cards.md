# План компактных карточек каталога

Дата: 26.07.2026.

Design:
[`2026-07-26-compact-catalog-title-cards-design.md`](../specs/2026-07-26-compact-catalog-title-cards-design.md).

## Рабочий checklist

| Priority | Статус | Что и почему | Файлы / зависимости | Риски | Проверка |
| --- | --- | --- | --- | --- | --- |
| critical | completed | Заново прочитать `AGENTS.md`, requirement index и применимые owners, чтобы не нарушить постоянные contracts | `AGENTS.md`, `docs/requirements/*`, architecture/UI/frontend/performance/cache/security/ops/maintenance/testing/development | Пропустить более новое правило | Read evidence и task matrix |
| critical | completed | Зафиксировать branch, HEAD, remote и чужие изменения, чтобы не смешать scope | Git metadata, shared worktree | Большой foreign dirty tree | `git status --short --branch`, `git rev-parse HEAD`, `git remote -v` |
| critical | completed | Проверить установленные версии и официальную документацию, чтобы использовать Laravel 13/Tailwind 4 API | Composer/npm, Laravel Boost, Context7 | Версионно неверный helper/class | Application info и docs search |
| critical | completed | Проследить все consumers общей карточки и data loaders, чтобы изменение работало на трёх обязательных поверхностях | `TitleCard`, Blade layouts, page builders, recommendation DTO/presenter | Частичный page-only fix | Repository usage map |
| critical | completed | Обновить конфликтующий canonical UI contract до production code | `docs/UI_STANDARDS.md`, `docs/frontend.md`, `docs/architecture.md` | CSS-only скрытие полного текста | Canonical reread и docs search |
| critical | completed | Описать дизайн, exact files, protected contracts, cross-feature impact и rollback | design, this plan, `current-task-plan.md` | Scope drift | Self-review links/matrices |
| critical | completed | Написать RED tests на bounded escaped excerpt, явную ссылку, metadata и одну reason | component/page/recommendation tests | Хрупкие проверки markup | Первый focused run дал 3 ожидаемых failures на отсутствующем contract |
| critical | completed | Реализовать query-free presentation projection в class component | `TitleCard.php`; Laravel `Str`, `Number` | Unicode/HTML/nullable rating | Safe excerpt/rating/genre/reason assertions и sparse projection regression |
| high | completed | Перестроить list/recommendation Blade на общий компактный набор данных | two title-card Blade views, existing poster/taxonomy/personal components | Stretched link перекроет action; mobile wrap | DOM assertions и Playwright desktop/mobile/tablet |
| high | completed | Добавить bounded eager-load рейтингов без N+1 | `CatalogTaxonomyRegistry.php`, existing rating relation/index | Лишние колонки/строки, query budget | Один constrained query; `EXPLAIN` использует existing unique index |
| high | completed | Добавить RU/EN parity для «Подробнее» и rating accessibility | `lang/{ru,en}/catalog.php` | Missing key/fallback leak | `TranslationCatalogParityTest` входит в GREEN matrix |
| high | completed | Проверить `/titles`, `/`, detail/discovery recommendations и комбинацию existing pagination/filter/sort | Existing routes/Livewire/page builders | Regression URL state or list ordering | 209-test related matrix и browser navigation |
| high | completed | Проверить отсутствие SQL/Blade/XSS/authorization regressions | component, eager loader, existing route/policy boundary | Lazy load, raw provider HTML, IDOR | Malicious description regression, escaped Blade, no new writes/routes |
| high | completed | Выполнить focused PHPUnit, затем широкий релевантный набор | feature/unit tests | Foreign unfinished tests in shared tree | 211 tests, 75 309 assertions passed |
| high | completed | Выполнить Pint, Larastan/Rector dry-run и Vite build | existing project tooling | Formatter touches foreign files | Exact-file Pint/PHPStan/Rector passed; Vite build passed |
| high | completed | Выполнить Playwright desktop/mobile/no-JS/a11y smoke | `/titles`, `/`, recommendation surfaces | Production data/auth/environment variance | Focused 3/3 desktop/mobile/tablet; catalog geometry passed in broad run |
| medium | completed | Просканировать связанные legacy paths, duplicate card markup, debug/TODO/secrets | whole repository relevant patterns | Удалить ещё используемый contract | Dependency-aware `rg`, diff review |
| medium | completed | Обновить тематические docs, README visitor history и русский CHANGELOG | owners, `README.md`, `CHANGELOG.md` | Смешать foreign hunks | Task-only documentation projection |
| critical | completed | Перечитать требования, проверить compliance matrix и весь task diff | canonical docs, current plan, Git | Ложное `completed` | Evidence links/commands and unresolved list |
| critical | in_progress | Подготовить только task hunks в отдельном index и создать commit в `main` | exact manifest | Захват чужих MM/untracked changes | `git diff --cached`, secret/debug/unrelated scan |
| critical | pending | Выполнить обычный push current `main` в configured upstream | `origin/main` | HTTPS credentials unavailable | Exact command, exit code and error |

## Ожидаемые изменяемые файлы

- `app/View/Components/Catalog/TitleCard.php`;
- `app/Services/Catalog/CatalogHomePageBuilder.php`;
- `app/Services/Catalog/CatalogTaxonomyRegistry.php`;
- `app/Services/Catalog/CatalogTitlesPageBuilder.php`;
- `app/Services/Catalog/CatalogTopListQuery.php`;
- `app/Services/Catalog/Search/CatalogTitleSuggestionQuery.php`;
- `resources/views/components/catalog/title-card-list.blade.php`;
- `resources/views/components/catalog/title-card-recommendation.blade.php`;
- `lang/ru/catalog.php`, `lang/en/catalog.php`;
- focused tests в `tests/Feature`/`tests/Unit`;
- homepage card hydration regression;
- `tests/browser/recommendation-ui.spec.js`;
- canonical UI/frontend/architecture docs;
- linked design/current plan;
- `README.md`, `CHANGELOG.md`.

## Защищённые contracts

- existing `main` branch, routes and route names;
- `/titles` query parameters, filters, sorts and paginator;
- homepage/discovery/title-detail Livewire public state;
- `CatalogRecommendationListItem::reasonLabels` list shape and ranking;
- feedback, personalization, diversity and repeat-suppression services;
- API Resources/JSON shape, SEO/full title descriptions and model data;
- publication/availability/audience/region/Premium/legal scopes;
- poster `2:3`/contain, personal card controls and taxonomy routes;
- existing migrations, indexes, cache keys, environment and dependencies;
- all foreign staged/unstaged/untracked work.

## Explicit non-scope

- schema migration, data cleanup or importer changes;
- new routes/controllers/endpoints;
- recommendation ranking or feedback semantics;
- cache version/TTL/invalidation changes;
- new frontend framework, JavaScript or package;
- mass redesign of unrelated pages.

## Verification evidence

- TDD RED: `CatalogCompactTitleCardTest` дал 3 ожидаемых отказа до появления
  excerpt/rating/single-reason contract; после реализации тот же набор прошёл.
- Итоговая связанная PHPUnit-матрица: 211 тестов, 75 309 утверждений, без
  отказов. Она покрывает общий компонент, `/titles`, главную, рекомендации,
  Top 100, глобальный поиск, query budgets, Blade invariant и RU/EN parity.
- Полный test-only запуск выполнил 1 914 тестов: 1 900 passed, 11 skipped,
  один связанный старый budget-test выявил новые две bounded relation-группы
  и после точного исправления прошёл вместе с поиском/карточкой (9 тестов,
  65 утверждений). Оставшиеся один failure account-session и одна ошибка
  отсутствующего `SeasonvarImportDispatchBatcher` принадлежат параллельному
  shared-tree коду вне Task 73.
- `Pint`, PHP syntax, task-scoped `PHPStan` (0 ошибок) и `Rector --dry-run`
  прошли на изменённых PHP-файлах.
- Focused Playwright прошёл 3/3 на Desktop Chromium, Mobile Chromium и Tablet
  Chromium. Более широкий browser run также подтвердил геометрию `/titles`;
  шесть посторонних fixture assertions для Top 100 и autocomplete остаются
  вне Task 73.
- Rating eager load выполняется одним запросом с полями
  `catalog_title_id/provider/rating`; SQLite выбирает существующий unique
  index `(catalog_title_id, provider)`. Новая migration или index не нужны.
- Стандартный полный PHPUnit process достигает закреплённого в
  `phpunit.xml` накопительного лимита `256M`; отдельный test-only запуск с
  повышенным лимитом используется как диагностический широкий gate и не
  меняет production runtime.
