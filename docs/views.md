# Blade-шаблоны

Обновлено: 12.07.2026

## Правило без inline PHP

- В файлах `resources/views/**/*.blade.php` нельзя использовать `@php` и `@endphp`.
- Blade-шаблоны должны оставаться декларативными: обычные директивы `@if`, `@foreach`, `@class`, `@isset`, компоненты и безопасный вывод `{{ }}`.
- Request-specific данные готовятся в контроллере или action-классе.
- Данные представления, SEO-переменные, подписи, query-string для ссылок, MIME-типы и активные состояния готовятся в `app/View/ViewData`, `app/View/ViewModels` или классах Blade-компонентов.
- Логика модели, которая является устойчивым свойством данных, должна жить в модели, accessor/cast, enum или отдельном сервисе.
- Проверка правила покрыта тестом `Tests\Unit\BladeTemplateTest`.

## Текущая структура

- `App\View\ViewData\AppLayoutData` готовит SEO, JSON-LD, метаданные и поисковые блоки для `layouts.app` через view composer в `AppServiceProvider`.
- `App\View\ViewModels\CatalogTitlesViewModel` готовит подписи фильтров и параметры ссылок каталога.
- `App\View\ViewModels\CatalogTitlesViewModel` нормализует scalar/list query-state через `scalarState()` и `listState()`, чтобы шаблоны не читали raw query-параметры напрямую.
- `App\View\ViewModels\CatalogTitlesViewModel` готовит состояние multi-select формы фильтров: скрытые поля для поиска/сортировки/расширенных параметров, выбранные годы и активные relation-значения.
- Шаблон `resources/views/catalog/titles.blade.php` может добавлять `data-catalog-filter-*` атрибуты для локального client-side поиска внутри уже отрендеренных групп фильтров; база данных из Blade не запрашивается.
- `App\View\ViewModels\CatalogShowViewModel` готовит состояние страницы тайтла: группы таксономий, выбранную серию, варианты медиа, MIME-тип видео и бейджи сезонов.
- Layout использует `x-layout.site-header` и `x-layout.site-footer`, один `<main>` и skip-link к основному содержимому.

## Компоненты

- Повторяемая разметка живет в `resources/views/components`; если компонент вычисляет классы, ссылки или состояние, добавляйте класс в `app/View/Components`.
- Общие UI-компоненты размещайте в пространстве `x-ui.*`, доменные элементы каталога - в `x-catalog.*`.
- Компоненты форм размещайте в `x-form.*`; поисковые поля используют `x-form.search-field`, а ошибки - `x-form.input-error`.
- Компоненты получают готовые модели, коллекции или ViewModel-объекты и не выполняют запросы к базе.
- В компонентных шаблонах используйте `$attributes->merge()` или `$attributes->class()` для расширяемых классов и атрибутов.
- `x-title-card` и `x-title-list-row` дают тайтлу один основной tab-stop; ссылки справочников остаются отдельными доступными ссылками поверх stretched-link.
- `x-title-poster` по умолчанию использует `object-contain`, чтобы постеры не обрезались на главной, в списках и на странице тайтла.
- Стандартный вызов `$paginator->links()` использует русский светлый override `vendor.pagination.tailwind`.
- Пустая выдача каталога показывает точный запрос; запрос только из стоп-слов получает отдельное сообщение «слишком общий». Пустое состояние не подменяется ближайшими карточками.
