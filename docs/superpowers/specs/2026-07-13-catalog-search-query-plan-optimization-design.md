# Оптимизация плана поиска каталога

Дата: 13.07.2026

## Цель

Устранить многосекундные SQLite-запросы на `/titles?q=...`, сохранив существующий FTS5-индекс, ранжирование, фильтры, русскую нормализацию и SQLite как единственную базу данных приложения.

## Подтвержденная причина

`CatalogTitleSearch::candidateQuery()` строит ranked FTS5 subquery, а `CatalogTitleQuery` подключает его через `joinSub()` для любого поискового контекста. SQLite разворачивает derived table и выбирает следующий порядок:

1. проходит почти все опубликованные `catalog_titles`;
2. находит соответствующий `catalog_title_search_documents` по primary key;
3. повторно выполняет FTS `MATCH` с ограничением по `rowid` для каждого тайтла.

На рабочей базе из 34 015 тайтлов запрос «Игра престолов» возвращает 9 строк, но один paginator count занимает 17 150 мс. Эквивалентный filter-only запрос через `WHERE IN (SELECT rowid FROM catalog_title_search_fts WHERE MATCH ?)` занимает 7,5 мс. Ranked derived table, который SQLite обязан материализовать, занимает 69,5 мс.

Полная страница усиливает дефект: paginator выполняет count и data query, а relation-фасеты, годы, тип публикации и наличие субтитров повторно строят поисковый контекст. Livewire дополнительно запускает полный render после паузы 650 мс при наборе каждого запроса.

## Рассмотренные подходы

### 1. Только принудительная материализация текущего subquery

Добавить к ranked candidate query семантически неограниченный `LIMIT`, который запрещает SQLite разворачивать derived table.

Плюсы: минимальное изменение и подтвержденное сокращение одного count с 17 150 до 69,5 мс. Минусы: BM25 и exact ranks продолжат вычисляться в фасетах, где порядок результатов не используется.

### 2. Разделение совпадения и ранжирования

Создать два FTS query boundary:

- filter-only query возвращает только совпавшие `rowid` и используется всеми count/facet/filter запросами через `whereIn`;
- ranked query загружает search documents, вычисляет exact ranks и BM25, принудительно материализуется и используется только основной выдачей карточек.

Плюсы: исправляет join order, не вычисляет ранги в агрегатах, сохраняет текущую релевантность и остается внутри существующих сервисов. Минус: `CatalogTitleQuery::filteredTitles()` должен явно различать основной ranked-result контекст и агрегатный контекст.

### 3. Внешний search engine

Laravel Scout с Meilisearch или Typesense отделил бы поиск от SQLite, но добавил бы production-сервис, сетевой отказ, отдельный индекс и lifecycle синхронизации. TNTSearch создал бы второй SQLite-индекс поверх уже работающего FTS5.

Для 34 тысяч тайтлов это не устраняет причину плохого SQL-плана и не оправдывает новую зависимость.

## Решение

Использовать подход 2. Подход 1 применяется внутри ranked query как SQLite-specific materialization boundary, но не заменяет разделение фильтрации и ранжирования. Новые Composer/npm-пакеты не устанавливаются.

## Границы компонентов

### `CatalogTitleSearch`

Сервис предоставляет два запроса:

- `matchingTitleIdsQuery(CatalogSearchQuery $search): ?Builder` возвращает `catalog_title_search_fts.rowid AS catalog_title_id`, применяет только параметризованный `MATCH` и не подключает search documents, BM25 или `ORDER BY`;
- `candidateQuery(CatalogSearchQuery $search): ?Builder` сохраняет exact title/original/alias ranks и weighted BM25, но получает SQLite materialization boundary до подключения к `catalog_titles`.

Оба метода возвращают `null`, если индекс нельзя безопасно использовать или FTS-выражение пусто.

### `CatalogTitleQuery`

`filteredTitles()` получает новый именованный флаг ranked-контекста с безопасным default `false`.

- Для основной выдачи с готовым поиском page builder передает ranked-контекст. Query подключает materialized `candidateQuery()` и регистрирует alias для существующего `sorted()`.
- Для paginator/facet/count контекстов без необходимости ранжирования query использует `whereIn('catalog_titles.id', matchingTitleIdsQuery())`.
- Legacy fallback остается только для состояния, когда полноценный FTS действительно нельзя использовать. Он не должен участвовать в ready FTS-пути.

Существующая публикационная видимость, relation-фильтры, advanced-фильтры и tie-breakers не меняются.

### `CatalogTitlesPageBuilder`

Только основной builder карточек запрашивает ranked-контекст. `CatalogFacetQuery`, `relationContextCounts()`, years, publication types и subtitle availability продолжают вызывать `filteredTitles()` без ranked-флага и автоматически получают filter-only FTS.

Paginator count может включать материализованный ranked query, но не должен возвращаться к plan, который вызывает `MATCH` на каждый `catalog_titles.id`. Если измерение покажет ненужную стоимость count, count и data query разделяются через явный paginator без изменения публичной формы pagination.

### Livewire/Blade

Основное поле `/titles` использует deferred `wire:model="filters.search"`. Поиск выполняется через существующий `wire:submit="applySearch"`, то есть по Enter или кнопке «Найти».

Полный page builder не запускается после каждой паузы при наборе. Отдельный autocomplete в эту задачу не входит: если он понадобится, он должен использовать отдельный ограниченный FTS lookup и не пересчитывать фасеты.

## Состояние и degraded mode

`ready`-индекс продолжает быть основным путем. Счетчики state являются диагностикой, а не заменой фактической проверки rebuild. Команда rebuild по-прежнему переводит индекс в `ready` только после совпадения исходных и фактических document counts и FTS integrity check.

Инкрементальные create/update/delete операции должны сохранять согласованность documents и FTS через существующие foreign key/triggers. Диагностическое расхождение сохраненных state counts после последующих удалений исправляется без двух полных `COUNT(*)` на каждый импортированный тайтл: reconciliation выполняется на границе завершенного batch/rebuild или отдельной дешевой health-операцией.

Если завершенный индекс временно помечен `stale` после ошибки incremental sync, допустимо продолжать использовать последний полный FTS snapshot с явным stale status. `building`, несовпадающая версия и rebuild без завершенного snapshot не могут использовать частичный индекс. Для этого состояния legacy driver остается функциональным, но не должен незаметно становиться постоянным production-путем; состояние логируется и требует rebuild.

## Кэширование и SQLite maintenance

Кэш не используется для маскировки плохого query plan. Сначала исправляется cold path.

После исправления допускается короткий cache фасетов по хешу нормализованного search/filter/audience signature, только если повторный benchmark показывает измеримую пользу. Raw пользовательский запрос не включается в cache key или metric labels.

`PRAGMA optimize`/`ANALYZE`, mmap/cache tuning и WAL checkpoint являются отдельной эксплуатационной оптимизацией. Они выполняются после завершения активного импорта и не считаются исправлением текущего nested-loop FTS plan.

## Безопасность и совместимость

- Пользовательские токены продолжают передаваться в `MATCH` только через bindings.
- API/Blade не получают raw FTS expression, search documents или importer state.
- SQLite остается source of truth; FTS external-content table остается производным индексом.
- Публичные URL, `q`, фильтры, pagination и русские сообщения не меняются.
- Exact-title, original-title, alias, transliteration, people/taxonomy search и year hard-filter сохраняются.
- Новые production dependencies отсутствуют.

## Тестирование

### Query regression

- Filter-only FTS query содержит один parameterized `MATCH`, возвращает только `rowid` и не содержит `bm25`, `catalog_title_search_documents` или `ORDER BY`.
- Ranked query сохраняет exact ranks/BM25 и имеет materialization boundary.
- `EXPLAIN QUERY PLAN` основной count/result query не показывает FTS lookup, вложенный после прохода `catalog_titles` для каждой строки.
- Facet/year/publication/subtitle queries используют filter-only boundary.

### Поведение

- Существующие acceptance tests подтверждают неизменный top-1/top-3 ranking.
- Exact title, alias, original title, transliteration, actor/director/taxonomy, year и true-zero результаты не меняются.
- Ready/stale/building/failed состояния покрываются отдельными тестами без использования частичного индекса.
- Livewire search не отправляет full render при каждом вводе и сохраняет Enter/button submit, URL hydration и reset page.

### Проверки

Сначала запускаются focused PHPUnit tests поиска и Livewire markup, затем Pint, широкий PHP test suite, documentation check и `npm run build` из-за изменения Blade. После этого повторяется benchmark на рабочей SQLite базе с тем же запросом и фиксируются current/optimized планы и времена.

## Критерии приемки

- Один search count для «Игра престолов» больше не выполняет nested FTS lookup для каждого опубликованного тайтла.
- Filter-only и ranked query возвращают одинаковый набор ID; ranked query сохраняет прежний порядок.
- Измеренный SQL cold path сокращается как минимум в 20 раз относительно 17-секундной исходной точки.
- Полный `/titles?q=...` не запускается при каждом изменении поискового поля.
- Все focused и полные проверки проходят без установки search package и без смены SQLite.
