# Группировка пакетных событий календаря релизов

Дата: 26.07.2026.

Статус: implemented and verified; delivery pending.

## Цель

Когда несколько episode-bound событий одного сериала публикуются одним
пакетом, публичный и личный HTML-календарь должен показывать одну карточку,
например:

> Осторожно с ангелом — добавлены серии 185–194

Карточка раскрывает точный список входящих событий. Канонические строки
расписания, уведомления, private iCalendar feed, редакторская история и
identity каждого события остаются раздельными.

## Исследованное текущее поведение

- `ReleaseCalendarQuery` пагинирует `release_schedule_entries` по 24 строки и
  строит по одной `ReleaseScheduleCardData` на строку.
- `ReleaseCalendarPage` группирует готовые карточки только по календарной дате.
- Blade выводит каждую серию отдельным `<li>`, поэтому синхронный импорт
  десятков серий создаёт десятки почти одинаковых карточек.
- Карточка использует только `catalogTitle`, номера сезона/серии и scalar
  metadata, хотя запрос дополнительно загружает полные связи сезона, серии и
  media.
- Обычная группировка уже пагинированной коллекции может разорвать одну пачку
  между страницами и оставить неверные `total`/`lastPage`.

## Рассмотренные подходы

### 1. Клиентская группировка Blade/JavaScript

Отклонено. Она получает уже разорванную пагинацией страницу, дублирует
семантику группировки в presentation layer и требует лишнего browser state.

### 2. PHP-группировка после текущего `paginate()`

Отклонено. Решение простое, но пачка из 25 и более событий повторится на
нескольких страницах; страница может визуально содержать одну карточку при
`per_page=24`, а paginator продолжит считать 24 события.

### 3. Двухфазная пагинация групп

Выбрано. Первый bounded SQL query пагинирует стабильные ключи групп. Второй
query одним запросом загружает все события только для групп текущей страницы
и необходимые проекции отношений. PHP-проектор собирает типизированные DTO,
сохраняя порядок первой фазы.

## Правило группировки

События объединяются, только если каждое имеет canonical `episode_id`,
числовой `episode_number` и точный `starts_at`, а также совпадают:

- `catalog_title_id`;
- `season_id` и snapshot `season_number`;
- `entry_type`;
- `status`, `precision` и `is_estimated`;
- точный `starts_at`;
- `date_value`, `date_end`, `release_year`, `release_month`,
  `release_quarter`;
- `translation_name` и `language_code`.

Строка без episode identity, номера или точного времени остаётся отдельной
группой через собственный `id`. Это запрещает склеивать премьеры, события с
неизвестной датой, разные сезоны, разные переводы и разные статусы.

Непоследовательные номера не превращаются в ложный диапазон. Они
форматируются как набор точных диапазонов, например `185–190, 192, 194`.
Повторный номер внутри одной semantic batch показывается один раз и в
summary, и в раскрытом списке: несколько медиавариантов одной серии не
создают ложную пачку. Каждое каноническое событие при этом остаётся
самостоятельной строкой базы, уведомления и iCalendar не объединяются.

## Backend

- `ReleaseCalendarQuery` остаётся единственным page query boundary.
- Общие visibility, period, personal, type/status/title filters применяются
  одинаково к обеим фазам.
- Групповая фаза использует portable `GROUP BY` и только фиксированное
  server-owned SQL-выражение для individual fallback; пользовательский ввод
  в raw SQL не попадает.
- `LengthAwarePaginator` считает карточки-группы, а не исходные строки.
- Новая presentation DTO представляет группу и содержит список существующих
  `ReleaseScheduleCardData`.
- Подписки загружаются одним grouped query для title ID текущей страницы.
- Члены группы загружают только `catalogTitle` и минимальную проекцию
  `episode`; неиспользуемые `season` и `licensedMedia` eager loads удаляются.
- `monthCounts()` продолжает считать канонические события, потому что подпись
  ячейки сообщает количество событий, а не число HTML-карточек.

## Presentation и accessibility

- Одиночное событие сохраняет существующий внешний вид и текст.
- Пачка показывает title и локализованную type-aware фразу с номерами серий.
- Раскрытие использует native `<details>/<summary>`, работает с клавиатуры,
  screen reader и без JavaScript.
- Внутри находится семантический `<ul>` с каждой серией и, если есть,
  названием эпизода.
- Все пользовательские строки добавляются одновременно в `lang/ru` и
  `lang/en`; persisted identity и route state не переводятся.
- Текущие loading, empty, error, subscription, mobile grid, query string,
  back/forward и pagination controls сохраняются.

## Public contracts и cross-feature impact

Сохраняются без изменений:

- все calendar/localized/legacy/admin/feed routes и route names;
- фильтры `type`, `status`, `sort`, `title` и `calendarPage`;
- canonical `ReleaseScheduleEntry` identity, enum values и status rules;
- notification deduplication и payload;
- private ICS event shape и token boundary;
- administration, corrections, importer observers и merge;
- catalog visibility, personal eligibility, SEO canonical/`hreflang`;
- cache keys/invalidation, database schema, API, queue и dependencies.

Изменяются только количество HTML-карточек на странице, paginator total и
SEO `ItemList` projection: одна semantic batch становится одним list item
тайтла, что уже соответствует существующей URL deduplication.

## Ошибки, безопасность и производительность

- Ошибка любой query остаётся в существующем `queryFailed` flow без
  раскрытия SQL или stack trace.
- Blade продолжает использовать escaped `{{ }}`; raw HTML не добавляется.
- Новых write endpoints, authorization решений, CSRF surface, external URL,
  uploads, cache или персональных shared payload нет.
- Количество OR-групп второй фазы ограничено `per_page` (сейчас 24).
- Запросы не выполняются из Blade и не зависят от размера полного каталога
  после получения group page.
- Query-budget test защищает отсутствие N+1 при увеличении числа событий
  внутри одной пачки.

## TDD и acceptance

До production-кода добавляются RED tests:

1. десять последовательных portal publications дают одну карточку и summary
   `Добавлены серии 185–194`;
2. раскрытый список содержит все десять серий;
3. номера с разрывами дают точную строку без ложного диапазона;
4. разные season/type/status/time/translation не склеиваются;
5. одиночное событие сохраняет прежнюю карточку;
6. 25 событий одной пачки не разрываются двумя страницами;
7. фильтры и сортировки применяются до группировки;
8. query count не растёт от числа членов группы;
9. RU/EN keys и browser accessibility/mobile smoke проходят.

## Rollout и rollback

Schema/DML/cache rebuild не нужны. Rollout — обычный deploy кода и assets.
Rollback — revert task commits; канонические события и пользовательские
данные не требуют восстановления.
