# Performance audit

Проверено: 13.07.2026. Основной владелец runtime budgets и SQL plans — `docs/performance.md`; cache topology — `docs/caching.md`.

## Подтверждённое состояние

- Публичные lists используют pagination и scoped selects; importer использует chunks/upserts/grouped queries. `Model::all()` на catalog hot paths и queries из Blade не найдены.
- Title page загружает summaries всех сезонов, но episodes/media только активного сезона. Playback resolver reuse удерживает resolve до двух запросов; sitemap/feed responses streamed.
- Catalog relation facets собраны bounded `UNION ALL`; search имеет FTS5/indexed fallback и deterministic tie-breakers. Existing tests фиксируют listing/title/facet/playback/sitemap query ceilings.
- Public cache хранит только compact IDs/DTO snapshots. User state, authorization decisions, signed URLs и Eloquent graphs обходят shared cache; versioned invalidation и locks покрыты tests.
- Media bytes не проходят PHP. Browser smoke подтвердил signed application redirect и provider `206 Partial Content`, не читая/сохраняя media на сервере.
- Livewire polling использует `.visible`, loading states и прекращается после terminal state; catalog search/filter state нормализован и paginated.

## Инфраструктурные результаты

`app:health` подтвердил SQLite/Redis/Memcached transports и queue worker heartbeat. Redis eviction count равен 0, но `maxmemory` не задан — это operational capacity risk. Cache-warm timestamp ещё не зарегистрирован. SQLite quick/FK checks прошли; search/import indexes имеют status `Ran`, а новая relation source identity table ожидает controlled migration.

## Риски и решения

- SQLite single-writer contention ограничивает безопасную worker concurrency. Сохранять текущие per-title locks, короткие transactions и измерять queue latency/lock waits перед расширением pools.
- Внешние source/provider requests доминируют latency и не должны происходить в catalog render; targeted refresh остаётся queued.
- Не добавлять Redis/Memcached одной ответственности: Redis остаётся coordination/session/queue/domain cache, Memcached — disposable second tier.
- Не добавлять Scout/external search без production query-plan evidence. Текущий каталог масштабируется через FTS5 и ограниченные filters.
- Не добавлять local transcoding/range controller: это ухудшит memory/latency и противоречит действующей external delivery architecture.

## Следующие измеримые шаги

1. После controlled migration снова снять `EXPLAIN QUERY PLAN` и query budgets каталога/import dedup.
2. В production monitoring подтвердить worker heartbeat, cache warm timestamp, Redis memory policy и queue latency.
3. Сравнивать Livewire payload size и browser Core Web Vitals только на deterministic fixtures; не оптимизировать по предположениям.
