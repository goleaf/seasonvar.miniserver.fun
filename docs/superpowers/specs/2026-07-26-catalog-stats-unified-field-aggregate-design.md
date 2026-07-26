# Единый aggregate полей `/stats`: дизайн

Дата: 26.07.2026.

Статус: approved — пользователь многократно поручил самостоятельно выбирать
evidence-backed улучшения, обновлять безлимитный план и продолжать
программирование.

## Цель

Убрать повторные полные проходы по одним и тем же таблицам при точной холодной
сборке `/stats`, сохранив все публичные значения, labels/order, snapshot,
cache, routes, schema и write paths.

## Подтверждённый root cause

После Task 72 и Task 75 прямой `CatalogStatsPageBuilder::data()` выполняет
`142` SQL statement. Профиль текущей production-scale SQLite показал, что
builder отдельно считает:

1. `present` для обычных полей таблицы;
2. `present`, `distinct` и absolute HTTP(S) для URL-полей той же таблицы.

Повторный scan существует для пяти таблиц:

- `catalog_titles`;
- `seasons`;
- `episodes`;
- `licensed_media`;
- `source_pages`.

На `licensed_media` с 880 655 строками два read-only прохода заняли
`7,34–7,83 s`, а один statement с теми же selectors — `6,02–7,18 s`.
Maximum RSS сохранился около `10,3 MiB`; дополнительного temp-store policy
или индекса не потребовалось. Это локальные diagnostic observations, не
p95/SLA.

## Рассмотренные подходы

### 1. Индексы для aggregate-полей

Отклонено. Большинство точных coverage/count запросов всё равно читает
значительную часть таблицы. Индексы длинных URL и многочисленных nullable
metadata увеличат database, backup и стоимость importer writes без
доказанного лучшего общего результата.

### 2. Материализованная таблица статистики

Отклонено для текущего шага. Она потребовала бы migration/backfill,
authoritative write hooks для importer/admin/merge/health, repair и
reconciliation, rolling deployment, cache-version migration и отдельный
rollback. Visitor path уже защищён fresh/stale snapshot.

### 3. Один table-owned aggregate — выбран

Существующие private count arrays и SQL-семантика сохраняются. Один loader
на таблицу строит union selectors:

- `present` для всех application-owned полей из
  `PRESENT_COUNT_COLUMNS`;
- `present/distinct/absolute` для URL-полей из `externalUrlFields()`;
- Task 75 mismatch proof для пары `path/playback_url`.

Loader вызывается и из `presentCount()`, и из URL helpers. Поэтому порядок
внутренних consumers больше не может вернуть отдельный scan. Таблицы только
с обычными полями или только с URL продолжают выполнять один прежний
aggregate соответствующей формы.

## Архитектура

Public flow остаётся прежним:

`CatalogStatsSnapshotCache → CatalogStatsSnapshotBuilder →
CatalogStatsPageBuilder → sanitizer → StatsDashboard`.

Изменяется только private read boundary builder. Нового service, cache
family, query object, таблицы, index или background owner нет.

Identifiers поступают только из двух application-owned maps и оборачиваются
активной Laravel grammar. Values для empty string и URL patterns остаются
bindings. Request input и raw provider URL в output не участвуют.

## Exact compatibility

- Filled: `column IS NOT NULL AND column != ''`.
- URL distinct: exact `COUNT(DISTINCT CASE WHEN ... THEN column END)`.
- Absolute: `column LIKE 'https://%' OR column LIKE 'http://%'`.
- Пробелы не trim-ятся и сохраняют прежнюю семантику.
- Task 75 переиспользует `path_distinct` только при нулевом same-statement
  mismatch; иначе выполняет exact `playback_url` fallback.
- Empty table возвращает integer `0` для каждого selector.

## Cross-feature impact

- `/stats`, route names, Livewire state, Blade, labels/order и SEO не
  меняются.
- Snapshot sanitizer, cache key/version/TTL/stale/invalidation/warming не
  меняются.
- Schema, data, importer/admin/merge/health writes, queues и scheduler не
  меняются.
- API, search, sitemap, recommendations, authentication, authorization,
  Premium, region, legal, notifications и account lifecycle не меняются.
- Translation, JavaScript, CSS и responsive behavior не меняются.

## Production, data safety и rollback

Изменение выполняет только `SELECT`; migration, DML, external HTTP,
dependency/config/environment change и cache flush отсутствуют. Backup не
требуется для code-only activation, но обычные deployment gates сохраняются.

Rollback — revert task commit, пересборка совместимых compiled artifacts и
graceful runtime reload. Restore, reindex, cache clear, backfill и
reconciliation не нужны.

## Тестирование

1. RED фиксирует один query, содержащий обычные presence selectors и URL
   selectors общей таблицы, и отсутствие второго presence-only scan.
2. Fixture проверяет точные summary и URL values для null, empty, whitespace,
   relative, HTTP, HTTPS и duplicates.
3. Existing Task 75 fast/fallback tests обязаны остаться GREEN.
4. Focused stats/cache/page tests проверяют snapshot compatibility.
5. Production-scale profile сравнивает query count, wall/SQL time, RSS,
   filesystem output и canonical hashes всех 21 URL rows.

## Вне scope

- approximate counters;
- изменение секций или DOM `/stats`;
- schema/index/materialized state;
- cache policy;
- grouped histogram consolidation;
- public-media или table-inventory redesign;
- любой foreign Task 76/77 shared-worktree scope.

