# Полное удаление локальных HTTP rate limits

## Цель

Исключить все ответы HTTP `429 Too Many Requests`, которые создаёт само приложение. Каталог, Livewire, JSON API, health endpoint, playback source, пользовательские действия и административные действия должны обрабатываться без локального минутного бюджета.

Ответ `429`, полученный от удалённого Seasonvar или CDN, остаётся внешним сигналом временной ошибки. Импортер и media checker продолжают распознавать такой ответ и применять существующий bounded retry/backoff; приложение не превращает это распознавание в локальное ограничение входящих запросов.

## Выбранный подход

Rate limiting удаляется из кода и конфигурации, а не выключается большим числом или feature flag. Это исключает скрытые лимиты, зависимость от stale config cache и повторное появление локального `429` из оставшихся buckets.

Сохраняются независимые boundaries, которые не являются rate limiting: authentication, policies/gates, signed playback URL, CSRF, request validation, import locks, unique queue jobs, транзакции и allowlist внешних URL.

Новые Composer и npm зависимости не добавляются.

## HTTP и Livewire

Со всех web- и API-маршрутов удаляются middleware `throttle:*`. Именованные limiters `catalog-stats`, `catalog-query`, `livewire-action`, `catalog-api`, `infrastructure-health` и `playback-source` удаляются из `AppServiceProvider`.

Livewire update и temporary upload routes продолжают использовать middleware `web`, но больше не получают transport-, component- или upload-level budget. Public cache middleware, signed URL и authorization middleware сохраняются.

`RequestRateLimitKey` удаляется как неиспользуемый класс. Настройки query rate limit удаляются из `config/catalog.php`.

Выделенные только для limiter counters `cache.limiter`, store `redis-limiter` и Redis connection `limiter` также удаляются. Readiness больше не проверяет неиспользуемое соединение `redis_limiter`; Redis cache, sessions, queues и locks остаются отдельными проверяемыми workloads.

## Действия приложения

`SensitiveActionRateLimiter` удаляется вместе с dependency injection и вызовами в:

- поиске и фильтрации каталога;
- watchlist, rating, progress и playback-session;
- истории просмотров;
- административном редактировании каталога;
- управлении импортом;
- проверке доступности внешних media source.

После удаления limiter все перечисленные действия продолжают проходить текущую валидацию, авторизацию и доменные сервисы. Проверка доступности media source больше не возвращает `not_checked` только из-за исчерпанного локального bucket и выполняет обычную внешнюю проверку.

Массив `security.rate_limits`, переменные `RATE_LIMIT_*`, `CACHE_LIMITER_*` и `REDIS_LIMITER_*` из `.env.example` и соответствующие эксплуатационные инструкции удаляются.

## Playback

Неиспользуемый локальный статус `PlaybackAvailability::ConcurrencyExceeded` и его HTTP-код `429` удаляются. Остальные причины недоступности playback и их HTTP-коды сохраняются. Signed playback route остаётся обязательным.

## Ошибки и внешние ограничения

Приложение больше не формирует локальный `429`. Повышенная частота запросов может увеличить SQL, Redis, Livewire и внешний HTTP load; это принято как явное следствие выбранного режима.

Удалённые ответы HTTP `429` не маскируются:

- importer продолжает классифицировать их как transient failure;
- media availability checker продолжает записывать внешний rate-limited status и планировать повторную проверку;
- retry windows, backoff и timeouts остаются ограниченными, чтобы один удалённый источник не создавал бесконечный tight loop.

## Документация

Обновляются основные тематические документы из `docs/README.md`: security/authorization, architecture, API, catalog search, caching/performance, environment/testing, administration, deployment и importer/media contracts там, где они описывают локальные budgets или выделенный limiter workload. Исторические design specs, implementation plans и maintenance log не переписываются: они сохраняют историю решений.

## Тестирование

- route tests подтверждают отсутствие `throttle:*` у web/API endpoints;
- Livewire update route не содержит throttle middleware;
- повторные catalog/user/admin actions не блокируются локальным bucket;
- media health check не пропускается из-за локального rate limit;
- playback enum больше не содержит локальной concurrency-причины `429`;
- cache/Redis/readiness tests подтверждают отсутствие выделенного limiter workload;
- importer tests подтверждают, что внешний HTTP `429` по-прежнему transient;
- focused PHPUnit, Pint, полный PHPUnit-набор и frontend build выполняются по правилам проекта.

## Критерии приёмки

- ни один маршрут или action приложения не использует Laravel rate limiter;
- конфигурация и readiness не содержат отдельный limiter store/connection/component;
- код приложения не вызывает `abort(429)` и не сопоставляет локальный доменный статус с `429`;
- поиск, фильтры, Livewire, API, playback, пользовательские и административные действия доступны без минутного бюджета;
- authentication, authorization, validation, signed URL, CSRF и importer locks не ослаблены;
- внешние ответы `429` сохраняют retry/backoff semantics;
- конфигурация, `.env.example`, актуальная документация и тесты соответствуют новому поведению.
