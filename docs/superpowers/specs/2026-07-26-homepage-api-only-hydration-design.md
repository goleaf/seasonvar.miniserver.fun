# Дизайн удаления API-only hydration из web-path главной

Дата: 26.07.2026.

Статус: approved. Пользователь прямо поручил выполнить рекомендуемый вариант
без дополнительного согласования.

## Контекст и измеренный root cause

Task 68 сократил HTML главной до 12 карточек последних обновлений, но private
`CatalogHomePageBuilder::buildData()` по-прежнему строит одинаковый superset
для full-page Livewire и `/api/v1/home`.

Свежий read-only профиль текущего `webData()` на рабочем SQLite:

- пять отдельных PHP processes: `144,63–186,93 ms`;
- `57` SQL statements;
- суммарный SQL: `29,25–36,98 ms`;
- результат: 12 latest, 12 featured, 8 video, 12 latest media и 8
  recommendation rows.

Static consumer trace доказал, что
`resources/views/livewire/catalog-home-page.blade.php` не читает
`$featuredTitles` и `$latestMedia`. Эти два набора сериализуются только
`CatalogHomeResource` из полного `CatalogHomePageBuilder::data()`.
Несмотря на это, web-path выполняет title/media hydration, taxonomy eager
loads и присоединяет лишние модели к grouped card-count pass.

Рабочий anonymous full-response cache уже отвечает за `0,071–0,089 s` TTFB,
поэтому увеличение TTL не устраняет доказанный cold-path waste.

## Рассмотренные варианты

### 1. Увеличить homepage cache TTL

Отклонено. Cache HIT уже быстрый, а первый MISS и cache bypass продолжат
выполнять те же ненужные SQL и Eloquent hydration. Более долгий stale window
также не должен маскировать лишнюю работу.

### 2. Перенести секции в lazy Livewire islands

Отклонено. Это добавит browser request, loading/error state и новый
cache/SEO/no-JavaScript contract, хотя проблема находится в данных, которые
вообще не рендерятся.

### 3. Не строить API-only секции внутри `webData()`

Выбрано. Существующий builder остаётся единственным owner:

- `data()` сохраняет полную проекцию `featuredTitles` и `latestMedia`;
- `/api/v1/home` продолжает использовать `data()` и прежний Resource shape;
- `webData()` сохраняет все существующие ключи массива, но возвращает пустые
  collections для двух неиспользуемых Blade секций;
- `latestTitles`, `videoTitles`, release groups, collections,
  recommendations, metrics, facets и SEO строятся как раньше;
- recommendation exclusions для web используют scalar
  `featured_title_ids` полного snapshot, поэтому пропуск Eloquent hydration
  не возвращает featured title в рекомендации;
- card counts и personal overlay получают только реально используемые web
  card models.

## Архитектура и поток данных

1. `CatalogHomeSnapshotCache` возвращает прежний scalar snapshot.
2. `data()` вызывает shared private builder в full projection mode.
3. `webData()` передаёт limit 12 и выключает только API-only hydration.
4. Full mode выполняет прежние `orderedTitles(featured_title_ids)` и
   `orderedMedia(latest_media_ids)`.
5. Web mode подставляет две пустые Eloquent collections без соответствующих
   SQL/eager-load operations.
6. Recommendation exclusions получают полный latest snapshot, scalar
   featured snapshot и прежние hydrated video IDs.
7. Blade получает ту же фактически используемую data shape и должен
   сгенерировать byte-identical содержательный HTML.

Новый service, DTO, cache family, background job или client request не
добавляется.

## Compatibility contracts

- `/`, `/ru`, `/en`, route names, full-page Livewire и HTML остаются;
- `/api/v1/home` сохраняет stats, latest titles, featured titles,
  titles-with-video, latest releases, facets и текущую safe Resource shape;
- `CatalogHomeSnapshotCache` key/schema/TTL/invalidation не меняются;
- homepage `response_contract=2` остаётся прежним, потому что HTML shape не
  меняется;
- recommendation type/order/exclusions/ranking и authenticated shown-state
  сохраняются;
- publication, availability, audience, region, Premium, legal и
  authorization predicates не меняются;
- translations, SEO, sitemap, search, importer, administration и public
  routes не меняются;
- SQLite остаётся поддержанным, database-specific SQL не добавляется.

## Failure и rollback

Изменение read-only и не создаёт нового failure mode. Cache outage продолжает
использовать authoritative builder, но выполняет меньше работы. Rollback —
revert PHP/test/docs commit и штатный compiled cache/PHP-FPM refresh.
Database restore, cache flush, key scan, queue cleanup и asset rollback не
нужны.

## Проверка

- TDD RED подтверждает, что текущий `webData()` ошибочно возвращает
  непустые `featuredTitles` и `latestMedia`;
- GREEN подтверждает пустые API-only web collections и полный API/data
  contract;
- query listener подтверждает отсутствие media-by-ID hydration и ровно две,
  а не три, card taxonomy hydration groups в web-path;
- existing homepage, API, recommendation, cache, projection и eager-load
  contracts остаются зелёными;
- fresh multi-process builder profile сравнивает query count, SQL и wall
  time на том же snapshot;
- HTTPS и managed Chromium проверяют HTTP 200, cache HIT/MISS, HTML bytes,
  DOM, LCP, overflow и browser errors;
- Pint, Larastan, Rector, docs check и repository legacy scan завершают
  verification.
