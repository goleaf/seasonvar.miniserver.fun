# Единый UX Livewire pagination islands — design

Дата: 19.07.2026

## Цель

Все видимые пагинаторы портала должны переключать только собственный Livewire island, немедленно показывать локальное состояние загрузки и начинать мягкую прокрутку к началу обновляемого блока. Конечная позиция учитывает фактическую высоту перекрывающей верхней навигации на текущем breakpoint, safe-area внутри шапки и дополнительный отступ `1rem`, поэтому содержимое не прижимается к меню.

## Проверенный исходный контекст

- В финальном движущемся inventory найдено `54` вызова `links()` в `40` Blade-шаблонах; все class-based Livewire-компоненты с пагинацией включены в единый contract.
- `resources/views/vendor/livewire/tailwind.blade.php` уже является общим progressive-enhancement pagination view: сохраняет `href`, именованные paginator actions и русские подписи.
- `resources/js/app.js` уже умеет выполнять post-morph easing, но только шесть вызовов `links()` задают точную цель, spinner общего назначения отсутствует, а offset основан на статических `scroll-mt-*` классах.
- Шапка `[data-site-header]` становится `sticky` только на `lg`; её высота меняется вместе с viewport, переносом навигации, zoom и safe-area. Фиксированное число для всех разрешений неверно.
- Официальный контракт [Livewire 4 pagination](https://livewire.laravel.com/docs/4.x/pagination) сохраняет URL page state, поддерживает named paginators и selector-based scroll target. [Livewire 4 islands](https://livewire.laravel.com/docs/4.x/islands) изолируют morph, а `always: true` синхронизирует island при обычном parent update. [Livewire 4 loading states](https://livewire.laravel.com/docs/4.x/loading-states) предоставляет scoped `data-loading` на элементе, который отправил запрос.

## Рассмотренные подходы

### 1. Shared frame/runtime + feature-scoped named islands — выбран

Каждый result block получает именованный `@island`, общий `x-ui.pagination-region` и собственный paginator page name. Общий pagination view остаётся единственным producer controls, а один Vite module отвечает за прокрутку. Плюсы: точный morph, один spinner/scroll contract, сохранённые query parameters и no-JS links. Цена: все существующие paginated templates должны быть явно размечены, а render data должны быть доступны island через component computed data.

### 2. Только глобальный JavaScript поверх текущих paginator links — отклонён

Это минимальный diff, но Livewire продолжил бы morph всего компонента. Подход не выполняет прямое требование islands, а multiple-paginator pages всё равно не знают точную обновляемую область.

### 3. Отдельный дочерний Livewire-компонент для каждого списка — отклонён

Он даёт изоляцию, но создаёт десятки новых component/query/authorization boundaries, усложняет parent state и URL synchronization и дублирует существующие доменные queries. Для presentation optimization это неоправданная архитектура.

## Архитектура

### Shared pagination frame

Новый пассивный Blade-компонент `resources/views/components/ui/pagination-region.blade.php` принимает стабильный `name` и выводит:

- wrapper `data-pagination-region="<name>"` и `data-pagination-scroll-target`;
- `aria-busy="false"` и неизменную геометрию блока;
- локальный overlay/status со spinner и `pagination.loading`;
- контейнер `data-pagination-content` для старого результата, который остаётся видимым и слегка приглушается только пока control имеет Livewire `data-loading`;
- slot с подготовленным содержимым и pagination controls.

Компонент не выполняет queries, authorization, route/config/service calls и не принимает Eloquent models как public state. Он только оформляет уже подготовленный slot.

### Shared Livewire pagination view

`resources/views/vendor/livewire/tailwind.blade.php` остаётся единственным Livewire pagination view. Каждый интерактивный control сохраняет:

- реальный `href`, `rel`, aria-label и `wire:key`;
- named `previousPage`, `nextPage` или `gotoPage` action;
- `data-pagination-control` и `data-pagination-page-name`;
- automatic Livewire `data-loading` styling, не требующий broad `wire:target`.

Paginator не выполняет встроенный `scrollIntoView`; прокруткой владеет только application Vite runtime. Обычный Laravel pagination view не меняет server-side/API behavior.

### Named islands and prepared data

Каждая область с `links()` помещает именно изменяемый список, empty state и его paginator внутрь уникального named island. Island объявляется вне `@if`/`@foreach`, а условия находятся внутри. Для parent filters/search/mutations используется `always: true`; pagination action внутри island morph-ит только его.

Render-local paginator нельзя неявно захватывать. Компонент предоставляет typed `#[Computed]` presentation array, использующий уже существующие boot-injected query/services. Root `render()` и `@island(with: $this->...)` читают один контракт. На страницах с несколькими paginator каждый region получает уникальное имя и существующий уникальный `pageName`; доменные query, sorting, authorization и page names не меняются.

Существующий каталог сохраняет grouped `catalog-live` controls. Results получают вложенный `catalog-pagination` island с `always: true`: filter actions обновляют внешний group и вложенный result, а paginator action обновляет только вложенный result.

### Scroll lifecycle

На primary click без modifier keys runtime:

1. находит ближайший `[data-pagination-region]` и его `[data-pagination-scroll-target]`;
2. немедленно запускает одну animation-frame прокрутку, пока Livewire показывает spinner и сохраняет старое содержимое;
3. после `island.morphed`/`morphed` повторно находит новый target и мягко исправляет только значимое расхождение;
4. на `interceptMessage` finish/error/failure очищает pending target, чтобы не прокручивать страницу после несвязанного запроса;
5. на `livewire:navigating`, pagehide и новом pagination click отменяет прежнюю animation.

Расчёт конечной координаты не использует user agent или breakpoint constant. Если `[data-site-header]` имеет computed `position: sticky|fixed`, runtime берёт его фактический `getBoundingClientRect().bottom`; для static header offset равен нулю. Затем добавляется CSS variable `--pagination-scroll-gap: 1rem`. Safe-area уже включена в измеренную геометрию header.

Основная анимация использует спокойный `easeInOutCubic` и distance-bounded duration около `520–820 ms`. Коррекция меньше `24 px` выполняется без второй заметной анимации. При `prefers-reduced-motion: reduce` переход мгновенный.

### Loading and failure behavior

Spinner появляется только когда control внутри конкретного region получил `data-loading`; другие paginator regions и другие Livewire actions не затрагиваются. На быстрых ответах не вводится искусственная минимальная задержка. Старый контент сохраняет высоту и остаётся читаемым, но получает сниженный opacity и блокировку повторного pointer interaction; active control уже защищён Livewire loading state.

Livewire автоматически снимает `data-loading` при success/failure. Network/server failure оставляет прежний result и URL state; global application error handling остаётся каноническим. Spinner имеет `role="status"`, `aria-live="polite"`, `aria-atomic="true"`; wrapper получает truthful `aria-busy`. Focus насильно не переносится, чтобы pagination не ломала keyboard context.

## Compatibility и cross-feature impact

- Сохраняются все route names, paginator page names, URL query strings, back/forward history, `href` fallback, locale и `rel=prev|next`.
- Queries, Eloquent pagination, cache identities, SEO/sitemap, notifications, authorization, privacy, premium, region/legal access, imports, administration actions и API не меняются.
- Новые dependencies, migrations, config/environment values, queue/cron, cache flush и production services не нужны.
- Результат остаётся полезным без JavaScript: ссылки выполняют обычный переход. Islands и animation являются progressive enhancement.

## Тестирование

### Automated contracts

1. Новый inventory test сначала падает на найденных paginator calls и требует, чтобы каждый был внутри pagination island/region с уникальным name и prepared `with` contract; финальный baseline — `54` links в `40` templates.
2. Shared view test проверяет `href`, named actions, data-loading controls, region metadata и отсутствие package-owned inline `scrollIntoView`/`@php`.
3. Frontend asset contract проверяет dynamic header measurement, `1rem` gap, reduced motion, immediate click scroll, post-island-morph correction и error/finish cleanup.
4. Focused Livewire feature tests проверяют default и named paginator URL state, multiple independent paginators и то, что page action возвращает island fragment без full component replacement.
5. Translation parity проверяет `pagination.loading` для `ru` и `en`.

### Browser matrix

Playwright проверяет минимум один public single-paginator page, каталог с nested island и один authenticated/admin multi-paginator fixture на `390×844`, tablet и desktop. Assertions: spinner видим во время delayed response, старый контент не исчезает, только target island morphs, итоговый target ниже sticky header минимум на `1rem`, mobile static header не создаёт ложный offset, URL/back-forward корректны, overflow/console/page/first-party request errors отсутствуют. Reduced-motion context проверяет отсутствие animation frames длительного перехода.

## Rollout и rollback

Rollout — обычный code/assets deploy: tests, Pint для PHP, Vite build/manifest, browser smoke, затем production HTTP/asset verification. Database backup и migration window не нужны; persistent data не затрагивается. Rollback — scoped revert shared component/pagination view/runtime/styles, island/computed-data refactors, translations, tests и docs с восстановлением прежнего `links()` behavior. Старые URL и server pagination остаются совместимыми в обе стороны.
