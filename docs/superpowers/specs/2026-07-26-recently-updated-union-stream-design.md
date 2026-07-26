# Design: единый поток событий «Недавно обновлённые»

Дата: 26.07.2026.

Статус: `approved_for_inline_execution`.

## Контекст и measured root cause

`CatalogPublicDiscoveryQuery::recentlyUpdated()` уже ограничивает каждый
авторитетный источник последними `11 520` событиями при текущем
180-кандидатном окне. Однако `recentContentEvents()` выполняет отдельный SQL
для `licensed_media` и `episodes`, материализует оба результата, объединяет их
и сортирует до `23 040` массивов в PHP, хотя следующему этапу нужны только
уникальные `catalog_title_id` в том же порядке.

Read-only профиль на текущей SQLite-копии подтвердил новый локальный
bottleneck без изменения данных. Отдельный baseline-процесс прочитал `11 520`
событий, вернул `668` уникальных ID с hash `c4f462cf3abd5a63`, выполнил два
SQL за `55,58 ms`, завершил event stage за `84,54 ms` и достиг `42 MiB`
PHP peak memory. Эквивалентный prototype с bounded `UNION ALL`, внешней
сортировкой и ленивым dedup вернул тот же hash, выполнил один SQL за
`26,51 ms`, завершился за `37,94 ms` и достиг `30 MiB`. Это одиночные
локальные диагностики, а не p95 или SLA.

## Рассмотренные подходы

### 1. Bounded `UNION ALL` и ленивый dedup — выбран

Каждый source query сохраняет прежние фильтры, order и `LIMIT`. Два bounded
подзапроса объединяются через `UNION ALL`; внешний query задаёт прежний
глобальный порядок:

1. `event_at DESC`;
2. `event_source ASC`, поэтому `episode` остаётся раньше `media` при равной
   дате;
3. `event_id DESC`.

`cursor()` читает ordered rows лениво, а PHP хранит только первое появление
каждого положительного `catalog_title_id` с непустым `event_at`. Последняя
проверка сохраняет поведение прежнего PHP-filter для legacy empty date rows.
Подход удаляет второй round trip, PHP merge/sort и полную коллекцию event
arrays, не вводя новую truth.

### 2. Адаптивная keyset-дозагрузка

Можно читать небольшие страницы событий до накопления 180 eligible title.
Подход отклонён: число SQL становится data-dependent, усложняются tie
boundaries, query-budget contract и доказательство одинакового результата
при сильной концентрации событий одного title.

### 3. Материализованный latest-update projection

Отдельная таблица дала бы самый дешёвый read. Подход отклонён: потребовались
бы новый write/rebuild owner, backfill, readiness, invalidation и rollback во
всех importer/admin/media/episode mutations. Измеренный bounded read этого не
оправдывает.

## Сохраняемый контракт

- canonical routes `discover.index` и `localized.discover.index`, включая
  `/discover/recently_updated`;
- `CatalogRecommendationType::RecentlyUpdated`;
- максимум 180 кандидатов и текущая формула event window;
- отдельный лимит на каждый источник, а не общий лимит после union;
- authoritative media filters: published status, non-deleted row и
  non-null `published_at`;
- authoritative episode filters: published/non-deleted episode,
  non-deleted season, non-null past-or-current `released_at`;
- прежний deterministic merge order и first-event title dedup;
- прежнее исключение неположительного title ID и пустого `event_at`;
- `CatalogRecommendationSource::ContentUpdate` и
  `CatalogRecommendationReason::RecentlyUpdated`;
- common visibility/watchability, audience, region, premium, legal,
  current-title, filters и exclusions;
- page/rank behavior, cache keys, guest/private boundaries, SEO, sitemap,
  API DTOs и UI.

`catalog_titles.updated_at` не становится сигналом. При высокой концентрации
событий section по-прежнему возвращает только правдивый bounded eligible set.

## Реализация

Private `recentContentEvents()` заменяется private
`recentContentTitleIds()`. Source builders выбирают одинаковые четыре
колонки, включая bound literal `event_source`. Каждый builder сначала
оборачивается в `fromSub`, чтобы его order/limit применялись до union.
Внешний `fromSub` сортирует объединённые строки. Laravel Query Builder
сохраняет bindings; raw user input не участвует.

Метод возвращает `Collection<int, positive-int>` с ordered unique title IDs.
`recentlyUpdated()` передаёт её существующему `eligibleOrderedIds()` без
изменения downstream semantics.

## TDD и verification

RED-контракт создаёт media и episode events с одинаковыми датами,
дубликатами title и excluded title. Он проверяет:

- exact final ID order, source и reason;
- один event statement вместо двух;
- наличие обоих bounded sources и `UNION ALL`;
- сохранение episode-before-media tie-break;
- отсутствие excluded title;
- bounded common eligibility stage.

После GREEN выполняются focused recommendation/discovery tests, Pint,
task-scoped PHPStan/Rector, production read-only parity/profile,
`project:docs-refresh --check`, полный подходящий test set и frontend build
только если фактически затронуты frontend assumptions.

## Production impact, rollback и failure recovery

Schema, indexes, data, cache namespace/TTL, routes, translations,
permissions, dependencies, queues, environment и assets не меняются.
Deployment — обычная замена PHP-кода. Rollback возвращает прежний private
event method; backup, database restore, reindex, cache flush и worker action
не требуются.

Если SQLite/MySQL/PostgreSQL grammar или binding regression проявится до
release, focused query-shape test блокирует delivery. Если проблема проявится
после deploy, code rollback немедленно возвращает два source query; public
cache остаётся только необязательным ускорением, а authoritative fallback
сохраняется.
