# Дизайн постоянно прогретого публичного кэша

**Дата:** 15.07.2026

**Статус:** утверждено пользователем для автономной реализации

**Область:** публичные web-страницы каталога, импортная инвалидация и очередь `cache-warm`

## Цель

Повторный гостевой запрос к главной и другим детерминированным публичным страницам должен возвращаться из Redis/Memcached без SQL-запросов к каталогу. После authoritative catalog write кэш инвалидируется только после commit, а актуальные страницы заранее прогреваются одним объединённым потоком.

База SQLite остаётся единственным источником истины. Общий кэш не хранит Eloquent-графы, пользовательские данные, raw media URL, CSRF-токены или долгоживущие playback-подписи.

## Исходное состояние

- `TieredCache`, `CacheVersionRegistry`, Redis domain/locks, Memcached hot tier, `CatalogCacheInvalidator` и `WarmCatalogCaches` уже существуют.
- Главная кэширует ID и агрегаты, но затем повторно читает карточки и связи из SQLite.
- Домены `title-detail`, `recommendations`, `sitemap` и `api` в основном описывают policy; полноценный server-side snapshot включён не для всех.
- На 15.07.2026 `cache-warm` содержит более 27 тысяч ожидающих jobs, worker не установлен, warming state равен `unknown`. Текущий `uniqueFor=300` позволяет lock истечь, пока job долго ждёт worker, и не предотвращает дальнейшее накопление.
- Публичный title HTML содержит session-specific CSRF и временную signed playback URL, поэтому его нельзя сохранять как обычную общую строку.

## Рассмотренные подходы

### 1. Кэшировать только DTO/ID каждого builder

Плюсы: максимально явный доменный контракт, простой контроль приватных полей. Минусы: для полной страницы потребуется сериализовать и восстанавливать большой граф title/season/episode/media, paginator и Livewire state; много существующего кода всё равно ожидает Eloquent-модели. Это хороший нижний слой, но он не даёт быстрый результат для всех страниц без большого переписывания.

### 2. Общий full-response cache внутри Laravel — выбранный вариант

Успешный гостевой HTML сохраняется как безопасная строка в существующем `TieredCache`. Middleware возвращает кэш до controller/Livewire render, поэтому на hit не выполняются catalog SQL queries. Динамические секреты заменяются markers при записи и восстанавливаются для текущей session при чтении. Существующие DTO/ID snapshots остаются cold-path и warming основой.

### 3. Только nginx/CDN microcache

Плюсы: минимальная стоимость PHP. Минусы: нет надёжной связи с transaction commit и title-scoped versions, сложнее исключить authenticated/session-bearing ответы и обновлять playback signatures. HTTP shared cache остаётся дополнительным слоем, но не authoritative реализацией этой задачи.

## Граница кэширования

Общий response cache включается только для `GET` с HTML-ответом и только когда одновременно выполнены условия:

- пользователь не аутентифицирован;
- отсутствует `Authorization` и `X-Livewire`;
- route явно подключил middleware с профилем `homepage`, `catalog`, `title` или `stats`;
- query string ограничен по длине, числу полей и размеру значений;
- свободный поиск `q` и контекст `title` отсутствуют;
- ответ имеет статус 200, HTML content type и размер не больше configured limit;
- response body успешно очищен от request-specific значений.

Профили:

| Профиль | Маршруты | Домен/версия |
| --- | --- | --- |
| `homepage` | `/` | `homepage:public` |
| `catalog` | `/titles`, year/taxonomy landings, directory indexes | `catalog-pages:public` |
| `title` | `/titles/{slug}` | `title-detail:title:{id}` плюс global title generation в dimensions |
| `stats` | `/stats` | `catalog-stats:public` |

Authenticated pages используют существующий authoritative path. В будущем публичный snapshot можно повторно использовать внутри персонального render, но полный HTML разных пользователей не смешивается.

## Безопасный HTML envelope

`PublicPageHtmlTransformer` выполняет два симметричных действия:

1. Перед записью заменяет текущий CSRF на постоянный marker.
2. Находит только валидные временные URL named route `playback.source`, извлекает `licensedMedia` ID и заменяет URL marker-ом без query/signature.
3. Перед выдачей вставляет CSRF текущей session.
4. Для каждого playback marker создаёт новую `temporarySignedRoute` с `viewer=0` и текущим TTL.

Если HTML не удаётся безопасно нормализовать, response не записывается. Raw playback target по-прежнему отсутствует: marker содержит только локальный numeric media ID.

Кэш хранит массив из строки body и allowlisted response metadata. Cookie, CSP/security headers и session cookie добавляются внешними middleware для конкретного запроса и не сохраняются в shared entry.

## Ключи и TTL

Ключ строится существующим `CacheKeyFactory` из:

- route name;
- нормализованных route parameters;
- рекурсивно отсортированного allowlisted query;
- locale и audience `public`;
- для title page — global title generation.

Ни raw search, ни slug, ни URL не попадают в Redis key открытым текстом. Используются текущие fresh/stale/hot окна доменов; `catalog-pages` получает собственное окно 300/1800/120 секунд. Старый stale HTML остаётся безопасным, потому что CSRF и playback signature восстанавливаются заново при каждом чтении.

## Инвалидация

`CatalogCacheInvalidator` остаётся единственной mutation boundary.

- Любое изменение bump-ит `homepage`, `catalog-pages`, facets, stats, API, sitemap и recommendations после commit.
- Если известны title IDs, bump-ятся их `title-detail:title:{id}` scopes.
- Если IDs неизвестны (например, общий sync import), bump-ится global `title-detail:public`; title response key включает эту generation, поэтому неизвестное изменение не может оставить вечный stale title.
- `Cache::flush()`, tags, wildcard scans и удаление очереди не используются.

## Объединённый прогрев

`CatalogCacheWarmRequestStore` хранит в critical `redis-locks` только bounded state:

- monotonic request generation;
- флаг refresh общих страниц;
- уникальные pending title IDs;
- время последнего запроса.

`CatalogCacheInvalidator` сначала записывает намерение прогрева, затем dispatch-ит `WarmCatalogCaches`. Job меняется на `ShouldBeUniqueUntilProcessing` с недельным `uniqueFor`: при отсутствующем worker в очереди остаётся одна новая job на длительное окно, а lock освобождается непосредственно перед обработкой.

Job читает один bounded batch, прогревает data snapshots, critical URLs, recent manifest URLs и изменённые title URLs. State подтверждается только после успеха. Если во время работы пришла новая generation, она остаётся pending и запускает следующий bounded проход. Legacy jobs без pending work завершаются no-op, поэтому существующий backlog не выполняет тысячи повторных SQL rebuilds.

`PublicPageCacheManifest` содержит bounded список недавно реально закэшированных относительных URL. Он нужен для повторного прогрева canonical long-tail страниц после global invalidation; произвольные поисковые URL в manifest не попадают.

Self-warming выполняется Laravel HTTP client через `APP_URL`/configured base URL с короткими connect/total timeouts, только для того же host, с фиксированным служебным заголовком и без cookies/authorization. Один job имеет ограничение числа URL; остаток остаётся pending.

## Отказы

- Memcached недоступен: Redis/shared path работает как раньше.
- Redis domain недоступен: middleware выполняет authoritative render и не маркирует его как общий cache hit.
- Version registry недоступен: старый HTML не используется; ответ формируется из приложения и получает private/no-store semantics существующего failure path.
- Self-warm URL не отвечает: job завершается ошибкой, pending state не подтверждается, старый namespace не удаляется.
- Warming worker отсутствует: health остаётся failed/degraded, но invalidation уже закрывает старую generation; первый запрос может выполнить safe rebuild.
- Oversized HTML: response возвращается пользователю, но не сохраняется; telemetry получает `payload-rejected` через `TieredCache`.

## Наблюдаемость

Ответы получают безопасный заголовок `X-Seasonvar-Page-Cache` со значениями `HIT`, `STALE`, `MISS` или `BYPASS`; ключи и URL не выводятся. Existing cache telemetry считает слой, rebuild, stale и failures в новом домене `catalog-pages`. Warming state дополняется количеством HTTP page targets и no-op jobs.

## Критерии готовности

1. Второй гостевой `GET /` возвращает тот же каталог с `HIT` и без SQL к catalog tables.
2. Авторизованный запрос никогда не получает гостевой HTML.
3. Shared entry не содержит CSRF, signature, raw media URL или user state.
4. Playback URL из stale cache имеет новую валидную подпись.
5. Инвалидация внутри transaction становится видимой только после commit.
6. Изменённый title ID и critical pages попадают в bounded warm request.
7. Много invalidations создают один pending warm intent; legacy duplicate jobs без intent становятся no-op.
8. Focused cache/import tests, Pint, full PHPUnit и frontend build проходят.
9. Документация `docs/caching.md`, `docs/performance.md`, `docs/queues.md`, `docs/deployment.md`, `.env.example` и changelog отражает реальное поведение.

## Не входит в эту реализацию

- shared cache authenticated/private HTML;
- cache произвольного свободного поиска;
- скачивание или хранение видео;
- установка новой production dependency, Horizon или Octane;
- автоматическое удаление существующих Redis queue jobs;
- включение production worker без отдельной безопасной rollout-проверки backlog/import contention.
