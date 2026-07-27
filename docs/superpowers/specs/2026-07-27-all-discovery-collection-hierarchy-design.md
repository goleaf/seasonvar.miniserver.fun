# Единая иерархия подборок во всех discovery modes

Дата: 27.07.2026.

Статус: согласовано прямым запросом вернуть список категорий и подкатегорий
на всех страницах `/discover/***` и делегированием рекомендуемого решения без
дополнительных вопросов.

## Контекст

Task 109 восстановил полный активный двухуровневый справочник внутри
`CatalogCollectionExplorer`, но `CatalogDiscoveryPage` продолжает монтировать
этот компонент только при `type=popular`. Поэтому `/discover/popular`
показывает 5 категорий и 31 подкатегорию, а остальные восемь валидных modes не
содержат collection section вообще. Ограничение находится в parent render
data и Blade mount, а не в route, schema, query или самом explorer.

## Рассмотренные варианты

1. Скопировать markup и collection queries в каждый mode. Отклонено:
   появятся девять расходящихся UI/query boundaries и риск N+1.
2. Подключить один существующий `CatalogCollectionExplorer` во всех modes.
   Выбрано: сохраняет одну реализацию, полный справочник, независимый URL
   state и mode-specific recommendation ranking.
3. Вернуть отдельный `/collections` directory. Отклонено: постоянный
   системный контракт запрещает этот маршрут, а пользователь просит именно
   `/discover/***`.

## UX и anchors

- Каждый из девяти поддерживаемых modes показывает compact section
  navigation, затем один collection explorer и затем выдачу сериалов.
- `#collections` стабилен во всех modes.
- `popular` сохраняет публично совместимый `#popular-titles`.
- Остальные modes используют общий `#discovery-titles`; это не создаёт новых
  route/query contracts.
- Справочник остаётся единым nested list на mobile/desktop: положительные
  узлы фильтруют коллекции, нулевые видимы как неинтерактивные строки.
- Глобальная ссылка «Подборки» продолжает вести на
  `/discover/popular#collections`.

## Data flow

`CatalogDiscoveryPage::render()` всегда передаёт две ссылки секций, mode-aware
anchor выдачи и стабильный child key из mode+locale.
`CatalogCollectionExplorer` сохраняет собственные URL-backed
`collections_q`, `collections_sort`, `collections_category`,
`collections_subcategory` и `collectionsPage`; recommendation filters,
ranking, refresh и paginator не меняются. Blade только размещает уже
существующий nested component до results и не выполняет запросов.

## Совместимость и impact

Не меняются route names, поддерживаемые modes, collection query keys, public
eligibility, category dictionary, DB schema/data, API, sitemap, translations,
permissions, imports, recommendation algorithms, cache key format или
invalidators. Clean request каждого indexable mode сохраняет прежний
canonical; collection state получает canonical текущего mode и
`noindex,follow`. Неиндексируемые modes сохраняют существующую SEO policy.

Каждая страница получает один и тот же фиксированный bounded collection
overhead: один explorer, один tree/count path и не более 12 карточек отдельным
paginator. Он не зависит от числа recommendation cards и не умножается по
категориям.

## Verification

- PHPUnit RED/GREEN на explorer и anchors во всех девяти modes, включая
  localized non-popular route.
- Existing discovery SEO, collection category и query-budget tests.
- Pint для изменённых PHP-файлов.
- Vite production build.
- Playwright desktop/mobile для `popular`, `random`, `editorial` и localized
  non-popular route; hierarchy, order, anchors и отсутствие overflow/errors.
- Read-only production SSR smoke по всем девяти URLs после доставки.

## Rollback

Вернуть popular-only parent render data и conditional mount. Миграции,
production data restore, cache flush, dependency rollback и route rollback не
нужны.
