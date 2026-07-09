# Связи данных и фильтры

Обновлено: 09.07.2026

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
- Несколько query-фильтров должны объединяться через условие AND.
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
