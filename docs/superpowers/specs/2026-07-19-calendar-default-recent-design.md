# Стартовая страница календаря с реальными релизами

Дата: 19.07.2026

Статус: утверждено пользователем

## Проблема

`/calendar` открывает представление `upcoming`, хотя в рабочих данных нет ни одной подтверждённой будущей даты. Страница отвечает `200`, корректно рендерится Livewire и не имеет browser/runtime ошибок, но показывает только пустое состояние. При этом в `release_schedule_entries` есть 4 342 публичных фактических события за последние 60 дней, и `/calendar/recent` уже выводит их карточками.

Исходный Task 17 сознательно запретил выдумывать даты из `created_at`, `updated_at`, `seasons.release_status_text` и других неоднозначных полей. Это ограничение сохраняется: исправление меняет входной маршрут, а не происхождение или смысл календарных данных.

## Утверждённое поведение

- `/calendar` становится канонической стартовой страницей календаря и показывает представление `recent`.
- `/calendar/upcoming` остаётся отдельной честной страницей будущих событий и может показывать локализованное пустое состояние, пока подтверждённых дат нет.
- Прежний `/calendar/recent` выполняет постоянное перенаправление на `/calendar`, поэтому закладки и внешние ссылки сохраняются без дублирования содержимого.
- Локализованные маршруты повторяют тот же контракт: `/{locale}/calendar`, `/{locale}/calendar/upcoming` и постоянное перенаправление с `/{locale}/calendar/recent`.
- `/calendar/day/{period}`, `/calendar/week/{period}`, `/calendar/month/{period}` и закрытый `/calendar/mine` не меняют семантику.
- Legacy `/schedule` и `/release-calendar` продолжают вести на канонический `/calendar`.

## Маршруты и навигация

Новый route name `calendar.index` принадлежит `/calendar`, а `calendar.upcoming` переносится на `/calendar/upcoming`. Для локализованных страниц используются `localized.calendar.index` и `localized.calendar.upcoming`. Старый recent URL получает отдельное legacy-имя и redirect, чтобы не существовало двух канонических страниц с одним набором карточек.

Главные ссылки календаря в header/footer ведут на `calendar.index`. Внутренняя вкладка «Недавние» тоже ведёт на `calendar.index`, а «Ближайшие» — на `calendar.upcoming`. Контекстные ссылки, которые обещают именно будущие события, например блок ближайших релизов, продолжают использовать `calendar.upcoming`. Уведомления о уже произошедших календарных событиях ведут на стартовый `calendar.index`, где такие события доступны.

## Данные и запросы

`ReleaseCalendarQuery` сохраняет текущие bounded-окна, видимость, фильтры и eager loading. Для стартовой страницы используется существующий `Recent`-запрос со статусом `released` за настроенные `recent_days`; новый источник дат, backfill или изменение импортера не вводятся.

Проверка наличия данных для SEO/sitemap должна использовать ту же публичную visibility boundary и то же bounded recent-окно. Она не должна считать скрытые, удалённые или недоступные media-записи основанием для публикации URL.

## SEO, sitemap и кеш

- Непустой `/calendar` без фильтров и пагинации может быть `index, follow`, иметь ограниченный public `ItemList` и присутствовать в sitemap.
- Пустой `/calendar`, фильтры и последующие страницы остаются `noindex`.
- `/calendar/upcoming` сохраняет self-canonical и может индексироваться только при непустом публичном результате без фильтров; пустая страница остаётся `noindex` и не добавляется в sitemap.
- Redirect `/calendar/recent` исключает duplicate canonical и отдельную cache identity для прежнего URL.
- RU/EN `hreflang` указывает на соответствующие канонические index/upcoming маршруты.
- Существующая `CacheDomain::ReleaseCalendar` и allowlist query-параметров не меняются; route identity автоматически отделяет index и upcoming responses.

## Ошибки и совместимость

Schema guard, локализованные состояния unavailable/query error и validation `404` сохраняются. Роуты остаются full-page Livewire. Доступ, personal state, timezone, translation identity, premium/region visibility, администрация, уведомления и importer facts не ослабляются.

Изменение не создаёт фиктивный контент, не редактирует production data и не выполняет destructive migration. Rollback состоит в возврате прежнего route mapping и ссылок; таблицы и календарные записи не меняются.

## Проверки

- Feature-тест доказывает, что `/calendar` рендерит существующий недавний публичный релиз и активирует представление `recent`.
- Feature-тест доказывает, что `/calendar/upcoming` остаётся отдельным представлением и не подмешивает прошедшие публикации.
- Route-тесты проверяют постоянные redirects с `/calendar/recent` и локализованного аналога, а также генерацию новых canonical URLs.
- SEO/sitemap-тесты проверяют `index/noindex`, canonical, `hreflang` и включение только непустых публичных окон.
- Регрессия проверяется focused PHPUnit, затем полным `php artisan test`, Pint, route/cache/view checks и Vite build из-за изменения публичных маршрутов/Blade assumptions.
- Browser QA проверяет `/calendar`, `/calendar/upcoming`, redirects и RU/EN на desktop и узком viewport, включая console/network errors и горизонтальный overflow.

## Документация и выпуск

Канонический владелец `docs/release-calendar.md` получает новый route/SEO contract. Связанные `docs/frontend.md`, `docs/caching.md`, `docs/performance.md` и sitemap/architecture документы меняются только если их действующие формулировки стали неточными. `README.md` получает понятное посетителю описание и датированную запись, `CHANGELOG.md` — отдельный русский технический пункт. `docs/plans/current-task-plan.md` хранит итоговую compliance matrix и evidence проверок.

