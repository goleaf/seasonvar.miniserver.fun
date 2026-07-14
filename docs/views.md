# Blade-шаблоны

Обновлено: 14.07.2026

## Правило без inline PHP

- В файлах `resources/views/**/*.blade.php` нельзя использовать `@php`, `@endphp`, `<?php` и `<?=`.
- Blade не вызывает Eloquent/database, Cache/Redis/Memcached, service container, `env()`, filesystem и не готовит переменные closure/collection transformations. Это относится и к Livewire Blade views.
- Volt и anonymous Livewire PHP внутри Blade запрещены; component class и view всегда находятся в отдельных файлах.
- Blade-шаблоны должны оставаться декларативными: обычные директивы `@if`, `@foreach`, `@class`, `@isset`, компоненты и безопасный вывод `{{ }}`.
- Request-specific данные готовятся в контроллере или action-классе.
- Данные представления, SEO-переменные, подписи, query-string для ссылок, MIME-типы и активные состояния готовятся в `app/View/ViewData`, `app/View/ViewModels` или классах Blade-компонентов.
- Логика модели, которая является устойчивым свойством данных, должна жить в модели, accessor/cast, enum или отдельном сервисе.
- Проверка правила покрыта тестом `Tests\Unit\BladeTemplateTest`.

## Текущая структура

- `App\View\ViewData\AppLayoutData` готовит SEO, JSON-LD, метаданные и поисковые блоки для `layouts.app` через view composer в `AppServiceProvider`.
- `App\View\ViewModels\CatalogTitlesViewModel` готовит подписи фильтров и параметры ссылок каталога.
- `App\Support\CatalogAlphabet` без запросов к базе задаёт канонический порядок символов, кириллицы и `A`–`Z`; `CatalogTitlesViewModel` и `CatalogDirectoryPageBuilder` передают Blade готовые группы. Query-free компонент `x-catalog.alphabet-filter` только выводит эти группы и готовые query-ссылки каталога.
- `App\Livewire\CatalogSeries` разделяет render-local данные на computed `catalogPage` и `catalogFacets`; Eloquent-коллекции не хранятся в публичных properties. Связанные Livewire islands `catalog-live` атомарно обновляют фильтры и карточки, а первый SSR не вычисляет facets. Карточки и строки используют `catalog-title-{id}` как стабильный `wire:key`.
- `App\View\ViewModels\CatalogTitlesViewModel` нормализует scalar/list query-state через `scalarState()` и `listState()`, чтобы шаблоны не читали raw query-параметры напрямую.
- `App\View\ViewModels\CatalogTitlesViewModel` готовит состояние единой формы фильтров: скрытые поля содержат только параметры без видимого control, поэтому годы, справочники, публикация, субтитры и расширенные поля не дублируются. ViewModel также готовит общий active count и максимальный календарный год; вычисления в Blade не дублируются.
- Класс-компонент `App\View\Components\Catalog\UnifiedTitleFilters` рендерит единую GET/Livewire-форму в отдельном deferred sibling island, а `App\View\Components\Catalog\TitleFilters` получает готовый массив `catalogFacets` и добавляет в неё годы и справочники. Компоненты не выполняют запросы; checkbox/select используют `wire:model.live`, а форма явно адресует связанные islands через `wire:island="catalog-live"`.
- Шаблоны `resources/views/catalog/titles.blade.php` и `resources/views/components/catalog/title-filters.blade.php` могут добавлять `data-catalog-filter-*` атрибуты для локального client-side поиска внутри уже отрендеренных групп фильтров; база данных из Blade не запрашивается.
- `App\View\ViewModels\CatalogShowViewModel` готовит состояние страницы тайтла: группы таксономий, выбранную серию, варианты медиа, MIME-тип видео, бейджи сезонов и подпись playback-профиля для каждой видимой серии.
- `resources/views/catalog/show.blade.php` остаётся компактной layout/SEO точкой входа. Полную SSR и динамическую разметку тайтла рендерит `resources/views/livewire/catalog-title-detail.blade.php`; Blade читает уже подготовленные page-builder данные и безопасное refresh state без запросов к cache или базе.
- `App\Livewire\CatalogTitlePlayer` передаёт в свой Blade только render-local summaries, серии одного сезона, media и `CatalogShowViewModel`; публичные properties ограничены locked title ID и небольшими URL-скалярами.
- Layout использует `x-layout.site-header` и `x-layout.site-footer`, один `<main>` и skip-link к основному содержимому.

## Компоненты

- Повторяемая разметка живет в `resources/views/components`; если компонент вычисляет классы, ссылки или состояние, добавляйте класс в `app/View/Components`.
- Общие UI-компоненты размещайте в пространстве `x-ui.*`, доменные элементы каталога - в `x-catalog.*`.
- Компоненты форм размещайте в `x-form.*`; поисковые поля используют `x-form.search-field`, а ошибки - `x-form.input-error`.
- Компоненты получают готовые модели, коллекции или ViewModel-объекты и не выполняют запросы к базе.
- В компонентных шаблонах используйте `$attributes->merge()` или `$attributes->class()` для расширяемых классов и атрибутов.
- `x-ui.poster-frame` — единственный Blade boundary для `<img>` каталожного постера: готовый URL и alt передаются вызывающим слоем, изображение использует cover + 2% overscan, а background появляется только у заглушки. Единственное исключение — широкий recommendation frame, который передаёт `overscan=false`, заполняет ширину и допускает crop по высоте.
- `x-ui.poster-card` собирает один внешний shell и poster frame в строгих layout `grid`, `horizontal`, `compact`, `recommendation`; он не создаёт wrapping anchor. Recommendation layout не рисует собственную рамку или тень, потому что строки группирует один родительский ordered list.
- `x-catalog.title-card` даёт `CatalogTitle` один основной tab-stop во всех сетках, списках, поиске и рекомендациях; ссылки справочников остаются отдельными доступными ссылками поверх stretched-link. Компонент не вызывает lazy loading и читает только агрегатные атрибуты или уже загруженные relations.
- `CatalogTitlePageBuilder` передаёт Blade одну коллекцию `recommendationItems`: precomputed `v3` и объединённый genre/year fallback используют одинаковые строки, последовательные ranks и дедупликацию по ID. Blade не объединяет коллекции и не выполняет запросы.
- Специализированная карточка новой серии готовит подписи в PHP/page builder и составляет shell через `x-ui.poster-card`; история просмотра и `/stats` также используют общий shell, не передавая туда авторизацию или проверку внешнего URL.
- Публичное имя тайтла берётся из `CatalogTitle::display_title`: совпадающий суффикс `/original_title` не повторяется в основном заголовке, а `display_original_title` выводится отдельной вторичной строкой. Исходные поля базы и поисковый индекс не изменяются.
- Стандартный вызов `$paginator->links()` использует русский светлый override `vendor.pagination.tailwind`; Livewire callers передают scoped `scrollTo` в отдельный override `vendor.livewire.tailwind`.
- Пустая выдача каталога показывает точный запрос; запрос только из стоп-слов получает отдельное сообщение «слишком общий». Пустое состояние не подменяется ближайшими карточками.
