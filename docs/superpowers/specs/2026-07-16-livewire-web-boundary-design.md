# Полная Livewire-граница web-интерфейса

**Дата:** 16.07.2026

## Цель

Перевести все HTML-страницы Seasonvar на full-page Livewire 4.3, удалить все прикладные контроллеры вне `app/Http/Controllers/Api`, сохранить публичный JSON API и прямые transport-маршруты без изменения их внешних контрактов и закрепить Livewire как единственную архитектуру новых HTML-страниц.

## Исходное состояние

В приложении уже 68 маршрутов ведут непосредственно на Livewire-компоненты. Оставшаяся web-граница содержит 17 non-API контроллеров и 46 controller-backed маршрутов. Эти маршруты делятся на две разные категории:

- HTML-страницы, которые рендерят Blade-оболочку и иногда вкладывают существующий Livewire-компонент;
- transport endpoints, которые возвращают redirect, JSON, XML, текст, изображение, вложение, подписанный playback-ответ или потоковый download.

Livewire подходит для первой категории. Для второй категории он не используется: Livewire-download сначала собирает содержимое ответа целиком, поэтому не сохраняет требуемую ограниченную потоковую передачу; full-page компонент также не является стабильным JSON/XML/file API для браузеров, роботов, мобильных приложений и внешних клиентов.

## Рассмотренные подходы

### 1. Перевести только HTML-контроллеры

HTML-страницы становятся full-page Livewire, а технические контроллеры остаются. Это минимальный и безопасный объём, но он не выполняет требование удалить non-API контроллеры и не создаёт проверяемую единую границу.

### 2. Livewire для HTML, responders для transport endpoints

HTML-маршруты ведут напрямую на Livewire-компоненты. Non-HTML маршруты остаются обычными Laravel-маршрутами и делегируют работу доменным сервисам, resolvers и responders, которые не рендерят пользовательский интерфейс. Все 17 non-API контроллеров удаляются. Этот подход выбран.

### 3. Пропустить все web-ответы через Livewire

Даже sitemap, health-check, media, вложения, redirects и downloads обслуживаются компонентами. Подход отклонён, потому что меняет content types и request lifecycle, ломает прямые URL и машинных клиентов и нарушает bounded-buffer контракт скачивания.

## Архитектурные правила

1. Любая новая HTML-страница в `routes/web.php` создаётся как full-page Livewire-компонент.
2. API остаётся stateless JSON-границей в `routes/api.php`, использует контроллеры и API Resources и не зависит от snapshot-протокола Livewire.
3. XML, plain text, JSON health-check, изображения, вложения, redirects, подписанное воспроизведение и потоковые downloads остаются обычными Laravel responses.
4. Transport responder не рендерит Blade и не содержит интерактивное состояние. Он проверяет transport-specific предусловия, вызывает существующие доменные сервисы и возвращает типизированный Symfony/Laravel response.
5. Livewire и API повторно используют текущие services, actions, policies, queries, page builders и DTO; бизнес-логика не дублируется в компонентах.
6. `app/Http/Controllers/Controller.php` сохраняется как базовый класс API-контроллеров. Вне `app/Http/Controllers/Api/**` других контроллеров быть не должно.
7. URL, имена маршрутов, HTTP-методы, middleware, route constraints, scoped model binding, статусы, заголовки, content types, SEO и cache policy сохраняются, если ниже явно не указано обратное. В этой миграции таких исключений нет.

## Миграция HTML-страниц

| Текущий владелец | Маршруты | Новый владелец |
| --- | --- | --- |
| `CatalogController::index()` | `home`, `localized.home` | новый `App\Livewire\CatalogHomePage` |
| `GlobalSearchController` | `search.index`, `localized.search.index` | новый `App\Livewire\GlobalSearchPage` |
| `CatalogController::show()` | `titles.show` | существующий `App\Livewire\CatalogTitleDetail`, расширенный до full-page |
| `CatalogController::stats()` | `stats` | существующий `App\Livewire\StatsDashboard`, расширенный до full-page |
| `CatalogTopListController` | `top.show`, `localized.top.show` | новый `App\Livewire\CatalogTopListPage` |
| `CatalogCollectionController::show()` и `localizedShow()` | `collections.show`, `localized.collections.show` | существующий `App\Livewire\Collections\CatalogCollectionPage`, расширенный до full-page |

### `CatalogHomePage`

Компонент получает данные через существующий `CatalogHomePageBuilder`, передаёт текущего пользователя как nullable viewer и владеет SEO/layout. Разметка `resources/views/catalog/index.blade.php` переносится в `resources/views/livewire/catalog-home-page.blade.php` без изменения видимого результата, запросов из Blade или структуры публичного кеша.

### `GlobalSearchPage`

Компонент нормализует `q` тем же контрактом, который сейчас задаёт `GlobalSearchRequest`, и вызывает `GlobalSearchPageQuery`. Default и localized routes используют один компонент; route locale определяет имя формы, canonical URL и breadcrumbs. Поиск остаётся `noindex,follow`, текущие безопасные empty/error/correction states и URL `GET ?q=` сохраняются. Стандартная GET-форма допустима внутри Livewire-страницы, потому что её серверным владельцем становится компонент, а внешний URL остаётся пригодным для передачи.

### `CatalogTitleDetail`

Full-page route передаёт компоненту связанный `CatalogTitle` по `slug`. Компонент сохраняет канонический `301` для исторического slug, получает SEO через `CatalogTitlePageBuilder`, применяет текущий `noindex` к review query parameters и расширяет собственный view через `layouts.app`. Вложенная оболочка `resources/views/catalog/show.blade.php` удаляется. Интерактивный refresh, отзывы, рекомендации, player и пользовательское состояние не меняются.

### `StatsDashboard`

Компонент продолжает читать snapshot через `CatalogStatsSnapshotCache`, дополнительно получает SEO через `CatalogStatsPageBuilder` и становится владельцем layout. Вложенная оболочка `resources/views/catalog/stats.blade.php` удаляется. Маршрут постера остаётся отдельным file/image transport endpoint.

### `CatalogTopListPage`

Компонент принимает `CatalogTopListCategory` через route enum binding, валидирует `year_from`, `year_to` и `country` по текущему контракту `CatalogTopListRequest` и передаёт неизменяемый набор фильтров в `CatalogTopListPageBuilder`. Default и localized routes используют один компонент. Публичное ранжирование, предел 100, canonical/noindex, пустые состояния и кеш-ключи не меняются. Текущая GET-форма сохраняет shareable URL; разметка переносится в `resources/views/livewire/catalog-top-list-page.blade.php`.

### `CatalogCollectionPage`

Route parameter остаётся `collectionSlug`. В `mount()` компонент использует `CatalogCollectionResolver`, выполняет policy authorization, перенаправляет исторический slug с `301`, фиксирует `collectionPublicId` как locked state и сохраняет выбранную route locale. SEO продолжает собирать `CatalogCollectionSeoPresenter`. Private/no-store и динамический `X-Robots-Tag` переносятся в route middleware/response hook без ослабления приватности. Оболочка `resources/views/collections/show.blade.php` удаляется; фильтры, управление подборкой, жалобы, обсуждение и пагинация остаются в текущем Livewire-компоненте.

## Миграция transport endpoints

| Удаляемый контроллер | Сохраняемый контракт | Новая граница |
| --- | --- | --- |
| `AccountDataExportController` | авторизованный потоковый JSON download | responder поверх `AccountDataExportService` и `UserProfileService` |
| `Auth\VerifyEmailController` | signed verification и redirect со status | responder поверх `AccountEmailVerificationService` и `AuthenticationRedirectService` |
| `CatalogCollectionController::legacyShow()` | постоянные redirects `/lists/*` и `/selections/*` | route handler поверх `CatalogCollectionResolver` и policy |
| `CatalogCollectionCoverController` | приватный inline image с проверкой пути/MIME/version | collection cover responder |
| `CatalogController::statsPoster()` | image response статистики | существующий `CatalogStatsPosterResponder` |
| `CatalogDirectoryRedirectController` | канонические `301` справочников с безопасным query string | directory redirect resolver/responder |
| `CatalogSitemapController` | streamed sitemap/feed/OpenSearch/`llms.txt` | существующий `CatalogSitemapResponder` |
| `CommentRedirectController` | private/no-store direct-link redirect | существующий `CommentDirectLinkResolver` с тонким responder |
| `DownloadLicensedMediaController` | авторизованный bounded-buffer direct-media download | responder поверх `StreamLicensedMediaDownload` и policy |
| `InfrastructureHealthController` | JSON `200/503`, `no-store` | responder поверх `InfrastructureHealthCheck` |
| `MigrateAnonymousPreferencesController` | валидированный `POST`, `204`, throttle | responder/action поверх `AccountSettingsService` |
| `PlaybackSourceController` | signed playback response с viewer binding | responder поверх `CatalogPlaybackSourceResolver` |
| `ReviewDirectLinkController` | canonical public review redirect с merge/alias checks | review direct-link resolver/responder |
| `TechnicalIssueAttachmentController` | авторизованное inline/attachment file response | technical issue attachment responder |
| `UserProfileMediaController` | приватный inline avatar/cover response | profile media responder |

Route closures остаются тонкими: dependency injection, получение route-bound аргументов и один вызов responder/service. Сложная проверка, выбор модели, построение URL и сборка headers не переносятся в `routes/web.php`. Responder-классы располагаются внутри соответствующих доменных `app/Services/*`, следуя уже используемому проектом паттерну.

## Поток запроса

### HTML

`GET web route → middleware/model binding → full-page Livewire mount → page builder/query/policy → Livewire Blade view → layouts.app → HTML response`

Последующие пользовательские действия идут через внутренний update endpoint Livewire и вызывают те же services/actions. Этот endpoint не документируется и не используется как API.

### API

`/api/* → api middleware/Sanctum → API controller → service/query/action → API Resource → JSON response`

Этот поток не меняется.

### Transport endpoint

`direct web URL → route middleware/signature/binding → thin route handler → domain responder/service/policy → typed redirect/file/stream/JSON/XML response`

Transport endpoint никогда не создаёт Livewire snapshot и не собирает bounded stream в память.

## Валидация, авторизация и ошибки

- Route constraints и implicit/scoped bindings сохраняются.
- Вход Livewire нормализуется до вызова queries; недопустимые значения получают безопасное значение по умолчанию либо русскую validation error в соответствии с текущим контрактом страницы.
- Policy/Gate checks сохраняются в компонентах и responders; скрытая кнопка не считается авторизацией.
- Существующие `401`, `403`, `404`, `422`, `503`, `301` и `302` сохраняются на соответствующих маршрутах.
- Ошибки поиска продолжают репортиться и показывают безопасное русское состояние без stack trace.
- Signed routes остаются signed routes; перенос владельца response не меняет подпись URL.
- Все file endpoints продолжают проверять диск, нормализованный path prefix, MIME, version и существование файла.

## Кеширование, SEO и производительность

- Middleware `public.page:*`, `public.cache:*`, локализация и private response headers остаются на тех же named routes.
- Компоненты используют существующие page builders, snapshots, cache generations, eager loading и проекции; запросы не добавляются в Blade.
- Canonical URL, `hreflang`, breadcrumbs, JSON-LD, `robots` и `X-Robots-Tag` сохраняются на initial full-page response.
- URL-bound состояние Livewire не включает секреты или персональные данные в общие cache keys.
- Sitemap/feed остаются streamed; licensed media остаётся bounded-buffer streamed и не сохраняется в storage/cache/database.

## Тестирование

Миграция выполняется TDD по отдельным границам.

1. Структурный тест сначала фиксирует требование: после миграции в `app/Http/Controllers` разрешены только `Controller.php` и `Api/**`; ни один web route action не ссылается на non-API controller.
2. Для каждой HTML-страницы route test проверяет прежний URL, имя, middleware, status, SEO и наличие корневого Livewire-компонента; component test проверяет mount, route parameters, validation, authorization и data builder.
3. Существующие feature tests для home, title, stats, top list, search и collections переводятся с controller assertions на Livewire assertions без ослабления поведения.
4. Transport regression tests проверяют status, content type, headers, redirect target, signatures, authorization, range/stream semantics и отсутствие HTML/Livewire payload.
5. API tests запускаются без изменения ожидаемой JSON-формы.
6. После focused tests запускаются Pint, полный `php artisan test`, `./vendor/bin/phpunit` при необходимости и `npm run build`. Существенные изменения маршрутизации дополнительно проверяются через `php artisan route:list` и существующие browser tests.

## Документация и постоянное правило

- В `AGENTS.md` добавляется обязательное правило: новые HTML web routes реализуются только full-page Livewire; API и non-HTML transport endpoints не маскируются под Livewire.
- `docs/architecture.md`, `docs/views.md`, `docs/frontend.md`, `docs/CODE_STANDARDS.md` и `docs/testing.md` обновляют владельцев и проверки границ.
- `README.md` получает актуальное описание доступной посетителю Livewire-навигации и датированную запись истории без внутреннего жаргона.
- `CHANGELOG.md` фиксирует полную техническую миграцию на русском языке.
- Управляемые блоки `project-docs` не редактируются вручную; если затронут их источник, запускается `php artisan project:docs-refresh`.

## Критерии готовности

- Все шесть оставшихся HTML-поверхностей обслуживаются full-page Livewire.
- Все 17 non-API контроллеров удалены; сохранён только общий `Controller.php` и дерево `Api/**`.
- Все прежние named web routes разрешаются с прежними методами, constraints и middleware.
- API JSON contracts не изменены.
- Sitemap, feed, OpenSearch, `llms.txt`, health, images, attachments, playback, redirects и downloads возвращают прежние типы, статусы и headers без Livewire payload.
- Потоковое скачивание прямого видео остаётся bounded-buffer и не сохраняет файл.
- Структурные, focused и полные проверки проходят.
- README и тематическая документация актуальны.
- Работа завершена в `main`, закоммичена, а рабочее дерево не содержит изменений этой миграции.
