# Конвейер импорта Seasonvar

Обновлено: 13.07.2026

## Граница данных

Единственная публичная команда — `php artisan seasonvar:import`. Последовательный и Redis queued-режимы сходятся в `SeasonvarCatalogImporter` и используют один конвейер:

1. `PoliteHttpClient` получает provider response с crawl delay, timeout и retry; успешный ответ и его hash фиксируются в `SourcePage`/`SourcePageSnapshot`.
2. `SeasonvarCatalogParser` извлекает данные без записи в базу.
3. `SeasonvarCatalogData::fromParsed()` валидирует обязательные поля, ограничения размеров и типы, нормализует строки и удаляет дубли внутри коллекций.
4. `SeasonvarCatalogIdentityResolver` ищет тайтл сначала по `(source_id, external_id)`, затем по каноническому URL hash или закреплённой source page. Название не является автоматическим идентификатором.
5. Короткая retry-aware transaction выполняет upsert тайтла, справочников, pivot-связей, provider ratings/reviews/signals, сезонов и серий и только затем помечает страницу разобранной.
6. Внешние playlist/media запросы выполняются вне catalog transaction; `source_media_key`, playback URL и unique keys делают повторную запись идемпотентной.
7. Import run counters обновляются после URL/chunk. `indexed_at` отмечает SQL-search visibility; Scout или внешний поисковый движок в проекте не установлен.
8. Полный sync/queued finalizer пересобирает рекомендации и обновляет stats cache; targeted URL run обновляет stats cache, но намеренно не запускает глобальное обслуживание каталога.
9. Новый тайтл получает `published/public`, но повторный import не меняет локальные publication status, audience, availability window, soft delete или slug. Публичный интерфейс всё равно повторно применяет `CatalogEntitlementService`.

## Queue coordinator и статусы

`/admin/imports` вызывает `SeasonvarImportAdminService`, который под Redis lock создаёт один `queued` run и отправляет `StartSeasonvarQueuedImport` только с scalar run ID. Coordinator имеет 3 attempts, backoff 60/300/900 секунд, timeout 900 секунд и unique lock на run. Transient network/408/425/429/5xx/SQLite-lock ошибки возвращают run в `queued` для retry; permanent validation/provider errors переводят его в `failed` без бесполезного повтора.

После начального SSR открытие видимой карточки в browser вызывает `wire:init` и может поставить один forced targeted refresh, если последнее успешное обновление было более 15 минут назад. SSR, crawler и клиент без JavaScript не создают import job. Browser передаёт только locked ID тайтла: URL читается из базы, нормализуется и повторно проверяется по HTTPS allowlist `seasonvar.ru`. Targeted pipeline разбирает каноническую страницу, затем все сохранённые прямые URL сезонов и записывает сезоны и серии внутрь того же `CatalogTitle`; глобальное обслуживание каталога для этого запуска не выполняется.

Page jobs принимают только IDs, lease token, canonical group key и force flag. Provider HTML/JSON, credentials и URLs в payload не кладутся. После всех claims finalizer сохраняет `completed`, а при ненулевых page/media failures — `partial`. Per-run unique lock не допускает дубликат одного finalizer job, а отдельный глобальный Redis lock `seasonvar-import-finalizer` сериализует catalog-wide cleanup, media maintenance, merge и recommendation rebuild между разными runs. Занятый lock не меняет итоговый статус: job обновляет heartbeat и возвращается в очередь с `SEASONVAR_QUEUE_FINALIZER_DELAY_SECONDS`. Все queue exception details проходят общий sanitizer, который убирает credentials, URLs и filesystem paths.

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
- Legacy merge допускается только для строк без `external_id`, если `SeasonvarImportGroupKey` подтверждает одну каноническую URL family. Разные непустые provider ID никогда не объединяются автоматически.
- Актёр/режиссёр: стабильная person URL используется как provider identity. Первый URL может закрепить ранее безадресную строку с тем же slug; другой стабильный URL при том же имени получает детерминированный hash suffix. Если provider не дал пригодный URL, fallback остаётся точным нормализованным именем — это известное ограничение текущей схемы без отдельной external-id таблицы.
- Жанры, страны, переводы, статусы, сети, студии, теги и возрастные рейтинги являются справочными значениями и идентифицируются нормализованным slug.
- Сезоны и серии используют существующие unique `(catalog_title_id, kind, number)` и `(season_id, kind, number)`. Pivot primary keys, alias/rating/review/recommendation unique keys и `licensed_media(catalog_title_id, source_media_key)` запрещают повторные строки.

Перед добавлением person-identity constraint проверена текущая база: обнаружен один неоднозначный повтор `actors.source_url` (`https://seasonvar.ru/actor/H&`) для разных имён. Поэтому migration добавляет только query indexes, а не небезопасный unique constraint. Сначала нужно очистить/переполучить такие обрезанные URL и только затем рассматривать уникальность.

## Владение полями

Provider владеет external/source IDs, URL/hash, crawl/import metadata, импортными рейтингами/отзывами/связями, release metadata и технической доступностью источника. Локальная редакция владеет slug, publication status, audience, availability window и soft delete.

Для `title`, `original_title`, `description` и `poster_url` используется безопасное трёхстороннее сравнение. `provider_field_values` хранит последний вход provider. Новое значение принимается, только если текущее поле пусто или всё ещё равно предыдущему provider value; отличающееся локальное значение сохраняется. Null/пустой provider value не стирает заполненное поле. У уже существующей строки без baseline первый import сохраняет текущее значение и только фиксирует provider baseline.

## Полные и частичные ответы

Parser фиксирует признаки `has_info_list`, `has_season_list` и `has_episode_script`. Отсутствие блока означает частичный ответ, а не удаление данных. Связи синхронизируются через additive `syncWithoutDetaching`, сезоны/серии/media только upsert-ятся, soft-deleted строки не восстанавливаются. Managed recommendation signals заменяются только при полном metadata snapshot. Политики удаления по complete snapshot пока нет; importer ничего не удаляет только из-за отсутствия записи в одном ответе.

## Версионированное восстановление metadata

`SeasonvarCatalogParser::METADATA_VERSION` задаёт текущую версию разбора связей. `source_pages` отдельно хранят успешно применённую и уже предпринятую версии, время разбора и allowlisted `metadata_presence`; `catalog_titles.relation_metadata_version` показывает версию производных связей тайтла. Поэтому изменение parser не требует немедленно повторно скачивать весь источник.

В начале полного цикла и queued finalizer `SeasonvarCatalogMetadataBackfill` разбирает только сохранённый `latestSnapshot` и не выполняет HTTP-запросов. Один запуск ограничен `SEASONVAR_METADATA_BACKFILL_PAGE_LIMIT` и `SEASONVAR_METADATA_BACKFILL_TITLE_LIMIT`; chunk-параметры управляют памятью. Валидный snapshot применяет связи и версии в одной transaction. Детерминированно невалидный snapshot продвигает только attempted version, чтобы одна строка не блокировала очередь; инфраструктурная ошибка не продвигает версии. Планировщик назначает `stale_metadata` только старой странице без пригодной ещё не предпринятой локальной копии и сохраняет обычную границу `refresh_after`.

Retention всегда оставляет snapshot с максимальным `captured_at`, а при равенстве — с максимальным `id`. При merge сохраняется минимальная relation version, чтобы устаревшие связи не маскировались актуальной канонической строкой. В `metadata_presence` допустимы только `present`, `rejected_invalid` и `absent_in_source`; raw provider values туда не попадают.

## Пересборка рекомендаций

Полная пересборка хранит профили компактно и строит bounded candidate pool только по информативным связям: жанрам, тегам, режиссёрам, актёрам, сетям и студиям. Страна, перевод, статус и возрастной рейтинг по-прежнему участвуют в точном score, но не могут сами расширить pool на весь каталог. `SEASONVAR_RECOMMENDATION_CANDIDATE_LIMIT` ограничивает число профилей для точного scoring одного тайтла, а `SEASONVAR_RECOMMENDATION_CANDIDATE_SCAN_PER_FEATURE` — число детерминированно выбранных тайтлов из одного общего признака. Итоговый лимит выдачи остаётся `SEASONVAR_RECOMMENDATION_MAX_PER_TITLE`.

## Порядок деплоя

1. Дождаться завершения активных import jobs и сделать backup SQLite.
2. Применить additive migrations через `php artisan migrate --force`, включая `2026_07_13_021800_add_health_state_to_licensed_media_table` и admin/import identity migrations по timestamp.
3. Health migration добавляет поля с безопасными defaults, backfill-ит прошлый `available` в `active`, известные failures в `unavailable` и ставит существующие проверенные строки в due backlog без удаления/merge данных.
4. Задать `SEASONVAR_IMPORT_ADMIN_EMAILS` и `SEASONVAR_MEDIA_CHECK_*`, пересобрать config cache и перезапустить queue workers через `php artisan queue:restart`.
5. Выполнить targeted repeat import на тестовой/проверочной странице и сверить counts/relations/health до и после.

Все указанные migrations additive и не делают удалений. Admin fields nullable и не требуют backfill; отсутствие editorial baseline намеренно заставляет первый repeat import считать существующее заполненное поле потенциально редакционным.
