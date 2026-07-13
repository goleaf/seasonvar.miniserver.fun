# Архитектура приложения

Обновлено: 13.07.2026

## Контроллеры

- Контроллеры остаются тонкими: принимают route/request зависимости, выбирают view или responder и не собирают сложные запросы, SEO-массивы или view state.
- Страницы каталога используют page-builder сервисы в `App\Services\Catalog`:
  - `CatalogHomePageBuilder` готовит данные главной страницы.
  - `CatalogTitlesPageBuilder` готовит выдачу каталога, фильтры, счетчики и SEO для списка.
  - `CatalogTitlePageBuilder` готовит статическую metadata-оболочку тайтла, summaries сезонов, рекомендации и SEO без загрузки всех серий.
- `/titles`, `/titles/year/{year}` и taxonomy-маршруты обслуживает full-page `App\Livewire\CatalogSeries`. Компонент отвечает только за URL/page state и пользовательские действия, валидирует состояние через `CatalogTitlesRequest` и ровно один раз за render делегирует данные в `CatalogTitlesPageBuilder`.
- Sitemap, feed, OpenSearch и `llms.txt` обслуживает отдельный `CatalogSitemapController`, который делегирует XML/text-ответы в `CatalogSitemapResponder`.
- JSON API обслуживает `App\Http\Controllers\Api\CatalogTitleController`: контроллер только принимает Form Request/model binding и возвращает API Resources, а выбор публичных связей выполняет `CatalogApiTitleQuery`.
- `/stats` обслуживается тонким controller-view слоем: `CatalogController::stats()` отдает SEO и Livewire-обертку, live-данные рендерит `App\Livewire\StatsDashboard`, а постеры статистики отдает `CatalogStatsPosterResponder` через внутренний proxy-маршрут.
- `/titles/{catalogTitle:slug}` сохраняет `CatalogShowRequest`, implicit route binding, historical-slug redirect и SSR SEO без побочных эффектов в тонком controller/view слое. Только после browser `wire:init` компонент `App\Livewire\CatalogTitleDetail` может запросить targeted refresh и затем владеет полной динамической оболочкой страницы; вложенный `CatalogTitlePlayer` отдельно отвечает за URL-state активного сезона/серии/media и authenticated user actions. Оба компонента держат `catalogTitleId` locked; Eloquent-коллекции существуют только как render data.
- `/watching` обслуживает full-page `App\Livewire\ViewingActivity`: компонент хранит только paginator state, получает render-local данные из `CatalogViewingActivityQuery` и делегирует удаления в `CatalogViewingActivityService`.
- `/admin/imports` обслуживает full-page `SeasonvarImportManager`: public state ограничен boolean options и notice, а authorization, duplicate lock, status transitions, retry/cancel/recovery и bounded run projection находятся в `SeasonvarImportAdminService`.
- `/admin/catalog` обслуживает full-page `CatalogAdministrationManager`: public state ограничен поиском и малыми form arrays, critical hierarchy/version IDs имеют `#[Locked]`. Bounded чтение выполняет `CatalogAdministrationQuery`, транзакционные allowlisted writes и optimistic locking — `CatalogAdministrationService`; importer dashboard не дублируется.

## Actions и сервисы

- Класс получает одну причину для изменения: атомарная операция оформляется Action, координация нескольких шагов или внешней интеграции остаётся Service, неизменяемое состояние между слоями передаётся DTO. Новая папка создаётся только вместе с реально используемым классом; пустые архитектурные каталоги не добавляются.
- Дискретные бизнес-операции оформляются как небольшие сервисы или action-классы с constructor/method injection; контроллеры и команды не должны держать тяжелую логику внутри `handle()` или action-методов.
- Параллельный режим `seasonvar:import --queued` использует `SeasonvarQueuedImportDispatcher`, атомарные lease в `SeasonvarPageClaimManager`, Redis job `ImportSeasonvarSourcePage` и единый `FinalizeSeasonvarQueuedImport`. SQLite не используется как очередь импорта.
- Режим `seasonvar:import --inventory-only` остаётся внутри той же публичной команды. `SeasonvarPageType` и `SeasonvarUrl` задают единственную typed classification/normalization boundary, `SeasonvarSitemapMirror` рекурсивно читает XML/gzip без обращения к страницам контента, `SeasonvarSourceInventory` строит DTO и сохраняет безопасный снимок в `SeasonvarImportRun.summary`, а `SeasonvarSourceParityRegistry` является единственным реестром local parser/route/sitemap capabilities.
- `FinalizeSeasonvarQueuedImport` сохраняет per-run `ShouldBeUnique`, затем берёт второй atomic lock из явно настроенного `seasonvar.queue.lock_store`. Глобальный ключ сериализует maintenance всего каталога между разными runs; после acquisition job повторно проверяет run state и live claims, а lock всегда освобождается через `finally` либо автоматически истекает после `timeout + 300` секунд.
- Admin HTTP-поток добавляет перед dispatcher только `StartSeasonvarQueuedImport(runId)`. Общий enum задаёт `queued/running/completed/partial/failed/cancelled`; heartbeat и live claims отделяют реально живую работу от stale run.
- `SeasonvarCatalogParser` не пишет в базу: его массив сначала проходит validation/normalization в readonly `SeasonvarCatalogData`, затем `SeasonvarCatalogIdentityResolver` использует только provider ID или canonical URL identity. Catalog writes и relationship synchronization выполняются короткой transaction; внешние playlist/media requests остаются за её пределами.
- `SeasonvarEditorialFieldResolver` защищает локальные title/description/artwork через сохранённый `provider_field_values` baseline. Publication/audience/window/soft-delete и slug повторным import не меняются; частичный snapshot не отсоединяет связи и не удаляет releases/media.
- `SeasonvarRefreshPlanner` перед обычными due-кандидатами выбирает не более одного import chunk страниц `missing_data`, отсортированных по времени следующей попытки и последнего импорта. Planner исключает страницы с живым claim до применения limit, поэтому recovery chunk заполняется реально доступными страницами; истёкшие claims остаются кандидатами.
- `SeasonvarTitlePageStateSynchronizer` пересчитывает title-level `missing_data_flags` после успешного parse или unchanged-skip и одним bounded update синхронизирует только уже parsed/unclaimed страницы того же тайтла. Связанные страницы находятся по canonical id и стабильным season URL hashes; mutable `seasons.source_page_id` для этого не используется.
- Worker проверяет lease token до HTTP-запроса и пересчитывает Redis lock по canonical slug текущей `SourcePage`; поэтому разные numeric ID сезонов одного тайтла не могут одновременно менять общие связи, включая jobs из уже накопленного backlog.
- SQLite catalog transactions используют `IMMEDIATE` mode вместе с WAL и busy timeout, чтобы разные workers не сталкивались на DEFERRED read-to-write upgrade; внешний fetch остаётся за пределами transaction.
- `RecordSeasonvarPageFailure` является единственной границей записи ошибочного состояния `SourcePage`; `SeasonvarImportFailureClassifier` разделяет transient connection/408/425/429/5xx/SQLite-lock ошибки и permanent ошибки страницы. Только transient exception покидает queued job и активирует Laravel backoff/retry window.
- `SeasonvarQueueServiceProvider` изолирует queue lifecycle hooks от HTTP/view bootstrap, откатывает оставленные job транзакции и передаёт исключения/QueueBusy в throttled monitor. `SeasonvarQueueStatusData` и `SeasonvarQueueStatus` питают read-only режим `seasonvar:import --status`; основным считается active queued/running run с максимальным числом живых claims.
- Сервисы возвращают типизированный результат или готовые данные для вызывающего слоя, а вывод сообщений, HTTP-ответы и консольные коды остаются в контроллере или команде.
- Не добавлять repository-классы для простых Eloquent-связей; reusable запросы остаются в query-сервисах, scopes или page-builder сервисах.
- `project:docs-refresh` делегирует обновление управляемых блоков документации в `App\Services\ProjectDocumentation\ProjectDocumentationRefresher`, а команда только печатает результат и возвращает код выхода.
- Статистика `/stats` собирается через `CatalogStatsSnapshotBuilder`, очищается `CatalogStatsSnapshotSanitizer` и кешируется `CatalogStatsSnapshotCache`; Livewire-компонент не хранит полный stats-массив в публичном состоянии.
- Cache-aware reads проходят через `CatalogHomeSnapshotCache`, `CatalogHomeMetricsCache`, `CatalogFacetSnapshotCache` и `CatalogStatsSnapshotCache`; controllers, Livewire render и Blade не строят ключи и не выбирают store. `CatalogCacheInvalidator` является общей after-commit boundary для admin/import bulk writes, а `CatalogCacheWarmer` — bounded rebuild boundary. Полный контракт находится в `caching.md`.
- `CatalogStatsPosterUrlGuard` проверяет, можно ли безопасно проксировать внешний poster URL; `CatalogStatsPageBuilder` не рендерит `poster_src` для URL, которые guard отвергнет, а `CatalogStatsPosterResponder` повторно применяет тот же guard перед HTTP-запросом.
- `CatalogEntitlementService` является общей access boundary для уже загруженных title/season/episode/media и для SQL scopes: publication status, legacy title flag, окно доступности и `public/authenticated` audience получают одно типизированное решение. `CatalogTitlePlaybackQuery` поверх него собирает видимые summaries, точные counts, один активный сезон, playable media и deterministic next episode. `CatalogPrimaryActionResolver` выбирает continue/next/replay/start, а `CatalogUserStateService` только после повторной проверки доступности атомарно записывает желаемое состояние списка просмотра, валидирует пользовательскую оценку по `config/catalog.php`, строит один grouped aggregate внутренних оценок и записывает канонический user/episode progress. Provider ratings остаются в `catalog_title_ratings` и не смешиваются с пользовательским агрегатом.
- `CatalogPlaybackProgressSession` выпускает opaque encrypted token, привязанный к user/title/episode/media и TTL; в базе хранится только ULID session. `CatalogUserStateService` внутри короткой transaction использует unique row, `insertOrIgnore`, row lock и event sequence для idempotency/concurrent-device ordering. `CatalogPlaybackCompletionRule` единолично вычисляет percentage и completion по trusted media duration, configurable percent/remaining time или `ended`.
- `CatalogViewingActivityQuery` не создаёт вторую историю: он ранжирует канонический progress через `ROW_NUMBER()` по сериалу, вычисляет следующий доступный выпуск одним оконным sequence-запросом в той же regular/special lane и затем пакетно загружает только выбранные тайтлы/серии. История пагинируется по user и eager-loads связи; отдельный grouped accessibility query помечает скрытые, удалённые и source-less строки без N+1.
- `CatalogPlaybackSourceResolver` является единственной границей выдачи playback source: проверяет title/season/episode/media в момент разрешения и повторно на signed `/playback/{licensedMedia}`, ранжирует источники по явно заданным предпочтениям, provider priority, успешной проверке и качеству, затем возвращает небольшой `PlaybackSourceData`. Raw provider URL не передается в Livewire snapshot или Blade.
- `PlaybackSourceUrlGuard` разделяется resolver и `SeasonvarMediaAvailabilityChecker`: допускаются только HTTPS-hosts из allowlist с публичными DNS-адресами. Availability checker не следует редиректам, использует Range/streaming, timeouts и лимит `Content-Length`, а progress context получает только `[redacted-url]`.

## Запросы и валидация

- Входные параметры списка каталога нормализует и проверяет `CatalogTitlesRequest`.
- URL-состояние `/titles` хранит `CatalogSeriesFilters`: только скаляры и ограниченные массивы slug/годов. Route-контекст года и taxonomy защищён `#[Locked]`; paginator, Eloquent-модели, фасеты и SEO не сериализуются в публичный Livewire snapshot.
- Проект использует только Laravel 13.x и conventional class-based Livewire 4.x: PHP-класс находится в `app/Livewire`, view — отдельно в `resources/views/livewire`. Volt, anonymous component classes и implementation PHP в Blade запрещены. Form Objects, `#[Locked]`, `#[Url]`, `#[Renderless]`, bounded pagination, `wire:poll.visible`, `wire:ignore`, loading/confirm/navigation directives применяются только по реальной UI-boundary.
- Shared computed/persisted computed, `#[Session]`, lazy/deferred/isolated components, Islands, async actions, Teleport и `wire:stream` намеренно не добавляются без отдельного измеримого use case. Domain cache имеет явные version/invalidation contracts; SEO-critical shell рендерится сразу; mutations влияют на UI синхронно; modal использует native dialog; progressive streaming отсутствует.
- `CatalogTitlesPageBuilder` один раз разбирает нормализованный `q` через `CatalogSearchQueryParser` и собирает неизменяемый `CatalogTitlesCriteria`; тот же объект передается в выдачу, контекстные счетчики связей и счетчики годов.
- Multi-select фильтры каталога передаются как повторяемые query-параметры: годы, relation-фильтры, типы публикации, качества и наличие субтитров остаются ограниченными наборами, relation slug резолвятся пакетно, а `CatalogTitlesCriteria` хранит только нормализованные уникальные ID и enum-значения. Значения одной группы объединяются через OR, отдельные группы — через AND.
- Query-параметры выбранной серии и видео на странице карточки проверяет `CatalogShowRequest`.
- Поддерживаемые типы фильтров перечислены в `App\Enums\CatalogFilterType`, а slug-значения проверяет `App\Rules\CatalogFilterSlug`.
- Единая public query boundary находится в `CatalogTitleQuery`: `visibleTo()` первым условием делегирует `CatalogEntitlementService` publication status, legacy-флаг публикации, окно доступности и audience текущего пользователя; soft delete остаётся global scope модели. `filteredTitles()` затем применяет поиск, годы, relation- и media/rating-фильтры, а `sorted()` — только enum-сортировку с `id` tie-breaker.
- Главная, список, API, публичные блоки статистики, sitemap/feed, facet-счетчики и построитель рекомендаций начинают выборку тайтлов через эту boundary. Служебные показатели качества импорта могут намеренно считать все сохраненные строки и не являются публичной выдачей.
- Каждый relation-фильтр реализован отдельным grouped pivot `whereIn`-подзапросом: несколько ID внутри подзапроса дают OR, а несколько подзапросов в основной выборке дают AND. Основная выборка не соединяется с pivot-таблицами, поэтому не требует `distinct`, а paginator count совпадает с числом видимых тайтлов.
- `CatalogFacetQuery` загружает не более 24 актеров или режиссеров за запрос и применяет серверный поиск только к нормализованной строке от двух до 80 символов; выбранные записи поднимаются в начало без дублей.
- Описание поддерживаемых фильтров, моделей связей и eager-load наборов находится в `CatalogTaxonomyRegistry`.

## Publication boundary

- `CatalogStatus` остаётся production metadata источника; публичную видимость определяют `PublicationStatus`, audience, availability window и soft-delete scope.
- `HasPublicationAvailability` больше не компилирует правила самостоятельно: scopes `published()` и `availableTo()` делегируют SQL-часть `CatalogEntitlementService`. Публичные page builders/API queries ограничивают сезоны, серии и media parents до eager loading и `withCount()`.
- `ReleaseKind` и составные unique keys отделяют specials от обычной нумерации. Relationship-модели отвечают за единый порядок, поэтому контроллеры и Blade не сортируют выпуски самостоятельно.
- Доступ `authenticated` пока означает только наличие `User`. `CatalogEntitlementDecision` заранее различает authentication required, plan required, region blocked, profile restricted и concurrency exceeded, но последние четыре статуса не возвращаются без реальных profile/billing/territory/stream сущностей. Отдельного admin preview bypass нет: authenticated user также не видит hidden/draft записи.

## Поиск каталога

- Полная значимая фраза сначала проверяется на точное совпадение с основным или оригинальным названием и хэшами алиасов. При наличии точного совпадения дальнейший широкий текстовый поиск не выполняется; ID кандидатов остаются SQL-подзапросом и не загружаются полной коллекцией в PHP.
- Если точного имени нет, каждый значимый терм образует отдельную `AND`-группу. Внутри группы варианты названия, описания, slug, внешнего ID, алиасов и имен связанных справочников объединяются через `OR`.
- Один распознанный год из `q` является жестким ограничением. Несовместимые годы из `q` и параметра `year` дают нулевую выдачу и не переходят к временному fallback.
- `CatalogTitleQuery` для запроса только из стоп-слов дает нулевое условие без `title` context, но сохраняет существующий `whereKey()` для title-scoped страниц. `CatalogTitlesPageBuilder` использует единственный paginator и не заменяет нулевой результат полным каталогом.
- Все варианты сортировки завершаются `catalog_titles.id DESC`, поэтому строки с одинаковыми годом, названием, счетчиками или `indexed_at` имеют устойчивый порядок.

## Авторизация

- Основные страницы каталога остаются публичными read-only страницами.
- `CatalogTitle::resolveRouteBindingQuery()` ограничивает все текущие публичные implicit bindings опубликованными тайтлами. Карточка, API show и `stats.poster` поэтому используют одну publication boundary; query-сервисы дополнительно сохраняют явный `published()` как защиту для вызовов вне HTTP binding.
- Служебная страница `/stats` тоже доступна как публичная read-only сводка, но остается под rate limiter и не раскрывает raw source URLs, приватные media URLs или stack traces.
- Livewire update endpoint использует `throttle:livewire-action`: общий actor-level Redis-бюджет 600 requests/minute и более строгий allowlisted component/action-бюджет 180 requests/minute. Страница `/stats` использует `wire:poll.15s.visible`, чтобы не держать polling в скрытой вкладке.
- Счетчики rate limiter используют `CACHE_LIMITER_STORE=redis-limiter`/connection `limiter`, отдельно от domain cache, sessions и queues; HTTP/Livewire throttles остаются атомарными без SQLite write contention.
- Новые write/admin/import-control endpoints должны получать отдельный gate или policy до регистрации маршрута.
- Authenticated действия карточки проходят auto-discovered `CatalogTitlePolicy::interact`; скрытие кнопок в Blade не используется как контроль доступа.
- `/watching` отклоняет гостя до render. `EpisodeViewProgressPolicy` разрешает удалить только собственную запись и очистить только историю текущего user; чужой числовой ID не превращается в доступ к чужой истории.

## Защитные ограничения

- В non-production `Model::shouldBeStrict()` запрещает lazy loading, молчаливое отбрасывание mass-assignment полей и чтение невыбранных атрибутов.
- В production `DB::prohibitDestructiveCommands()` блокирует `db:wipe`, `migrate:fresh`, `migrate:refresh`, `migrate:reset` и rollback-команды.
- Новые domain/action/DTO/exception/provider классы используют `declare(strict_types=1)`; массовое механическое добавление в старые файлы не требуется.

## Представление и SEO

- Blade получает готовые переменные и не использует `@php`/`@endphp`.
- Переменные для layout SEO готовит `AppLayoutData`.
- View state для фильтров и страницы тайтла находится в `App\View\ViewModels`.
- SEO, JSON-LD, breadcrumbs, поисковые фразы и related links готовит `CatalogSeoBuilder`.
- Публичная карточка тайтла отдаёт title, plain-text description, canonical, Open Graph и `TVSeries` JSON-LD в первом server-rendered response; Livewire отвечает только за интерактивный player/user state.
- `App\Support\PlainText` удаляет HTML, script/style blocks, control characters и лишние пробелы из provider/editorial metadata до её использования в meta/JSON-LD и plain-text UI.
- Locale интерфейса задаёт `<html lang>`, Open Graph locale и язык `WebPage`, но не язык произведения или media track. Отдельного content locale/audio/subtitle preference в текущей доменной модели нет, поэтому `TVSeries.inLanguage` намеренно отсутствует.
- Переводы каталога хранятся в `lang/{locale}/catalog.php`; русская локаль — основная/fallback, а plural counts формируются `trans_choice()` вместо ручных окончаний.
- `catalog_title_slugs` хранит прежние публичные slug. Route binding применяет ту же publication/access boundary, а controller отвечает `301` на текущий canonical slug без переноса query string. Import slug allocation резервирует историю, а merge переносит её к каноническому тайтлу.

## API Resources

- Публичные JSON-ответы используют ресурсы в `app/Http/Resources`, а не массивы в контроллерах.
- Ресурсы не раскрывают source URL, HTML-снимки, внутреннее состояние импортера, raw media URLs, ключи медиа или stack traces.
- Связи и счетчики в ресурсах добавляются только через `whenLoaded()` и `whenCounted()`; query-сервисы заранее загружают нужные отношения.
