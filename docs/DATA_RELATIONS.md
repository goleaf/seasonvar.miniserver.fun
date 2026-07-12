# Связи данных и фильтры

Обновлено: 12.07.2026

## Основные связи

- `CatalogTitle belongsTo Source`
- `CatalogTitle belongsTo SourcePage`
- `CatalogTitle hasMany Season`
- `CatalogTitle hasManyThrough Episode`
- `CatalogTitle hasMany LicensedMedia`
- `CatalogTitle hasMany CatalogTitleAlias`
- `CatalogTitle hasMany CatalogTitleRating`
- `CatalogTitle hasMany CatalogTitleReview`
- `CatalogTitle hasMany SeasonvarImportEvent`
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
- `LicensedMedia belongsTo CatalogTitle`
- `LicensedMedia belongsTo Season`
- `LicensedMedia belongsTo Episode`
- `SourcePage belongsTo Source`
- `SourcePage hasOne CatalogTitle`
- `SourcePage hasMany Season`
- `SourcePage hasMany Episode`
- `SourcePage hasMany CatalogTitleReview`
- `SourcePage hasMany SourcePageSnapshot`
- `SourcePage hasMany SeasonvarImportEvent`
- `SourcePage belongsTo SeasonvarImportRun` через `last_import_run_id`
- `SeasonvarImportRun hasMany SeasonvarImportEvent`
- `SeasonvarImportRun hasMany SourcePageSnapshot`
- `SeasonvarImportRun hasMany SourcePage` через `last_import_run_id`
- Каждая модель связи каталога относится ко многим `CatalogTitle` через явную pivot-таблицу.
- Для метаданных каталога не используются morph- или polymorphic-связи.

## Целостность выпуска и публикации

- `CatalogStatus` через `catalog_status_catalog_title` описывает production status источника (`выходит`, `завершён` и подобные значения) и не управляет публичной видимостью.
- `publication_status` у `CatalogTitle`, `Season` и `Episode` использует `draft`, `published` или `hidden`; публичный scope дополнительно проверяет `available_from`, `available_until`, `audience` и `deleted_at`.
- `audience=public` доступна гостю, `audience=authenticated` — только переданному в `availableTo(User)` пользователю. Модели подписок и территориальных лицензий пока отсутствуют и не симулируются.
- `CatalogTitle.is_published` временно сохраняется как legacy-совместимый второй защитный флаг. Публичный тайтл обязан одновременно иметь `is_published=true` и `publication_status=published`.
- Обычные сезоны и серии имеют `kind=regular`, спецвыпуски — `kind=special`. Unique-ключи `(catalog_title_id, kind, number)` и `(season_id, kind, number)` разрешают специальный и обычный выпуск с одним номером, но запрещают дубли внутри вида.
- Порядок сезонов и серий детерминирован: `kind`, `sort_order`, `number`, `id`; обычные выпуски идут до специальных и не перенумеровываются из-за specials.
- `CatalogTitle`, `Season`, `Episode` и `LicensedMedia` используют soft delete; merge импортёра применяет физическое удаление только к уже объединённым дублям, чтобы не оставлять конфликтующие provider keys.
- Публичные медиа проверяют собственный status/window/audience и доступность связанных сезона и серии.

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
- `/titles` принимает повторяемые параметры `year[]`, `genre[]`, `actor[]`, `director[]`, `country[]`, `translation[]`, `status[]`, `network[]`, `studio[]`, `tag[]` и остальные типы из `CatalogFilterType`.
- Несколько годов объединяются как допустимый набор годов выпуска: тайтл может совпасть с любым выбранным годом.
- Несколько значений одного справочника внутри `genre[]`, `actor[]`, `director[]` и других relation-фильтров требуют совпадения всех выбранных значений этой группы.
- Несколько разных query-фильтров объединяются через условие AND.
- Счетчики в боковых фильтрах имеют два значения: количество в текущем фильтре и глобальное количество.
- Неверные значения фильтров не должны откатываться к полной выдаче каталога.
- Фильтры года должны принимать четырехзначный год от 1900 до следующего года.

## Индексы запросов

Каталог зависит от этих индексов для стабильных фильтров и быстрого обновления:

- Обратные pivot-индексы каждой связи по связанному ID и `catalog_title_id` нужны для фильтров и рекомендаций.
- `catalog_titles_indexed_at_idx` по `indexed_at` нужен для списков новых карточек.
- `catalog_titles_year_indexed_idx` по `year, indexed_at` нужен для списков с фильтром года.
- `source_pages_status_type_id_idx` по `parse_status, page_type, id` нужен для выбора страниц источника из очереди.
- `source_pages_type_status_crawled_id_idx` по `page_type, parse_status, last_crawled_at, id` нужен для циклов обновления.
- `licensed_media_title_status_published_idx` по `catalog_title_id, status, published_at` нужен для списков медиа карточки.
- `licensed_media_episode_status_quality_idx` по `episode_id, status, quality` нужен для выбора медиа серии.
- Уникальная пара `licensed_media.catalog_title_id + source_media_key` нужна для стабильного обновления видео-ссылок.
- Unique-пары `catalog_titles.source_id + external_id` и `catalog_titles.source_id + source_url_hash` сохраняют стабильную идентичность тайтла у внешнего провайдера.
- `catalog_title_ratings.catalog_title_id + provider` запрещает второй рейтинг того же провайдера для тайтла.
- Каждая metadata pivot-таблица имеет составной primary key по `catalog_title_id` и related ID, поэтому одну связь нельзя присоединить дважды.
- Publication lookup и display-order индексы на тайтлах, сезонах, сериях и медиа обслуживают публичные scopes и упорядоченные relationship loads.
- Индексы состояния страниц источника по `import_status`, `retry_after_at` и `last_imported_at` нужны для единственной команды импорта.

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
- исходные HTML-снимки для диагностики

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
- Без аргументов команда читает sitemap Seasonvar, сохраняет все найденные ссылки страниц каталога, затем обрабатывает очередь по одному запросу.
- С URL-аргументом команда обновляет эту карточку и найденные прямые страницы сезонов.
- Существующие связи, серии и медиа сохраняются, если они исчезли со страницы источника.
- Импортируемые сезоны и серии всегда записываются как `regular`, получают `sort_order=number`, публичное состояние и восстанавливаются после soft delete по стабильному составному ключу.
- Измененные название, описание, постер, рейтинг и поля видео-ссылок обновляются.
- Дубли страниц сезонов объединяются в одну `CatalogTitle`; сезоны остаются внутренними записями.
- Один запуск импорта держит cache lock, чтобы две копии `seasonvar:import` не обновляли одну очередь одновременно.
- Если частый cron стартует новую копию во время активного импорта, команда пропускает этот запуск с успешным кодом выхода.
- Каждый цикл импорта помечает неправильные вложенные ссылки Seasonvar как недоступные и проверяет ограниченный backlog старых медиа с пустым или устаревшим статусом доступности.
- Каждый цикл импорта нормализует старые состояния разобранных страниц источника и дозаполняет отсутствующие ключи медиа, качество, формат и перевод.

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
