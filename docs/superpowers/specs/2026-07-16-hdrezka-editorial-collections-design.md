# Синхронизация редакционных коллекций HDRezka

Дата: 16.07.2026

## Цель

Портал локально и автоматически синхронизирует все редакционные подборки со страницы `https://hdrezka.my/collections.html`, обходит всю пагинацию каждой подборки, сопоставляет карточки только с уже существующими `CatalogTitle`, сохраняет оптимизированные WebP-обложки коллекций, публикует коллекции через существующий домен и использует подтверждённое совместное членство как дополнительный сигнал рекомендаций.

Полные видео, HLS, описания фильмов и удалённые постеры фильмов не импортируются. Карточки фильмов продолжают использовать метаданные и постеры локального каталога.

## Подтверждённое исходное состояние

- На странице источника обнаружено 54 коллекции и 51 уникальный URL обложки.
- Страница одной коллекции содержит 24 карточки и может иметь не менее 19 страниц пагинации.
- Локальная база содержит 32 926 тайтлов, 383 651 рассчитанную рекомендацию и пока не содержит коллекций.
- В проекте уже существуют `CatalogCollection`, публичный каталог коллекций, обложки на private `uploads` disk, редакционная модерация, sitemap/API/cache invalidation и полный прогрев `cache:warm-catalog --scope=all-public --queue --refresh`.
- Персональная выдача уже учитывает собственные коллекции пользователя, но рассчитанное сходство тайтлов не использует совместное членство в одобренных редакционных подборках.

## Границы решения

### Входит в объём

- Один allowlisted provider `hdrezka` с базовым HTTPS-host `hdrezka.my`.
- Полный обход страницы коллекций и pagination каждой найденной коллекции.
- Идемпотентное локальное хранение external identity, source item snapshot, match state и bounded run metrics без raw HTML.
- Создание и обновление существующих `CatalogCollection` типа `editorial`, видимости `public`, со статусом `approved`.
- Полная синхронизация membership только для source-managed коллекций.
- Локальная оптимизация именно обложек коллекций в WebP; фильмовые постеры берутся из локального `CatalogTitle`.
- Автоматический rebuild рекомендаций и запрос существующего полного прогрева после material membership change.
- Синхронный CLI-режим, queue-friendly расписание, distributed lock, resume-safe/idempotent повторный запуск и русские counters.
- Диагностика matched, ambiguous, unmatched, removed, cover failures и HTTP/parser failures.

### Не входит в объём

- Создание отсутствующих `CatalogTitle` из HDRezka.
- Импорт или публикация playback URL, видео, HLS, описаний, рейтингов, актёров или жанров HDRezka как authoritative metadata.
- Загрузка каждого постера фильма с внешнего сайта.
- Runtime-запросы к HDRezka при открытии посетителем страницы.
- Автоматическое прикрепление неоднозначного совпадения.
- Новый параллельный домен тегов, smart collections или второй recommendation repository.

## Архитектура

Поток синхронизации:

`Artisan/scheduler -> distributed lock -> guarded HTTP client -> collection index parser -> collection page parser -> normalized source rows -> matcher -> source-managed CatalogCollection reconciliation -> WebP cover storage -> recommendation signals/rebuild -> cache invalidation/warm request`.

Команда остаётся транспортным слоем. Сетевые запросы, parsing, matching, image processing и database reconciliation разделяются на небольшие сервисы. Сеть и WebP-конвертация никогда не выполняются внутри транзакции. Каждая коллекция применяется короткой транзакцией через bulk `upsert`/`delete`.

Синхронизация коллекций не встраивается в `seasonvar:import`: это отдельный источник редакционных связей, а `seasonvar:import` остаётся единственной публичной командой импорта Seasonvar.

## Модель данных

### `catalog_collection_sync_runs`

Одна строка на запуск: provider, status (`running|completed|partial|failed`), counters, timestamps и bounded sanitized error summary. Raw HTML, cookies и URL с query credentials не сохраняются. Индексы обслуживают поиск последнего запуска и активного запуска provider.

### `catalog_collection_sources`

Связывает stable `(provider, source_key)` с одним `catalog_collection_id`. Хранит normalized relative source path, remote display name, cover relative path/hash, semantic content hash, last successful sync, retry state и timestamps. Raw response не хранится. Unique provider/source key делает повторный discovery идемпотентным.

### `catalog_collection_source_items`

Хранит stable remote item key, source title, normalized title key/hash, nullable year, source type, normalized countries, relative detail path/hash, source page/position, match status (`matched|ambiguous|unmatched`), nullable `catalog_title_id`, match method/confidence/reasons и `last_seen_run_id`.

Индексы:

- unique `(catalog_collection_source_id, source_item_key)`;
- `(catalog_collection_source_id, last_seen_run_id, position, id)` для reconciliation;
- `(match_status, updated_at, id)` для отчёта и повторного matcher-а;
- `(catalog_title_id, catalog_collection_source_id)` для recommendation/cache fan-out;
- normalized search-document indexes для exact primary/original name lookup.

`catalog_collection_items` остаётся единственной публичной membership-таблицей. Source rows являются provenance/staging и не читаются Blade/API.

## HTTP и parsing

`HdRezkaCollectionHttpClient` принимает только HTTPS URL exact host `hdrezka.my`, стандартный порт и разрешённые paths:

- `/collections.html`;
- `/xfsearch/collections/.../` и `/page/{n}/`;
- `/uploads/mini/...` для cover;
- detail page `/{numeric-id}-{slug}.html` только для bounded ambiguous enrichment.

Клиент использует connect/total timeout, не более двух повторов только для transient 408/425/429/5xx/network failures, explicit User-Agent, максимум два same-host redirects, crawl delay и максимальные response sizes. Pagination следует только нормализованной same-collection ссылке и останавливается на отсутствии следующей страницы, повторном URL, повторном semantic hash, configured page limit или item limit.

Parser использует DOM/XPath и отдельные immutable DTO:

- index: name, collection path, cover path, discovery position;
- item page: remote numeric key, displayed title, year, countries, category/type, detail path, source order;
- optional detail JSON-LD: `alternateName`, `dateCreated`, type и genres только как matching evidence.

HTML не сохраняется. Fixture-тесты фиксируют только минимальные synthetic fragments с той же DOM-формой.

## Сопоставление с локальным каталогом

Matcher никогда не создаёт новый тайтл. Он использует существующие нормализатор, search documents, aliases и publication-safe catalog metadata.

Порядок:

1. Exact normalized match по `title`, `original_title` и aliases.
2. Hard filtering по году, если обе стороны содержат год.
3. Type compatibility (`film`, `series`, `cartoon`, `anime`) и country overlap как дополнительные критерии.
4. При нескольких кандидатах — bounded detail enrichment и повторный score по alternate/original title, type, year, countries и genre overlap.
5. Auto-match допускается только для единственного кандидата выше threshold и с достаточным отрывом от второго.
6. Иначе source row получает `ambiguous` или `unmatched` и не входит в `catalog_collection_items`.

Повторный запуск автоматически пересматривает unresolved rows после изменения локального search index/catalog metadata. Match reasons хранят только безопасные codes и числовой breakdown.

## Reconciliation коллекций

Для каждой source collection создаётся или разрешается один `CatalogCollection`:

- `type=editorial`;
- `visibility=public`;
- `moderation_status=approved`;
- `sort_mode=manual`;
- `content_locale=ru`;
- имя из источника после Unicode/plain-text normalization;
- global slug через существующий `CatalogCollectionSlugService`;
- `owner_id=null` для system-managed редакционной записи;
- featured управляется локальным администратором и не перезаписывается sync-ом.

Source-owned fields: name, membership order и imported cover. Local-owned fields: description/SEO translation, feature flag, moderation override и publication controls. Rejected/hidden/archived коллекция не возвращается в public автоматически; sync обновляет source snapshot, но не обходит локальную модерацию.

Membership собирается из всех `matched` rows текущего run, deduplicate-ится по `catalog_title_id`, получает минимальную source position и bulk-синхронизируется. Исчезнувшие или ставшие unresolved source rows удаляются только из соответствующей source-managed коллекции. Material change повышает `content_version` один раз и запускает existing targeted invalidation after commit.

## WebP-обложки

Загружается только collection cover, не фильмовые posters. Response ограничен размером, проверяется по MIME и фактическому декодированию. GD/Imagick создаёт WebP с сохранением aspect ratio, без upscale, с максимальными размерами и quality из config; metadata удаляется.

Файл хранится на существующем private `uploads` disk в каталоге `catalog-collections/{public_id}/imported/{content-hash}.webp`. База хранит существующие `cover_disk`, `cover_path`, `cover_mime_type=image/webp`, exact size и увеличенную `cover_version`. Controller продолжает отдавать файл через policy/no-store boundary. Старый owned imported file удаляется только after commit; одинаковый content hash повторно не конвертируется.

## Рекомендации

Каждое подтверждённое членство в approved public editorial source collection создаёт нормализованный recommendation signal с ключом provider/source collection. Полная успешная синхронизация удаляет только stale signals этого provider; частичный/failed run не выполняет destructive cleanup.

Recommendation v6:

- candidate generator индексирует bounded collection signal keys;
- pair scorer добавляет отдельный configurable source score за общие одобренные редакционные коллекции;
- слишком широкие коллекции получают frequency/size penalty и cap, чтобы «Фильмы Netflix» не подавляли сильные genre/tag/director signals;
- причина отображается короткой русской меткой «Одна подборка» только когда она реально повлияла на результат;
- доступность playable published media и существующий minimum relevance остаются обязательными.

Rebuild выполняется один раз после полностью успешного material sync. Partial run сохраняет безопасные добавления, но не удаляет старые signals и не запускает destructive full replacement.

## UI и меню

Новый отдельный маршрут не нужен: `/collections` и пункт «Коллекции» уже существуют. Directory получает более выразительную, но совместимую сетку:

- локальная 16:9 WebP-обложка;
- название, число найденных локальных тайтлов и отметка редакционной подборки;
- стабильные mobile/tablet/desktop колонки;
- существующие поиск, сортировка и пагинация;
- collection detail использует существующие карточки локальных тайтлов и фильтры.

Source URL, match status, remote title и диагностика не показываются посетителям. Администратор получает bounded summary последнего sync и counts matched/ambiguous/unmatched без raw URL или HTML.

## Cache, Redis и Memcached

Новая команда прогрева не дублирует уже готовую архитектуру. После material sync вызываются существующие `CatalogCollectionCacheInvalidator`, recommendation cache invalidation и `CatalogCacheWarmRequestStore`.

Операторский прогрев:

```bash
php artisan cache:warm-catalog --scope=all-public --queue --refresh
```

Он уже перечисляет directory pages, collection details, title pages, discovery, API, sitemap и documents. Redis остаётся queue/lock/L1 boundary; Memcached — допустимый L2 store согласно `config/cache-architecture.php`. HTML никогда не содержит user-specific state.

## Команды и расписание

Публичная команда коллекций:

```bash
php artisan catalog-collections:sync-hdrezka
php artisan catalog-collections:sync-hdrezka --dry-run
php artisan catalog-collections:sync-hdrezka --retry-unresolved
```

`--dry-run` выполняет discovery/parsing/matching и печатает counters без DB membership/cover/recommendation mutations. Обычный запуск защищён distributed lock. Scheduler запускает sync консервативно один раз в сутки, `withoutOverlapping`, `onOneServer`; включение, delays, limits и schedule задаются config/env без редактирования `.env` кодом.

## Ошибки и безопасность

- Любой URL повторно нормализуется и проверяется; off-host redirect или path отклоняется.
- Cookies, full HTML, secrets и stack traces не сохраняются и не выводятся.
- Один broken collection даёт `partial`, не откатывает уже успешно применённые независимые коллекции и не удаляет старую membership этой broken collection.
- Index fetch failure завершает run как failed без mutations.
- Cover failure не блокирует membership: остаётся предыдущая/fallback обложка.
- DB writes используют short transactions и retries; network/image work находится снаружи.
- Все counters и operator messages — на русском языке.

## Тестирование

- Unit: URL guard, index parser, item parser, pagination loop guard, matcher scoring, WebP dimensions/size/MIME, recommendation collection score.
- Feature: full fake HTTP sync с двумя страницами, idempotent repeat, removed item, partial page failure, ambiguous/unmatched retention, cover replacement, stale signal cleanup only after success, cache/rebuild dispatch.
- Console: validation, lock conflict, dry-run without writes, Russian summary and exit codes.
- Query/schema: required unique/index/FK contracts and no N+1 on directory/detail.
- UI: collection directory/detail snapshots at mobile/tablet/desktop, local cover delivery and fallback.
- Broad: focused tests, Pint, full `php artisan test`, `npm run build`, Playwright catalog QA.

## Rollout и критерии готовности

1. Применить additive migration и развернуть код при выключенном schedule.
2. Выполнить dry-run и записать counts collections/pages/items/matched/ambiguous/unmatched.
3. Проверить sample ambiguity и права на source/cover reuse.
4. Выполнить обычный sync с conservative delay.
5. Пересобрать recommendation v6, запросить all-public warm и проверить health/metrics.
6. Включить ежедневное расписание.

Готово, когда все обнаруженные коллекции имеют source record; каждая доступная страница pagination обработана либо отражена как bounded failure; все уверенно совпавшие локальные тайтлы находятся в правильной коллекции; ни одно неоднозначное совпадение не прикреплено; коллекционные обложки являются локальными WebP; публичные страницы не выполняют внешних запросов; рекомендации учитывают совместные подборки; повторный sync не создаёт дублей и не меняет версии при отсутствии material change.
