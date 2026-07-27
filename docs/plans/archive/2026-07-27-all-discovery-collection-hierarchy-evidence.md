# Task 110 — evidence единой иерархии discovery

Дата: 27.07.2026.

## Результат

Все девять поддерживаемых страниц `/discover/{type}` теперь показывают перед
выдачей сериалов один существующий `CatalogCollectionExplorer`. В production
каждая страница отвечает `200` и содержит полный активный справочник из 5
категорий и 31 подкатегории на desktop/mobile. Локализованный
`/en/discover/random` показывает ту же структуру.

## Root cause

- Сам explorer и Task 109 hierarchy были исправны.
- Parent `CatalogDiscoveryPage::render()` создавал section navigation только
  для `CatalogRecommendationType::Popular`.
- Blade монтировал explorer только внутри проверки этой navigation.
- Indexable non-popular pages дополнительно продолжали выдавать старый
  `STALE` full-page HTML до смены версии `CatalogPages`.

## Реализация

- Parent всегда передаёт две section links и монтирует ровно один explorer.
- `#collections` сохранён для всех modes.
- `popular` сохраняет прежний `#popular-titles`; остальные modes используют
  `#discovery-titles`.
- Nested Livewire key включает mode и locale.
- Collection URL state и paginator остаются независимыми от recommendation
  filters, paginator, refresh и ranking.
- Единственная production-операция — штатный bump `CatalogPages` до версии
  `257`; store-wide flush, database writes и queue/session changes не
  выполнялись.

## TDD и automated verification

| Check | Result |
|---|---|
| Regression RED | Первый `personalized` упал на отсутствующей section navigation |
| Focused GREEN | `22/22`, 278 assertions |
| Broad collection/discovery matrix | `157/157`, 1089 assertions |
| Pint | Passed for four task PHP files |
| PHPStan | Changed application component passed; test extension `assertSeeLivewire()` не распознаётся PHPStan и не является application error |
| Vite/player release | Vite 8.1.4 build passed; release `ready=true`, 29 sources, 19 assets |

## Production и browser verification

- После invalidation все девять SSR responses: HTTP `200`, explorer `1`,
  roots `5`, children `31`; indexable modes получили новый `MISS`, остальные
  сохранили `BYPASS`.
- Изолированный managed Chromium с заблокированным service worker проверил 18
  production страниц (9 modes × 1440×1200/390×844): один H1, один explorer,
  5/31 hierarchy, overflow `0`, browser errors `0`.
- `/en/discover/random` проверен на обеих ширинах.
- Screenshots:
  `output/playwright/task-110-discovery-random-desktop.png` и
  `output/playwright/task-110-discovery-random-mobile.png`.

## Browser baseline observation

Полный `tests/browser/discovery-collections.spec.js` прошёл новые assertions
для `random`, `editorial`, localized non-popular mode, anchors, hierarchy и
responsive layout в Desktop/Mobile Chromium. Его финальный общий guard
остался красным только из-за двух прежних `404` на
`/pwa/posters/browser-smoke` в каждом проекте. Ошибка не относится к
discovery и не маскировалась.

## Совместимость

Routes, schema/data, API, sitemap, translations, permissions, public
eligibility, moderation/quality, recommendation algorithms, cache key format
и invalidators не менялись. Rollback не требует migration/data restore или
cache flush.

## Delivery

Branch остаётся `main`, owner lease подтверждён. Финальный
`verify-paths task-108-complete-seasonvar-importer` отклонил существующий
shared staged set как не совпадающий с declared task paths; `index_approved`
остаётся `no`. Поэтому `approve-index`, commit и push честно остаются
`unresolved`: foreign staged Task 107/108 paths не сбрасывались, не
переставлялись и не включались в небезопасную общую фиксацию.
