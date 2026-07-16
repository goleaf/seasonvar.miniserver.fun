# Стандарты кода

Обновлено: 16.07.2026

## Обязательный процесс

Каждое будущее изменение кода должно обновлять этот набор документации, если меняет архитектуру, правила импортера, компоненты интерфейса, связи, команды или запросы.

Единственная карта владельцев документации находится в [`docs/README.md`](README.md). Обновляйте основной документ изменённой темы и добавляйте ссылки вместо копирования длинного контракта. Git workflow, единственная рабочая ветка `main` и hooks определены в [`docs/development.md`](development.md#git-workflow).

`CODE_STANDARDS.md` владеет правилами PHP/Laravel и именования. Архитектурные boundaries принадлежат `architecture.md`, интерфейсные правила — `UI_STANDARDS.md`, а эксплуатационная история — `MAINTENANCE_LOG.md`.

## Правила Laravel

- Не менять серверную конфигурацию в рамках прикладных задач.
- Не использовать сидеры для производственных данных каталога.
- Держать `php artisan seasonvar:import` единственной публичной командой импорта Seasonvar.
- Предпочитать Eloquent-связи сырым запросам.
- Загружать заранее все связи, которые используются Blade-страницами.
- Любой literal eager load в `with()`, `load()` или `loadMissing()` обязан задавать проекцию связанных столбцов через colon syntax или `select()`. В проекцию всегда входят primary key связанной модели и foreign/local keys, необходимые Eloquent для сопоставления; список содержит только поля, которые читает query/presenter/action boundary.
- Dynamic relation maps допустимы только через централизованные projection helpers. Единственное текущее исключение для полного relation aggregate — `SeasonvarTitleMerger`, который переносит и сравнивает доменные строки целиком; это исключение нельзя копировать в публичные, API, paginated, export или batch-read запросы.
- Не выполнять запросы к базе внутри Blade-шаблонов.
- Не использовать `@php`, `@endphp`, `<?php` или `<?=` в Blade; вычисления переносить в контроллеры, view-model, view-data классы или классы компонентов.
- Blade и Blade component classes не выполняют database/Eloquent, Cache, Redis, Memcached, service resolution, filesystem или environment calls. Они получают готовые presentation data; raw `{!! !!}` допустим только после документированного strict sanitizer review.
- Livewire использует только отдельные class-based компоненты `app/Livewire` + `resources/views/livewire`. Laravel Volt, `livewire/volt`, anonymous component classes и смешивание PHP-класса с Blade не допускаются.
- Cache keys/TTL/invalidation не пишутся строками в controllers/Livewire. Используются `App\Support\Cache`, cache-aware query/page services и after-commit `CatalogCacheInvalidator`; `Cache::flush()` в application code запрещён.
- Использовать `withCount()` для счетчиков связей в списках.
- Считать количества пакетно, когда это возможно, а не отдельным запросом на каждый видимый фильтр.
- Фильтры маршрутов должны оставаться локальными: `/titles/{type}/{taxonomy}` и `/titles?...` всегда ведут на страницы местного каталога.
- Нормализовать и проверять все входные параметры перед применением фильтров.
- Размещать request validation в Form Request-классах; reusable/сложные проверки оформлять Rule-классами.
- Размещать authorization decisions в gates, policies или middleware; Blade может использовать только простые `@can`, `@cannot`, `@auth`, `@guest` для отображения.
- Пользовательские uploads хранить только через приватный disk `uploads` или явно авторизованный private-диск; публичную выдачу делать отдельным signed/authorized endpoint.
- Notifications и emails должны быть queueable, содержать только безопасный operational context и тестироваться отдельно на dispatch и content.

## Правила импортера

- Использовать только `https://seasonvar.ru/` как домен источника Seasonvar.
- Собирать метаданные, связи, рейтинги, дополнительные названия, отзывы, сезоны, серии, постеры, состояние страницы источника, HTML-снимки и видео-кандидаты.
- Не зависеть от внешних страниц плеера для локальной навигации.
- Сохранять только внешние видео-ссылки; никогда не скачивать видеофайлы в приложение.
- Для медиа хранить `source_media_key`, качество, перевод, формат и поля проверки доступности.
- Все проверки доступности видео-ссылок проводить через `SeasonvarMediaAvailabilityChecker`; не дублировать HTTP-логику Range-проверок в сервисах импорта.
- Сохранять уже существующих актеров, серии и медиа, даже если Seasonvar перестал возвращать их на странице.
- Очищать названия перед сохранением: убирать `>>>`, хвосты про онлайн-просмотр, шум дат и суффиксы Seasonvar.
- Текст обновления сезона вроде `(09.07.2026 1 серия (AniDub) из ??)` хранить как структурированные поля сезона плюс исходный текст статуса.
- Значения, похожие на связи, хранить в конкретных таблицах связей, если они фильтруются: жанр, страна, актер, режиссер, возраст, перевод, статус, сеть, студия, метка.
- Не вводить morph- или polymorphic-связи для метаданных каталога; использовать явные `belongsToMany`-связи и pivot-таблицы.
- Синхронизация связей должна группировать разобранные значения по типу, пакетно выполнять `upsert`, получать ID одним запросом на тип и добавлять новые pivot-записи без удаления старых отсутствующих связей.
- Запись карточки после успешного HTTP-разбора должна выполняться в одной транзакции базы: карточка, связи, сезоны, серии и финальный parsed-статус страницы источника.
- Синхронизация сезонов и серий должна использовать пакетный `upsert` вместо построчного `updateOrCreate()`.
- Сохранение найденных URL должно идти пакетами, через `upsert`, и не должно сбрасывать существующие страницы источника в pending.
- Импортер должен отклонять неправильные ссылки каталога Seasonvar вроде вложенных `.html/...` и помечать уже сохраненные неправильные страницы как недоступные, не запрашивая их снова.
- Обновление карточки должно сначала искать точное совпадение по `source_url_hash`, а уже потом выполнять более широкий поиск дублей по названию.
- Импортер должен пропускать HTML-разбор и запись каталога для неизменившихся страниц, которые уже разобраны и имеют карточку.
- В каждом цикле импортер должен проверять небольшой backlog старых медиа с пустым или устаревшим `check_status`.
- В каждом цикле импортер должен переводить старые разобранные `source_pages` из pending-состояния импорта в parsed-состояние.
- В каждом цикле импортер должен дозаполнять отсутствующие качество, формат и перевод медиа без повторного скачивания страниц источника.
- В каждом цикле импортер должен дозаполнять отсутствующие `source_media_key` у старых медиа и сохранять стабильность ключа для будущих обновлений видео-ссылок.
- Длинный запуск импорта должен обновлять счетчики `seasonvar_import_runs` после каждого обработанного chunk или URL, а не только в конце цикла.
- При раскрытии HLS master playlist в варианты качества импортер должен сохранять контекст сезона и серии.
- Импортер должен сохранять трейлеры и анонсы даже без номера серии; обычное видео серии по-прежнему требует совпавший сезон и серию.
- Возрастные значения должны быть короткими строгими рейтингами вроде `18+`, `16+`, `12+`; длинный текст нельзя сохранять как возрастной рейтинг.
- Значения связей для жанра, страны, статуса, сети, студии и метки должны оставаться короткими и не содержать описательных предложений.
- Структуру сезонов и серий хранить как `seasons` и `episodes`, а не как метки.
- Дублированные страницы сезонов объединять в одну `CatalogTitle`; сезоны должны оставаться внутренними записями этой карточки.

## Правила запросов

- `CatalogTitlesPageBuilder` должен пакетно разрешать активные relation-фильтры через конкретную модель из `CatalogTaxonomyRegistry`; Livewire-компонент и Blade не строят SQL.
- Multi-value фильтры используют OR внутри одной группы и AND между разными группами; эту семантику нельзя дублировать вне `CatalogTitleQuery`.
- Счетчики контекста боковых фильтров должны считаться агрегированными или union-запросами по pivot-таблицам, а не циклами по каждому элементу.
- Счетчики по годам должны считаться сгруппированным запросом по году, а не циклом по строкам года.
- `CatalogController::show()` должен один раз готовить сгруппированные коллекции связей и передавать их в Blade.
- `CatalogController::show()` должен заранее загружать только те связи, которые используются текущими Blade-блоками.
- Запросы рекомендованных карточек не должны заранее загружать сезоны или коллекции связей, если блок рекомендаций их не показывает.
- Many-to-many фильтры каталога должны иметь обратный pivot-индекс по связанному ID и `catalog_title_id`.
- Списки, отсортированные по `indexed_at`, должны иметь индекс, который поддерживает сортировку и частые фильтры по году.
- Поиск медиа для страниц карточек должен иметь индекс по карточке, статусу и дате публикации.
- Карточка тайтла получает summaries/counts и active-season episodes через `CatalogTitlePlaybackQuery`; нельзя возвращать eager loading всех серий всех сезонов или дублировать playback availability в Blade/Livewire. Publication/audience/window ограничения для query и loaded releases принадлежат `CatalogEntitlementService`; новые plan/region/profile/concurrency источники подключаются только туда.
- Private watchlist/rating/progress writes проходят policy и `CatalogUserStateService`, а `catalogTitleId` Livewire-компонента остаётся locked; browser ID всегда повторно разрешается внутри доступной иерархии. Список просмотра записывается explicit desired-state операцией через unique insert-or-ignore и conditional update, а не toggle read-modify-write; одинаковый retry не меняет `updated_at`. Пользовательские агрегаты считаются только из `catalog_title_user_states.rating`; импортные `catalog_title_ratings` к ним не присоединяются.

## Правила именования

- Модели называются в единственном числе: `CatalogTitle`, `Genre`, `Country`, `Actor`, `Director`, `AgeRating`, `Translation`, `CatalogStatus`, `Network`, `Studio`, `Tag`, `Season`, `Episode`.
- Коллекции используют описательные имена во множественном числе: `$recommendedTitles`, `$taxonomiesByType`.
- Имена данных для view должны описывать подготовленные данные, а не детали реализации.
- Blade-компоненты лежат в `resources/views/components`, переиспользуемый интерфейс лежит в `resources/views/components/ui`.

## Коллекции

- Новая collection behavior расширяет только `App\Services\Collections`, `CatalogCollectionQuery`, enums/models/policy/DTO и существующие catalog/account/cache/SEO boundaries. Не создавайте второй list/folder/playlist model, pivot, visibility mapper или cache infrastructure.
- Все mutations принимают stable resolved record/UUID, берут actor/owner server-side, повторно authorizes и выполняют short transaction. Invalidation регистрируется after commit; membership не вызывает watchlist/rating/progress/history services.
- User text проходит `UserPlainText`, остаётся plain original-language content и экранируется. Editorial translations живут в DB rows; visibility/type/moderation/sort codes никогда не переводятся в storage.
- Query-object заранее готовит owner, translations, counts, cover fallback, permissions/state and paginated items. Blade не рассчитывает visibility/SEO/order/count, не вызывает relation lazy load и не конкатенирует collection URL.
- Livewire хранит locked stable IDs/version и bounded scalar/UUID drafts, не full Eloquent graphs. Используются existing Vite/Tailwind/components, без Volt, `@php`, inline CSS и inline business JavaScript.

## Обсуждения

- Любая новая comment mutation расширяет существующие `App\Actions\Comments`/policy/services и `Comment` table; отдельная reply/reaction/report architecture для title/season/episode/collection запрещена.
- Target type, sort/status/reaction/report/restriction/notification values проходят enums и resolver, не raw class/column/translated label. Author/target/moderation fields не принимаются из client data.
- Body обрабатывается только `CommentBody`/`UserPlainText` и escaped Blade. Не добавлять unrestricted HTML, Markdown renderer, auto-link/preview или mention notification без отдельного audited domain contract.
- Read path готовит DTO/query overlays; Blade не считает permissions/status/counts/block/spoiler и не lazy-load-ит relations. Viewer state не входит в public cache. Replies остаются bounded root threads, direct routes — redirects на target.
- Visible control обязан иметь canonical action, localized state/error/loading и server authorization. Новые labels добавляются одновременно в exact-parity `lang/ru/comments.php` и `lang/en/comments.php`; placeholder/plural structure проверяется автоматически, а естественность, терминология и aria-label — редактором обеих локалей.

## Отзывы

- Любая review mutation расширяет `catalog_title_reviews`, `App\Actions\Reviews`, policy/services/query/presenter и существующие rating/account/cache/merge boundaries. Вторая review/rating/helpfulness/moderation table, comment-as-review adapter или season/episode review запрещены без нового product contract.
- Target/status/origin/sort/vote/report/restriction/deletion/moderation/notification values проходят enums; arbitrary class, raw column и translated DB value запрещены. Actor/owner/status/verified target берутся server-side, rating переиспользует `CatalogTitleUserState`.
- User title/body проходят `ReviewTitle`/`ReviewBody`/`UserPlainText`, остаются original-language escaped plain text. Не добавлять unrestricted HTML, Markdown, automatic translation/link preview/sentiment/reaction/schema без отдельного audited contract.
- Public query/presenter готовит counts, canonical rating, permissions, spoiler body, author and vote totals; viewer vote/block/mute/restriction/pending state — отдельный overlay. Blade не lazy-load-ит models и не рассчитывает permission/average/helpfulness/verified/spoiler.
- Stable ID/aliases переживают edit/delete/restore/slug/title merge. Create/vote/report must be idempotent and transaction-safe; cache invalidation is after commit and scoped. Visible actions require localized success/error/loading/confirmation and exact `lang/ru/reviews.php`/`lang/en/reviews.php` parity.

## Рейтинги Top 100

- Новая категория расширяет `CatalogTopListCategory` и общий `CatalogTopListQuery`; отдельные controller/query/view для каждого Top 100 запрещены. Route binding принимает только backed enum values.
- Рейтинг строится только из canonical public/watchable eligibility и provider ratings, имеет bounded limit 100 и стабильный ID tie-breaker. Viewer overlay не может менять публичный candidate set, score, место, sitemap или shared cache key.
- Формула и границы категорий документируются в `docs/catalog-search.md` и покрываются feature tests. Blade получает DTO, переиспользует `x-catalog.title-card` и не выполняет ranking/database/service logic.
- Backed enum route parameters должны сериализоваться в public cache dimensions по их scalar value; отбрасывать enum parameter и объединять HTML разных категорий запрещено.

<!-- project-docs:start -->
## Автоматизация документации, карты сайта и robots

- После изменений маршрутов, карты сайта, robots или команд нужно запускать `php artisan project:docs-refresh`.
- После PHP-правок по-прежнему обязателен `vendor/bin/pint --dirty --format agent`.
- Совместимый `/sitemap.xml` должен оставаться адресом индекса карты сайта, а не монолитной картой всех URL.
- Карта видео должна включать только опубликованные медиа с абсолютной внешней ссылкой `http://` или `https://`.
- `/sitemap.xml` (`sitemap`)
- `/sitemap-index.xml` (`sitemap.index`)
- `/sitemap-static.xml` (`sitemap.static`)
- `/sitemap-taxonomies.xml` (`sitemap.taxonomies`)
- `/sitemap-landings.xml` (`sitemap.landings`)
- `/sitemap-titles-{page}.xml` (`sitemap.titles`)
- `/sitemap-videos-{page}.xml` (`sitemap.videos`)
<!-- project-docs:end -->
