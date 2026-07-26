# Консолидация запросов `/stats`: дизайн

Дата: 26.07.2026

Статус: approved по повторному прямому указанию пользователя выполнить
следующую измеренную рекомендацию и продолжать программирование без
дополнительной остановки.

## Цель

Сократить стоимость точной холодной сборки `CatalogStatsPageBuilder` без
изменения публичной формы `/stats`, значений статистики, visibility,
cache identity/TTL/invalidation, schema, importer writes или routes.

## Измеренный root cause

На текущем production-scale SQLite snapshot прямой read-only вызов
`CatalogStatsPageBuilder::data()` выполнил 1042 SQL statement за
`34 592,84–36 403,38 ms`; суммарное измеренное SQL-время одного прохода
составило `31 289,22 ms`.

Основные затраты:

- три отдельных `COUNT(DISTINCT ...)` для URL в `licensed_media` заняли
  около `9,1 s`;
- два одинаковых подсчёта серий без опубликованного media заняли около
  `2,3 s`;
- отдельные подсчёты public video links и distinct playable episode links
  заняли около `5,1 s`;
- отдельные present/absolute/grouped scans повторно читают
  `licensed_media`;
- SQLite index inventory выполняет `PRAGMA index_list` для каждой таблицы
  и `PRAGMA index_info` для каждого индекса, создавая большую часть из
  1042 round trips.

Read-only experiments подтвердили точную форму объединения:

- один aggregate десяти present-полей `licensed_media` — `1 007,05 ms`;
- один aggregate present/distinct/absolute для трёх media URL-полей —
  `8 085,54 ms` с теми же значениями;
- один visibility-aware aggregate public video/episode counts —
  `2 762,96 ms` с результатом `880580 / 727277`;
- один SQLite table-valued PRAGMA query вернул 519 индексов за `4,17 ms`;
- один SQLite `UNION ALL` вернул exact counts 159 таблиц за `1 117,64 ms`.

Эти значения являются локальными diagnostic observations одного snapshot,
а не SLA или production p95.

## Рассмотренные подходы

### 1. Консолидация внутри существующего builder — выбран

Сохранить `CatalogStatsPageBuilder` единственным владельцем snapshot и
заменить повторные проходы на несколько подготовленных aggregate boundaries:

- SQLite table counts одним `UNION ALL`, с прежним per-table fallback для
  других drivers или ошибки metadata query;
- SQLite index inventory одним запросом через table-valued
  `pragma_index_list`/`pragma_index_info`, с прежним fail-closed empty
  result;
- present counts одной conditional-aggregate выборкой на таблицу;
- present/distinct/absolute URL metrics одной conditional-aggregate
  выборкой на таблицу;
- public video URL count и distinct episode link count одним
  visibility-aware media aggregate;
- одинаковые missing-release counts вычислять один раз и переиспользовать
  в summary и quality sections.

Преимущества: code-only rollback, точная parity, отсутствие write
amplification и новой invalidation architecture. Недостаток: exact
`COUNT(DISTINCT long_url)` и несколько больших grouped histograms всё ещё
остаются дорогими.

### 2. Новые URL/grouping indexes — отклонён на этом этапе

Индексы по `path`, `playback_url`, `source_url`, `translation_name`,
`storage_disk`, `format` и `quality` ускорили бы отдельные scans, но
дублировали бы большие строки, существенно увеличили SQLite и стоимость
880k-row importer writes. Такой обмен нельзя принимать без отдельного
storage/write benchmark, backup и migration rollout.

### 3. Материализованный stats read model — отложен

Предрасчитанные counters/histograms могут сделать rebuild почти
мгновенным, но требуют schema, authoritative reconciliation, importer/admin
hooks, time-window policy, repair command, cache/version migration и
rolling-deploy strategy. Это отдельный feature, а не безопасный query
refactor.

## Архитектура

`CatalogStatsSnapshotCache → CatalogStatsSnapshotBuilder →
CatalogStatsPageBuilder` остаётся неизменным. Нового service, cache family
или таблицы не создаётся.

Внутри builder добавляются небольшие private query helpers и request-local
arrays. Они возвращают только integer counters или существующую форму index
rows. Все identifiers происходят из static application-owned maps либо
SQLite schema inventory и оборачиваются grammar; request input в SQL не
участвует.

Public media aggregate начинает с тех же:

1. `LicensedMedia::availableTo(null)`;
2. `forAvailableReleases(null)`;
3. visible `CatalogTitleQuery::visibleTo(null)` ID subquery.

В одном проходе conditional `COUNT` считает строки с absolute playback/path,
а conditional `COUNT(DISTINCT episode_id)` — episode links. Ни один
publication/audience/window/release predicate не удаляется и не
переносится в PHP.

## Совместимость и ошибки

- PHP 8.5, Laravel 13.22, SQLite и in-memory PHPUnit остаются
  поддерживаемыми.
- Non-SQLite connections сохраняют прежний portable per-table count path;
  SQLite-only PRAGMA consolidation находится под driver guard.
- Ошибка SQLite metadata inventory сохраняет прежний truthful empty index
  result; ошибка обычной factual aggregate не скрывается cache fallback
  внутри builder.
- Snapshot sanitizer, cache payload, cache domain/version/TTL, stale
  fallback и invalidation не меняются.
- Routes, Livewire state, Blade, translations, SEO, poster proxy,
  authorization, importer, queues и schema не меняются.

## Data safety, deployment и rollback

Изменение выполняет только `SELECT`; migration, DML, cache flush и external
HTTP отсутствуют. Backup не требуется для активации code-only refactor,
однако обычный deployment/rollback contract проекта сохраняется.

Rollback — revert task commit, безопасная пересборка compiled code/views и
graceful reload runtime. Database restore, cache clear, key scan или
reconciliation не нужны, потому что payload contract и persisted state не
меняются.

## Тестирование

TDD RED должен доказать отсутствующие boundaries до production-кода:

1. один visibility-aware statement содержит оба media counters и сохраняет
   exact values;
2. одинаковые missing-release counts не выполняются повторно;
3. SQLite index inventory не создаёт per-table/per-index PRAGMA round trips;
4. SQLite table inventory не создаёт one-query-per-table;
5. present/URL aggregates сохраняют существующие values для null, empty,
   relative, HTTP, HTTPS и duplicate URL;
6. полный sanitized snapshot до/после на одном snapshot имеет одинаковый
   canonical hash после удаления времени-зависимых полей, если такие поля
   будут обнаружены.

После GREEN выполняются focused stats tests, соседние catalog/cache tests,
Pint, Larastan, Rector dry-run, полный разрешённый backend suite и повторный
read-only profile с query count, SQL time, payload hash и `EXPLAIN` для
изменённых тяжёлых statements.

## Вне scope

- удаление или редизайн секций `/stats`;
- уменьшение HTML/DOM страницы;
- новый dashboard route/API;
- приблизительные counters;
- schema/index migration;
- materialized stats table;
- новый cache key/TTL или store;
- изменение полного нефильтрованного `with_video`.

Последние два performance-направления остаются отдельными следующими
этапами после верификации этой консолидации.
