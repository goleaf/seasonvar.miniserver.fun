# Коррелированная проверка сезона для обновлений главной — design

Дата: 26.07.2026.

Статус: approved by explicit user preauthorization to implement all
recommended homepage performance improvements without an additional approval
pause.

## Цель

Сократить холодную сборку данных главной страницы после инвалидирования
`Homepage`, не меняя содержимое, порядок, правила доступности и публичные
contracts блока «Новые серии».

Оптимизация должна устранить широкую materialization доступных сезонов при
проверке уже ограниченного списка последних episode ID. Повторные запросы
по-прежнему обслуживаются существующим full-response и domain cache; новый
cache layer не создаётся.

## Подтверждённая причина

Read-only профиль текущего `main` на production-like SQLite содержит:

- 33 002 тайтла;
- 48 635 сезонов;
- 729 494 серии;
- 880 984 media rows.

При прогретом compact cache годовых buckets private
`CatalogHomeSnapshotCache::build()` выполнил 15 statements за `253,460 ms`
wall / `96,660 ms` SQL. Самый дорогой повторяемый statement занимал
`51,760 ms` и проверял доступность 2 048 уже ограниченных episode ID.

Текущий `CatalogHomeContentAdditionQuery::availableEpisodeIds()` использует:

```sql
episodes.id IN (...)
AND episodes.season_id IN (
    SELECT seasons.id
    FROM seasons
    WHERE <public availability>
)
```

`EXPLAIN QUERY PLAN` подтвердил:

- `SEARCH episodes USING INTEGER PRIMARY KEY (rowid=?)` для bounded episode
  rows;
- `LIST SUBQUERY`;
- широкий `MULTI-INDEX OR` по `seasons_available_until_idx` для полного
  множества доступных сезонов.

То есть outer episode boundary уже правильный, но вложенный `IN` сначала
материализует несвязанный широкий набор сезонов.

Same-snapshot сравнение девяти запусков сохранило `2 048` episode ID и
одинаковый SHA-256 результата. Медиана wall-time составила:

- `season_id IN (SELECT id ...)`: `192,910 ms`;
- correlated `EXISTS` по точному `episodes.season_id`: `72,887 ms`.

Время зависит от общей нагрузки production SQLite, поэтому acceptance
основан на результате и query plan, а не на хрупком жёстком millisecond
assertion.

## Рассмотренные варианты

### 1. Correlated season `EXISTS` — выбран

Создать существующий `Season::query()->availableTo(null)` как correlated
subquery:

```sql
EXISTS (
    SELECT 1
    FROM seasons
    WHERE seasons.id = episodes.season_id
      AND <public availability>
)
```

Преимущества:

- переиспользует authoritative publication/audience/window/soft-delete scope;
- сохраняет bounded episode window и точный результат;
- приводит каждый season check к integer primary-key probe;
- не добавляет schema, index, cache key, queue или dependency;
- является обычным portable `EXISTS` для SQLite и остальных поддерживаемых
  database drivers.

### 2. Уменьшить initial event window

Не выбран. Окно `2 048` участвует в доказательстве точной границы 48 последних
обновлённых тайтлов. Его уменьшение может дать меньше distinct titles на
плотных пакетах одного сериала и потребует дополнительных итераций. Это не
исправляет широкую season materialization в каждой итерации.

### 3. Добавить новый season index

Не выбран. Запрос сравнивает единственный `seasons.id`, для которого уже
существует integer primary key. Новый secondary index не лучше точного PK,
повысит стоимость importer writes и потребует DDL/backup/rollback без
обоснованной пользы.

### 4. Перенести всю availability-фильтрацию в recent-event scan

Не выбран. `recentEpisodeEvents()` намеренно ограничивает raw event window
через `episodes_created_at_idx`. Фильтрация до `LIMIT` могла бы заставить
SQLite просматривать неограниченно большой хвост недоступных событий, а
ручное дублирование entitlement predicates в derived query создало бы вторую
границу истины.

## Архитектура решения

Изменяется только query shape внутри
`CatalogHomeContentAdditionQuery::availableEpisodeIds()`:

1. Нормализация, дедупликация и bounded episode ID остаются прежними.
2. SQLite outer query сохраняет `episodes NOT INDEXED`, чтобы уже
   подтверждённый exact-ID hydration использовал primary key.
3. `Season::query()->availableTo(null)` получает
   `whereColumn('seasons.id', 'episodes.season_id')`, `selectRaw('1')` и
   `toBase()`.
4. Episode query использует `whereExists($availableSeason)` вместо
   `whereIn('season_id', ...select('id'))`.
5. `latestVisibleUpdates()`, adaptive event window, title visibility,
   ordering и snapshot serialization не меняются.

Новый service, repository, DTO, Form Request, policy, migration, model
relationship, event, job, cache resource или configuration option не
создаётся.

## Data flow

```text
recent episode IDs (max current adaptive window)
  → exact episode PK lookup
  → correlated exact season PK availability probe
  → eligible episode ID set
  → existing visible-title reduction and deterministic ordering
  → existing CatalogHomeSnapshotCache payload
  → existing web/API hydration and full-response cache
```

## Сохраняемые contracts

- `/`, `/ru`, `/en`, route names, locale, SEO, headers и public page cache;
- `/api/v1/home` и `CatalogHomeResource` JSON shape/order;
- `CatalogHomeSnapshotCache` resource, dimensions, key, version, TTL, stale,
  lock, invalidation и payload keys;
- `latest_title_ids`, `latest_title_updates`, 48-row full/API contract и
  12-row web projection;
- public publication/audience/time-window/soft-delete visibility тайтла,
  сезона, серии и media;
- `RELEASE_ITEMS_PER_TITLE`, `has_more`, release group ordering и hydration;
- authenticated/private/Premium/region/legal/player/admin/importer/search/
  recommendation/calendar/notification contracts;
- schema, indexes, foreign keys, data, queue names и serialized jobs;
- existing SQLite support and no-op-free portable SQL on other drivers.

## Validation, authorization и security

Пользовательский ввод отсутствует: homepage snapshot использует фиксированные
server-side limit и public audience. SQL строится Eloquent/query builder с
bindings; raw user data в SQL не вставляется.

Изменение не ослабляет authorization:

- `Episode::availableTo(null)` остаётся authoritative episode boundary;
- `Season::availableTo(null)` остаётся authoritative season boundary;
- correlated `whereColumn` только связывает две server-owned integer keys;
- title/media visibility и cache audience dimensions остаются прежними.

CSRF, mass assignment, IDOR, SSRF, redirects, uploads и personal-data cache
не затрагиваются.

## Database, production и rollback

- DDL, DML, migration, backfill и data mutation отсутствуют.
- Новый index не добавляется; backup для самого code-only изменения не
  требуется.
- Deployment использует обычный clean `main` code rollout с соответствующим
  opcache/PHP-FPM reload по действующему runbook.
- Cache key/schema/format не меняются, поэтому flush/version bump запрещены.
- Queue restart нужен только как стандартный release step для загрузки кода;
  payload/queue compatibility не меняется.
- Rollback — revert code/test/docs commit и graceful reload. Database,
  storage, cache и queue restore не нужны.
- При partial deployment старый и новый code читают один и тот же snapshot
  payload и используют одну schema.

## Error handling

Новый failure state не создаётся. Пустой ID set по-прежнему возвращает пустую
collection без SQL. Database exception не скрывается. Если season не
существует или недоступен, correlated probe возвращает false — ровно как
прежний `IN` subquery.

## TDD и verification

До production code тест расширяется так, чтобы:

1. создать доступные и недоступные season/episode fixtures;
2. вызвать реальные `latestTitleUpdates()`/snapshot boundary;
3. подтвердить прежний состав и отсутствие недоступных серий;
4. захватить exact availability SQL;
5. потребовать correlated `EXISTS` по `seasons.id = episodes.season_id`;
6. запретить `season_id IN (SELECT id FROM seasons ...)`;
7. проверить `EXPLAIN QUERY PLAN`: episode и season используют integer
   primary key, `LIST SUBQUERY` отсутствует.

После GREEN запускаются:

- focused performance/content-addition/page/API/cache tests;
- exact Pint, syntax, PHPStan и Rector dry-run;
- repeated same-snapshot result/hash/query-plan/profile comparison;
- safe live GET и Chromium desktop/mobile/API smoke без cache/data mutation;
- broad PHPUnit и Vite build в пределах доступного shared repository;
- final requirement, legacy, debug, secret и exact-diff audit.

## Acceptance criteria

- season availability выполняется correlated primary-key probe;
- `LIST SUBQUERY` отсутствует в exact availability plan;
- result IDs и SHA-256 совпадают с прежним запросом;
- hidden/future/expired/authenticated/deleted season/episode rows исключены;
- adaptive window, ordering, limits, web/API payload и cache lifecycle
  неизменны;
- focused и related tests зелёные;
- live homepage остаётся HTTP 200, compressed payload bounded, повторный
  ответ остаётся page-cache `HIT`;
- migration/index/dependency/config/route/translation/UI change отсутствует;
- документация, commit и push status записаны фактически.
