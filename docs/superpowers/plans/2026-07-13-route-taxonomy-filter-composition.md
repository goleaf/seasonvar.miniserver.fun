# План реализации составных route-фильтров каталога

> Реализация выполняется в текущей сессии на существующей ветке `main`. Отдельная ветка или worktree не создаются по правилам проекта.

**Цель:** сделать route-taxonomy и route-год обычной частью множественного состояния фильтров, чтобы одна группа работала через OR, разные группы — через AND, а UI, URL и выдача не расходились.

**Архитектура:** `CatalogSeries` гидратирует route-контекст в `CatalogSeriesFilters`. `CatalogTitlesPageBuilder` объединяет route-slug с query-значениями вместо замены массива. Blade отражает вычисленное выбранное состояние в checkbox. Существующий `CatalogTitleQuery` продолжает применять relation IDs через один `whereIn` на группу.

## 1. Регрессионные тесты

- Проверить начальную гидратацию route-страны.
- Добавить вторую страну и проверить OR по результатам.
- Добавить жанр и проверить AND между группами.
- Проверить route-жанр плюс второй жанр.
- Проверить обычный HTTP GET taxonomy-route и серверный `checked`.
- Проверить route-год плюс второй год.

## 2. Единая гидратация

- До валидации объединить route-taxonomy с соответствующим query-массивом через allowlist `CatalogSeriesFilters::TAXONOMY_PROPERTIES`.
- Тем же helper добавить route-год первым значением `filters.years`.
- Сохранить лимиты и нормализацию `CatalogTitlesRequest`.
- При служебном redirect не дублировать route-значение в query string.

## 3. Одинаковая серверная семантика

- В `CatalogTitlesPageBuilder` объединить route-slug с уже запрошенными slug.
- Удалить дубликаты и сохранить route-slug первым.
- Сохранить 404 для несуществующего route-slug.
- Не менять `CatalogTitleQuery`: OR внутри relation-группы и AND между группами уже реализованы.

## 4. Состояние checkbox

- Добавить серверный `checked` для годов, taxonomy, типов публикации, субтитров и качества.
- Сохранить `wire:model.live`, GET fallback, touch-targets и существующие Tailwind-классы.
- Не добавлять JavaScript-дублирование состояния.

## 5. Проверка качества

- `./vendor/bin/pint` для изменённых PHP-файлов.
- Focused PHPUnit, соседние catalog tests и полный `php artisan test`.
- `npm run build`.
- Browser QA на `1440×1200` и `390×844`: Великобритания → США → жанр; затем refresh/back, overflow и console/network errors.
- Проверка `git status --short --branch` и commit только файлов этой задачи на `main`.
