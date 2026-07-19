# План объединения discovery, подборок и управления каталогом

Дата: 19.07.2026  
Статус: `in_progress`  
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
2. `in_progress` — оптимизировать popularity и исправить обложки/focus.
3. `pending` — встроить публичные подборки в discovery.
4. `pending` — объединить административные менеджеры.
5. `pending` — удалить маршруты, редиректы, классы, views и мёртвые контракты.
6. `pending` — синхронизировать навигацию, SEO, sitemap, кеш и warm targets.
7. `pending` — выполнить legacy search, Pint, tests, build и browser QA.
8. `pending` — обновить канонические документы, README, CHANGELOG и compliance matrix.

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
| Одна публичная страница | `unresolved` | Финальные HTTP/Livewire-тесты |
| Одна административная страница | `unresolved` | Финальные admin-тесты |
| Старые URL удалены без редиректов | `unresolved` | Route list и 404 assertions |
| Detail/API сохранены | `unresolved` | Regression tests |
| Зелёная рамка удалена | `unresolved` | CSS assertion и browser QA |
| Медленная загрузка исправлена | `unresolved` | SQL shape и TTFB |
| Legacy удалён после dependency search | `unresolved` | Финальный `rg` |
| README/документация актуальны | `unresolved` | Documentation check |
| Data safety / rollback | `already_compliant` | Миграций нет; откат commit + `route:cache` + Vite build |

## Production verification и rollback

- Схема и данные БД не меняются.
- После выкладки: `200` для discovery, авторизованного admin, detail/API; `404` для удалённых directory URL.
- Проверяются холодный/прогретый TTFB, обложки, console/network errors и мобильная сетка.
- Откат: возврат единого commit, `php artisan route:cache`, rebuild assets.
