# Readiness редакционных подборок — дизайн

Дата: 26.07.2026.

Статус: `approved_for_implementation`.

Пользователь поручил продолжать программу улучшения `/discover` без
искусственного лимита и заранее разрешил выполнять рекомендованный вариант.
Этот change set реализует Task 15 editorial master plan и discovery Task 9,
не выполняя production content mutation.

## Подтверждённая проблема

`CatalogCollectionModerationService::feature()` сейчас проверяет только
`editorial + public + approved`. `CatalogCollectionQuery::featured()` и
editorial-ветка `CatalogPublicDiscoveryQuery` доверяют одному
`is_featured=true`. Поэтому пустая, тонкая или содержащая недоступные тайтлы
подборка может получить публичный редакционный статус.

Read-only снимок SQLite содержит 54 source-managed public editorial
подборки: 15 непустых и 39 пустых. Ни одна сейчас не featured, поэтому
вводится fail-closed guard без изменения рабочих строк.

## Цель

Создать одну каноническую readiness boundary, которая:

- разрешает feature только approved/public/published editorial collection;
- требует минимум 12 guest-watchable тайтлов для локальной подборки;
- требует минимум 4 guest-watchable тайтла для source-managed подборки;
- требует нулевое количество membership, недоступных гостю для просмотра;
- исключает stale featured row из homepage/discovery без изменения самой
  публичной collection page;
- показывает модератору точные безопасные причины до нажатия feature.

## Не-цели

- не создавать, не публиковать и не feature-ить production collection;
- не менять membership, порядок, категории, изображения или source sync;
- не добавлять migration, cache key, queue, scheduler, package или env;
- не менять detail/profile/API/sitemap eligibility обычных public collections;
- не считать rating, popularity или внешнее мнение readiness evidence;
- не запускать provider HTTP и не сохранять новые данные.

## Рассмотренные варианты

### Только фильтр discovery

Защищает visitor output, но оставляет административную mutation ошибочной и
даёт ложный `is_featured`. Отклонено.

### Только guard mutation

Предотвращает новые ошибки, но уже featured collection может стать stale
после visibility/media/source change и продолжить отображаться. Отклонено.

### Единая mutation/read/admin boundary

Выбрано. Один сервис вычисляет exact metrics и reusable eligible-ID query.
Write boundary повторно проверяет locked row, public read boundaries
fail-closed используют тот же query, admin получает bounded batch evaluation.

## Компоненты

### `CatalogWatchableTitleQuery`

Нейтральная catalog boundary для guest/user-visible тайтлов с реально
доступным media:

```php
public function visibleTo(?User $user): Builder;
public function mediaForTitle(?User $user): Builder;
```

Media сохраняет canonical scopes: published, release availability, active или
degraded health и непустой playback location. Существующий
`CatalogRecommendationVisibilityService` делегирует этой boundary
watchable-часть, чтобы readiness не копировала recommendation SQL.

### `CatalogCollectionPublicationReadiness`

```php
/** @return array{
 *   ready: bool,
 *   visible_items: int,
 *   total_items: int,
 *   unavailable_items: int,
 *   required_items: int,
 *   source_managed: bool,
 *   reason_codes: list<string>
 * } */
public function evaluate(CatalogCollection $collection): array;

/** @return array<int, array{...}> */
public function evaluateMany(iterable $collections): array;

public function eligibleFeaturedCollectionIds(): QueryBuilder;
```

Один grouped aggregate соединяет точные collection IDs с membership и
guest-watchable title subquery. `evaluateMany()` выполняет один bounded query
для admin page, а не запрос на карточку. Source identity определяется
существующей unique `catalog_collection_sources.catalog_collection_id`.

Стабильные reason codes принадлежат enum:

- `not_editorial`;
- `not_public`;
- `not_approved`;
- `not_published`;
- `deleted`;
- `source_missing`;
- `insufficient_visible_items`;
- `unavailable_items`.

Неизвестный код никогда не разрешает feature и отображается нейтральной
локализованной ошибкой.

## Write и read flow

`feature(true)` после policy и row lock вызывает readiness. Любой отказ
возвращает русскую validation error, не меняет `is_featured`, version, audit
или cache. `feature(false)` остаётся всегда доступным. Успешный exact retry
остаётся no-op.

`CatalogCollectionQuery::featured()` и editorial discovery добавляют
`whereIn(collection_id, eligibleFeaturedCollectionIds())`. Поэтому изменение
visibility, publication, media health/location или membership немедленно
убирает коллекцию из featured output даже до ручного unfeature. Обычная
public collection page и каталог подборок продолжают жить по прежнему
public/moderation contract.

## Admin presentation

`CatalogCollectionAdministrationManager` получает readiness service через
`render()`, вычисляет metrics для текущих максимум 50 строк и передаёт Blade
только prepared scalar attributes. Карточка показывает:

- «Готова к редакционному показу» или «Не готова»;
- доступное/общее количество и требуемый минимум;
- локализованные причины.

Feature-кнопка доступна только ready row. Unfeature остаётся доступным даже
для stale row. Blade не выполняет query/service resolution и не содержит
`@php`.

## Производительность и безопасность

- admin evaluation ограничена IDs текущей страницы;
- public eligible query начинается с indexed featured/editorial/public shape;
- membership использует существующие collection/title indexes;
- никакой Eloquent graph не попадает в Livewire public state;
- client не передаёт readiness, required count или reason codes;
- policy, row lock, audit и after-commit cache invalidation сохраняются;
- SQL использует portable joins, conditional aggregate, `GROUP BY` и
  `HAVING`, совместимые с SQLite и поддерживаемыми Laravel connections.

После GREEN выполняются query-count и `EXPLAIN QUERY PLAN` проверки. Новый
index добавляется только при доказанном плане, не заранее.

## Совместимость и rollback

Сохраняются все routes, route names, URL/filter/page state, collection
identity, moderation status, source provenance, manual item order,
translations, API/SEO/sitemap, recommendation reason, cache domains,
importer, private state и text-only presentation.

Rollback — revert PHP/UI/docs change. Schema/data/cache rollback не требуется.
Уже не-featured production rows не меняются. Отдельный production canary
разрешён только после operator review через существующий UI и не входит в
этот commit.

## Acceptance

- RED доказывает, что текущий service feature-ит thin collection и discovery
  читает stale featured row.
- Local `12/12` и source `4/4` проходят; `11/11`, `3/3`, `11/12`, missing
  source и structural mismatch fail closed.
- Failed feature не пишет collection/version/audit/cache state.
- Exact successful retry не создаёт второй audit.
- Homepage/query и editorial discovery возвращают только readiness-passing
  featured collections и сохраняют manual order.
- Admin current page получает одну grouped readiness выборку без N+1.
- Focused collection/discovery tests, Pint, PHPStan, docs, Vite и
  desktop/mobile Playwright проходят; broad-suite независимые blockers
  фиксируются честно.
