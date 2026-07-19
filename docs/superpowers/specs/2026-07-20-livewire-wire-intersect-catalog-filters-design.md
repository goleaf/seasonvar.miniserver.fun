# Livewire `wire:intersect` catalog filters design

Дата: 20.07.2026

## Цель

Закрепить существующую one-time viewport-загрузку тяжёлых фасетов каталога как доступный и проверяемый Livewire 4 contract. Первичный SSR результатов, обычная пагинация, SEO и browser history должны сохраниться.

## Официальный контракт и текущая граница

Livewire 4 выполняет action из `wire:intersect` при входе элемента в viewport; `:enter` является default, `:leave` запускает action при выходе. `.once` ограничивает вызов одним входом, а `.half`, `.full`, `.threshold.*` и `.margin.*` меняют порог observer.

В каталоге уже используется `@island(name: 'catalog-live', lazy: true)`. Установленный `livewire/livewire v4.3.3` преобразует первый элемент island placeholder в `wire:intersect.once="__lazyLoadIsland"`. Поэтому приложение получает правильный официальный contract без публичного `loadFacets()` action, ручного `IntersectionObserver` или Alpine duplicate. Результаты каталога остаются в первом SSR response, а viewport request загружает только тяжёлый граф фильтров.

## Рассмотренные варианты

1. Выбранный: сохранить lazy island и усилить его loading placeholder семантикой `role="status"` рядом с существующими `aria-live="polite"` и `aria-busy="true"`; regression-тест проверяет один generated `.once` trigger и доступный status.
2. Infinite scroll через `wire:intersect="loadMore"`. Вариант отклонён: он заменил бы доступные ссылки пагинации, усложнил SEO/back-forward behavior и увеличивал бы Livewire payload по мере прокрутки.
3. Server-driven lazy images или visibility analytics. Вариант отклонён: для изображений уже уместнее browser-native loading, а analytics добавила бы privacy/write boundary и лишние запросы без подтверждённого product requirement.

Модификаторы `.half`, `.full`, `.threshold.*` и `.margin.*` не добавляются. Lazy-island owner поддерживает только canonical `.once`, текущий placeholder расположен в нормальном потоке страницы, а vendor patch или дублирующий authored directive создали бы хрупкую зависимость от внутреннего `__lazyLoadIsland` action.

## Render и accessibility flow

Первый request серверно рендерит поисковую форму, результаты и placeholder фильтров. Livewire добавляет `.once` к корневому `#catalog-filters`; `aria-busy="true"` описывает незавершённую область. Вложенный `data-catalog-facets-loading` получает `role="status"` и сохраняет `aria-live="polite"` с существующей локализованной строкой `catalog.catalog.filters.loading`.

При входе placeholder в viewport Livewire вызывает внутренний `__lazyLoadIsland` ровно один раз и morph-заменяет placeholder готовым `<x-catalog.unified-title-filters>`. Новый application action, component state, query или client-side observer не создаётся.

## Cross-feature, безопасность и production impact

- Публичные route names, validation, search/filter state, named pagination island, canonical URLs и SSR results не меняются.
- Authentication, authorization, administration, audit, premium, regional/legal rules и privacy data отсутствуют в этой read-only boundary.
- Новых database queries вне существующего lazy request, cache keys, queue jobs, notifications, imports, storage или external calls нет.
- RU/EN translation keys и visible copy сохраняются; hardcoded пользовательский текст не добавляется.
- CSS, JavaScript, Vite dependencies, framework/package versions, environment и production services не меняются.
- На mobile/desktop сохраняется один normal-flow блок без nested scrolling; motion поведение не расширяется.

## Ошибки, rollback и восстановление

Если JavaScript недоступен или lazy request завершился ошибкой, server-rendered каталог и обычная пагинация остаются доступными; placeholder честно остаётся busy и не выдаёт готовые фасеты. Retry/control не изобретается в рамках этой директивы. Rollback локален: удалить `role="status"` и связанные test/docs records; data migration, cache flush, asset rollback или downtime не нужны.

## Проверки

- RED доказывает, что generated `.once` placeholder ещё не имеет `role="status"`.
- GREEN проверяет один `wire:intersect.once="__lazyLoadIsland"`, `#catalog-filters`, `aria-busy="true"`, `data-catalog-facets-loading`, `role="status"` и `aria-live="polite"` в initial response.
- Существующий deferred-island helper подтверждает successful `__lazyLoadIsland` response и готовые filter controls.
- Focused catalog tests, Vite build, managed docs check, diff/legacy scans и full suite выполняются перед завершением.

## Самопроверка дизайна

Решение использует уже поддерживаемую Livewire boundary, не раскрывает internal action в application code, не меняет pagination/SEO и не вводит новый запрос сверх существующего lazy island. Все modifiers оценены; отсутствие margin/threshold осознанно. Неразрешённых design gaps нет.
