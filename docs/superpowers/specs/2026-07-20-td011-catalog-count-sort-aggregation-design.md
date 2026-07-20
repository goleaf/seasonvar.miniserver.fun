# `TD-011` catalog count-sort aggregation design

Дата: 20.07.2026

## Контекст и граница

После пакетной загрузки presentation-счётчиков обычная выдача `/titles` больше не выполняет три correlated `withCount()` для каждой карточки. Однако `episodes_desc`, `seasons_desc` и `with_video` всё ещё оставляют один соответствующий correlated aggregate в result query, потому что он нужен до пагинации для сортировки полного отфильтрованного набора.

Публичный контракт нельзя менять: сохраняются три `sort`-ключа, все фильтры, guest/auth visibility, порядок `count DESC → indexed_at DESC → id DESC`, numbered pagination, точные card counts, ranked search и URL/Livewire state. Задача не добавляет schema, cache family, queue, dependency или runtime configuration.

## Рассмотренные варианты

1. **Grouped aggregate subquery с `leftJoinSub()` — выбран.** Для активной сортировки один visibility-aware запрос группирует только нужную relation по `catalog_title_id`. `LEFT JOIN` сохраняет тайтлы с нулём, `COALESCE(..., 0)` создаёт прежний alias, а существующий `CatalogTitleQuery::sorted()` сохраняет порядок и tie-breakers.
2. **Денормализованные persisted counters.** Это ускорило бы чтение, но потребовало бы additive schema, backfill, согласования importer/admin/publication-window changes, transactional invalidation и rollback данных. Для трёх существующих сортировок такая постоянная архитектура не оправдана без evidence, что grouped scan недостаточен.
3. **Cache готовых sorted ID lists.** Комбинации locale-independent visibility, guest/auth audience, времени публикации, поиска и всех фильтров создают большую invalidation surface. Cache miss всё равно требует корректный SQL, а stale список может нарушить publication boundary.
4. **Удалить count-sort или оставить correlated `withCount()`.** Первое ломает публичный URL, второе сохраняет уже локализованный остаточный риск `TD-011`.

## Выбранная архитектура

`CatalogTitleQuery` остаётся владельцем visibility, aggregate semantics и сортировки. Новый публичный метод принимает result builder, `CatalogSort` и viewer, а затем:

- для `seasons_desc` строит `Season::availableTo($user)` grouped по `catalog_title_id`;
- для `episodes_desc` агрегирует доступные episodes по доступным seasons и затем по `catalog_title_id`;
- для `with_video` строит `LicensedMedia::availableTo($user)->forAvailableReleases($user)` grouped по `catalog_title_id`;
- для остальных sort возвращает builder без изменения.

`CatalogTitlesPageBuilder` применяет эту границу одинаково к ordinary и ranked-search ID query до `sorted()`/`paginate()`. После пагинации существующий `CatalogTitleCardCountLoader` по-прежнему заполняет все три presentation attributes только для bounded page IDs. `publicCardCounts()` сохраняется для других consumers.

Paginator total считается отдельной исходной `filteredTitles()` query и передаётся в `paginate(total: ...)`. Иначе Laravel корректно сохраняет `LEFT JOIN` при автоматическом count query, но materializes тяжёлый sort aggregate второй раз, хотя total от него не зависит. Число SQL round-trips не меняется: прежний внутренний count заменяется тем же count до присоединения ordering aggregate.

## Совместимость, data safety и rollback

- Laravel Boost version-aware search для установленного `laravel/framework 13.20.0` подтверждает `joinSub`/`leftJoinSub` как public Laravel 13 Query Builder API; undocumented internals не используются.
- Route names, query parameters, validation, pagination URLs, search ranking, filters, locale, SEO, HTML, Livewire islands/state, mobile API и authorization не меняются.
- Все subquery переиспользуют canonical `availableTo()`/`forAvailableReleases()`; client не передаёт column, alias или SQL fragment.
- Grouped relation query возвращает максимум одну строку на title, поэтому `LEFT JOIN` не размножает result rows и не меняет paginator total.
- Schema/data/cache keys/payloads/queues/importer/dependencies/assets/production services не меняются. Применение и rollback не требуют cache/queue clear, restart или data rewrite.
- Rollback code-only: вернуть sort-only `withCount()` в обе ветки builder и удалить grouped sort method. Presentation loader остаётся совместимым.

## Verification design

- Сначала read-only stable-window baseline для всех трёх sort и generated SQL/`EXPLAIN QUERY PLAN`; timing обозначается diagnostic, не SLA/p95.
- RED parameterized regression создаёт два тайтла с разными relation counts, подтверждает прежний порядок и запрещает correlated title-count subquery для каждого sort.
- GREEN требует grouped join, нулевой count через `COALESCE`, прежние tie-breakers и точные presentation counts.
- Затем запускаются focused catalog/search/cache tests, Pint/Larastan/Rector, полный PHPUnit, managed docs/diff и Vite build. Browser smoke нужен только для сохранения public sorting/pagination behavior; визуальное изменение отсутствует.
- Перед delivery выполняется repository-wide поиск legacy sort-only `withCount`, duplicate aggregate paths и stale documentation. README обновляется только по фактически подтверждённому visitor-visible performance result.
