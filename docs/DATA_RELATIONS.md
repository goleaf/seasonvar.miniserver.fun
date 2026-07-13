# Связи данных и фильтры

Обновлено: 13.07.2026

## Основные связи

- `SeasonvarImportRun belongsTo User` через nullable `requested_by_user_id`; CLI/cron runs остаются без requester, а удаление user обнуляет ссылку.
- `SeasonvarImportRun belongsTo SeasonvarImportRun` через nullable `retry_of_run_id`; retry создаёт новую audit-строку. `last_heartbeat_at` и index `(execution_mode, status, last_heartbeat_at)` питают bounded stale recovery.
- `SeasonvarImportRun hasMany SeasonvarImportTitleGroup`; одна группа соответствует одному каноническому сезонному семейству внутри конкретного запуска.
- `SeasonvarImportTitleGroup belongsTo CatalogTitle` через nullable `catalog_title_id`, потому что первый подготовленный payload может создать тайтл только на стадии fan-in.
- `SeasonvarImportTitleGroup hasMany SeasonvarImportPreparedPage`; unique `(seasonvar_import_title_group_id, source_page_id)` исключает повторную подготовку одной страницы в группе.
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
- `SourcePage hasOne CatalogTitle`
- `SourcePage hasMany Season`
- `SourcePage hasMany Episode`
- `SourcePage hasMany CatalogTitleReview`
- `SourcePage hasMany SourcePageSnapshot`
- `SourcePage hasMany SeasonvarImportEvent`
- `SourcePage belongsTo SeasonvarImportRun` через `last_import_run_id`
- `SourcePage.page_type` хранит строковое значение `SeasonvarPageType`; inventory может добавить разрешённый неизвестный или ещё не разбираемый URL, но никогда не меняет `parse_status`/`import_status` уже существующей строки. Sitemap-документы также хранятся как source pages для полного audit trail и не попадают в serial parser queue.
- Metadata taxonomy provenance не дублируется отдельной таблицей: нормализованный `source_url` справочника однозначно связывается с `SourcePage.url_hash`, а `SourcePage` хранит ETag/Last-Modified, content hash, crawl/import/parse timestamps, missing flags и import events. `SourcePageSnapshot` для non-serial не хранит исходную страницу или описательный текст, а только безопасную hash-сводку; serial snapshot остаётся полным из-за существующего локального metadata-backfill.
- `SeasonvarImportRun hasMany SeasonvarImportEvent`
- `SeasonvarImportRun hasMany SourcePageSnapshot`
- `SeasonvarImportRun hasMany SourcePage` через `last_import_run_id`
- Каждая модель связи каталога относится ко многим `CatalogTitle` через явную pivot-таблицу.
- `User hasMany CatalogTitleUserState` и `User hasMany EpisodeViewProgress`; unique `(user_id, catalog_title_id)` хранит одну запись списка просмотра и внутренней пользовательской оценки, unique `(user_id, episode_id)` — одну каноническую позицию выпуска. Отдельной таблицы favorites нет: в текущем продукте «избранное» означает тот же список просмотра. `CatalogTitleRating` остаётся импортной provider-оценкой и не участвует во внутреннем среднем; editorial rating в модели отсутствует и не симулируется. Пока отдельной модели профиля нет, владельцем приватной строки является текущий `User`. Progress дополнительно хранит trusted duration/percent, первый и последний просмотр, неизменяемый `completed_at`, source media, ULID активной playback session и последний принятый event sequence.
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
- Автоматическая identity тайтла — `(source_id, external_id)`, при отсутствии provider ID — точный canonical URL hash/source page. Все десять справочников используют общий canonical slug; для актёров и режиссёров он строится после транслитерации, поэтому эквивалентные кириллическое/латинское написания и разные provider URL не создают вторую строку. URL остаётся provenance, а не identity; отдельные реальные provider IDs потребуют явной source-identity таблицы, а не slug suffix.
- Admin attaches metadata через `syncWithoutDetaching`, а importer relation sync использует ту же idempotent семантику: локально добавленные pivot rows не отсоединяются частичным или повторным provider import. Concurrent admin writes проверяют fingerprints редактируемых полей и relation IDs под row lock.
- Публичные медиа проверяют собственный status/window/audience и доступность связанных сезона и серии.
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
- Счетчики в боковых фильтрах имеют два значения: количество в текущем фильтре и глобальное количество.
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
- `licensed_media_title_status_published_idx` по `catalog_title_id, status, published_at` нужен для списков медиа карточки.
- `licensed_media_episode_status_quality_idx` по `episode_id, status, quality` нужен для выбора медиа серии.
- `licensed_media_health_due_idx` по `health_status, next_check_at, id` нужен для bounded due backlog без полного сканирования media.
- Уникальная пара `licensed_media.catalog_title_id + source_media_key` нужна для стабильного обновления видео-ссылок.
- Unique-пары `catalog_titles.source_id + external_id` и `catalog_titles.source_id + source_url_hash` сохраняют стабильную идентичность тайтла у внешнего провайдера.
- `catalog_title_ratings.catalog_title_id + provider` запрещает второй рейтинг того же провайдера для тайтла.
- Каждая metadata pivot-таблица имеет составной primary key по `catalog_title_id` и related ID, поэтому одну связь нельзя присоединить дважды.
- Publication lookup и display-order индексы на тайтлах, сезонах, сериях и медиа обслуживают публичные scopes и упорядоченные relationship loads.
- Индексы состояния страниц источника по `import_status`, `retry_after_at` и `last_imported_at` нужны для единственной команды импорта.
- `episode_progress_user_history_idx` по `(user_id, last_watched_at, id)` обслуживает стабильную пагинацию истории. Он применяется после migrations persistent progress/backfill; backfill должен завершиться до первого публичного использования `/watching`.

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
- При старте каждого обычного или queued цикла один ограниченный chunk страниц `missing_data` повторяется раньше общего `retry_after_at`; страницы с живыми claims отбрасываются до limit, выборка ротируется по времени попытки и не отменяет backoff для HTTP/connection failures.
- Каждый цикл импорта нормализует старые состояния разобранных страниц источника и дозаполняет отсутствующие ключи медиа, качество, формат и перевод.

## Счетчики публичного каталога

- Главный total `/titles` начинается с `catalog_titles` и применяет relation-фильтры через grouped pivot-подзапросы, поэтому актеры, режиссеры, жанры, страны, сезоны, серии и media не размножают строки paginator.
- Facet count — число уникальных публично доступных тайтлов при всех активных условиях, кроме собственной группы фасета. Внутри одной relation-группы значения по-прежнему объединяются через OR, между группами — через AND.
- Для каждой relation-группы выполняется один grouped aggregate; годы, publication type и subtitles используют отдельные bounded агрегаты. Полные коллекции тайтлов для подсчета в PHP не загружаются.
- Счетчики не кешируются между запросами. Изменения импортера в тайтлах, сезонах, сериях, media и pivot-связях видны на следующем запросе без дополнительной инвалидизации.

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
