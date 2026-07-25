# Primary-key hydration эпизодов главной — design

Дата: 26.07.2026.

Статус: approved by explicit user preauthorization to implement the recommended
homepage optimization.

## Цель

Сократить подтверждённый холодный путь `CatalogHomePageBuilder`, не меняя
состав, порядок, видимость и HTML блока «Новые серии». Оптимизация должна
устранить два ошибочных широких SQLite index scan при гидратации уже
ограниченного списка episode ID и сохранить текущий `MISS → HIT` lifecycle
полного ответа.

## Подтверждённая причина

Read-only профиль текущего `main` на рабочей SQLite дал:

- `CatalogHomePageBuilder::data()` — `358,53 ms`;
- 57 запросов, `219,69 ms` суммарного SQL;
- две episode hydration операции — `86,04–88,23 ms`;
- обе операции уже имеют ограниченный список ID, но SQLite выбирает
  `episodes_recommendation_release_events_idx` по
  `publication_status/deleted_at` и просматривает широкий опубликованный
  корпус вместо primary key.

`EXPLAIN QUERY PLAN` подтвердил `SEARCH episodes USING INDEX
episodes_recommendation_release_events_idx`. Same-snapshot read-only
сравнение с `episodes NOT INDEXED` сохранило 28 и 37 строк соответственно и
изменило медианы:

- ranked episode hydration: `85,89 → 0,92 ms`;
- eager episode hydration для media: `89,60 → 0,07 ms`.

Оба изменённых плана используют `SEARCH episodes USING INTEGER PRIMARY KEY
(rowid=?)`. Внутренние ranking/visibility subqueries продолжают использовать
существующие специализированные индексы.

## Рассмотренные варианты

### 1. Локальный primary-key hydration — выбран

Переиспользовать существующий private helper
`CatalogHomeContentAdditionQuery::withoutSecondaryIndexes()` только в двух
наружных bounded episode hydration queries.

Преимущества:

- исправляет подтверждённый planner defect в источнике;
- не добавляет schema, cache или lifecycle;
- не меняет inner ranking и visibility;
- для не-SQLite соединений является no-op;
- code-only rollback.

### 2. Новый composite index

Не выбран. Запрос уже ограничен primary key, поэтому ещё один индекс дублирует
данные, повышает стоимость importer writes и не гарантирует выбор planner.
Миграция и production backup/rollback были бы несоразмерны проблеме.

### 3. Новый cache/snapshot release groups

Не выбран. Материализация результата добавила бы новый key/version/TTL,
инвалидацию и риск устаревшей видимости. Текущий response cache уже решает
повторные чтения; задача относится только к правильному cold SQL plan.

## Архитектура решения

Изменяется только `CatalogHomeContentAdditionQuery`.

1. Inner query `rankedIds()` продолжает:
   - проверять public episode/season/title visibility;
   - ограничивать даты конкретной координатой тайтла;
   - выбирать не более `RELEASE_ITEMS_PER_TITLE + 1`;
   - использовать текущие publication/home indexes.
2. Наружный `episodesFor()` query получает `Episode::query()` через
   `withoutSecondaryIndexes()`, затем выполняет прежние `availableTo`,
   `whereIn`, eager load сезона и deterministic ordering.
3. Eager-load `episode` внутри `mediaFor()` получает тот же helper до
   `availableTo()` и прежней ограниченной projection.
4. Helper изменяет `FROM` только на SQLite. MySQL/PostgreSQL и остальные
   drivers получают исходный Eloquent builder без изменения SQL.

Новый service, repository, cache, event, queue или configuration option не
создаётся.

## Сохраняемые contracts

- `/`, `/ru`, `/en`, route names, locale, SEO и HTTP headers;
- набор и порядок `latestReleaseGroups`;
- максимум восемь episode и media rows на один тайтл и truthful `has_more`;
- `CatalogTitleQuery`/publication/audience/window/soft-delete visibility;
- season/episode/media model identity и eager-loaded presentation fields;
- `CatalogHomeSnapshotCache`, `CatalogHomeMetricsCache`, full-response keys,
  versions, TTL, stale и invalidation;
- importer command, write transactions, queues, workers и cache warming;
- authenticated/private/premium/region/legal/player/API/admin contracts;
- existing indexes и SQLite compatibility.

## Data, migration и production safety

- DDL/DML, migration, backfill и production content mutation отсутствуют.
- Backup не требуется, потому что authoritative data и schema не меняются.
- Deployment применяет обычный code rollout; queue/PHP-FPM restart нужен
  только если стандартный release process требует загрузить новый PHP code.
- Cache flush, queue clear, claim mutation и version bump запрещены.
- Rollback — revert PHP/test/docs commit. Cache/data restore не требуется.
- Если driver не SQLite, helper возвращает исходный query и поведение
  полностью сохраняется.

## Error handling

Новых failure states нет. Query продолжает возвращать пустую коллекцию при
отсутствии строк и propagates существующую database exception. Planner hint
не ослабляет visibility: все predicates остаются в SQL, а primary-key lookup
изменяет только способ доступа.

## TDD и verification

До production code добавляется тест, который:

1. создаёт опубликованные title/season/episode/media;
2. вызывает реальные `latestTitleUpdates()` и `latestReleaseGroups()`;
3. подтверждает прежние IDs, relations и counts;
4. захватывает SQL;
5. требует `episodes NOT INDEXED` в двух наружных hydration queries;
6. требует сохранения специализированного inner ranking query.

RED должен падать из-за отсутствующих двух `NOT INDEXED`, а не из-за fixture.
После GREEN запускаются:

- focused content-addition/performance/home tests;
- adjacent catalog/cache tests;
- exact Pint и PHPStan;
- `EXPLAIN QUERY PLAN` и repeated same-snapshot timing;
- live safe GET matrix без cache/data mutation;
- broad PHPUnit в пределах доступного окружения;
- documentation, README/CHANGELOG и legacy scan.

Runtime test не получает жёсткий миллисекундный assertion: автоматический
contract проверяет план и результат, а время остаётся диагностическим evidence.

## Acceptance criteria

- два наружных episode hydration query используют primary key на SQLite;
- inner ranking/visibility query и все predicates сохранены;
- одинаковые episode/media/title IDs и `has_more`;
- focused и adjacent tests зелёные;
- production-safe direct builder быстрее baseline без роста query count;
- live homepage остаётся HTTP 200 и сохраняет response-cache lifecycle;
- routes, schema, dependencies, translations, cache keys и payload contracts
  не изменены;
- документация, commit и push status записаны честно.
