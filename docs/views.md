# Blade-шаблоны

Обновлено: 20.07.2026

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
- `App\Livewire\CatalogSeries` разделяет render-local данные на computed `catalogPage` и `catalogFacets`; Eloquent-коллекции не хранятся в публичных properties. Связанные Livewire islands `catalog-live` атомарно обновляют фильтры и карточки, а первый SSR не вычисляет facets. Lazy-island placeholder получает от Livewire один `wire:intersect.once`, сохраняет `aria-busy` и объявляет локализованную загрузку через `role="status"`; application Blade не вызывает internal action напрямую. Карточки и строки используют `catalog-title-{id}` как стабильный `wire:key`.
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
- `x-layout.site-header` выводит две независимые адаптивные полосы в одном семантическом header: бренд, глобальный поиск и auth-actions сверху, вся навигация снизу. Нижняя полоса использует перенос пунктов без внутренней прокрутки; подписи видимы с `sm`, а на телефоне доступные имена сохраняются рядом с иконками для экранных дикторов. Переключателя языка в header нет.
- `x-layout.header-search` рендерит query-free GET fallback, neutral input frame и пустые accessible result regions. Постеры, год и агрегаты тайтлов, группы портала и responsive limits добавляет `resources/js/header-search.js`; Blade не выполняет поиск и не сериализует Eloquent graph. Dropdown не имеет внутренней прокрутки и не выходит за viewport на mobile/tablet/desktop/TV.
- `/search` получает query result DTO-like arrays/models только из `GlobalSearchPageQuery`; template не содержит `@php`, model/service/facade calls или SQL. Он выводит локализованный exact title count, server-prepared portal groups, safe error/empty/typo states и ссылки на canonical full catalogue/actor/tag pages. `CatalogTitlesViewModel` и `CatalogDirectoryPageBuilder` заранее готовят translated labels и locale-formatted counts для `/titles`, actor/tag directories и их Livewire updates.
- Компоненты получают готовые модели, коллекции или ViewModel-объекты и не выполняют запросы к базе.
- В компонентных шаблонах используйте `$attributes->merge()` или `$attributes->class()` для расширяемых классов и атрибутов.
- `x-ui.poster-frame` — единственный Blade boundary для `<img>` каталожного постера: готовые URL, alt и `fit` передаются вызывающим слоем. Контентная строка использует `contain`, `2:3` и `overscan=false`; явно выбранный `cover` с 2% overscan остаётся для главного постера и технических миниатюр. Background появляется только у заглушки.
- `x-ui.poster-card` собирает poster frame и body в строгих layout `list`, `compact`, `recommendation`, `stats`; он не создаёт wrapping anchor. Контентные layouts не рисуют собственную рамку или тень, потому что строки группирует один родительский `divide-y` список; `stats` остаётся техническим исключением.
- `x-catalog.title-card` даёт `CatalogTitle` один основной tab-stop во всех списках, поиске и рекомендациях; неизвестный или устаревший layout нормализуется в `list`. Ссылки справочников остаются отдельными доступными ссылками поверх stretched-link. Компонент не вызывает lazy loading и читает только агрегатные атрибуты или уже загруженные relations.
- `CatalogTitlePageBuilder` передаёт Blade одну коллекцию `recommendationItems`: precomputed `v3` и объединённый genre/year fallback используют одинаковые строки, последовательные ranks и дедупликацию по ID. Blade не объединяет коллекции и не выполняет запросы.
- Специализированная строка новой серии готовит подписи в PHP/page builder и составляет shell через `x-ui.poster-card`; история просмотра и `/stats` также используют общий shell, не передавая туда авторизацию или проверку внешнего URL. Главная, `/titles`, directory hubs, рекомендации и личная библиотека группируют контент только вертикальными списками; структурные grid-раскладки форм, навигации, player/admin и статистики остаются отдельным layout-решением.
- Публичное имя тайтла берётся из `CatalogTitle::display_title`: совпадающий суффикс `/original_title` не повторяется в основном заголовке, а `display_original_title` выводится отдельной вторичной строкой. Исходные поля базы и поисковый индекс не изменяются.
- Обычный `$paginator->links()` использует русский светлый override `vendor.pagination.tailwind`. Каждый Livewire caller помещает выдачу и controls в уникальные `@island(... with: $this->...Page)` и `x-ui.pagination-region`, а в `vendor.livewire.tailwind` передаёт `links(data: ['region' => '...'])`; произвольные `scrollTo`, inline JavaScript и duplicate spinner markup не используются.
- Пустая выдача каталога показывает точный запрос; запрос только из стоп-слов получает отдельное сообщение «слишком общий». Пустое состояние не подменяется ближайшими карточками.

## Представление коллекций

`x-collections.collection-card` получает eager-loaded `CatalogCollection` summary и `CatalogCollectionCardViewModel`; Blade не считает visibility/count/URL и не запрашивает owner/cover/items. ViewModel превращает описание в escaped plain-text excerpt длиной не более 180 Unicode-символов, поэтому карточка не рендерит пользовательский HTML и не раздувается длинным текстом. Collection item rows переиспользуют `x-catalog.title-card`, prepared collection item attributes и stable `wire:key` по item ID. Public count и owner count подготовлены query-object раздельно.

`CatalogDiscoveryPage` является единственным full-page owner публичного discovery. Только `popular` монтирует `CatalogCollectionExplorer`, который хранит отдельные URL-backed search/sort/page поля и рендерит компактные `x-collections.collection-card` в responsive 1/2/3/4-column grid. `CatalogAdministrationPage` аналогично владеет `/admin/catalog` и условно монтирует один из двух manager fragments; вложенные компоненты не расширяют layout и не создают второй `<h1>`.

Collection Livewire views содержат только passive loops/conditions над prepared values. Locked UUID/title IDs остаются в component, policy and criteria — в PHP. Нет `@php`, model/service/database calls, inline CSS или inline business JavaScript. Empty/search-empty/unavailable/moderation/status/loading/error/report/share/delete/restore states имеют реальные controls или safe text; absent likes/follows/collaboration не изображаются неработающими кнопками.

Title membership selector оставляет checkbox на deferred `wire:model="selectedCollectionPublicIds"`, а локализованный счётчик использует `wire:text` от `selectedCollectionPublicIds.length`. Точно нацеленный на этот массив `wire:dirty` показывает локализованный текстовый status до Apply и исчезает при совпадении draft с server snapshot. Серверный `selectedCountLabel` остаётся SSR/no-JavaScript fallback; оптимистическая presentation не сохраняет membership и не заменяет `apply()`, policy или validation.

Панель формы создания подборки существует только при `CatalogCollectionDashboard::$showCreate` и получает безымянный `wire:transition` через attribute bag `x-ui.panel`. Переход ограничен этой add/remove boundary, не управляет authorization, validation или persistence и не сопровождается custom CSS/JavaScript, способным обойти reduced-motion либо unsupported-browser fallback Livewire.

Manual item list `CatalogCollectionEditor` имеет единственный `wire:sort="sortItem"`; каждый `<li>` совмещает stable `wire:key` и canonical `wire:sort:item` collection-item ID. Отдельная `wire:sort:handle` ручка является pointer/touch enhancement, а wrapper кнопок имеет `wire:sort:ignore`; up/down/remove остаются обычными focusable buttons. Handler принимает только ID и page-local position, все membership/window/persistence decisions принадлежат PHP service.

Editorial editor выводит одну форму без locale fieldset, RU/EN buttons и подписи «Русский»: locked PHP boundary загружает и сохраняет `ru`. Existing English translation rows остаются в базе, но не являются переключаемым authoring UI.

## Представление обсуждений

`CommentDiscussion` передаёт `x-comments.item` только immutable `CommentItemData`, scope DTO, paginator и form scalars. Blade не получает Eloquent graph и не вычисляет authorization, status visibility, reaction/reply totals, block/mute state, target URL или spoiler access. Stable `wire:key` использует comment ID; root/reply markup переиспользуется, recursive component tree отсутствует.

Unrevealed spoiler и collapsed long tail не находятся в скрытой DOM-разметке: presenter возвращает `body=null` или excerpt, а explicit Livewire action повторно готовит full body. Tombstone/hidden state не рендерит original author/body. All user text идёт через escaped interpolation с `whitespace-pre-line`/wrapping; automatic anchors/raw HTML отсутствуют.

Composer/reply/edit/report/moderation controls имеют реальные submit/cancel/loading/disabled/error states. `resources/js/comments.js` отвечает только за dialog lifecycle, focus/scroll, reduced motion, локальный Unicode character counter и allowlisted locale-state carry; business decisions и записи остаются PHP actions. Inline CSS, inline application JS, `@php`, Blade model/service/facade calls и Volt не используются.

Progressive reply loading начинает с 20 строк и bounded server-state увеличивает окно не более чем до 200. При достижении ceiling компонент оставляет уже показанную ветку, отключает дальнейшую загрузку и выводит локализованное status-сообщение; direct focused reply сохраняется как отдельный доступный context без recursive tree или unbounded Livewire payload.

Обычная title page оставляет discussion island lazy. Положительный `comment` из прямого канонического redirect сначала нормализуется в locked parent state; только такой запрос рендерит discussion сразу, чтобы `#comment-{id}` существовал до browser scroll/focus. Blade не читает request и не определяет lazy policy самостоятельно.

## Представление отзывов

Direct links are generated by `ReviewPresenter`: active `ru|en` интерфейс использует `localized.reviews.show`, а прежний `reviews.show` остаётся fallback/compatibility. Blade не конкатенирует locale, review ID, title slug, page или anchor. Оба маршрута делегируют одному responder, поэтому localized link сохраняет stable identity, alias resolution, safe 404, current page и focus без отдельной presentation path.

`CatalogTitleReviews` передаёт `x-reviews.review-card` immutable `ReviewItemData`, criteria/paginator and bounded form scalars; Eloquent model graphs не являются Livewire public state. Stable `wire:key`/anchor uses review ID. Presenter заранее вычисляет scope, title/body/excerpt, author/rating/verified/status/edited, vote totals/current vote, permissions and direct URL; Blade не вызывает models/services/database и не рассчитывает aggregate, verification, spoiler or authorization.

Unrevealed spoiler title/body равны `null` и отсутствуют в DOM/screen-reader tree, а translated warning/reveal server action загружает их заново. Hidden/deleted/blocked records never render body/private reason. User/provider text is escaped with preserved line breaks and wrapping; no `{!! !!}`, auto-link, Markdown or provider HTML. Rating uses normal labelled select fallback and textual value, not color-only stars.

Composer/edit/report/preferences/helpfulness/delete/restore/moderation controls map to real actions with error/success/loading/disabled/confirm states and retain drafts on recoverable failure. `resources/js/reviews.js` only stores account-, target- and edit-scoped 24-hour session drafts, restores focus/direct highlight and respects reduced motion; policy, validation and writes remain PHP. The outer title component captures a positive direct-link review ID in locked server state, passes it to the island and disables viewport deferral only for that request: the child hydration request does not inherit the original query string, while a hash target absent from the placeholder cannot trigger viewport loading. Ordinary title pages remain lazy. The opaque account scope prevents a draft from one signed-in account appearing to another account in the same browser session. No Volt, `@php`, inline CSS, inline business JavaScript or query from Blade is introduced.

## Представление тегов

Public tag page data готовят `TagPagePresenter`, `TagSeoPresenter` и existing `CatalogTitlesPageBuilder`; Blade получает `TagPageData`, paginator/card view data и prepared filter state. Description/aliases/related/count/canonical/JSON-LD не вычисляются в template. `x-ui.taxonomy-chip` получает eager-loaded tag and route helper URL, добавляет textual accessible public-tag label и не выполняет relation query.

`PersonalTagManager`/`PersonalTagSelector` держат public state только в bounded strings, booleans, locked title/UUID/version and draft UUID list. Owner tags/counts/title cards разрешает `PersonalTagLibraryQuery`, writes — `PersonalTagService`; templates loops only prepared rows. Private badge не имеет public link, personal text escaped, stable `wire:key` uses opaque UUID.

Personal tag manager не показывает content-language control: новый UGC получает `null`, а edit сохраняет ранее explicit content locale. Tag administration выводит одну translation и alias form за раз с allowlisted `ru|en` selector и понятной подписью языка; public state содержит только bounded scalar forms configured locales, не Eloquent translations graph, а существующие переводы не удаляются при переключении.

Admin view receives bounded query results, enum options and merge impact from `TagAdministrationQuery`; it does not resolve aliases, normalize names, authorize, count usage or query provider mappings in Blade. Loading/empty/error/confirmation state maps to real Livewire action. No tag template introduces Volt, `@php`, raw HTML, inline CSS, inline business JavaScript, DB/facade/service call or complete Eloquent graph public serialization.

## Представление профилей пользователей

`resources/views/livewire/profile/public-profile-page.blade.php` renders only prepared `PublicUserProfileData` and typed selected-paginator DTOs. Privacy/authorization/SEO are computed in profile PHP; review rows/count delegate to `CatalogTitleReviewQuery`/`ReviewPresenter`, while comment rows/count delegate to `CommentProfileQuery`/`CommentPresenter`. Поэтому unrevealed review title/body и comment spoiler excerpt отсутствуют в public cards, а Blade не повторяет visibility, excerpt, target-title или direct-link rules. Watch cards contain only safe title-level data, and no paginator array shape is interpreted as a domain rule in Blade. Длинная biography получает подготовленный preview/flag и native `<details>` с локализованным accessible summary; текст остаётся escaped. `profile-page.blade.php` remains owner-only, announces prepared success/failure and receives prepared options/URLs/limits; it does not resolve models/services/config or arbitrary privacy fields. Author links in comment/review/collection components are optional prepared URLs and never cause Blade queries.

## Представление настроек аккаунта

`resources/views/livewire/settings/account-settings-page.blade.php` отображает один active section из подготовленных DTO/options. Blade не читает модели/services/config/database, не вычисляет precedence/privacy/entitlement и не сериализует User, session payload или provider secret. Профиль/security/export/delete остаются canonical отдельными Livewire/routes и открываются из единой navigation.

Section navigation, fieldsets, native checkbox/select/datalist/range, status/error live regions, confirmations и loading locks переиспользуют проектные components/classes. На phone navigation становится горизонтальным overflow-safe списком, forms — одной колонкой; long locale/timezone/notification/provider-unavailable text переносится. Settings/profile/security metadata имеют noindex и не содержат private values/social/JSON-LD.

## Представления заявок на материалы

`ContentRequestPresenter` — единственная card/detail preparation boundary. Public card не содержит requester identity/email/private evidence/note/import state; detail добавляет только public-safe timeline/results/references, а clarification появляется исключительно в owner/moderator context. Viewer vote/follow/permission flags вычисляются после guest-cache boundary. `x-content-requests.card` переиспользуется directory/My/admin и не выполняет query.

Create view показывает search-before-submit, type descriptions, stable target selection, canonical season/episode sequence, language/translation/subtitle/quality/correction fields, bounded source/external-ID rows и probable-difference explanation. Не существует file/video upload, fake queue position/progress/ETA или public discussion. Admin presenter state выводит только допустимые текущим status transition controls: clarification, rejection, completion, merge и importer handoff не показываются в несовместимом состоянии, но каждая видимая mutation всё равно повторно authorizes server-side. Empty/error/unavailable/merged/completed/rejected/partial states ведут к реальному действию или безопасной информации; visible copy/ARIA/confirmations имеют exact `ru`/`en` key parity и user prose остаётся untranslated escaped text.

## Представления рекомендаций

`CatalogRecommendationPresenter` — единственная boundary для type metadata, relation labels, stored similarity badges и broad explanation templates. `CatalogRecommendationListItem` содержит уже загруженный card title, rank/reason codes и permission to dismiss; Blade не видит score breakdown, user history или query services.

Homepage показывает не более одной recommendation section; title detail — explicit related перед computed similar; discovery — filtered paginated list с working not-interested/blacklist/undo; library — link на personal discovery и owner-only hidden restore; search no-result — labelled popular link. Все состояния используют `lang/{ru,en}/recommendations.php`, existing cards/buttons/panels and escaped output. No fake percentage/AI label/dead feedback/carousel/hover-only reason exists.

## Представление рейтингов Top 100

`CatalogTopListPageBuilder` передаёт `resources/views/livewire/catalog-top-list-page.blade.php` готовые category links, podium, основной список, count и SEO contract. Каждая строка — `CatalogTopListItem` с уже определёнными местом, источником рейтинга, форматированными оценкой/голосами и объясняющими признаками; Blade не вычисляет score, eligibility, category boundaries, URL или permissions и не обращается к базе.

Тот же builder готовит `filterForm` и `emptyState`: action/reset URLs, scalar values, максимальный год и ограниченные списки стран и жанров формирует PHP-сервис. Blade только выводит native GET controls и ошибки Form Request; database query, query-string parsing, route building и решение об индексировании в шаблоне отсутствуют.

Шаблон переиспользует `x-catalog.title-card`, существующие panels/icons/focus styles и только безопасный escaped output. Отдельной Eloquent-сериализации, inline PHP/CSS/JS, client ranking и скрытого full list нет. `CollectionPage`/`ItemList`, canonical и hreflang строятся PHP SEO builder из того же упорядоченного набора, поэтому видимый порядок и structured data не расходятся; при активном фильтре builder оставляет clean canonical и переключает страницу в `noindex,follow` без alternate/structured-list разметки.

## Представления технических обращений

`livewire/technical-issues/*` и `components/technical-issues/*` получают prepared DTO/options из Livewire/query/presenter. Create, My Tickets, detail, notifications и staff queue используют existing light Tailwind panels/forms/badges, stable `wire:key`, responsive wrapping, visible focus, minimum touch targets, labelled upload/status/timeline controls и ARIA live regions. Follower view не получает requester evidence; internal notes/raw diagnostics никогда не передаются в normal-user view.

Create-form не дублирует общий layout offline alert. Только её final submit получает component-scoped `wire:offline.attr="disabled"` рядом с существующим targeted loading attribute; остальные поля остаются редактируемыми, а серверный submit после восстановления использует прежний action/validation/upload pipeline.

`resources/js/issues.js` — единственный Vite boundary для optional client summary, attachment preview cleanup, player position и focus/live announcements. Blade не содержит model/service/database calls, `@php`, inline CSS или ticket business JavaScript. Полный responsive/accessibility contract: [`technical-issues.md`](technical-issues.md).

## Responsive shell и mobile presentation Task 23

`layouts/app.blade.php`, `components/layout/site-header.blade.php` и `site-footer.blade.php` образуют единственную responsive shell. `AppLayoutData` готовит общие navigation items/active state и private-page marker; Blade не определяет устройство, entitlement, player capability, PWA eligibility или cache policy. Mobile native `<details>` и desktop row выводят те же DTO items, а header search остаётся одной progressive GET form.

Главный контент, route announcer и online/offline status server-rendered. Safe-area/dynamic-viewport styling находится в `resources/css/app.css`; interaction — в Vite modules. Изменённые templates не содержат `@php`, inline style, inline business script, model/service/facade/database calls или raw user output. Password visibility, public share, player bridge, filter draft и keyboard viewport не дублируются в Blade.

Catalog filter presentation остаётся одним component tree: `<details>` даёт compact disclosure, wide viewport использует тот же inline form, Apply/Cancel/Clear имеют отдельную семантику. Player template передаёт только prepared localized copy, public Media Session metadata, authorized navigation URLs и opaque progress endpoint state; protected source decision остаётся PHP boundary.

## Представления Premium

`livewire/premium/*` и settings Premium section получают `PremiumPlanData`, `PremiumAccessSummary` либо подготовленные safe arrays. Pricing, return, owner history, coupon, notifications и admin grant/promotion screens не вычисляют access, amount, currency, expiry, refund или permission в Blade и не обращаются к моделям/сервисам. Provider objects/secrets не входят в Livewire state.

Шаблоны используют существующие light Tailwind panels/buttons/forms/tables, responsive wrapping/overflow, visible focus, minimum touch targets, `wire:loading`, disabled duplicate actions и translated ARIA status/error labels. Fake checkout, invoice, discount, recurring disclosure или feature card не выводятся. Полный presentation contract — [`premium.md`](premium.md).

## Представления центра помощи

`livewire/help-center/*` и `components/help/*` получают только prepared DTO, enum options и paginator. Home/category/article/search/preview/admin не вызывают model/service/facade/database из Blade, не содержат `@php`, inline CSS или business JavaScript. Visibility, locale fallback, relation/ranking/escalation, canonical и sanitizer принадлежат PHP boundary.

Server-rendered article/TOC/FAQ, search form/results, category/article cards, feedback/report и escalation повторно используют existing light UI, focus/touch/loading/live-region/empty/error patterns. `help-center.js` отвечает только за autocomplete interaction и editor unsaved guard. Полный responsive/accessibility contract: [`help-center.md`](help-center.md).

Outdated-report form имеет stable `id="help-report-form"`, modifier-free `wire:show="showReportForm"` и `wire:cloak`: скрытие меняет только `display`, не удаляя draft controls из DOM. Toggle сохраняет server `$toggle` и связывается с form через `aria-controls`/`aria-expanded`; submit/cancel и errors остаются Livewire actions. Другие условные sections не получают `wire:show` без требования сохранять DOM identity.

## Представление канонического плеера Task 07

`livewire/catalog-title-player.blade.php` получает prepared DTO/view-model data: stable IDs, localized labels, canonical navigation, one selected signed grant и grouped authorized options выбранной серии. Шаблон не разрешает source URL, не запрашивает БД/service, не содержит `@php`, inline CSS или business JavaScript. Empty menus для отсутствующих audio/subtitle/quality capability не выводятся.

Parent `catalog-title-detail.blade.php` назначает единственному child player статический `wire:ref="player"` рядом с независимым stable `wire:key`. Ref служит только component-scoped адресом refresh event; он не становится DOM ID, selector API, authorization boundary или заменой keyed identity.

Native buttons/links/dialog имеют семантику, visible focus, минимум 44 px, translated ARIA/live regions, mobile wrapping и working href fallback. Plyr остаётся владельцем core controls; portal controls не имитируют unsupported browser features. Полный presentation checklist: [`audits/video-playback-report.md`](audits/video-playback-report.md).

Только keyed `data-player-shell` имеет полный `wire:ignore`, потому что Plyr/HLS изменяют всё его поддерево. `wire:ignore.self` недостаточен, а расширение boundary на loading overlay, media options, ошибки или portal controls запрещено: эти sibling-элементы обязаны получать server-owned Livewire morphs.

`wire:replace.self` используется ровно в четырёх template pattern `title-filters.blade.php` и только на leaf-checkbox с `wire:model.live`: заменяется сам input после grouped island response, окружающие label/counter/group остаются morph-owned. Bare subtree replacement, replacement на player, forms/dialogs/editors и text/search inputs запрещены без воспроизводящего дефект теста и доказательства, что узкий key/component/lifecycle boundary недостаточен.

## Представление личной библиотеки Task 09

`resources/views/livewire/library/user-library-page.blade.php` отображает только prepared tabs, paginator items, grouped counts, filter/sort options, update indicators и safe action flags. Вычисление status, update predicate, progress percentage, marker time и URL принадлежит typed PHP/query/service boundary. Шаблон не содержит `@php`, inline CSS, direct model/service query или application JavaScript.

Табы, фильтры, cards, marker rows, empty/loading/error/live-region состояния адаптивны, keyboard-operable и используют `library.*` переводы для ru/en. Existing collection pages остаются единственным CRUD/ordering/visibility UI; library не создаёт competing modal или fake bulk actions.
