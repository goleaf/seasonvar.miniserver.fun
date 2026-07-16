# Поиск по каталогу

## Public directory hubs

Directory search полностью локален и не обращается к Seasonvar или другим providers. Registry фиксирует одиннадцать index routes; taxonomy identity берётся из `CatalogTaxonomyRegistry`. Для actors/directors доступные буквы выводятся отдельными подписанными группами кириллицы и латиницы, а `#` остаётся в группе символов; порядок внутри групп задаёт `CatalogAlphabet`. Для years используются непустые decades и диапазон 1900..configured maximum. Состояние URL: `q` — нормализованная строка до 80 символов, `letter` — одна Cyrillic/Latin буква или `#`, `sort` — только `name_asc|count_desc`, `decade` — валидное начало десятилетия, `page` — paginator Livewire. Filtered/sorted variants получают `noindex,nofollow` и canonical index hub; page-only pagination сохраняет собственный canonical page URL.

Search suggestions общего `/titles` могут предложить подходящий локальный directory route по названию справочника. Это metadata-only registry lookup без внешнего HTTP и без загрузки taxonomy rows.

## Контракт запроса

HTTP query-параметр `q` проходит через `CatalogTitlesRequest`: строка приводится к Unicode NFKC, пробелы по краям удаляются, а последовательности Unicode-пробелов схлопываются. Непустая строка должна содержать от 2 до 80 Unicode-символов (`min:2|max:80`). Запрос из одного символа отклоняется с ошибкой `Введите не менее 2 символов для поиска.`, а строка длиннее 80 символов — с ошибкой `Поисковый запрос слишком длинный.`. Значение не обрезается молча. Array/object-shaped `q` считается пустым безопасным состоянием и не попадает в SQL.

Фильтры каталога передаются повторяемыми query-параметрами. Пример: `/titles?q=знахарь&year[]=2024&year[]=2023&genre[]=drama&genre[]=detective&actor[]=ivan-ivanov&sort=year_desc&page=2`. Несколько значений внутри одной группы объединяются через OR, разные группы, поиск, исключения и расширенные параметры — через AND. На scoped routes `/titles/{type}/{taxonomy}` и `/titles/year/{year}` route-значение гидратируется как первое выбранное значение соответствующей группы: дополнительная страна или жанр расширяет эту группу через OR, а значение другой группы сужает результат через AND. Снятие route-таксономии через checkbox, активный chip или групповой сброс переводит на `/titles`, удаляет только это значение и сохраняет остальные фильтры. SSR GET, `<noscript>` fallback и Livewire используют одинаковое объединение и дедупликацию. `q`, фильтры, сортировка и `page` синхронизируются Livewire 4 с browser history, поэтому refresh, back и forward восстанавливают выдачу.

Фильтр названий выводит отдельные группы кириллицы и индивидуальных латинских букв `A`–`Z`; выбор латинской буквы передаёт её как обычное значение `letter`. Контракт query-параметра не меняется: `letter=latin` продолжает поддерживаться как legacy-значение всей латиницы, `#` означает названия не с буквы, а кириллическая `Е` по-прежнему охватывает `Е` и `Ё`. Пагинация сохраняет route-контекст и все активные query-фильтры как в Livewire-переходе, так и в обычном GET fallback.

Mobile `GET /api/v1/titles` переиспользует ту же `CatalogTitlesRequest` normalization и `CatalogTitlesPageBuilder`, но не принимает web-only `view`, `type`, `taxonomy`. Его `per_page` равен 20 по умолчанию и ограничен 1–50. Indexed/unindexed PHP-массивы (`country[0]=turciia`, `country[]=turciia`) и повторяемые значения имеют одинаковый смысл. `GET /api/v1/catalog/filters` публикует отдельные `cyrillic`, `latin` и `other`, а directory API применяет тот же `CatalogDirectoryQuery` с отдельным v1 page size без изменения web defaults.

Расширенный UI «Точный подбор» не вводит новых query keys: он группирует существующие `year_from`, `year_to`, `updated`, `seasons_min`, `seasons_max`, `episodes_min`, `episodes_max`, `rating_source`, `rating_min`, `votes_min`, `video` и повторяемый `quality[]`. Групповой сброс удаляет только эти ключи, сохраняя `q`, обычные группы, `letter`, `sort`, `view` и `per_page`. Livewire action и обычный GET reset URL используют один и тот же allowlist из `CatalogSeriesFilters::ADVANCED_REQUEST_PROPERTIES`.

`CatalogSearchQueryParser::parse()` принимает строку и возвращает неизменяемый объект запроса. Он содержит отображаемую строку `raw`, нормализованный ключ `normalized`, не более восьми значимых токенов в исходном порядке, один распознанный год, состояние запроса, безопасное FTS5-выражение и SHA-256-хэши вариантов точного имени.

Один уникальный четырехзначный год распознается в диапазоне от 1900 года до следующего календарного года и исключается из текстовых токенов. Несколько разных допустимых годов автоматически не извлекаются и остаются текстовыми токенами. Чисто числовые токены сохраняются независимо от длины.

## Нормализация

- Вход приводится к Unicode NFKC, внешние пробелы удаляются, последовательности пробельных символов схлопываются.
- Поисковый ключ переводится в нижний регистр, `ё` приводится к `е`, а знаки пунктуации становятся границами токенов.
- Для точного сопоставления формируются варианты регистра, написания через `е` и `ё`, пользовательская транслитерация и совместимый вариант старых slug со сменой `kh` на `x`.
- В weighted FTS-полях сохраняется исходное написание; если поле содержит `ё`, рядом добавляется только его поисковый вариант с `е`. Поэтому `Фёдор` и `Федор` совпадают без потери исходного имени в документе.
- Буквенные токены длиной от трех символов получают quoted prefix expression. Двухсимвольные и числовые токены остаются точными quoted tokens, а несколько токенов соединяются явным оператором `AND`.
- Хэши точного имени строятся из приведенных к нижнему регистру и схлопнутых legacy-вариантов полной фразы.

## Состояния

| Состояние | Условие |
| --- | --- |
| `empty` | После display-нормализации строка пуста. |
| `insufficient` | В непустом вводе не осталось значимых токенов и не распознан год. |
| `ready` | Есть хотя бы один значимый токен или один распознанный год. |

## Сопоставление

`CatalogTitlesPageBuilder` вызывает `CatalogSearchQueryParser::parse()` один раз после HTTP-нормализации. Полученный неизменяемый `CatalogSearchQuery` передается в запрос результатов, пакетные счетчики связей и сгруппированные счетчики годов. Повторный разбор строки для отдельных фасетов не выполняется.

Visibility boundary поиска всегда ограничивает publication status, даты публикации, audience и soft delete. Готовый ranked FTS path начинает SQL с materialized FTS candidates, затем делает `CROSS JOIN`/primary-key lookup в `catalog_titles`; legacy fallback начинает с `CatalogTitleQuery::visibleTo()`. Оба driver ищут только в основном, оригинальном и альтернативных названиях, включая их транслитерацию и варианты `е/ё`. Полная значимая фраза сначала сравнивается с точным основным, оригинальным названием и хэшами алиасов. Если точного совпадения нет, каждый значимый терм должен совпасть с названием одного и того же тайтла. Описание, slug, `external_id`, актеры, режиссеры, жанры, страны и другие relations в `q` не участвуют; для них используются явные фильтры и справочники.

Распознанный в `q` год применяется как обязательный фильтр до текстового сопоставления. Если одновременно передан другой допустимый `year`, запрос получает нулевое условие. Такой конфликт и любой запрос с распознанным годом не заменяются fallback-выдачей.

Состояние `insufficient` добавляет нулевое условие, если `title` context отсутствует. Для существующего title-scoped запроса сохраняется исключение: при заданном допустимом `title` остается его `whereKey()`-ограничение. Неизвестный или недоступный `title` context также дает нулевую выдачу, а не полный каталог.

## Сортировка, ввод и пагинация

Публичный `sort` принимает только значения `CatalogSort`: `relevance`, `updated`, `year_desc`, `year_asc`, `episodes_desc`, `seasons_desc`, `with_video`, `title_asc`, `title_desc`, `kinopoisk_desc`, `imdb_desc`, `popularity_desc`. При активном поиске без явно заданной сортировки используется `relevance`; без поиска — `updated`. Направление уже закодировано в выбранном ключе; отдельный query-параметр `direction` не используется. Неподдерживаемый или array-shaped `sort` нормализуется к безопасному default, а пользовательское значение никогда не передается как column name в `orderBy()`.

Все ветки сортировки заканчиваются `catalog_titles.id DESC`, поэтому равные основные поля не меняют порядок между страницами. Search/filter/sort update сбрасывает страницу к 1. Livewire 4 приводит нечисловую, array-shaped, нулевую или отрицательную страницу к 1; если положительный `page` больше последней страницы после изменения числа результатов, компонент после count query перенаправляет на последнюю допустимую страницу. Такой redirect одновременно канонизирует browser-visible URL. Активные элементы пагинации являются обычными ссылками на точный paginator URL и усиливаются через `wire:click.prevent`, поэтому переключение страниц сохраняет route/query state и работает без JavaScript. Поисковое поле использует отложенный `wire:model`: поиск отправляется по Enter или кнопке «Найти», а пауза во вводе не запускает полную Livewire-пересборку выдачи и фасетов. Обычная GET-отправка формы остается fallback без JavaScript.

## Crawl budget и автоматическая панель фильтров

Стабильные canonical listings остаются индексируемыми. Поиск, сортировка, вид, размер страницы, алфавит и сложные filter combinations получают `noindex,nofollow`; ссылки, меняющие только состояние интерфейса, имеют `rel="nofollow"`. Card links и стабильные directory/taxonomy/year routes остаются followable. `public/robots.txt` запрещает query-варианты `/titles?`, а отдельная группа `ClaudeBot` дополнительно задаёт `Crawl-delay`. Каталог не применяет локальный request budget к crawler или пользовательским query GET.

Первый GET и новый текстовый поиск не вычисляют relation/year/publication/subtitle facets и не отправляют сотни checkbox в начальный Livewire HTML. Карточки и расширенные поля сразу рендерятся в sibling islands `catalog-live`, а годы и справочники автоматически запрашиваются отдельным связанным `@island(name: 'catalog-live', defer: true)`, который занимает позицию единого «Точного подбора» между панелью управления и результатами. Одноимённые islands не вкладываются друг в друга. Отдельных sidebar, mobile dialog, trigger и кнопки «Показать фильтры» нет.

`CatalogSeries` предоставляет раздельные computed-границы `catalogPage` и `catalogFacets`. Checkbox и select используют `wire:model.live`, поэтому выбранная страна, год, taxonomy, тип публикации, субтитры и select/качество точного подбора обновляют связанные islands, результаты и контекстные счётчики одним состоянием компонента. Текстовый поиск и числовые диапазоны остаются submit-driven, чтобы не запускать тяжёлый запрос на каждый символ; обычная GET-форма и `<noscript>` submit сохраняют fallback без JavaScript.

Когда facets запрошены при готовом FTS, `CatalogTitleSearch` один раз материализует filter-only candidate IDs в `CatalogSearchMatchSet`. Все contextual facet queries используют один параметризованный SQLite `json_each(?)` набор. В JSON попадают только нормализованные приложением положительные integer IDs; исходный пользовательский текст остаётся binding исходного `MATCH`.

## Acceptance corpus

| Регрессионный случай | Ожидаемый разбор |
| --- | --- |
| `OA` | `ready`, токен `oa`, точное FTS-выражение `"oa"`. |
| `FM` | `ready`, токен `fm`, точное FTS-выражение `"fm"`. |
| `11.22.63` | `ready`, токены `11`, `22`, `63`, год отсутствует. |
| `Знахарь 2019` | `ready`, токен `знахарь`, год `2019`, prefix-выражение `"знахарь"*`. |
| `смотреть онлайн` | `insufficient`, поскольку оба токена входят в список стоп-слов. |
| `znakhar` | `ready`, токен `znakhar`; legacy-варианты включают совместимое написание `znaxar`. |

Полный acceptance corpus закреплён в `CatalogSearchAcceptanceTest`: точное название, original title, alias и title+year проверяются на top-1/top-3. Отдельные негативные корпусы подтверждают, что имена людей, категории, жанры, страны и описания не входят в результаты `q`; варианты названий с `е/ё` остаются симметричными.

## FTS5-документы и rebuild

Additive migration `2026_07_13_170000_create_catalog_search_index` создаёт один `catalog_title_search_documents` на тайтл, external-content FTS5 table и три trigger для insert/update/delete. Удаление тайтла каскадно удаляет документ, а delete trigger удаляет FTS row. Миграция не backfill-ит каталог; singleton `catalog_search_index_states` остаётся в `building`, пока отдельная проверенная перестройка не завершится.

`CatalogSearchDocumentBuilder` принимает только заранее загруженный тайтл с алиасами. Поисковый документ содержит основное, оригинальное и альтернативные названия и их транслитерацию. Колонки `people`, `taxonomies` и `description` сохранены только для совместимости текущей схемы и всегда записываются пустыми. Детерминированный fingerprint не зависит от timestamps и порядка alias rows. Текущая версия документа — `3`; несовпадающая версия состояния немедленно оставляет публичный поиск на title-only legacy fallback до проверенной перестройки.

`CatalogSearchIndexer` пакетно загружает только алиасы, индексирует публично видимые тайтлы и выполняет upsert только при изменившемся fingerprint. Полная перестройка использует checkpoint ID и bounded `chunkById`; после каждого пакета checkpoint сохраняется. Команда:

```bash
php artisan catalog:search-rebuild --chunk=200
```

Команда отказывается работать при `queued` или `running` import run. Состояние становится `ready` только при совпадении source/document counts и успешном FTS5 external-content integrity check. Ошибка сохраняется в sanitized виде, checkpoint остаётся для resume, а публичный поиск продолжает legacy path.

## Ranked driver и синхронизация

При `ready`-состоянии совпадающей версии `CatalogTitleSearch` строит две параметризованные FTS5-границы. Основная выдача использует ranked candidate derived table как корень SQL: точное основное название, original title и алиас имеют отдельные ранги перед weighted BM25, затем выполняется lookup `catalog_titles` по primary key и применяются `indexed_at DESC`/ID tie-breakers. `LIMIT PHP_INT_MAX` не обрезает 64-bit выдачу, но не даёт SQLite сплющить derived table. Paginator сначала получает только ID текущей страницы; вторым запросом загружаются card columns, relations и counters только для этих ID с восстановлением ranked order. Total использует filter-only FTS без BM25, а загруженные facets переиспользуют один `CatalogSearchMatchSet` через `json_each(?)`. При `building`, `stale`, `failed`, несовпадающей версии или неполных counts запрос автоматически использует исправленный legacy driver, поэтому незавершённый индекс не скрывает тайтлы.

Importer индексирует только изменившийся тайтл после завершения его записей; metadata backfill отправляет один bounded batch изменённых ID; merger после commit переиндексирует canonical title, а duplicate document удаляется FK cascade и FTS trigger. Административные изменения тайтла и relations также синхронизируют документ. Ошибка incremental sync не откатывает доменную запись: индекс помечается `stale`, публичный портал немедленно переходит на legacy fallback.

## People lookup и typo suggestions

`GET /api/catalog/people?type=actor&q=Иван` принимает только `actor` или `director`, нормализованную строку 2–80 символов и возвращает через API Resource максимум 20 публичных вариантов: `type`, `slug`, `name`, `count`. Внутренние ID, publication state и source fields не сериализуются. Клиентский combobox использует debounce 300 мс, отменяет предыдущий `fetch` через `AbortController`, поддерживает стрелки, Enter и Escape; выбранный slug добавляется в обычный GET query.

Mobile `GET /api/v1/search/suggestions` использует тот же title-only parser, FTS candidate order и visibility boundary для максимума 5 title suggestions. До 5 actor и 5 director options добавляются отдельными типизированными подсказками и не влияют на выдачу `/titles` и `/api/v1/titles`. Search score, FTS document и index state не сериализуются.

`CatalogSearchSuggestion` работает только при готовом FTS и истинном нуле: если исходный FTS candidate существует, подсказки не строятся даже при конфликтующем фильтре. SQL по параметризованным общим триграммам отбирает не более 60 search documents, Dice similarity отбрасывает слабые совпадения, а UI показывает не более трёх ссылок в отдельном блоке `Возможно, подойдет`. Подсказки не входят в paginator, result count, canonical URL или SEO-утверждение о совпадениях.

## Production rollout

На SQLite создание индекса кратковременно блокирует schema writes. Deployment order: дождаться terminal состояния importer/finalizer, временно остановить queue workers, выполнить online backup, `PRAGMA quick_check`, согласованный WAL checkpoint и `PRAGMA optimize`, запустить `php artisan migrate --force`, затем `php artisan catalog:search-rebuild --chunk=200`, проверить state/counts/integrity и только после этого возвращать workers. Migration, optimize и rebuild не выполняются параллельно активному bulk import.

## Граница текущего продукта

Внешний поисковый сервис, переход с SQLite на PostgreSQL, персонализированное ранжирование и feature experiments отсутствуют как продуктовые возможности, а не являются скрытым implementation backlog. Текущий production-контракт — локальный SQLite FTS5 с детерминированным BM25/точным ранжированием и общими public visibility/filter boundaries. Любая смена движка или ранжирования требует отдельного измеренного design, миграции/rollback, privacy review и acceptance corpus; установка Scout, Meilisearch, Typesense или другого пакета сама по себе не считается реализацией.

## Поиск и фильтры коллекций

Directory `/collections` использует отдельный bounded UGC search по public approved name/description, real active/fallback editorial translations и public owner name; private, unlisted, pending/rejected/hidden/deleted records исключаются до поиска. Это дополнение к title-only FTS, а не расширение его document: collection text не смешивается с `CatalogTitleSearch` и не попадает в title snippets.

Внутри одной коллекции `CatalogCollectionQuery::items()` переиспользует canonical title visibility, title/original/alias normalization и существующие genre/country/status/year relations. Allowlisted sorting и paginator state находятся в URL; любое изменение criteria сбрасывает только `collectionPage`. Unique collection/title pivot и identity-first join предотвращают дубли от aliases/taxonomy translations. Stateful query URL получает clean collection canonical и `noindex`, но остаётся shareable и корректно восстанавливается browser back/forward через Livewire `#[Url(history: true)]`.

## Поиск, подсказки и фильтры тегов

Title `q` остаётся title-only FTS и не получает metadata fields. Public tags имеют отдельный `TagQuery::searchPublic`: минимум два meaningful Unicode symbols, escaped wildcard input, canonical name/normalized value/slug, active+fallback translation и approved locale/`und` aliases. Results сначала exact normalized match, затем visible serial count/name/ID; aliases возвращают canonical tag один раз. Explicit synonyms расширяют только найденные canonical IDs на один hop и общий limit, поэтому cycle/query explosion невозможны.

`GET /api/v1/tags?q=` и tag suggestions используют ту же boundary. Empty/wildcard-only query не выполняет широкую выборку; no-query API возвращает public popularity. Personal lookup использует отдельный owner-scoped `PersonalTagLibraryQuery`, исходное имя и normalized comparison, не public FTS/cache. Другие user labels/counts невозможно получить через query или timing-dependent count envelope.

Публичный `tag[]` filter и route `/titles/tag/{slug}` продолжают использовать общий `CatalogSeriesFilters`, `CatalogTitlesRequest`, `CatalogDirectoryQuery` и `CatalogTitleQuery`, а не второй filter engine. Input — allowlisted canonical slug/legacy alias resolution, relation matching — `whereHas`, выдача — distinct title identity, stable sort + ID tie-breaker. Locked route identity хранится отдельно от URL-bound form state: route tag/year не дублируется как `?tag[0]=...`/`?year[0]=...`, дополнительный filter остаётся shareable, а снятие route checkbox явно переводит на `/titles` и сохраняет независимые filters. Filter/sort/page URLs работают с browser history, но сложный state canonicalize/noindex согласно общему catalog SEO policy; одиночная eligible tag route имеет собственный clean canonical.

## Поиск существующего контента и заявок

Request form autocomplete переиспользует `CatalogSearchQueryParser`/`CatalogTitleSearch` и существующий alias-aware FTS, затем добавляет bounded public request candidates. При недоступном FTS есть escaped title/original/alias fallback; submit не доверяет autocomplete и всегда повторяет server-side content/exact/probable checks. Selection хранит stable title/season/episode ID, а не translated label; missing episode использует canonical sequence number.

Request directory ищет только public eligible title/original/alternative/normalized request fields, никогда private note, clarification, email, hidden source или importer detail. Query/type/status/sort URL state валидируется enum allowlist, search minimum/length bounded, sorting имеет ID tie-breaker, pagination сохраняет query/history. Exact duplicate сначала использует indexed active identity и overlapping allowlisted external IDs; probable/related search сужается type+target/title hash+year и ограничивается configured candidate limit. Полная таблица не сравнивается fuzzy в PHP.

## Discovery URL state и no-results

Canonical discovery URL — `/discover/{type}` и `/{locale}/discover/{type}`; default redirect выбирает `popular`, legacy `/recommendations` сохраняется 301. `type` — implemented enum strategy, поэтому отдельный arbitrary `sort` column отсутствует. Stable URL fields: `period`, `rating_source`, taxonomy slugs `genre|country|tag|actor|director|translation|studio`, `year_from|year_to`, `quality`, `subtitles=available`, `rating_min`, `votes_min`, `page`. Unknown/out-of-range values normalize to defaults; page max 500, result limit server-configured max 48.

Filters reuse `CatalogTaxonomyRegistry`, task 04 facet SQL and canonical visibility. Interface locale does not change media preference or title identity. Personal feedback/history/current user/recent IDs/seed never enter query. Filtered/authenticated/personal/random pages are noindex and canonicalize to stable public type; default filters are omitted. Search empty state links to clearly labelled popular discovery and never treats those cards as search matches.

Search/filter technical defects use the private Task 20 form with allowlisted `search`/`page` context; arbitrary query strings, signed/private parameters and raw search history are not copied. Ticket search is a separate viewer-scoped DB query and does not alter catalogue ranking, filters, public cache or URL contract. См. [`technical-issues.md`](technical-issues.md).
