# Комплексная оптимизация поиска и crawl-нагрузки

## Цель

Сократить время первого ответа и Livewire-обновления каталога `/titles` без смены SQLite и без новых production-зависимостей. Оптимизация должна устранить четыре подтверждённых источника задержки: комбинаторный обход query URL ботами, неверный порядок соединения ranked FTS, подсчёт всех фасетов до показа карточек и небезопасные эксплуатационные настройки вокруг SQLite/Blade cache.

## Выбранный подход

Реализуется комплексный SQLite-native вариант. Минимальный bot-only патч оставил бы тяжёлый пользовательский SQL и большой Livewire payload. Внешний поисковый сервис добавил бы отдельный индекс и согласование данных, но не устранил бы фасеты, card counts и crawl trap. Текущий FTS5 остаётся поисковым индексом, Redis — хранилищем HTTP limiter, а SQLite — единственным источником истины.

Новые Composer и npm зависимости не устанавливаются.

## Crawl control и SEO

Стабильные страницы каталога сохраняют индексируемые canonical URL. Любая интерактивная комбинация поиска, сортировки, вида, размера страницы, алфавита или нескольких фильтров получает `noindex,nofollow`. Ссылки, которые меняют только состояние `/titles`, получают `rel="nofollow"`; ссылки на карточки, стабильные directory hubs и тематические canonical pages остаются обычными.

`public/robots.txt` содержит отдельную группу `ClaudeBot` с запретом параметризованного каталога и консервативным `Crawl-delay`, а общий агент получает запрет query-вариантов `/titles?`. Robots является advisory boundary, поэтому маршрут `/titles` дополнительно получает именованный HTTP limiter. Обычный canonical GET имеет более высокий бюджет, query GET — более низкий, известный crawler — самый низкий. Limiter использует уже настроенное Redis limiter connection и возвращает стандартный HTTP 429 без раскрытия внутреннего состояния.

Directory hubs с интерактивными `q`, `letter`, `sort` или `decade` также получают `noindex,nofollow`, но их базовые страницы остаются индексируемыми.

## FTS-first ranked pagination

Filter-only count продолжает использовать один параметризованный `MATCH` и не вычисляет BM25. Ranked query меняет корневую таблицу: сначала материализуются FTS-кандидаты, затем через `CROSS JOIN` выполняется lookup `catalog_titles` по primary key и применяются visibility/filter constraints. Это фиксирует порядок SQLite plan: FTS candidate → catalog title row, а не полный проход опубликованного каталога → candidate lookup.

Для активного ready FTS выдача становится двухфазной:

1. Ranked query выбирает и сортирует только ID текущей страницы. Paginator использует заранее посчитанный filter-only total и не запускает второй ranked count.
2. Отдельный Eloquent query загружает только карточки текущей страницы, необходимые relations и `withCount`. Коллекция восстанавливает порядок ID и подставляется в тот же paginator.

Все существующие сортировки, exact-title/original/alias rank, BM25, year hard-filter, visibility, taxonomy filters и pagination URL сохраняются. Legacy path без готового FTS остаётся функциональным и использует прежнюю однофазную выдачу.

## Ленивая загрузка фасетов

Первый GET и каждое применение текстового поиска возвращают карточки, total, SEO и выбранные значения без полного набора фасетов. `CatalogSeries` хранит явное публичное состояние `facetsLoaded` и предоставляет метод `loadFacets()`.

На desktop вместо ещё не загруженного sidebar показывается компактная русская кнопка загрузки. На mobile существующая кнопка открытия фильтров одновременно открывает dialog и запускает Livewire action; во время запроса виден loading state. После загрузки используется существующая разметка и поведение фильтров. При изменении фильтра внутри открытой панели contextual counts продолжают обновляться. Новый текстовый поиск сбрасывает `facetsLoaded`, чтобы карточки снова приходили раньше тяжёлых агрегатов.

`CatalogTitlesPageBuilder` принимает флаг `includeFacets`. При `false` он не выполняет taxonomy UNION, year, publication type, subtitle и missing-selected context queries, но возвращает коллекции правильной формы, чтобы Blade не делал запросов и не содержал специальных database branches.

Когда фасеты запрошены и FTS готов, filter-only FTS IDs материализуются один раз в value object. Фасетные, year, publication type, subtitle и context-count queries переиспользуют этот набор через SQLite `json_each(?)`. Параметр содержит только целочисленные ID, сформированные приложением; пользовательский FTS expression по-прежнему передаётся единственным binding в исходный `MATCH`.

## Эксплуатационная часть

Перед SQLite maintenance проверяются активные import runs, очереди и процессы. Подтверждённый зависший диагностический `sqlite3` process останавливается только если PID и command line всё ещё совпадают. Количество активных systemd worker instances уменьшается до консервативного бюджета для четырёх CPU; текущие задания не удаляются и Redis queues не очищаются.

Compiled Blade views и `bootstrap/cache` получают владельца runtime-пользователя `www`, а документация деплоя требует запускать cache compilation от `www` либо исправлять владельца после root deployment.

После отсутствия активных importer writes создаётся SQLite online backup, выполняется `PRAGMA wal_checkpoint(PASSIVE)`, затем `PRAGMA optimize`. Не выполняются `migrate:fresh`, `db:wipe`, cache flush, WAL truncate или прямой `ANALYZE`. Настройки `cache_size` и `mmap_size` не меняются, поскольку измерение показало регрессию.

## Ошибки и degraded mode

- Недоступный Redis limiter не должен превращать поисковую страницу в раскрывающую topology ошибку; используется существующее Laravel middleware/error handling.
- Пустой materialized match set формирует `where 1 = 0`, не строит некорректный `IN ()`.
- Неготовый FTS не материализуется и автоматически использует legacy query path.
- Ошибка ленивой загрузки оставляет доступными карточки и повторную кнопку загрузки фильтров.
- Неудачный SQLite backup или наличие активного import write прекращает maintenance до PRAGMA-команд и явно сообщается оператору.

## Тестирование

### Crawl и SEO

- сложный `/titles?...` имеет `noindex,nofollow`;
- state links содержат `rel="nofollow"`, а card links не получают его;
- `robots.txt` содержит правила общего query crawl trap и отдельный `ClaudeBot` policy;
- named limiter ограничивает query GET и не блокирует нормальный canonical GET в пределах бюджета;
- directory interactive pages получают `noindex,nofollow`.

### SQL и данные

- `EXPLAIN QUERY PLAN` показывает FTS candidate coroutine/materialization до primary-key lookup каталога;
- ranked ID query не содержит card count subqueries;
- вторая фаза загружает только ID текущей страницы и сохраняет порядок;
- exact/alias/transliteration/taxonomy/year acceptance tests сохраняют результаты;
- materialized facet match выполняет один `MATCH` на один page-builder вызов с загруженными фасетами.

### Livewire и frontend

- initial render не содержит сотни facet controls и не выполняет facet queries;
- `loadFacets()` добавляет существующие controls;
- `applySearch()` снова скрывает фасеты до отдельной загрузки;
- desktop/mobile Playwright smoke подтверждает карточки, loading state, открытие фильтров, отсутствие console/network errors и уменьшенный initial HTML/Livewire payload.

### Проверки проекта

Сначала запускаются focused PHPUnit tests, затем Pint, полный `php artisan test`, documentation refresh check, `npm run build`, production-like query benchmark и Playwright QA. Изменения коммитятся только на существующую `main`.

## Критерии приёмки

- параметризованный bot traffic ограничен robots policy и Redis-backed HTTP limiter;
- ranked SQLite plan начинается с FTS candidates;
- card counts выполняются только для текущей страницы;
- initial `/titles?q=...` не вычисляет фасеты и не отправляет полный filter DOM;
- один facet load переиспользует один materialized FTS match set;
- `/titles`, Livewire search и фильтры сохраняют публичные URL и русское поведение;
- SQLite остаётся единственной базой, production dependencies не добавлены;
- focused/full tests, build и browser smoke проходят после реализации.
