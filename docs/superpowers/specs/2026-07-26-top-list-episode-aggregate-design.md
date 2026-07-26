# Оптимизация классификации Top 100: дизайн

Дата: 26.07.2026.

Статус: approved — пользователь прямо поручил самостоятельно выбрать
evidence-backed улучшение, обновить безлимитный план и продолжить реализацию.

## Цель

Сократить cold SQL time `/top/{category}` и `sitemap-static.xml` для фильмов
и сериалов, сохранив точный публичный Top 100, формулу рейтинга, категории,
фильтры, порядок, SEO, cache, routes и права доступа.

## Подтверждённый root cause

На production-scale SQLite находятся:

- 33 002 `catalog_titles`;
- 35 683 `catalog_title_ratings`;
- 48 635 `seasons`;
- 729 494 `episodes`;
- 880 984 `licensed_media`.

Текущий `CatalogTopListQuery` строит список тайтлов с одним или двумя и более
доступными эпизодами через episode-owned aggregate. Он:

1. материализует отдельный список доступных `seasons.id`;
2. повторно присоединяет `seasons`;
3. группирует все подходящие episodes по `seasons.catalog_title_id`;
4. создаёт две временные B-tree: для `GROUP BY` и для
   `COUNT(DISTINCT episodes.id)`.

`EXPLAIN QUERY PLAN` подтвердил `LIST SUBQUERY`, `REUSE LIST SUBQUERY`,
`USE TEMP B-TREE FOR GROUP BY` и `USE TEMP B-TREE FOR count(DISTINCT)`.
Три последовательных direct observations дали:

| Операция | Movies, ms | Series, ms |
| --- | --- | --- |
| `hasItems()` | 795–1 022 | 1 041–1 270 |
| `items()` | 935–1 344 | 1 274–1 414 |

Anime и cartoons не используют тот же episode aggregate: `hasItems()` для
anime занял 57–65 ms. Это локальные последовательные observations при
текущей внешней нагрузке, не p95/SLA.

## Рассмотренные подходы

### 1. Persisted episode-count projection

Отклонено. Точная публичная классификация зависит не только от количества
строк, но и от publication status, audience, availability window и soft
delete сезонов и эпизодов. Persisted projection потребовал бы migration,
backfill, importer/admin/observer write hooks, reconciliation, rolling
deployment и data rollback.

### 2. Новый широкий composite index

Отклонено. Существующие `seasons_publication_lookup_idx` и
`episodes_publication_lookup_idx` уже покрывают связи и основные public
предикаты. Bottleneck вызван формой полной агрегации и лишним distinct/list,
а не отсутствующим lookup. Новый индекс увеличил бы 30 GiB SQLite, backup и
стоимость importer writes без подтверждённого общего выигрыша.

### 3. Дополнительный cache классификации

Отклонено. Повторный HTTP уже закрывается общим versioned page cache.
Отдельная cache family продублировала бы invalidation и stale/error
контракты, не улучшив authoritative cold rebuild.

### 4. Correlated episode count/existence

Отклонено после read-only прототипа. Два `EXISTS` заставили SQLite выбрать
регрессивный порядок обхода; вариант с bounded nested count занял 67,68 s
для movies и был остановлен. Эта форма не переносится в production.

### 5. Season-owned flattened aggregate — выбран

`Season::availableTo(null)` становится owner внешней агрегации, а
`Episode::availableTo(null)` присоединяется как узкая проекция
`id, season_id` через `joinSub`. SQLite flatten-ит подзапрос и больше не
материализует отдельный список сезонов.

Каждая episode row после inner join соответствует ровно одному season и
имеет уникальный primary key, поэтому `COUNT(*)` точен и эквивалентен
прежнему `COUNT(DISTINCT episodes.id)`. Удаляется только лишняя temp B-tree,
а границы `= 1` и `>= 2` не меняются.

`hasItems()` строится из того же base eligible query, но выполняет
`exists()` без weighted projection и `ORDER BY`, потому что sitemap нужен
только факт наличия хотя бы одного rankable title.

## Exact compatibility

- Public title, season, episode и media entitlement scopes сохраняются.
- Watchability, health status и playback-location predicates сохраняются.
- Movies: non-anime catalog type, без animation genre, ровно один доступный
  episode.
- Series: те же type/genre границы, два и более доступных episodes.
- Anime и cartoons не меняются.
- КиноПоиск имеет прежний приоритет над IMDb.
- Bayesian smoothing, votes/rating/id tie-breakers и `LIMIT 100` не меняются.
- Year/country/genre filters применяются до ranking limit.
- Viewer overlay не меняет публичный candidate set.
- Ordered payload hashes до изменения:
  - movies:
    `58b112bb9ed46974c9b16cab3f918ab4b7a784aec78a4bfe388318458ce73b87`;
  - series:
    `9f99dc2dae5b2283c2f0e3d0a3b3d6e52166ecaad5909083017a10f5724041fd`;
  - anime:
    `26cf6491308b34ac1e48ad34d8b47b90aa452c0e0e942ab66ce65fc74b359592`;
  - cartoons:
    `54b8f22327b7694a93982d8422c7c2521ca9b348eeb497567c3069c8bdcaba35`.

## Architecture

Изменяется только private read boundary
`App\Services\Catalog\CatalogTopListQuery`.

Public flow остаётся:

`CatalogTopListPage → CatalogTopListPageBuilder → CatalogTopListQuery →
CatalogRecommendationTitleLoader → Blade`.

Новый service, route, controller, DTO, cache key, queue, migration, index,
configuration, dependency или environment variable не создаётся.

## Cross-feature impact

- `/top`, localized aliases, full-page Livewire ownership и validation
  сохраняются.
- `sitemap-static.xml` получает тот же набор Top 100 URLs через более лёгкий
  existence path.
- SEO/JSON-LD/canonical/hreflang, API, search, recommendations, player,
  collections, homepage и administration не меняются.
- Authentication, authorization, Premium, region и legal boundaries
  сохраняются.
- Translations, Blade, CSS, JavaScript, mobile/tablet/TV layout и service
  worker не меняются.
- Existing page cache identity, TTL, stale, lock, invalidation и warming не
  меняются.

## Production, data safety и rollback

Изменение выполняет только `SELECT`. Migration, DDL, DML, backfill, external
HTTP, cache flush, package/config/environment change отсутствуют. Backup и
restore для code-only rollout не требуются; обычные deployment и process
reload gates сохраняются.

Rollback: revert Task 96 commit и выполнить обычный compatible deployment.
Restore, reindex, data repair и cache clear не нужны.

## Проверка

1. RED фиксирует flattened join, отсутствие season-ID list и
   `COUNT(DISTINCT)`.
2. RED фиксирует `hasItems()` как `EXISTS` без weighted `ORDER BY`.
3. Semantic fixture проверяет episodes в разных seasons и исключение
   private/future/expired/deleted releases.
4. Existing route/filter/ranking/SEO/sitemap tests остаются GREEN.
5. Ordered production-scale payload hashes должны совпасть.
6. `EXPLAIN QUERY PLAN` не должен содержать temp B-tree для distinct или
   отдельный available-season list.
7. Paired repeated profile должен показать измеримое улучшение movies и
   series; результат документируется как observation, не SLA.

## Вне scope

- изменение формулы/категорий/лимита Top 100;
- approximate classification;
- schema/index/cache redesign;
- UI/translation/responsive redesign;
- общая переработка entitlement scopes;
- любые foreign Task 92–95 shared-worktree изменения.
