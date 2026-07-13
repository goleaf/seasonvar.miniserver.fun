# Blade-шаблоны

Обновлено: 13.07.2026

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
- `App\Livewire\CatalogSeries` передаёт в Blade только render-local paginator/facet/view-model данные; Eloquent-коллекции не хранятся в публичных properties. Карточки и строки используют `catalog-title-{id}` как стабильный `wire:key`.
- `App\View\ViewModels\CatalogTitlesViewModel` нормализует scalar/list query-state через `scalarState()` и `listState()`, чтобы шаблоны не читали raw query-параметры напрямую.
- `App\View\ViewModels\CatalogTitlesViewModel` готовит состояние multi-select формы фильтров: скрытые поля для поиска/сортировки/буквы, выбранные годы и активные relation-значения. Для блока «Точный подбор» ViewModel также готовит точный active count, GET reset query и максимальный календарный год; вычисления в Blade не дублируются.
- Шаблон `resources/views/catalog/titles.blade.php` может добавлять `data-catalog-filter-*` атрибуты для локального client-side поиска внутри уже отрендеренных групп фильтров; база данных из Blade не запрашивается.
- `App\View\ViewModels\CatalogShowViewModel` готовит состояние страницы тайтла: группы таксономий, выбранную серию, варианты медиа, MIME-тип видео, бейджи сезонов и подпись playback-профиля для каждой видимой серии.
- `App\Livewire\CatalogTitlePlayer` передаёт в свой Blade только render-local summaries, серии одного сезона, media и `CatalogShowViewModel`; публичные properties ограничены locked title ID и небольшими URL-скалярами.
- Layout использует `x-layout.site-header` и `x-layout.site-footer`, один `<main>` и skip-link к основному содержимому.

## Компоненты

- Повторяемая разметка живет в `resources/views/components`; если компонент вычисляет классы, ссылки или состояние, добавляйте класс в `app/View/Components`.
- Общие UI-компоненты размещайте в пространстве `x-ui.*`, доменные элементы каталога - в `x-catalog.*`.
- Компоненты форм размещайте в `x-form.*`; поисковые поля используют `x-form.search-field`, а ошибки - `x-form.input-error`.
- Компоненты получают готовые модели, коллекции или ViewModel-объекты и не выполняют запросы к базе.
- В компонентных шаблонах используйте `$attributes->merge()` или `$attributes->class()` для расширяемых классов и атрибутов.
- `x-title-card` и `x-title-list-row` дают тайтлу один основной tab-stop; ссылки справочников остаются отдельными доступными ссылками поверх stretched-link.
- `x-title-poster` по умолчанию использует `object-cover`: изображение заполняет весь заданный frame, допускает небольшой боковой crop и не добавляет внутреннюю ring/border-рамку поверх структурной рамки карточки.
- Публичное имя тайтла берётся из `CatalogTitle::display_title`: совпадающий суффикс `/original_title` не повторяется в основном заголовке, а `display_original_title` выводится отдельной вторичной строкой. Исходные поля базы и поисковый индекс не изменяются.
- Стандартный вызов `$paginator->links()` использует русский светлый override `vendor.pagination.tailwind`.
- Пустая выдача каталога показывает точный запрос; запрос только из стоп-слов получает отдельное сообщение «слишком общий». Пустое состояние не подменяется ближайшими карточками.
