# Журнал обслуживания

- 13.07.2026: добавлены 11 public directory hubs на одном `CatalogDirectoryRegistry`/`CatalogDirectoryQuery`/`CatalogDirectoryBrowser`: counts ограничены общей public visibility boundary и grouped distinct SQL, detail aliases перенаправляют на существующие canonical title listings, Livewire assets подключены глобально, sitemap/navigation/SEO/JSON-LD и локальные search suggestions синхронизированы; schema changes не потребовались благодаря существующим reverse pivot/publication indexes.
- 13.07.2026: production-scale SQLite FTS поиск разделён на материализованную exact/BM25-выдачу и filter-only rowid для paginator total/фасетов; subtitle availability больше не группирует всю `licensed_media`, а поле не отправляет Livewire-запрос на паузе ввода. На 34 015 FTS-документах count запроса «Игра престолов» сократился с 17 150,4 до 7,3–17,5 мс, warm full-page samples заняли 335,8–493,6 мс; новых пакетов и индексов не потребовалось.
- 13.07.2026: previous/next навигация плеера переведена на уже авторизованную коллекцию серий активного сезона: локальный переход выполняет 0 SQL, реальная граница сезона — один полный watchable keyset query. Три новые регрессии проверяют release lane, query budget и отказ для серии вне trusted collection; существующие navigation/direct-source проверки сохранены. Production-scale SQLite профиль `/titles/veshhdok` сократил playback SQL на 73,3% (493,68 → 132,05 мс), server render на 26,2% (1381,70 → 1019,26 мс), HTTP p95 с 1638,8 до 1037,9 мс при неизменном payload.
- 13.07.2026: импорт Seasonvar переведён на typed `SeasonvarPageHandlerRegistry`: serial behavior сохранён, actor/genre/country/tag получили выключенные по умолчанию metadata-only handlers, RSS стал bounded freshness signal, а static/search/sitemap/unknown остались passive. Добавлены per-type enable/automatic/refresh/chunk/publication настройки, `--page-type`, ETag/Last-Modified/304, Unicode/`ё`-`е` identity, bounded deferred serial discovery, подробные events и безопасные non-serial snapshots без provider prose; новых таблиц не потребовалось.
- 13.07.2026: в единственную команду `seasonvar:import` добавлен безопасный `--inventory-only`: рекурсивный XML/gzip sitemap audit использует typed `SeasonvarPageType`, считает unknown/malformed/blocked URL, сохраняет идемпотентный снимок в существующий import run/event и обновляет управляемый `docs/SOURCE_PARITY.md`, не разбирая serial pages и не изменяя каталог или media.
- 13.07.2026: карточка тайтла подключена к уникальному фоновому targeted refresh: одно успешное обновление действует 15 минут, все известные и динамически найденные страницы сезона независимо готовятся в `seasonvar-title-refresh` без application-level limit, единый finalizer применяет полный manifest без удаления локальных данных, а Livewire-оболочка обновляется каждые 3 секунды только в видимой вкладке до terminal state. URL и exception details в UI state не сохраняются.
- 13.07.2026: исправлен strict-mode сбой `LicensedMedia::episode` для нескольких video sources уровня сериала; запрос теперь явно помечает гарантированно отсутствующую связь загруженной, а регрессионный тест воспроизводит исходный multi-row сценарий.
- 13.07.2026: постеры всего публичного портала переведены на трёхслойную class-based систему: `x-ui.poster-frame` единолично выводит cover-изображение с 2% overscan, `x-ui.poster-card` задаёт один structural frame, а query-free `x-catalog.title-card` обслуживает сетку, список, поиск и рекомендации. Главная, title hero, `/watching` и guarded proxy-постеры `/stats` мигрированы; legacy-компоненты удалены, video `poster` не изменён. Целевой набор прошёл 107 тестов и 1000 assertions.
- 13.07.2026: внедрён tiered Redis/Memcached cache layer для homepage/default facets/stats, versioned after-commit invalidation, stale+lock stampede protection, bounded warm job/worker, HTTP validators, readiness и cache metrics. Реальные Redis/Memcached integration/outage tests проходят без shared flush; production-like baseline и повторные метрики зафиксированы в `performance.md`.
- 13.07.2026: production-scale профиль показал 65,074 с для cold rebuild полного `/stats` audit, поэтом прежний 15-секундный fresh TTL заменён на measured policy 30 минут fresh/24 часа stale с event-driven invalidation. Livewire poll остался 15 секунд, но читает готовый snapshot; limiter и critical importer locks перенесены в отдельные `redis-limiter`/`redis-locks` workloads.
- 13.07.2026: поисковый acceptance corpus расширен проверками ранжирования и `е/ё`; FTS document version поднята до 2, документы сохраняют исходное написание и отдельный `ё→е` вариант. Eligibility metadata-backfill переведён с коррелированных scans на индексируемые подзапросы с двумя additive lookup-индексами. После runtime memory exhaustion finalizer systemd-контракт разделяет PHP hard limit `256M` и Laravel recycle threshold `192`, а `Restart=always` сохраняет десять workers.
- 13.07.2026: добавлен policy-backed Livewire workspace `/admin/catalog` с bounded queries, серверной валидацией, reversible publication actions и optimistic locking; существующие unique keys/import ownership переиспользованы без новой миграции, progress/history/watchlist/rating не удаляются.
- 13.07.2026: relation-фасеты `/titles` объединены в один bounded UNION (20 → 11 page-builder queries), карточки/страницы загружают только отображаемые taxonomy columns, а playback resolve переиспользует уже авторизованную hierarchy (6 → 2 queries).
- 13.07.2026: importer dashboard ограничен пятью запросами; health/due counters используют один covering UNION, backlog здоровья — индексируемый конечный список состояний. EXPLAIN подтвердил текущие catalog/release/history/health индексы, поэтому новые индексы не добавлялись.
## 13.07.2026 — безопасный health monitoring видеоисточников

- Добавлена единая state machine `active/degraded/unavailable/disabled` с configurable threshold, exponential retry и recovery; transient timeout больше не отключает источник после одной проверки.
- Probe использует HTTPS allowlist, проверку всех A/AAAA и pin публичного IP, запрещает redirects/credentials/private/link-local/metadata targets, читает только bounded Range или HLS manifest fragment и не сохраняет URL/token/body в диагностике.
- Playback, публичные media/counts, refresh planner, queued finalizer, stats snapshot и `/admin/imports` переведены на канонический health status. Admin показывает только агрегаты и due count.
- Additive migration и rollback проверены на отдельной временной SQLite-базе; thresholds, permanent failure, timeout, recovery, disabled и source fallback проверены в существующих feature tests.

## 13.07.2026 — операционный importer UI и queue coordinator

- Добавлен защищённый gate и Livewire-экран `/admin/imports`; долгий importer не выполняется в HTTP-запросе.
- Ручной старт создаёт unique coordinator job только с run ID; retry делит temporary/permanent failures, cancel освобождает claims, а stale recovery закрывает running run только без live claims.
- Проверены duplicate click, retry, permanent failure, cancel-before-worker, partial result, authorization, safe error output и остановка polling после terminal state в существующих feature tests.

## 13.07.2026 — Централизованная выдача playback source

- Импорт Seasonvar получил проверяемую границу parser → normalized DTO → provider identity → transaction. Name-only merge удалён: тайтлы различаются по provider ID/canonical URL, а одинаковые имена людей с разными стабильными person URL получают разные строки.
- Добавлен `provider_field_values` baseline для безопасного трёхстороннего обновления названия, оригинального названия, описания и постера; publication/audience/window, soft delete и slug остаются локальными. Частичные ответы не удаляют связи/signals/releases/media, повторный import не восстанавливает редакционно удалённые строки.
- Добавлена authenticated Livewire-страница `/watching`: Continue Watching дедуплицирован по сериалу и динамически выбирает сохранённый или следующий доступный выпуск, а фактическая история пагинируется без отдельной event-таблицы. Удаление одной записи и полная очистка защищены `EpisodeViewProgressPolicy` и сразу синхронизируют оба блока.
- Continue Watching использует оконные SQL rank/lead по каноническому progress и release lane, пакетную загрузку тайтлов/серий и текущую playback boundary. Поэтому новая опубликованная серия возвращает завершённый сериал без cache invalidation, а hidden/expired/deleted/source-failed выпуски не получают ссылку.
- Raw provider URLs удалены из HTML и Livewire payload; плеер использует короткоживущий signed endpoint с viewer binding и rate limit.
- Persistent progress сохраняется одной строкой на user/episode: encrypted session token связывает user/title/episode/media, ULID и event sequence отбрасывают retries/out-of-order/старые вкладки, а короткая transaction атомарно обновляет source, trusted duration, percentage, first/last watched и completion. Completed episode не становится непросмотренным от позднего события или replay; отдельного unwatched product action пока нет.
- Добавлен `SeasonvarTitlePageStateSynchronizer`: после успешного parse или unchanged-skip он выравнивает derived `missing_data_flags` уже разобранных и не закреплённых worker'ом страниц одного тайтла по canonical id и season URL hashes, не копируя page-specific import history.
- Уточнённый read-only аудит по стабильным canonical/season URL hashes нашёл 1 103 страницы с устаревшим `seasons_without_episodes`, 3 317 с `episodes_without_video`, 190 с `no_episodes` и 161 с `no_video`. Прежняя выборка через mutable `seasons.source_page_id` занижала значения. Исторические строки массово не перезаписывались: они исправляются ограниченно при очередном успешном импорте связанной страницы без дополнительного sibling HTTP-запроса.
- Plyr/HLS переведён на одну guarded browser-session для точного `title:episode:media`: AbortController освобождает listeners/timers/resources при Livewire morph и навигации, generation token отменяет stale async init, progress сохраняется bounded heartbeat/lifecycle flush-ами, а старый session key не может записать прогресс новой серии. При уничтожении lifecycle-маркеры очищаются и с восстановленного Plyr media node; это проверено повторной навигацией, Back/Forward, сменой серии и мобильной ориентацией без duplicate instance и stale progress event.
- Добавлены безопасные русские loading/buffering/retry/expired/unavailable/fatal состояния и локальная retry-кнопка без provider URL или текста исключений.
- Video sitemap переведен с raw `video:content_loc` на внутренний `video:player_loc`, который проходит ту же server-side playback boundary.
- На resolve и direct access повторяются publication/audience/window и hierarchy checks; известные failures исключаются из публичных counts и автоматического выбора.
- Проверка внешнего media URL использует общий HTTPS/DNS allowlist, запрещает redirects, работает в stream mode с Range/timeouts/size bound и не пишет полный URL или exception message в события.
- Возрастной профиль, территория, entitlement/subscription и concurrent-stream учет пока отсутствуют в доменной модели и не симулируются; будущие правила должны подключаться к `CatalogPlaybackSourceResolver`.

## 2026-07-13

- Publication/audience/window проверки каталога, поиска, route binding, рекомендаций, policies и playback сведены в `CatalogEntitlementService`: SQL scopes и loaded release возвращают согласованную границу, а signed direct playback повторяет решение для каждого parent/media.
- Решение различает authentication/plan/region/profile/concurrency отказы, но продукт по-прежнему реализует только publication window и `public/authenticated` audience. Профили, PIN, roles/admin preview, billing/territory и stream sessions намеренно не добавлялись; текущий `User` остаётся владельцем progress/history/watchlist/rating.
- Список просмотра карточки переведён с race-prone toggle на explicit desired-state insert-or-ignore и conditional update под unique `(user_id, catalog_title_id)`; одинаковый retry не меняет даже `updated_at`, очистка отсутствующего состояния не создаёт строку, browser не передаёт user/profile ID, а policy теперь применяется внутри общего write-сервиса.
- «Избранное» закреплено как название того же списка просмотра без второй таблицы. Диапазон внутренних оценок централизован в `config/catalog.php`; count/average обновляются одним conditional aggregate и не смешиваются с импортными provider ratings.
- Livewire-плеер карточки тайтла получил кнопки предыдущей/следующей доступной серии. Навигация использует keyset-запросы по `sort_order`, номеру и ID, переходит между видимыми сезонами, пропускает hidden/expired/source-less записи и не смешивает обычные выпуски со спецвыпусками.
- `catalogTitleId` остаётся locked-свойством, изменяемые URL-параметры сезона/серии повторно проверяются в общей playback boundary, а смена сезона не затрагивает watchlist и пользовательскую оценку. Alpine сохраняет одну history-запись перед действием, после чего Livewire атомарно заменяет все URL-параметры playback state; Back/Forward больше не перебирают variant/quality/format по одному.

## 2026-07-12

- Карточка сериала переведена на вложенный Livewire 4 playback-компонент: статическая оболочка загружает metadata и summaries сезонов, серии/media загружаются только для активного сезона, а shareable URL сохраняет season/episode/media profile.
- Добавлены additive таблицы watchlist/rating и episode progress, server-side `CatalogTitlePolicy`, deterministic continue/next/replay/start action и throttled сохранение позиции из локального Plyr/HLS player.
- Общая playback boundary исключает hidden/window/audience releases и published media без фактического playback location из counts, выбора серии, primary action и прогресса; рекомендации повторно проходят доступность текущего пользователя.
- Добавлен Redis queued-режим `seasonvar:import --queued`, атомарные lease страниц, Redis-блокировка сезонов одного тайтла, десять systemd workers и cron на 10 запусков диспетчера в сутки; успешные страницы повторно проверяются через 24 часа и обновляют изменившийся постер по новому HTML.
- Multi-select каталог использует единый контракт OR внутри группы и AND между группами для годов, справочников, типов публикации, качеств и субтитров; grouped pivot-подзапросы сохраняют точный paginator total без `distinct` основной выборки.
- Добавлены URL-синхронизированные группы `publication_type[]` и `subtitles[]`, точечное удаление выбранных значений и debounced серверный поиск актеров/режиссеров с лимитом 24 результата. Языки дорожек не симулируются: используется существующая озвучка/перевод и признак наличия субтитров.
- Пустые URL-поля формы допускают временный `null` при Livewire popstate hydration и сразу нормализуются обратно в безопасные значения; browser back/forward больше не оставляет typed properties неинициализированными. Счетчик активных фильтров теперь включает relation-, fixed-list- и scalar-группы.
- `/titles` переведён на full-page Livewire 4.3: bounded URL-synced form state, server-side поиск/фильтры/сортировка/пагинация, точечные и полные сбросы, loading/error/empty states и стабильные `wire:key`; вся выборка по-прежнему делегируется общей public query boundary, а GET fallback сохранён.
- Публичные выборки тайтлов сведены к `CatalogTitleQuery`: каталог, API, фасеты, sitemap/feed, рекомендации и публичные счетчики начинают запрос с единой user-aware publication boundary; `CatalogTitlesCriteria` передает нормализованные ID фильтров, а enum-сортировки имеют стабильный `id` tie-breaker.
- Relation-фильтры и legacy-поиск оставляют ID в SQL-подзапросах: pivot joins не размножают тайтлы, paginator total совпадает с выдачей, а полный список поисковых кандидатов больше не загружается в PHP.
- Левая панель «Быстрый доступ» на странице сериала стала плоской: у меню, текущего выбора и счетчиков убраны декоративные рамки, а сами счетчики получили локальные FontAwesome-иконки.
- Добавлены версионируемые Git guard hooks: `.githooks/pre-commit` требует ветку `main` и отсутствие unstaged/untracked файлов перед commit, `.githooks/pre-push` требует ветку `main` и чистое рабочее дерево перед push; правила закреплены в AGENTS, README, CODE_STANDARDS и development docs.
- Домен каталога получил отдельные publication statuses, audience и окна доступности, soft delete, детерминированный порядок сезонов/серий и special-aware unique keys; production statuses источника остались независимым справочником. Трёхфазные миграции сначала добавляют поля, затем backfill-ят данные и только после проверки дублей включают ограничения; importer upsert сохраняет `regular`-семантику и находит строки по стабильным provider keys, не отменяя последующее редакционное soft delete.
- Constraint-миграция `2026_07_12_174219_enforce_catalog_domain_publication_integrity` успешно применена и откатана на копии текущей SQLite-базы; текущая локальная база также прошла additive, backfill и constraint-фазы, а `migrate:status` не показывает pending миграций.
- `CatalogTitlesViewModel` и `CatalogTitlesPageBuilder` больше не подмешивают устаревшие `invalidFilterSlugs` в публичное состояние ссылок и контекст каталога: выбранные фильтры остаются единственным источником query-state, а неиспользуемый helper удаления invalid-фильтров удален.
- `/titles` больше не показывает публичный счетчик «Ошибочных фильтров»: устаревшие или несуществующие slug-значения справочников тихо отбрасываются, валидные выбранные фильтры продолжают работать, а пагинация и ссылки получают очищенное query-state.
- В пустой выдаче `/titles` действие, которое сохраняет текущий поиск и убирает только фильтры, переименовано в «Убрать фильтры», чтобы не путать его с глобальным сбросом и «Показать весь каталог».
- Исправлена агрегация справочников каталога через обратные `belongsToMany`: facet query теперь соединяет `catalog_titles` по related pivot key и группирует по foreign taxonomy key, поэтому страны, жанры, актеры и остальные справочники не теряются из фильтров.
- Publication boundary `CatalogTitle` перенесена в implicit route binding: неопубликованные тайтлы закрыты единообразно для публичной карточки, API show и proxy постера статистики до внешнего HTTP-запроса.
- Proxy постеров статистики теперь принимает корректные image-ответы без `Content-Length`, сохраняя проверку непустого заголовка, фактического размера тела, MIME-типа, HTTPS URL и запрет редиректов.
- Синхронизированы существующие PHPUnit-контракты multi-select фильтров, полного списка сортировок, grouped state view model и polling `/stats`; публичный переключатель вида использует нейтральную подпись «Сетка».
- `/titles` больше не показывает мусорный chip `Сериал: не найден`, если в URL остался устаревший или несуществующий `title`-slug; такой контекст теперь игнорируется и не протаскивается в ссылки сортировки, вида, алфавита и фильтров.

## 2026-07-09

- `/titles` переведен на multi-select боковые фильтры: годы, актеры, режиссеры, жанры и остальные relation-фильтры выбираются чекбоксами в одной GET-форме и применяются пачкой без декоративных outlined-ссылок.
- Для актеров и режиссеров локальный поиск по загруженному списку заменен серверным Livewire-поиском с ограниченной выдачей; остальные длинные группы сохраняют локальный progressive enhancement.
- Выбранные годы и значения справочников `/titles` теперь закрепляются первыми в своих группах, а активные группы получили плоский точечный сброс без outlined-стиля.
- Исправлены URL удаления последнего значения relation-фильтра и пустой `title=` в ссылках фильтров каталога.
- Query-логика relation-фильтров использует OR между значениями одного типа и AND между типами; выбранные годы и значения показываются отдельными chips, а canonical URL сохраняет массивы фильтров без presentation-параметров.
- Убраны декоративные обводки у текстовых ссылок, taxonomy/status chips, боковых меню, фильтров, сортировок, пагинации, SEO-query links и `/stats` badges; правило закреплено в `docs/UI_STANDARDS.md`, структурные рамки панелей/карточек/форм сохранены.
- Доведена нормализация query-state каталога: `/titles` больше не читает `$filterView->catalogQueryState[...]` из Blade, scalar-поля идут через `CatalogTitlesViewModel::scalarState()`, list-поля — через `listState()`.
- Пустые display-значения расширенных фильтров `/titles` больше не создают активные chips, даже если raw query-key присутствует в URL.
- Активные расширенные фильтры `/titles` теперь раскрывают блок `<details>` сразу, чтобы состояние URL было видно без дополнительного клика.
- Добавлен `CatalogStatsPosterUrlGuard`: `/stats` больше не рендерит `poster_src` для неразрешимых или небезопасных poster hosts, блок последних постеров оставляет только proxyable кандидатов, а proxy responder использует тот же guard перед внешним HTTP-запросом.
- Укреплена страница `/titles`: Blade больше не перебирает raw scalar/list query-state для скрытых полей и чекбоксов качества, а мобильная выдача получила явный переход к фильтрам без изменения порядка результатов.
- Снижена нагрузка `/stats`: Livewire polling переведен на `wire:poll.15s.visible`, fresh TTL снимка увеличен до 15 секунд, документация синхронизирована с новым интервалом.
- Rate limiter переведен на отдельный `CACHE_LIMITER_STORE=file`, чтобы throttle-счетчики публичных маршрутов не создавали частые записи в SQLite `cache` table.
- Зафиксировано проектное Git-правило: рабочая ветка только существующая `main`; feature branches, worktree-ветки, временные ветки и дополнительные `main`-подобные ветки запрещены без прямого нового указания пользователя.
- Добавлены read-only Google summary-команды `google:search-console:summary` и `google:analytics:summary`, lightweight service-account OAuth JWT flow без новых Composer-зависимостей и тесты с `Http::fake()`.
- Добавлена команда `php artisan integrations:doctor` для read-only диагностики MCP, Google Search Console/Analytics config, user-level MCP registration и CLI-инструментов без раскрытия секретов; глобально зарегистрированы `openaiDeveloperDocs` и Google Workspace MCP endpoints, но Google OAuth login требует отдельный OAuth client ID.
- Добавлен безопасный слой MCP/Google-интеграций: проектные skills `seasonvar-importer`, `seasonvar-ui`, `seasonvar-seo`, `seasonvar-mcp-ops`, шаблон `.codex/mcp.example.toml`, документация `docs/integrations/mcp-catalog.md` и `docs/integrations/google.md`, а также выключенные read-only Google placeholders в `.env.example` и `config/services.php`.
- Eloquent-модели дополнены обратными связями для `SourcePage`, `SeasonvarImportRun` и событий импорта, числовые поля приведены к явным casts, а правила моделей и query usage вынесены в `docs/models.md`.
- Синхронизирована Markdown-документация с текущими маршрутами, командами, Laravel/Livewire архитектурой, MCP-настройкой, CI, setup/testing/deployment правилами и no-`@php` Blade-подходом.
- Добавлен GitHub Actions CI workflow: backend проверяет Composer, Pint, PHP syntax lint, Laravel cache-команды и тесты; frontend выполняет `npm ci`, `npm audit` и `npm run build` через официальный npm registry.
- `/stats` перенесена на Livewire 4: страница обновляет все видимые блоки через `wire:poll.15s.visible`, использует `CatalogStatsSnapshotCache` с 15-секундным fresh TTL и fallback на последний успешный снимок, а полный stats-массив не хранится в публичном состоянии Livewire.
- Добавлены `CatalogStatsSnapshotBuilder` и `CatalogStatsSnapshotSanitizer`: stats-данные приводятся к массивам и очищаются от внешних source/media URL перед рендером, чтобы HTML и Livewire-ответы не раскрывали приватные адреса.
- `seasonvar:import` после завершения запуска обновляет stats snapshot; отдельные публичные команды для обновления статистики не добавлялись.
- Лимит `catalog-stats` увеличен до 180 запросов в минуту и применен к Livewire update endpoint; polling `/stats` переведен на 15 секунд и viewport-only режим, чтобы не создавать лишние update-запросы в скрытой вкладке.
- Страница `/stats` открыта для гостевого read-only доступа: route `can('viewCatalogStats')` снят со статистики и proxy постеров, сохранен `catalog-stats` throttle и запрет на вывод raw source/private URLs.
- Ранее для служебной статистики добавлялась authorization-граница; после решения открыть `/stats` публично соответствующие route `can` middleware и gate сняты, а тесты/документация обновлены под гостевой доступ.
- `seasonvar:import` подготовлена для частого cron-запуска: если предыдущий импорт еще держит lock, новая копия пропускается с успешным кодом выхода, а все обновления остаются внутри единой команды импорта.
- Стабилизированы проверки и отчетность импорта: базовый `Tests\TestCase` отключает Vite в тестах через `withoutVite()`, `/stats` снова скрывает технические имена таблиц и использует пользовательские русские подписи, а `seasonvar:import` обновляет счетчики запуска после каждого обработанного chunk/URL, чтобы длинный запуск не показывал нули до конца цикла.
- Валидация публичных query-параметров каталога оформлена через Laravel Form Request-классы: `CatalogTitlesRequest` получил правила для фильтров, `CatalogShowRequest` проверяет выбранную серию/медиа, slug-фильтры вынесены в reusable Rule, а типы фильтров — в enum; добавлена документация `docs/validation.md`.
- Оптимизирована карта посадочных страниц: пары справочник/год для `sitemap-landings.xml` считаются grouped join-запросами по pivot-таблицам вместо `exists()` в цикле; добавлена документация `docs/performance.md` и regression test на количество запросов.
- `CatalogController` разделен на тонкий web-контроллер, `CatalogSitemapController`, Form Request для фильтров каталога, page-builder сервисы, query-сервис и SEO-builder; добавлена документация `docs/architecture.md`.
- Удалены все `@php`/`@endphp` из Blade-шаблонов: SEO/layout данные вынесены в `AppLayoutData`, состояние фильтров и страницы тайтла вынесено в view-model классы, добавлен тест запрета inline PHP в Blade.
- Подтверждено безопасное состояние Laravel 13: приложение работает на Laravel 13.19/PHP 8.5, зависимости соответствуют официальному руководству обновления, выполнено точечное Composer-обновление совместимого набора и обновлена документация процесса обновления.
- Исправлена проектная MCP-команда Laravel Boost: `.codex/config.toml` запускает `boost:mcp` с `--env=local`, потому что Boost регистрирует MCP-команды только в local/debug окружении.
- Обновлена карта сайта портала: `/sitemap.xml` теперь отдает индекс карты сайта, статические годы и посадочные страницы учитывают опубликованные карточки, а карта видео включает только опубликованные медиа с абсолютными внешними ссылками.
- Обновлен `public/robots.txt`: оставлен стабильный `Sitemap: https://seasonvar.miniserver.fun/sitemap-index.xml` без ручного перечисления paginated sitemap-файлов.
- Добавлена команда `php artisan project:docs-refresh` для управляемого обновления разделов документации проекта.
- Добавлены версионируемые Git-хуки в `.githooks`, скрипт `scripts/docs-autocommit-push.sh` и локальная настройка `core.hooksPath=.githooks` для автообновления документации и отдельного коммита документации; отправка в Git выполняется только при `SEASONVAR_DOCS_AUTO_PUSH=1`.
- Добавлены функциональные тесты карты сайта и `robots.txt`: совместимый индекс карты сайта, годы опубликованных карточек, карта изображений, карта видео с абсолютными URL и стабильный `robots.txt`.
- Добавлено автоматическое дозаполнение `source_media_key` для старых медиа внутри `seasonvar:import`, общий генератор ключей медиа для импорта Seasonvar и playlist-импорта, а также русские сообщения прогресса для этого этапа обслуживания.
- Обновлены правила проекта и настройки источника: единственная команда импорта явно сохраняет внешние видео-ссылки без скачивания видеофайлов.
- Отдельные публичные команды Seasonvar заменены на `php artisan seasonvar:import`: режим одного URL, принудительное обновление, бесконечный режим, журналы запусков в базе, обнаружение sitemap, обновление страниц, обновление сезонных страниц, сбор медиа и объединение дублей тайтлов.
- Добавлены таблицы и поля состояния импорта для событий запусков, повторов страниц источника, признаков недостающих данных, HTML-снимков, отзывов и стабильного обновления медиа через `source_media_key`.
- Хранение медиа Seasonvar переведено на внешние ссылки воспроизведения, качество, перевод, формат, проверки доступности и все варианты без скачивания видеофайлов.
- Объединение сезонных дублей вынесено во внутренний сервис, чтобы один сериал владел всеми сезонами.
- Страницы тайтлов обновлены: аккордеоны сезонов, понятные русские сообщения для посетителей, выбор варианта медиа и воспроизведение через Plyr/HLS.
- Ручная команда импорта плейлистов убрана из публичного списка Artisan, при этом импорт плейлистов оставлен как внутренний сервис.
- Добавлена поддержка рейтингов IMDb/КиноПоиск и альтернативных названий Seasonvar через отдельные таблицы.
- Улучшен разбор информационных блоков Seasonvar: подписи, рейтинги, возрастные ограничения, страны, жанры, режиссеры, актеры и запасное извлечение серий.
- Убраны декоративные подписи каталога, которые не давали навигации, фильтров, счетчиков или полезного состояния.
- Добавлены локальные иконки FontAwesome через npm/Vite, параметры иконок подключены к общим UI-компонентам без CDN.
- Удален текст описаний, который раньше попадал в страны, и закрыт повторный импорт описательных текстов как названий связей.
- Запрещен импорт длинных текстов как возрастных ограничений.
- Исправлена пакетная обработка `upsert` для найденных URL и сохранено экономное использование памяти при записи sitemap-ссылок.
- Добавлен быстрый путь для неизмененных страниц: HTML-разбор и запись каталога пропускаются, если хэш контента не изменился.
- Оптимизирован `upsert` тайтлов Seasonvar: identity resolver сначала использует стабильный provider ID, затем точный `source_url_hash`/source page; совпадение названия не является основанием для merge.
- Найденные URL Seasonvar сохраняются пакетным `upsert`, а не через отдельный `firstOrNew()` для каждой ссылки.
- Сезоны и серии импортера Seasonvar синхронизируются пакетными `upsert`-операциями.
- Запись каталога в импортере Seasonvar обернута в транзакцию, синхронизация конкретных связей вынесена в типизированный пакетный помощник.
- Отрисовка постеров каталога первоначально была централизована, а 13.07.2026 заменена текущим `x-ui.poster-frame` с cover + overscan и одной внешней рамкой.
- Адаптивные строки тайтлов первоначально были общими, а 13.07.2026 сведены к единому API `x-catalog.title-card` с layout `grid`, `horizontal` и `compact`.
- Улучшены адаптивные макеты каталога, на главной добавлены миниатюры постеров рядом с названиями.
- Синхронизация конкретных связей каталога в импортере Seasonvar оптимизирована через групповые `upsert` и одну синхронизацию pivot-связей на тип.
- Добавлены конкретные таблицы связей каталога и отношения Eloquent `belongsToMany` для жанров, стран, актеров, режиссеров, возрастов, переводов, статусов, каналов, студий и тегов без morph-связей.
- Добавлен разбор статуса списка сезонов Seasonvar: дата последней серии, количество вышедших серий, известное или неизвестное общее количество, перевод сезона и исходный текст статуса.
- Снижена нагрузка страницы тайтла: убраны неиспользуемые `source`, вложенные страницы источника сезонов, страницы источника серий и лишние eager-load связи рекомендаций.
- Добавлены индексы запросов для фильтров таксономий, новых списков, очередей синхронизации страниц источника и списков медиа тайтлов.
- Подсчет контекстных счетчиков в боковой панели сокращен с отдельных SQL-запросов по типам до одного агрегированного union-запроса по pivot-связям.
- Поиск активных таксономий фильтров оптимизирован в один пакетный запрос.
- Счетчики боковой панели оптимизированы с отдельных запросов по элементам до пакетных `withCount()` по типам таксономий.
- Контекстные счетчики годов оптимизированы в один групповой запрос.
- Страница тайтла оптимизирована: убраны повторные eager-load связи типизированных таксономий, группы таксономий готовятся в контроллере.
- Интерфейс каталога оставлен светлым и компонентным.
- Добавлены стандарты документации для кода, интерфейса, связей, поведения парсера и будущих записей обслуживания.
- Добавлены SEO-метаданные портала: canonical URL, robots-правила, OpenGraph/Twitter-теги, JSON-LD schema.org, динамическая карта сайта Laravel и объявление sitemap в robots.txt.
- Добавлены SEO-данные для главной, списка/фильтров каталога и отдельных страниц тайтлов: TVSeries, VideoObject, CollectionPage, WebSite и BreadcrumbList.
- Расширена SEO-автоматизация: индекс sitemap, разделы статических страниц, годов, таксономий и тайтлов, image sitemap для постеров, RSS обновленных тайтлов, устранение дублей canonical для фильтров и расширенные OpenGraph-данные видео/изображений.
- Добавлены метаданные OpenSearch, чтобы браузеры и поисковые системы могли находить поиск по каталогу автоматически.
- Добавлена автоматическая структура ItemList для главной и каталога, а также структура сезонов и серий на страницах тайтлов с уже загруженными связями.
- Добавлены автоматические данные WebPage и Organization, hreflang-альтернативы, last-modified, article tags, расширенные поля TVSeries для издателя, языка, бесплатного доступа и ссылки `sameAs` на источник.
- Добавлены видимые хлебные крошки, структура SiteNavigationElement и автоматические FAQ-блоки на страницах тайтлов с соответствующим FAQPage JSON-LD.
- Добавлена автоматическая генерация длинных поисковых фраз из названий, алиасов, жанров, стран, актеров, режиссеров, годов, сезонов, серий и доступности медиа; добавлены видимые блоки поисковых фраз и метаданные keywords/news_keywords.
- Добавлены автоматические SEO-описания и внутренние связанные ссылки для главной, каталога и страниц тайтлов, а также speakable WebPage-селекторы для читаемого контента.
- Добавлены video sitemap, текст для LLM-обнаружения, Dublin Core, семантические кластеры ключевых слов и видимые блоки кластеров на основе фактов каталога/тайтла.
- Добавлены чистые URL страниц годов, динамические H1/lead для каждого состояния фильтра, года и поиска, ссылки sitemap на страницы годов и кликабельные семантические/поисковые чипы.
- Добавлен sitemap программных SEO-страниц для реальных сочетаний таксономии и года: жанр/год, страна/год, актер/год, режиссер/год, перевод/год и возраст/год.
- Добавлено тематическое SEO-расширение портала с meta-тегами subject/classification/page-topic, данными schema.org DefinedTermSet и видимыми тематическими внутренними ссылками.
- Добавлено SEO-расширение поисковых намерений с article tags, target/search-intent metadata, тематической навигацией schema.org ItemList и видимыми query-ссылками.
- Добавлено entity SEO-расширение с метаданными canonical summary, повторяемыми entity meta tags, schema.org WebPage `about`/`mentions` и microdata во видимой внутренней навигации.
- Добавлено SEO-расширение быстрых ответов с видимыми карточками ответов страницы и соответствующими данными FAQPage на основе заголовка, описания, тем и поисковых намерений.
- Добавлено SEO-расширение структуры страницы: автоматическое оглавление, якоря секций, metadata `toc-count`, schema.org ItemList contents и WebPageElement `hasPart` для каждого SEO-блока.
- Добавлено long-tail SEO-расширение с автоматическими ссылками поисковых фраз, metadata `long-tail-keywords`, metadata `query-count` и schema.org ItemList.
- Добавлено related-collection SEO-расширение с тематическими карточками подборок, metadata `related-collection-count` и schema.org CollectionPage `hasPart`.
- Добавлена нормализация расширенных ключевых слов: keywords, topics, intents, long-tail phrases и related collections объединяются в `keywords`, `news_keywords`, schema.org WebPage keywords, keyphrases, keyword aliases и metadata `keyword-count`.
- Добавлено action-intent SEO-расширение с видимыми действиями страницы, metadata `action-count`, schema.org WebPage `potentialAction`, SearchAction/ReadAction/ViewAction/WatchAction и ItemList action navigation.
- Добавлено semantic-glossary SEO-расширение с видимыми карточками DefinedTerm, metadata `defined-terms`, metadata `glossary-count` и schema.org DefinedTermSet.
- Добавлено semantic-hub SEO-расширение с группами тематических хабов, metadata `semantic-hub-count`, расширенной интеграцией ключевых слов и schema.org ItemList для просмотра, сезонов, описаний, актеров, жанров и связанных подборок.
- Добавлено snippet-block SEO-расширение с тезисными карточками страницы, metadata `snippet-count` и `snippet-topics`, расширенной интеграцией ключевых слов и schema.org WebPageElement ItemList.
- Добавлено content-signal SEO-расширение с видимыми метриками качества/охвата, metadata `content-signal-count` и `content-signal-summary`, main-content microdata, расширенной интеграцией ключевых слов и schema.org PropertyValue ItemList.
- Исправлен порядок зависимостей SEO-переменных и добавлено audience-path SEO-расширение с видимыми путями поиска, metadata `audience-path-count`, расширенной интеграцией ключевых слов, keyword aliases и schema.org ItemList.
- Добавлено also-search SEO-расширение с видимыми чипами связанных запросов, metadata `also-search-count`, расширенной интеграцией ключевых слов и алиасов, интеграцией оглавления и schema.org ItemList.
- Добавлено discovery/freshness SEO-расширение с видимыми сигналами индексации/обновления, ссылками sitemap/feed/opensearch/llms, metadata `discovery-signal-count`, расширенной интеграцией ключевых слов и schema.org DataCatalog/Dataset.
- Добавлено query-matrix SEO-расширение с группами матриц поисковых намерений, metadata `query-matrix-count`, расширенной интеграцией ключевых слов и алиасов, интеграцией оглавления и schema.org ItemList.
- Добавлено media SEO-расширение с нормализованными сигналами изображений/видео, metadata `media-signal-count` и `media-assets`, расширенной интеграцией ключевых слов, видимыми ссылками предпросмотра медиа и schema.org ImageObject/VideoObject ItemList.
- Добавлено publisher-trust SEO-расширение с видимыми сигналами издателя/индексации/поиска, metadata `publisher-signal-count`, расширенной интеграцией ключевых слов и schema.org CreativeWork/WebSite/Organization.
- Добавлено freshness SEO-расширение с запросами текущего года, новых серий и обновлений, metadata `freshness-query-count` и `freshness-year`, расширенной интеграцией ключевых слов и алиасов, видимыми карточками актуальности и schema.org ItemList.
- Добавлено SEO-расширение русских вариантов запросов с чипами языка, качества, субтитров, озвучки и внутреннего поиска, metadata `russian-query-variant-count`, расширенной интеграцией ключевых слов и алиасов, а также schema.org ItemList.
- Добавлено catalog-direction SEO-расширение с карточками направлений жанров, стран, годов, актеров, режиссеров, переводов, возраста и тем, metadata `catalog-direction-count`, расширенной интеграцией ключевых слов и алиасов, а также schema.org ItemList.
- Добавлено comparison SEO-расширение с карточками похожего, альтернатив и рекомендаций, metadata `comparison-query-count`, расширенной интеграцией ключевых слов и алиасов, а также schema.org ItemList.
- Добавлено episode-intent SEO-расширение с карточками сезонов, серий, последних выпусков и расписания, metadata `episode-intent-count` и `episode-keywords`, расширенной интеграцией ключевых слов и алиасов, а также schema.org ItemList.
- Добавлено watch-mode SEO-расширение с карточками веб-плеера, мобильного просмотра, качества и внешнего видео, metadata `watch-mode-count` и `watch-mode-keywords`, расширенной интеграцией ключевых слов и алиасов, а также schema.org ItemList.
- Добавлено translation SEO-расширение с карточками озвучки, субтитров, дубляжа и перевода, metadata `translation-query-count` и `translation-keywords`, расширенной интеграцией ключевых слов и алиасов, а также schema.org ItemList.
- Добавлено voice-search SEO-расширение с карточками разговорных вопросов, metadata `voice-search-query-count` и `voice-search-keywords`, расширенной интеграцией ключевых слов и алиасов, а также schema.org ItemList.
- Добавлено topic-authority SEO-расширение с карточками описания, фактов, навигации, обновлений и похожих тем, metadata `topic-authority-count` и `topic-authority-keywords`, расширенной интеграцией ключевых слов и алиасов, а также schema.org ItemList.
- Добавлено release-calendar SEO-расширение с карточками дат выхода на сегодня, завтра, неделю и конкретную дату, metadata `release-calendar-query-count` и `release-calendar-keywords`, расширенной интеграцией ключевых слов и алиасов, а также schema.org ItemList.
- Исправлены внутренние SEO-страницы поиска: длинные сгенерированные фразы разбиваются на токены, общие стоп-слова игнорируются, значимые слова ищутся по названию, оригинальному названию, описанию, связям и году, а отсутствие совпадений сохраняется как честная пустая выдача.
- Добавлены сервисные дозаполнения импорта для старых статусов страниц источника и недостающих метаданных медиа, а также сохранение трейлеров на уровне тайтла/сезона, когда номер серии отсутствует.
- Добавлена защита единственной команды импорта Seasonvar через cache-lock: параллельные CLI-запуски быстро завершаются ошибкой вместо одновременной обработки одной очереди.
- Добавлена автоматическая очистка некорректных вложенных URL Seasonvar и проверка URL, чтобы ссылки вида `.html/...` больше не запрашивались.
- В каждый цикл импорта добавлено дозаполнение доступности медиа, чтобы постепенно проверять старые строки `licensed_media` с отсутствующим или устаревшим `check_status`.
- Добавлено разворачивание master-playlist HLS: найденные `.m3u8` плейлисты создают отдельные варианты качества с сохранением исходного сезона и серии.
- Улучшен разбор URL сезонов Seasonvar для `season`, `sezon`, `сезон` и номеров с ведущими нулями вроде `00005-sezon`, чтобы медиа из плейлиста по умолчанию привязывались к правильному текущему сезону.
- Сохранены канонические хэши контента тайтлов при импорте дополнительных сезонных страниц того же сериала, чтобы неканонические страницы сезонов не перезаписывали состояние синхронизации тайтла.
- SEO-фразы поиска сохраняют варианты регистра кириллицы и сначала выбирают точные совпадения по названию, поэтому запросы вроде `сериал Знахарь описание жанры` ведут к конкретной карточке, а не к общему каталогу.
- Страницы тайтлов передают `search_context` в общий SEO-layout; сгенерированные ссылки поиска добавляют текущий slug тайтла, а контроллер каталога применяет этот контекст, чтобы переходы из конкретной страницы оставались внутри этого тайтла.
- Для контекстных поисковых страниц сохранен параметр `title` в canonical URL, форме поиска, ссылках фильтров и ссылках годов.
- Безопасный размер пачки разбора по умолчанию для `seasonvar:import` уменьшен до 100 страниц за цикл, чтобы одиночная команда не перегружала свой и внешний сервер.
- Команда `seasonvar:import` получила heartbeat активного запуска и автоматическое восстановление зависшей блокировки, если прошлый процесс остановился без завершения.
- Для SQLite включены `busy_timeout`, WAL-журнал и нормальный synchronous-режим по умолчанию, чтобы импорт устойчивее переживал краткие блокировки базы.

<!-- project-docs:start -->
## Автоматически обновляемое состояние документации

- Последнее автоматическое обновление блоков документации: 13.07.2026.
- Команда обновления: `php artisan project:docs-refresh`.
- Хук автокоммита: `.githooks/post-commit` через `scripts/docs-autocommit-push.sh`; отправка в Git включается только через `SEASONVAR_DOCS_AUTO_PUSH=1`.
- Основной sitemap для robots и поисковых систем: `https://seasonvar.miniserver.fun/sitemap-index.xml`.
<!-- project-docs:end -->
