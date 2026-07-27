# Архитектура приложения

Обновлено: 20.07.2026

## Постоянные cross-system contracts

- Domain services предоставляют стабильные application-owned contracts; provider/package objects не протекают в Blade, Livewire public state или несвязанные modules.
- Feature modules не копируют authorization, visibility, locale fallback, premium entitlement, regional access или legal-restriction logic. Они вызывают соответствующую каноническую boundary и получают минимальный typed result.
- Feature modules не создают независимые notification categories, audit systems, cache infrastructures или user-state overlays для уже существующего события/состояния.
- Shared read models и DTO остаются presentation-safe: private notes, secrets, raw provider URLs, permissions graph и полные Eloquent models в них не включаются.
- Shared actions остаются idempotent там, где request, webhook, queue job или import может быть повторён.
- Cross-feature events используют стабильные codes. Event не заменяет явный invariant и не становится местом скрытой business logic либо причиной обязательной unsupported queue infrastructure.
- Прямая synchronous integration допустима, когда она проще, безопаснее и гарантирует correctness; optional infrastructure всегда имеет documented fallback.
- Изменение shared contract требует affected-feature map, compatibility strategy, targeted invalidation и rollback до implementation.

## Постоянные backend и frontend constraints

- Laravel Volt запрещён. Livewire реализуется class-based компонентами; `@php`/`@endphp` и PHP-теги в Blade запрещены.
- Blade не выполняет model queries, service/repository/facade/database/container calls, direct class calls, business logic, permission calculation, cache-key construction или route concatenation. Он получает подготовленные DTO/view-model data и использует стабильные route helpers.
- Inline CSS и large inline JavaScript в Blade запрещены. JavaScript находится в Vite-managed modules, CSS — в существующей Tailwind/SCSS architecture; новый конкурирующий visual/runtime layer не создаётся.
- PHP-классы и их public contracts типизированы. Request/Livewire input проходит canonical Form Request, Livewire Form или reusable validation class; meaningful state changes выполняют actions/services, сложные reads — query objects, а Blade получает DTO/presenter/view-model data.
- Status/type/role/permission/config identities используют enums или stable codes. Переведённые labels никогда не становятся internal values, route identities, cache identities или database identities.
- Authorization выполняют policies, gates и middleware. Private actions, entitlement, region, moderation и legal checks всегда server-side; browser state не является доказательством доступа.
- Нельзя доверять client-supplied user identity, role, premium state, region, status, permission, price или resource ownership. Locked identifier повторно разрешается и авторизуется на сервере.
- Route names/helpers, model identities и database contracts сохраняют compatibility. Destructive schema или route replacement требует staged migration/redirect strategy.
- Mass assignment, N+1 и сериализация полных Eloquent graphs в Livewire public state запрещены. Livewire payload bounded, `wire:key` стабилен, duplicate submissions и stale responses предотвращаются.
- Safe filter/pagination state сохраняет browser back/forward behavior; query-column mapping и authorization не передаются клиенту напрямую.
- Conditions, общие нескольким компонентам, принадлежат canonical domain service, а не копируются. Public base data отделяется от viewer-specific overlays и никогда не получает общий cache с private state.

До добавления нового слоя обязательно проверяются существующие: routes, middleware, Livewire components, actions, services, query objects, DTO classes, presenters/view models, value objects, enums, policies, events, listeners, notifications, cache services, storage services, importers, administration components, translations и documentation. Новый слой создаётся только при отсутствии эквивалентного владельца.

## Route inventory Task 27

Финальный Task 26 inventory 20.07.2026 зарегистрировал 242 routes: 67 под `/api`, 17 под `/admin` и 158 остальных web/framework entries. Administration routes имеют `auth`, `auth.session`, `verified`, `account.private`, `account.active`, `admin.access` и явный action gate; mobile writes используют Sanctum abilities, verification middleware и domain policies. Signed playback/download, attachment, payment-return и webhook boundaries остаются отдельными узкими routes. Destructive administration mutation через `GET` не зарегистрирована; multi-method legacy aliases выполняют только redirect и не изменяют state. `php artisan route:list --json` остаётся live source of truth, поэтому датированный count обновляется при route changes и не используется как authorization proof.

## Third-party и upgrade boundaries

- Используются framework-supported public APIs, а не undocumented internals. Version-specific решения подтверждаются официальной документацией.
- Third-party usage изолируется application-owned services/adapters, когда это practically отделяет provider/package contract. Provider-specific classes не распространяются по несвязанным features.
- Package objects не передаются в Blade или Livewire public state; provider/package statuses отображаются в stable application enums/codes, а не package text.
- Package configuration централизована; `env()` вне `config/*.php` запрещён.
- Service-container bindings не создают скрытых global side effects. Duplicate providers, listeners, middleware, route macros и cache stores запрещены.
- Package facade не добавляется только ради удобства, когда важна dependency isolation.
- Package removal охватывает config, providers, aliases, migrations, assets, translations, docs, deployment, environment и cache cleanup.
- Package replacement сохраняет transition adapter, когда persisted data/public contracts зависят от прежнего package.
- Deprecated code не suppress-ится бессрочно. Каждый shim документирует owner, purpose, dependants и removal condition.
- Reflection, dynamic class resolution, monkey patching и undocumented hooks не используются без подтверждённой архитектурной необходимости.
- Payment, mail, storage, search, media, cache и external providers используют стабильные application contracts; optional package failure деградирует через documented fallback.
- Framework upgrade сохраняет route names/public URLs, event/notification codes, cache identities, translation/permission keys и DB identity values либо предоставляет полную compatibility migration.
- Канонические package purposes, runtime states, decisions, shims и removal conditions находятся только в [`maintenance/`](maintenance/dependency-inventory.md); этот раздел задаёт architectural boundary, но не копирует registry.

## Production configuration и deployment boundaries

- Environment values читаются только через `config/*.php`; application code не вызывает `env()` напрямую и не содержит absolute server paths или production hostnames.
- Filesystem disks, cache/session/queue drivers и mail transports используют canonical Laravel configuration. External clients получают typed configuration, bounded timeouts и безопасное failure behavior; retryable mutations используют idempotency там, где повтор может повредить state.
- Application version и build identity доступны через безопасную конфигурацию без secret context. Service-worker cache names versioned; manifest и соответствующие hashed assets разворачиваются как одна совместимая единица.
- Миграции не предполагают background workers. Long/locking migrations выявляются до deployment; destructive schema changes используют staged compatibility, когда old/new code могут сосуществовать.
- Maintenance mode не раскрывает debug context. Public health возвращает только минимальный summary, а детальное operational state требует авторизации.
- Logs используют структурированный безопасный context и не содержат secrets, cookies, authorization headers, protected URLs или private file contents.

## Целевая архитектура и data flow

Цель — сохранить Laravel-native слои и текущие доменные сервисы, уменьшая только доказанные god boundaries. Не вводится универсальный repository/action/interface слой.

HTTP/API read flow:

`Route → middleware → Form Request/route binding → controller orchestration → focused query/page builder → ViewModel/API Resource/Response → response`.

HTTP mutation flow:

`Route → middleware → Form Request authorize+validate → typed input when data crosses layers → focused service/action → short transaction → after-commit invalidation/event → Resource/redirect`.

Livewire mutation flow:

`Component action → locked identifier resolution → policy/gate → Livewire Form validation → typed input → focused service/action → short transaction → after-commit cache invalidation → prepared component state → passive Blade`.

Import flow:

`seasonvar:import → one global lifecycle decision → bounded page jobs → guarded HTTP → parser DTO → semantic fingerprint → title-group fan-in → short catalog transaction/upserts → terminal run version → scoped FTS/recommendation/cache work`.

Playback flow:

`Page/API → entitlement → episode/media resolver → short-lived signed viewer grant → delivery reauthorization → allowlisted provider redirect → browser player → throttled monotonic progress service`.

Target boundaries:

- Blade receives scalars/arrays/DTOs/view models and prepared flags/classes/URLs only. Route checks, config reads, services, queries, authorization, formatting derivation and state mutations do not belong in templates.
- Models own relationships/casts/scopes/small predicates, not importer/admin workflows.
- Actions represent meaningful mutations; cohesive technical capabilities stay services.
- Slow network calls never occur inside a DB transaction.
- After-commit invalidation and derived work share one operation-owned boundary; user state and signed playback data never enter public shared cache.
- Expected domain failures use stable exceptions/error codes; internal exception text is never an API/Livewire user message.

## Контроллеры

- Контроллеры остаются тонкими: принимают route/request зависимости, выбирают view или responder и не собирают сложные запросы, SEO-массивы или view state.
- Страницы каталога используют page-builder сервисы в `App\Services\Catalog`:
  - `CatalogHomePageBuilder` готовит данные главной страницы.
  - `CatalogTitlesPageBuilder` готовит выдачу каталога, фильтры, счетчики и SEO для списка.
  - `CatalogTitlePageBuilder` готовит статическую metadata-оболочку тайтла, summaries сезонов, рекомендации и SEO без загрузки всех серий.
- `/titles`, `/titles/year/{year}` и taxonomy-маршруты обслуживает full-page `App\Livewire\CatalogSeries`. Компонент отвечает только за URL/page state и пользовательские действия, валидирует состояние через `CatalogTitlesRequest` и ровно один раз за render делегирует данные в `CatalogTitlesPageBuilder`.
- Одиннадцать directory hubs (`/genres`, `/countries`, `/actors`, `/directors`, `/age-ratings`, `/translations`, `/statuses`, `/networks`, `/studios`, `/tags`, `/years`) обслуживает один full-page `CatalogDirectoryBrowser`. Locked `directory` выбирается только route default; URL-bound `q`, `letter`, `sort`, `decade` и paginator нормализуются до query. `CatalogDirectoryRegistry` хранит UI/route metadata, `CatalogTaxonomyRegistry` остаётся authority модели/relation, `CatalogDirectoryQuery` строит grouped SQL, а `CatalogDirectoryPageBuilder` готовит render/SEO данные ровно один раз.
- Sitemap, feed, OpenSearch и `llms.txt` обслуживает отдельный `CatalogSitemapController`, который делегирует XML/text-ответы в `CatalogSitemapResponder`.
- JSON API обслуживает `App\Http\Controllers\Api\CatalogTitleController`: контроллер только принимает Form Request/model binding и возвращает API Resources, а выбор публичных связей выполняет `CatalogApiTitleQuery`.
- Mobile API работает рядом с legacy API через `/api` discovery и версионированную группу `/api/v1`. Небольшие controllers отвечают только за request orchestration; `App\Services\Catalog\Api\V1` владеет фильтрованной выдачей, detail hierarchy, suggestions, recommendations, reviews и owner-scoped библиотеками, а explicit Resources — публичной/приватной JSON-формой. `PlaybackProgressRecorder` в этом namespace разрешает видимый пользователю тайтл и типизированно адаптирует проверенное HTTP-событие, но каноническая авторизация, trusted playback session, длительность и запись progress остаются только в `CatalogUserStateService`; остальные state mutations также переиспользуют этот сервис. Continue Watching/history используют `CatalogViewingActivityQuery` и `CatalogViewingActivityService`, а `MobilePlaybackSessionService` компонует существующие playback query/resolver; отдельная мобильная доменная модель не создаётся. `AssignApiRequestId`, optional Sanctum middleware, `EnsureMobileEmailIsVerified` и централизованный `ApiErrorResponse` задают общий transport contract. Legacy controllers/resources не заменяются v1-классами.
- `/stats` обслуживается тонким controller-view слоем: `CatalogController::stats()` отдает SEO и Livewire-обертку, live-данные рендерит `App\Livewire\StatsDashboard`, а постеры статистики отдает `CatalogStatsPosterResponder` через внутренний proxy-маршрут.
- `/titles/{catalogTitle:slug}` сохраняет `CatalogShowRequest`, implicit route binding, historical-slug redirect и SSR SEO без побочных эффектов в тонком controller/view слое. Только после условного browser `wire:init` без модификаторов компонент `App\Livewire\CatalogTitleDetail` может запросить targeted refresh и затем владеет полной динамической оболочкой страницы; init отсутствует при active refresh, свежем `completed` state или отсутствии source URL. `CatalogTitleRefreshCoordinator` вычисляет render hint и повторно проверяет eligibility под distributed lock, поэтому hint не становится client-trusted boundary. Вложенный `CatalogTitlePlayer` отдельно отвечает за URL-state активного сезона/серии/media и authenticated user actions. Оба компонента держат `catalogTitleId` locked; Eloquent-коллекции существуют только как render data.
- `/watching` обслуживает full-page `App\Livewire\ViewingActivity`: компонент хранит только paginator state, получает render-local данные из `CatalogViewingActivityQuery` и делегирует удаления в `CatalogViewingActivityService`.
- `/admin/imports` обслуживает full-page `SeasonvarImportManager`: public state ограничен boolean options и notice, а authorization, duplicate lock, status transitions, retry/cancel/recovery и bounded run projection находятся в `SeasonvarImportAdminService`.
- `/admin/catalog` обслуживает full-page `CatalogAdministrationManager`: public state ограничен поиском и малыми form arrays, critical hierarchy/version IDs имеют `#[Locked]`. Bounded чтение выполняет `CatalogAdministrationQuery`, транзакционные allowlisted writes и optimistic locking — `CatalogAdministrationService`; importer dashboard не дублируется.

## Actions и сервисы

- Класс получает одну причину для изменения: атомарная операция оформляется Action, координация нескольких шагов или внешней интеграции остаётся Service, неизменяемое состояние между слоями передаётся DTO. Новая папка создаётся только вместе с реально используемым классом; пустые архитектурные каталоги не добавляются.
- Дискретные бизнес-операции оформляются как небольшие сервисы или action-классы с constructor/method injection; контроллеры и команды не должны держать тяжелую логику внутри `handle()` или action-методов.
- Параллельный режим `seasonvar:import --queued` и browser-triggered refresh сходятся в `SeasonvarImportTitleGroupDispatcher`: каждая известная страница сезонного семейства получает независимый `PrepareSeasonvarImportTitlePage`, а один `FinalizeSeasonvarImportTitleGroup` детерминированно применяет подготовленные payload внутри коротких catalog transactions. `SeasonvarImportTitleGroup`/`SeasonvarImportPreparedPage` дают durable fan-out/fan-in state; Redis хранит очередь и critical locks, SQLite — только authoritative catalog и audit state.
- `SeasonvarGlobalImportRunCoordinator` является общей короткой start-boundary для полного sitemap import в `sync` и `queue` execution modes. Один distributed start-lock охватывает только проверку active `queued/running` sitemap-run и вставку новой lifecycle-строки; pipeline/network work под ним не выполняются. Sync CLI заранее резервирует run и передаёт его в `SeasonvarImportPipeline`, поэтому pipeline не создаёт вторую audit-строку. Повторный sync/queued/admin start возвращает существующий global run без dispatch. `mode=url`, inventory и status намеренно остаются вне этой single-flight границы.
- Режим `seasonvar:import --inventory-only` остаётся внутри той же публичной команды. `SeasonvarPageType` и `SeasonvarUrl` задают единственную typed classification/normalization boundary, `SeasonvarSitemapMirror` рекурсивно читает XML/gzip без обращения к страницам контента, `SeasonvarSourceInventory` строит DTO и сохраняет безопасный снимок в `SeasonvarImportRun.summary`, а `SeasonvarSourceParityRegistry` является единственным реестром local parser/route/sitemap capabilities.
- Обычный и queued import выбирают страницу через `SeasonvarPageHandlerRegistry`, а не через switch в catalog importer. Definition каждого типа фиксирует discovery persistence, automatic parsing, metadata-only границу, parser/importer, retry policy, expected result, source-access класс и отдельное разрешение локальной публикации. Serial handler оставляет существующий `SeasonvarCatalogParser`/catalog pipeline; actor/genre/country/tag делегируют в `SeasonvarTaxonomyPageParser` и `SeasonvarTaxonomyPageImporter`; RSS только обновляет freshness serial queue; passive handlers никогда не запрашивают static/search/sitemap/unknown страницы.
- `SeasonvarSourcePageFetcher` централизует allowlist, crawl delay, ETag/Last-Modified и 304. Для serial сохраняется совместимый raw snapshot, нужный metadata backfill; metadata/RSS snapshot намеренно содержит только page type, исходный размер и content hash, чтобы не копировать provider prose. `SeasonvarRefreshPlanner` применяет enabled/automatic/type-specific refresh/chunk/retry настройки; явный `--page-type` не может обойти disabled или parser-less тип.
- `FinalizeSeasonvarQueuedImport` сначала ждёт terminal state всех title groups и только затем берёт global maintenance lock из `seasonvar.queue.lock_store`. Partial/failed группы учитываются в результате общего run. Тот же `SeasonvarImportStorageMaintenance`, который вызывается на maintenance-boundary pipeline, доступен через независимый empty-payload unique job: один проход делит общий row/chunk/monotonic-time budget между events, snapshots и terminal groups с каскадными prepared pages, требует terminal parent run, сохраняет latest snapshot и не выполняет provider HTTP. Daily schedule зарегистрирован выключенным по умолчанию до production backup/owner/canary gate.
- Admin HTTP-поток добавляет перед dispatcher только `StartSeasonvarQueuedImport(runId)`. Общий enum задаёт `queued/running/completed/partial/failed/cancelled`; heartbeat и live claims отделяют реально живую работу от stale run.
- `SeasonvarCatalogParser` не пишет в базу: его массив сначала проходит validation/normalization в readonly `SeasonvarCatalogData`, затем `SeasonvarCatalogIdentityResolver` использует только provider ID или canonical URL identity. Catalog writes и relationship synchronization выполняются короткой transaction; внешние playlist/media requests остаются за её пределами.
- `SeasonvarEditorialFieldResolver` защищает локальные title/description/artwork через сохранённый `provider_field_values` baseline. Publication/audience/window/soft-delete и slug повторным import не меняются; частичный snapshot не отсоединяет связи и не удаляет releases/media.
- `SeasonvarRefreshPlanner` перед обычными due-кандидатами выбирает не более одного import chunk страниц `missing_data`, отсортированных по времени следующей попытки и последнего импорта. Planner исключает страницы с живым claim до применения limit, поэтому recovery chunk заполняется реально доступными страницами; истёкшие claims остаются кандидатами.
- `SeasonvarTitlePageStateSynchronizer` пересчитывает title-level `missing_data_flags` после успешного parse или unchanged-skip и одним bounded update синхронизирует только уже parsed/unclaimed страницы того же тайтла. Связанные страницы находятся по canonical id и стабильным season URL hashes; mutable `seasons.source_page_id` для этого не используется.
- Page worker проверяет lease token до HTTP-запроса и не пишет каталог: страницы одного и разных тайтлов могут готовиться параллельно. Короткий Redis apply-lock действует только на finalizer одной канонической URL family; он не блокирует preparation или независимые тайтлы.
- Preparation получает bounded media availability до staging. `SeasonvarCatalogImporter::applyPreparedPage()` под title apply-lock записывает только prepared availability и переводит отсутствующий или устаревший размер прямого файла в `pending`; provider `HEAD`/minimal `Range` выполняет только общая bounded file-size backlog stage после catalog apply. Поэтому title finalizer не удерживает lock на provider latency, а HLS manifest не интерпретируется как полный видеофайл.
- SQLite catalog transactions используют `IMMEDIATE` mode вместе с WAL и busy timeout, чтобы разные workers не сталкивались на DEFERRED read-to-write upgrade; внешний fetch остаётся за пределами transaction.
- `RecordSeasonvarPageFailure` является единственной границей записи ошибочного состояния `SourcePage`; `SeasonvarImportFailureClassifier` разделяет transient connection/408/425/429/5xx/SQLite-lock ошибки и permanent ошибки страницы. Только transient exception покидает queued job и активирует Laravel backoff/retry window.
- `SeasonvarQueueServiceProvider` изолирует queue lifecycle hooks от HTTP/view bootstrap, откатывает оставленные job транзакции и передаёт исключения/QueueBusy в throttled monitor. `SeasonvarQueueStatusData` и `SeasonvarQueueStatus` питают read-only режим `seasonvar:import --status`; основным считается active queued/running run с максимальным числом живых claims.
- `LicensedMediaFileSizeBacklog` централизует direct-file eligibility и TTL/retry due predicate для importer pipeline и read-only observability. Один typed conditional aggregate сохраняется через operational `TieredCache`; CLI status и authorized admin Livewire получают counters/bytes/capture time без media hydration, URL disclosure или сетевого inspection. Per-media writes не bump-ят snapshot: bounded fifteen-minute eventual consistency исключает повторный full SQLite scan на каждом poll.
- Сервисы возвращают типизированный результат или готовые данные для вызывающего слоя, а вывод сообщений, HTTP-ответы и консольные коды остаются в контроллере или команде.
- Не добавлять repository-классы для простых Eloquent-связей; reusable запросы остаются в query-сервисах, scopes или page-builder сервисах.
- Query/service boundary владеет формой eager-loaded relation: он явно выбирает используемые столбцы и ключи сопоставления, а Resource/Presenter/Blade только читает уже подготовленную модель или DTO. Централизованные taxonomy/card maps возвращают closures с projections; локальные detail/export/import reads задают собственный bounded список.
- Полный relation graph не считается универсальным aggregate. Он разрешён `SeasonvarTitleMerger` только на mutation boundary, где переносятся все aliases, ratings, taxonomies, seasons и episodes; read-only код не может ссылаться на это исключение.
- `project:docs-refresh` делегирует обновление управляемых блоков документации в `App\Services\ProjectDocumentation\ProjectDocumentationRefresher`, а команда только печатает результат и возвращает код выхода.
- Статистика `/stats` собирается через `CatalogStatsSnapshotBuilder`, очищается `CatalogStatsSnapshotSanitizer` и кешируется `CatalogStatsSnapshotCache`; Livewire-компонент не хранит полный stats-массив в публичном состоянии.
- Cache-aware reads проходят через `CatalogHomeSnapshotCache`, `CatalogHomeMetricsCache`, `CatalogFacetSnapshotCache` и `CatalogStatsSnapshotCache`; controllers, Livewire render и Blade не строят ключи и не выбирают store. `CatalogCacheInvalidator` является общей after-commit boundary для admin/import bulk writes, а `CatalogCacheWarmer` — bounded rebuild boundary. Полный контракт находится в `caching.md`.
- `CatalogStatsPosterUrlGuard` проверяет, можно ли безопасно проксировать внешний poster URL; `CatalogStatsPageBuilder` не рендерит `poster_src` для URL, которые guard отвергнет, а `CatalogStatsPosterResponder` повторно применяет тот же guard перед HTTP-запросом.
- `CatalogEntitlementService` является общей access boundary для уже загруженных title/season/episode/media и для SQL scopes: publication status, legacy title flag, окно доступности и `public/authenticated` audience получают одно типизированное решение. `CatalogTitlePlaybackQuery` поверх него собирает видимые summaries, точные counts, один активный сезон, playable media и deterministic next episode. `CatalogPrimaryActionResolver` выбирает continue/next/replay/start, а `CatalogUserStateService` только после повторной проверки доступности атомарно записывает желаемое состояние списка просмотра, валидирует пользовательскую оценку по `config/catalog.php`, строит один grouped aggregate внутренних оценок и записывает канонический user/episode progress. Provider ratings остаются в `catalog_title_ratings` и не смешиваются с пользовательским агрегатом.
- `CatalogPlaybackProgressSession` выпускает opaque encrypted token, привязанный к user/title/episode/media и TTL; в базе хранится только ULID session. `CatalogUserStateService` внутри короткой transaction использует unique row, `insertOrIgnore`, row lock и event sequence для idempotency/concurrent-device ordering. `CatalogPlaybackCompletionRule` единолично вычисляет percentage и completion по trusted media duration, configurable percent/remaining time или `ended`.
- `MobilePlaybackGrant` выпускает отдельный короткоживущий encrypted grant только с version, nullable user ID, media ID и expiry. Same-origin signed mobile delivery сначала валидирует grant и привязанного user, затем повторно вызывает `CatalogPlaybackSourceResolver`; signed URL не заменяет domain authorization и не переносит provider URL в API Resource.
- `CatalogViewingActivityQuery` не создаёт вторую историю: он ранжирует канонический progress через `ROW_NUMBER()` по сериалу, вычисляет следующий доступный выпуск одним оконным sequence-запросом в той же regular/special lane и затем пакетно загружает только выбранные тайтлы/серии. История пагинируется по user и eager-loads связи; отдельный grouped accessibility query помечает скрытые, удалённые и source-less строки без N+1.
- `UserTitleStateQuery` собирает личное state, aggregate и primary action без записи. `UserLibraryQuery` начинает watchlist/ratings с `whereBelongsTo($user)`, пересекает их с `CatalogTitleQuery::visibleTo($user)` и заранее загружает card relations/counts. API Resources не выполняют запросов; query-delta при росте страницы остаётся постоянным.
- Offline-sync использует append-only invalidation journal, а не копию catalog graph. `CatalogSyncChangePublisher` сворачивает import/backfill/admin/merge изменения до `title.upsert|delete` после успешной транзакции; `UserSyncChangePublisher` публикует owner-scoped state/progress/history entries. Оба publisher fail-safe проверяют наличие additive schema и не превращают завершённую доменную запись в ошибку transport-подсистемы.
- `ApiSyncCursorCodec` шифрует scope, nullable owner, monotonic journal ID и время выдачи; `ApiSyncPullQuery` выполняет bounded keyset pull без offset и продвигает checkpoint до последнего возвращённого journal ID. Повторные invalidations остаются append-only transport events и могут сворачиваться клиентом только после сохранения нового checkpoint. `ApiSyncMutationService` сначала атомарно резервирует owner/UUID receipt по canonical SHA-256 payload, затем переиспользует `CatalogUserStateService` и `CatalogViewingActivityService`; request payload и playback grant в journal/receipt не сохраняются.
- Версии watchlist/rating проверяются под row lock и инкрементируются только при фактической смене соответствующего desired state. `ApiSyncRetentionPruner` удаляет changes старше 30 дней и receipts старше 90 дней ordered ID-пачками до 500; console command остаётся тонким transport-слоем, а scheduler запрещает overlap и дубли на нескольких процессах.
- `CatalogPlaybackSourceResolver` является единственной границей выдачи
  playback source: проверяет title/season/episode/media в момент разрешения и
  повторно на signed web `/playback/{licensedMedia}` или mobile
  `/api/v1/playback/{licensedMedia}`. Порядок выбора:
  явный разрешённый media/variant, любимая озвучка, запасной перевод, режим
  озвучки или оригинала с субтитрами, явно распознанный язык субтитров,
  прежние format/quality/provider/health сигналы. Hidden variants
  исключаются до ранжирования и из меню, включая mobile API; raw provider
  URL и private preference set не передаются в Blade/JSON/shared cache.
- `PlaybackSourceUrlGuard` разделяется resolver и `SeasonvarMediaAvailabilityChecker`: допускаются только HTTPS-hosts из allowlist с публичными DNS-адресами. Availability checker не следует редиректам, использует Range/streaming, timeouts и лимит `Content-Length`, а progress context получает только `[redacted-url]`.

## Запросы и валидация

- Входные параметры списка каталога нормализует и проверяет `CatalogTitlesRequest`.
- URL-состояние `/titles` хранит `CatalogSeriesFilters`: только скаляры и ограниченные массивы slug/годов. Route-контекст года и taxonomy защищён `#[Locked]`; paginator, Eloquent-модели, фасеты и SEO не сериализуются в публичный Livewire snapshot.
- Проект использует только Laravel 13.x и conventional class-based Livewire 4.x: PHP-класс находится в `app/Livewire`, view — отдельно в `resources/views/livewire`. Volt, anonymous component classes и implementation PHP в Blade запрещены. Form Objects, `#[Locked]`, `#[Url]`, `#[Renderless]`, bounded pagination, `wire:poll.visible`, `wire:ignore`, `wire:intersect`, `wire:text`, `wire:dirty`, `wire:transition`, loading/confirm/navigation directives применяются только по реальной UI-boundary. `wire:text` допускается для локальной presentation-only производной от уже entangled/deferred state при сохранённом SSR fallback; `wire:dirty` допускается только для точно указанного draft, где следующая синхронизация совпадает с каноническим Apply/Cancel boundary и не изображает database truth. `wire:transition` применяется только к ограниченным add/remove/change boundaries; встроенные `prefers-reduced-motion` и мгновенный fallback браузеров без View Transitions API не переопределяются custom animation. Authorization и persistence остаются server-side. Layout подключает Livewire styles/scripts один раз для всех routes, поэтому новые full-page компоненты не требуют route-specific asset condition.
- Единственный полный application `wire:ignore` принадлежит keyed media shell `CatalogTitlePlayer`, потомками которого управляют Plyr/HLS и `player-menu.js`; `.self` не защитил бы эти library-generated descendants. Корень workspace отдельно использует `wire:ignore.self`, чтобы Livewire не заменял client-owned session attributes после hot swap, но продолжал morph дочернего server-owned UI. Loading overlay, media selection, errors и portal/personal controls остаются вне полного shell; другие native/server-owned widgets не получают ignore без подтверждённого DOM ownership. Внутри media boundary разрешены только JavaScript-owned меню сезонов/серий/переводов и локальные loading/error states, необходимые для сохранения fullscreen DOM identity; второй полный `wire:ignore` и client-trusted access state запрещены. Данные и один signed source grant выдают последовательные bounded `#[Renderless]` actions после повторной server-side проверки. `#[Json]` для этой границы запрещён, потому что установленный Livewire `4.3.3` автоматически включает для него параллельное выполнение.
- `wire:replace` inventory ограничен четырьмя template pattern `wire:replace.self` на leaf-checkbox contextual filters каталога с `wire:model.live`: новый input принимает authoritative checked state после grouped island response, а окружающие labels/groups продолжают morphing. Bare subtree replacement отсутствует. Новый replacement допустим только после воспроизводимого DOM reuse/internal-state defect, когда более узкие `wire:key`, component boundary и explicit lifecycle не устраняют проблему; player остаётся под keyed `wire:ignore`, а native dialogs/forms/editors и text/search inputs сохраняют focus, draft и DOM identity через обычный morphing.
- Единственный application `wire:show` без modifiers принадлежит малой форме outdated-report публичной help-статьи: form остаётся в DOM, false initial state закрыт `wire:cloak`, а toggle связан через `aria-controls`/`aria-expanded`. Visibility не является authorization; validation, actor identity, deduplication и persistence выполняются только server action. Native collection dialog сохраняет add/remove + Vite focus lifecycle, а create-collection form — отдельный `wire:transition` contract.
- Shared computed/persisted computed, `#[Session]` и Teleport намеренно не добавляются без отдельного измеримого use case. Lazy island каталога и именованные pagination islands являются проверенными исключениями: Livewire сам добавляет `wire:intersect.once="__lazyLoadIsland"` к busy placeholder фасетов, а приложение не вызывает internal action напрямую и не дублирует observer в Alpine/JavaScript. Каждый paginator обновляет только собственный подготовленный presentation array и region, сохраняя server-owned URL state, authorization и обычные ссылки. Общий `InteractsWithPaginationIslands` переиспользует данные normal render и вызывает типизированный `render()` через container только для isolated fallback, не сохраняя Eloquent graph в public state. Domain cache имеет явные version/invalidation contracts; SEO-critical shell рендерится сразу; mutations влияют на UI синхронно; modal использует native dialog.
- `#[Async]` запускает action параллельно и немедленно, без queue; modifier `.async` включает тот же режим для отдельного вызова. Он допустим только для идемпотентного fire-and-forget side effect, который не меняет отражённое в UI состояние компонента, не зависит от порядка соседних requests и имеет отдельную failure/observability boundary. Application inventory равен нулю: текущие Livewire actions изменяют form/status/pagination/player/import state, требуют validation/authorization/transaction response или делегируют фоновую работу существующим queue/post-commit services. Добавлять async к таким mutations запрещено из-за stale snapshots и lost updates.
- `wire:stream` дописывает содержимое по умолчанию до завершения одного Livewire-request, а `replace: true` или modifier `.replace` заменяет содержимое именованной DOM-цели; официальный runtime contract несовместим с Laravel Octane. Приложение не имеет bounded progressive-text producer, поэтому `wire:stream` и `$this->stream()` отсутствуют. Импорт и media checks принадлежат очередям/командам, активный статус имеет конечный `wire:poll.visible`, player получает разрешённый media source, а sitemap/feed/download используют отдельный Laravel `response()->stream()` и не являются Livewire DOM-streaming. Новый target требует отдельного обоснования partial-content lifecycle, escaping, cancellation/failure UX, timeout и production runtime compatibility.
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

- Полная значимая фраза сначала проверяется на точное совпадение с основным, оригинальным названием или хэшами алиасов; ID кандидатов остаются SQL-подзапросом и не загружаются полной коллекцией в PHP.
- `CatalogTitleQuery::matchingTitles()` — единая title-match boundary для `/titles`, `/search`, header и mobile suggestions. Она всегда начинает с `visibleTo()`, применяет год и выбирает ranked FTS либо безопасный legacy/exact-short path; presenters/controllers не повторяют publication или matching predicates.
- Если точного имени нет, каждый значимый терм образует отдельную `AND`-группу по основному, оригинальному и альтернативным названиям. Описания, slug, внешние ID и имена relations из текстового поиска исключены.
- Один распознанный год из `q` является жестким ограничением. Несовместимые годы из `q` и параметра `year` дают нулевую выдачу и не переходят к временному fallback.
- `CatalogTitleQuery` для запроса только из стоп-слов дает нулевое условие без `title` context, но сохраняет существующий `whereKey()` для title-scoped страниц. `CatalogTitlesPageBuilder` использует единственный paginator и не заменяет нулевой результат полным каталогом.
- Все варианты сортировки завершаются `catalog_titles.id DESC`, поэтому строки с одинаковыми годом, названием, счетчиками или `indexed_at` имеют устойчивый порядок.

## Авторизация

- Основные страницы каталога остаются публичными read-only страницами.
- `CatalogTitle::resolveRouteBindingQuery()` ограничивает все текущие публичные implicit bindings опубликованными тайтлами. Карточка, API show и `stats.poster` поэтому используют одну publication boundary; query-сервисы дополнительно сохраняют явный `published()` как защиту для вызовов вне HTTP binding.
- Служебная страница `/stats` тоже доступна как публичная read-only сводка и не раскрывает raw source URLs, приватные media URLs или stack traces.
- Livewire update и temporary upload endpoints используют стандартный `web` middleware stack без локального request budget. `/stats` не использует `wire:poll`: публичный запрос один раз читает подготовленный snapshot, а его обновлением владеют importer/admin invalidation и плановый warmer.
- Публичные web/catalog/API маршруты не создают локальный HTTP 429. Узкие mobile credential endpoints имеют отдельные named budgets из `api.md`; ответ 429 от Seasonvar/CDN остаётся внешней transient-ошибкой с bounded retry/backoff.
- Новые write/admin/import-control endpoints должны получать отдельный gate или policy до регистрации маршрута.
- Authenticated действия карточки проходят auto-discovered `CatalogTitlePolicy::interact`; скрытие кнопок в Blade не используется как контроль доступа.
- `/watching` отклоняет гостя до render. `EpisodeViewProgressPolicy` разрешает удалить только собственную запись и очистить только историю текущего user; чужой числовой ID не превращается в доступ к чужой истории.

## Каноническая аутентификация

- Единственная browser identity boundary — Laravel `web` session guard с Eloquent `User`; единственная mobile boundary — Sanctum с `mobile:read|mobile:write`. `users` password broker, Laravel `Hash`, signed email verification и стандартная remember-token механика не заменяются собственными криптографическими реализациями.
- Guest-потоки обслуживают существующие Livewire pages и typed `App\Services\Auth` services. Канонические `/login`, `/register`, `/forgot-password`, `/reset-password/{token}` сохраняются; `/{locale}/...` — только локализованные aliases к тем же components. API `/api/v1/auth/*` вызывает те же registration, recovery, reset, verification и account services.
- `NormalizedEmail` одинаково trim-ит и lowercase-ит email для registration/login/recovery/reset/profile/social-ready matching, не удаляя точки и plus-addressing. `User::whereEmailIdentity()` остаётся case-insensitive compatibility lookup, а unique race завершается существующим database constraint. Отдельного normalized-email столбца и data rewrite не требуется.
- `Password::defaults()` — одна политика для registration/reset/change: минимум 12 символов, letters, mixed case, number и symbol, плюс resource ceiling 255. Browser guard выполняет verification/rehash; mobile login явно применяет `Hash::needsRehash()` после успешной credential check. Password, hash и broker tokens не входят в DTO, log, cache или Livewire render state.
- `AccountRegistrationService` принимает только name/email/password, создаёт обычного unverified user внутри transaction, сохраняет поддерживаемую active locale только когда account choice отсутствует и отправляет locale-aware verification. `AUTH_REGISTRATION_ENABLED=false` убирает web и API create routes; клиент не задаёт role, premium, restriction или verification timestamp.
- `AuthenticationRedirectService` принимает только безопасный internal path/same-origin URL и отклоняет external/protocol-relative/control/double-encoded/malformed/auth-loop destinations. Locale хранится server-side и после OAuth-ready/verification/reset переходов выбирается только из `ru|en`; reset/verification token не попадает в canonical/OG/structured metadata.
- Verification остаётся temporary signed и idempotent; recovery/reset остаются Laravel broker-owned, expiring и replay-safe. Recovery body generic для существующего/несуществующего адреса. Email change требует текущий пароль, снимает verification и вращает remember token; reset вращает remember token и отзывает mobile tokens, а `auth.session` завершает старые browser sessions после изменения password hash.
- Database-session UI доступен только при database driver, показывает bounded summary и принимает HMAC opaque action token вместо session ID. Logout и logout-other используют canonical guard/session methods; mobile devices owner-scoped, а sensitive web actions требуют текущий пароль и очищают только использованное secret state.
- `AuthenticationAuditService` пишет стабильный event code, internal user ID и HMAC email/network fingerprints в отдельный daily channel. Passwords, raw email/IP/user-agent, reset/verification/OAuth code/provider token, cookie, session ID и request payload запрещены; channel retention задаёт `AUTH_AUDIT_DAYS`.
- Socialite/OAuth providers, external identities, provider linking/unlinking, automatic merging, magic links, MFA, trusted devices и account-status model отсутствуют. Соответствующие controls/routes/data не создаются; совпавший provider email никогда не считается доказательством владения или основанием для auto-merge.
- Guest bookmark/watch-status storage отсутствует. Проигрыватель хранит максимум 50 свежих позиций за 30 дней в versioned `seasonvar.playback-progress.v1`; после входа и подтверждения email существующий `/settings/preferences/migrate` передаёт только stable episode/position/duration/time в канонический `CatalogUserStateService`. Batch visibility query повторно разрешает доступные цели, любая существующая account progress строка имеет приоритет, imported completion не доверяется, а `completion_source=anonymous` исключает ложную verified-watching отметку до настоящего playback event. Миграция идемпотентна, возвращает в private response только принятые episode IDs, очищает только их неизменившиеся local snapshots и никогда не блокирует authentication; остальная библиотека, коллекции, комментарии, рецензии, Premium и moderation остаются на прежнем `user_id`.

## Защитные ограничения

- В non-production `Model::shouldBeStrict()` запрещает lazy loading, молчаливое отбрасывание mass-assignment полей и чтение невыбранных атрибутов.
- В production `DB::prohibitDestructiveCommands()` блокирует `db:wipe`, `migrate:fresh`, `migrate:refresh`, `migrate:reset` и rollback-команды.
- Новые domain/action/DTO/exception/provider классы используют `declare(strict_types=1)`; массовое механическое добавление в старые файлы не требуется.

## Представление и SEO

- Blade получает готовые переменные и не использует `@php`/`@endphp`.
- `AppLayoutData` возвращает явный bounded contract для layout: scalar meta/URL/flags, нормализованные breadcrumbs, prepared navigation и список hex-safe JSON-LD strings. Он не использует `extract()`/`get_defined_vars()` и не передаёт Blade произвольный входной graph.
- View state для фильтров и страницы тайтла находится в `App\View\ViewModels`.
- Canonical SEO, JSON-LD и breadcrumbs готовит `CatalogSeoBuilder`; `AppLayoutData` очищает текстовые поля и кодирует каждый JSON-LD object через Laravel `Js::encode()` до render. Единственный raw boundary в Blade выводит уже готовую JSON-строку внутри `application/ld+json` и покрыт closing-script/XSS regression.
- Недостижимая матрица автоматически придуманных keyword/query/semantic blocks удалена: ни один producer не включал её flags, а Google требует, чтобы structured data соответствовали видимому основному контенту. В layout остаются title/description/robots, canonical/discovery links, Open Graph/Twitter и правдивые builder-owned JSON-LD objects.
- Публичная карточка тайтла отдаёт title, plain-text description, canonical, Open Graph и `TVSeries` JSON-LD в первом server-rendered response. Full-page lazy loading намеренно не применяется: `wire:init` обслуживает только необязательный post-SSR refresh и присутствует лишь для stale refreshable state. После init Livewire владеет полной динамической оболочкой страницы, а вложенный `CatalogTitlePlayer` — URL-state плеера и authenticated user actions; canonical, Open Graph и JSON-LD остаются в controller SEO shell.
- `App\Support\PlainText` удаляет HTML, script/style blocks, control characters и лишние пробелы из provider/editorial metadata до её использования в meta/JSON-LD и plain-text UI.
- Locale интерфейса задаёт `<html lang>`, Open Graph locale и язык `WebPage`, но не язык произведения или media track. Отдельного content locale/audio/subtitle preference в текущей доменной модели нет, поэтому `TVSeries.inLanguage` намеренно отсутствует.
- Переводы каталога хранятся в `lang/{locale}/catalog.php`; русская локаль — основная/fallback, а plural counts формируются `trans_choice()` вместо ручных окончаний.
- `catalog_title_slugs` хранит прежние публичные slug. Route binding применяет ту же publication/access boundary, а controller отвечает `301` на текущий canonical slug без переноса query string. Import slug allocation резервирует историю, а merge переносит её к каноническому тайтлу.

### Локализация интерфейса и главной страницы

- Поддерживаются только allowlisted `ru` и `en`; application default и fallback — `ru`. Web precedence: валидный route locale → session locale для Livewire hydration → сохранённая user setting → guest session → default. API отдельно выбирает allowlisted locale из `Accept-Language`. Cookie/domain/subdomain detection и отдельные language databases отсутствуют.
- Интерфейс хранится в semantic PHP catalogs `lang/{ru,en}/*.php`; JSON language files и translation package не используются. Task 01 использует `home.*`, общая оболочка — `catalog.layout.*`/`catalog.locale.*`. Named placeholders совпадают между locale, предложения не собираются из переведённых фрагментов, plural nouns проходят через `trans_choice`, числа — через `Number::format`.
- `/{locale}` является локализованным alias главной, `/{locale}/search` — alias общей noindex-страницы поиска; `/` и `/search` остаются default-locale routes. Остальной портал сохраняет существующую частичную `localized.*` strategy. Slug вручную не префиксуется и не переводится.
- Единственный visible locale control — allowlisted select в account appearance settings; публичный header и доменные редакторы его не дублируют, POST `/interface-locale` отсутствует. Сохранённое `user_account_settings.locale` и session locale применяет `ApplyAccountPreferences` в `web` group до обычного и Livewire render, поэтому update/pagination/modal hydration не возвращается к default locale. Mutable arbitrary locale не попадает в file paths, route decisions или cache keys.
- Editorial collection content продолжает читать existing translation rows через active → configured fallback → base behavior, а его current authoring boundary остаётся фиксированной на `ru`. Global tag administration отдельно выбирает поддерживаемый `ru|en` для каждой translation и alias row; активная/fallback локаль влияет только на чтение, не на stable identity. Личный web-flow не угадывает язык UGC и сохраняет `content_locale=null`; API принимает только явно переданный allowlisted `ru|en`, а PATCH без поля сохраняет прежнее значение. Core title/season/episode, studio/audio/subtitle и остальной UGC не машинно переводятся: `display_title` сохраняет различие provider/original/alternative values, а interface locale не считается content/audio/subtitle language.
- `AccountDateTimeFormatter` форматирует homepage date groups и card dates через `IntlDateFormatter` с active locale и account/default timezone; `today`/`yesterday` — переводимые keys. Localized home SEO задаёт translated title/description/JSON-LD, self canonical, reciprocal `ru`/`en` alternates и x-default; sitemap содержит только реально существующие locale routes.

## Канонический домен коллекций

Именованные пользовательские списки, умные личные подборки, редакционные подборки и защищённые system records представлены только `CatalogCollection`; отдельной модели «lists», «playlists», «favorites groups» или второй smart-aggregate нет. Watchlist в `catalog_title_user_states.in_watchlist`, ratings, progress и history остаются независимыми. Коллекции поддерживают только `CatalogTitle`: сезоны, серии, users и external links не являются item types.

### Identity, ownership и lifecycle

- Реляционная identity — неизменяемый `catalog_collections.id`; внешний non-secret identifier — UUID `public_id`. Имя, глобальный slug, имя владельца, категория, locale, visibility и item count не участвуют в identity.
- User/editorial/system являются стабильными кодами `CatalogCollectionType`. Обычный verified user создаёт только `user`; editorial и system boundaries защищены `manage-catalog`, причём system records нельзя создавать через пользовательский UI. `CatalogCollectionMode::Manual|Smart` определяет способ формирования состава: smart mode разрешён только owner-owned user collection, всегда private и хранит versioned allowlisted правила вместо materialized membership.
- `owner_id` задаётся сервисом из authenticated actor и никогда не принимается из формы. User collections всегда имеют owner; editorial/system допускают системное владение схемой, но текущий editor flow сохраняет creator-owner. Переход владения и collaborators отсутствуют.
- `CatalogCollectionService` владеет create/update/soft-delete/restore/force-delete. Удаление сразу снимает public access; restoration проверяет текущую soft-deleted row и 30-дневный cutoff под lock, конкурентный завершённый retry не переписывает version, а material restore всегда возвращает запись как private/approved. `catalog-collections:prune` bounded-пакетами окончательно удаляет просроченные записи, повторно проверяя cutoff под тем же lock, что и permanent delete; schedule запускает его ежедневно в 04:07 через уже существующий scheduler.
- Account export отдаёт исходные metadata, mode, rules version, нормализованные smart rules, категорию, translations, visibility/sort, timestamps, order/items и допустимый public URL без paths и moderation notes. Для smart mode export не materialize-ит текущий динамический состав и не выдаёт public URL. Существующий account-deletion transaction окончательно удаляет owned collections, а generic collection comments privacy-retire; watchlist/progress/history обрабатываются собственными прежними правилами.
- `CatalogCollectionSchema` — единая rolling-deploy capability boundary: она требует все пять collection tables и `users.public_id`, запоминает результат на request и fail-closed при ошибке schema inspection. До полного применения migrations публичные/listing read boundaries возвращают пустые paginators/exports, account cleanup безопасно ничего не делает, create отвечает безопасным `503`, а direct identity resolution — `404`. `User::creating` отдельно проверяет наличие additive UUID column, поэтому регистрация до migration сохраняет прежний INSERT, а после migration получает UUID; это совместимость окна deploy, а не замена обязательному migration-before-traffic порядку.

### Privacy, moderation и text

- `private`, `unlisted`, `public` — единственные стабильные visibility values. Default — `private`. Private доступна owner/admin, отсутствует в directory/search/API/sitemap и выдаётся только `private, no-store`; unauthorized resolution маскируется 404. Unlisted доступна по intended slug, но `noindex`, не находится directory/search/recommendations/sitemap. Public видима только при `approved`, непустом составе не более 500 тайтлов, активной категории с активным родителем, дате публикации и доступном source record. Этот `eligibleForPublicListing()` scope един для directory, detail policy, API, поиска, профиля, sitemap, SEO и recommendation signals; прямой slug не обходит его.
- Smart collection принудительно остаётся `type=user`, `visibility=private`, без категории, публикации и feature state. Public eligibility дополнительно требует `mode=manual`; поэтому ручная подмена сохранённых полей не выводит smart rules или персональный результат в directory, public profile, API, search, sitemap, related/recommendations либо shared cache.
- Moderation codes: `pending`, `approved`, `rejected`, `hidden`, `archived`. Новая или изменённая public/unlisted user collection становится pending; private user content approved локально. Admin queue и audit покрывают approve/reject/hide/archive/feature/report resolution. Featured может быть только approved public editorial record.
- `CatalogCollectionPublicationReadiness` расширяет базовую public-quality границу для перехода editorial collection в featured и для её использования главной/редакционной discovery-выдачей. Локальной подборке нужны не менее 12 доступных гостю воспроизводимых тайтлов, source-managed — не менее 4; любая недоступная связь, отсутствующий источник, неактивная категория, превышение public cap, несовпадение editorial/public/approved, отсутствие даты публикации или удаление закрывают readiness. Проверка ничего не публикует и не меняет автоматически. Повторное feature остаётся idempotent, unfeature разрешён даже после утраты readiness, а публичное чтение сразу перестаёт доверять устаревшему `is_featured`.
- Каноническая admin query включает pending rows, open-report targets и все non-deleted approved public editorial collections. Последняя ветка сохраняет реальный feature/unfeature workflow после одобрения; она не делает user/system records featureable и не создаёт отдельный editorial dashboard.
- Collection-level reports имеют стабильные reason/status codes, rate limit и idempotency key actor/collection/content-version/reason. После permanent deletion evidence сохраняет collection UUID/version и nullable relation. Discussion переиспользует единый comment target/reaction/report/moderation boundary; отдельной comment table для коллекций нет.
- Collection likes, follows и collaborators намеренно отсутствуют: до Task 10 в проекте не было соответствующей reusable product model. Комментарий может иметь существующую reaction, но она не представляется как «лайк коллекции».
- Каждая manual collection обязана получать version-aware `quality_score` от 0 до 100 до новой public approval, feature или редакционной верификации. Оценка хранит четыре объяснимых компонента `metadata`, `structure`, `theme`, `trust`; учитывает category/text clarity, bounded/watchable structure, exact composition duplicates, похожий шаблонный текст, theme-match и причины элементов, collection reports, а также только privacy-safe aggregate watchlist/completion/return signals существующих тайтлов. Низкая или stale оценка не допускает подборку в public recommendations; список больше 500 элементов остаётся hard-ineligible независимо от score. Автоматический анализ создаёт review issues и никогда не удаляет fuzzy match, тогда как exact demo/source quarantine сохраняет отдельную provenance- и backup-защищённую границу. Source-managed manual и owner-only smart records помечаются как динамические, но smart result остаётся private; badge «Проверено редакцией» действителен только для точной `content_version`. Likes, follows и collaborators этим quality contract не вводятся.
- `CatalogCollectionQualityAssessor` является единственной write-boundary производных quality-данных. Он получает один snapshot списка таблиц через `CatalogCollectionSchema`, агрегирует engagement grouped-запросами, вычисляет составную SHA-256 сигнатуру отсортированных title ID и сравнивает fuzzy-кандидатов только в ограниченных token buckets. Каноническим exact duplicate является запись с наименьшим уже сохранённым ID; все остальные получают review issue и penalty `35`, но не удаляются. Один chunk делает одну выборку существующих сигнатур, а theme hydration ограничен public cap. Асессор пишет score, компоненты, item match/reason и issues в транзакции без изменения пользовательского `updated_at`; эти derived поля исключены из mass assignment.
- `catalog-collections:quality-refresh` обрабатывает ограниченную dirty/stale очередь, поддерживает `--all`, `--limit` и безопасный `--dry-run`, сохраняет bounded run evidence и запускается scheduler каждые десять минут с overlap/server guards. Изменение public eligibility инвалидирует существующие collection/homepage/sitemap/title-detail/recommendation/API domains; отдельного кеша score нет. Rolling schema capability включается только после появления последней таблицы migration `catalog_collection_quality_issues`, поэтому частично применённая migration не открывает запись.
- `UserPlainText` выполняет Unicode NFKC normalization, удаляет HTML/script/style, control/bidi characters и сохраняет абзацы описания. Blade всегда экранирует значение. User name/description не переводятся автоматически; interface locale не записывается как язык user content.

### Slug, категории и items

- `CatalogCollectionSlugService` формирует глобальный lowercase slug как readable base плюс полный UUID. UUID делает конкурентное распределение детерминированным; current/history namespace проверяется до записи. Rename сохраняет предыдущий slug, resolver принимает current/history/case variant и controller после policy даёт один `301` на canonical, поэтому private metadata не раскрывается redirect-ом и loops невозможны.
- Единственный public directory коллекций встроен через один вложенный `CatalogCollectionExplorer` во все девять поддерживаемых `/discover/{type}`. Совместимый default `/discover` и его localized aliases являются только `302` entry routes к соответствующему `popular#collections`; они не рендерят отдельную страницу и не создают второй owner. Отдельного `/collections` directory, `/lists`, `/selections`, `/my/lists` или `/recommendations` нет, и эти адреса возвращают `404` без redirects. На каждом mode каталог подборок расположен перед mode-specific выдачей сериалов и доступен через `#collections`; `popular` сохраняет совместимый `#popular-titles`, остальные modes используют общий `#discovery-titles`, а глобальная ссылка «Подборки» ведёт к locale-aware popular URL с прежним fragment. Detail `/collections/{slug}`, localized detail, owner/profile и API contracts остаются каноническими; прежний cover route удалён.
- `CatalogCollectionCategory` — один управляемый двухуровневый справочник: корень или дочерний узел хранится одним nullable FK `catalog_collection_category_id`. Public/unlisted owner/editor write требует активный узел, private и trusted importer могут оставаться без категории. Importer не угадывает и не переписывает назначение. В public directory доступны только active root/child; «Без категории» остаётся административной очередью классификации и не является публичным разделом. Category/subcategory query-state получает clean canonical URL текущего discovery mode и `noindex,follow`. Создание, перевод, порядок, archive/restore и bounded bulk assignment до 100 UUID принадлежат `content.manage`.
- `CatalogCollectionClassificationQuery` по умолчанию пагинирует только public/approved подборки без категории и после пагинации загружает bounded evidence текущей страницы; visibility, moderation и type остаются явными фильтрами. `CatalogCollectionCategorySuggestionService` вычисляет детерминированную подсказку по allowlisted словам и агрегированным признакам не более 50 тайтлов, но ничего не сохраняет. Выбор всей страницы, строки по подсказке и одна категория для выбранного пакета меняют только request/browser draft; confidence меняет порядок только текущей страницы. Администратор видит причины, может изменить цель и обязан пройти отдельный preview/confirm. `CatalogCollectionCategoryService::confirmAssignments()` повторно разрешает authoritative collection/category state под lock, проверяет `content_version`, пишет audit и применяет не более 100 точных назначений. Category tree, queue, summary и suggestions являются request-scoped computed; единая manager island обновляет счётчик, строки, preview и progressive category dictionary синхронно.
- Собственные изображения подборок полностью удалены из upload/import/runtime/schema. Все карточки и hero являются text-only; категория, описание и счётчики не заменяются poster fallback. `catalog-collections:purge-covers` сохранился только как guarded rolling-deploy cleanup: default dry-run, exact `uploads/catalog-collections/`, explicit `--execute`, безопасный no-op после удаления колонок.
- `CatalogCollectionItemService` — единственная write boundary для add/remove/batch/reorder/move/title merge. Она повторно авторизует locked current collection и, для добавления, current visible title; exact add/remove/batch/full-order retry не меняет domain version, но может повторить bounded targeted cache invalidation после потерянного ответа. Public/unlisted membership ограничен 500 строками, private storage сохраняет прежний предел 5 000; проверка выполняется под collection lock. Unique `(catalog_collection_id,catalog_title_id)` и service idempotency запрещают дубль, но один title разрешён во многих collections. Item хранит только collection/title, manual `position`, `added_by_id` и timestamps.
- Все add/remove/batch/reorder/move boundaries повторно проверяют `mode=manual` под lock. Для smart collection они отклоняют даже прямой или forged вызов; скрытие drag/remove controls в Livewire не используется как authorization.
- Multi-selector на title page загружает одной выборкой не более 100 manageable collections и membership `exists`, держит draft UUID set, а Apply транзакционно сверяет его с owner-scoped locked records и bulk insert/delete. Cancel ничего не записывает; create-and-add оборачивает обе операции одной transaction. Ни одна membership operation не меняет watchlist, rating, blacklist, progress или history.
- Manual order использует `position,id`. `CatalogCollectionEditor` добавляет один modifier-free `wire:sort` только внутри текущего 24-item pagination window: browser передаёт stable item ID и page-local zero-based position, service под collection lock повторно проверяет policy, membership/current/target window и rate limit, затем обновляет только затронутый диапазон и canonical content-version/cache boundary. Handle ограничивает pointer/touch drag, interactive controls имеют `wire:sort:ignore`, а up/down остаются обязательной keyboard/touch/no-drag альтернативой. Межстраничные/group/cross-collection moves и full browser order payload запрещены; existing bounded `reorder()` до 500 IDs остаётся отдельной service boundary. Automatic sort modes (`added_desc`, `added_asc`, `title_asc`, `year_desc`, `rating_desc`, `updated_desc`) меняют только query ordering и сохраняют manual positions.
- Title merge переносит membership до force-delete duplicate: existing canonical row сохраняет минимальную position, самое раннее meaningful `created_at` и attribution, затем positions нормализуются. Удалённый/скрытый title не ломает owner page: public query исключает его, owner получает bounded unavailable placeholder и может удалить membership.
- Импортное обновление одного title проверяет только его существующие publicly listed collection memberships. При наличии связи оно обновляет collection/home/sitemap/recommendation/API generations и не делает global TitleDetail flush; fan-out до 1 000 collections получает targeted scopes, больший набор безопасно переходит на один global collection generation. Private/unlisted/pending collections не имеют shared public summary и не создают лишний bump; полный import по-прежнему выполняет существующую общую catalog invalidation в finalizer.

### Умные личные подборки

- `smart_rules_version=1` поддерживает только `country_slug`, `genre_slug`, `actor_slug`, `imdb_min`, `year_from`, `year_to`, `completion`, `episodes_max`, `max_episode_minutes`, `in_library`, `unwatched`, `has_subtitles`, `has_new_episodes`, `watch_status`, `watch_status_older_days` и `video_available`. `CatalogSmartCollectionRules` нормализует строки, boolean, целые и десятичные значения, проверяет диапазоны и сочетания. Неизвестный key/version, пустой набор или malformed stored JSON fail-closed дают пустой результат.
- `CatalogSmartCollectionQuery` строит parameter-bound Eloquent subqueries по актуальным taxonomy, ratings, seasons, media availability, owner library/progress/status и release updates. Он не записывает `catalog_collection_items`, не вызывает provider и не зависит от queue, scheduler или cache. Результат сортируется с deterministic title-ID tie-breaker и пагинируется; карточки получают существующие grouped relations и personal state без N+1.
- Готовые presets являются presentation-кодами и только заполняют draft: новые корейские триллеры, короткие завершённые комедии, библиотека с новыми сериями, непросмотренное с выбранным актёром, брошенное более 90 дней назад и библиотека с доступным видео. Сохранение выполняет существующий `CatalogCollectionService::update()` под policy, collection lock и optimistic `content_version`, атомарно вместе с metadata.
- Существующий collection-scope iCalendar feed использует динамический title-ID subquery владельца, поэтому автоматически видит новый состав без изменения token/scope/window/limit. Account export сохраняет правила, а не вычисленный снимок. Title merge и importer не переписывают правила: stable taxonomy slugs и личные scalars разрешаются при следующем чтении.

### Синхронизация внешних редакционных подборок

- `catalog-collections:sync-hdrezka` — отдельная публичная команда только для редакционных подборок HDRezka; она не входит в `seasonvar:import`, не создаёт второй импортёр тайтлов и не меняет Seasonvar source identity. Функция выключена по умолчанию, а включённое расписание запускает её ежедневно в `03:37` под distributed lock с ограничениями количества подборок, страниц, элементов, размера ответа и задержкой между запросами.
- `HdRezkaCollectionUrlGuard` принимает только HTTPS-страницы точного host `hdrezka.my` и разрешённые пути индекса, подборки, пагинации и карточки. Автоматические redirects отключены; sync может вручную принять не более одного канонического перехода только на тот же host и путь того же назначения, повторный/циклический/off-host переход отклоняется. Credentials, fragments, обход пути и произвольные URL также запрещены. Источник запрашивается через HTTP/2, поскольку его edge отклоняет HTTP/1.1; bounded sink сохраняет общий лимит body, timeout и retry. Обход начинает с полного индекса и следует по pagination каждой найденной подборки до настроенного предела.
- Синхронизация сопоставляет только уже существующие `CatalogTitle`: exact normalized primary/original/approved-alias title является обязательной основой, несовпадение года или типа блокирует связь, а country/detail metadata помогают разрешить конкурирующие exact candidates. Неоднозначные и отсутствующие карточки остаются диагностическими source items; команда не создаёт фиктивные фильмы, жанры, сезоны, серии, media или публичный текст.
- `HdRezkaCollectionTypeCompatibility` является общей границей нормализации типа для matcher и диагностики. `series|show|anime|documentary` относятся к поддерживаемой области текущего каталога, `film|cartoon` — к неподдерживаемой, а отсутствующий или новый неизвестный код остаётся `unknown` и не маскируется под несовместимость. Эта классификация не ослабляет matching: известное несовпадение типов по-прежнему fail closed, неизвестный тип не даёт type-score, а scope никогда не создаёт membership самостоятельно.
- Каждая новая remote-подборка получает ownerless `editorial`, `private`, `archived`, unpublished collection с русской translation row. Синхронизация обновляет только source-owned название, items и provenance; локальные category/moderation/visibility/description/featured/publication decisions сохраняются. Редактор назначает категорию и явно проводит запись через moderation перед публикацией. Complete snapshot удаляет только устаревшие source-owned memberships/signals, а partial snapshot никогда не выполняет destructive stale reconciliation.
- Восстановление ранее опубликованных source-managed подборок отделено от
  обычной синхронизации. Ручная dry-run-first recovery-команда может
  классифицировать и повторно опубликовать только заранее проверенный exact
  allowlist `provider + source_key → stable category slug`: источник должен
  оставаться доступным, состав — непустым и не превышать public cap, а
  назначенная категория и её родитель — активными. Команда не принимает
  произвольные ID, URL, названия или category values, не публикует
  демонстрационные, пустые, конфликтно классифицированные или не
  включённые в allowlist записи и после изменения обязана пересчитать
  version-aware quality. Production write требует проверенного backup,
  остановленных writers и отсутствия активного импорта, source sync и
  незавершённой recommendation build. Это явное редакционное восстановление
  не ослабляет правило `private/archived` для новых sync rows и не превращает
  category suggestions в автоматическую публикацию.
- Изображения remote-подборок не запрашиваются, не скачиваются и не сохраняются. Полные видео, постеры тайтлов, HTML snapshots и произвольные remote assets также не сохраняются.
- Audit состоит из агрегированного run, source record и source-item match rows. Public/admin presentation показывает только allowlisted counters/status и не раскрывает source URL, raw error, filesystem path или raw match evidence. Приватная admin-сводка дополнительно считает source-managed коллекции без membership, отделяет пустые коллекции с поддерживаемым или неизвестным типом от коллекций только с неподдерживаемыми типами, показывает `supported|unsupported|unknown` item counts, долю `matched/items` и только известные пары `match_status:match_method` последнего run; неизвестный type/method code не передаётся в представление как raw value. Public card является text-only и может показывать отметку «Обновляется автоматически» без runtime-запроса к источнику.
- Успешное material reconciliation обновляет `editorial_collection:*` recommendation signals только для source collection, уже прошедших канонический public-quality scope. Добавленные и удалённые связи таких подборок помечают affected IDs dirty и ставят единственную deduplicated Redis job пересборки; private review rows не инициируют бессмысленный rebuild. Recommendation scorer учитывает не более трёх общих подборок и ограничивает вклад; после активации поколения запускается существующий bounded warm критических Redis/Memcached-backed страниц.

### Query, Livewire, locale и discovery

- `CatalogCollectionQuery` является единым read boundary directory/profile/manageable membership/contents/filter options/related/admin/sitemap. Public directory сначала применяет канонический active-category/non-empty/maximum-500/source-safe scope и пагинирует IDs по индексированному category/public/order shape, затем одной bounded summary-выборкой загружает только текущую страницу с owner, category translations и grouped counts. Items переиспользуют `CatalogTitleQuery`, entitlement, taxonomy card loads и `CatalogUserCardStateLoader`; pagination детерминирована и не загружает весь список.
- Внутри collection применяются title/original/alias search, существующие genre/country/status/year relations, allowlisted sort и URL-backed Livewire state. В public discovery используются изолированные keys `collections_q`, `collections_sort`, `collections_category`, `collections_subcategory`, `collectionsPage`; detail/profile сохраняют `collectionPage`/`profileCollectionsPage`, public API — conventional `page`. Search/sort/category/page variants получают clean discovery canonical и `noindex`.
- Locked public UUID/ID properties, prepared DTO/scalars, policy resolution on every action and small paginator state предотвращают mass model graph serialization. Loading, status/error, empty, confirm, report dialog, share and up/down states находятся в component/Blade; focus/dialog/share lifecycle — в Vite module `resources/js/collections.js`, без Volt, inline CSS или Blade business JavaScript.
- Unprefixed `/collections/{slug}` — canonical для user-created content и default editorial locale. `/{locale}/collections...` меняет interface chrome. User aliases noindex и не заявляют `hreflang`; существующая editorial translation row может сделать non-default locale self-canonical и участвовать в reciprocal alternates. Текущий dashboard/editor создаёт и изменяет только `ru` row независимо от locale интерфейса, не показывает языковой control и не удаляет прежние English rows.
- Public approved collections участвуют в общей секции `#collections` каждого поддерживаемого `/discover/{type}`, public owner profile, title discovery, collection-to-collection recommendations и title-page search suggestions. Collection explorer сохраняет собственные URL state и paginator и не меняет ranking текущего recommendation mode. Related query исключает current record и blocked owners существующим relationship domain и сопоставляет только guest-visible shared titles, поэтому hidden/deleted membership не влияет на discovery. Private/unlisted/pending/rejected/hidden/deleted rows не попадают в discovery.
- Public SEO presenter формирует escaped title/description, clean canonical, safe owner display, breadcrumbs и bounded `CollectionPage` JSON-LD без image field только для approved non-empty public pages. Private/unlisted и UI-state variants noindex; sitemap stream содержит только approved public non-deleted records с хотя бы одним guest-visible title и не содержит collection image nodes. User content locale aliases не выдают ложный `hreflang`.

## Канонический домен обсуждений

### Identity, targets и единая write boundary

- `Comment` является единственной моделью для top-level комментария и reply; provider `CatalogTitleReview` остаётся независимым review-доменом. Stable identity — `comments.id`, не body/hash, locale, slug, author name, sort/page или position. Anchor — `#comment-{id}`.
- `CommentTargetType` допускает только `title`, `season`, `episode`, `collection`. `CommentTargetResolver` повторно проверяет положительный ID, allowlist из `config/comments.php`, publication/audience/window и hierarchy ownership через существующие catalog/collection boundaries. Request никогда не передаёт PHP class или morph alias.
- `CreateComment`/`CreateReply`, `UpdateComment`, `DeleteComment`, `RestoreComment`, `SetCommentReaction`, `ReportComment`, moderation/restriction и block/mute actions — канонические mutation boundaries. Они получают authenticated actor из server context, повторяют policy/target checks, используют explicit fields и короткие transactions/optimistic version/row locks там, где есть race.
- Один `CommentPolicy` владеет view/create/reply/update/delete/restore/react/report/moderate. Запись требует verified email; moderator gate `manage-comments` переиспользует существующий configured administrator allowlist, но comment restriction не является account ban.
- Title merge до force-delete переносит title/season/episode target identity и `catalog_title_id`, сохраняя comment/reply/reaction/report IDs и timestamps. Permanent collection deletion не удаляет discussion evidence: `CommentTargetLifecycleService` privacy-retires rows; soft-deleted/restored collection сохраняет исходную ветку.
- `CommentSchema::available()` является fail-closed capability check полного canonical comment row, а engagement/relationship/notification capabilities отдельно проверяют требуемые tables/columns. `writable()` дополнительно требует весь набор и `COMMENTS_ENABLED=true`. Поэтому feature flag отключает UI/mutations, но никогда не отключает account export/deletion, target merge или collection privacy retirement для уже сохранённых rows.

### Replies, text, visibility и lifecycle

- `parent_id` у любого reply всегда указывает на top-level root; `reply_to_id` хранит непосредственный логический контекст. Parent и reply обязаны иметь одинаковые target type/ID. Новое сообщение нельзя перепривязать, поэтому self/descendant cycles и структурная глубина больше одного уровня невозможны; глубокий разговор визуально flatten-ится с безопасной подписью автора, которому отвечают.
- Body — NFKC-normalized Unicode plain text через `CommentBody`/`UserPlainText`. Сохраняются значимые переносы; HTML/script/style/control/bidi удаляются, Blade выводит только escaped `{{ }}`. Markdown, rich HTML, provider HTML, iframes, automatic links и JavaScript/data/vbscript URLs не исполняются.
- Server validation отклоняет пустой результат, более 5 000 Unicode-символов, более 40 строк, более двух URL-like tokens, более пяти `@`-tokens, опасную схему и чрезмерный повтор символа. `@text` остаётся обычным исходным текстом: username mention parser и mention notifications намеренно отсутствуют.
- New verified users публикуют сразу; только bounded new-account-plus-link signal даёт `pending`. Тот же decision повторяется при edit, поэтому link-free publish нельзя использовать для обхода pre-moderation последующей правкой. Stable status codes: `published`, `pending`, `hidden`, `rejected`, `spam`, `removed`. Owner видит собственные pending/hidden/rejected/spam body и replies через private overlay; removed/deleted rows остаются tombstone/unavailable, а public query/count/cache не включает ни одно owner-only состояние. Moderator notes/reasons никогда не входят в public DTO.
- Whole-body `is_spoiler` является единственной spoiler model. До явного server action body отсутствует в prepared DTO/HTML, screen-reader tree, profile preview, notification, SEO и structured data. Long body аналогично отдаёт только Unicode-safe excerpt до server show-more; hide удаляет полный body из следующего payload.
- Owner edit доступен 30 минут и использует `version` compare-and-swap; identity, author, target, parent и `created_at` неизменны, `edited_at` задаётся отдельно. User mutations повторно блокируют user, allowlisted visible target и comment в одном порядке, поэтому concurrent target retirement/merge или account deletion не принимает late write. Edit history не добавлена. Owner delete — soft delete с tombstone и сохранением replies/reactions/reports; owner restore доступен 7 дней только для author deletion и никогда не отменяет status `removed`. Moderator removal/privacy retirement имеют отдельные stable reasons.

### Reads, reactions, privacy и integrations

- `CommentDiscussionQuery` выполняет deterministic top-level pagination по 15 строк и allowlisted `newest|oldest|popular`; replies всегда chronological, начальный batch — 20 и расширяется по требованию до server-owned ceiling 200. Достижение ceiling оставляет ветку стабильной и показывает локализованное состояние вместо неограниченного Livewire payload. Author/reply-to authors, reaction totals и reply counts eager/grouped; current-user reaction и block/mute sets загружаются отдельными bounded queries, не на каждый item.
- Public count означает все `published`, non-deleted comments, включая replies. Thread public count означает опубликованные non-deleted replies; собственные pending/hidden/rejected/spam replies добавляются только к private owner overlay, а removed/deleted строки остаются недоступными. Up/down totals и score derived, denormalized aggregate columns не существуют.
- `comment_reactions` допускает одну `up|down` строку на user/comment. Change/remove идемпотентны, self-reaction запрещена, deleted/non-public/blocked interaction закрыта policy/service. Current reaction не входит в shared cache.
- Directional block скрывает обе стороны друг от друга и запрещает reply/reaction/notification; private mute скрывает muted author только текущему viewer и подавляет его notifications, не запрещая остальные действия. Neutral unavailable/tombstone сохраняет thread structure, не сообщает публично причину скрытия и не раскрывает author/relationship controls, когда body недоступен этому viewer. Собственные private block/mute management lists используют отдельные bounded paginator'ы и никогда не входят в public cache.
- Database notifications покрывают reply, reaction, moderation и report resolution, проверяют preference, self-event, block/mute и deterministic UUID deduplication. Payload хранит только stable IDs/type/status, не body/excerpt/actor identity. `/profile/discussions` объединяет private self activity, inbox/preferences и relationship management. Добавленный Task 14 public-profile comments tab доступен только через явную section privacy; его published catalog-rooted rows/count делегированы `CommentProfileQuery`, который повторно применяет exact title/season/episode availability и viewer block/mute context, а spoiler-safe DTO/title/direct URL — `CommentPresenter`. Поэтому профиль не является второй comment visibility/presentation boundary и не раскрывает комментарий после ограничения его точной цели.
- Reports используют allowlisted category, plain optional detail до 2 000 символов, rate limit и unique unresolved deduplication key. `/admin/comments` даёт paginated queue/filter/context, approve/hide/reject/spam/remove/restore, private notes, report resolution и temporary/permanent restriction. Все изменения audit-ятся безопасными fingerprints; удалённая/скрытая цель остаётся доступна через private admin context, а public URL продолжает отвечать безопасно.
- Restrictions вычисляют active state при каждом permission check: `expires_at` прекращает действие без cron. Anti-spam объединяет 90-секундное сравнение нормализованного без учёта регистра body для того же user/target/root, UUID submission token, exact user/target и более мягкие user-global rate-limit buckets, text/link limits и bounded create/edit review signal; rotation целей и edit-after-publish не обходят лимит, а один слабый сигнал не создаёт permanent ban.
- Target page содержит один `CommentDiscussion` с locked IDs/locale и prepared DTO, не Eloquent graph. URL-backed scope/sort/page/thread/focus поддерживают browser navigation; scope drafts живут только в текущем Livewire state и очищаются после success. Persistent local/public drafts, premium emoji/stickers и automatic translation отсутствуют.
- Direct `/comments/{id}` и `/{locale}/comments/{id}` проходят через единый `CommentDirectLinkResolver`: он загружает stable row, повторно применяет `view` policy и moderator gate, разрешает allowlisted target, structural root и oldest page и собирает только trusted internal URL. Localized closure принимает оба route scalar в объявленном порядке, поэтому `locale` не может подменить comment ID. `CatalogTitleDetail` сохраняет положительный focused ID в locked state и отключает lazy discussion только для direct request: anchor присутствует уже в initial HTML, а обычные title pages остаются lazy. Авторизованный focused reply безопасно поднимает structural root даже когда тот скрыт и не имеет других public replies; произвольный query ID сначала проходит `view` policy. Hidden/inaccessible content не раскрывается и не получает dead public link в prepared tombstone; moderator получает private admin context. Redirect и query-state page имеют private/noindex либо `noindex,follow` с чистым target canonical; comments отсутствуют в sitemap, feed, search documents и JSON-LD.
- Account export включает только собственные comments/reactions и public-safe target identity; password-confirmed response имеет private/no-store headers. Общий JSON snapshot materialize-ится существующим account exporter до streamed response, поэтому incremental large-account streaming остаётся известным cross-domain ограничением. Account deletion перед user hard-delete обнуляет comment authors/reporters, user-derived submission keys и report deduplication keys, удаляет reactions/preferences/restrictions/blocks/mutes и собственные body-free notifications; body/thread/report/moderation evidence сохраняются без прежнего profile linkage.
- `lang/{ru,en}/comments.php` имеют exact key/placeholder parity. Любое изменение видимого текста требует двуязычной редакторской проверки естественности, терминологии, plural forms и доступных aria-label; автоматическая parity-проверка подтверждает структуру, но не качество перевода.

DB tables, indexes, rollback и count definitions принадлежат [`DATA_RELATIONS.md`](DATA_RELATIONS.md); cache boundary — [`caching.md`](caching.md); authorization/security/notifications/admin/UI — соответствующим тематическим документам. Полный dated audit, known limitations и acceptance checklist находятся только в Task 12 [`plans/laravel-video-portal-modernization.md`](plans/laravel-video-portal-modernization.md).

## Канонический домен отзывов

### Identity, target и граница с обсуждениями

- `CatalogTitleReview` остаётся единственной review aggregate. Existing numeric `catalog_title_reviews.id` — стабильная публичная identity, не зависящая от body/hash, title slug, locale, sort или page; `CatalogTitleReviewAlias` сохраняет legacy ID после merge. `ReviewOrigin` разделяет существующие `provider` rows и новые `user` rows без второй review table.
- Review target — только прямой `catalog_title_id`. `ReviewTargetType::Title`, `ReviewTargetResolver` и существующая visibility query не принимают morph class из браузера и запрещают reviews для скрытого/удалённого/недоступного тайтла. Сезон/серия остаются comment scopes; интерфейс явно подписывает отзыв как мнение о сериале целиком.
- Comment — разговорная threaded запись с replies/reactions и несколькими scope. Review — non-threaded структурированное мнение с required title/body, optional canonical score, spoiler, one-current-review ownership, helpfulness и editorial moderation. Одна отправка создаёт только одну review row и никогда не копирует текст в comments.
- User title/body сохраняются на исходном языке как escaped Unicode plain text. `ReviewTitle`, `ReviewBody` и `UserPlainText` нормализуют NFKC/line endings, отвергают control/bidi, unsafe schemes, excessive links/lines/repetition и не исполняют HTML, Markdown, Blade, iframe или automatic rich preview. Provider rows и их историческая длина не переписываются.

### Lifecycle, rating, verification и engagement

- `Create/Update/Delete/RestoreCatalogTitleReview` являются единственной write boundary. Authenticated actor всегда берётся server-side; verified email обязателен для create/vote/report, а owner edit/delete/restore повторно проходят собственные policy/restriction/target rules. Target/author/status/verification нельзя mass-assign. UUID submission key и nullable deterministic ownership key дают retry-safe один текущий user review на title. Soft-deleted row удерживает slot 30 дней; поздняя create транзакционно архивирует прежний slot, сохраняя старый ID/body/timestamps/moderation evidence.
- Optional review score не хранится в review. Action переиспользует единственную 1–10 integer запись `catalog_title_user_states`; review без score и score без review допустимы, edit может изменить/очистить score, delete/restore score не удаляют. Provider/external ratings остаются отдельными и не смешиваются с portal average.
- `VerifiedWatchingService` принимает только persisted episode completion либо meaningful authorized progress для того же title и сохраняет non-downgrading boolean snapshot. Client не передаёт trusted flag; page visit/bookmark не подтверждают просмотр, exact seconds/episode/device/translation не публикуются, а последующее удаление history не переписывает исторически истинную отметку.
- Whole-review spoiler title and body до `reveal` отсутствуют в prepared DTO/HTML, profile excerpt, notification, metadata, search и schema. Reveal/hide — server actions. Edit использует optimistic `version`, сохраняет identity/author/target/created date/votes/reports и задаёт `edited_at`; owner delete soft, restore — 30 дней; moderator removal сохраняет evidence. Edit-history table осознанно не добавлена.
- Legacy provider rows have no trustworthy spoiler metadata. Migration keeps their prior meaning with `is_spoiler=false` rather than inferring a label from text; users may report an unmarked spoiler and moderators can set the canonical flag. This is a documented legacy limitation, while user-authored rows must choose the flag explicitly.
- `catalog_title_review_votes` допускает одну `helpful|not_helpful` row на user/review. Atomic change/remove идемпотентны; self-vote, deleted/non-public review и block conflict запрещены. Public totals и score `helpful - not_helpful` derived SQL; current-user vote загружается отдельным viewer overlay.

### Reads, moderation, privacy и integrations

- `CatalogTitleReviewQuery` выполняет deterministic pagination и allowlisted `newest|oldest|most_helpful|highest_rated|lowest_rated`, exact rating/spoiler/verified filters, eager author/title, joined canonical rating и grouped vote totals. Missing rating не становится нулём и сортируется после rated rows. Public count включает published non-deleted provider+user rows; review average включает только опубликованные user reviews с non-null portal score.
- `ReviewPresenter` готовит immutable DTO и полностью удаляет title/body из unrevealed spoiler. Public query вообще исключает hidden/deleted rows; owner может видеть собственное moderation state, но обычный profile/title Blade подавляет deleted body, а сохранённый evidence доступен только gated moderation context. Direct `/reviews/{id}` и локализованный compatibility route `/{locale}/reviews/{id}` разрешают alias, повторно авторизуют review+target, вычисляют страницу и redirect-ят на canonical title query/anchor; inaccessible content не раскрывается. `/profile/reviews` остаётся private self history. Добавленный Task 14 public-profile review tab доступен только через явную section privacy и делегирует rows/count в `CatalogTitleReviewQuery`/`ReviewPresenter`; один общий author builder также применяет bounded block/mute context к списку и счётчику, поэтому mute не оставляет отдельный профильный канал утечки. Отдельного author-review домена или второй visibility/spoiler projection нет.
- Reports имеют stable categories, unresolved deduplication и private reporter/moderator data. `/admin/reviews` через `manage-reviews` поддерживает paginated queue, filters, moderation, report resolution и temporary/permanent review-only restriction; expiry проверяется на каждом action без cron. Generic directional blocks/private mutes и database notifications переиспользуются, но comment restrictions/reactions не смешиваются.
- `status=removed` является не только moderation label: canonical moderator action атомарно сохраняет `deletion_reason=moderator`, исходного deletion actor и `deleted_at`. Переход из `removed` восстанавливает только moderator tombstone и никогда не отменяет author deletion или merge evidence. Идемпотентная convergence migration исправляет прежние incomplete removed rows без изменения stable ID, текста, голосов, жалоб или ownership.
- Notification payload содержит только stable `kind`, review ID, optional vote/report ID и moderation status, никогда target title, body/excerpt или actor/reporter data. Target повторно разрешается при presentation. Deterministic UUID/preferences/self checks suppress duplicates; generic block/mute applies to the social helpful notice, while official moderation/report outcomes obey their dedicated preferences. Delivery failure is best-effort and cannot roll back an already committed mutation. Account export включает только собственный public-safe review/vote contract; deletion anonymizes ownership/reporter linkage и удаляет private engagement/preferences/restrictions/notifications, сохраняя опубликованный text и moderation evidence без прежнего profile link.
- Cached guest title shell содержит только lazy placeholder `CatalogTitleReviews`; public review rows/aggregates загружаются отдельным `X-Livewire` request, который shared full-response cache всегда обходит. Vote, permissions, blocks/mutes, pending owner visibility, restrictions, reports и moderation controls вычисляются только request-specific. Mutations after commit bump affected title; provider moderation/title merge также bump API/recommendation versions. No global cache flush, review HTML cache or second cache exists.
- Search не индексирует review text; recommendation v4 получает только bounded public review count во время существующего full importer rebuild. Review mutation invalidates the read namespace but deliberately does not launch an expensive full-catalog rebuild or new queue; stored ordering catches up on the next scheduled/explicit importer rebuild. Review text, author identity and personal rating never become recommendation explanations. Review/direct/filter/page URLs отсутствуют в sitemap и canonicalize/noindex к title. User reviews/spoilers не входят в JSON-LD; существующий provider aggregate schema остаётся отдельным источником до отдельной validated editorial strategy.
- `ReviewMergeService` выполняется внутри title merge: перемещает reviews/votes/reports, выбирает canonical duplicate детерминированно, monotonic-объединяет truthful verified snapshot и spoiler safety, архивирует collision row вместо hard delete, сохраняет original hash/identity/timestamps/status и alias. Deleted/inaccessible target скрывает review публично, но не уничтожает restoration/moderation data.

Schema/index/rollback и aggregate definitions принадлежат [`DATA_RELATIONS.md`](DATA_RELATIONS.md); security, authorization, cache, notifications, admin и UI уточняются в тематических документах. Полный исходный аудит, migration risks, known limitations и ручной acceptance checklist находятся только в Task 13 [`plans/laravel-video-portal-modernization.md`](plans/laravel-video-portal-modernization.md).

## Канонический домен тегов

### Identity, типы и переводы

- Глобальная классификация продолжает использовать единственные `Tag` и `catalog_title_tag`. Внутренний FK `tags.id` сохраняется, `public_id` служит непрозрачной внешней identity, а mutable `name`, localized label, slug и alias не являются identity. У `system`/`editorial` может быть уникальный неизменяемый `code`; normal user его не задаёт.
- Allowlisted global types: `system`, `editorial`, `imported`, `hidden_internal`; visibility: `public|internal`; moderation: `pending|approved|rejected|hidden|merged|archived`. Это машинные enum values, не переведённые подписи. Public-user type намеренно отсутствует: продукт не поддерживает публичные пользовательские теги и их reporting/appeal lifecycle.
- `TagNormalizationService` единолично готовит safe display text и comparison identity: Unicode NFC/NFKC, whitespace/control/invisible cleanup, dash/punctuation normalization, leading hashtag removal и case folding без удаления диакритики. Exact normalized hash задаёт duplicate boundary; fuzzy similarity ничего не объединяет автоматически.
- `tag_translations` хранит по одной `ru`/`en` записи label, plain-text descriptions и SEO fields. `TagQuery` выбирает только active/fallback locale; correlated subqueries и unique `(tag_id, locale)` не размножают теги или title rows. User-created label не переводится, не попадает в PHP locale files и сохраняет исходный script/case.
- Approved locale-aware `TagAlias` — точное альтернативное имя одного canonical tag; optional alias slug только redirect-ит. Создание проверяет normalized alias против canonical names и aliases всех locale, а resolver fail-closed отклоняет legacy ambiguity нескольких target вместо случайного выбора. `TagSlug` хранит прежние current slugs. `TagSynonym` — явная directional/bidirectional one-hop связь с bounded expansion; она не является alias, merge или hierarchy inheritance. Циклическая recursive expansion отсутствует.

### Global и personal write boundaries

- `TagService` владеет authorized create/update/translation/alias/synonym/archive/restore/merge/provider moderation; `TagAssignmentService` — global title assignment и provenance; `TagResolver` — current/history/alias/merge URL; `TagQuery` — public/personal reads; `TagCacheInvalidator` — versions. Контроллеры и Livewire только принимают нормализованное состояние и вызывают эти границы.
- Глобальный merge выполняется в transaction, выбирает явный canonical target, дедуплицирует pivots, переносит assignment provenance/provider mappings/slugs/aliases/synonyms и заполняет отсутствующие translated fields. `TagMergeEvent` сохраняет source→target и impact; source остаётся internal merged record для legacy resolution. Exact legacy duplicates согласуются миграцией детерминированно, fuzzy pairs требуют редакционного решения.
- `UserTag` — отдельный owner-scoped private aggregate со stable UUID, original label, normalized hash, optional explicitly known `content_locale` и optimistic `content_version`. Web authoring не показывает language control и сохраняет новый UGC с `null`, не подменяя язык выбранной locale интерфейса; API может явно передать allowlisted `ru|en`, а edit сохраняет предыдущее значение, если поле отсутствует. `PersonalTagService` всегда получает owner из authenticated context, ограничивает create/batch size, обеспечивает owner-local uniqueness, idempotent assignment/remove, soft delete и 30-дневное restore. Другой пользователь не может передать owner ID, прочитать label/count или назначить tag.
- Batch selector хранит в Livewire только UUID draft, Apply transactionally reconciles owner tags, Cancel восстанавливает persisted set. Global и personal assignment не изменяют watchlist, rating, progress, history, collection membership или title metadata. Title merge отдельно переносит оба вида assignments без duplicate pivots и уплотняет порядок личных тегов каждого затронутого владельца по прежней `(position, tag ID)` последовательности.
- Постоянное global delete отсутствует: используйте archive или merge. Archive запоминает прежние visibility/moderation values и restore возвращает их, включая internal/rejected state, а не публикует запись по умолчанию. Personal force delete выполняется только после bounded restoration window; account deletion cascade и owner export обработаны существующим account service.

### Public reads, privacy, cache, import и SEO

- Canonical public URL — `/titles/tag/{slug}`. `/tags/{slug}` и `/tag/{slug}` — compatibility redirects. Middleware сначала проверяет approved/public/non-internal/non-archived canonical target и наличие visible title, затем canonicalizes case/history/alias/merged input с сохранением безопасного query string; недоступный/пустой tag возвращает неразглашающий 404. Главная разрешает ссылку «с субтитрами» по immutable `code=subtitle-available`, а URL готовит route helper, поэтому переименование label/slug не оставляет dead link.
- Public tag page переиспользует `CatalogSeries`, `CatalogTitlesPageBuilder`, allowlisted filters/sorts, `CatalogTitleQuery::visibleTo(null)`, deterministic pagination и canonical cards. Locked route tag/year остаётся отдельно от URL-bound form values, поэтому canonical route не повторяет собственный slug в query; route checkbox имеет явное remove-действие, а дополнительные filters сохраняют browser history. Popularity — число distinct visible public title assignments; related tags сначала учитывают explicit editorial relations, затем bounded distinct co-occurrence. Private assignments не участвуют ни в одной формуле.
- Public search/suggestions ищут canonical/active-fallback translation/approved alias fields, de-duplicate по canonical ID и расширяют synonyms максимум на один bounded hop. Personal search существует только внутри owner library. Private/internal/pending/rejected/hidden/archived tags не входят в public FTS/suggestions/directory/filter/recommendations.
- Shared snapshots dimensioned stable tag UUID + locale/fallback + bounded input + public version + explicit projection version; compact popular/related API summary сохраняет и optional stable `code`. Guest full-page cache допускает только exact canonical scheme/authority из `app.url`, включает origin dimension и отвечает `BYPASS` на другой host/port, исключая cache poisoning абсолютными asset/canonical URL. Personal reads и API responses — owner-scoped и `private, no-store`; private labels/selections никогда не записываются в shared HTML/cache. Mutations bump only tag/catalog/search/sitemap/title/recommendation dependencies после commit, без application-wide flush.
- `TagImportSynchronizer` связывает normalized provider identity с `tag_provider_mappings`, сохраняет raw source label/allowlisted Seasonvar URL отдельно от canonical display, помечает новые mappings pending и записывает per-title provenance. Complete successful snapshots mark stale observations non-current and detach pivot только при отсутствии другого current/editorial source; rejected/hidden/archive и editorial corrections не перезаписываются retry.
- `TagSeoPresenter` использует real localized label/description/public count, canonical, breadcrumbs и public-safe existing CollectionPage/ItemList schemas. Alias/history pages только redirect; empty/private/unapproved/internal/archive/filter/search variants исключены или noindex. Sitemap включает только non-empty eligible canonical tags. Locale-prefixed tag routes отсутствуют, поэтому `hreflang`/machine-translated user content намеренно не объявляются.
- Нет season/episode assignment, tag hierarchy, public user tag/report, hashtag-to-catalog creation или mandatory queue. Эти отсутствующие product contracts не имитируются polymorphic relations, fake controls или dead routes.

## Канонический домен профилей пользователей

- `users.id` остаётся внутренним FK, `users.public_id` — стабильной непрозрачной identity, `users.name` — изменяемым display name. `UserProfile.username` является только уникальным lowercase ASCII route alias; текущий и исторические aliases разрешает `UserProfileResolver`, старый/case-вариант перенаправляется на `/users/{username}` без создания второго user.
- Публичный read side состоит из `PublicUserProfilePresenter`, allowlisted `PublicUserProfileData` и `PublicUserProfileQuery`. Он не загружает email/security/provider/progress/history/private collection fields, применяет profile+section privacy, target publication и bilateral block policy, а selected Livewire tab загружает только один bounded paginator.
- Owner writes остаются на существующем `/profile`: `UserProfileService` владеет biography/privacy/username, `UserProfileMediaService` — private-disk avatar/cover, а Task 12 block/mute actions не дублируются. Profile image processor проверяет MIME/bytes/pixels/EXIF, center-crop/resample и сохраняет только WebP `320×320` avatar или `1280×360` cover. Username требует current password, rate limit, transaction lock и history; biography — escaped plain text без HTML/links.
- Owner `/profile`, `/profile/discussions`, `/profile/reviews`, `/library*` и `/library/tags/manage` используют только `UserPortalCache` для bounded ID/count projections. Livewire/HTML, security sessions/devices/password, notification action state и Eloquent graphs не кэшируются. Каждая cached row повторно гидратируется current owner/visibility query, а mutation after commit повышает user scope и coalesces фоновой прогрев.
- `UserProfilePolicy` разделяет public, owner и moderator contexts. Public active profile и каждая section должны быть явно public; detailed history/exact progress/blacklist/personal tags/account settings никогда не имеют public DTO/control. New accounts private; backfill preserves only the pre-existing public collection-owner presentation and leaves behavioral sections private.
- `UserProfileReportService` и `/admin/profiles` используют stable categories/status, verified reporter, deduplication/rate limits и private moderator notes. Публичные active-профили участвуют только в существующем `PortalSearchSuggestionQuery` по username/display name; email, biography и private/moderated profiles не ищутся. Отдельных profile directory/index, role/badge/rank/follow/activity architectures нет, и отсутствующие product models не имитируются UI.
- Public profile HTML не кэшируется вторым слоем; content/media versions дают targeted invalidation and immutable media URLs. Public active non-empty overview may enter profile sitemap/`ProfilePage` JSON-LD; localized/tab/query/private/moderated variants are noindex or inaccessible.
- `UserProfileSchema` проверяет полный набор используемых columns, а не только имена таблиц. До завершения additive rollout profile search и sitemap возвращают пустой public result, а owner profile и account mutations fail closed. Migration-before-code обязателен: регистрация не должна обходить профильный backfill, а удаление аккаунта — пропускать очистку private media.

Полный аудит, schema/rollback, unsupported boundaries и acceptance находятся только в Task 14 [`plans/laravel-video-portal-modernization.md`](plans/laravel-video-portal-modernization.md).

## Канонические настройки аккаунта

- Единственная settings shell — `AccountSettingsPage` на owner-only `/settings/{section?}` и `/{locale}/settings/{section?}`. Стабильные section codes перечислены `AccountSettingsSection`; ссылки `/profile`, `/profile/security`, `/profile/discussions`, `/profile/reviews`, `/notifications`, `/library`, `/my/collections` сохраняются как канонические специализированные страницы и не дублируются внутри настроек.
- `AccountSettingsService` является typed write/read boundary для appearance, playback, collection default, reset и idempotent anonymous merge. Он принимает только allowlisted DTO/value-object значения, повторно применяет Gate и transaction lock, увеличивает `settings_version` и никогда не mass-assign-ит произвольный ключ, JSON path или поле пользователя. `AccountSettingsSchema` даёт read-default/write-503 поведение во время rolling migration.
- Translation preferences расширяют тот же playback boundary: scalar
  favorite/fallback/mode/subtitle-language/notification и нормализованный
  hidden set записываются одной retryable transaction под user lock.
  Favorite/fallback — разные реальные voiceover keys; hidden set ограничен,
  уникален и не может включать их. Режим original+subtitles или явный язык
  субтитров также включает `subtitles_enabled`. Reset удаляет только эти
  preferences, а export/delete сохраняют portability/cascade semantics.
- Профиль продолжает использовать существующий `ProfilePage`/`AccountService`, расширенный каноническими Task 14 `UserProfileService` и `UserProfileMediaService`; settings shell лишь ссылается на него и не дублирует username/avatar/cover/biography writes. Comments/reviews notification actions и delivery services остаются источником истины; player продолжает использовать один `CatalogTitlePlayer` и один `player.js`; session/export/delete используют существующие auth services. OAuth providers, premium, email/push notification channels, media-language tracks, дополнительные player styles и continue-watching modes отсутствуют и не представлены фиктивными controls.
- `AccountSettingsData` разрешает nullable explicit database fields поверх defaults. URL locale имеет request-level приоритет, authenticated database preference синхронизируется между устройствами, versioned local storage применяется только к безопасному immediate/device state, затем используется config default. Anonymous values после входа валидируются и заполняют только ещё не выбранные account fields; завершённый merge помечается opaque HMAC account scope.
- Interface locale (`ru|en`) отделён от media identity. Timezone хранится как IANA identifier и применяется одним `AccountDateTimeFormatter`; browser timezone — только явное предложение. Preferred quality/variant являются stable codes реальных доступных media rows, сохраняются при временной недоступности и не содержат URL.
- Приватность истории, точного прогресса, личной библиотеки и personal tags остаётся enforced private. Collection default влияет только на следующие создаваемые коллекции. Comment/review notification matrix сохраняет существующие category booleans и реально enforced database channel; critical account mail не выдаётся за отключаемую preference.
- Settings HTML и все auth responses имеют `private, no-store` и `noindex,nofollow`; пользовательский ID, email, session ID, provider token или private setting не входят в URL/cache/metadata. Public settings cache намеренно отсутствует, а versioned local device state привязан к opaque account scope.

Полный route/storage/risk/compatibility audit, feature gaps, rollout и manual acceptance checklist принадлежат Task 16 [`plans/laravel-video-portal-modernization.md`](plans/laravel-video-portal-modernization.md); security/authorization/cache/frontend details остаются в соответствующих тематических документах.

## API Resources

- Публичные JSON-ответы используют ресурсы в `app/Http/Resources`, а не массивы в контроллерах.
- Ресурсы не раскрывают source URL, HTML-снимки, внутреннее состояние импортера, raw media URLs, ключи медиа или stack traces.
- Связи и счетчики в ресурсах добавляются только через `whenLoaded()` и `whenCounted()`; query-сервисы заранее загружают нужные отношения.

## Канонический домен заявок на материалы

`ContentRequest` — единственная aggregate для отсутствующих сериалов, сезонов, серий, переводов, субтитров, улучшения качества, административных исправлений метаданных/списка серий и восстановления недоступного материала. Comments, reviews, reports и importer source pages не являются параллельными заявками. До Task 19 request/ticket/suggestion routes и data отсутствовали, поэтому additive домен не мигрирует и не удаляет legacy rows.

- Stable public identity — opaque UUID `public_id`; внутренний numeric ID, название, locale, requester, status, votes и priority не входят в URL identity. `ContentRequestIdentity` предпочитает canonical title/season/episode ID, а для отсутствующего сериала — allowlisted external ID либо normalized original title+year. `active_identity_key` запрещает exact active duplicate; fuzzy/alias similarity лишь показывает bounded candidates.
- Один typed input/action выполняет server-side normalization, type rules, content-existence check, duplicate check, verified-account policy, per-action/network rate limits, idempotency token и transactional create. Requester server-side получает исходный vote/follow; client никогда не задаёт requester/status/priority/publication/moderation/import state.
- Исправление поля остаётся тем же `ContentRequest`, но типы `metadata_correction` и `episode_list_correction` являются administrative-only. Их создаёт только пользователь с `manage-content-requests`; `CatalogCorrectionTargetResolver` повторно разрешает тайтл и принадлежность relation/episode, `correction_target_key` входит в active identity, а backed-enum `correction_reason` обязателен только для тега. Action принудительно сохраняет такие строки private, без vote/follow и sitemap/cache side effects. Public query, route binding, SEO, presenter и notifications независимо исключают административный тип, поэтому даже прежняя строка с ошибочным `is_public = 1` остаётся fail-closed.
- `ContentRequestStatus` владеет transition matrix. Generic status action повторно authorizes moderator, использует optimistic `version`, связывает только реально опубликованный target нужного title/season/episode/media, пишет append-only public/private history и запускает targeted cache/notification changes. `clarification_needed`, `merged`, `duplicate` и `withdrawn` запрещены в generic boundary: вопрос, merge mapping/community migration и requester withdrawal выполняют только dedicated actions. Clarification — закрытый requester/moderator thread, не второй публичный comment domain.
- Merge допускается только для семантически совместимых type/target/season/episode/language/translation/quality/correction dimensions. Он idempotently переносит votes, followers, private evidence и external IDs, сохраняет обе истории, записывает canonical mapping, наследует public visibility и оставляет старый публичный UUID redirect-ом. Cross-requester merge запрещён, если хотя бы одна заявка private; restricted clarification переносится только при том же requester или при принятии ownership пустым canonical record, иначе остаётся у source history и не раскрывается другому requester. Title/season/episode merge importer-а использует тот же privacy-aware reconciliation boundary и при unsafe collision отмечает probable duplicate для ручной moderation вместо автоматического раскрытия.
- Read side разделён на public card/detail DTO и viewer overlays. Public directory имеет validated search/type/status/sort, deterministic pagination и grouped counts; My Requests и moderation остаются authenticated/noindex. Blade только отображает DTO/options и не вычисляет identity, priority, duplicate, transition или authorization.
- Handoff не создаёт второй importer и не исполняет команды из браузера. Moderator передаёт allowlisted Seasonvar source page либо existing-title refresh в текущий importer, а `ContentRequestImportRunLinker` сохраняет реальный run reference. Completion остаётся ручной проверяемой transition: текущая media schema не позволяет честно auto-complete language-specific subtitles/translations.

Полный аудит, rollback, known limitations и acceptance принадлежат Task 19 общего плана. Tables/indexes — в [`DATA_RELATIONS.md`](DATA_RELATIONS.md), permissions — в [`authorization.md`](authorization.md), privacy/link controls — в [`security.md`](security.md), notification/cache/import/UI details — в соответствующих owner-документах.

## Канонический домен технических обращений

`TechnicalIssue` — единственный aggregate для дефектов существующего контента и функций. Он не заменяет Task 19 `ContentRequest` и moderation reports. Один create/workflow boundary владеет random UUID/`ISS-…` identity, allowlisted type/target rules, encrypted player context, sanitized diagnostics, private raster attachments, bounded duplicate matching, participant engagement, messages/history, assignment, resolution/reopen/merge и staff-only source-health linkage. Read side разделён на viewer-scoped DTO/query для requester, limited participant и support; Blade не вычисляет policy/identity/duplicate/status/severity/priority.

Полный контракт, ограничения и acceptance находятся только в [`technical-issues.md`](technical-issues.md); schema — в [`DATA_RELATIONS.md`](DATA_RELATIONS.md), access matrix — в [`authorization.md`](authorization.md), security — в [`security.md`](security.md).

## Каноническая recommendation/discovery boundary

`CatalogRecommendationService` — единственный orchestration layer для homepage, title related/similar, discovery, library и legacy recommendation API. Он собирает server-only `CatalogRecommendationContext`, выбирает один bounded query provider, применяет canonical visibility/exclusions/availability rerank/diversity/repeat suppression и отдаёт typed result/item/explanation DTO. Controllers и Livewire только нормализуют request state и выбирают response; Blade не запрашивает модели и не рассчитывает ranking.

Для Task 94 `CatalogHomePageBuilder::webData()` оркестрирует отдельные
bounded public и authenticated homepage projections, но не вводит новый
recommendation/query domain. Guest использует `Trending` и `RecentlyAdded`;
authenticated пользователь — `Personalized` и отдельный общий `Trending`.
Продолжение просмотра делегируется `CatalogViewingActivityQuery`, обновления
библиотеки — compact read `UserLibraryQuery` поверх существующего
`CatalogPersonalUpdateQuery`. API-only `data()` и `CatalogHomeResource`
сохраняют прежний response contract.

Personal queries запускаются только при наличии server-authenticated `User`
после обхода shared public response cache. Они остаются owner-scoped,
visibility-aware и bounded; результат не сохраняется в
`CatalogHomeSnapshotCache`, `CatalogHomeMetricsCache`, public full-response
cache или warmer targets. Blade получает уже подготовленный порядок,
счётчики, labels и URLs.

Recommendation feedback остаётся в canonical one-row-per-user/title state.
`more_like_this` — явный bounded positive source для legacy и v2
personalization; `not_interested|blacklisted` — единственные отрицательные
feedback values. Поэтому положительный сигнал не попадает в hidden library,
feature demotion или release-notification suppression, но отмеченный source
title по-прежнему exact-excluded из нового результата. Карточка явно
подписывает broad reason как «Почему это показано» и объясняет последствия
всех трёх обратимых действий до записи, не раскрывая source title, private
activity или internal weight. Presentation сохраняет полный ordered evidence
list внутри server-side DTO, но общая карточка выводит только первую наиболее
значимую broad-причину; это не меняет ranking, feedback или audit payload.

`x-catalog.title-card` является единственной query-free presentation boundary
для `/titles`, главной и recommendation rows. Class component получает только
attributes, batch counts, prepared metadata и eager-loaded relations, формирует
escaped plain-text excerpt до 240 Unicode-символов и выбирает rating по
layout-contract. Blade не получает скрытое полное описание, не выполняет lazy
loading и сохраняет canonical `titles.show` через stretched title link и
отдельную доступную ссылку «Подробнее».

Интерактивные действия grid/list остаются внутри full-page
`CatalogSeries`. Компонент принимает только scalar title ID, boolean или
allowlisted enum reason, повторно получает тайтл через
`CatalogTitleQuery::visibleTo()` и передаёт запись существующим
`CatalogUserStateService`/`CatalogRecommendationFeedbackService`.
`CatalogTitlePolicy::interact` остаётся server-side boundary; уведомление и
ошибка рендерятся в том же `catalog-pagination` island, что и кнопки, поэтому
Livewire round-trip не оставляет соседний DOM устаревшим.

`CatalogDiscoveryPage` выполняет один `discover()` на interaction. При явном
обновлении новый seed и page 1 устанавливаются до запроса, а защищённый
request-local `CatalogRecommendationResult` передаётся следующему render того
же Livewire-запроса; отдельный prepared flag не позволяет повторить тяжёлое
чтение после ошибки. DTO не сериализуется в публичное состояние компонента.
Гостевой cold-start накапливает уникальные строки по цепочке
`editorial → weekly trending → monthly trending → popular` до полного
окна, причём месячный резерв сохраняет собственную локализованную причину.
`random` является одностраничной выборкой: context нормализует page к 1,
query возвращает не более `perPage`, а result всегда скрывает продолжение.

Повторный аудит 17.07.2026 подтвердил эту границу и устранил два расхождения без нового домена или схемы. Optional Sanctum viewer read-only API теперь проходит через тот же `visibleTo($user)` и `forTitle(..., $user)`, поэтому owner blacklist/`not_interested` и audience действуют так же, как в web, при неизменном гостевом контракте. `recently_added` сортируется по стабильному `catalog_titles.created_at`, поскольку технический `indexed_at` меняется при переиндексации метаданных; `recently_updated` по-прежнему строится только по событиям публикации media и выпуска episode. В текущей схеме нет отдельного title `published_at`: импорт создаёт новый тайтл уже опубликованным в одной транзакции, а поздняя публикация старого draft остаётся явно задокументированным ограничением, без выдуманного backfill.

Static sitemap проверяет непустоту `top_rated` с тем же allowlisted `recommendations.top_rated.default_source`, что и каноническая discovery page. Это не смешивает rating providers: портал, IMDb и КиноПоиск сохраняют разные queries и thresholds, но заполненная индексируемая default page больше не пропадает из sitemap из-за DTO-default другого provider. Personal/random/empty discovery остаются исключёнными.

Активное calculated similarity остаётся в существующем `catalog_title_recommendations`. Builder v6 строит bounded profiles/candidates, но полный результат сначала записывает в `catalog_recommendation_builds`/`catalog_recommendation_build_rows`; active rows заменяются одной transaction только после непустого golden quality gate. Даже явный override отсутствующей локальной разметки не разрешает активацию пустого build. До успешной активации прежний набор продолжает обслуживать запросы. Активный build сохраняется всегда, а диагностическая история завершённых shadow build'ов ограничена последними пятью (настраивается `recommendations.similarity_v6.build_history_limit`) и подчищается best-effort как после обычного завершения, так и после аварии активации; удаление старого build каскадно удаляет только принадлежащие ему shadow rows и не затрагивает active выдачу. Во время profile/scoring chunks builder обновляет `updated_at` как heartbeat; pruner переводит `building` без heartbeat дольше `recommendations.similarity_v6.stale_build_minutes` в `failed`. `catalog_recommendation_dirty_titles` хранит только уникальный ID изменённого тайтла, bounded reason и время: scoped rebuild расширяет соседей тем же candidate index, сравнивает канонический payload и не переписывает неизменные строки. Builder/evaluator учитывают только реально воспроизводимое media, а activator повторно проверяет весь row set внутри transaction и откатывает замену при изменении visibility/availability. Scoped rebuild остаётся частью основной импортной границы, но version mismatch или превышение dirty/source limit не разрешают queued finalizer запускать catalog-wide full build внутри ограниченного 900-секундного job: он возвращает `deferred`, сохраняет dirty rows и завершает run. Полный shadow fallback выполняется контролируемым synchronous maintenance-запуском той же команды `seasonvar:import`, где нет бесконечной повторной доставки queue job; прежняя active выдача всё это время остаётся доступной. Перед рекомендациями queued finalizer сохраняет versioned checkpoint завершённых maintenance/media/cleanup/merge стадий, поэтому retry не повторяет уже конечную работу. При любом активном Seasonvar run collection-sync job сохраняет dirty rows импортному pipeline и не запускает cache warm, а вне импорта также возвращает `deferred` для слишком широкого или несовместимого набора.

Generic taxonomy/rating/year/page-quality данные читаются прямо из канонических таблиц и больше не копируются в `catalog_title_recommendation_signals`. Эта таблица сохраняется только для проверенных `provider_recommendation`/`related_title` с provenance; legacy `seasonvar_info` rows удаляются bounded chunks только после подтверждённой v6 activation. Explicit directional/editorial/provider relations по-прежнему живут отдельно в `catalog_title_relations`; approved featured collections остаются единственной editorial-section architecture. `CatalogTitleQuery::visibleTo()` и media availability scopes — единая access boundary. Второго публичного recommendation repository, translation/cache/admin/import architecture нет.

Read-only inventory 17.07.2026: 32 938 visible titles, 380 772 active `v4` rows для 32 373 source titles, без self/duplicate/invalid-reason pairs; пять полных `v6` build'ов корректно отклонены quality gate, один zero-row build имеет stale heartbeat, а 5 328 dirty IDs превышают scoped threshold. Pruner и full rebuild не запускаются конкурентно с активным long-running importer на SQLite: прежний `v4` остаётся доступным, gate не ослабляется и худший build не активируется принудительно.

Stable types/sources, user-signal/exclusion policy, routes, fallback и SEO описаны в [общем recommendation design](superpowers/specs/2026-07-13-recommendation-v3-list-design.md). Двусторонний feedback и объяснимость уточнены в [design от 26.07.2026](superpowers/specs/2026-07-26-recommendation-explainability-feedback-design.md). Формулы content similarity, quality gate, activation и scoped rebuild принадлежат [v6 design](superpowers/specs/2026-07-16-recommendation-similarity-v6-design.md); audit/checklist — в соответствующем [v6 execution plan](superpowers/plans/2026-07-16-recommendation-similarity-v6.md).

## Размер внешнего видео и authenticated delivery boundary

Канонический media flow остаётся одним: `SeasonvarCatalogParser/ExternalPlaylistImporter → SeasonvarCatalogImporter → LicensedMedia → CatalogTitlePlaybackQuery → CatalogPlaybackSourceResolver → CatalogTitlePlayer → CatalogShowViewModel → Blade`. После каталожной транзакции media сохраняется, и `InspectLicensedMediaFileSize` отдельно решает freshness/format и вызывает `ExternalMediaFileSizeInspector`. `LicensedMediaFileSizeMetadataWriter` является общей mutation boundary для importer и download-time correction: pre-HTTP snapshot фиксирует media/title/`playback_url`/`path`/`format`, один conditional update пишет только size metadata, а material change через существующий `CatalogCacheInvalidator::titlePlaybackMetadataChanged()` меняет только scoped `TitleDetail` generation затронутого тайтла. Этот путь не проверяет collection membership, не bump-ит homepage/sitemap/recommendation/API generations и не ставит general warm intent; полноценное import-изменение тайтла сохраняет прежний широкий invalidation contract. Если источник изменился за время HTTP, старый результат отбрасывается, а новая pending metadata остаётся источником истины. Ошибка HTTP metadata не откатывает title/season/episode/media и не меняет playback health.

Выбор legacy size backlog начинается с `LicensedMediaFileSizeBacklog::query()`, поэтому automatic cycle, explicit backfill и status snapshot используют один direct-format/location/freshness контракт. Query сохраняет Eloquent SoftDeletes scope; processing остаётся stable `lazyById` с eager-loaded title/season/episode context и hard-capped limit, а status строится отдельным одним aggregate statement без загрузки строк. Scheduled size-only mode дополнительно применяет immutable monotonic time budget между media records: исчерпание успешно завершает bounded run, сохраняет elapsed/reason в существующих event/summary и не прерывает уже начатый timeout-bounded upstream request.

Автоматический импорт никогда не читает и не сохраняет полный video body. Метаданные direct file получают `HEAD` с `Accept-Encoding: identity`; если доверенного `Content-Length` нет, выполняется streamed `GET Range: bytes=0-0`, принимается только строгий `206 Content-Range`, после чего upstream немедленно закрывается. HLS/m3u/m3u8 — manifest, а не полный видеофайл: его длина не сохраняется как размер видео, сегменты не объединяются и FFmpeg не вызывается.

Обычный playback продолжает использовать короткоживущий same-origin grant и provider redirect. Отдельный `titles.media.download` является намеренным исключением только для authenticated on-demand attachment: scoped route → `LicensedMediaPolicy` → release entitlement/health/direct-format checks → повторная allowlist/public-DNS validation с connection pinning → `StreamLicensedMediaDownload`. PSR-7 body передаётся bounded chunks, single Range валидируется end-to-end, upstream закрывается в `finally`; полная/временная копия, application cache video bytes и DB transaction вокруг stream отсутствуют.

## Архитектурная граница календаря релизов

Full-page Livewire `ReleaseCalendarPage` отвечает только за locked route/URL state, подготовленные DTO и пользовательские actions. `ReleaseCalendarQuery` владеет bounded выборкой и видимостью, `ReleaseDateValue`/presenters — точностью, timezone и countdown, `ReleaseScheduleService` — mutation/status/history, notification/cache services — post-commit side effects. Admin и importer observers вызывают ту же service boundary; Blade не обращается к модели и не вычисляет даты. Полный contract: [`release-calendar.md`](release-calendar.md).

## Каноническая mobile web boundary Task 23

Mobile experience является responsive presentation существующего Laravel/Livewire portal, а не вторым frontend. Все phone/tablet/desktop requests используют одинаковые named routes, route model binding, page builders, policies, cache/SEO identity и server-rendered content. Device class, orientation, user agent, client storage, network hint и PWA state никогда не участвуют в authorization, premium, region, source или download decision.

`AppLayoutData` — единый navigation/private-page presenter; `app.css` — единая mobile-first design boundary; `mobile-runtime.js` — малый progressive enhancement; `CatalogTitlePlayer`/`player.js` — единственный playback lifecycle. Header menu, filters, forms и player меняют presentation по viewport/capability, не бизнес-логику или route. HTML остаётся содержательным без JavaScript; optional share, Media Session, visual viewport и Network Information деградируют безопасно.

Существующий `/api/v1` mobile sync — versioned Sanctum API для публичного
каталога, owner library/progress и encrypted-cursor/idempotent state
mutations. PWA не подменяет его: browser shell использует web session,
минимальные owner snapshots и только `watchlist.set`/`rating.set`, а mobile
bearer boundary сохраняет прежние abilities и shapes.

Task 100 добавляет `/manifest.webmanifest`, `/service-worker.js`, публичную
`/offline`, bounded snapshot responders, один `PwaActionSyncResponder` и
payloadless Web Push. `PwaBuildAssetResolver` связывает worker cache version с
Vite manifest; worker precache-ит только shell/icons/build assets и
отказывается кешировать private/media boundaries. `PwaSessionResponder`
выдаёт opaque owner scope, а IndexedDB никогда не становится authorization
или server truth.

Additive `web_push_subscriptions` хранит owner relation, encrypted endpoint,
endpoint hash, browser keys, locale, timestamps/failure count и revoke state.
Она не является device fingerprint, не хранит notification text и удаляется
каскадно с пользователем. On-demand `titles.media.download` остаётся online
authenticated bounded stream без server/browser persistence; HLS segments не
собираются и offline license/storage не добавляются.

## Канонический Premium-домен

`PremiumAccessResolver` читает explicit `premium_entitlements` и возвращает один request-scoped `PremiumAccessSummary`; user boolean, session, provider status, Blade и browser redirect не являются источником доступа. `PremiumPlanQuery → CreatePremiumCheckout → PremiumPaymentGateway → PremiumWebhookResponder → PremiumBillingReconciler → PremiumEntitlementService` образует единственный billing flow. Provider registry, currencies и public plans сейчас пусты, поэтому hosted checkout не показывается и никакая оплата не имитируется.

Единственный реальный feature code — `premium_access`; качество, источники, реклама, скачивания, комментарии, профиль и поддержка не получают Premium-привилегий. Manual/lifetime/promotion records, account settings/export/delete guard, database notifications и разделённые admin gates используют тот же resolver/service. Полные identity, precedence, money, webhook, refund/dispute, privacy/cache/SEO/rollout contracts находятся в [`premium.md`](premium.md).

## Канонический центр помощи Task 21

`HelpCenterQuery`/`HelpArticleResolver` → prepared DTO/presenter → полноэкранные Livewire pages образуют единственный read boundary. `SaveHelpArticle`, `TransitionHelpArticle`, revision/merge/feedback/report actions образуют авторизованный mutation boundary. Blade получает только DTO/options и не вычисляет visibility, fallback, ranking, relation, SEO или escalation.

Base article/category UUID и code стабильны; title/slug/locale/status/order не являются identity. Editorial Markdown хранится по translation в БД, interface labels — в `lang/{ru,en}/help.php`. Public resolver допускает только published audience-aware content; preview/internal/revisions обходят public search/cache/sitemap. Task 19 requests, Task 20 tickets и moderation сохраняют собственные aggregates, а help передаёт им только allowlisted безопасный контекст. Полный контракт: [`help-center.md`](help-center.md).

## Каноническая playback boundary Task 07

`CatalogTitlePlayer` orchestrates только prepared current title/season/episode context и последовательные renderless menu/prepare/commit actions. `CatalogTitlePlaybackQuery` владеет stable episode identity, playability, bounded season page и editorial ordering; `CatalogPlayerTransitionFactory` компонует query/resolver/navigation/progress в allowlisted transition DTO; `CatalogPlaybackSourceResolver` вместе с `CatalogEntitlementService` владеют hierarchy/access/source selection и short-lived grant; `CatalogUserStateService` владеет authenticated progress/restart/completion. `player.js` управляет только realtime video/Plyr/HLS lifecycle и in-place context rotation, `player-menu.js` — DOM/focus меню, а `player-navigation.js` передаёт meaningful Livewire actions и синхронизирует History API без `timeupdate` traffic.

`CatalogTitleDetail` именует единственный вложенный player через `wire:ref="player"` и после refresh направляет существующий `catalog-title-refreshed` только `->to(ref: 'player')`, а не всем компонентам класса. Ref scoped parent component и не заменяет `wire:key`; child listener сохраняет defensive сравнение `catalogTitleId` до очистки своих render caches.

HTML season lane получает episode metadata/counts, source summaries — только выбранная серия. Raw provider URLs, full models, progress/preferences/entitlement не являются Livewire public state. Отдельного player route/system/cache/progress store нет. Полная matrix форматов, fallback, subtitles/audio truth, progress concurrency, mobile/a11y/security/SEO и rollback находится в [`audits/video-playback-report.md`](audits/video-playback-report.md).

## Каноническая личная библиотека Task 09

Личная библиотека расширяет существующие `CatalogTitleUserState`, `EpisodeViewProgress`, коллекции и календарь релизов; второй bookmark, status, progress, blacklist или collection aggregate не создаётся. `in_watchlist` остаётся единственным bookmark/favorite flag на уникальной паре user/title. `planned`, `watching`, `paused`, `completed` и `dropped` — стабильные serial-level status codes; «просмотрено» как история выводится из progress, а не хранится переводной строкой. Положительный `more_like_this`, отрицательный `not_interested` и более сильный `blacklisted` остаются отдельным recommendation feedback dimension с централизованным precedence; hidden library включает только два отрицательных значения.

`UserLibraryQuery` и `UserLibrarySummaryQuery` владеют owner-scoped pagination, grouped counters, filters, deterministic sorting и update predicate. `CatalogWatchStatusTransitionService` — единственная boundary автоматических переходов: meaningful playback может изменить только empty/planned → watching, а completed ставится после завершения всех доступных воспроизводимых серий. Явные paused/dropped/completed не перезаписываются открытием страницы или обычным heartbeat; новый сезон/эпизод сохраняет исторический completed и создаёт отдельный update indicator.

`CatalogManualPlaybackService` хранит episode-level manual completion provenance в существующем progress и ровно один явный playback marker на user/episode. Маркер является независимой resume-точкой и не переписывает automatic progress до явного перехода пользователя. `CatalogPersonalUpdateQuery` сравнивает только опубликованные и доступные meaningful `ReleaseScheduleEntry` с server-owned acknowledgment; технический `updated_at`, hidden/unpublished/deleted/inaccessible content не считается обновлением. Existing calendar subscriptions/notification preferences не заменяются.

Один full-page `UserLibraryPage` обслуживает `/library` и совместимые section/locale aliases. URL хранит только allowlisted section/filter/sort/page codes, а Livewire public state не содержит owner ID, Eloquent graph, marker row, private progress или collection membership. Collection CRUD/visibility/order остаются у существующего collection domain; библиотека только ведёт к нему и не связывает membership с bookmark/status/progress. Anonymous bookmark/status/blacklist merge отсутствует и не симулируется; anonymous playback progress продолжает принадлежать playback boundary.

## Архитектурная граница onboarding вкусов Task 71

`TasteOnboardingPage` — единственный full-page Livewire owner маршрутов
`/onboarding/tastes` и `/{locale}/onboarding/tastes`. Компонент отвечает за
locked selected IDs, bounded autocomplete, UX-состояние и orchestration;
`CatalogTasteOnboardingQuery` подготавливает options/search/state, а
`CatalogTasteOnboardingService` авторизует, повторно валидирует видимость и
taxonomy, блокирует owner row и атомарно заменяет данные. Blade не выполняет
запросов и не принимает user ID.

Первое подтверждение email перенаправляет только matching authenticated owner
с готовой схемой на localized onboarding. Anonymous и повторные verification
ссылки сохраняют прежние destinations. Route использует `auth` и `verified`,
а write boundary дополнительно проверяет `account.private` и named rate limit.
Rolling deployment guard оставляет старый flow работоспособным до применения
миграции.

Onboarding не создаёт второй recommender. Liked titles поступают в существующие
legacy/v2 profile builders как typed evidence, все выбранные titles становятся
hard exclusions, а genre/country/playback/completion/duration preferences дают
только capped positive boost в существующем taste reranker. Feature extractor
читает фактические translation/subtitle/status/duration relations grouped
queries; неизвестный status или `NULL` duration остаётся neutral. Сброс
профиля, title merge и account export расширены через прежние lifecycle
services. Public routes/API, shared discovery cache, importer, similarity
builds и notification contracts не меняются.

Подробные решения и ограничение cold-start находятся в
[`taste onboarding design`](superpowers/specs/2026-07-26-taste-onboarding-design.md),
а проверяемый checklist — в
[`taste onboarding plan`](superpowers/plans/2026-07-26-taste-onboarding.md).

## Архитектурная граница шапки и глобального поиска Task 88

`AppLayoutData` остаётся единственным server-side владельцем состава и
активности ссылок общего shell. Он подготавливает четыре desktop-раздела,
меню «Ещё», account/notification actions и четыре ссылочных слота мобильной
навигации; пятый слот поиска является действием одного существующего
`x-layout.header-search`. Blade не выполняет route discovery, authorization
или запросы к базе.

Desktop dropdown и mobile fullscreen presentation используют один input,
один combobox/listbox и один Vite runtime. `GET /search`, `/titles?q=…` и
`GET /api/v1/search/suggestions` сохраняют прежние публичные contracts.
Country добавляется только в header title projection через bounded eager-load;
route middleware и policies профиля, уведомлений, библиотеки и заявок не
заменяются сокрытием элементов интерфейса.

## Архитектурная граница `/titles` Task 98

Новый layout не создаёт controller, repository, второй filter form или
client-side store. Full-page `CatalogSeries`, `CatalogSeriesFilters`,
`CatalogTitlesRequest`, `CatalogTitlesPageBuilder` и
`CatalogTitlesViewModel` сохраняют прежние обязанности; Blade получает
готовые options/query links и не выполняет database queries.

`CatalogView` является web presentation enum. `CatalogTitleIndexRequest`
явно исключает его из API validation/state, поэтому `/api/v1/titles` и его
Resource shape не меняются. Search, taxonomy/year route binding,
authorization, visibility, SEO, cache, importer, player и notification
границы остаются прежними. Rollback — обычный code/docs revert без migration,
backfill, reindex или cache flush.
## Player workspace boundary

Redesign player workspace не создаёт нового application boundary. Один
`CatalogTitlePlayer` получает подготовленный `CatalogShowViewModel`,
`CatalogTitlePlaybackQuery` выбирает только нужные поля selected media, а
`CatalogPlayerTransitionFactory` формирует opaque/safe transition payload.
Blade не выполняет database query.

Theatre mode — presentation-only state в существующих Vite modules. Он не
меняет route, Livewire public methods, signed playback contract, media
authorization, progress ownership, fallback policy, cache keys или database
schema. Media element не переносится между owners и остаётся внутри одного
keyed `wire:ignore`.

Compact context controls выбирают только реально разрешённые media rows.
`subtitle_language` может входить в узкую projection и presenter label, но не
является subtitle body/URL и не раскрывает provider details. Translation,
quality, format и subtitle availability остаются свойствами server-resolved
source; client не считается источником entitlement или availability.

Failure UI вызывает существующие bounded retry/fallback/report boundaries.
Ни theatre, ни recovery controls не записывают global source health, не
создают второй report service и не логируют raw URL/grant. PWA/service worker
по-прежнему исключает video, HLS, playback и download requests из cache.

## Query Classes и главная страница Task 111

Сложные повторно проверяемые чтения главной страницы находятся в
`App\Services\Catalog\Queries`: `CatalogHomeSnapshotQuery`,
`CatalogHomeMetricsQuery` и `CatalogHomeFacetGroupsQuery`. Каждый класс
является `final readonly`, имеет один публичный `handle()` и закрытые детали
сборки. `CatalogHomeSnapshotCache` и `CatalogHomeMetricsCache` сохранили свои
публичные методы и делегируют только cache miss; generic repository или
обёртки над простыми Eloquent-связями не добавлены.

`CatalogHomePageBuilder` остаётся владельцем web/API projection. Один union
query получает жанры и страны, web получает все доступные жанры, страны и
валидные годы, а совместимый `/api/v1/home` сохраняет 18 жанров и 12 годов.
Authenticated web не запрашивает редакционные подборки, которые его Blade не
показывает. Query Class contract проверяется reflection-тестом.

Последний внешний blueprint крупных Laravel-проектов применён выборочно:
существующие services, view models, API Resources, config owners и Blade
components уже обеспечивают полезные границы. Механические interfaces для
каждого сервиса, global view composer, repository на каждую модель и полный
переезд по domain folders не добавлены: у них нет второго implementation,
измеримого выигрыша тестируемости или необходимости для Task 111. Заявление
о zero-downtime deployment также не добавлено без production automation
evidence; миграция индекса сохраняет явный backup/writer-pause contract.

Full-page `CatalogTitleDetail` остаётся владельцем начального request state:
он получает только данные из `CatalogShowRequest` и явно передаёт
`season`/`episode`/`media`/`variant`/`quality`/`format` вложенному
`CatalogTitlePlayer`. Nullable аргументы `mount()` применяются только когда
переданы родителем, поэтому прямой mount компонента сохраняет официальную
Livewire-гидратацию `#[Url]`. Authorization и source resolution по-прежнему
выполняются внутри существующей server-side player boundary.
`CatalogTitleDetail` входит в `resources/player-release.json`, поэтому это
изменение участвует в едином PHP/JS/CSS release fingerprint и readiness
проверке проигрывателя.

Корень `CatalogTitlePlayer` использует `wire:ignore.self`: client transition
владеет текущим opaque session key, а Livewire продолжает обновлять дочерний
workspace. Это предотвращает возврат корневого атрибута к прежней server
identity при сохранённом `wire:ignore` media owner; progress/navigation
по-прежнему сравнивают exact session key и fail closed для stale events.

`CatalogTitlesPageBuilder` и `TagPageData` остаются владельцами подсказок
каталога и связанных тегов: Blade только отображает подготовленные данные.
`CatalogTopListItem::ratingProvider` передаётся в существующий `TitleCard`
как presentation preference, чтобы отображаемое значение совпадало с
критерием ранжирования без дополнительного запроса или нового repository.
