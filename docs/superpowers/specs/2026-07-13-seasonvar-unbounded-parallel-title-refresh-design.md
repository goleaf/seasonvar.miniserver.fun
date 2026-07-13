# Безлимитное параллельное обновление всего сериала Seasonvar

Дата: 13.07.2026

## Цель

Открытие любой серии публичного тайтла должно инициировать одну полную фоновую сверку всего сериала с Seasonvar, если предыдущая полная успешная сверка старше 15 минут. Система должна получить актуальный список сезонов, параллельно проверить каждую прямую страницу сезона, собрать все доступные серии и внешние варианты воспроизведения, сравнить source manifest с локальным каталогом и сохранить результат внутри одного `CatalogTitle`.

В коде приложения не устанавливается лимит числа одновременно созданных сезонных jobs. Для сериала с девятью прямыми страницами создаются девять page jobs, для сериала с пятьюдесятью — пятьдесят. Redis используется только как надёжный транспорт с retries; jobs не образуют последовательную chain и сразу доступны всем процессам приоритетного worker pool. Фактическое число одновременно исполняемых процессов всегда ограничивается доступными workers, памятью, CPU, сетевыми соединениями и ОС — приложение не может создать физически бесконечную вычислительную ёмкость.

`php artisan seasonvar:import` остаётся единственной публичной командой Seasonvar. Видео-файлы не скачиваются.

## Согласованный подход

Используется двухфазная схема fan-out/fan-in:

1. **Fan-out:** независимые workers параллельно загружают и подготавливают страницы сезонов, не изменяя общие связи `CatalogTitle`.
2. **Fan-in:** один title finalizer после завершения всех page jobs детерминированно применяет подготовленные результаты к одной карточке, выполняет точную сверку и объединяет подтверждённые дубли.

Такой подход сохраняет требуемую параллельность внешнего чтения и исключает потерю данных от одновременных `sync()`/upsert одного тайтла. Текущий Redis group lock сериализует весь page job и поэтому не удовлетворяет новой цели; после изменения он защищает только финальную консолидацию каталога.

## Триггер и дедупликация

- Browser-trigger остаётся в `CatalogTitleRefreshCoordinator`; SSR, crawler без JavaScript и обычный публичный GET не выполняют внешний HTTP-запрос.
- Открытие любой серии происходит внутри страницы тайтла и использует server-bound locked `catalogTitleId`. Клиент не передаёт source URL, номер сезона, queue name или force flag.
- Freshness window равен 15 минутам и относится только к одному `CatalogTitle`.
- Один активный refresh suppresses повторный dispatch того же тайтла, но не блокирует другие тайтлы.
- Окно 15 минут начинается только после полной успешной консолидации. `partial` и `failed` не считаются свежими и могут быть повторены согласно retry/backoff policy.
- Redis unique lock защищает coordinator job. Page-level claims защищают конкретную `SourcePage` от повторной загрузки в том же или пересекающемся запуске.

## Компоненты

### Title refresh coordinator job

Существующий `RefreshSeasonvarCatalogTitle` становится коротким оркестратором:

- повторно загружает `CatalogTitle` и проверяет сохранённый URL через `SeasonvarUrl`;
- создаёт адресный `SeasonvarImportRun` с `execution_mode=queue` и привязкой к `catalog_title_id` в безопасном summary;
- собирает canonical URL и все уже известные прямые `seasons.source_url`;
- нормализует, дедуплицирует и сохраняет соответствующие `SourcePage`;
- создаёт run-scoped title group;
- немедленно dispatches один preparation job на каждую известную страницу в `seasonvar-title-refresh` без chunk/concurrency cap;
- ставит title finalizer с короткой задержкой и завершает coordinator process.

Coordinator не держит group lock во время сетевых запросов и не ждёт page jobs синхронно.

### Run-scoped title group

Для безопасного fan-in требуется явное состояние группы одного тайтла. Additive migration вводит внутреннюю таблицу `seasonvar_import_title_groups` с:

- import run ID;
- nullable canonical catalog title ID: visitor refresh задаёт его сразу, а global group нового сериала заполняет после первого deterministic apply;
- необратимо хешированным canonical group key;
- статусом `discovering/running/finalizing/completed/partial/failed`;
- counters expected/prepared/failed/applied;
- timestamps и sanitized last error.

Уникальная пара `(seasonvar_import_run_id, group_key_hash)` не допускает вторую группу. Индексы обслуживают finalizer lookup и recovery. Raw source HTML и URL не попадают в operational status или browser payload.

### Parallel page preparation job

Один job обрабатывает одну `SourcePage` и содержит только scalar prepared-page ID. Claim token получается worker после загрузки server-side group/page state и никогда не сериализуется в Redis payload. Job:

1. Проверяет active run, group и claim до HTTP.
2. Повторно нормализует URL и ограничивает его `https://seasonvar.ru/`.
3. Выполняет HTTP-запрос с существующими timeout, retry и User-Agent.
4. Сохраняет crawl metadata и raw `SourcePageSnapshot`.
5. Разбирает HTML в валидированный `SeasonvarCatalogData`, но не пишет `CatalogTitle`, relations, seasons, episodes или licensed media.
6. Внутри того же сезонного worker раскрывает найденные playlist/media manifests существующими guarded HTTP services, чтобы finalizer не выполнял последовательные внешние запросы. Ошибка playlist сохраняется как warning этой prepared page и не уничтожает уже разобранные сезоны/серии.
7. Сохраняет run-scoped prepared payload с parser version, content hash, source page ID, season/episode/resolved-media facts и timestamp.
8. Извлекает все прямые season URLs из payload. Новые URL атомарно добавляются в ту же группу, получают claims и немедленно dispatches как дополнительные preparation jobs до освобождения parent claim.
9. Атомарно обновляет counters и освобождает собственный claim.

Prepared payload хранится во внутренней additive таблице `seasonvar_import_prepared_pages`. Строка содержит только group/run/source-page IDs, status, parser version, content hash, normalized JSON payload, sanitized warning counters и timestamps; уникальная пара `(seasonvar_import_title_group_id, source_page_id)` делает повторную подготовку idempotent. Payload не возвращается через API и удаляется bounded import storage maintenance после завершения retention window. Raw HTML остаётся только в существующей `source_page_snapshots`.

Page job не берёт title-group write lock. Поэтому все страницы сезонов могут одновременно выполнять внешнее чтение и CPU parsing. Короткие независимые записи snapshots/counters сохраняют SQLite `IMMEDIATE` transactions, busy timeout и bounded retry.

### Dynamic discovery

Локальный каталог может знать не все сезоны. Поэтому fan-out не ограничивается URL, существовавшими до открытия страницы:

- known URLs dispatches сразу;
- каждая успешно подготовленная страница сообщает все season links из актуального HTML;
- отсутствующая `SourcePage` создаётся idempotent upsert;
- новый URL увеличивает expected count и dispatches до освобождения текущего claim;
- повторно найденный URL не создаёт второй job;
- title finalizer начинает применение только когда нет активных claims и expected count равен сумме prepared и terminal failed.

Так устраняется race, при котором finalizer мог бы завершиться между освобождением parent page и постановкой вновь обнаруженного сезона.

### Title finalizer

Finalizer уникален по group ID и работает в `seasonvar-title-refresh`. Он:

1. Проверяет terminal accounting и release/retries, пока page jobs ещё активны.
2. Берёт существующий canonical `SeasonvarImportGroupKey` lock только на период catalog write.
3. Загружает prepared payloads и сортирует их детерминированно: canonical/current page, затем regular season number, specials и source page ID.
4. Повторно валидирует prepared payload parser version, content hash, URL family и соответствие выбранному `CatalogTitle`.
5. Применяет payloads через выделенный importer boundary с обязательным preferred canonical title.
6. Применяет уже подготовленные playlist/media candidates без новых source HTTP-запросов и сохраняет только внешние URL/metadata.
7. Запускает `SeasonvarTitleMerger::mergeForCanonicalSlug()` и сохраняет historical slug redirects.
8. Строит source/local manifests до и после применения, записывает bounded counters сверки в import-run summary и инвалидирует catalog caches.
9. Переводит refresh state в `completed`, `partial` или `failed`.

Одновременные page preparation jobs global importer для той же URL защищены page claim. Scheduled catalog write и visitor finalizer для одного сериала защищены общим canonical group lock.

## Сверка source и local manifests

Сверка выполняется по стабильным нормализованным ключам, а не по отображаемому тексту:

- сезон: `(kind, number, source_url_hash)`;
- серия: `(season kind, season number, episode kind, episode number)`;
- media candidate: stable `source_media_key`, с fallback на нормализованный playback URL только внутри существующей media boundary;
- metadata: parser completeness flags и provider field baseline.

Summary содержит только counts:

- source/local seasons before and after;
- source/local episodes before and after;
- source/local media candidates before and after;
- added, updated, unchanged, failed и local-only counts;
- expected/prepared/failed page counts.

Отсутствие ранее сохранённой серии на одной текущей source page не приводит к автоматическому удалению локальной записи: источник может вернуть частичную страницу или временно потерять блок. Система добавляет и обновляет подтверждённые source facts, помечает local-only расхождения для повторной проверки и сохраняет последнюю успешную информацию.

## Глобальный importer

Новые boundaries подготовки и применения используются не только browser refresh:

- глобальный queued importer продолжает dispatch page jobs через Redis;
- page job может использовать тот же fetch/prepare service, чтобы title-group lock больше не удерживался во время внешнего HTTP;
- catalog application для страниц одной canonical family выполняется через тот же deterministic group finalizer;
- global run finalizer ждёт завершения page preparations и title finalizers, затем выполняет только catalog-wide maintenance, merge и recommendation rebuild;
- синхронный импорт одной URL сохраняется как диагностический режим и вызывает те же prepare/apply services внутри одного процесса.

В рамках одной реализации сначала вводятся общие prepare/apply services, затем до production deploy на них переводятся visitor refresh, global queued importer и синхронный URL-режим. Ни один production path не должен иметь независимую реализацию parser persistence.

## Очереди и workers

- Все coordinator, page preparation и title finalizer jobs используют приоритетную Redis-очередь `seasonvar-title-refresh` для visitor-triggered запуска.
- Global importer использует `seasonvar-import`, но обе очереди вызывают общие services.
- Все сезонные jobs dispatches сразу; chains, per-title chunks и semaphore/concurrency limit отсутствуют.
- `seasonvar-title-refresh` остаётся раньше `seasonvar-import` в worker command.
- Production worker pool управляется systemd. Приложение не запускает `systemd-run`, shell-процессы или произвольные workers из HTTP-запроса.
- Для девяти сезонов одновременный старт всех девяти требует не менее девяти свободных worker processes. Если processes заняты, Redis сохраняет jobs до освобождения capacity; это инфраструктурное ограничение, а не последовательность в application data flow.

## Ошибки и восстановление

- Transient HTTP/connection/408/425/429/5xx/SQLite-lock ошибки используют retry window и backoff конкретной страницы.
- Permanent 404/gone/invalid page завершается terminal failure только этой страницы.
- Page failure не откатывает successful snapshots других сезонов.
- После исчерпания page retry group становится `partial`; finalizer применяет подтверждённые payloads и сохраняет старые данные неуспешных страниц.
- `partial` не запускает 15-минутное success window.
- Потерянный worker восстанавливается через claim expiry; job с устаревшим token не выполняет HTTP.
- Finalizer crash безопасно повторяется: apply operations используют существующие unique keys/upserts, а group state переходит в terminal status только после commit и cache invalidation.
- Queue dispatch failure освобождает только новый claim и увеличивает failed accounting.
- Browser получает только русский status и timestamps; URL, HTML, payload, tokens и exception details остаются внутренними.

## Безопасность и нагрузка

- Разрешены только нормализованные HTTPS URL внутри `seasonvar.ru`.
- Redirects, media URLs и playlist URLs продолжают проходить существующие guards.
- HTTP timeout, connect timeout и retry остаются bounded.
- Пользователь явно выбрал отсутствие application-level concurrency cap. Это увеличивает риск rate-limit/429 или блокировки источника; такие ответы обрабатываются как transient failures и не приводят к потере локальных данных.
- Payload jobs содержит только IDs/tokens, не HTML и не большие DTO.
- Raw snapshots и prepared payloads не раскрываются через API/Livewire.

## Тестирование

Реализация выполняется test-first с `Http::preventStrayRequests()` и `Http::fake()`.

Обязательные PHPUnit-сценарии:

1. открытие любой серии dispatches один unique full-title coordinator;
2. свежий completed refresh моложе 15 минут не dispatches новый run;
3. девять known season URLs создают девять независимых page jobs без chunk/concurrency cap;
4. пять разных тайтлов создают независимые groups и fan-outs;
5. page preparation jobs одной canonical family не используют title write lock;
6. найденный во время parsing новый сезон атомарно увеличивает group и dispatches один дополнительный job;
7. повторно найденный URL не создаёт duplicate job;
8. finalizer не начинает apply при живом parent/child claim;
9. workers могут завершить страницы в любом порядке, но final apply всегда детерминирован;
10. все payloads применяются к открытому canonical `CatalogTitle`, даже если Seasonvar numeric ID различается по сезонам;
11. source/local manifest после refresh содержит все source seasons/episodes/media и точные counts;
12. local-only данные не удаляются из-за partial source snapshot;
13. одна terminal page failure даёт `partial`, сохраняет successful pages и не создаёт fresh 15-minute window;
14. transient page failure повторяется независимо;
15. stale claim восстанавливается, stale-token job не делает HTTP;
16. global queued importer использует общую prepare/apply boundary и остаётся idempotent;
17. старые slug redirect ведут на canonical title;
18. raw URLs/HTML/errors отсутствуют в browser refresh state;
19. существующие parser, playlist, media, queued importer и title-page tests остаются зелёными.

После PHP-изменений выполняются focused tests, расширенные importer regressions, `./vendor/bin/pint --dirty --format agent`, полный `php artisan test`, `php artisan project:docs-refresh --check` и production queue/HTTP/DB verification. Frontend build нужен только если меняется Blade/JS/Tailwind markup.

## Документация

Обновляются владельцы тем из `docs/README.md`: importer, queues, environment/deployment, architecture, data relations, testing и changelog. Управляемые `project-docs` блоки изменяются только через `php artisan project:docs-refresh`.

## Критерии готовности

- Открытие любой серии инициирует полную проверку всего тайтла не чаще одного успешного запуска за 15 минут.
- Все known и dynamically discovered season pages dispatches немедленно, без application-level limit и без sequential chain.
- Внешнее чтение страниц одного сериала выполняется параллельно доступными workers.
- Общий каталог одного тайтла изменяет только deterministic finalizer под canonical group lock.
- В одной карточке находятся все найденные сезоны, серии и внешние media metadata; отдельные season titles не создаются.
- Source/local manifest и partial failures наблюдаемы через bounded internal counters.
- Failed/partial source не удаляет последнюю успешную локальную информацию.
- Visitor refresh не ждёт глобальный backlog.
- Global importer использует те же prepare/apply boundaries и сохраняет единственную публичную команду.
- Production verification подтверждает работу на реальном multi-season тайтле и отсутствие новых failed jobs от развертывания.
