# Filtered Directory Candidate Aggregate Design

**Дата:** 26.07.2026
**Статус:** approved — пользователь повторно разрешил реализовать все
рекомендованные улучшения после глубокого анализа.

## Контекст и подтверждённая проблема

Одиннадцать публичных справочников `/actors`, `/directors`, `/genres`,
`/countries`, `/categories`, `/types`, `/years`, `/channels`, `/studios`,
`/translators` и `/tags` обслуживает один
`CatalogDirectoryQuery`. Web и
`GET /api/v1/catalog/directories/{directory}` используют одинаковую
нормализацию, guest-public visibility, фильтры, сортировку и paginator.

Предыдущие задачи уже:

- ограничили обычный `name_asc` результат текущей страницей;
- сохранили глобальный grouped aggregate для нефильтрованного
  `count_desc`;
- вынесли summary, alphabet и decades в компактные versioned snapshots.

Оставшийся cold path проявляется только при активном `q` или `letter`:

1. `filteredValueCount()` соединяет всю taxonomy table с pivot и видимыми
   тайтлами, затем применяет taxonomy-фильтр и выполняет
   `count(distinct taxonomy.id)`.
2. `count_desc` агрегирует видимые связи для всех taxonomy values и только
   во внешнем запросе применяет `q`/`letter`.

На текущем actor directory с более чем 111 тысячами значений это заставляет
SQLite повторно обходить и группировать существенно больше связей, чем
нужно для 1 539 поисковых или 10 462 буквенных кандидатов.

Read-only измерение текущей формы:

| Сценарий | Exact total | Total SQL | Result SQL | Полный wall |
| --- | ---: | ---: | ---: | ---: |
| `letter=А`, `count_desc` | 10 462 | 518,51 ms | 290,47 ms | 811,57 ms |
| `q=Александр`, `count_desc` | 1 539 | 588,32 ms | 306,79 ms | 897,97 ms |

Это одиночные локальные observations под текущей SQLite-нагрузкой, а не
production p95/SLA.

## Цели

- До pivot aggregate ограничить работу точным множеством taxonomy candidate
  IDs, когда задан `q` или `letter`.
- Сохранить результат, порядок, total, page size, URL, API Resource, SEO,
  locale и visibility без изменений.
- Переиспользовать один taxonomy candidate builder для total и result,
  исключив расхождение фильтров.
- Не добавлять migration, index, cache resource, dependency, queue,
  configuration или production data mutation.
- Зафиксировать форму SQL и поведение PHPUnit-тестами до изменения
  production-кода.

## Не входит в scope

- Изменение UI, маршрутов, переводов, query-параметров или pagination.
- Перепроектирование summary/alphabet/decades snapshots.
- Изменение нефильтрованного `count_desc`, которому нужен глобальный
  aggregate для корректного сравнения всех значений.
- Изменение `CatalogTitleQuery::visibleTo(null)`, public entitlement,
  canonical tag eligibility или localized tag labels.
- Новые индексы, materialized counters, scheduled refresh или cache
  invalidation.
- Оптимизация `/stats`, homepage, importer, player, collections или других
  параллельно изменяемых модулей.

## Рассмотренные варианты

### 1. Candidate-scoped grouped pivot — выбран

Сначала строится существующий taxonomy query кандидатов:

```sql
SELECT actors.id
FROM actors
WHERE name/slug are valid
  AND <q and/or letter predicates>
```

Затем pivot aggregate ограничивается этим подзапросом до visibility и
grouping:

```sql
SELECT actor_id, COUNT(DISTINCT catalog_title_id)
FROM catalog_title_actor
WHERE actor_id IN (<candidate IDs>)
  AND catalog_title_id IN (<guest-visible title IDs>)
GROUP BY actor_id
```

Для filtered total внешний `COUNT(*)` считается над тем же grouped pivot,
поэтому taxonomy rows не размножаются join-ами и `COUNT(DISTINCT
taxonomy.id)` не нужен. Для filtered `count_desc` этот aggregate
присоединяется к тому же candidate query и сохраняет прежнюю сортировку.

Преимущества:

- exact semantics на SQLite и остальных поддерживаемых Laravel drivers;
- candidate predicate выполняется до pivot grouping;
- используются существующие taxonomy и reverse pivot covering indexes;
- один builder владеет canonical tag/search/letter predicates;
- отсутствуют PHP materialization ID-массивов и дополнительный round-trip;
- нет schema, cache или invalidation риска.

Сравнение альтернативной формы на том же snapshot:

| Сценарий | Новый total SQL | Новый result SQL | Ожидаемое суммарное SQL-снижение |
| --- | ---: | ---: | ---: |
| `letter=А`, `count_desc` | 120,59 ms | 171,88 ms | около 64% |
| `q=Александр`, `count_desc` | 137,28 ms | 176,02 ms | около 60% |

Обе пары вернули прежние exact totals и одинаковые ordered result hashes.
Финальные цифры должны быть повторены после GREEN в одной read-only
транзакции; эти значения не являются SLA.

### 2. Correlated `EXISTS` от каждой taxonomy row — отклонён

Форма «отфильтровать taxonomy и для каждой строки проверить видимую pivot
связь» семантически точна, но на текущей SQLite:

- `letter=А` заняла около `11 988,81 ms`;
- `q=Александр` заняла около `1 352,39 ms`.

Широкая буква создаёт тысячи повторных correlated probes. Этот вариант
хуже текущего total и не должен использоваться.

### 3. Materialized counters или новый индекс — отклонён

Stored counters могли бы ускорить и нефильтрованный `count_desc`, но требуют
schema, backfill, write-path ownership, importer invalidation, reconciliation,
rollback и drift monitoring. Существующая pivot уже имеет primary
`(catalog_title_id, actor_id)` и reverse covering
`(actor_id, catalog_title_id)` indexes; проблема заключается в порядке
ограничения и grouping, а не в отсутствии очевидного индекса.

## Архитектура решения

`CatalogDirectoryQuery` получает один private
`taxonomyCandidates()` builder:

1. Выбирает только `id`, `name`, `slug`.
2. Исключает пустые identity values.
3. Для canonical tags применяет `publiclyEligible()` и прежние localized
   label projections.
4. Применяет существующие `applyTaxonomySearch()` и `applyLetter()`.

`taxonomyQuery()` использует этот builder как outer query. При
`sort=count_desc` и наличии `q` или `letter` его клон, выбирающий только
qualified taxonomy ID, передаётся в `whereIn(pivot.related_key, subquery)`
внутреннего grouped aggregate. Без фильтра candidate restriction не
добавляется, и прежний глобальный grouped aggregate остаётся неизменным.

`filteredValueCount()` для taxonomy:

1. Берёт тот же candidate ID subquery.
2. Группирует pivot по related key только внутри candidate IDs и
   guest-visible title IDs.
3. Считает строки grouped subquery через `fromSub()->count()`.

Year directory продолжает использовать отдельный прежний
`validYearTitles()` path.

Новый public class, route, controller, Livewire component, DTO, Resource,
model relation, event, job или cache key не создаётся.

## Data flow

```text
normalized q / letter
  → canonical taxonomy candidate builder
  → candidate ID subquery
  → existing pivot covering index
  → existing CatalogTitleQuery::visibleTo(null)
  → grouped visible counts only for candidates
  ├─→ filtered total
  └─→ existing deterministic result ordering/pagination
      → unchanged web Livewire and API Resources
```

## Сохраняемые contracts

- все web directory routes, localized URLs и route names;
- `GET /api/v1/catalog/directories/{directory}` response shape;
- validated `q`, `letter`, `sort`, `decade`, `page` и per-page rules;
- `name_asc` и `count_desc` ordering/tie-breakers;
- exact `published_titles_count`, paginator total и rows;
- publication status, public audience, availability windows и soft deletes;
- canonical tag eligibility, translation/alias search и localized labels;
- page-only canonical, filtered/sorted `noindex,nofollow` и SEO headers;
- summary/alphabet/decades snapshots, keys, TTL, stale, locks and invalidation;
- importer, search, homepage, recommendations, collections, player, Premium,
  administration, regional/legal and user-private behavior;
- schema, indexes, data, queues, dependencies and environment.

## Validation, authorization и security

HTTP normalization и validation остаются в существующих web/API
boundaries. В новый SQL не вставляется raw input: query builder продолжает
использовать bindings; qualified table/column names поступают только из
server-owned `CatalogTaxonomyRegistry`.

Directory остаётся guest-public read-only. `CatalogTitleQuery::visibleTo(null)`
не меняется и продолжает исключать draft, future, expired, authenticated,
Premium и soft-deleted тайтлы. Canonical tag privacy/moderation boundary
переиспользуется без дублирования. Новые записи, personal data, secrets,
remote URLs и external requests отсутствуют.

## Database, cache, production и rollback

- DDL, DML, migration, backfill и production data mutation отсутствуют.
- Существующие indexes проверяются через schema inspection и final
  `EXPLAIN QUERY PLAN`; новый index не требуется.
- Cache resource/key/version/TTL/stale/lock/invalidation не меняются;
  flush или version bump не выполняется.
- Deployment — обычный clean `main` code rollout и штатный graceful reload
  по существующему runbook.
- Partial deployment безопасен: старый и новый код читают одну schema и
  производят один public payload.
- Rollback — revert code/test/docs commit и graceful reload. Database,
  storage, cache и queue restore не нужны.
- При database exception существующее error handling сохраняется; новая
  fail-open/fallback ветка не добавляется.

## TDD и verification

RED-тесты должны:

1. Создать filtered actor candidates с public, draft, future, expired и
   soft-deleted title relations.
2. Подтвердить прежние exact total, rows, counts и deterministic order.
3. Потребовать, чтобы filtered total группировал candidate-scoped pivot и не
   выполнял taxonomy-to-pivot `COUNT(DISTINCT taxonomy.id)`.
4. Потребовать candidate restriction внутри filtered `count_desc`
   `directory_value_counts`.
5. Подтвердить, что нефильтрованный `count_desc` сохраняет глобальный
   aggregate.

После GREEN выполняются:

- focused optimization, directory web/API, cache/SEO/warming tests;
- Pint, PHP syntax, task-scoped PHPStan и Rector dry-run;
- repeated same-snapshot exact totals/hashes and timing comparison;
- SQLite `EXPLAIN QUERY PLAN` для `q` и `letter`;
- safe web/API smoke при доступном runtime;
- broad PHPUnit и Vite build в пределах shared repository;
- final requirements, legacy, duplicate, debug, secret and exact-diff audit.
