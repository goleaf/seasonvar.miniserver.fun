# Task 109 — compliance matrix восстановления иерархии подборок

Обновлено: 27.07.2026.

| Requirement | Status | Evidence |
|---|---|---|
| Root `AGENTS.md`, requirement index и canonical owners прочитаны | `completed` | `docs/requirements/*`, architecture, views, frontend, UI, performance, caching и collection plans/specs |
| Фактические framework/runtime/packages проверены | `completed` | PHP 8.5.8, Laravel 13.22.0, Livewire 4.3.3, Boost 2.4.13, Tailwind CSS 4.3.2, Vite 8.1.4 |
| Version-specific Laravel/Livewire/Tailwind docs проверены | `completed` | Laravel Boost: Livewire actions/testing/security и Tailwind responsive mobile-first contract |
| Existing implementation и history проверены | `completed` | Component, Blade, category query, feature/browser tests и file history |
| Production root cause воспроизведён read-only | `completed` | HTTP `200`; 5 roots/31 children существуют, все hidden из-за zero-count filter |
| Design и implementation plan подготовлены | `completed` | Task-specific design и executable plan |
| Exclusive lease и exact paths | `completed` | Existing owner lease `task-108-complete-seasonvar-importer` продолжен для follow-up, manifest расширен до first edit |
| Canonical persistent UI rule обновлён до implementation | `completed` | `docs/frontend.md`, `docs/views.md`, `docs/performance.md`, `docs/UI_STANDARDS.md` |
| TDD RED до production code | `completed` | Новый hierarchy test сначала упал на отсутствующем marker, затем прошёл с 12 assertions |
| Full-page Livewire и query-free Blade boundary | `already_compliant` | Existing `CatalogDiscoveryPage`/`CatalogCollectionExplorer`; DB query в Blade не добавляется |
| Routes/query keys/normalization backward compatible | `completed` | Existing hidden names, Livewire URL attributes, normalize tests и browser URL assertions |
| Authorization/privacy/public eligibility | `already_compliant` | Read-only active dictionary; collection public scope не меняется |
| Database/migrations/cache keys/dependencies | `not_applicable` | Изменения не требуются |
| RU/EN translation parity | `already_compliant` | Новых user-facing strings и keys нет |
| Responsive/a11y/browser behavior | `completed` | Production desktop/mobile: HTTP `200`, H1, 5 roots, 31 children, overflow `0`, strict isolated errors `[]` |
| PHP style, focused tests и Vite build | `completed` | Pint green, PHPStan green с `512M`, collection/discovery `165/165` и `985` assertions, Vite/player release ready |
| Legacy/duplicate/dead implementation scan | `completed` | Removed select/subcategory presenter references отсутствуют в active app/view/test paths; исторические plans сохранены |
| README/CHANGELOG/canonical docs | `completed` | Отдельные русские записи от 27.07.2026 и current canonical contract |
| Commit/push в `main` | `unresolved` | Shared index уже содержит foreign Task 107 staged paths; повторная exact check обязательна |
| Полный existing collection browser scenario | `unresolved` | Новые assertions проходят, final guard падает только на соседнем `/pwa/posters/browser-smoke` `404` desktop/mobile |

## Cross-feature impact

| Domain | Status | Evidence |
|---|---|---|
| Authentication/authorization | `already_compliant` | Guest-readable category dictionary, no writes |
| Translations | `already_compliant` | Existing `collections.directory.*` labels reused |
| Caching | `already_compliant` | No key changes; page response observed `no-store` |
| Search/pagination | `completed` | Existing collection directory/discovery interaction/query-budget tests green |
| SEO | `completed` | Unified discovery canonical/noindex suite green |
| Mobile/accessibility | `completed` | Visible nested list at 390px and 1440px; zero nodes use noninteractive rows, overflow `0` |
| Administration/imports | `not_applicable` | Dictionary management and importer behavior unchanged |
| Privacy/security | `already_compliant` | No collection metadata beyond active public taxonomy/counts |

## Protected files и contracts

- `routes/web.php`, `routes/api.php`;
- database migrations and `catalog_collection_category_id`;
- collection visibility/moderation/quality eligibility;
- `CatalogCollectionCategoryQuery::publicDirectoryTree()` return shape;
- public collection detail/profile/API/sitemap boundaries;
- existing Task 107 staged paths and all Task 108 importer changes.
