# Дизайн facet snapshot для тега субтитров на главной

Дата: 26.07.2026.

Статус: одобрено прямым разрешением пользователя выполнить рекомендуемый
вариант.

## Проблема

После предыдущих оптимизаций `CatalogHomeSnapshotCache::build()` по-прежнему
при каждом принудительном или Homepage-only rebuild ищет публичный системный
тег субтитров и выполняет correlated `withCount()` по всем видимым тайтлам.
Этот результат зависит от публичного состояния каталога и тега, но не от
Homepage-only событий, которые меняют подборки, календарь или представление.

Свежий read-only профиль production-like SQLite содержит 33 002 тайтла,
133 896 связей `catalog_title_tag`, 729 494 серии и 880 984 media rows.
После прогрева годового facet resource private build главной выполнил девять
SQL statements за `38,03 ms` учтённого SQL и `193,287 ms` wall. Запрос
`subtitle-available` был самым дорогим — `21,14 ms`.

`EXPLAIN QUERY PLAN` подтверждает корректный план:

- `tags_code_unique` для `tags.code`;
- covering
  `catalog_title_tag_tag_id_catalog_title_id_index(tag_id, catalog_title_id)`
  для pivot;
- integer primary key для проверки каждого связанного `catalog_titles`.

Добавление индекса не устранит повторный authoritative count и создаст лишнюю
стоимость записи для импортера.

## Цель

Повторно использовать компактный публичный subtitle-tag snapshot через уже
существующий `CatalogFacetSnapshotCache`, чтобы Homepage-only rebuild не
выполнял неизменившийся correlated count.

Успех означает:

- внешний `subtitle_tag` сохраняет прежние `id`, `name`, `slug` и
  `catalog_titles_count`;
- canonical и legacy schema paths сохраняются;
- server-side visibility/publication/audience/window/soft-delete predicates
  не ослабляются;
- второй `CatalogHomeSnapshotCache::refresh()` в той же версии
  `CatalogFacets` не выполняет subtitle count query;
- bump `CatalogFacets` пересчитывает значение;
- route, API, HTML, locale, Homepage key/TTL и invalidation contracts остаются
  совместимыми.

## Не цели

- не добавлять migration, index, table, counter column или materialized view;
- не менять tag taxonomy, visibility или assignment rules;
- не менять `CatalogFacetSnapshotCache` public contract;
- не менять маршруты, Livewire, Blade, переводы, Vite assets или зависимости;
- не включать в эту задачу персонализированный redesign главной из Task 94.

## Рассмотренные подходы

### 1. Compact resource существующего CatalogFacets snapshot — выбран

`CatalogHomeSnapshotCache` помещает прежний Tag query в
`CatalogFacetSnapshotCache::remember()`. Rebuild возвращает пустой список или
один scalar array; Homepage owner берёт первый элемент и сохраняет его в
прежнем nullable `subtitle_tag`.

Преимущества:

- переиспользуются действующие TieredCache, version registry, locks, stale
  fallback, telemetry и outage fallback;
- `CatalogCacheInvalidator::catalogChanged()` уже повышает `Homepage` и
  `CatalogFacets`;
- `TagCacheInvalidator::publicChanged()` делегирует той же after-commit
  boundary;
- в shared cache сохраняются только публичные scalar attributes, без Eloquent
  object graph и private state.

Ограничение: первый build новой `CatalogFacets` generation честно выполняет
прежний запрос. Это необходимый authoritative fallback.

### 2. Новый или принудительный индекс — отклонён

Planner уже использует индекс по canonical code, covering reverse pivot index
и primary key тайтлов. Дополнительный индекс не устраняет чтение связанных
публичных тайтлов, увеличивает database size и write amplification активного
importer. SQLite-specific index hint также не решает повтор между rebuild.

### 3. Denormalized counter или materialized table — отклонён

Хранимый счётчик дал бы дешёвый cold read, но потребовал бы migration,
backfill, transactional updates для всех publication/window/audience/tag
изменений, repair command и новый race/failure contract ради одного bounded
значения.

### 4. Удаление count или сохранение только в Homepage snapshot — отклонено

Удаление меняет публичное представление. Текущий Homepage snapshot уже
кэширует count, но Homepage-only invalidation именно поэтому снова выполняет
запрос; это не устраняет выявленный повтор.

## Архитектура

Новый service, repository или controller не создаётся.
`CatalogHomeSnapshotCache` получает результат через приватный
`subtitleTag(): ?array`:

1. определяет canonical или legacy schema path;
2. вызывает `CatalogFacetSnapshotCache::remember()` с отдельным resource;
3. rebuild выполняет прежний `Tag::query()->select(...)`, canonical
   `code/publiclyEligible()` либо legacy slug, затем constrained
   `withCount()`;
4. rebuild возвращает `[]` либо список из одного `getAttributes()` array;
5. private method возвращает первый элемент или `null`;
6. outer Homepage snapshot сохраняет прежний `subtitle_tag`.

Dimensions содержат `audience=public` и schema mode. Locale намеренно не
входит: текущий запрос выбирает canonical `tags.name` и не загружает
`TagTranslation`. Если контракт позже станет locale-aware, его resource
version/dimensions должны быть изменены вместе с query.

## Cache и invalidation

Resource принадлежит `CacheDomain::CatalogFacets` и использует действующие
fresh/stale/hot/lock настройки.

- Любое canonical catalog change повышает `Homepage` и `CatalogFacets`.
- Публичное изменение/назначение тега проходит через
  `TagCacheInvalidator::publicChanged()` и ту же общую after-commit
  инвалидацию.
- Homepage-only rebuild получает facet hit.
- Bump `CatalogFacets` меняет identity и выполняет authoritative rebuild.
- При cache miss или отказе store `TieredCache` вызывает прежний DB query.
- Store-wide flush, wildcard scan и отдельная warming job не добавляются.

## Совместимость, безопасность и данные

Сохраняются:

- `/`, `/ru`, `/en`, full-page Livewire, SSR SEO и `/api/v1/home`;
- exact nullable `subtitle_tag` shape и integer count;
- canonical/legacy schema compatibility;
- `CatalogTitleQuery::constrainVisible()` как server-side access boundary;
- существующие imports/admin/tag writes, after-commit invalidation и warming;
- отсутствие пользовательских данных, source URLs и secrets в payload;
- SQLite и остальные поддерживаемые Eloquent grammars.

Изменение read-only. Оно не выполняет DDL/DML, не ослабляет policy/gate,
не добавляет внешний запрос и не меняет обработку ошибок.

## TDD и проверка

RED:

- создать публичный subtitle tag, видимые и скрытые связанные тайтлы;
- дважды вызвать `CatalogHomeSnapshotCache::refresh()`;
- подтвердить точный payload;
- ожидать только один correlated subtitle count query;
- текущая реализация должна упасть из-за второго query.

GREEN:

- добавить ещё один видимый связанный тайтл и доказать, что без bump сохраняется
  прежний snapshot;
- повысить `CatalogFacets`, повторить refresh и получить новый count со вторым
  query;
- проверить отсутствие subtitle query на Homepage-only refresh.

Далее выполняются focused homepage/cache/API regressions, Pint, PHP syntax,
PHPStan, Rector, PHPUnit, Vite и browser checks по применимости. Fresh-process
профиль должен сравнить первый и повторный rebuild; локальные timing values не
объявляются production p95 или SLA.

## Rollout и rollback

Deployment меняет только PHP/tests/docs. Migration, database backup/restore,
reindex, queue restart, environment update и cache flush не нужны. Первый
read новой resource identity заполнит compact snapshot; при отказе cache
сработает прежний SQL fallback.

Rollback — обычный revert кода. Недостижимый versioned cache entry истечёт по
действующей политике и не требует ручного удаления.

## Ожидаемые изменяемые файлы

- `app/Services/Catalog/CatalogHomeSnapshotCache.php`;
- `tests/Feature/CatalogHomePerformanceTest.php`;
- `docs/superpowers/plans/2026-07-26-homepage-subtitle-facet-snapshot.md`;
- `docs/plans/current-task-plan.md`;
- `docs/caching.md`;
- `docs/performance.md`;
- `README.md`;
- `CHANGELOG.md`.
