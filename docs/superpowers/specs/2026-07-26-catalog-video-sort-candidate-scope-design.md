# Candidate-scoped `with_video` sort design

Дата: 26.07.2026.

## Контекст и измеренная причина

Публичная сортировка `/titles?sort=with_video` сохраняет точный порядок
`published_media_count DESC, indexed_at DESC, id DESC`. После предыдущего
`TD-011` follow-up коррелированный count заменён одним visibility-aware
grouped `LEFT JOIN`, но SQLite всё ещё материализует media aggregate по всем
`880 589` строкам до применения фильтров внешнего списка.

Read-only профиль текущего `CatalogTitlesPageBuilder` на рабочем snapshot:

- `with_video`: `2 685,46 ms`, из них `2 308,61 ms` занимает result query;
- `episodes_desc`: `908,45 ms`;
- `seasons_desc`: `461,40 ms`.

`EXPLAIN QUERY PLAN` показывает
`MATERIALIZE catalog_media_sort_counts`, scan
`licensed_media_status_title_episode_idx` и release-availability проверки
сезона/эпизода до соединения с отфильтрованными `catalog_titles`.
Фильтр `year=2024` оставляет `2 122` тайтла и `39 184` связанных media, но
текущий aggregate всё равно занимает `2 194,56 ms`; для `year=2000` —
`2 937,71 ms`, для `year=1980` — `2 380,61 ms`.

## Рассмотренные варианты

1. **Ограничить grouped media aggregate точным candidate-ID subquery —
   выбран.** Клон уже построенного result builder сохраняет canonical
   publication, audience, time-window, search и filter predicates, но
   выбирает только `catalog_titles.id`. `licensed_media` использует этот
   subquery в `whereIn`, поэтому индекс получает равенство по
   `status + catalog_title_id` и не проверяет media чужих кандидатов.
2. **Добавить широкий covering index.** На одноразовой 125 MiB профильной
   SQLite-копии индекс
   `(status, catalog_title_id, episode_id, season_id, audience, deleted_at,
   published_at, available_from, available_until)` занимал около `49,7 MiB`
   и уменьшал медиану только с `2,37` до `1,99 s`. Цена хранения, импорта,
   WAL и backup не оправдана относительно code-only candidate scope.
3. **Persisted public/auth counters или cache sorted IDs.** Это самый дешёвый
   read path, но создаёт новый consistency owner для media, season, episode,
   audience и publication-window изменений, title merge, importer,
   administration и invalidation. Без отдельного доказанного SLA такой
   schema/cache boundary преждевременен.
4. **Ослабить release visibility или считать только `licensed_media.status`.**
   Отклонено: это изменило бы entitlement и могло показать недоступные
   сезоны/серии.

## Выбранная архитектура

`CatalogTitleQuery::withCardCountSortAggregate()` до присоединения media
aggregate клонирует переданный result builder. Клон:

- удаляет eager-load declarations, которые не участвуют в subquery;
- удаляет order clauses;
- заменяет presentation select точным `catalog_titles.id`;
- сохраняет все joins, FTS rank boundary, filters и server-side
  `availableTo($user)` predicates.

Только ветка `CatalogSort::VideoDesc` передаёт этот builder в
`mediaCountSortAggregate()`. Media query продолжает использовать
`LicensedMedia::availableTo($user)->forAvailableReleases($user)`, после чего
добавляет `whereIn(licensed_media.catalog_title_id, $candidateTitleIds)`.
Внешний `LEFT JOIN`, `COALESCE(..., 0)`, paginator total, card-count loader и
tie-breakers не меняются.

Candidate query строится из того же builder до media join, поэтому
саморекурсивного SQL нет. Для полного каталога список кандидатов совпадает с
прежним доступным набором; одноразовый read-only prototype сохранил 96 rows
и занял `2 296,09 ms`, то есть не показал практической регрессии против
`2 308,61 ms`. На точных year scopes он занял:

- `year=2024`: `155,27 ms`;
- `year=2000`: `32,24 ms`;
- `year=1980`: `9,83 ms`.

Это одиночные diagnostic observations, не p95 и не SLA.

## Совместимость и риски

- Сохраняются route `/titles`, `CatalogSort::VideoDesc`, query parameter
  `with_video`, filters, FTS ranking, numbered pagination и URL/Livewire
  state.
- Guest/auth audience, publication status, time windows, season/episode
  hierarchy, region/Premium/legal boundaries и policy contracts остаются
  server-side и переиспользуются без копирования правил.
- API, sitemap, importer, queue, recommendations, collections,
  administration, translations, SEO markup и Blade не меняются.
- Schema, migration, index, production data, dependency, config/env, cache
  key/TTL/payload и background job не добавляются.
- Query text становится длиннее из-за повторения candidate predicates.
  Регрессии защищают exact results, FTS branch, один grouped aggregate и
  presence bounded candidate subquery.
- Rollback code-only: убрать candidate clone/`whereIn`; data restore, cache
  clear, worker restart и migration rollback не нужны.

## Verification design

1. RED создаёт подходящие и неподходящие по году тайтлы с media и требует,
   чтобы result SQL ограничивал `licensed_media.catalog_title_id` subquery
   отфильтрованных `catalog_titles.id`. Текущая реализация должна упасть на
   SQL boundary при сохранённой семантике.
2. GREEN сохраняет order, total, zero-count titles, ordinary и real FTS
   branches; grouped media alias встречается только в result query.
3. Повторный read-only profile сравнивает тот же snapshot и `EXPLAIN`,
   отдельно full catalogue и `year=2024|2000|1980`.
4. Focused catalog/search/API/cache tests, Pint, static analysis и полный
   доступный PHPUnit подтверждают отсутствие regression.
5. Frontend build/browser не обязателен для PHP-only SQL change; если
   shared tree содержит frontend изменения, их результаты нельзя выдавать
   за evidence этой задачи.
