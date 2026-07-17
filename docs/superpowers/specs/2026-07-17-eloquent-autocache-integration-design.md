# Интеграция Eloquent AutoCache

## Цель

Интегрировать `wddyousuf/eloquent-autocache` в Laravel 13-каталог как управляемый opt-in слой для повторяющихся публичных Eloquent-запросов. Интеграция должна реально уменьшать повторные чтения справочников, автоматически сбрасывать их после Eloquent-записей, не кэшировать приватные или зависящие от пользователя данные и не заменять существующие `TieredCache`, version registry, public-page cache и after-commit invalidators.

Успех подтверждается следующими наблюдаемыми условиями:

- проект фиксирует совместимую стабильную версию пакета в `composer.json` и `composer.lock`;
- повторное построение параметров стран и жанров для публичных топ-листов не выполняет второй одинаковый SQL `SELECT`;
- create, update и delete соответствующего справочника делают следующий opt-in read свежим;
- обычный запрос без `cache()` продолжает обращаться к БД и не становится кэшируемым случайно;
- чтения внутри открытой транзакции обходят cache, поэтому rollback не оставляет значение из незакоммиченного состояния;
- штатные `autocache:warm`, `autocache:flush`, `autocache:clear` и `autocache:stats` доступны оператору;
- значения совместимы с Laravel 13 `cache.serializable_classes=false`;
- документация описывает включение, откат, диагностику и границы ответственности.

## Проверенный baseline на 17.07.2026

- Приложение работает на PHP 8.5 и Laravel 13.20; пакет `wddyousuf/eloquent-autocache` версии `0.2.3` объявляет поддержку PHP 8.1+ и Laravel 10–13.
- Default domain cache — `redis-domain`; публичные DTO и HTML уже используют собственные `TieredCache`, `CacheVersionRegistry`, `CatalogCacheInvalidator` и bounded warming.
- PHPUnit использует SQLite in-memory и array cache; production использует Redis/Memcached topology с существующим `recomputable-failover` для восстанавливаемых значений.
- `CatalogTopListFilterOptions` повторно читает два простых публичных справочника: `Country` и `Genre`, ограничивая каждый результат 100 строками и выбирая только `id`, `name`, `slug`.
- Каталог содержит приватные user-owned модели, write-heavy importer state, сложные relation/pivot-запросы и прямые `DB::table()` mutations. Автоматическое кэширование этих границ без отдельной dependency/invalidation matrix небезопасно.
- Текущая документация запрещает второй доменный cache и store-wide flush. AutoCache поэтому дополняет только точечный Eloquent read path и не становится новым владельцем публичных page/snapshot caches.

## Рассмотренные варианты

### 1. Opt-in для двух публичных справочников — выбран

Package trait подключается только к `Country` и `Genre`, а единственный прикладной opt-in query принадлежит параметрам публичных топ-листов. Это даёт измеримый cache hit, небольшой invalidation surface и не меняет семантику приватных или импортных запросов.

### 2. Opt-in для всего публичного catalog graph

Trait можно добавить к `CatalogTitle`, сезонам, сериям, media, всем taxonomies и коллекциям. Выигрыш потенциально шире, но каждый join, subquery, eager relation и direct/pivot write потребует отдельной dependency map. Этот вариант дублирует уже существующие snapshot/page caches и отклонён до появления измеренного cold-path bottleneck.

### 3. Режим `auto` для всех Eloquent-моделей

Вариант требует минимального числа явных `cache()` вызовов, но кэширует private/viewer-specific запросы, создаёт write-time invalidation на importer и пользовательских моделях и повышает риск stale reads после direct SQL. Он не соответствует текущей cache/privacy архитектуре и отклонён.

## Архитектурная граница

Новый project concern `App\Models\Concerns\CachesCatalogFilterOptions` композиционно использует vendor trait `Wddyousuf\AutoCache\Traits\Cacheable`. Concern подключается только к `Country` и `Genre` и владеет одним точным query contract:

- `select(['id', 'name', 'slug'])`;
- `orderBy('name')->orderBy('id')`;
- `limit(100)`;
- явный `cache()`;
- тот же builder возвращается `cacheWarmupQueries()`, поэтому package warming прогревает реально используемый ключ, а не неиспользуемый `select *`.

`CatalogTopListFilterOptions` вызывает этот named query и по-прежнему преобразует модели в текущие массивы `{name, slug}`. Форма данных Livewire/Blade, маршруты, валидация фильтров и SEO не меняются.

Ни `CatalogTitle`, ни `User`, ни коллекции, reviews/comments, importer/queue state или media/access models trait не получают. Сложные catalog facets, personalization, search, recommendations, title pages и public HTML продолжают использовать существующие cache-aware services.

## Конфигурация

В репозитории хранится опубликованный и адаптированный `config/autocache.php`. Базовый production contract:

- `enabled=true` с `AUTOCACHE_ENABLED` как emergency kill switch;
- `mode=opt-in`; переход на `auto` не является эксплуатационной настройкой и требует нового code review;
- `store=recomputable-failover` по умолчанию, `AUTOCACHE_STORE=array` в PHPUnit;
- finite TTL 300 секунд и jitter 10%;
- project/environment/version-aware prefix, не содержащий пользовательских данных;
- `use_tags=false`, чтобы не вводить второй tag invalidation mechanism;
- `lock_for=5` секунд для bounded stampede protection;
- `row_cache=false`, потому что выбранный contract кэширует bounded lists, а не `find()`;
- `cache_in_transactions=false` для rollback correctness;
- `swr=0`, поскольку stale/flexible semantics остаётся ответственностью существующего `TieredCache`;
- `max_rows=100`, совпадающий с query limit;
- `stats=false` по умолчанию; встроенные counters можно включить временно через `AUTOCACHE_STATS=true`;
- `models=[Country::class, Genre::class]` для deterministic CLI discovery;
- пустая `pivot_invalidation.map`, потому что кэшируемые строки зависят только от собственных таблиц справочников.

`.env.example`, `docs/environment.md` и deployment runbook перечисляют переменные без секретов. Реальный `.env` задача не изменяет.

## Поток чтения

1. Публичная top-list boundary запрашивает страны или жанры через `CatalogTopListFilterOptions`.
2. Named model query явно вызывает `cache()`; все прочие запросы модели остаются uncached из-за `mode=opt-in`.
3. AutoCache строит ключ из model/table, connection, SQL, bindings, columns и project prefix.
4. Первый запрос читает authoritative SQLite, сохраняет только plain row arrays/scalars и возвращает обычные hydrated Eloquent models.
5. Повторный идентичный запрос получает те же модели из cache без повторного SQL.
6. Существующий page/snapshot cache может полностью обойти этот cold path; AutoCache не меняет внешний response cache contract.

В cache value не входят request, session, user ID, права, source URL, Eloquent object graph или HTML.

## Инвалидация и транзакции

Create, save/update, delete и bulk writes, выполненные через cacheable Eloquent builder `Country` или `Genre`, используют штатную self-invalidation пакета. Invalidation выполняется сразу для read-after-write consistency и повторно after commit, когда write находится в транзакции.

`cache_in_transactions=false` запрещает cache read/write внутри любой открытой DB transaction. Это намеренное отличие от package default: тесты для hit path не должны полагаться на `RefreshDatabase` transaction wrapper и используют изолированную migration lifecycle там, где проверяют настоящий cache hit.

Direct `DB::table()` writes пакет самостоятельно не видит. Existing catalog/import boundaries остаются владельцами таких mutations. В этой версии не добавляется глобальный query listener и не включается pivot map: выбранные cached lists не зависят от pivot membership, а direct mutation справочника должен либо перейти через Eloquent builder, либо явно вызвать `AutoCache::flush(Country::class|Genre::class)` в той же after-commit boundary. Документация фиксирует это как обязательное правило для будущих direct writers.

Store-wide `Cache::flush()`, Redis `KEYS`, wildcard deletion и очистка чужих cache domains запрещены.

## Отказы и эксплуатация

Cache остаётся производным слоем, SQLite — источником истины. `recomputable-failover` позволяет восстанавливаемому query cache перейти с Redis на существующий file fallback; finite TTL ограничивает жизнь значения после отказа. Ошибка cache не должна превращаться в разрешение доступа, потому что выбранные values являются только публичными справочниками и не участвуют в authorization.

Операторские действия:

- `php artisan autocache:stats` — встроенные counters, когда `AUTOCACHE_STATS=true`;
- `php artisan autocache:warm --all` — прогрев exact country/genre queries;
- `php artisan autocache:flush "App\Models\Country"` — точечный сброс;
- `php artisan autocache:clear` — сброс только зарегистрированных AutoCache models, не всего application cache;
- `AUTOCACHE_ENABLED=false` плюс config cache rebuild и graceful reload — runtime rollback без удаления dependency;
- Composer rollback выполняется только после выключения feature и удаления trait/query usage/config в отдельном tested change.

Package cache events продолжают проходить через Laravel cache events и существующую operational telemetry без записи raw keys. Встроенная package statistics остаётся opt-in, чтобы не добавлять постоянные Redis increments на каждый hit/miss.

## Тестирование

Реализация следует TDD и разделяет проверки:

1. dependency/config contract подтверждает opt-in, strict transactions, no tags/SWR/row cache, bounded rows, registered models и `cache.serializable_classes=false`;
2. первый exact filter query даёт miss, второй — hit без второго SQL;
3. обычный query без `cache()` выполняется дважды;
4. create, update и delete инвалидируют соответствующий cached list и возвращают свежий результат;
5. rollback test прогревает значение вне транзакции, выполняет write/read/rollback и подтверждает отсутствие poisoned cache;
6. country write не сбрасывает genre cache и наоборот;
7. cached hydration при `serializable_classes=false` возвращает `Country`/`Genre`, а не incomplete object;
8. `autocache:warm --all`, model flush, clear и stats commands завершаются успешно и ограничены зарегистрированными моделями;
9. существующие `CatalogTopListPageTest`, cache infrastructure tests и полный PHPUnit suite подтверждают отсутствие регрессии;
10. `composer validate`, `composer audit`, Pint, static analysis и полный project CI gate проверяют dependency и PHP-контракты.

Frontend assets и визуальный контракт не меняются; `npm run build` нужен только если параллельные уже существующие изменения затрагивают Blade/Tailwind/Vite, но не является доказательством AutoCache integration.

## Документация и rollout

Реализация обновляет `README.md`, `CHANGELOG.md`, `.env.example`, `docs/caching.md`, `docs/environment.md`, `docs/deployment.md` и dependency/upgrade documentation. Обычный русский текст сохраняется русским, команды и identifiers — в исходном написании. Управляемые `project-docs` blocks изменяются только через `php artisan project:docs-refresh`.

Rollout выполняется после `composer install` из lock, config cache rebuild и graceful reload PHP-FPM/workers. Сначала package остаётся включённым только для двух зарегистрированных справочников; оператор подтверждает повторный cache hit и свежесть после controlled write. Расширение на новую модель требует отдельного измерения, privacy classification, списка direct/pivot writers, invalidation test и обновления этой cache owner documentation.

## Вне области

- Автоматическое кэширование всех Eloquent models.
- Кэширование viewer-specific, authenticated или private queries.
- Замена `TieredCache`, public-page cache, recommendation/search/tag snapshots или `CacheVersionRegistry`.
- Новый Redis/Memcached service, cache store, migration, queue или scheduler.
- Cache tags, pivot query listener, row cache и stale-while-revalidate.
- Ручное изменение production `.env` или очистка production cache.
