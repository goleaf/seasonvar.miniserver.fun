# Design: новая карточка сериала для grid/list

Дата: 26.07.2026.

## Цель

Сделать сериал главным элементом каталога: постер, название, краткая
идентификация и один очевидный следующий шаг. Убрать конкуренцию тегов,
описаний и вторичных счётчиков, сохранив реальные библиотечные и
recommendation actions на клавиатуре и touch.

## Подтверждённое исходное состояние

- Один canonical Blade class component `TitleCard` уже обслуживает семь
  layouts; `grid` и `list` используются в `/titles`, а `compact` разделяет
  старый list-template с другими страницами.
- `/titles` принадлежит full-page Livewire `CatalogSeries`; page builder
  заранее загружает genres/ratings и grouped counts/user state без Blade SQL.
- Grid показывает rating в теле, taxonomy pills и counts, но не имеет
  watch/library actions. List показывает три genres, pills и только details.
- Library writes и recommendation feedback уже имеют canonical services,
  policies, transactions, rate limit и invalidation. Создавать второй write
  boundary нельзя.
- Country, age rating и recent season status не входят в текущую card
  projection. Три новых eager relations увеличили бы query budget.

## Решение

### Presentation

- `grid`: poster 2:3, optional «Новая серия» и `18+`, ровно один rating,
  title максимум две строки, original title одна, `year · seasons`, максимум
  два genre links, visible watch/library/menu actions.
- `list`: poster 2:3, title/original, одна metadata строка
  `year · country · seasons · episodes`, отдельная строка IMDb/КиноПоиск,
  максимум два genre links, description максимум три строки и visible
  watch/library/details/menu actions.
- `compact` получает собственный template с прежним presentation contract,
  поэтому поиск, подборки и recommendation consumers не меняются случайно.
- `PosterCard` получает optional named media overlay slot. Это additive Blade
  contract; существующие consumers без slot не меняются.

### Actions

- Watch является обычной ссылкой на реальный resume/play URL; при отсутствии
  media fake action не выводится.
- Library button передаёт только title ID и желаемое boolean-state.
  `CatalogSeries` нормализует input, повторно получает title через
  `CatalogTitleQuery::visibleTo()` и вызывает `CatalogUserStateService`.
- «Не интересует» находится в native `details` menu. В нём доступны только
  существующие non-subject reasons; subject IDs не принимаются. Запись идёт
  через `CatalogRecommendationFeedbackService`.
- Guest получает ссылку на login. Verified/entitled access остаётся под
  `CatalogTitlePolicy::interact`; hidden/tampered title ID возвращает 404.
- Все действия постоянно существуют в DOM и доступны focus/touch; hover
  меняет только presentation. На mobile menu становится bounded bottom sheet.

### Metadata projection

`CatalogTitleCardMetadataLoader` выполняет один `UNION ALL` запрос только по
ID текущей страницы (не более 96):

- ratings IMDb/КиноПоиск;
- алфавитно первая country для list;
- exact `18+` relation;
- recent regular published/available season за семь календарных дней,
  исключая будущие даты.

Для `/titles` этот запрос заменяет отдельный rating eager-load, поэтому
количество SQL не растёт. Genres остаются единственной Eloquent card relation.
Существующие title-first pivot/rating/season indexes проверяются реальным
`EXPLAIN QUERY PLAN`; migration не добавляется без доказанной необходимости.

### Failure behavior

- Invalid scalar/boolean/reason: localized component error, без write.
- Guest: navigate redirect на login.
- Hidden title: 404, без утечки identity.
- Policy denial: server-side denial, без state change.
- Domain validation/schema unavailability: существующее безопасное
  recommendation message.
- Unexpected exception: report safe exception и localized generic message;
  client input, secrets и private URLs не логируются.

## Отклонённые варианты

- Новая Vue/Alpine card system: второй frontend boundary и dependency не нужны.
- Eager-load countries, age ratings, seasons и ratings отдельно: добавляет
  несколько запросов и ломает measured `/titles` budget.
- CSS-only hover buttons: недоступны keyboard/touch.
- Generic direct «Не интересует» без причины: обходит существующую reason
  taxonomy и ухудшает recommendation signal.
- Отдельный repository/controller для карточки: дублирует существующие
  Laravel owners без практической пользы.

## Совместимость, rollout и rollback

Routes, query string, models, schema, API, cache keys и other card layouts
сохраняются. Rollout — обычный code/assets deploy. Rollback — revert Task 103
commit; data migration, cache flush, queue restart и provider action не нужны.
