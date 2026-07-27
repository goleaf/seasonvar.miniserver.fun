# Task 109 — evidence восстановления иерархии подборок

Дата: 27.07.2026.

## Результат

`https://seasonvar.miniserver.fun/discover/popular#collections` снова
показывает полный активный двухуровневый справочник: 5 категорий и 31
подкатегорию на desktop и mobile. Нулевые пункты видимы как честные
неинтерактивные строки, а пункты с доступными подборками остаются
Livewire-фильтрами.

## Root cause

- В production 1 403 подборки и 5/31 active taxonomy nodes.
- Категория пока не назначена ни одной подборке, поэтому grouped public count
  каждого узла равен `0`.
- `CatalogCollectionExplorer` фильтровал root/child по `count > 0`, поэтому
  SSR оставлял только «Все категории (0)».
- Маршрут, schema, translations, query object и Livewire mount были исправны.

## Реализация

- Presenter сохраняет полный active tree, добавляет scalar `is_filterable` и
  не выполняет новых запросов.
- `selectCategory()` принимает недоверенные root/child slugs, повторно
  нормализует их через `CatalogCollectionCategoryQuery` и сбрасывает только
  `collectionsPage`.
- Blade рендерит один семантический nested list на всех ширинах. Положительные
  узлы имеют action target не меньше 44px, нулевые являются обычными rows.
- Search, sort, cards, pagination, route/query names, schema/data, public
  eligibility, API, SEO и cache keys не менялись.

## TDD и automated verification

| Check | Result |
|---|---|
| New hierarchy RED | Expected failure: отсутствовал `data-collection-category-tree` |
| New hierarchy GREEN | `1/1`, 12 assertions |
| Focused discovery/category matrix | `47/47`, 244 assertions |
| Broad collection/discovery matrix | `165/165`, 985 assertions |
| Pint | Passed for component и feature test |
| PHPStan | Passed for component с `memory_limit=512M`; default `128M` exhausted before analysis result |
| Vite/player release | Vite 8.1.4 build passed; release `ready=true`, 29 sources, 19 assets |
| Production SSR | HTTP `200`; 5 unique category markers, 31 unique subcategory markers |
| Isolated production browser | Desktop 1440×1200 и mobile 390×844: status `200`, correct H1/tree, overflow `0`, errors `[]` with service worker blocked |

## Browser baseline observation

Общий существующий `tests/browser/discovery-collections.spec.js` на Desktop
Chromium и Mobile Chromium дошёл до финального guard после прохождения новых
hierarchy/count/URL/responsive assertions. Оба проекта упали только из-за
двух одинаковых `404` на `/pwa/posters/browser-smoke`. Отдельный production
запуск с включённым service worker также получил console error о `404` script
fetch; при изоляции страницы с `serviceWorkers: 'block'` ошибок нет. PWA route
не изменялся и не маскировался этой задачей.

## Visual evidence

- `output/playwright/task-109-discovery-collections-before-desktop.png`
- `output/playwright/task-109-discovery-collections-before-mobile.png`
- `output/playwright/task-109-discovery-collections-after-desktop.png`
- `output/playwright/task-109-discovery-collections-after-mobile.png`

## Documentation

Обновлены canonical frontend/view/performance/UI contracts, visitor summary и
история `README.md`, техническая запись `CHANGELOG.md`, task design, plan и
compliance matrix. Исторические записи о прежнем zero-count behavior не
переписывались.

## Delivery

Branch `main` сохранена. Exact Task 109 commit/push остаётся `unresolved`:
shared index уже содержит 74 staged paths, включая overlapping
`docs/UI_STANDARDS.md` и `docs/plans/current-task-plan.md` прежних задач;
`verify-paths` закономерно отклоняет этот index. Во время verification также
появился отдельный вне scope diff `composer.lock`, а configured HTTPS remote
не имеет credential helper. Foreign changes не reset, не unstage и не
включаются в Task 109.
