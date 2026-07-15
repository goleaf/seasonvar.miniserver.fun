# Blade-шаблоны

Обновлено: 15.07.2026

## Правило без inline PHP

- В файлах `resources/views/**/*.blade.php` нельзя использовать `@php`, `@endphp`, `<?php` и `<?=`.
- Blade не вызывает Eloquent/database, Cache/Redis/Memcached, service container, `env()`, `request()`, `config()`, auth/gate directives, filesystem и не готовит переменные closure/collection transformations. Это относится и к Livewire Blade views.
- Volt и anonymous Livewire PHP внутри Blade запрещены; component class и view всегда находятся в отдельных файлах.
- Blade-шаблоны должны оставаться декларативными: обычные директивы `@if`, `@foreach`, `@class`, `@isset`, компоненты и безопасный вывод `{{ }}`.
- Request-specific данные готовятся в контроллере или action-классе.
- Данные представления, SEO-переменные, подписи, query-string для ссылок, MIME-типы и активные состояния готовятся в `app/View/ViewData`, `app/View/ViewModels` или классах Blade-компонентов.
- Логика модели, которая является устойчивым свойством данных, должна жить в модели, accessor/cast, enum или отдельном сервисе.
- Проверка правила покрыта тестом `Tests\Unit\BladeTemplateTest`.

## Текущая структура

- `App\View\ViewData\AppLayoutData` через view composer в `AppServiceProvider` готовит explicit layout contract: SEO scalars, normalized breadcrumbs/flags, hex-safe JSON-LD strings и header/footer URL, active/audience/permission state с полными Tailwind class maps как immutable `LayoutNavigationItem`. Blade только проверяет готовые flags, итерирует готовые элементы и выводит один audited raw JSON-LD scalar; `json_encode()` в шаблонах запрещён regression-тестом.
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
- `x-ui.poster-frame` — единственный Blade boundary для `<img>` каталожного постера: готовые URL, alt и `fit` передаются вызывающим слоем. Контентная строка использует `contain`, `2:3` и `overscan=false`; явно выбранный `cover` с 2% overscan остаётся для главного постера и технических миниатюр. Background появляется только у заглушки.
- `x-ui.poster-card` собирает poster frame и body в строгих layout `list`, `compact`, `recommendation`, `stats`; он не создаёт wrapping anchor. Контентные layouts не рисуют собственную рамку или тень, потому что строки группирует один родительский `divide-y` список; `stats` остаётся техническим исключением.
- `x-catalog.title-card` даёт `CatalogTitle` один основной tab-stop во всех списках, поиске и рекомендациях; неизвестный или устаревший layout нормализуется в `list`. Ссылки справочников остаются отдельными доступными ссылками поверх stretched-link. Компонент не вызывает lazy loading и читает только агрегатные атрибуты или уже загруженные relations.
- `CatalogTitlePageBuilder` передаёт Blade одну коллекцию `recommendationItems`: precomputed `v3` и объединённый genre/year fallback используют одинаковые строки, последовательные ranks и дедупликацию по ID. Blade не объединяет коллекции и не выполняет запросы.
- Специализированная строка новой серии готовит подписи в PHP/page builder и составляет shell через `x-ui.poster-card`; история просмотра и `/stats` также используют общий shell, не передавая туда авторизацию или проверку внешнего URL. Главная, `/titles`, directory hubs, рекомендации и личная библиотека группируют контент только вертикальными списками; структурные grid-раскладки форм, навигации, player/admin и статистики остаются отдельным layout-решением.
- Публичное имя тайтла берётся из `CatalogTitle::display_title`: совпадающий суффикс `/original_title` не повторяется в основном заголовке, а `display_original_title` выводится отдельной вторичной строкой. Исходные поля базы и поисковый индекс не изменяются.
- Стандартный вызов `$paginator->links()` использует русский светлый override `vendor.pagination.tailwind`; Livewire callers передают scoped `scrollTo` в отдельный override `vendor.livewire.tailwind`.
- Пустая выдача каталога показывает точный запрос; запрос только из стоп-слов получает отдельное сообщение «слишком общий». Пустое состояние не подменяется ближайшими карточками.

## Представление коллекций

`x-collections.collection-card` получает eager-loaded `CatalogCollection` summary и `CatalogCollectionCardViewModel`; Blade не считает visibility/count/URL и не запрашивает owner/cover/items. ViewModel превращает описание в escaped plain-text excerpt длиной не более 180 Unicode-символов, поэтому карточка не рендерит пользовательский HTML и не раздувается длинным текстом. Collection item rows переиспользуют `x-catalog.title-card`, prepared collection item attributes и stable `wire:key` по item ID. Public count и owner count подготовлены query-object раздельно.

Collection Livewire views содержат только passive loops/conditions над prepared values. Locked UUID/title IDs остаются в component, policy and criteria — в PHP. Нет `@php`, model/service/database calls, inline CSS или inline business JavaScript. Empty/search-empty/unavailable/moderation/status/loading/error/report/share/delete/restore states имеют реальные controls или safe text; absent likes/follows/collaboration не изображаются неработающими кнопками.

## Представление обсуждений

`CommentDiscussion` передаёт `x-comments.item` только immutable `CommentItemData`, scope DTO, paginator и form scalars. Blade не получает Eloquent graph и не вычисляет authorization, status visibility, reaction/reply totals, block/mute state, target URL или spoiler access. Stable `wire:key` использует comment ID; root/reply markup переиспользуется, recursive component tree отсутствует.

Unrevealed spoiler и collapsed long tail не находятся в скрытой DOM-разметке: presenter возвращает `body=null` или excerpt, а explicit Livewire action повторно готовит full body. Tombstone/hidden state не рендерит original author/body. All user text идёт через escaped interpolation с `whitespace-pre-line`/wrapping; automatic anchors/raw HTML отсутствуют.

Composer/reply/edit/report/moderation controls имеют реальные submit/cancel/loading/disabled/error states. `resources/js/comments.js` отвечает только за dialog lifecycle, focus/scroll, reduced motion, локальный Unicode character counter и allowlisted locale-state carry; business decisions и записи остаются PHP actions. Inline CSS, inline application JS, `@php`, Blade model/service/facade calls и Volt не используются.

## Представление отзывов

`CatalogTitleReviews` передаёт `x-reviews.review-card` immutable `ReviewItemData`, criteria/paginator and bounded form scalars; Eloquent model graphs не являются Livewire public state. Stable `wire:key`/anchor uses review ID. Presenter заранее вычисляет scope, title/body/excerpt, author/rating/verified/status/edited, vote totals/current vote, permissions and direct URL; Blade не вызывает models/services/database и не рассчитывает aggregate, verification, spoiler or authorization.

Unrevealed spoiler title/body равны `null` и отсутствуют в DOM/screen-reader tree, а translated warning/reveal server action загружает их заново. Hidden/deleted/blocked records never render body/private reason. User/provider text is escaped with preserved line breaks and wrapping; no `{!! !!}`, auto-link, Markdown or provider HTML. Rating uses normal labelled select fallback and textual value, not color-only stars.

Composer/edit/report/preferences/helpfulness/delete/restore/moderation controls map to real actions with error/success/loading/disabled/confirm states and retain drafts on recoverable failure. `resources/js/reviews.js` only stores account-, target- and edit-scoped 24-hour session drafts, restores focus/direct highlight and respects reduced motion; policy, validation and writes remain PHP. The outer title component captures a positive direct-link review ID in locked server state, passes it to the island and disables viewport deferral only for that request: the child hydration request does not inherit the original query string, while a hash target absent from the placeholder cannot trigger viewport loading. Ordinary title pages remain lazy. The opaque account scope prevents a draft from one signed-in account appearing to another account in the same browser session. No Volt, `@php`, inline CSS, inline business JavaScript or query from Blade is introduced.

## Представление тегов

Public tag page data готовят `TagPagePresenter`, `TagSeoPresenter` и existing `CatalogTitlesPageBuilder`; Blade получает `TagPageData`, paginator/card view data и prepared filter state. Description/aliases/related/count/canonical/JSON-LD не вычисляются в template. `x-ui.taxonomy-chip` получает eager-loaded tag and route helper URL, добавляет textual accessible public-tag label и не выполняет relation query.

`PersonalTagManager`/`PersonalTagSelector` держат public state только в bounded strings, booleans, locked title/UUID/version and draft UUID list. Owner tags/counts/title cards разрешает `PersonalTagLibraryQuery`, writes — `PersonalTagService`; templates loops only prepared rows. Private badge не имеет public link, personal text escaped, stable `wire:key` uses opaque UUID.

Admin view receives bounded query results, enum options and merge impact from `TagAdministrationQuery`; it does not resolve aliases, normalize names, authorize, count usage or query provider mappings in Blade. Loading/empty/error/confirmation state maps to real Livewire action. No tag template introduces Volt, `@php`, raw HTML, inline CSS, inline business JavaScript, DB/facade/service call or complete Eloquent graph public serialization.
