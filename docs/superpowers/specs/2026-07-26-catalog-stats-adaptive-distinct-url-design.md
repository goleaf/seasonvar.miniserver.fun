# Адаптивный exact count URL в `/stats`

Дата: 26.07.2026.

Статус: approved — пользователь многократно разрешил выполнять все
evidence-backed рекомендации и продолжать программирование без
искусственного лимита.

## Цель

Сократить главный оставшийся холодный SQL-cost точного rebuild `/stats`, не
добавляя вторую таблицу агрегатов, большие URL-индексы, новые cache keys,
background owner или риск рассогласования с authoritative `licensed_media`.

## Подтверждённый baseline

После Task 72 `CatalogStatsPageBuilder` выполняет один aggregate для
`path`, `playback_url` и `source_url`. SQLite строит три temporary B-tree для
`COUNT(DISTINCT ...)`.

На активной production-scale SQLite:

- `licensed_media`: 880 611 строк;
- `path` и `playback_url`: по 879 322 distinct values;
- `source_url`: 76 992 distinct values;
- построчное сравнение: 880 611 одинаковых non-empty пар, 0 empty/mismatch;
- три baseline-процесса: 7,55 / 7,57 / 7,66 s, 573 000 filesystem output
  units;
- prototype с двумя distinct B-tree и exact equivalence check:
  4,93 / 4,95 / 5,17 s, 295 456 filesystem output units;
- `PRAGMA temp_store=MEMORY` дал 5,17 s, но потребовал 314 796 KiB RSS и
  поэтому несовместим с worker hard limit 256 MiB.

Значения являются read-only observations при активном importer, а не SLA или
p95.

## Рассмотренные подходы

### 1. Materialized read model

Отдельная таблица URL cardinality могла бы сделать чтение почти постоянным,
но потребовала бы согласованного обновления для importer bulk upsert,
administration, merge, health и всех будущих query-builder writes. Backfill и
repair затронули бы большую production SQLite. Подход отклонён до отдельной
доказанной потребности: текущий visitor path уже защищён fresh/stale snapshot.

### 2. Индексы по трём URL

Covering indexes могут ускорить distinct scan, но длинные значения
`path`/`playback_url` увеличат database, backup, migration time и write
amplification активного importer. Без writer-free копии и rollout evidence
такая schema change неоправданна.

### 3. Адаптивный exact aggregate

Выбранный подход удаляет только redundant temporary B-tree. В том же
aggregate считаются:

- прежние filled/absolute metrics всех трёх полей;
- distinct для `path` и `source_url`;
- число строк, где empty-state `path`/`playback_url` различается либо оба
  значения filled, но не равны.

Если mismatch count равен нулю, множества учитываемых `path` и
`playback_url` построчно эквивалентны, поэтому их exact distinct cardinality
одинакова и `path_distinct` безопасно переиспользуется. Если mismatch count
больше нуля, builder выполняет прежний exact
`COUNT(DISTINCT playback_url)` отдельным fallback-запросом.

## Контракт эквивалентности

Текущая семантика filled сохраняется буквально: значение учитывается, если
оно `IS NOT NULL` и не равно пустой строке. Пробелы не получают новой
нормализации.

Пара эквивалентна, когда:

1. оба значения empty по текущему правилу; либо
2. оба значения filled и равны по database comparison.

Проверка выполняется в том же read statement, что и primary counts.
Fast-path не доверяет importer invariant, cache, PHP sample или table count.

## Архитектура и data flow

Изменяется только private read helper
`CatalogStatsPageBuilder::loadExternalUrlCounts()`:

`externalUrlFieldRows → grouped aggregate → equivalence decision → reuse exact
path cardinality OR exact playback fallback`.

`CatalogStatsSnapshotBuilder`, sanitizer, `CatalogStatsSnapshotCache`,
`CatalogCacheInvalidator`, warmer и public Livewire DTO остаются без
изменений.

## Совместимость

- public `/stats`, route names, labels, order и values не меняются;
- schema, migrations, indexes, authoritative data и write paths не меняются;
- cache domain/key/version/TTL/invalidation/warming не меняются;
- security sanitizer и запрет raw source/media URL сохраняются;
- importer, administration, API, SEO, sitemap, recommendations, auth,
  Premium, region и legal decisions не затрагиваются;
- одинаковый fast-path работает на SQLite SQL, а mismatch fallback сохраняет
  корректность для любых будущих данных.

## Ошибки и rollback

Если primary aggregate не докажет нулевой mismatch, используется прежний
точный fallback. Database/cache exceptions продолжают обрабатываться
существующим `CatalogStatsSnapshotCache` и не получают нового silent
fallback.

Rollback — обычный code/docs revert. Migration, restore, cache flush,
reindex, backfill и environment change не требуются.

## Тестирование

1. RED: identical `path`/`playback_url` rows должны сохранить exact metrics и
   не выполнять отдельный `playback_url` distinct.
2. RED: mixed empty/different rows должны сохранить прежние exact values и
   выполнить один exact fallback.
3. GREEN: existing snapshot invalidation, public page, sanitizer и cache tests.
4. Query listener фиксирует primary aggregate с двумя distinct aliases,
   equivalence alias и отсутствие прежнего тройного aggregate.
5. Read-only production profile сравнивает одинаковые values, elapsed,
   maximum RSS и filesystem output минимум в трёх отдельных процессах.

## Self-review

- Placeholder scan: `TBD`/`TODO`/неопределённых решений нет.
- Consistency: fast-path разрешён только доказательством внутри authoritative
  read; fallback сохраняет прежнюю exact семантику.
- Scope: один private read helper и focused regression tests.
- Ambiguity: empty, equality, fallback, cache и rollback определены явно.
