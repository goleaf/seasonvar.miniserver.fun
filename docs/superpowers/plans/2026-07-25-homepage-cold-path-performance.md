# Оптимизация холодного пути главной страницы

> **Execution note:** выполнять последовательно через TDD в существующей ветке `main`; ветки и worktree запрещены правилами проекта.

**Цель:** вернуть гостевой главной cacheable HTML и убрать измеренные многосекундные запросы из синхронного cold path без ослабления видимости, приватности или API-контрактов.

**Архитектура:** сохранить текущую цепочку full-page Livewire → `CatalogHomePageBuilder` → domain queries/cache. Ограничить только обзор новых выпусков на главной, выбирая bounded newest rows для каждого из 12 тайтлов и сообщая о продолжении на полной странице тайтла. Для гостевой обзорной подборки использовать уже существующий индексируемый `RecentlyAdded`, тогда как персональная подборка авторизованного пользователя остаётся прежней. Полный snapshot из 48 обновлений и `/api/v1` не сокращать.

**Стек:** PHP 8.5, Laravel 13.22, Livewire 4.3, SQLite, PHPUnit 12.5, Tailwind CSS 4.3.

## Подтверждённая исходная причина

- рабочий HTML главной: 1 529 634–1 598 093 байта при `max_uncompressed_payload_bytes=1_500_000`;
- middleware возвращает `X-Seasonvar-Page-Cache: BYPASS`, поэтому каждый гостевой запрос пересобирает страницу;
- блок «Новые серии» занимает около 983 КБ: отдельные импорт-пакеты разворачивают 80–114 серий и столько же media rows для одного тайтла;
- cold `Trending` candidate query занимает около 2 748 мс; существующий `RecentlyAdded` с теми же exclusions возвращает 8 строк примерно за 326 мс;
- `latestReleaseGroups()` загружает сотни Eloquent-моделей и занимает около 567 мс;
- cache metrics: 33 rebuild, average 5 652,18 мс, hit ratio 0,06.

После первого GREEN cold rebuild раскрыл два дополнительных агрегата, ранее
скрытых прогретым snapshot: `latestTitleUpdates()` занял 3 259 мс, а точный
count 880 486 публичных media — 2 116 мс; полный builder занял 8 690 мс.
Существующие `episodes_created_at_idx` и `licensed_media_created_at_idx`
позволяют получить bounded newest events за 9–10 мс, но SQLite без index hint
выбирает status index и сортирует всю media table.

Первый adaptive implementation profile занял 3 893 мс: chunked exact
validation выбирала publication indexes вместо row-id probes, а
`video_title_ids` materialized все публичные media за 2 129 мс. SQLite
`NOT INDEXED` для bounded 2 048-ID validation дал 54–74 мс, а эквивалентный
correlated `EXISTS` для восьми video titles вернул те же ID за 5,34 мс.

## Сохраняемые контракты

- route names/URI, full-page Livewire boundary и локализованные aliases;
- `CatalogHomeSnapshotCache` с 48 фактическими обновлениями и форма `/api/v1` (`latest_titles`, `latest_releases`);
- фактический порядок `Episode.created_at`/`LicensedMedia.created_at`;
- visibility/watchability, publication, audience, premium, region и legal scopes;
- персональные рекомендации и remember-shown только для авторизованного пользователя;
- shared cache без user/session/private state и текущая targeted invalidation;
- страница тайтла остаётся полным источником всех сезонов, серий и видео;
- RU/EN parity, светлая mobile-first разметка и server-rendered content.

## Ожидаемые изменяемые файлы

- `app/Services/Catalog/CatalogHomeContentAdditionQuery.php`;
- `app/Services/Catalog/CatalogHomeMetricsCache.php`;
- `app/Services/Catalog/CatalogHomeSnapshotCache.php`;
- `app/Services/Catalog/CatalogHomePageBuilder.php`;
- `app/View/Components/Catalog/LatestMediaCard.php`;
- `resources/views/components/catalog/latest-media-card.blade.php`;
- `lang/ru/home.php`, `lang/en/home.php`;
- `tests/Feature/CatalogHomeContentAdditionTest.php`;
- `tests/Feature/CatalogHomeCardCountQueryTest.php` либо новый focused performance test;
- `tests/Feature/PublicPageResponseCacheTest.php`;
- `docs/frontend.md`, `docs/caching.md`, `docs/performance.md`;
- `docs/plans/current-task-plan.md`, `README.md`, `CHANGELOG.md`.

Новые migration, route, permission, package, environment variable, queue, scheduler или production DML не ожидаются.

## Task 1 — RED: bounded release overview

1. Добавить feature test с более чем восемью эпизодами и media одного тайтла за один день.
2. Проверить, что group query возвращает не более восьми newest episode/media rows, сохраняет правильный порядок и выставляет `has_more=true`.
3. Проверить малую группу с `has_more=false`.
4. Добавить HTTP assertion о локализованном сообщении и ссылке на полную страницу тайтла.
5. Запустить focused test и сохранить ожидаемый RED.

## Task 2 — GREEN: bounded SQL/hydration

1. Добавить одну именованную константу лимита обзора.
2. В `CatalogHomeContentAdditionQuery` ограничить episode/media rows per title на уровне SQL через `ROW_NUMBER() OVER (PARTITION BY ...)`, сохраняя те же public scopes и stable tie-breaker.
3. Запрашивать limit+1 только для определения overflow, затем передавать в group максимум limit episode rows, limit media rows и `has_more`.
4. После объединения episode rows и standalone/episode media в компоненте повторно ограничить итоговый список восемью release items; если два bounded набора дали больше восьми уникальных items, компонент также выставляет `has_more`.
5. Выводить локализованную ссылку на полную страницу тайтла.
6. Запустить focused tests и измерить рабочий query time/model counts.

## Task 3 — RED/GREEN: быстрый гостевой recommendation path

1. Добавить regression test, что guest builder использует `RecentlyAdded`, а authenticated builder сохраняет `Personalized`.
2. Изменить только guest recommendation type; убрать неприменимый synchronous fallback `Trending → Popular`.
3. Сохранить exclusions, 8-item limit, presentation, discovery URL и private remember-shown contract.
4. Запустить recommendation/privacy/cache regressions.

## Task 4 — RED/GREEN: cacheability и payload budget

1. Добавить production-shaped HTTP test с mass same-day additions и малым детерминированным uncompressed budget.
2. До исправления подтвердить второй `BYPASS`, после исправления — `MISS`, затем `HIT`.
3. Не повышать production payload limits.
4. Проверить, что cache hit не выполняет catalog queries.

## Task 5 — RED/GREEN: cold snapshot и метрики

1. Добавить regression test точного порядка newest additions и проверки
   SQLite query plan на существующие created-at indexes.
2. Заменить full-history `MAX/GROUP BY` на bounded adaptive event window:
   сканировать newest episode/media rows через existing indexes, повторно
   проверять authoritative episode/season/media/title visibility и расширять
   окно, пока 48-я запись доказанно новее непрочитанной границы.
3. Для bounded exact validation на SQLite запретить ложный secondary-index
   plan через `NOT INDEXED`, сохраняя model scopes; maximum window 20 000
   остаётся ниже подтверждённого SQLite parameter limit 32 766.
4. Заменить uncorrelated materialization всех media в `video_title_ids` на
   equivalent correlated `EXISTS`, чтобы indexed title order остановился
   после восьми совпадений.
5. Не добавлять migration: нужные индексы уже существуют и проверяются
   текущими schema tests.
6. Перевести только homepage metrics на отдельный стабильный version scope:
   public HTML invalidation не уничтожает последний точный count, а
   `CatalogCacheWarmer::refresh()` и explicit `forget()` остаются
   authoritative refresh boundaries.
7. Добавить test: общий catalog bump сохраняет старый быстрый metrics snapshot,
   explicit refresh получает новое точное значение.
8. Повторно измерить truly cold builder.

## Task 6 — Документация и verification

1. Повторно измерить builder queries, response bytes и два последовательных HTTP-запроса к рабочему route.
2. Выполнить `./vendor/bin/pint --dirty --format agent`.
3. Выполнить focused tests, затем релевантный широкий PHPUnit-набор и `npm run build`, поскольку меняется Blade/Tailwind markup.
4. Обновить canonical owners, visitor README history, русский CHANGELOG и compliance matrix.
5. Перечитать применимые canonical requirements и выполнить repository-wide legacy/duplicate scan.
6. Проверить `project:docs-refresh --check`, task-scoped `git diff --check`, точные task paths и ветку `main`.
7. Commit/push только если exact-path фиксация не затронет чужие staged/unstaged изменения; иначе честно оставить delivery `unresolved_shared_worktree`.

## Rollback и production impact

- Code rollback возвращает прежний обзор и `Trending`; schema/data/cache restore не требуется.
- Старые HTML/cache entries имеют versioned TTL и безопасно истекают; глобальная очистка cache запрещена.
- Частичный deploy безопасен только как атомарный code/assets release: новые group keys и Blade consumer должны выкатываться вместе.
- При unavailable cache запрос всё равно строит bounded HTML; unavailable recommendation data даёт существующий честный empty state.
- Queue, storage, service worker, PHP-FPM, Redis/Memcached configuration и importer data вручную не изменяются.
