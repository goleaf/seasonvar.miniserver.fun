# Дизайн компактной web-проекции главной страницы

Дата: 26.07.2026.

Статус: approved. Пользователь многократно поручил выполнить рекомендуемый
вариант без дополнительного согласования.

## Контекст и измеренный root cause

Предыдущие этапы устранили SQL-задержки и import/cache contention. Свежий
read-only профиль текущего production-like ответа подтвердил:

- anonymous cache `HIT`: `0,075–0,119 s`;
- anonymous cache `BYPASS`: `0,387–0,531 s`;
- обычный managed Chromium: FCP/LCP `0,30–0,51 s`;
- управляемый mobile-профиль с `4×` CPU throttling и ограниченным 4G:
  LCP `1,436 s`, DOMContentLoaded `1,938 s`;
- uncompressed HTML: `715 641` bytes, gzip transfer около `39,3 KB`;
- `3 983` DOM nodes, `76` poster elements и mobile document height
  `30 354 px`.

Основной остаточный объём создаёт не сеть и не новый SQL bottleneck, а web
presentation: 48 полноразмерных карточек блока «Последние обновления»
занимают `319 741` bytes. Все homepage title cards вместе занимают
`415 855` bytes, а двенадцать bounded release groups — ещё `124 915` bytes.

Полный snapshot из 48 factual updates остаётся полезным для `/api/v1/home`
и внутренних consumers. Однако web SSR не обязан одновременно выводить все
48 тяжёлых карточек: это увеличивает parsing, style/layout work, PHP render,
DOM и длину страницы, хотя полный каталог уже доступен через canonical
discovery route.

## Рассмотренные варианты

### 1. Оставить payload без изменения и добавить только regression budget

Отклонено как недостаточное. Gate предотвратил бы дальнейший рост, но не
устранил бы измеренные `319 741` bytes и 48 повторных карточных деревьев.

### 2. Перенести блок в lazy/deferred Livewire island

Отклонено. Это добавило бы второй HTTP/Livewire request, loading state,
cache/invalidation lifecycle и зависимость от JavaScript для содержательной
части главной. SEO-critical и обычная no-JavaScript навигация стали бы
сложнее, а общий объём после загрузки не уменьшился бы.

### 3. Ограничить только web SSR двенадцатью свежими тайтлами

Выбрано. Существующий `CatalogHomePageBuilder` остаётся единственным owner:

- `data()` сохраняет полный 48-row contract для `/api/v1/home` и текущих
  внутренних consumers;
- новый явный `webData()` использует ту же private build boundary, но
  гидратирует максимум 12 первых snapshot IDs для HTML;
- full 48-row scalar snapshot, ordering и API shape не меняются;
- recommendation exclusions продолжают использовать полный snapshot, чтобы
  состав рекомендации не менялся только из-за presentation cap;
- web SEO ItemList получает фактически показанные 12 элементов;
- под списком выводится обычная локализованная ссылка «Показать все» на
  существующий indexable `/discover/recently_updated`;
- новый запрос, Livewire island, schema, index, dependency, configuration
  или environment variable не добавляются.

Двенадцать соответствует уже существующей homepage overview density:
featured titles, content-addition title groups и latest media используют
bounded наборы `12`. Это не меняет полные сезоны, серии, media или каталог.

## Поток данных

1. `CatalogHomeSnapshotCache` возвращает прежние 48
   `latest_title_ids/latest_title_updates`.
2. API вызывает `CatalogHomePageBuilder::data()` и получает прежнюю полную
   проекцию.
3. Full-page Livewire вызывает `CatalogHomePageBuilder::webData()`.
4. Builder до Eloquent hydration берёт первые 12 stable ordered IDs.
5. Taxonomy eager loads, card-count hydration, release groups, card state и
   Blade работают только с этими 12 web rows.
6. Recommendation exclusion объединяет все 48 snapshot IDs с прежними
   featured/video IDs, поэтому recommendation result contract сохраняется.
7. Blade выводит link на canonical localized `recently_updated` discovery.

## Cache и deployment

Форма guest homepage HTML меняется, поэтому `PublicPageCachePolicy` добавляет
homepage `response_contract=2`. Старые envelopes становятся недостижимыми без
scan, flush или удаления чужих keys; после TTL они истекают естественно.
Первый запрос нового namespace использует обычный `MISS`, а существующий
warmer может заполнить его до visitor traffic.

Rollback возвращает прежний код и прежнюю dimension shape. Database, snapshot,
queue и cache cleanup не нужны; старые entries остаются ограничены текущими
TTL. Production activation следует существующему locked install/compiled
cache/PHP-FPM/worker/warm workflow, без DDL и DML.

## Совместимость

- `/`, `/ru`, `/en`, route names, full-page Livewire и public cache остаются;
- `/api/v1/home` сохраняет полный 48-row snapshot и Resource shape;
- stable content-added ordering, availability, audience, region, Premium,
  legal и publication predicates не меняются;
- recommendation type, candidate exclusions, score и remember-shown
  остаются прежними;
- полные сезоны, серии, media, search, sitemap, importer и administration
  не меняются;
- RU/EN используют существующий `home.actions.view_all`, новый translation
  key не нужен;
- no-JavaScript navigation, accessibility и mobile single-column flow
  сохраняются.

## Проверка

- RED/GREEN test разделяет полный `data()` и 12-row `webData()`;
- HTTP test фиксирует не более 12 latest-update cards, ссылку на
  `recently_updated`, полный API contract и bounded HTML bytes;
- cache-policy test фиксирует homepage `response_contract=2`;
- existing homepage/content/recommendation/API/cache suites проверяют
  отсутствие semantic drift и N+1;
- Pint, Larastan, Rector, Vite build и managed Chromium desktop/mobile
  подтверждают status, cache headers, FCP/LCP, DOM/bytes, overflow,
  console/page/request failures;
- live read-only series выполняется без cache flush, generation bump или
  production DML.
