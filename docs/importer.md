# Конвейер импорта Seasonvar

Обновлено: 16.07.2026

## Граница данных

Единственная публичная команда — `php artisan seasonvar:import`. Последовательный и Redis queued-режимы сходятся в `SeasonvarCatalogImporter` и используют один конвейер:

1. `PoliteHttpClient` получает provider response с crawl delay, timeout и retry; успешный ответ и его hash фиксируются в `SourcePage`/`SourcePageSnapshot`.
2. `SeasonvarCatalogParser` извлекает данные без записи в базу.
3. `SeasonvarCatalogData::fromParsed()` валидирует обязательные поля, ограничения размеров и типы, нормализует строки и удаляет дубли внутри коллекций.
4. `SeasonvarCatalogIdentityResolver` ищет тайтл сначала по `(source_id, external_id)`, затем по каноническому URL hash или закреплённой source page. Название не является автоматическим идентификатором.
5. Короткая retry-aware transaction выполняет upsert тайтла, справочников, pivot-связей, provider ratings/reviews/signals, сезонов и серий и только затем помечает страницу разобранной.
6. Внешние playlist/media запросы выполняются вне catalog transaction; `source_media_key`, playback URL и unique keys делают повторную запись идемпотентной.
7. Import run counters обновляются после URL/chunk. `indexed_at` отмечает SQL-search visibility; Scout или внешний поисковый движок в проекте не установлен. На главной обновление тайтла определяется только добавлением доступной серии (`episodes.created_at`) или опубликованного видеоварианта (`licensed_media.created_at`), поэтому повторный импорт одной метаинформации не поднимает тайтл в свежей выдаче.
8. Полный sync/queued finalizer пересобирает рекомендации и обновляет stats cache; targeted URL run обновляет stats cache, но намеренно не запускает глобальное обслуживание каталога.
9. Новый тайтл получает `published/public`, но повторный import не меняет локальные publication status, audience, availability window, soft delete или slug. Публичный интерфейс всё равно повторно применяет `CatalogEntitlementService`.

Полный sitemap import имеет одну lifecycle start-boundary независимо от способа выполнения. `SeasonvarGlobalImportRunCoordinator` под коротким distributed lock ищет active `queued/running` sitemap-run среди `sync` и `queue`; новый sync run резервируется до входа в pipeline, а повторный CLI/admin/cron start безопасно переиспользует существующую строку без второго dispatch. Lock не удерживается во время HTTP, parsing или catalog writes. Targeted URL import, `--inventory-only` и `--status` не являются полным global cycle и сохраняют независимый контракт.

## Retention и секреты

Владелец технического retention — production operator runbook Seasonvar. `SeasonvarImportStorageMaintenance` применяет только существующие bounded окна: import events — 7 дней, source snapshots — 14 дней, terminal prepared groups — 7 дней через `SEASONVAR_IMPORT_*_RETENTION_DAYS`; при очистке snapshots всегда сохраняется детерминированная последняя копия. Failed jobs автоматически не удаляются, а пользовательская история, legal/admin audit и другие продуктовые данные не входят в importer prune без отдельной policy.

Provider credentials, DRM/license secrets и их browser delivery в importer не реализованы и не являются скрытым backlog. Queue/staging сохраняют только IDs, allowlisted hashes/status и bounded подготовленные данные; credentials, raw provider payload и URL в job diagnostics не кладутся.

## Инвентаризация source parity

`php artisan seasonvar:import --inventory-only` использует тот же configured sitemap, один экземпляр `PoliteHttpClient`, правила `robots.txt`, максимальный из configured/robots crawl delay, HTTPS/host/path allowlist и `SourcePage` bulk-upsert, но останавливается до catalog parser. Индекс и дочерние sitemap читаются рекурсивно, включая gzip XML; child failure делает run `failed`, поэтому частичный обход не выдаётся за успешный снимок. Player, playlist, media и страницы сериалов не запрашиваются. PHPUnit задаёт отдельный `seasonvar.sitemap_storage_directory`, поэтому очистка тестового XML-зеркала не затрагивает рабочий inventory.

`SeasonvarPageType` является единственным enum типов, `SeasonvarUrl` канонизирует host/path/query identity и сохраняет неизвестные разрешённые пути как `unknown`, а `SeasonvarSourceParityRegistry` описывает возможности discovery/storage/parser/public route/local sitemap. Результат `SeasonvarSourceInventoryResult` хранится в `SeasonvarImportRun.summary.source_inventory`; события содержат только counts и очищенные ошибки. Повторный запуск не создаёт дубли по `url_hash`, не обновляет неизменённые source rows и не переводит ранее разобранный serial обратно в pending. Подтверждённый снимок и юридическая граница находятся в [`SOURCE_PARITY.md`](SOURCE_PARITY.md).

## Обработчики типов страниц

`SeasonvarPageHandlerRegistry` — единственный runtime registry. Каждый handler объявляет тип, persistence при discovery, automatic parsing, metadata-only режим, parser/importer classes, retry behavior, expected result, возможность локальной страницы, класс доступа к источнику и независимое `publication_authorized`. Planner и `--page-type` читают этот registry; switch по свободным строкам в `SeasonvarCatalogImporter` отсутствует.

- `serial`: включён автоматически, сохраняет совместимый catalog parser/importer, сезоны внутри одного тайтла, additive relations и approved media behavior.
- `actor`, `genre`, `country`, `tag`: реализованы и покрыты fake-HTTP тестами, но defaults `enabled=false`, `automatic=false`, `publication_authorized=false`, потому что подтверждённый inventory 13.07.2026 не нашёл эти URL. Они не считаются подтверждёнными категориями источника.
- `rss`: включён как metadata-only freshness signal. Он не создаёт и не обновляет `CatalogTitle`, а только нормализует bounded serial links и делает существующие source pages eligible для следующего цикла.
- `static`, `search`, `sitemap`, `unknown`, а также неподтверждённые director/translation/status/network/studio: passive storage/audit; HTTP parse и локальная публикация отключены.

Taxonomy parser сохраняет только каноническое имя, source slug/URL, title, букву, безопасный count и ограниченный список serial URL. Большие описания не импортируются. `SeasonvarTaxonomyIdentity` нормализует HTML entities, Unicode, whitespace, punctuation, case и `ё/е`, а запись справочника проходит через общий `CatalogRelationNameSanitizer`: кириллическое/латинское написание одного имени переиспользует общий canonical slug. `CatalogTaxonomyRegistry` остаётся authority model/relation mapping. Первый пригодный taxonomy `source_url` остаётся provenance и связывается с `SourcePage.url_hash`; hash этого канонического URL дополнительно закрепляется в `CatalogRelationSourceIdentity`, поэтому та же metadata page не создаёт новую строку после смены display name. Crawl/parser timestamps, content hash, missing flags и события принадлежат `SourcePage`; metadata/RSS snapshots не содержат исходный HTML/XML prose.

`PoliteHttpClient` принимает только allowlisted conditional headers; `SeasonvarSourcePageFetcher` отправляет ETag/Last-Modified для уже parsed pages и обрабатывает 304 без повторного импорта. Связанные serial URLs ограничены `SEASONVAR_IMPORT_MAX_LINKED_SERIAL_URLS` и получают defer из `SEASONVAR_IMPORT_LINKED_SERIAL_DEFER_MINUTES`, чтобы не создавать рекурсивный crawl в текущем цикле.

Serial fetcher и parser распознают точное provider-сообщение «По просьбе правообладателя, сезон заблокирован для вашей страны» как `provider_availability_status=region_blocked`. `SourcePage` хранит нормализованный статус и время проверки отдельно от локального playback entitlement; raw provider message не копируется в отдельное поле. Полный sync/queued finalizer ограниченно разбирает уже сохранённые latest snapshots без HTTP, а due-страницы повторно выбираются штатным planner после `SEASONVAR_PROVIDER_AVAILABILITY_RETRY_HOURS` (default `168`). Proxy/Tor и подмена региона не являются частью importer boundary.

Для controlled rollout задаются `SEASONVAR_PAGE_<TYPE>_ENABLED`, `..._AUTOMATIC`, `..._REFRESH_HOURS`, `..._CHUNK_SIZE` и для публикуемых типов `..._PUBLICATION_AUTHORIZED`. После изменения environment нужно пересобрать config cache и перезапустить workers. Пример ручной проверки уже разрешённого типа: `php artisan seasonvar:import --no-discovery --page-type=actor`.

## Режимы длительного запуска

Репозиторий поддерживает два взаимоисключающих production-профиля. `seasonvar-import-forever.service` непрерывно выполняет sitemap discovery и весь sync pipeline одним PHP-процессом. Redis queued-профиль использует cron dispatcher и отдельные import/title-refresh workers для параллельной обработки. Одновременный запуск профилей запрещён: application-level single-flight предотвращает создание нового пересекающегося global run, но не заменяет корректное отключение лишнего systemd/cron profile и не завершает уже существовавший run автоматически.

Удалённый после dispatch `CatalogTitle` считается нормальным устаревшим targeted job: refresh state очищается, import group не создаётся, exception и retry не нужны. Absolute retry deadline preparation и group finalizer jobs равен максимуму configured retry window и page claim lease; поэтому живой 24-часовой claim не переживает job, который должен его дождаться или завершить группу.

Установка и безопасное переключение systemd-профилей описаны в [`deployment.md`](deployment.md), queue lifecycle — в [`queues.md`](queues.md).

## Queue coordinator и статусы

Sync CLI/legacy wrapper, `/admin/imports`, cron/CLI `--queued` и retry используют общий `SeasonvarGlobalImportRunCoordinator`. Atomic start-lock охватывает active lookup и вставку: пока существует глобальный `sitemap` run любого execution mode в `queued/running`, повторный вызов возвращает его и не создаёт run, page jobs или title groups. Sync path заранее создаёт running reservation и передаёт её в pipeline без второй audit-строки. Targeted `mode=url` refresh не владеет этой глобальной границей и не блокируется. Новый `queued` run создаётся короткой transaction; `StartSeasonvarQueuedImport` получает только scalar run ID. Coordinator job имеет 3 attempts, backoff 60/300/900 секунд, timeout 900 секунд и unique lock на run. Transient network/408/425/429/5xx/SQLite-lock ошибки возвращают run в `queued` для retry; permanent validation/provider errors переводят его в `failed` без бесполезного повтора.

После начального SSR открытие видимой карточки в browser вызывает `wire:init` и может поставить один forced targeted refresh, если последнее успешное обновление было более 15 минут назад. SSR, crawler и клиент без JavaScript не создают import job. Browser передаёт только locked ID тайтла: URL читается из базы, нормализуется и повторно проверяется по HTTPS allowlist `seasonvar.ru`. Coordinator немедленно создаёт title group и dispatches по одному preparation job на каноническую и каждую известную прямую страницу сезона — без chunk/max-pages limit. Динамически найденные страницы добавляются в ту же группу до закрытия discovery, а посетители разных тайтлов создают независимые группы. После fan-in один finalizer применяет payload в стабильном порядке и записывает все сезоны и серии внутрь того же `CatalogTitle`. Новое 15-минутное окно начинается только после `completed`; `partial/failed` не считаются свежим обновлением.

Preparation выполняет HTTP, parser и проверку внешних media до catalog write; durable payload не содержит credentials и не требует повторного сетевого запроса при apply/retry. Finalizer строит общий source manifest и не удаляет локальные сезоны, серии или media, которых нет в provider snapshot. Ошибка или warning отдельной страницы делает группу `partial`, сохраняя успешно подготовленные данные; exact verified duplicates сезонного семейства объединяются с сохранением slug redirects. Global queued importer использует тот же group pipeline, но после всех terminal groups дополнительно выполняет catalog-wide maintenance, media checks, merge, recommendations и stats refresh.

Queue jobs принимают только IDs, lease token, canonical group key и force flag. Provider HTML/JSON, credentials и URLs в queue payload не кладутся. Preparation после release claim посылает deduplicated group signal; terminal group посылает global signal. Оба finalizer реализуют `ShouldBeUniqueUntilProcessing`: ожидающий job удерживает unique key, перед обработкой key освобождается, поэтому более поздний terminal event может поставить следующий wake-up. Если siblings/claims ещё живы, finalizer обновляет heartbeat и завершает job без `release()`/polling; это не расходует queue attempts. Redis group/apply lock защищает единственное применение. Global finalizer аналогично ждёт terminal state всех групп, а Redis lock `seasonvar-import-finalizer` сериализует catalog-wide cleanup, media maintenance, merge и recommendation rebuild между runs; только редкая конкуренция за apply/global lock использует delayed release.

Уникальный `WakeSeasonvarImportFinalizers` каждые десять минут страхует потерянный terminal signal. Watchdog ограничен `SEASONVAR_QUEUE_FINALIZER_WATCHDOG_BATCH_SIZE` (default 250), выбирает active queue-runs, counter-ready группы и active группы старше полного retry/claim окна плюс 300 секунд, затем повторно dispatches те же unique finalizers. Он не импортирует страницы, не меняет page/group state, не очищает очередь и не делает незавершённую группу terminal. Ошибка постановки terminal signal не откатывает уже успешно подготовленную/импортированную страницу: в журнал попадают только IDs и класс ошибки, а следующий initial signal/watchdog восстанавливает fan-in. Все persisted queue exception details по-прежнему проходят sanitizer, который убирает credentials, URLs и filesystem paths.

Stale reconciliation выполняет сам finalizer в короткой transaction и повторно проверяет active run, timestamp и отсутствие live claim. `queued/preparing` rows считаются необратимо потерянными только после `max(retry_window, claim_window) + 300s`; они получают `preparation_deadline_exceeded`, а подготовленные siblings всё равно применяются с итогом `partial`. Нулевой/неполный page set, отсутствие любой применимой страницы и исчерпание finalizer deadline завершаются `failed` с allowlisted codes `empty_page_set`, `page_set_mismatch`, `no_prepared_pages` или `finalizer_deadline_exceeded`. Код хранится отдельно от user-safe русского сообщения; raw exception, URL, HTML и queue payload туда не попадают. Terminal/fresh группы и live claims не изменяются.

Все восемь importer jobs имеют явные `tries` или абсолютный `retryUntil`, timeout, backoff, uniqueness strategy и `failed()` boundary. Три coordinator/watchdog jobs ограничены числом attempts, пять page/preparation/finalizer/refresh jobs — временем; максимальный timeout 900 секунд остаётся ниже queue `retry_after=1200`. Page delivery намеренно не получает дополнительный unique lock: её idempotent ownership задаёт claim token. Watchdog при окончательном сбое логирует только стабильный job code и класс исключения без exception message, queue payload, URL или token.

Historical failed finalizers не являются частью live state machine. Read-only `app:failed-job-audit` bounded-порциями проверяет exact scalar target ID без `unserialize()`, сопоставляет его с current group/run/claim state и выдаёт только безопасные disposition codes. `canonical_signal_candidate` обрабатывает существующий watchdog; команда не retry-ит старый envelope, не dispatch-ит новый job, не забывает и не очищает строки.

Счётчики admin UI — операционные: `created` объединяет новые source pages и media rows, `updated` — успешно обработанные source pages и обновлённые media, `skipped` — необработанные выбранные pages и пропущенные media, `failed` — page/media failures. Это не точное число созданных Eloquent entities: текущая pipeline не хранит entity-level deltas, а выдавать их приближённо было бы некорректно.

## Здоровье видеоисточников

`SeasonvarMediaAvailabilityChecker` возвращает нормализованный результат без provider body/URL, а `MediaSourceHealthManager` атомарно применяет его к одной строке `licensed_media`. Health-check не выполняется внутри catalog transaction. В queued-режиме due backlog обрабатывается finalizer job; отдельного Laravel scheduler для этого нет, потому что production cron уже запускает единый dispatcher `seasonvar:import --queued` десять раз в сутки.

Один full cycle или queued finalizer проверяет не больше `SEASONVAR_MEDIA_CHECK_MAX_PER_CYCLE` due-строк в стабильном порядке `id`; default `20` оставляет запас внутри 900-секундного worker timeout даже при трёх 10-секундных попытках на URL. `SEASONVAR_MEDIA_CHECK_CHUNK_SIZE` управляет только размером чтения и памяти, а не снимает hard cap. Непроверенный остаток сохраняет `next_check_at` и остаётся due для следующих запусков. Увеличивать cap следует только после измерения provider latency и вместе с проверкой worker timeout/retry policy.

- `active` — последняя проверка успешна; источник участвует в playback и counts.
- `degraded` — временная ошибка ещё не достигла порога; источник остаётся кандидатом, а resolver может выбрать резервный вариант.
- `unavailable` — постоянная ошибка или достигнутый failure threshold; источник исключён из playback/counts до успешной перепроверки.
- `disabled` — ручное операционное отключение; автоматические проверки и recovery не меняют его.

Каждый результат сохраняет `checked_at`, `last_successful_check_at`, bounded `check_latency_ms`, allowlisted `last_error_category`, `consecutive_failures` и `next_check_at`. Timeout/connection/408/425/429/5xx используют exponential retry; redirect, invalid URL, 401/403, 404/410, oversized response и invalid manifest сразу дают `unavailable`, но всё равно получают будущую перепроверку. Один timeout по умолчанию даёт только `degraded`; успешный ответ сбрасывает счётчик и возвращает `active`.

Retry не воскрешает старую строку: создаётся новый run с `retry_of_run_id`, поэтому audit trail и счётчики прошлой попытки не переписываются. Idempotent upsert/identity/lease-границы не дублируют каталог. Cancel освобождает claims и запрещает новым jobs начинать; текущий HTTP/transaction step не прерывается посредине.

## Идентичность и идемпотентность

- Тайтл: стабильный provider ID внутри `Source`; fallback при отсутствии ID — точный канонический URL hash/source page. Совпадение или похожесть названия не объединяет строки.
- Legacy merge допускается для строк без `external_id`, если `SeasonvarImportGroupKey` подтверждает одну каноническую URL family. Seasonvar выдаёт разные provider ID страницам сезонов одного сериала, поэтому глобальный finalizer также объединяет строки с непустыми ID, но только при одновременном совпадении source/type, нормализованного названия, канонической URL family и хотя бы одного точного `seasons.source_url_hash`; простого совпадения названия или похожего URL недостаточно.
- Все десять справочников используют `CatalogRelationNameSanitizer` и уникальный canonical slug. Для актёров и режиссёров ключ строится после транслитерации, поэтому эквивалентные кириллическое и латинское написания объединяются; интерфейс сохраняет кириллическую подпись, если она доступна. Provider URL хранится как provenance первого пригодного наблюдения, но не разделяет одинаковый canonical key.
- `CatalogRelationSourceIdentity` хранит только `source_id`, relation type, SHA-256 namespaced external ID/канонического HTTPS URL и закреплённый canonical key. Первое решение защищено unique constraint и не перезаписывается новым provider label; raw external ID/URL в реестре отсутствуют.
- Жанры, страны, переводы, статусы, сети, студии, теги и возрастные рейтинги идентифицируются тем же нормализованным slug без fuzzy matching.
- Сезоны и серии используют существующие unique `(catalog_title_id, kind, number)` и `(season_id, kind, number)`. Pivot primary keys, alias/rating/review/recommendation unique keys и `licensed_media(catalog_title_id, source_media_key)` запрещают повторные строки.

Индекс `actors.source_url`/`directors.source_url` остаётся lookup-индексом provenance, а не unique identity constraint: один URL может быть повреждён у источника, а одно лицо может прийти из нескольких источников. Конкурентную защиту обеспечивает существующая уникальность canonical `slug`.

`CatalogRelationSyncer` — общая транзакционная граница для будущих importer adapters: он принимает optional `source_id` и `source_external_id`, проверяет HTTPS source URL, сначала восстанавливает закреплённый canonical key, затем выполняет bulk `upsert`/`syncWithoutDetaching`. Без override используется `CatalogTitle.source_id`; adapter обогащения из другого источника обязан передать собственный source ID. При первом refresh старой строки точный сохранённый provenance URL выбирается до создания identity, поэтому отдельный полный backfill production-таблиц не нужен. `SeasonvarCatalogRelationSyncer` перед делегированием дополнительно нормализует URL и разрешает только `seasonvar.ru`.

Full cycle и queued finalizer запускают `CatalogMetadataDeduplicator`: сервис через временную SQLite identity-таблицу и bounded chunks переносит pivot-связи, удаляет мусор и дубли во всех десяти справочниках, синхронизирует поисковые документы и остаётся идемпотентным. Source identities проходят собственный двухфазный staging вместе со slug, поэтому старый slug одной записи, совпадающий с новым slug другой, не перетягивает provider mappings; identities удалённого мусора очищаются после каждого типа.

## Владение полями

Provider владеет external/source IDs, URL/hash, crawl/import metadata, импортными рейтингами/отзывами/связями, release metadata, технической доступностью источника и вычисленным типом публикации. Тип определяется только из явного source-жанра с приоритетом `аниме` → `реалити-шоу` → `документальные` → обычный сериал. Локальная редакция владеет slug, publication status, audience, availability window и soft delete.

Parser boundary принимает реальные source-названия длиной до 500 Unicode-символов: это покрывает сохранённые в текущем каталоге названия длиной до 301 символа без молчаливого обрезания.

Для `title`, `original_title`, `type`, `description` и `poster_url` используется безопасное трёхстороннее сравнение. `provider_field_values` хранит последний вход provider. Новое значение принимается, только если текущее поле пусто или всё ещё равно предыдущему provider value; отличающееся локальное значение сохраняется. Null/пустой provider value не стирает заполненное поле. У уже существующей строки без baseline первый import сохраняет текущее значение и только фиксирует provider baseline; исключение — прежний искусственный `type=serial`, который parser v5 один раз заменяет подтверждённым source-жанром. Snapshot без принятого genre taxonomy не меняет тип и его baseline.

## Полные и частичные ответы

Parser фиксирует признаки `has_info_list`, `has_season_list` и `has_episode_script`. Отсутствие блока означает частичный ответ, а не удаление данных. Связи синхронизируются через additive `syncWithoutDetaching`, сезоны/серии/media только upsert-ятся, soft-deleted строки не восстанавливаются. Managed recommendation signals заменяются только при полном metadata snapshot. Политики удаления по complete snapshot пока нет; importer ничего не удаляет только из-за отсутствия записи в одном ответе.

## Версионированное восстановление metadata

`SeasonvarCatalogParser::METADATA_VERSION` задаёт текущую версию разбора связей и производного типа публикации. Версия 5 исправляет legacy-классификацию `serial` локальным повторным разбором сохранённых HTML snapshots только при наличии принятого source-жанра и не понижает тип по частичной странице. `source_pages` отдельно хранят успешно применённую и уже предпринятую версии, время разбора и allowlisted `metadata_presence`; `catalog_titles.relation_metadata_version` показывает версию производных данных тайтла. Поэтому изменение parser не требует повторно скачивать весь источник.

В начале полного цикла и queued finalizer `SeasonvarCatalogMetadataBackfill` разбирает только сохранённый `latestSnapshot` и не выполняет HTTP-запросов. Один запуск ограничен `SEASONVAR_METADATA_BACKFILL_PAGE_LIMIT` и `SEASONVAR_METADATA_BACKFILL_TITLE_LIMIT`; chunk-параметры управляют памятью. Валидный snapshot применяет связи и версии в одной transaction. Детерминированно невалидный snapshot продвигает только attempted version, чтобы одна строка не блокировала очередь; инфраструктурная ошибка не продвигает версии. Планировщик назначает `stale_metadata` только старой странице без пригодной ещё не предпринятой локальной копии и сохраняет обычную границу `refresh_after`.

Retention всегда оставляет snapshot с максимальным `captured_at`, а при равенстве — с максимальным `id`. При merge сохраняется минимальная relation version, чтобы устаревшие связи не маскировались актуальной канонической строкой. В `metadata_presence` допустимы только `present`, `rejected_invalid` и `absent_in_source`; raw provider values туда не попадают.

## Пересборка рекомендаций

Алгоритм `v3` выполняет полную локальную пересборку без HTTP: хранит профили компактно, извлекает из названия и описания ограниченный словарь тем и строит bounded candidate pool по темам и информативным связям. Тема, тег, режиссёр, актёр, сеть или студия обязательны как сильное совпадение; общий жанр, страна, год и качество кандидата сами по себе не проводят пару через relevance gate. Вклад связи и темы умножается на ограниченный коэффициент обратной частоты, поэтому редкий общий признак важнее массового жанра. После точного scoring небольшой MMR-штраф разводит повторяющиеся результаты, не меняя самый релевантный первый тайтл. Rating, отзывы и опубликованное видео остаются только ограниченным quality tie-breaker; candidate без доступного опубликованного media не сохраняется.

Candidate maps используют packed IDs для жанров, тегов, режиссёров, актёров, сетей, студий, отдельных тем и пар `тема+страна`/`тема+жанр`. `SEASONVAR_RECOMMENDATION_CANDIDATE_LIMIT` ограничивает число профилей для точного scoring одного тайтла, `SEASONVAR_RECOMMENDATION_CANDIDATE_SCAN_PER_FEATURE` — детерминированную выборку одного признака, `SEASONVAR_RECOMMENDATION_DIVERSITY_PENALTY` — мягкий MMR-штраф, а `SEASONVAR_RECOMMENDATION_MAX_PER_TITLE` — итоговый список. Пересборка по-прежнему выполняется один раз в full cycle или queued finalizer через единственную публичную команду `php artisan seasonvar:import`; targeted URL import её не запускает.

Перед записью каждого набора builder повторно проверяет source и candidates через public boundary уже внутри write-транзакции. Это защищает длинный compact profile snapshot от concurrent targeted merge/delete: исчезнувшие ID отбрасываются, оставшиеся ranks перенумеровываются, а один внешний ключ не обрывает catalog-wide rebuild.

## Порядок деплоя

1. Дождаться завершения активных import jobs и сделать backup SQLite.
2. Применить additive migrations через `php artisan migrate --force`, включая `2026_07_13_021800_add_health_state_to_licensed_media_table`, `2026_07_13_171455_create_catalog_relation_source_identities_table` и admin/import identity migrations по timestamp.
3. Health migration добавляет поля с безопасными defaults, backfill-ит прошлый `available` в `active`, известные failures в `unavailable` и ставит существующие проверенные строки в due backlog без удаления/merge данных.
4. Задать `SEASONVAR_IMPORT_ADMIN_EMAILS` и `SEASONVAR_MEDIA_CHECK_*`, пересобрать config cache и перезапустить queue workers через `php artisan queue:restart`.
5. Выполнить targeted repeat import на тестовой/проверочной странице и сверить counts/relations/health до и после.

Пока additive migration реестра ожидает применения, `CatalogRelationSourceIdentityRegistry` fail-open возвращает canonical fallback и не обращается к отсутствующей таблице: уже запущенные workers не падают, но новые source mappings в этот период не закрепляются. Это только rolling-deploy совместимость, а не замена migration; после её применения обязателен штатный `queue:restart` из шага 4.

Все указанные migrations additive и не делают удалений. Admin fields nullable и не требуют backfill; отсутствие editorial baseline намеренно заставляет первый repeat import считать существующее заполненное поле потенциально редакционным.

## Импортные и пользовательские отзывы

`catalog_title_reviews` remains one table. Seasonvar importer owns only `origin=provider` rows and continues to upsert by `(catalog_title_id,body_hash)` with source page, provider author, plain body and publication date. It never assigns portal `user_id`, title/spoiler/verified/moderation ownership/submission fields, never creates helpful votes and never changes `catalog_title_user_states`.

Recommendation v4 reads the published/non-deleted/non-merged provider+user review count only during its existing full import rebuild. Community review writes never invoke that catalog-wide job synchronously and do not add a queue/scheduler; they invalidate the existing read namespace, while persisted ordering/reasons converge on the next scheduled or explicit `seasonvar:import` rebuild. Review text, author identity, votes and personal rating are not recommendation features.

The primary review migration uses provider/published defaults, and idempotent repair `235100` only converges four missing lifecycle columns plus nullable report dedup, so existing importer SQL and 73 101 audited rows remain compatible before/after rollout. `ReviewSchema` keeps the legacy API/read path active during partial deploy. Provider bodies may exceed community limits and are preserved byte-semantically; community value-object validation is not retroactively applied to imported content.

`SeasonvarTitleMerger` invokes `ReviewMergeService` before duplicate hierarchy deletion. Review identity, source/body/date, votes/reports/status/deletion evidence and direct aliases survive; exact body-hash collisions are archived with preserved original hash instead of hard-deleted. Import never downloads video or translates/rewrites user reviews and review creation never changes importer state.

## Каноническая синхронизация тегов

`TagImportSynchronizer` — единственная tag-specific mapping/assignment boundary внутри существующего Seasonvar relation pipeline. Provider label сначала очищается и Unicode-normalize-ится, provider identity строится из allowlisted normalized Seasonvar tag URL/source key, а `tag_provider_mappings` сохраняет raw spelling/provenance отдельно от canonical public label. Raw provider HTML, credentials и arbitrary external host не принимаются; URL обязан оставаться под `https://seasonvar.ru/`.

Legacy reviewed tags/pivots backfill-ятся как approved mappings/current provenance, не переименовывая `tags.id/name/slug/source_url` и не меняя `catalog_title_tag`. Новая неизвестная provider identity получает pending mapping/candidate и не становится public автоматически. Approved mapping может назначать eligible canonical tag; rejected mapping помнит решение и не возрождается retry. Imported label auto-refresh допустим только для pending imported Seasonvar-owned row и никогда не перезаписывает system/editorial correction, translation, hidden/archive/rejected state.

Для успешно разобранного полного набора одного title/provider sync upsert-ит current observations, затем помечает отсутствующие прежние observations non-current. Aggregate pivot снимается только если для tag/title не осталось другого current source; explicit editorial assignment/suppression и unrelated provider source сохраняются. Partial/error snapshot не выполняет stale convergence. Unique mapping/provenance/pivot keys и material-change comparison делают повторный импорт идемпотентным; cache/search/recommendation dependencies invalidated только при фактическом изменении.

`SeasonvarTitleMerger` до удаления duplicate вызывает `TagTitleMergeService`: global pivots/provenance и owner-scoped personal pivots переходят на canonical title с `insertOrIgnore`, duplicate positions reconcile-ятся детерминированно. Ни tag merge, ни title merge не создают отдельные season/episode tags и не изменяют progress/watchlist/history/collections/comments/reviews.

Tag migrations применяются в порядке `230000` → `230050` → `230060` → `230075` → `230100` → `230200`: schema, archive pre-state, public-query index, normalization repair, mapping/provenance backfill, exact duplicate reconciliation/unique hash. Перед production rollout обязательны backup, terminal importer, остановка writers/workers, disposable rehearsal/query inspection, `migrate --force`, cache/config refresh и worker restart. После принятия новых personal/translation/alias/provenance data rollback требует export/verified database restore, а не destructive drop.
