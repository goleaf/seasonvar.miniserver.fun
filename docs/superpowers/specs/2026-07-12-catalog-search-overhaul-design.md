# Обновление поиска каталога

Дата: 12.07.2026

## Цель

Сделать поиск `/titles` предсказуемым, релевантным и быстрым для названий, алиасов, людей и справочников каталога. Поиск должен сохранять фильтры, честно показывать нулевой результат, поддерживать короткие и годовые запросы и оставаться работоспособным во время импорта Seasonvar.

## Исходное состояние

Текущая база содержит более 23 тысяч тайтлов, 65 тысяч алиасов, 85 тысяч актеров и 16 тысяч режиссеров. Почти все описания заполнены и в среднем значительно длиннее названий, поэтому равноправный поиск по описанию создает шум.

Подтвержденные дефекты:

- запрос без совпадений заменяется обычной выдачей каталога;
- запрос из стоп-слов или коротких токенов может полностью снять поисковое условие;
- год объединяется с текстом через `OR`, поэтому выбирается неправильная экранизация;
- отдельный токен может преждевременно завершить точный поиск и заменить полное намерение пользователя;
- `OA`, `FM` и `11.22.63` не обрабатываются как полноценные запросы;
- алиас ищется по `name_hash`, но существующий индекс начинается с другого столбца;
- поиск по десяти группам связей и описанию использует ведущий `%LIKE%`;
- выдача сортируется по обновлению, а не по релевантности;
- мобильные фильтры находятся после всех карточек и пагинации;
- `/titles` показывает две поисковые формы и почти не достигает честного empty state.

## Рассмотренные подходы

### 1. Доработка текущего `LIKE`-поиска

Плюсы: минимальная миграция и быстрый первый релиз. Минусы: ведущие wildcard-условия, повторные relation-подзапросы и отсутствие нормального ранжирования не позволяют стабильно достичь целевой задержки и качества на текущем объеме данных.

### 2. Поэтапный переход на SQLite FTS5

Плюсы: FTS5 уже доступен в SQLite 3.46.1 текущего PHP runtime, не требует production-зависимости и поддерживает взвешенное полнотекстовое ранжирование. Корректный legacy-путь остается безопасным fallback, пока индекс строится или признан устаревшим.

Минусы: требуется отдельный поисковый документ, синхронизация после импорта и процедура rebuild.

### 3. Внешний поисковый сервис

Meilisearch или Typesense дали бы встроенную typo tolerance и развитые фасеты, но добавили бы production-сервис, сетевую зависимость, отдельный deployment lifecycle и новый источник рассинхронизации. Для локального SQLite-каталога такого масштаба это не оправдано.

## Решение

Использовать поэтапный FTS5-подход. Сначала исправляется контракт и поведение текущего поиска, затем добавляются индекс и синхронизация, после готовности индекса включается ranked FTS5. Каждый этап остается отдельно проверяемым и откатываемым.

## Границы компонентов

### `CatalogSearchNormalizer`

Чистый сервис нормализации:

- Unicode NFKC;
- lowercase и схлопывание пробелов;
- `ё` и `Ё` приводятся к `е`;
- пунктуация становится границей токенов, но порядок числовых частей сохраняется;
- русские слова получают детерминированный вариант транслитерации;
- нормализатор не выполняет database queries.

Транслитерация должна давать ожидаемые пользовательские формы, включая `х -> kh`, чтобы `znakhar` находил `Знахарь`. Slug не считается поисковой транслитерацией.

### `CatalogSearchQueryParser`

Парсер возвращает immutable query object со следующими данными:

- исходная и нормализованная строка;
- значимые токены в исходном порядке;
- безопасное FTS5-выражение;
- один извлеченный допустимый год;
- состояния `empty`, `ready` и `insufficient`;
- нормализованные хэши для точного имени или алиаса.

Парсер не вставляет пользовательский ввод в raw SQL. Все кавычки, wildcard-операторы и служебные символы FTS формируются из уже разобранных токенов.

### `CatalogSearchDocumentBuilder`

Builder создает один документ на `CatalogTitle` из заранее загруженных связей:

- основное название;
- оригинальное название;
- алиасы;
- актеры и режиссеры;
- жанры, страны, теги, переводы и остальные заполненные справочники;
- контролируемые варианты транслитерации;
- описание с минимальным поисковым весом;
- строка имен для будущего typo suggestion;
- fingerprint содержимого.

Source URL, raw video URL, HTML snapshots и внутреннее состояние импортера в документ не попадают.

### `CatalogSearchIndexer`

Indexer принимает ID тайтлов, загружает все необходимые связи пакетно, строит документы и делает bulk upsert только изменившихся fingerprints. Полная перестройка использует `chunkById`, а не загружает каталог целиком.

Indexer не является observer: сохранение тайтла происходит раньше алиасов и pivot-связей, поэтому observer создавал бы устаревший документ и дополнительные запросы.

### `CatalogTitleSearch`

Search service строит ranked candidate subquery и отдает его существующему `CatalogTitleQuery`. `CatalogTitleQuery` продолжает владеть публикационным scope, фильтрами, годами и facet counts; полнотекстовое совпадение и relevance принадлежат `CatalogTitleSearch`.

### `CatalogSearchIndexState`

Внутренняя singleton-запись хранит версию индекса, статус `building`, `ready`, `stale` или `failed`, количество исходных и индексированных тайтлов и время успешного завершения. Она не выводится через публичный API.

В режиме `ready` с совпадающей версией используется FTS5. Во всех других состояниях применяется уже исправленный legacy-поиск, поэтому неполный индекс никогда не скрывает тайтлы.

## Структура базы

Additive migration создает:

- `catalog_title_search_documents` с `catalog_title_id` как primary foreign key с cascade delete;
- нормализованные ключи title/original title;
- отдельные weighted text columns для title, original title, aliases, transliteration, people, taxonomies и description;
- fingerprint и timestamps;
- `catalog_title_search_fts` как external-content FTS5 table с `unicode61 remove_diacritics 2`;
- insert/update/delete triggers между document table и FTS5;
- внутреннюю таблицу состояния индекса;
- индекс `catalog_title_aliases(name_hash, catalog_title_id)` для точного legacy и rollout lookup.

`down()` удаляет triggers и virtual table до обычных таблиц и удаляет только добавленный alias index.

## Контракт запроса

- `q` после нормализации имеет длину от 2 до 80 символов.
- Строка длиннее 80 символов или строка из одного символа дает русскую validation error, а не молча обрезается.
- Двухсимвольные и числовые токены ищутся точно.
- Prefix matching применяется только к буквенным токенам длиной от трех символов.
- Все значимые токены должны присутствовать в одном поисковом документе.
- Один самостоятельный год от 1900 до следующего календарного года извлекается и применяется как hard filter.
- Если одновременно заданы несовместимые `q`-год и `year`, результат равен нулю.
- Несколько разных четырехзначных годов не извлекаются автоматически и остаются обычными токенами.
- Глобальный запрос только из стоп-слов получает состояние `insufficient` и нулевую выдачу.
- Title-scoped SEO query может сохранить явно заданный `title` context даже при отсутствии значимых токенов.
- Нулевой результат никогда не заменяется общим каталогом.

## Ранжирование

Порядок приоритетов:

1. точное нормализованное основное название;
2. точное оригинальное название;
3. точный алиас;
4. weighted BM25;
5. `indexed_at DESC`;
6. `catalog_titles.id DESC` как стабильный tie-breaker.

Начальные веса BM25:

- title: 12;
- original title: 10;
- aliases: 9;
- transliteration: 7;
- actors/directors: 6;
- taxonomy: 4-5;
- description: 1.

При активном `q` сортировка по умолчанию называется `relevance`. Явно выбранные год, название, количество серий или наличие видео остаются доступными и используют relevance только как дополнительный стабильный порядок.

## Результаты и фасеты

Один candidate subquery используется для выдачи, общего count, годов и relation context counts. До добавления временной таблицы или кэша нужно измерить фактический query plan повторного FTS subquery.

Результаты ограничиваются опубликованными тайтлами. Eloquent продолжает eager-load карточных связей и aggregate counts; database queries из Blade не добавляются.

## Синхронизация с импортом

- импорт собирает измененные ID и выполняет пакетную индексацию после завершения page/batch transaction;
- direct URL import делает single-title flush;
- merger переиндексирует canonical title после commit;
- удаленные duplicate documents удаляются cascade foreign key и FTS trigger;
- неизмененная source page не пересобирает документ;
- ошибка incremental sync помечает состояние `stale`, после чего портал безопасно использует исправленный legacy driver;
- успешный rebuild возвращает состояние `ready` только после проверки количества документов и FTS integrity.

Публичная команда импорта остается `php artisan seasonvar:import`. Отдельная maintenance-команда `php artisan catalog:search-rebuild --chunk=200` только перестраивает локальный индекс и не импортирует Seasonvar.

Команда rebuild отказывается начинать работу при активном import run. Текущий длительный importer должен завершиться или быть штатно остановлен до применения migration и rebuild на рабочей SQLite базе.

## Безопасный rollout

- migration меняет только схему и не выполняет backfill production-данных;
- rebuild является отдельной resumable maintenance-операцией с checkpoint по последнему обработанному ID;
- до live migration и rebuild активный import run должен завершиться;
- перед операцией создается проверенная SQLite backup через согласованный с WAL механизм, а не простое копирование одного `.sqlite` файла;
- после завершения импортера проверяются WAL checkpoint, свободное место и SQLite integrity;
- search state остается неготовым до совпадения количества опубликованных тайтлов и документов и успешной FTS integrity check;
- при любой ошибке rebuild сохраняет прогресс, переводит state в `failed` или `stale` и оставляет портал на исправленном legacy driver;
- полный PHPUnit suite и browser QA не запускаются параллельно live importer, потому что часть существующих тестов использует общие storage paths; до остановки импортера допустимы только изолированные focused tests на in-memory SQLite.

## UI

- на `/titles` остается одна основная форма поиска, header search на этой странице скрывается;
- вне `/titles` header search называется `Поиск по всему каталогу`;
- форма каталога называется `Поиск по каталогу` или `Искать в выбранной подборке` при активных фильтрах;
- `Очистить поиск` удаляет только `q`;
- `Сбросить фильтры` сохраняет поисковый запрос;
- `Показать весь каталог` очищает весь контекст;
- честный empty state: `По запросу «…» ничего не найдено.` и `Проверьте написание или измените фильтры.`;
- insufficient state: `Запрос «…» слишком общий. Добавьте название, имя актера, режиссера или жанр.`;
- рекомендации показываются только отдельным блоком `Возможно, подойдет`, не входят в paginator и счетчик совпадений;
- текст SEO не утверждает поиск по сезонам, сериям и видео, пока эти поля не добавлены в документ.

## Mobile и accessibility

- до первого результата видны итог, `Фильтры · N` и компактная сортировка;
- desktop sidebar остается на `lg`, mobile filters открываются в native dialog;
- dialog закрывается `Escape` и возвращает фокус trigger;
- mobile cards используют компактную строку с постером около 80 px;
- search landmarks имеют уникальные доступные имена;
- активная сортировка получает `aria-current="true"`;
- выбранные фильтры объявляются как выбранные и удаляемые;
- interactive targets имеют минимум 44x44 px;
- focus-visible ring имеет толщину не менее 2 px;
- постер и название одного тайтла не создают два одинаковых tab stop;
- длинный непрерывный запрос не вызывает horizontal overflow на 320 и 390 px.

## Большие справочники людей

Актеры и режиссеры не загружаются целиком в Blade. Отдельный read-only lookup принимает только разрешенный тип, нормализованный `q`, ограничивает ответ 20 строками и возвращает публичные `slug`, `name` и count через API Resource. Выбранное значение всегда остается доступным, даже если не входит в текущую двадцатку.

Клиентский combobox использует debounce, отменяет устаревший request, поддерживает клавиатуру и не добавляет production dependency.

## Typo suggestions

После стабилизации основного FTS добавляется отдельный trigram candidate lookup по нормализованной строке имен из search document. Он работает только при истинном нуле результатов, возвращает ограниченное число кандидатов и проверяет similarity до показа.

Suggestions не меняют search result count, canonical URL или SEO-утверждение о совпадениях.

## Проверки

### PHPUnit

- validation: 1, 2, 80 и 81 символ, whitespace и scalar/non-scalar input;
- parser/normalizer: NFKC, `е/ё`, транслитерация, punctuation, stopwords и year extraction;
- ranking: exact title, original title, alias, person, taxonomy и low-weight description;
- запросы `Во все тяжкие`, `Breaking Bad`, `Friends`, `Death Note`, `Знахарь 2019`, `Чернобыль 2022` и `Le Comte de Monte Cristo 1961`;
- запросы людей `Милли Бобби Браун`, `Брайан Крэнстон` и `Федор Лавров`;
- категории `аниме`, `Япония`, `дорама`, `LostFilm` и `медицина`;
- короткие и punctuation queries `OA`, `FM` и `11.22.63`;
- transliteration `znakhar`, typo/zero state и stopword-only state;
- FTS triggers, cascade delete, idempotent rebuild, stale fallback и importer/merger sync;
- query parameter preservation, clear-search и reset behavior.

### Playwright

Viewports: `320x720`, `390x844`, `768x1024` и `1440x1200`.

Проверяются normal, filtered, insufficient, zero, suggestion, pagination и people lookup states; отсутствие overflow и console/local-asset errors; первый результат в первом viewport; keyboard flow dialog; возврат фокуса; targets 44 px; активные ARIA states; один tab stop на тайтл; back/forward и сохранение query parameters.

Артефакты сохраняются только в `output/playwright/`.

## Целевые метрики

- unique exact title/original/alias: 100% top-1 на acceptance set;
- year-qualified title: правильный год top-1, неправильный год отсутствует в top-3;
- exact person: не менее 90% precision@10;
- category: не менее 95% первых 24 карточек имеют соответствующую связь;
- short title, punctuation, `е/ё` и transliteration: ожидаемый тайтл в top-3;
- zero query: ноль посторонних карточек;
- warm SQLite search + count: p95 не более 150 ms;
- exact lookup: p95 не более 50 ms;
- полная страница каталога во время импорта: p95 не более 500 ms без учета сети и browser rendering.

Метрики снимаются отдельно на фиксированном acceptance corpus и на копии production-scale SQLite базы. Тест производительности не должен изменять рабочую базу.

## Этапы и Git-история

1. Контракт запроса, normalizer/parser, честные состояния и regression tests.
2. Additive FTS schema, index state, alias index, document builder/indexer и rebuild command.
3. Importer, direct import и merger synchronization.
4. Ranked FTS driver, relevance sort, year semantics и facet integration.
5. Search toolbar, reset actions, русские состояния и корректный SEO text.
6. Mobile filter dialog, compact result rows и accessibility contract.
7. Server-backed actor/director lookup.
8. Trigram suggestions при истинном нуле.
9. Query-plan tuning, benchmark acceptance set, полный PHPUnit и Playwright QA.

После каждого завершенного этапа обновляются относящиеся к нему Markdown-файлы, выполняется `php artisan project:docs-refresh`, запускаются focused checks, затем этап получает отдельный commit и push. Пункты acceptance criteria не дробятся на искусственные commits, если они реализуются одним и тем же атомарным изменением.

## Документация

В ходе этапов обновляются:

- `README.md` после включения нового пользовательского поведения;
- `docs/architecture.md` и `docs/DATA_RELATIONS.md` для search document и FTS lifecycle;
- `docs/performance.md` для query plan и p95;
- `docs/validation.md` для query contract;
- `docs/forms.md`, `docs/views.md`, `docs/frontend.md` и `docs/UI_STANDARDS.md` для search UI;
- `docs/testing.md` для acceptance matrix;
- `docs/CODE_STANDARDS.md` для index synchronization;
- `docs/MAINTENANCE_LOG.md` после каждого завершенного milestone.

## Не входит в задачу

- внешний search service;
- скачивание видео или индексация raw external URL;
- authenticated write/admin endpoint;
- изменение публичной команды импорта Seasonvar;
- morph relations для каталога;
- постоянный cache результатов до подтвержденной необходимости по профилю.

## Task 02: аудит и единая portal-search граница — 16.07.2026

Task 02 не вводит второй индекс или внешнюю поисковую службу. Аудит подтвердил, что канонический поиск тайтлов уже принадлежит `CatalogSearchNormalizer` → `CatalogSearchQueryParser` → `CatalogTitleSearch` → `CatalogTitleQuery`, использует SQLite FTS5 с безопасным legacy fallback и синхронизируется существующими importer/admin/merge путями. Общий autocomplete и `/search` должны переиспользовать эту цепочку, а `/titles` остаётся полной Livewire-выдачей с фильтрами, relevance sorting, точным count и стабильной пагинацией.

### Подтвержденная архитектура и ограничения

- Поддерживаются `ru` и `en`; `ru` — default и fallback. Route locale, session locale и сохранённая настройка account разрешаются существующими middleware. Search-cache всегда включает активный locale, public audience и нормализованный hash запроса.
- Метаданные тайтла не имеют отдельной locale relation: `title` — импортированное основное отображаемое имя, `original_title` — оригинальное имя, а `catalog_title_aliases` хранит альтернативные/provider-варианты. FTS ищет все эти значения и детерминированную транслитерацию независимо от interface locale. Audio/subtitle/translation-studio taxonomies остаются отдельным доменом.
- `Actor` хранит только стабильные `id/name/slug/source_url`; aliases, биография, фото и локализованные имена отсутствуют. Поэтому каноническая публичная страница актёра — существующая фильтрованная `/titles/actor/{slug}`, а `/actors/{slug}` сохраняется как совместимый redirect. Неподтверждённые биографические поля не создаются.
- Public `Tag` имеет translations, aliases, moderation/publication scopes и стабильный slug. Общий autocomplete должен учитывать base name, активный/fallback translation и approved aliases, возвращая один tag identity. Каноническая страница остаётся `/titles/tag/{slug}` с существующими presenter/SEO/cache правилами.
- Title visibility централизована в `CatalogEntitlementService`/`CatalogTitleQuery`: publication, audience, availability windows и soft deletion. Отдельных title-level полей region, premium или age entitlement в текущей схеме нет; Task 02 не имитирует отсутствующие ограничения.
- Внешний search engine, Scout и search analytics/history отсутствуют. SQLite FTS5 достаточен для текущего каталога; Redis/Memcached используются только существующим tiered cache и не являются обязательным поисковым backend.
- `/search?q=…` — shareable noindex/follow discovery page; `/titles?q=…` — каноническая полная выдача. Search query/filter combinations не входят в sitemap. Стабильные actor/tag pages сохраняют текущую indexing policy; alternate URLs не выдумываются, потому что эти routes сейчас не locale-prefixed.
- Header autocomplete сохраняет progressive `GET` fallback без JavaScript. Два bounded API scope (`header_titles`, `header_portal`) имеют 160 ms debounce, request abort и sequence guard. API ограничен existing named limiter, stale response не может перезаписать новый ввод.

### Task 02 решения

1. Оставить одну title-search semantics boundary и одну portal-suggestion boundary; header, mobile compatibility API и `/search` получают данные через них.
2. Расширить portal suggestion tag lookup на текущую locale/fallback/alias модель без загрузки всех переводов и без duplicate joins.
3. Возвращать из API полностью локализованную готовую metadata строку title suggestion. JavaScript не строит plural phrases и не форматирует числа самостоятельно.
4. Убрать presentation mapping из Blade `@php`; query/view data передают уже упорядоченные группы с переведёнными заголовками.
5. Показать на `/search` точный локализованный count тайтлов и отдельный combined preview count; полная pagination/filter/sort остаётся по канонической ссылке `/titles?q=…`, не дублируя `CatalogSeries`.
6. Добавить safe error boundary для общей страницы без раскрытия database/cache/exception деталей; обычный GET и no-JS fallback остаются работоспособными.
7. Инвалидировать `SearchSuggestions` после изменений public collections, public content requests и public profiles через существующие versioned invalidators. Персональные карточные overlays не помещаются в shared suggestion cache.
8. Перевести новые validation/count/error/a11y строки во все поддерживаемые locale и сохранить одинаковые placeholders/plural structure.
9. Миграции и production dependencies не добавлять: текущие FTS, taxonomy и pivot indexes соответствуют реальным query paths. Search history/popular-query storage не вводить без privacy/retention owner.

### Rollback

Изменения additive и не меняют route names, public model identity, FTS schema или API envelope. Откат Task 02 возвращает прежний presenter/cache-invalidation слой; title search продолжает работать через тот же FTS/legacy механизм, а header form — через обычный `GET /search`. Отдельная операция rebuild, queue worker или database backfill для отката не требуется.
