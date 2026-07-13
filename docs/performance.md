# Производительность запросов

Обновлено: 13.07.2026

## Правила

- Blade-шаблоны не выполняют запросы к базе и не содержат `@php`/`@endphp`.
- Связи, которые читает Blade или view-model слой, заранее загружаются в page-builder сервисах.
- Счетчики связей в списках считаются через `withCount()` или агрегированные запросы.
- Контекстные счетчики десяти relation-фасетов строятся `CatalogFacetQuery::taxonomyGroups()` одним bounded `UNION ALL`: каждая ветвь применяет собственный контекст, сортировку и limit до объединения. Это сохраняет семантику «все фильтры кроме своей группы» без общего window-sort по полным справочникам.
- Основная выдача использует pivot-таблицы только в сгруппированных `whereIn`-подзапросах. Поэтому один тайтл дает одну строку, `distinct` в paginator не нужен, а total count не расходится с видимой выдачей.
- Годовые счетчики считаются сгруппированными запросами по `catalog_titles.year`.
- Поисковый запрос разбирается один раз в `CatalogTitlesPageBuilder`; один неизменяемый объект используется для выдачи и обоих видов контекстных счетчиков, поэтому токены и распознанный год не вычисляются повторно и не расходятся между запросами.
- Для sitemap/feed с потенциально большим объемом данных используются `chunkById()`, `cursor()` или ограниченные страницы.

## Публичные страницы

- Главная страница загружает только опубликованные тайтлы, их опубликованные видео, годы и таксономии. Подборка «Сейчас можно смотреть» ограничивается индексируемым подзапросом published media, а связанные тайтлы в блоке новых серий дополнительно проходят `published()`.
- Главная страница загружает последние карточки с `CatalogTaxonomyRegistry::cardSummaryLoads()` и `withCount(['seasons', 'episodes'])`. Для taxonomy-моделей выбираются только `id/name/slug`, а для строки списка вместо всей коллекции сезонов загружается только `latestSeason(id, catalog_title_id, number)`.
- Страница списка каталога использует те же constrained relation loads, один компактный `latestSeason` на тайтл в list-режиме и счетчики сезонов/серий один раз на страницу пагинации.
- `CatalogSeries::render()` вызывает `CatalogTitlesPageBuilder` один раз; `mount()` только валидирует URL и не читает каталог. Контрольный прямой вызов page builder с непустым каталогом выполняет 11 SQL-запросов вместо 20; HTTP session middleware может добавлять свои запросы.
- Initial Livewire snapshot каталога содержит только `filters`, две короткие строки `optionSearch`, три locked route-поля и `paginators` (1288 байт JSON на контрольной базе); paginator, модели и facet-коллекции остаются только в render data.
- Поиск вариантов actor/director ограничен одним facet-запросом и 24 строками, не создает запрос на каждый пункт и не сериализует справочник в Livewire snapshot. Основная выдача сохраняет сгруппированные pivot-подзапросы, поэтому число строк paginator не размножается.
- Legacy-поиск сначала выполняет селективную `exists`-проверку полного точного названия или алиаса. Только при ее отсутствии строятся ограниченные парсером `AND`-группы термов; оба набора кандидатов остаются SQL-подзапросами и не материализуют коллекцию ID в PHP.
- Подзапросы алиасов и каждой связи группируют все legacy-варианты одного терма, чтобы не размножать одинаковые подзапросы на каждый вариант регистра, `е/ё` или транслитерации.
- Выдача, API, фасеты, публичные счетчики, sitemap/feed и рекомендации начинают title-запросы с `CatalogTitleQuery::visibleTo()`. Распознанный год добавляется до текстовых условий. Все сортировки сопоставлены в `CatalogSort` и завершаются `catalog_titles.id DESC` как детерминированным tie-breaker.
- Карточки списка выбирают только отображаемые поля, загружают constrained relations из `CatalogTaxonomyRegistry::cardSummaryLoads()` и считают видимые сезоны, серии и media через общие count-ограничения `CatalogTitleQuery`.
- Статическая часть страницы тайтла загружает справочники, aliases/ratings и summaries сезонов с агрегированными playable episode/media counts. Все серии всех сезонов больше не eager-load-ятся.
- Вложенный `CatalogTitlePlayer` загружает только серии активного сезона и их playable media; выбор first/next episode остаётся SQL query с детерминированным tuple-order, а не полной PHP-коллекцией выпусков.
- `CatalogPlaybackSourceResolver` переиспользует уже проверенные title/episode/season instances и выбирает только поля, нужные entitlement, ranking и player DTO. Контрольный episode resolve выполняет 2 запроса вместо 6; direct signed route по-прежнему самостоятельно загружает компактную publication hierarchy и повторно проверяет доступ.
- Публичный Livewire snapshot карточки содержит только locked `catalogTitleId` и URL-скаляры `season`, `episode`, `media`, `variant`, `quality`, `format`; Eloquent models, список просмотра, rating и progress остаются server/render-local. Watchlist count, user rating count и average собираются одним conditional aggregate по уникальным user/title строкам без join, поэтому provider ratings и pivot-умножение не искажают результат.
- Блок рекомендаций на странице тайтла не загружает тяжелые связи, потому что он показывает только базовые поля карточек.
- `/stats` обновляется через Livewire `wire:poll.15s.visible`, но читает серверный snapshot из `CatalogStatsSnapshotCache`; тяжелые агрегаты собираются не чаще одного раза в 15 секунд и имеют fallback на последний успешный снимок.
- `/stats` не рендерит `poster_src` для poster URL, которые `CatalogStatsPosterUrlGuard` не сможет безопасно проксировать; блок последних постеров берет расширенный набор кандидатов и оставляет только реально proxyable изображения, чтобы убрать лишние браузерные 404-запросы к `stats.poster`.
- Rate limiter использует отдельный `CACHE_LIMITER_STORE=file`, чтобы throttle-счетчики публичных маршрутов не создавали лишние записи в SQLite `cache` table.

## Sitemap

- `sitemap-landings.xml` сначала получает ограниченный список годов и top taxonomies.
- Реальные пары `taxonomy/year` считаются одним grouped join-запросом по pivot-таблице на каждый непустой тип справочника.
- Нельзя возвращать per-pair `exists()` для посадочных страниц: при росте каталога это снова станет N+1 по `taxonomy * year`.
- Регрессия закрыта тестом `SitemapAndRobotsTest::test_landing_sitemap_uses_grouped_taxonomy_year_queries`.

## Импорт

- `seasonvar:import` обновляет счетчики активного запуска после каждого обработанного chunk страницы или отдельного URL.
- Queued-режим пишет задания в Redis-очередь `seasonvar-import`; десять workers не резервируют jobs в SQLite и выполняют внешние HTTP-запросы вне catalog transactions.
- Диспетчер может запускаться десять раз в сутки, но живые lease и 24-часовой freshness interval не позволяют повторно ставить или скачивать свежую страницу при каждом cron tick.
- Импорт одного URL выполняет только targeted page/season pipeline и не запускает глобальные relation cleanup, media metadata/source-key backlog, merge и rebuild рекомендаций. Эти maintenance-операции остаются в full/sitemap cycle и queued finalizer.
- Catalog-wide queued finalization сериализована отдельным Redis lock `seasonvar-import-finalizer`: разные runs не выполняют одновременно cleanup, media backlog, merge и recommendation rebuild. TTL lock равен job timeout плюс 300 секунд; успешный или исключительный выход освобождает lock через `finally`, а аварийно убитый worker не оставляет вечную блокировку.
- Исключение сделано для ограниченного recovery-пакета: каждый запуск берёт не более `SEASONVAR_IMPORT_CHUNK_SIZE` старейших claimable-страниц `missing_data`. Живые claims отфильтровываются до limit, а после попытки обновлённые timestamps перемещают страницы в конец ротации, поэтому тысячи проблемных страниц не скачиваются одновременно.
- После импорта более поздней страницы сезона устаревшие title-level missing-data flags ранней страницы синхронизируются bounded database update без повторного HTTP-запроса. Pending, failed и claimed страницы сохраняют собственный lifecycle и не получают состояние соседней страницы.
- Страницы сезонов одного сериала используют общий Redis lock по canonical slug. Ключ вычисляется worker во время обработки, поэтому старые jobs с numeric key в сериализованном payload также не создают параллельные записи одного тайтла.
- SQLite работает в WAL с `busy_timeout=10000` и `IMMEDIATE` transaction mode: catalog transaction получает writer lock до первых reads, а десять workers продолжают параллельно выполнять внешние HTTP-запросы вне transaction.
- Массовая запись серий выполняется пакетами по 50 строк. Это сохраняет bulk-upsert и не превышает SQLite bind-variable limit даже для страниц с тысячами выпусков; предварительный запрос существующих серий не строит многотысячный `whereIn(number)`.
- Seasonvar playlist запрашивается с актуальным `?time=...`, но в `licensed_media.source_url` и `source_media_key` используется стабильный URL без volatile `time`. Повторный refresh поэтому не перезаписывает тысячи неизменённых media rows.
- Connection failures, HTTP 408/425/429/5xx и исчерпанная SQLite lock transaction повторяются Laravel worker с экспоненциальным backoff в ограниченном retry window. HTTP 404 и ошибки содержимого записываются как permanent result и не создают бессмысленный немедленный повтор.
- Абсолютный `retryUntil` page job не короче настроенного claim lease: большой Redis backlog не завершает job до первого `handle()`. Уже сериализованные payload сохраняют старый deadline; после его истечения `failed()` освобождает claim, и следующий queued cron выбирает страницу повторно.
- Queue jobs отправляются только after commit. Worker имеет явные `--memory=256`, `--max-time=3600` и `--max-jobs=1000`, поэтому долгоживущий PHP-процесс регулярно освобождает накопленные ресурсы.
- `seasonvar:import --status` использует Laravel 13 queue inspection methods и показывает pending, delayed, reserved, oldest pending job, общее число живых claims, число running runs и dominant run с максимальным backlog claims. Если active runs нет, показывается последний queued run.
- Проверка зависшего sync-lock распознаёт только Linux-процесс, чья command line начинается с PHP executable и `artisan seasonvar:import`; `watch ... --status`, `--queued`, `--help` и текст других процессов не считаются активным импортом.
- Страница состояния не должна ждать завершения длинного цикла, чтобы показать выбранные, обработанные, ошибочные и добавленные видео.
- После завершения `seasonvar:import` команда принудительно обновляет stats snapshot, чтобы следующий Livewire poll показывал финальные счетчики запуска.
- `/admin/imports` выбирает только отображаемые поля 20 последних запусков. Health totals и due total выполняются одним `UNION ALL` round trip по covering `licensed_media_health_due_idx`; active-state проверяется через `exists()`. Контрольный dashboard render ограничен пятью запросами.
- Backlog здоровья перечисляет конечные состояния `active/degraded/unavailable`, а не использует `health_status != disabled`: SQLite поэтому выбирает `licensed_media_health_due_idx` вместо full table scan.
- Health finalizer читает due backlog через `lazyById()`, но останавливает lazy stream после `SEASONVAR_MEDIA_CHECK_MAX_PER_CYCLE` строк. Default `20` ограничивает худший HTTP budget примерно 600 секундами при трёх попытках по 10 секунд; `chunk_size` остаётся независимой memory/query настройкой, а остаток переносится на следующие циклы.
- Регрессия закрыта тестом `SeasonvarImportMaintenanceTest::test_it_updates_import_run_counters_after_each_processed_page_chunk`.

## Измерения и планы SQLite

- На рабочей read-only базе (32 535 тайтлов, 245 380 actor pivot rows) последовательные relation-фасеты занимали 1568/1290 мс, bounded UNION — 1339/1397 мс. Это диагностический замер на текущем сервере, не SLA; query-budget закреплён тестом как 11 запросов полного page-builder вместо 20.
- `EXPLAIN QUERY PLAN` использует `catalog_titles_feed_query_idx` для public indexed ordering, `seasons_title_display_order_idx` и `episodes_season_display_order_idx` для release order. Дополнительные season/episode индексы не добавлялись.
- После применения уже существующей pending-миграции `2026_07_13_120000` история использует covering `episode_progress_user_history_idx (user_id, last_watched_at, id)` без временной сортировки.
- Health backlog и оба admin health aggregates используют covering `licensed_media_health_due_idx`. Дополнительный индекс не добавлялся: изменение предиката исправило план без роста write amplification.

## Cache boundaries

- Выдача, relation/year/publication/subtitle facets, progress, history и Continue Watching намеренно не кешируются между запросами: они зависят от publication windows, viewer access или приватного user state и должны быть свежими без shared-key риска.
- Единственный тяжелый публичный cross-request cache — агрегированный `/stats`: ключи не содержат private user data, fresh TTL равен 15 секундам, stale fallback — 15 минут, rebuild защищён lock.
- Sync-import обновляет stats snapshot после завершения; queued dispatcher сбрасывает только fresh key перед циклом, finalizer пересобирает fresh/stale snapshots после catalog/media/health изменений. `Cache::flush()` для lifecycle-инвалидизации не используется.
- Signed playback URL не кешируются: DTO создаётся на каждый authorized resolve и ограничен `playback.signed_url_ttl_seconds`.
