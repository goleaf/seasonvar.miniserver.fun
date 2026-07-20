# `TD-011` homepage card-count batching design

Дата: 20.07.2026

## Контекст и подтверждённая причина

В стабильном окне guest `HIT` для `/`, `/titles`, `/titles/ierrohierro` и `/en` занимает примерно 0,19–0,74 секунды. После очередной invalidation `/en` не отдал тело за два 15-секундных client timeout, а завершившийся server rebuild затем дал быстрый `HIT`. Последний critical warm сохранил одну `ConnectionException`; fingerprint точно соответствует `/en`.

Read-only вызов `CatalogHomePageBuilder::data(null)` для `en` занял 19 864,69 мс, из которых 19 638,99 мс — SQLite. Четыре `CatalogTitle` hydration query используют `withCount(CatalogTitleQuery::publicCardCounts())`: три заняли 5 790,69/5 646,88/5 592,68 мс, ещё одна — 1 498,59 мс. Это повторяет тяжёлые correlated availability subqueries для четырёх bounded card groups.

## Рассмотренные варианты

1. Рекомендуемый: удалить только homepage `withCount` и после hydration передать все card model instances в существующий `CatalogTitleCardCountLoader`. Loader уже является каноническим для recommendations/library и использует bounded title IDs плюс grouped season/episode/media queries. Семантика counts сохраняется, duplicate model instances также получают attributes, schema/cache keys/public routes не меняются.
2. Оставить nested latest-media `withCount`, заменив только три основных query. Это уменьшает задержку, но сохраняет подтверждённый 1,5-секундный correlated path и две реализации одной card-count архитектуры.
3. Добавить ещё один homepage payload cache или summary table. Это дублирует текущий `TieredCache`, требует новой invalidation/data migration architecture и не оправдано при наличии готового bounded loader.

Выбран вариант 1 как наименьшее coherent изменение.

## Архитектура и поток данных

- `CatalogHomePageBuilder` по-прежнему получает bounded IDs из `CatalogHomeSnapshotCache` и гидратирует те же title/media/taxonomy relations.
- `titleSummaryQuery()` больше не добавляет correlated counts.
- Nested `latestMedia.catalogTitle` также получает только presentation columns и release relations без correlated counts.
- После hydration builder объединяет `latestTitles`, `featuredTitles`, `videoTitles` и все фактические `latestMedia.catalogTitle` model instances и один раз вызывает `CatalogTitleCardCountLoader::load()`.
- Loader сам deduplicate-ит scalar IDs для SQL, но применяет результаты к каждому переданному model instance. Поэтому одинаковый title в нескольких sections сохраняет counts без дополнительных queries.
- Recommendation loader остаётся самостоятельным consumer существующей boundary; public/user visibility передаётся тем же nullable `User`.

## Safety и совместимость

- Persisted data, ordering, cards, route names, locale, visibility, premium/region/legal decisions и cache identity не меняются.
- Нет migration, dependency, package/config, Redis/Memcached, queue/job, service-worker или deployment-runtime change.
- SQLite остаётся source of truth; grouped queries используют bounded IDs уже отображаемых карточек.
- Rollback: вернуть homepage `withCount` calls и удалить injection/call `CatalogTitleCardCountLoader`; cache/data cleanup не нужен.

## Verification design

- RED regression вызывает реальный `CatalogHomePageBuilder`, подтверждает корректные season/episode/media counts и запрещает correlated count subqueries в title hydration SQL.
- GREEN должен сохранить semantic assertions и использовать канонические grouped count query shapes.
- Затем выполняются homepage/public-cache/content-addition/recommendation-loader focused tests, Pint, Larastan, full PHPUnit, Vite/docs/diff gates.
- Production activation не требует cache clear: следующий natural invalidation/cold build использует новый code. Read-only `/en` MISS→HIT проверяется только при естественно missing/stale state; fresh cache намеренно не удаляется.
