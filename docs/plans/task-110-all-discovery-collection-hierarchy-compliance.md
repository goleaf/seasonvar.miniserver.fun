# Task 110 — compliance matrix единой иерархии discovery

Обновлено: 27.07.2026.

| Requirement | Status | Evidence |
|---|---|---|
| Root `AGENTS.md`, requirement index и canonical owners прочитаны | `completed` | Системные, multilingual, maintenance, production, architecture, frontend/view/UI/cache/performance документы |
| Фактические framework/runtime/packages проверены | `completed` | PHP 8.5, Laravel 13.22.0, Livewire 4.3.3, Boost 2.4.13, PHPUnit 12.5.32, Tailwind CSS 4.3.2 |
| Version-specific Livewire/Tailwind docs проверены | `completed` | Stable nested component keys, URL state/history и mobile-first wrapping через Laravel Boost |
| Existing implementation/history/production проверены | `completed` | Popular-only parent condition; production: `popular` 5/31, остальные modes 0/0 |
| Design и executable plan подготовлены | `completed` | Task-specific spec и plan |
| Exclusive lease и exact paths | `completed` | Existing owner lease продолжен; manifest расширен до первой правки и исключает foreign `composer.lock` |
| Новое постоянное правило сначала обновлено у canonical owner | `completed` | `docs/requirements/system-wide-integration.md` и связанные owners |
| TDD RED до production code | `completed` | Первый `personalized` ожидаемо упал на отсутствующей section navigation до app changes |
| Full-page Livewire и query-free Blade boundary | `already_compliant` | Existing `CatalogDiscoveryPage` и nested `CatalogCollectionExplorer` |
| Routes/query keys/normalization backward compatible | `completed` | Focused и broad discovery/collection suites green; popular anchors сохранены |
| Authorization/privacy/public eligibility | `already_compliant` | Read-only public explorer и существующие public scopes не меняются |
| Database/migrations/dependencies/permissions/imports | `not_applicable` | Изменения не требуются |
| Cache keys/invalidation | `already_compliant` | Existing `CatalogPages` invalidation; новых keys нет |
| RU/EN translation parity | `already_compliant` | Используются существующие labels |
| Responsive/a11y/browser behavior | `completed` | Production Chromium 18/18, localized EN, 5/31, overflow `0`, errors `0` |
| PHP style, focused tests, static/build checks | `completed` | Focused `22/22`, broad `157/157`; Pint, app PHPStan, Vite/player release green |
| Legacy/duplicate/dead implementation scan | `completed` | Active app/view/tests/canonical owners не содержат popular-only mount contract; historical plans сохранены |
| README/CHANGELOG/canonical docs/evidence | `completed` | Отдельные русские записи, Task 110 map/plan/spec/matrix/evidence |
| Commit/push в `main` | `unresolved` | Owner verified, branch `main`, но `verify-paths` сообщает несовпадение shared staged set с manifest; index не изменялся |

## Cross-feature impact

| Domain | Status | Evidence |
|---|---|---|
| Authentication/authorization/privacy | `already_compliant` | Guest-readable public taxonomy; writes и personal state не меняются |
| Translations | `already_compliant` | Новых user-facing strings нет |
| Caching | `already_compliant` | Collection state остаётся bypass/noindex variant; mode cache identity уже route-scoped |
| Search/pagination | `completed` | Existing collection state/normalization и query budget green |
| SEO/sitemap | `completed` | Popular и `top_rated` collection-state canonical/noindex assertions green; sitemap unchanged |
| Mobile/accessibility | `completed` | Один nested list, existing 44px actions, desktop/mobile overflow `0` |
| Administration/imports | `not_applicable` | Management, classification и importer не меняются |
| Performance | `completed` | Non-popular page collection SQL ≤12; broad query-budget suite green |

## Browser limitation

Полный `discovery-collections.spec.js` прошёл все Task 110 assertions, но его
финальный общий guard остаётся `unresolved` из-за прежнего соседнего
`404 /pwa/posters/browser-smoke` desktop/mobile. Изолированный production
scenario с service worker blocked завершён без browser errors.

## Expected changed files

- `app/Livewire/CatalogDiscoveryPage.php`
- `resources/views/livewire/catalog-discovery-page.blade.php`
- `tests/Feature/UnifiedDiscoveryCollectionsTest.php`
- `tests/Feature/CatalogDiscoveryLayoutTest.php`
- `tests/Feature/CatalogDiscoveryQueryBudgetTest.php`
- `tests/browser/discovery-collections.spec.js`
- canonical docs, `README.md`, `CHANGELOG.md`, Task 110 plan/spec/matrix/evidence

## Protected contracts

- `routes/web.php`, `routes/api.php` и девять значений
  `CatalogRecommendationType::publicCases()`;
- `/discover/popular#collections`, `#popular-titles`, localized routes;
- `collections_q`, `collections_sort`, `collections_category`,
  `collections_subcategory`, `collectionsPage`;
- recommendation filters/ranking/refresh/paginator;
- collection public eligibility, moderation, quality, API, sitemap и detail;
- Task 107/108 staged changes, Task 109 explorer implementation и foreign
  `composer.lock`.
