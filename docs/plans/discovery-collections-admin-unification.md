# План объединения discovery, подборок и управления каталогом

Дата: 19.07.2026

Статус: `completed_locally`; финальная отправка в Git проверяется отдельно

Владелец требований: `docs/requirements/system-wide-integration.md`

## Цель

Объединить `/discover/popular` и `/collections` в полноценную страницу `/discover/popular`, а управление каталогом и подборками — в `/admin/catalog`. Старые directory-маршруты удаляются без редиректов. Карточки, поиск, сортировка, пагинация, персональные действия, локализация, SEO, кеширование и административные операции сохраняются.

## Решения

- Каноническая публичная точка: `/discover/popular`, подборки — секция `#collections`.
- Каноническая административная точка: `/admin/catalog` с секциями каталога и подборок в одном full-page Livewire-компоненте.
- `/collections`, локализованный directory-route, `/lists`, `/selections`, `/recommendations`, `/discover` и `/admin/collections` удаляются; fallback возвращает `404`.
- `/collections/{slug}`, обложки, профили, пользовательская панель и read-only API сохраняются.
- Зелёная рамка устраняется только у программно фокусируемого `<main>`, остальные focus-visible индикаторы сохраняются.
- Popularity использует групповые агрегаты вместо четырёх коррелированных подзапросов на каждый тайтл.

## Этапы

1. `completed` — воспроизвести задержку и UI-дефекты, получить RED-тесты.
2. `completed` — оптимизировать popularity и исправить обложки/focus.
3. `completed` — встроить публичные подборки в discovery.
4. `completed` — объединить административные менеджеры.
5. `completed` — удалить маршруты, редиректы, классы, views и мёртвые контракты.
6. `completed` — синхронизировать навигацию, SEO, sitemap, кеш и warm targets.
7. `completed` — выполнить legacy search, Pint, tests, build и browser QA.
8. `completed` — обновить канонические документы, README, CHANGELOG и compliance matrix.

## Cross-feature impact

| Область | Влияние и проверка |
|---|---|
| Authentication / authorization | Публичные действия и `manage-catalog` сохраняются; HTTP-тесты гостя и администратора |
| Translations | Русский UI и локализованный discovery; RU route assertions |
| Caching / warming | Независимые collection query keys и инвалидация discovery; policy tests |
| Search / pagination | `collections_q`, `collections_sort`, `collectionsPage`; Livewire/HTTP tests |
| SEO / sitemap | Один directory canonical, detail canonical сохраняется; sitemap tests |
| Mobile / accessibility | Компактная сетка и точечный focus fix; build/browser QA |
| Administration / audit | Старые manager-операции внутри одного shell; admin tests |
| Imports / premium / regional / legal | `not_applicable`; границы не меняются, regression suite |

## Compliance matrix

| Требование | Статус | Доказательство |
|---|---|---|
| Отдельный план | `completed` | Этот файл |
| Одна публичная страница | `completed` | Route inventory содержит только `/discover/{type}` и localized variant; `UnifiedDiscoveryCollectionsTest` и HTTPS подтверждают embedded explorer на `popular` |
| Одна административная страница | `completed` | `/admin/catalog` принадлежит `CatalogAdministrationPage`; `section=collections` монтирует прежний manager, `/admin/collections` отсутствует |
| Старые URL удалены без редиректов | `completed` | PHPUnit и прямой HTTPS вернули `404` без `Location` для directory/default/alias URL; общий unknown fallback также `404` |
| Detail/API сохранены | `completed` | Route inventory сохранил detail, localized detail, cover, private/profile и три read-only API contracts; focused regression suite зелёный |
| Зелёная рамка удалена | `completed` | CSS contract и Chromium desktop/mobile: `<main>` остаётся `:focus-visible`, но computed `outline: none`, `box-shadow: none`; controls не затронуты |
| Медленная загрузка исправлена | `completed` | Четыре per-title correlated subquery заменены grouped aggregates; прежний запрос не дал first byte за 120 s, новый cold rebuild — 6,163 s, cache HIT — 0,114 s |
| Legacy удалён после dependency search | `completed` | Классы, view, responder, routes, duplicate navigation/SEO/sitemap/cache/warm/translation contracts отсутствуют; оставшиеся имена существуют только в regression evidence и документации удаления |
| README/документация актуальны | `completed` | Проверены и обновлены README visitor history, CHANGELOG, canonical integration/architecture/admin/views/performance/cache/frontend/audit/maintenance owners |
| Data safety / rollback | `already_compliant` | Миграций нет; откат scoped commits + `route:cache` + Vite build |
| Authentication / privacy / premium / region / legal | `already_compliant` | Server-side collection policies/gate, visibility query и private `no-store` detail/actions сохранены; новые entitlement или client-trusted states не добавлялись |
| Imports / notifications / search | `already_compliant` | Import source-sync, direct collection detail and notification targets сохранены; portal/header/title search ведут в `#collections` единой страницы |
| Production dependencies / runtime / database | `not_applicable` | Зависимости, `.env`, schema и persistent rows этой задачей не менялись |
| Full repository suite | `unresolved` | Финальный прогон после task fixes: 1 304 tests, 1 280 passed, 119 587 assertions, 11 skipped и 13 failures только в параллельно изменяемых help/player/OpenAPI/infrastructure/user-portal contracts; unified discovery, collection, fallback, cache, sitemap и demo проверки в этом же состоянии проходят |
| Commit / push | `unresolved` | Scoped task commits существуют в `main`; фактический результат настроенной отправки фиксируется только после финальной попытки |

## Verification evidence

- Направленный финальный HTTP/SQL/UI набор: 27 tests, 204 assertions — passed.
- Дополнительные collection/cache/sitemap/job regression: 27 tests, 191 assertions — passed.
- Demo collection corpus: 4 tests, 5 575 assertions — passed.
- Архитектурные Blade/visual проверки после удаления infrastructure call и text truncation: 42 tests, 345 assertions — passed.
- `Pint --test --format agent` для task scope — passed; `npm run build` — 23 modules, Vite 8.1.4.
- `route:cache` и `view:cache` — passed; route inventory не содержит `/admin/collections` или отдельного collection directory.
- Chromium 1440×1100 и 390×844: status 200, один `<h1>`, 12 collection cards, working search/empty state; zero horizontal overflow, broken images, console/page/first-party response errors.

## Production verification и rollback

- Схема и данные БД не меняются.
- После выкладки: `200` для discovery, авторизованного admin, detail/API; `404` для удалённых directory URL.
- Проверяются холодный/прогретый TTFB, обложки, console/network errors и мобильная сетка.
- Откат: возврат единого commit, `php artisan route:cache`, rebuild assets.
