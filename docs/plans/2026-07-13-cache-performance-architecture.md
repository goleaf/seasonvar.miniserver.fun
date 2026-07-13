# План производительности и кэширования — 2026-07-13

## Цель

Снизить стоимость повторных публичных чтений каталога без переноса доменной истины из SQLite: Redis отвечает за общий доменный кэш, версии, блокировки, rate limiting, сессии и очереди; Memcached — за короткоживущий hot-cache компактных публичных снимков.

## Исходная точка

- Laravel 13.19.0, Livewire 4.3.3, PHP 8.5.8.
- PhpRedis 6.3.0 и Memcached 3.4.0 установлены; Redis и Memcached доступны локально.
- 34 319 опубликованных тайтлов в production-like SQLite.
- До изменений: главная — 24,731 с, каталог — 3,265 с, публичная страница тайтла — 0,785 с на первом измеренном запросе к локальному production-like серверу.
- Прикладной кэш ограничен `CatalogStatsSnapshotCache`; default cache, session и queue используют database, limiter — file.

## Ограничения дизайна

- Кэшируются только массивы, скаляры и ID; Eloquent-модели, приватные URL, токены и авторизационные решения не кэшируются.
- Любой общий дорогой rebuild защищается Redis-lock и имеет ограниченное stale-поведение.
- Инвалидация выполняется после успешного commit; bulk importer сообщает об изменениях явно.
- Ключи формируются централизованно из нормализованных и хэшированных измерений; wildcard deletion и `Cache::flush()` не используются.
- Memcached всегда считается теряемым. Redis queue/session/locks не переключаются на Memcached.
- Horizon и Octane не добавляются: для них нужны отдельное операционное решение и новая production dependency.

## Шаги реализации

1. Зафиксировать контракт тестами: конфигурация ролей, канонические ключи, TTL, tiered fallback, locks, version invalidation, HTTP headers, Blade/Volt restrictions и реальные Redis/Memcached stores.
2. Добавить именованные Redis/Memcached stores и отдельные Redis connections/prefixes для cache, sessions, queues, limiter, locks и broadcasting.
3. Реализовать `App\Support\Cache`: домены, TTL policy с jitter, key factory, version registry, metrics и один tiered cache coordinator.
4. Перевести снимок статистики на общий coordinator; добавить компактный home snapshot и кэш публичных guest facets.
5. Добавить grouped invalidation после административных транзакций и завершения importer; поставить уникальные warm jobs в `cache-warm`.
6. Добавить безопасные HTTP cache validators для явно публичных GET/HEAD и private/no-store для Livewire/admin/playback.
7. Добавить lightweight health/readiness command и endpoint, rate limiter на Redis и production defaults для Redis session/queue.
8. Расширить CI реальными Redis/Memcached integration tests без flush общих stores.
9. Синхронизировать документацию, выполнить аудит запрещённых паттернов, полный test/build/cache validation и повторные замеры.
10. Закоммитить логическими commits, push существующей `main`, подтвердить чистое дерево.

## Проверка

- Сначала focused tests и зафиксированный RED, затем реализация и GREEN.
- `composer validate --strict`, `composer audit`, Pint, PHP syntax lint, `php artisan test`, конфигурационные/маршрутные/Blade caches, `npm audit`, `npm run build`.
- Реальные Redis/Memcached tests используют уникальный test prefix и удаляют только созданные ключи.
- Controlled HTTP benchmark повторяет одинаковые публичные URL и отдельно сообщает cold/warm, p50/p95, размер ответа и ограничения измерения.
