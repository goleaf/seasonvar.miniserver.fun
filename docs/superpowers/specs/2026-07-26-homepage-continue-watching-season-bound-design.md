# Дизайн season-bound запроса «Продолжить просмотр» на главной

Дата: 26.07.2026.

Статус: одобрено прямым разрешением пользователя выполнить рекомендуемый
вариант.

## Проблема

Гостевая главная после предыдущих оптимизаций уже работает быстро: реальные
desktop/mobile проверки дали LCP примерно `0,47–0,96 s`, а warm response-cache
ответы — `0,07–0,15 s`. Авторизованная главная намеренно не использует
публичный full-response cache и собирает персональные секции заново.

Свежий read-only профиль production-like SQLite показал:

- `CatalogHomePageBuilder::webData()` для существующего пользователя выполнял
  `91–118` SQL statements за `1,48–1,97 s` wall;
- один запрос `CatalogViewingActivityQuery::continueWatching()` занимал
  `1,42–1,90 s`;
- его `watchable_episode_ids` materialized subquery начинал с глобального
  прохода published episode stream через
  `episodes_recommendation_release_events_idx`;
- в базе сейчас `729 494` episodes, хотя текущий Continue Watching batch
  содержит не более `96` title IDs.

Запрос уже ограничивал `seasons.catalog_title_id`, но SQLite не переносил эту
границу внутрь materialized episode subquery. В результате canonical
visibility и playability вычислялись корректно, однако planner сначала
рассматривал весь опубликованный episode corpus.

## Цель

Сделать Continue Watching на авторизованной главной title/season bounded до
поиска watchable episodes, сохранив точную пользовательскую семантику.

Успех означает:

- latest owner activity по-прежнему выбирается один раз на тайтл;
- незавершённый доступный выпуск продолжает воспроизведение;
- завершённый выпуск ведёт к следующему доступному выпуску той же canonical
  season/type sequence;
- inaccessible current/next episodes не становятся доступными;
- visible title, season, episode и licensed-media predicates не ослабляются;
- SQL содержит прямую границу `episodes.season_id` по доступным сезонам только
  текущего title batch;
- SQLite plan использует `seasons_publication_lookup_idx` и
  `episodes_publication_lookup_idx` вместо глобального release-event scan для
  materialized watchable subset;
- homepage, `/watching`, mobile API, player и DTO contracts не меняются.

## Не цели

- не добавлять migration, index, table, materialized view или stored counter;
- не кэшировать owner progress или Continue Watching в shared cache;
- не менять completion threshold, activity ordering, batch size или limit;
- не менять `CatalogTitlePlaybackQuery` public methods для других consumers;
- не менять routes, Livewire state, Blade, API Resources, translations,
  assets, packages, queue, scheduler или environment;
- не оптимизировать несвязанные homepage sections без отдельного measured
  evidence.

## Рассмотренные подходы

### 1. Exact season-ID semi-join внутри watchable subset — выбран

`CatalogViewingActivityQuery` уже знает ограниченный `$catalogTitleIds`.
Watchable subquery получает дополнительный:

```php
whereIn(
    episodes.season_id,
    Season::query()
        ->availableTo($user)
        ->whereIn('catalog_title_id', $catalogTitleIds)
        ->select('id'),
)
```

Условие семантически избыточно относительно существующих joins и visibility
predicates, но даёт planner точную search boundary до materialization.

Изолированное сравнение на одном snapshot:

- текущий запрос: `1 447–1 904 ms`;
- season-bound запрос: `85–172 ms`;
- result rows совпали точно;
- новый plan выбрал `episodes_publication_lookup_idx` по
  `(season_id, publication_status, audience, deleted_at)` и
  `seasons_publication_lookup_idx` по
  `(catalog_title_id, publication_status, audience, deleted_at)`.

Преимущества: минимальный локальный diff, существующие индексы, отсутствие
нового persisted/cache state, простой rollback.

### 2. Глобально переписать `CatalogTitlePlaybackQuery` — отклонён

Можно изменить общий builder на seasons-first derived query. Это затронуло бы
title page, player navigation, history, API и другие consumers ради одного
batch-specific planner issue. Риск compatibility regression выше, а
доказанная оптимизация локальна Continue Watching.

### 3. Новый индекс или materialized Continue Watching projection — отклонён

Требуемые индексы уже существуют и выбираются после точной season boundary.
Новый индекс повысил бы стоимость importer writes. Materialized/private cache
потребовал бы invalidation для progress, episode, season, media, visibility,
Premium/region и account lifecycle и создал бы второй источник истины.

### 4. Отдельный запрос следующего выпуска на каждую activity row — отклонён

Per-row navigation проще для planner, но создаёт N+1, увеличивает latency и
теряет текущую bounded window семантику.

## Архитектура запроса

Архитектурные слои не меняются:

1. owner-scoped `EpisodeViewProgress` выбирает последнюю activity каждого
   тайтла;
2. batch ограничивается максимум `max(96, limit * 4)`;
3. exact title IDs дают exact available season IDs;
4. watchable episode subquery применяет прежние episode/season/title/media
   visibility и новую season-ID boundary;
5. canonical ordered sequence сохраняет текущий episode даже при потере
   playability, чтобы безопасно решить continue/next;
6. `LEAD()` остаётся partitioned по title, season kind и episode kind;
7. финальная hydration повторно применяет `availableTo($user)`.

Новый service/repository/cache boundary не создаётся.

## База данных и индексы

Изменение read-only и не меняет schema/data. Новая migration не нужна:

- `seasons_publication_lookup_idx` обслуживает title-bound available seasons;
- `episodes_publication_lookup_idx` обслуживает season-bound available
  episodes;
- current progress owner indexes, title primary keys и licensed-media
  playability indexes сохраняются.

`EXPLAIN QUERY PLAN` проверяется на фактическом SQLite и в regression test.
Raw user input в SQL отсутствует; title IDs происходят только из
owner-scoped database rows и передаются query-builder bindings.

## Авторизация, безопасность и privacy

- user определяется server-side и не принимается из URL/Livewire state;
- `whereBelongsTo($user)` остаётся owner boundary;
- `availableTo($user)` остаётся на title, season, episode и media;
- exact progress, activity и episode IDs не попадают в public cache,
  metadata или URL;
- запрос не выполняет writes, external HTTP, deserialization или redirect;
- Blade и API output contracts не меняются;
- SQL injection невозможен: применяется Eloquent/query-builder binding.

## Cache, production и отказоустойчивость

Authenticated homepage продолжает bypass shared response cache. Новый
personal cache не добавляется. Public homepage cache keys, TTL, generations,
invalidation и warming не меняются.

Deployment меняет только PHP/tests/docs:

- migration, backup/restore, reindex, cache clear и environment change не
  нужны;
- normal PHP process reload получает новый query;
- partial deployment безопасен, потому что old/new code читают одну schema и
  возвращают одинаковый DTO contract;
- при ошибке rollback — обычный revert кода;
- данные пользователя не мутируются и не требуют восстановления.

## TDD и проверка

RED test создаёт два тайтла, доступные seasons/episodes/media и owner progress:

- подтверждает current/next semantics;
- перехватывает `watchable_episode_sequence` SQL;
- требует direct `episodes.season_id` semi-join по batch title IDs;
- запускает `EXPLAIN QUERY PLAN` с фактическими bindings;
- требует оба publication lookup indexes.

Текущий код должен упасть только на SQL-shape/plan assertions, а не на
семантике fixtures.

После GREEN выполняются:

- focused query-plan test;
- существующие Continue Watching/homepage/library/API/player regressions;
- production-like repeated direct profile и exact result parity;
- Pint, syntax, PHPStan/Rector/Composer checks по установленным инструментам;
- полный `php artisan test` с честной фиксацией foreign failures;
- Vite build только если затронут asset assumption (не ожидается);
- browser desktop/mobile authenticated/guest QA, если доступна безопасная
  тестовая авторизация.

Timing values остаются диагностическими observations, не p95/SLA.

## Ожидаемые изменяемые файлы

- `app/Services/Catalog/CatalogViewingActivityQuery.php`;
- новый `tests/Feature/CatalogViewingActivityQueryPlanTest.php`;
- `docs/superpowers/specs/2026-07-26-homepage-continue-watching-season-bound-design.md`;
- `docs/superpowers/plans/2026-07-26-homepage-continue-watching-season-bound.md`;
- append-only Task 99 section в `docs/plans/current-task-plan.md`;
- task-specific hunk в `docs/performance.md`;
- `README.md`;
- `CHANGELOG.md`.

## Защищённые contracts и файлы

- все foreign staged/unstaged/untracked hunks Tasks 92–98;
- ветка `main`, существующая история и non-force push;
- `/`, `/{locale}`, `/watching`, `/api/v1/home` и mobile library routes;
- `CatalogContinueWatchingItem` shape и action labels;
- completion rule, owner order, batch/limit, current/next semantics;
- title/season/episode/media visibility, Premium/region/audience boundaries;
- public/private cache policy и all invalidation paths;
- Blade/Livewire/API/resource/SEO/localization contracts.
