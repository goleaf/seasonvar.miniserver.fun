# Связи данных и фильтры

Обновлено: 16.07.2026

## Основные связи

- `SeasonvarImportRun belongsTo User` через nullable `requested_by_user_id`; CLI/cron runs остаются без requester, а удаление user обнуляет ссылку.
- `SeasonvarImportRun belongsTo SeasonvarImportRun` через nullable `retry_of_run_id`; retry создаёт новую audit-строку. `last_heartbeat_at` и index `(execution_mode, status, last_heartbeat_at)` питают bounded stale recovery.
- `SeasonvarImportRun hasMany SeasonvarImportTitleGroup`; одна группа соответствует одному каноническому сезонному семейству внутри конкретного запуска.
- `SeasonvarImportTitleGroup belongsTo CatalogTitle` через nullable `catalog_title_id`, потому что первый подготовленный payload может создать тайтл только на стадии fan-in.
- `SeasonvarImportTitleGroup hasMany SeasonvarImportPreparedPage`; unique `(seasonvar_import_title_group_id, source_page_id)` исключает повторную подготовку одной страницы в группе.
- `SeasonvarImportTitleGroup.terminal_reason_code` nullable и хранит только allowlisted code из `SeasonvarImportTitleGroupTerminalReason`; русское безопасное объяснение вычисляется enum, а не сохраняется как идентификатор. Индекс `(status, updated_at, id)` обслуживает десятиминутный bounded watchdog для counter-ready/stale active групп; сам reason code не индексируется, потому что по нему нет runtime eligibility query.
- `SeasonvarImportPreparedPage belongsTo SourcePage` и хранит нормализованный parser payload, предупреждения, content hash и статусы `queued/preparing/prepared/applied/failed`, но не становится источником истины каталога.

- `CatalogTitle belongsTo Source`
- `CatalogTitle belongsTo SourcePage`
- `CatalogTitle hasMany Season`
- `CatalogTitle hasManyThrough Episode`
- `CatalogTitle hasMany LicensedMedia`
- `CatalogTitle hasMany CatalogTitleAlias`
- `CatalogTitle hasMany CatalogTitleSlug` для прежних публичных адресов
- `CatalogTitle hasMany CatalogTitleRating`
- `CatalogTitle hasMany CatalogTitleReview`
- `CatalogTitle hasMany SeasonvarImportEvent`
- `CatalogTitle hasMany CatalogTitleUserState`
- `CatalogTitle hasMany EpisodeViewProgress`
- `CatalogTitle belongsToMany Genre`
- `CatalogTitle belongsToMany Country`
- `CatalogTitle belongsToMany Actor`
- `CatalogTitle belongsToMany Director`
- `CatalogTitle belongsToMany AgeRating`
- `CatalogTitle belongsToMany Translation`
- `CatalogTitle belongsToMany CatalogStatus`
- `CatalogTitle belongsToMany Network`
- `CatalogTitle belongsToMany Studio`
- `CatalogTitle belongsToMany Tag`
- `Season belongsTo CatalogTitle`
- `Season belongsTo SourcePage`
- `Season hasMany Episode`
- `Episode belongsTo Season`
- `Episode belongsTo SourcePage`
- `Episode hasMany EpisodeViewProgress`
- `LicensedMedia belongsTo CatalogTitle`
- `LicensedMedia belongsTo Season`
- `LicensedMedia belongsTo Episode`
- `EpisodeViewProgress belongsTo LicensedMedia` как последний подтверждённый playback source; связь nullable, удаление media не удаляет историю.
- `SourcePage belongsTo Source`
- `CatalogRelationSourceIdentity belongsTo Source`; source хранит много таких hash-only наблюдений для десяти явных справочников.
- `SourcePage hasOne CatalogTitle`
- `SourcePage hasMany Season`
- `SourcePage hasMany Episode`
- `SourcePage hasMany CatalogTitleReview`
- `SourcePage hasMany SourcePageSnapshot`
- `SourcePage hasMany SeasonvarImportEvent`
- `SourcePage belongsTo SeasonvarImportRun` через `last_import_run_id`
- `SourcePage.page_type` хранит строковое значение `SeasonvarPageType`; inventory может добавить разрешённый неизвестный или ещё не разбираемый URL, но никогда не меняет `parse_status`/`import_status` уже существующей строки. Sitemap-документы также хранятся как source pages для полного audit trail и не попадают в serial parser queue.
- `SourcePage.provider_availability_status=region_blocked` означает, что Seasonvar сам вернул сообщение о блокировке сезона правообладателем для региона исходящего сервера. `provider_availability_checked_at` фиксирует время нормализованной проверки; это page-specific provider observation, а не пользовательский `PlaybackAvailability::RegionBlocked` и не доказательство доступности в других странах.
- Crawl provenance metadata-страницы остаётся в `SourcePage`: URL/hash, ETag/Last-Modified, content hash, crawl/import/parse timestamps, missing flags и import events. `catalog_relation_source_identities` отдельно хранит только `(source_id, relation_type, source_key_hash) -> canonical_key`, чтобы повторный provider object после смены подписи возвращался к прежней строке; raw external ID и raw URL туда не записываются. `SourcePageSnapshot` для non-serial не хранит исходную страницу или описательный текст, а только безопасную hash-сводку; serial snapshot остаётся полным из-за существующего локального metadata-backfill.
- `SeasonvarImportRun hasMany SeasonvarImportEvent`
- `SeasonvarImportRun hasMany SourcePageSnapshot`
- `SeasonvarImportRun hasMany SourcePage` через `last_import_run_id`
- Каждая модель связи каталога относится ко многим `CatalogTitle` через явную pivot-таблицу.
- `User hasMany CatalogTitleUserState` и `User hasMany EpisodeViewProgress`; unique `(user_id, catalog_title_id)` хранит одну запись списка просмотра и внутренней пользовательской оценки, unique `(user_id, episode_id)` — одну каноническую позицию выпуска. Отдельной таблицы favorites нет: в текущем продукте «избранное» означает тот же список просмотра. `CatalogTitleRating` остаётся импортной provider-оценкой и не участвует во внутреннем среднем; editorial rating в модели отсутствует и не симулируется. Отдельной profile table нет: `/profile` редактирует имя/email текущего `User`, а все library rows принадлежат ему напрямую. Progress дополнительно хранит trusted duration/percent, первый и последний просмотр, неизменяемый `completed_at`, source media, ULID активной playback session и последний принятый event sequence.
- `User morphMany PersonalAccessToken` через Sanctum. Каждая строка хранит SHA-256 hash, device name, abilities, last-use и expiry; plaintext существует только в issuance/rotation response. Device list и delete всегда начинаются с relation текущего пользователя, поэтому token другого user не может быть адресован по глобальному ID.
- Web `/library/*`, mobile watchlist/ratings API и library summary проецируют существующий `CatalogTitleUserState`, а Continue Watching/history — существующий `EpisodeViewProgress`; дополнительных web/mobile/favorites/history/summary tables нет. Library reads пересекают owner rows с текущей видимостью тайтла. Summary считает четыре owner-scoped раздела теми же query boundaries и возвращает последний `last_watched_at`. History сохраняет недоступную старую строку только как safe summary с `is_accessible=false`; playback entitlement из неё не восстанавливается.
- `User hasMany ApiSyncChange` и `User hasMany ApiSyncMutation`; обе owner-scoped связи удаляются cascade вместе с аккаунтом. `api_sync_changes` также хранит публичные catalog entries с `user_id=null`: monotonic `id`, scope, resource type/public key до 512 символов, `upsert|delete|clear` и `changed_at`. Этот запас вмещает canonical slug тайтла до 255 символов и составной progress key; это журнал invalidations, а не копия тайтла или пользовательского payload.
- `api_sync_mutations` имеет unique `(user_id, mutation_id)` и хранит canonical payload hash, safe result/status и timestamps для идемпотентного replay. `catalog_title_user_states.watchlist_version` и `rating_version` начинаются с `0` и независимо увеличиваются только при фактическом изменении соответствующего desired state; они не являются глобальными версиями тайтла.
- Catalog/user changes старше 30 дней и mutation receipts старше 90 дней удаляет `api:sync-prune` ordered primary-key пачками до 500. Эти окна относятся только к transport-журналу синхронизации: canonical `CatalogTitleUserState`, `EpisodeViewProgress`, imported catalog data и admin audit ими не удаляются.
- Mobile playback session и delivery grant не создают таблиц: это короткоживущие encrypted/signed transport tokens. Grant содержит только version, nullable user ID, media ID и expiry; progress token ссылается на существующий user/title/episode/media, а каноническая позиция продолжает храниться только в `EpisodeViewProgress`.
- Удаление mobile аккаунта внутри одной транзакции явно удаляет personal access tokens, password-reset rows и database sessions, затем `User`. FK `cascadeOnDelete` удаляет `catalog_title_user_states` и `episode_view_progress`; `CatalogTitle`, seasons/episodes и импортные metadata не принадлежат пользователю и сохраняются.
- `AdminAuditEvent belongsTo User` через обязательный `actor_id`; resource хранится как закрытая allowlist-пара `resource_type/resource_id`, а не polymorphic relation. Строка содержит только action, version fingerprints и имена изменённых полей без их значений или source/provider payload.
- Viewing History не имеет отдельной таблицы: фактической активностью считается `EpisodeViewProgress.first_started_at IS NOT NULL`. Удаление из истории удаляет каноническую user/episode строку и одновременно сбрасывает Continue Watching для этого выпуска.
- Для метаданных каталога не используются morph- или polymorphic-связи.

## Целостность выпуска и публикации

- `CatalogStatus` через `catalog_status_catalog_title` описывает production status источника (`выходит`, `завершён` и подобные значения) и не управляет публичной видимостью.
- `publication_status` у `CatalogTitle`, `Season` и `Episode` использует `draft`, `published` или `hidden`; публичный scope дополнительно проверяет `available_from`, `available_until`, `audience` и `deleted_at`.
- `audience=public` доступна гостю, `audience=authenticated` — только текущему `User`. Оба SQL scope и решение для загруженной модели формирует `CatalogEntitlementService`. Модели профилей/детских ограничений/PIN, ролей, подписок, покупок, trial, территориальных лицензий и concurrent streams отсутствуют и не симулируются; отдельной строки profile preferences для языка, субтитров или autoplay также нет.
- `CatalogTitle.is_published` временно сохраняется как legacy-совместимый второй защитный флаг. Публичный тайтл обязан одновременно иметь `is_published=true` и `publication_status=published`.
- Обычные сезоны и серии имеют `kind=regular`, спецвыпуски — `kind=special`. Unique-ключи `(catalog_title_id, kind, number)` и `(season_id, kind, number)` разрешают специальный и обычный выпуск с одним номером, но запрещают дубли внутри вида.
- Порядок сезонов и серий детерминирован: `kind`, `sort_order`, `number`, `id`; обычные выпуски идут до специальных и не перенумеровываются из-за specials.
- `CatalogTitle`, `Season`, `Episode` и `LicensedMedia` используют soft delete; merge импортёра применяет физическое удаление только к уже объединённым дублям, чтобы не оставлять конфликтующие provider keys.
- `catalog_title_slugs.slug` глобально уникален внутри истории. При редакционном изменении текущий slug записывается в историю, при возврате к прежнему адресу его historical row удаляется, а при importer merge slug дубля и его история переносятся к каноническому тайтлу. Исторический URL не обходит publication/access scope и отвечает `301` только на доступную текущую карточку.
- `CatalogTitle.provider_field_values` хранит только последний baseline импортируемых `title`, `original_title`, `description` и `poster_url`; это не публичная metadata и не источник отображения. Импорт меняет текущее поле лишь пока оно совпадает с предыдущим baseline, поэтому локальное редакционное значение не затирается.
- Автоматическая identity тайтла — `(source_id, external_id)`, при отсутствии provider ID — точный canonical URL hash/source page. Все десять справочников используют общий canonical slug; для актёров и режиссёров он строится после транслитерации, поэтому эквивалентные кириллическое/латинское написания разных источников сходятся в одну строку. Stable external ID или канонический HTTPS URL каждого источника дополнительно закрепляется hash-only строкой `CatalogRelationSourceIdentity`; следующий refresh переиспользует сохранённый canonical key даже после неэквивалентного переименования provider label. Fuzzy matching и slug suffix для provider identity не используются.
- Admin attaches metadata через `syncWithoutDetaching`, а importer relation sync использует ту же idempotent семантику: локально добавленные pivot rows не отсоединяются частичным или повторным provider import. Concurrent admin writes проверяют fingerprints редактируемых полей и relation IDs под row lock.
- Успешные admin mutations атомарно добавляют append-only `admin_audit_events`; application model запрещает update/delete, а FK actor использует restrict delete, чтобы история не исчезла каскадно. Importer и public user flows audit rows не создают.
- Публичные медиа проверяют собственный status/window/audience и доступность связанных сезона и серии. `LicensedMedia::forAvailableReleases()` не строит глобальные списки child IDs: ненулевые `season_id` и `episode_id` проверяются коррелированными `EXISTS` через точные primary keys, а серия дополнительно проверяет собственный сезон. `NULL`-связь сохраняет прежнюю семантику; publication/audience/window/soft-delete predicates по-прежнему принадлежат только `CatalogEntitlementService` и `availableTo($user)`.
- Playback дополнительно требует непустой `playback_url` или `path`; пустая опубликованная media row не делает серию доступной и не попадает в counts/primary action.

## Типы фильтров справочников

Эти типы справочников участвуют в фильтрах и должны иметь локальные страницы:

- `genre`
- `country`
- `actor`
- `director`
- `age_rating`
- `translation`
- `status`
- `network`
- `studio`
- `tag`

## Поведение фильтров

- `/titles/{type}/{taxonomy}` должен показывать только карточки, связанные с точным значением справочника.
- Directory index routes не создают новых taxonomy tables: десять relation-справочников используют модели/pivot из `CatalogTaxonomyRegistry`, а `/years` группирует валидные `catalog_titles.year` от `catalog.directories.minimum_year` до configured maximum. Активным считается только значение, связанное хотя бы с одним `CatalogTitleQuery::visibleTo(null)` тайтлом.
- Counts directory cards выполняются на pivot, присоединённом к подзапросу видимых title IDs, и используют `count(distinct catalog_title_id)`. Общий title count использует visibility-first `whereHas`, поэтому несколько связей не размножают total или paginator. Existing reverse pivot indexes `(taxonomy_id, catalog_title_id)` и public title indexes покрывают join; отдельная миграция не потребовалась.
- Canonical filmography/listing остаётся `/titles/{type}/{taxonomy}` и `/titles/year/{year}`. `/genres/{slug}`, `/countries/{slug}`, `/actors/{slug}`, `/directors/{slug}`, `/age-ratings/{slug}`, `/translations/{slug}`, `/statuses/{slug}`, `/networks/{slug}`, `/studios/{slug}`, `/tags/{slug}` и `/years/{year}` проверяют существование видимой связи и дают 301; неизвестное значение возвращает 404.
- `/titles` принимает повторяемые параметры `year[]`, `genre[]`, `actor[]`, `director[]`, `country[]`, `translation[]`, `status[]`, `network[]`, `studio[]`, `tag[]` и остальные типы из `CatalogFilterType`.
- Фиксированные multi-value группы передаются как `publication_type[]`, `quality[]` и `subtitles[]`; тип публикации не смешивается с `publication_status`, который остается границей публичной видимости.
- Несколько годов объединяются как допустимый набор годов выпуска: тайтл может совпасть с любым выбранным годом.
- Несколько значений одного справочника внутри `genre[]`, `actor[]`, `director[]` и других relation-фильтров объединяются через OR: достаточно совпадения с одним выбранным значением группы.
- Несколько разных query-фильтров объединяются через условие AND.
- Каждый пункт фильтра показывает только контекстное число публично доступных тайтлов при всех активных условиях, кроме собственной группы; отдельный глобальный счётчик для каждого значения не вычисляется.
- Синтаксически корректные, но уже отсутствующие slug справочников тихо отбрасываются; неподдерживаемые enum-значения показывают ошибку валидации и не должны откатываться к полной выдаче каталога.
- Фильтры года должны принимать четырехзначный год от 1900 до следующего года.
- Языки и отдельные аудиодорожки не фильтруются как самостоятельные сущности, потому что нормализованных таблиц для них нет. Текущая `Translation` представляет озвучку/перевод, а `licensed_media.has_subtitles` — только наличие субтитров.
- Locale интерфейса не используется как скрытая media preference: язык произведения, отдельные audio/subtitle language entities и profile preference в текущей схеме отсутствуют.

## Индексы запросов

Каталог зависит от этих индексов для стабильных фильтров и быстрого обновления:

- Обратные pivot-индексы каждой связи по связанному ID и `catalog_title_id` нужны для фильтров и рекомендаций.
- `catalog_titles_indexed_at_idx` по `indexed_at` нужен для списков новых карточек.
- `catalog_titles_year_indexed_idx` по `year, indexed_at` нужен для списков с фильтром года.
- `source_pages_status_type_id_idx` по `parse_status, page_type, id` нужен для выбора страниц источника из очереди.
- `source_pages_type_status_crawled_id_idx` по `page_type, parse_status, last_crawled_at, id` нужен для циклов обновления.
- `source_pages_provider_availability_retry_idx` по `provider_availability_status, retry_after_at, id` нужен для bounded повторной проверки provider-region блокировок.
- `licensed_media_title_status_published_idx` по `catalog_title_id, status, published_at` нужен для списков медиа карточки.
- `licensed_media_episode_status_quality_idx` по `episode_id, status, quality` нужен для выбора медиа серии.
- `licensed_media_health_due_idx` по `health_status, next_check_at, id` нужен для bounded due backlog без полного сканирования media.
- Уникальная пара `licensed_media.catalog_title_id + source_media_key` нужна для стабильного обновления видео-ссылок.
- Unique-пары `catalog_titles.source_id + external_id` и `catalog_titles.source_id + source_url_hash` сохраняют стабильную идентичность тайтла у внешнего провайдера.
- Unique `(source_id, relation_type, source_key_hash)` в `catalog_relation_source_identities` запрещает два canonical решения для одного объекта справочника у одного источника; `(relation_type, canonical_key)` индексирует перенос identity при maintenance.
- `catalog_title_ratings.catalog_title_id + provider` запрещает второй рейтинг того же провайдера для тайтла.
- Каждая metadata pivot-таблица имеет составной primary key по `catalog_title_id` и related ID, поэтому одну связь нельзя присоединить дважды.
- Publication lookup и display-order индексы на тайтлах, сезонах, сериях и медиа обслуживают публичные scopes и упорядоченные relationship loads.
- Индексы состояния страниц источника по `import_status`, `retry_after_at` и `last_imported_at` нужны для единственной команды импорта.
- `episode_progress_user_history_idx` по `(user_id, last_watched_at, id)` обслуживает стабильную пагинацию `/library/history` и выбор последнего просмотра для summary. Он применяется после migrations persistent progress/backfill; backfill должен завершиться до первого использования личной библиотеки.
- `catalog_user_state_watchlist_order_idx` по `(user_id, in_watchlist, updated_at, id)`, `catalog_user_state_rating_order_idx` по `(user_id, rating, updated_at, id)` и `catalog_user_state_updated_order_idx` по `(user_id, updated_at, id)` обслуживают owner-first списки, counts и стабильную сортировку личной библиотеки; фильтры названия/типа/года дополнительно соединяются с индексами `catalog_titles`.

## Поля импортера

Текущий импортер Seasonvar сохраняет:

- название
- оригинальное название
- тип
- год
- описание
- ссылку постера
- внешний ID источника
- дополнительные названия
- рейтинги IMDb и КиноПоиск
- отзывы
- номер текущего сезона
- сезоны
- статус выхода сезона: дата последней серии, количество вышедших серий, общее количество серий, если известно, перевод сезона и исходный текст статуса
- серии
- видео-кандидаты, внешние ссылки воспроизведения, стабильный `source_media_key`, качество, формат, перевод и состояние доступности
- варианты HLS master playlist как отдельные записи `licensed_media`, если варианты качества удается определить
- трейлеры и анонсы как медиа карточки или сезона, если номер серии отсутствует
- разобранные значения связей для жанров, стран, актеров, режиссеров, возрастов, переводов, статусов, сетей, студий и меток
- исходные HTML-снимки serial pages для совместимого metadata-backfill; non-serial snapshots содержат только hash-сводку без provider prose

## Извлечение связей

Текущие источники связей:

- структурированный JSON-LD для жанра, актера, режиссера и страны
- метки `pgs-sinfo_list` для жанра, страны, возраста, перевода, статуса, сети и студии
- `itemprop=directors` для режиссеров
- `data-info=actor` и schema-блоки актеров
- маркеры перевода в списке сезонов, например `(AniDub)`
- маркеры обновления сезона вроде `(09.07.2026 1 серия (AniDub) из ??)` как структурированные поля `seasons`
- ссылки списка меток Seasonvar, если они есть
- текстовые маркеры субтитров как `tag=субтитры`

## Поведение импорта

- Единственная публичная команда Seasonvar: `php artisan seasonvar:import`.
- `--inventory-only` классифицирует sitemap и source-page URL, записывает import run/event snapshot и обновляет только инфраструктурные `Source`/`SourcePage`; `CatalogTitle`, сезоны, серии, связи и media в этом режиме не читаются для записи и не изменяются.
- `--page-type=<type>` допускает только тип с зарегистрированным parser/importer и `enabled=true`; без опции planner использует только `automatic=true`. Значения `refresh_after_hours` и `chunk_size` задаются отдельно для serial, actor, genre, country, tag и RSS.
- Actor/genre/country/tag handlers записывают только каноническое имя/slug/source URL и bounded ссылки serial. Ссылки получают отложенную первую попытку, поэтому одна taxonomy page не запускает рекурсивный crawl в том же цикле. Stable person URL разделяет одноимённых актёров; справочные genre/country/tag дедуплицируются по Unicode/`е`-`ё`/punctuation-insensitive slug.
- RSS не владеет данными тайтла: он нормализует только разрешённые serial URL и переводит существующие source pages в freshness queue. Static/search/sitemap/unknown не выбираются automatic planner и не создают локальные страницы.
- Без аргументов команда читает sitemap Seasonvar, сохраняет найденные ссылки и в queued-режиме группирует serial pages по тайтлу: известные и динамически найденные страницы сезона немедленно получают независимый preparation job.
- С URL-аргументом команда обновляет эту карточку и все найденные прямые страницы сезонов; сезоны и серии применяются одним finalizer к одному `CatalogTitle`.
- Source/local manifest хранит только агрегированные counts сезонов, серий и media. Отсутствующая в одном provider snapshot локальная строка не удаляется: importer остаётся additive.
- Существующие связи, серии и медиа сохраняются, если они исчезли со страницы источника.
- Импортируемые сезоны и серии всегда записываются как `regular`, получают `sort_order=number`, публичное состояние и восстанавливаются после soft delete по стабильному составному ключу.
- Измененные название, описание, постер, рейтинг и поля видео-ссылок обновляются.
- JSON-плейлисты Seasonvar разворачиваются рекурсивно: листовые записи с `file` импортируются и из вложенных `folder`, при этом URL по-прежнему проходит allowlist прямых видео-форматов.
- Дубли страниц сезонов объединяются в одну `CatalogTitle`; сезоны остаются внутренними записями.
- Один запуск импорта держит cache lock, чтобы две копии `seasonvar:import` не обновляли одну очередь одновременно.
- Если частый cron стартует новую копию во время активного импорта, команда пропускает этот запуск с успешным кодом выхода.
- Каждый цикл импорта помечает неправильные вложенные ссылки Seasonvar как недоступные и проверяет ограниченный backlog старых медиа с пустым или устаревшим статусом доступности.
- Каждый обычный или queued full cycle локально классифицирует bounded backlog serial snapshots без `provider_availability_checked_at`; сетевые запросы для этого backfill не выполняются. Новые/повторные fetch сразу обновляют provider availability, а `region_blocked` возвращается в planner после configured retry interval.
- При старте каждого обычного или queued цикла один ограниченный chunk страниц `missing_data` повторяется раньше общего `retry_after_at`; страницы с живыми claims отбрасываются до limit, выборка ротируется по времени попытки и не отменяет backoff для HTTP/connection failures.
- Каждый цикл импорта нормализует старые состояния разобранных страниц источника и дозаполняет отсутствующие ключи медиа, качество, формат и перевод.

## Счетчики публичного каталога

- Главный total `/titles` начинается с `catalog_titles` и применяет relation-фильтры через grouped pivot-подзапросы, поэтому актеры, режиссеры, жанры, страны, сезоны, серии и media не размножают строки paginator.
- Facet count — число уникальных публично доступных тайтлов при всех активных условиях, кроме собственной группы фасета. Внутри одной relation-группы значения по-прежнему объединяются через OR, между группами — через AND.
- Для каждой relation-группы выполняется один grouped aggregate; годы, publication type и subtitles используют отдельные bounded агрегаты. Полные коллекции тайтлов для подсчета в PHP не загружаются.
- Default public facets хранятся как compact snapshot через `CatalogFacetSnapshotCache`; пользовательский и любой непустой контекст фильтров обходят общий snapshot. Importer/admin mutations инвалидируют соответствующую catalog cache version after commit, поэтому устаревший snapshot не переживает authoritative изменение.

## Коллекции сериалов

### Таблицы и связи

| Таблица | Назначение и ограничения |
| --- | --- |
| `catalog_collections` | Stable DB ID, unique public UUID и global current slug; nullable owner FK, original name/description, enum-backed type/visibility/moderation/sort, optional content locale, feature/publication state, private cover metadata/version, content version, timestamps и soft delete. |
| `catalog_collection_slugs` | Unique historical slug → stable collection FK. Старые URL разрешаются после policy check и перенаправляются на current slug. |
| `catalog_collection_items` | Одна serial membership: collection FK, `CatalogTitle` FK, nullable added-by FK, manual position и timestamps. Unique collection/title запрещает логический дубль; title/collection и collection/position indexes обслуживают discovery и ordering. |
| `catalog_collection_translations` | Только editorial DB content: unique collection/locale title, description, SEO title/description. User-created text сюда не копируется. |
| `catalog_collection_reports` | Collection moderation evidence с nullable FK, preserved collection UUID/content version, nullable reporter/moderator, stable reason/status, sanitized details/note и unique deduplication key. |

`users.public_id` — nullable-at-schema external UUID с migration backfill и model-side assignment для новых users. Публичный owner URL использует его, а не numeric ID/email. Relations: `User::catalogCollections()`, `CatalogCollection::{owner,items,historicalSlugs,translations,reports,comments}()`, `CatalogTitle::collectionItems()` и item `catalogTitleWithTrashed()` для owner-safe unavailable state.

### Invariants

- User collection создаётся только с server-derived `owner_id`; owner name/email не входят в identity. `id` и `public_id` переживают rename, slug change, locale, cover, visibility и restore.
- Internal values хранятся только как `user|editorial|system`, `private|unlisted|public`, `pending|approved|rejected|hidden|archived` и enum sort codes. Переведённые labels существуют только в `lang/ru|en/collections.php`.
- Unique current slug и unique history slug вместе с UUID suffix дают deterministic allocation. История same collection может быть освобождена при возврате к прежнему имени; current/history другого record не переиспользуются.
- Unique item constraint вводится сразу, потому что pre-migration audit подтвердил отсутствие legacy collection tables/rows. Runtime add/batch остаётся идемпотентным и обрабатывает DB races transaction retry; merge duplicate titles выполняет reconciliation до title delete.
- Manual positions последовательны после remove/move/reorder/merge. Automatic ordering не меняет их. Стабильный secondary key — item/title ID в зависимости от sort.
- Public count считает только guest-visible titles; owner count — сохранённые items, включая bounded unavailable list. Это не раскрывает скрытые memberships гостю и сохраняет owner recovery.
- Soft delete сохраняет items/slugs/translations/reports на restoration window. Restore делает record private/approved; permanent delete cascades owned structural rows, оставляет report evidence через nullable FK и privacy-retires generic comments.

### Обоснованные индексы

- `catalog_collections_owner_order_idx(owner_id, deleted_at, updated_at, id)` — active/deleted owner dashboards с deterministic pagination.
- `catalog_collections_public_order_idx(visibility, moderation_status, deleted_at, updated_at, id)` — public directory/profile/search/sitemap eligibility и recent ordering.
- `catalog_collections_featured_idx(type, is_featured, visibility, moderation_status)` — bounded homepage/editorial featured query.
- `catalog_collection_items_manual_order_idx(catalog_collection_id, position, id)` — manual page/order normalization; unique collection/title одновременно обслуживает membership existence.
- `catalog_collection_items_title_lookup_idx(catalog_title_id, catalog_collection_id)` — title page discovery, merge reconciliation и bounded import-driven public collection cache lookup.
- Translation locale/name, slug history collection/time и report collection/status/public-identity/queue indexes соответствуют editorial lookup, redirects, preserved evidence и admin queue. Дополнительные likes/follows/collaborator indexes не добавлялись, потому что таких таблиц нет.

### Rollback и legacy normalization

Миграции additive и SQLite-compatible: сначала добавляют/backfill `users.public_id`, затем collection tables, потом editorial translations. `down()` удаляет только новые tables и UUID column; watchlist, rating, progress, history, comments, reviews, catalog identity и media не затрагиваются. После production writes перед rollback нужен account/collection export и backup: rollback схемы намеренно не переносит новые коллекции в другую архитектуру.

Baseline audit не нашёл duplicate systems/items/slugs/positions, translated DB labels, invalid owners или unsafe legacy covers, поэтому destructive backfill не нужен. Будущая диагностика должна выявлять unique violations, invalid enum codes, orphaned owners/titles, position gaps/duplicates, missing cover files и public rows вне approved state; исправление проходит idempotent domain services, а не blind delete.

## Обсуждения

### Таблицы и identity

| Таблица | Контракт |
| --- | --- |
| `comments` | Stable ID, nullable author, allowlisted target type/numeric ID, optional root title FK, root `parent_id`, logical `reply_to_id`, plain body/hash, spoiler/status/version/edit/moderation/deletion fields, UUID-derived submission key, timestamps и soft delete. |
| `comment_reactions` | Unique `(comment_id,user_id)` и stable `up|down`; comment/user deletion удаляет только engagement row. |
| `comment_reports` | Comment evidence, nullable reporter/moderator, category/status, sanitized details/private note, nullable unique unresolved deduplication key и resolution time. Comment FK restrict сохраняет evidence. |
| `comment_restrictions` | Comment-only temporary/permanent restriction с user/moderator/revoker, reason, private note, start/expiry/revocation. Expiry определяется query, не scheduler. |
| `user_blocks`, `user_mutes` | Directional private relations с unique pair и reverse lookup. Block и mute имеют разные semantics. |
| `comment_notification_preferences` | Одна строка на user с reply/reaction/moderation/report switches. |
| `notifications` | Стандартная Laravel database-notification identity/data/read state; comment payload body-free и использует type `comment.activity`. |

`comments.id` переживает edit, soft delete/restore, target slug/name/locale change и merge. `target_type,target_id` не является arbitrary polymorphic relation: model class никогда не хранится и разрешается только `CommentTargetType`/`CommentTargetResolver`. `catalog_title_id` связывает title/season/episode scope с одним cache/merge root; collection target оставляет его `null`.

### Reply, status и aggregate invariants

- Top-level row имеет `parent_id=null`; reply имеет `parent_id` ровно root ID и optional `reply_to_id` на published non-deleted row той же цели. `CreateComment` вычисляет root server-side и требует, чтобы он оставался published; live root либо author-deleted published tombstone допускает сохранённое продолжение, но hidden/rejected/spam/removed root закрывает новые replies. Reparent/update target API отсутствует, поэтому cycles и structural nesting глубже одного невозможны.
- `restrictOnDelete` для `parent_id` и comment reports не позволяет случайно force-delete thread/evidence; `reply_to_id` допускает `nullOnDelete` только для законного hard-delete контекста. Обычные user/moderator удаления используют soft delete.
- Public count — число `status=published AND deleted_at IS NULL` для цели, включая roots/replies. Root `replies_count` — published non-deleted replies. Собственные pending/hidden/rejected/spam replies считаются отдельным private viewer overlay; removed/deleted и blocked/muted state не изменяют public aggregate.
- Reaction up/down/score и reply totals derived SQL subqueries. Stored aggregate columns не добавлены, поэтому create/edit/delete/restore/moderation/reaction не поддерживают drift-prone counters.
- Stable DB values никогда не переводятся: target/status/reaction/report/restriction/deletion/moderation/notification codes хранятся на английском; `lang/{ru,en}/comments.php` владеет labels. User body сохраняет исходный язык/script и не входит в translation rows/files.

### Query indexes и uniqueness

- `comments_target_list_idx(target_type,target_id,parent_id,status,created_at,id)` обслуживает exact scope, root list и deterministic pagination; `comments_thread_replies_idx(parent_id,status,deleted_at,created_at,id)` — chronological progressive replies.
- `comments_author_activity_idx(user_id,status,deleted_at,created_at,id)` обслуживает private activity; `comments_duplicate_window_idx(user_id,target_type,target_id,parent_id,body_hash,created_at)` — short same-thread duplicate detection; `comments_title_cache_idx(catalog_title_id,status,deleted_at)` — targeted invalidation/account identity changes. Reaction unique/totals indexes обслуживают mutation и public aggregates, `comment_reactions_user_current_idx(user_id,comment_id,type)` — один grouped viewer overlay, а отдельный user/created/id composite остаётся для private export ordering.
- `comments_moderation_queue_idx(status,created_at,id)` и report queue/comment-status indexes обслуживают bounded moderator views. `comment_reactions_totals_idx` и unique pair обслуживают grouped totals/current state. Restriction/block reverse indexes соответствуют active permission и both-direction block queries; `user_blocks_owner_page_idx(blocker_id,id)` и `user_mutes_owner_page_idx(muter_id,id)` обслуживают независимые deterministic privacy-list paginators.
- `notifications_recipient_list_idx(notifiable_type,notifiable_id,type,created_at,id)` обслуживает deterministic paginated domain inbox, а `notifications_recipient_unread_idx(notifiable_type,notifiable_id,type,read_at)` — domain-scoped unread count/update. Отдельный дублирующий morph-prefix index намеренно не создаётся.
- Unique submission key, reaction pair, report deduplication key и directional block/mute pair введены сразу: pre-migration audit подтвердил отсутствие любых legacy comment/reaction/report rows. Runtime actions всё равно idempotent и обрабатывают concurrent retry.

### Migration, normalization, targets и privacy

Migrations `2026_07_15_210000`–`210300` и focused index migration `2026_07_15_235200` additive, reversible и SQLite-compatible. Они не переписывают `catalog_title_reviews`, каталог, collections, watchlist/rating, progress/history, media или import state. Initial audit не нашёл competing comment/reply/reaction tables, orphan/circular relations, translated statuses, unsafe legacy bodies, duplicate votes/reports или legacy anchors, поэтому destructive normalization/backfill отсутствует. `CommentSchema` отдельно проверяет canonical row и engagement/relationship/notification capabilities; feature flag влияет только на writable UI и не пропускает lifecycle/privacy работу над существующей схемой. Permanent collection retirement bulk-обновляет и активные rows, и tombstones до stable `removed/privacy`, сохраняет существующий `deleted_at` и всю thread/report evidence. После появления production discussion data rollback допустим только после private export/backup; `down()` удаляет новые данные и не переносит их в reviews.

Title merge сначала remap-ит title/season/episode targets и root title FK, затем удаляет duplicate hierarchy; ID, parent/reply context, reactions, reports, moderation/spoiler/edit/deletion state и timestamps сохраняются. Soft-deleted/hidden target закрывается существующей visibility boundary без изменения comments. Permanent collection deletion privacy-retires discussion, сохраняя rows/evidence; public direct URL не обходит target policy, moderator queue читает сохранённую запись отдельно.

Account deletion анонимизирует `comments.user_id` и `comment_reports.reporter_id`, очищает user-derived submission/unresolved-report deduplication keys, удаляет reactions/private relations/preferences/restrictions и body-free notifications до удаления user. Thread body, body hash, report content/status и moderation evidence сохраняются без former account linkage. Export содержит только owner comments/reactions и исключает private moderator/report data и чужие поля.

## Отзывы пользователей

### Таблицы, identity и rating relation

| Таблица | Контракт |
| --- | --- |
| `catalog_title_reviews` | Existing stable ID/title/source/author/body/body hash/date плюс nullable user, `provider|user` origin, title, original hash, spoiler/verified flags, moderation/version/edit/deletion/ownership/submission/merge fields. Прямой title FK — единственный review target. |
| `catalog_title_review_aliases` | Unique legacy review ID → canonical review ID и legacy title context после merge; direct links не зависят от slug/page. |
| `catalog_title_review_votes` | Unique `(review_id,user_id)`, stable `helpful|not_helpful`; FK cascade удаляет engagement только при законном hard delete review/user. |
| `catalog_title_review_reports` | Review evidence, nullable reporter/moderator, category/status, sanitized details/private note, resolution и nullable unique open deduplication key. Review FK restrict сохраняет evidence. |
| `catalog_title_review_restrictions` | Review-only temporary/permanent restriction, reason, private note, start/expiry/revocation; active query учитывает expiry без scheduler. |
| `catalog_title_review_notification_preferences` | Одна owner row для helpful/moderation/report preferences. Standard `notifications` хранит body-free `review.activity` payload. |
| `catalog_title_user_states` | Уже существующая unique user/title row остаётся единственным optional 1–10 portal score. Review table не хранит дублирующую оценку. |

Provider rows сохраняют прежний `(catalog_title_id,body_hash)` unique key, ID/source/body/date и unlimited-per-title semantics. User `body_hash` author-scoped; `original_body_hash` сохраняет evidence при archival collision. Nullable unique `ownership_key=sha256(user,title)` обеспечивает один current user review, а `submission_key=sha256(user,title,UUID)` — idempotent retry. Deleted row хранит ownership 30 дней; следующая create после expiry очищает slot в той же locked transaction, но не удаляет historical review.

### Visibility, aggregates и query indexes

- Public row: `status=published`, `deleted_at IS NULL`, `merged_into_id IS NULL` и доступный title. Owner-only pending/history и moderator records не входят в public count/cache. Imported provider rows получают DB defaults `origin=provider,status=published` без destructive backfill.
- Public count — provider+user rows этого public predicate. Review score count/average — только `origin=user` public rows с non-null `catalog_title_user_states.rating`; missing score не равен 0. Helpful/not-helpful/score derived from grouped vote subqueries. Stored counters/average columns отсутствуют.
- Public title list index ведёт exact title/status/deleted/date/id pagination; author-history — user/status/deleted/date/id; moderation — status/date/id; verified/spoiler/rating filters используют ограниченные indexes и canonical rating join. Vote unique/totals, report queue/dedup и active restriction indexes соответствуют реальным permission/list queries; duplicate speculative indexes не добавляются.
- Sorting uses allowlisted SQL only and deterministic ID tie-breaker. Author/title/rating/totals eager/join/grouped; viewer vote и block/mute sets загружаются bounded отдельно. Full lists не сортируются in memory и Eloquent graphs не сериализуются в Livewire.

### Migration, merge, lifecycle и privacy

`2026_07_15_220000_extend_catalog_title_reviews_for_community_reviews.php` additive и SQLite-compatible: расширяет существующую table, затем создаёт aliases/votes/reports/restrictions/preferences. Pre-migration schema audit нашёл 73 101 provider rows, zero duplicate title/body-hash groups, orphans, unsafe HTML-like bodies или legacy user/vote/report data, поэтому destructive duplicate reconciliation не нужен. `ReviewSchema` fail-closed отключает community writes до полной schema capability и сохраняет legacy provider API reads.

`2026_07_15_235100_repair_catalog_title_review_rollout.php` — idempotent convergence для среды, где ранняя in-flight версия `220000` уже записалась в `migrations`: она добавляет только отсутствующие `original_body_hash`, pre-merge status/reason и `ownership_released_at`, а report dedup key делает nullable для account anonymization. Fresh schema является no-op; provider/reports IDs/text/hash сохраняются. Repair `down()` намеренно ничего не удаляет, потому что эти поля принадлежат `220000`; полный rollback именно `220000` удаляет Task 13 schema, а production user data перед ним требует export/backup.

Title merge переносит provider/user rows до удаления duplicate title. Same body/author collision сначала выбирает current ownership slot, затем public/active, latest edited и более содержательную row; retained historical rows не могут перехватить reconciliation, а released/previously merged rows не получают ownership повторно. Non-canonical ID архивируется с `merged_into_id`, stable alias, прежним status/deletion evidence и collision-safe active hash; входящие aliases flatten-ятся на финальный canonical ID, поэтому direct links и notification cleanup не зависят от длины merge chain. Truthful verified snapshot и safety-critical spoiler flag объединяются monotonic OR, votes/reports переходят idempotently, self/duplicate votes схлопываются, portal rating/progress rows объединяются отдельным existing title-user merger; review delete/create не меняет progress, history, watchlist, bookmark или collection membership.

Account deletion обнуляет `reviews.user_id` и `reports.reporter_id`, удаляет reporter dedup key, privacy-rotates author-scoped body hash, очищает original/ownership/submission hashes, удаляет actor votes/preferences/restrictions/body-free notifications и сохраняет public text/moderation evidence без former identity. Export включает owner title reference/title/body/rating/spoiler/verified/public status/timestamps и votes made, но исключает moderator note, reports/reporters и exact watch evidence. `verified_watching` — non-downgrading boolean snapshot, не FK/aggregate private progress.

## Системные, редакционные и личные теги

### Таблицы и stable identity

- `tags` остаётся canonical global base. Existing `id/name/slug/source_url/timestamps` и `catalog_title_tag` сохранены; additive fields: `public_id`, nullable `code`, `type`, `visibility`, `moderation_status`, `source`, `normalized_name/hash`, `content_version`, `merged_into_id`, archive timestamp и прежнее archive state. `public_id` — API identity, `id` — FK, `code` — optional integration identity; имя и slug mutable.
- `tag_translations(tag_id, locale)` — localized label/plain-text description/SEO; `tag_aliases(locale, normalized_hash)` — exact alternate identity и optional redirect slug; `tag_slugs(slug)` — current-slug history; `tag_synonyms(tag_id, related_tag_id, relationship)` — bounded semantic relation; `tag_merge_events` — append-only source/target/actor/impact snapshot.
- `tag_provider_mappings(provider, provider_key)` хранит stable provider mapping, normalized/raw label, allowlisted source URL, confidence/status и last seen. `catalog_title_tag_sources` хранит per-title canonical assignment provenance/current observation. Original `catalog_title_tag` остаётся aggregate presence pivot и совместимым read path.
- `user_tags` — отдельный private owner aggregate со UUID, original Unicode name, normalized hash, optional content locale/version, timestamps/soft delete. `catalog_title_user_tag` связывает его только с `CatalogTitle` и хранит deterministic `position`; user ID не принимается из request. Season/Episode/Collection/Comment polymorphic tag pivots отсутствуют.

### Uniqueness, indexes и reconciliation

- Unique constraints: global UUID/code/current slug, one normalized active global name, one translation per tag/locale, one alias per locale/normalized value, one historical/alias slug globally, one provider/provider-key mapping, one source observation identity, one user-tag name per owner, one global and one personal tag/title pivot. Global normalized uniqueness включается только после exact reconciliation.
- `2026_07_15_230000_create_canonical_tag_domain.php` создаёт additive schema и backfill, `230050` сохраняет archive pre-state, `230060` переставляет public eligibility index по equality predicate/алфавитному lookup, `230075` повторно вычисляет безопасную comparison identity для code-before-migration deployments, `230100` backfill-ит Seasonvar mapping/provenance, `230200` объединяет только exact normalized legacy duplicates и затем включает unique hash.
- Duplicate reconciliation выбирает canonical deterministically по lifecycle, type, moderation, public state, assignment count и ID; moves/deduplicates title pivots, provenance, translations/descriptions, aliases/slugs, provider mappings и synonyms, пишет merge event и очищает source hash. Нечёткое сходство, transliteration или перевод сами по себе не являются merge evidence.
- Query indexes соответствуют фактическим lookup: public eligibility, normalized lookup, merge target; translation locale/label; alias target/locale/status; synonym reverse direction; provider tag/status и normalized/status; source tag/title/current и provider source; owner/deleted/order; оба направления title pivots. `count(distinct catalog_title_id)` и `whereHas` предотвращают duplicate cards/counts.

### Visibility, lifecycle и privacy invariants

- Public global predicate: `visibility=public`, `moderation_status=approved`, type не `hidden_internal`, без archive/merge, плюс минимум один `CatalogTitleQuery::visibleTo(null)` title. Hidden/unpublished/soft-deleted/ineligible title не входит в page/count/popularity/related/sitemap. Текущий продукт не имеет отдельной territory/premium модели; используются только реальные catalog entitlement publication/audience/window rules.
- `system` и `editorial` разделены stable type, но оба управляются `manage-catalog`; normalized `imported` публикуется только после approved mapping. `hidden_internal` всегда internal. Archive запрещает новые assignments и public discovery; restore возвращает recorded pre-state. Merge source остаётся compatibility record и не получает новые assignments.
- Personal tag всегда private, owner-only и не имеет visibility/moderation/public slug/page. Soft delete скрывает tag и новые assignments, сохраняет stable identity/assignments для 30-day restore; expired purge удаляет его вместе с pivot. Different owners могут иметь одинаковое normalized name и никогда не merge-ятся.
- Account export содержит только собственные labels/descriptions/content locale/assignment references/timestamps, включая restoration evidence согласно policy; moderator notes/provider credentials/чужие данные исключены. Account deletion cascade удаляет private tags/pivots. Global tags не меняют owner state; title merge переносит global provenance и personal pivots, затем независимо уплотняет позиции каждого затронутого владельца без изменения относительного `(position, tag ID)` порядка.

### Search, counts, recommendations и imports

- Public tag search — отдельная canonical query, а не расширение title FTS: canonical name/slug, active+fallback labels и approved aliases; explicit synonyms дают только bounded one-hop related expansion. Personal search owner-scoped по normalized value и не индексируется глобально.
- Public count/popularity использует distinct visible title count. Related сначала сохраняет explicit editorial ordering, затем shared visible-title count; current/private/hidden tags исключены. Recommendations используют только eligible global tags как weighted signal; personal assignments не входят в public similarity или explanation.
- Full provider-set sync идемпотентен: stable mapping/pivot uniqueness, current provenance timestamps и complete-snapshot stale reconciliation не создают повторов. Explicit editorial provenance/suppression survives import; rejected mapping clears only its current provider observations, а pivot удаляется только без remaining current source. Raw provider spelling не становится canonical public label автоматически.

## Аутентификация и sessions

- `users` — единственная portal identity table: unique `email`, Laravel password hash cast, nullable `email_verified_at`, remember token и timestamps. Username/profile/external-identity/account-status/MFA/magic-link/merge columns отсутствуют. Canonical email normalization применяется до write, case-insensitive scope сохраняет lookup compatibility; Task 15 не переписывает существующие rows и не добавляет schema.
- `password_reset_tokens` сохраняет Laravel broker-compatible email/token/created timestamp; raw token не возвращается из persistence и lifecycle остаётся broker-owned. Email change/registration/reset/delete удаляют только применимые rows через canonical account services.
- Browser session storage определяется `SESSION_DRIVER`: production default Redis не предоставляет enumerable records; database driver использует существующую `sessions` table/index by user/last activity. UI выбирает только safe summary fields и превращает raw record identity в HMAC action token. Session payload, cookie, raw user agent/IP и exact identifier наружу не выдаются.
- `personal_access_tokens` остаётся Sanctum-owned: token hash, abilities, expiry и owner morph. `(tokenable_type, tokenable_id)`, unique token hash и expiry/prune behavior уже поддерживают реальные login/device queries; Task 15 не добавляет redundant index. Plaintext существует только в issuance response.
- Authentication audit — отдельный bounded daily log, не user-owned relational history и не источник authorization. Stable event enum и HMAC fingerprints не позволяют восстановить email/IP без application secret. Retention задаётся operation config; экспорт normal user его не включает.
- External identity/provider-token, merge marker, trusted-device, account lock/status, MFA и magic-link tables отсутствуют. Duplicate email/account collision не auto-merge-ится; неоднозначный legacy conflict потребовал бы отдельного read-only report и proof-of-control/admin workflow до любой schema uniqueness change.

## Настройки аккаунта

- `users` имеет optional `hasOne(UserAccountSetting::class)`. Таблица `user_account_settings` использует `user_id` одновременно как primary/foreign key, поэтому физически допускает ровно одну строку на account без duplicate reconciliation; account deletion удаляет её FK cascade.
- Nullable columns фиксируют только явный выбор: `locale`, `timezone`, `autoplay`, `remember_volume`, `volume`, `muted`, `playback_speed`, `preferred_quality`, `preferred_variant`, `subtitles_enabled`, `keyboard_shortcuts_enabled`, `reduced_motion`, `collection_default_visibility`. Переведённые labels, media URLs, session/provider secrets, email/password, progress/history и notification matrix сюда не записываются.
- `settings_version` монотонно увеличивается при material update и участвует в account/device precedence. Invalid legacy locale/timezone/boolean/range/speed/quality/variant/visibility читается как safe default, но не переписывается скрытно; следующий explicit save нормализует только выбранную категорию.
- Interface locale использует существующий registry `ru|en`; timezone — allowlisted IANA ID, не raw offset. Playback speed берётся из config allowlist, volume — integer `0..100`, quality/variant — bounded stable codes реально доступных `licensed_media`/variant rows. Preferred временно недоступное значение сохраняется, resolver выбирает safe playable fallback.
- Comment/review preferences остаются в `comment_notification_preferences` и `catalog_title_review_notification_preferences`, потому что delivery services уже читают эти таблицы. Collection default не изменяет `catalog_collections.visibility` существующих rows. Exact viewing history/progress/library остаются в private tables и не превращаются в settings fields.
- `seasonvar.account-preferences.v1` в local storage — не database relation: anonymous/device state имеет schema version, optional account version и opaque owner scope. После login server принимает только typed allowlist и заполняет nullable account fields; volume/mute могут остаться device-only. Legacy `plyr` key читается совместимо и не удаляется до подтверждённой миграции.

Additive migration `2026_07_16_000000_create_user_account_settings_table.php` не backfill-ит и не переписывает существующие user/player/collection/notification data. До её применения schema guard возвращает defaults для reads и fail-closed `503` для writes; rollback удаляет только новую preference table, поэтому после реальных writes требуется export/backup и предпочтителен roll-forward.

<!-- project-docs:start -->
## Публичная индексация

- Индекс карты сайта собирает статические страницы, годы, активные справочники, программные посадочные страницы, карточки тайтлов и карту видео.
- Карточки тайтлов попадают в `sitemap-titles-{page}.xml` только при `is_published=true` и заполненном `slug`.
- Карта видео строится по `licensed_media` со статусом `published` и абсолютной внешней ссылкой в `playback_url` или `path`.
- `robots.txt` объявляет только индекс карты сайта, потому что количество страниц `sitemap-titles-*` и `sitemap-videos-*` зависит от базы.
- `/sitemap.xml` (`sitemap`)
- `/sitemap-index.xml` (`sitemap.index`)
- `/sitemap-static.xml` (`sitemap.static`)
- `/sitemap-taxonomies.xml` (`sitemap.taxonomies`)
- `/sitemap-landings.xml` (`sitemap.landings`)
- `/sitemap-titles-{page}.xml` (`sitemap.titles`)
- `/sitemap-videos-{page}.xml` (`sitemap.videos`)
<!-- project-docs:end -->

## Связи и schema заявок на материалы

`content_requests.id` остаётся внутренним FK, `public_id` — уникальная route identity. Nullable requester и target/result FKs сохраняют общественно полезную историю при удалении account или canonical target; target merge retarget-ит их до удаления duplicate content. `type`, `status`, `priority`, rejection/provider/language/quality codes хранят только stable values, никогда translated labels.

| Таблица | Назначение и ограничения |
| --- | --- |
| `content_requests` | Typed aggregate, canonical target/sequence, normalized title/hash, exact active identity, idempotent submission, public/private moderation fields, merge/completion/import references и optimistic version. |
| `content_request_votes` | Один vote на `(content_request_id,user_id)`; count derived, voter list private. |
| `content_request_followers` | Одна подписка на `(content_request_id,user_id)`; identities private. |
| `content_request_status_histories` | Append-only transition/reason/private-note timeline с nullable actor и unique retry key. |
| `content_request_source_links` | До трёх normalized HTTP(S) evidence links, private by default; per-request URL hash unique. |
| `content_request_external_identifiers` | Allowlisted provider+normalized ID evidence; provider/ID/request index сужает duplicate candidates. |
| `content_request_clarifications` | Requester/moderator plain-text thread с idempotent submission key; не public discussion. |
| `content_request_notification_preferences` | Owner PK и requester/voted/followed boolean categories. |

Exact active uniqueness обеспечивается nullable unique `active_identity_key`; terminal row очищает его, сохраняя historical `exact_identity_hash`. Composite indexes соответствуют public status pagination, type/status moderation, requester/My Requests, normalized title duplicate narrowing и title/season/episode target lookup. Vote/follow unique keys одновременно являются integrity boundary и count index prefix; history/source/external indexes обслуживают только реальные timeline/visibility/duplicate queries. SQLite `migrate --pretend` подтверждает additive FK/index DDL.

Migration `2026_07_16_180000_create_content_request_domain.php` ничего не backfill-ит: audit не нашёл legacy request/ticket/suggestion data. Rollback безопасно удаляет только новые таблицы до появления production writes; после появления заявок сначала нужен export/backup, а importer source pages и уже доставленные notifications не откатываются.

## Recommendation relations и user state

Migration `2026_07_16_120000_add_canonical_recommendation_discovery.php` additive и не меняет `catalog_title_recommendations`, watchlist, rating или progress. Она добавляет nullable `recommendation_feedback`, feedback version/timestamp, nullable `watch_status` и watch-status version в existing unique `(user_id,catalog_title_id)` row. Stable values: feedback `not_interested|blacklisted`, status `planned|watching|completed|dropped`; translated labels не хранятся.

`catalog_title_relations` хранит `(source_title_id,target_title_id,relation_type,source)` unique, provider key, bounded manual priority, lock/active flags и timestamps. FK cascade удаляет explicit rows при hard title delete. Service пишет inverse pair и не смешивает editorial/imported identity. Seasonvar title merge переносит incoming/outgoing rows до duplicate force-delete, объединяет priority/lock/active/provenance, удаляет self-relations и сохраняет legacy slugs.

Индексы соответствуют реальным запросам:

- `(source_title_id,is_active,priority,id)` — related display;
- `(target_title_id,relation_type,is_active)` — inverse/merge/cycle lookup;
- `(source,provider_key)` — idempotent provider provenance;
- `(user_id,recommendation_feedback,catalog_title_id)` — owner hard exclusions/library restore;
- `(user_id,watch_status,updated_at,catalog_title_id)` — bounded personal status source/demotion;
- `(in_watchlist,updated_at,catalog_title_id,user_id)` и progress `(last_watched_at,catalog_title_id,user_id)` — recent public semantic activity.
- `episodes_recommendation_release_events_idx(publication_status,deleted_at,released_at,id,season_id)` — bounded `recently_updated` episode event stream: equality on publication/deletion state, release range/order, deterministic ID tie-break and season join key. It avoids the former historical aggregate; isolated SQLite `EXPLAIN QUERY PLAN` selected it as a covering index. The extra episode-write cost is limited to this one real discovery query, and `down()` removes only the index.

Existing user-title unique and history indexes continue to serve owner state/progress. No feedback/impression/analytics aggregate table, region/premium/language/creator/franchise table or polymorphic recommendation relation was introduced. Complete domain semantics are owned by the [recommendation design](superpowers/specs/2026-07-13-recommendation-v3-list-design.md).

Release-availability hardening не добавило индекс: correlated season/episode checks выбирают существующие integer primary keys после title-keyed `licensed_media_publication_lookup_idx`. Дополнительный media index не устранил бы прежнюю materialization child lists, а summary-table создала бы второй источник истины для publication, audience, window, health и delete lifecycle.

## File-size metadata `licensed_media`

Additive migration `2026_07_16_190000_add_file_size_metadata_to_licensed_media.php` не меняет media IDs, relationships, playback URL или health columns. Она добавляет:

| Поле | Семантика |
| --- | --- |
| `file_size_bytes unsigned BIGINT nullable` | точный доверенный byte count; `null` = неизвестно, `0` = явно подтверждённый пустой resource |
| `file_size_checked_at timestamp nullable` | завершение последней проверки независимо от результата |
| `file_size_check_status varchar(24) nullable` | `pending|known|unknown|unsupported|failed` |
| `file_size_source varchar(64) nullable` | bounded источник вроде `head-content-length` или `ranged-content-range` |
| `file_size_http_status unsigned smallint nullable` | финальный status безопасного metadata response |
| `file_size_check_error varchar(255) nullable` | category + safe message без URL/query/token/exception message |

Форматированная строка не хранится. Composite `licensed_media_file_size_due_idx (file_size_check_status,file_size_checked_at,id)` соответствует backlog freshness/order; `file_size_bytes` не индексируется, потому что каталог по нему не фильтрует и не сортирует. Изменение effective URL atomically сбрасывает только эти поля в pending, не изменяя publication/availability.

Migration `2026_07_16_190100_add_media_file_size_counters_to_seasonvar_import_runs.php` добавляет safe-default unsigned counters `media_sizes_checked|known|unknown|unsupported`, `media_size_checks_failed` и `media_size_known_bytes`. `SeasonvarImportRunRecorder` увеличивает их атомарно на terminal progress event, поэтому parallel page jobs не выполняют read-modify-write race. `down()` обеих migrations удаляет только введённые columns/index.
