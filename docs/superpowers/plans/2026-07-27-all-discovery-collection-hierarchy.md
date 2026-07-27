# План единой иерархии подборок во всех discovery modes

Дата: 27.07.2026.

**Цель:** показать существующий полный список категорий и подкатегорий на
каждом поддерживаемом `/discover/{type}` без дублирования explorer или
изменения recommendation ranking.

**Стек:** Laravel 13.22, Livewire 4.3, Blade, Tailwind CSS 4.3, PHPUnit 12.5,
Playwright.

## Task 1: Закрепить постоянный contract

**Files:**

- Modify: `docs/requirements/system-wide-integration.md`
- Modify: `docs/architecture.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/caching.md`
- Modify: `docs/performance.md`
- Modify: `docs/README.md`

- [x] Заменить popular-only rule на один explorer во всех девяти modes.
- [x] Зафиксировать anchors, независимый URL state и fixed bounded overhead.
- [x] Добавить Task 110 в карту владельцев документации.

## Task 2: Написать regression RED

**Files:**

- Modify: `tests/Feature/UnifiedDiscoveryCollectionsTest.php`
- Modify: `tests/Feature/CatalogDiscoveryLayoutTest.php`
- Modify: `tests/Feature/CatalogDiscoveryQueryBudgetTest.php`

- [x] Проверить explorer и category tree во всех девяти modes.
- [x] Проверить localized non-popular route.
- [x] Проверить `#popular-titles` и общий `#discovery-titles`.
- [x] Проверить bounded full-page SQL на non-popular mode.
- [x] Запустить focused tests и подтвердить ожидаемый RED до production code.

## Task 3: Реализовать минимальный parent fix

**Files:**

- Modify: `app/Livewire/CatalogDiscoveryPage.php`
- Modify: `resources/views/livewire/catalog-discovery-page.blade.php`

- [x] Всегда передавать section navigation.
- [x] Добавить prepared mode-aware results anchor.
- [x] Сформировать стабильный child key из mode+locale.
- [x] Монтировать ровно один существующий explorer до serial results.
- [x] Запустить focused GREEN.

## Task 4: Browser contract

**Files:**

- Modify: `tests/browser/discovery-collections.spec.js`

- [x] Проверить tree/order/anchors в `random` и `editorial`.
- [x] Проверить localized non-popular mode.
- [x] Проверить mobile/desktop overflow.
- [x] Зафиксировать существующий browser guard failure только на соседнем
  `/pwa/posters/browser-smoke`.

## Task 5: Документация и verification

**Files:**

- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: task plan/compliance/evidence files

- [x] Запустить related PHPUnit matrix и query-budget checks.
- [x] Запустить task-scoped Pint, static checks и `npm run build`.
- [x] Выполнить Playwright и production SSR smoke.
- [x] Перечитать канонические требования и проверить README last-H2 rule.
- [x] Выполнить targeted legacy/duplicate scan.
- [x] Обновить evidence и compliance statuses.

## Task 6: Exact delivery

- [x] Проверить `git status --short --branch` и branch `main`.
- [x] Сравнить exact task paths с lease manifest.
- [x] Не изменять и не смешивать foreign staged changes.
- [x] Сохранить `unresolved`: `verify-paths` отклоняет существующий shared
  index, поэтому `approve-index`, commit и push небезопасны.
