# Карта документации проекта

Обновлено: 13.07.2026

Этот файл — единый индекс проектной документации. У каждого контракта есть один основной файл-владелец; остальные документы должны ссылаться на него, а не копировать длинные правила. `README.md` остаётся точкой входа, `AGENTS.md` содержит только обязательные инструкции для агентов, а история изменений ведётся только в `CHANGELOG.md`.

## Источники истины

| Тема | Основной документ | Что в нём поддерживается |
| --- | --- | --- |
| Обзор и быстрый старт | [`README.md`](../README.md) | Назначение проекта, стек и основные команды. |
| Границы Laravel/Livewire | [`architecture.md`](architecture.md) | Тонкие контроллеры, page builders, query/services/actions, locked Livewire state и server-rendered оболочки. |
| Каталог, поиск и URL state | [`catalog-search.md`](catalog-search.md) | Формат query-параметров, нормализация, OR внутри группы и AND между группами, allowlisted sorting, pagination и browser history. |
| Домен и пользовательское состояние | [`DATA_RELATIONS.md`](DATA_RELATIONS.md) | Видимость, связи, regular/special ordering, episode navigation, watchlist/rating, progress, Continue Watching и Viewing History. |
| Авторизация и playback access | [`authorization.md`](authorization.md) | Public/profile boundary, policies/gates, entitlement decisions, signed playback route и ограничения отсутствующих product-моделей. |
| Импорт Seasonvar | [`importer.md`](importer.md) | Response → DTO → identity → transaction/upsert → relation sync → counters/cache/index/UI, идемпотентность и владение полями. |
| Очереди | [`queues.md`](queues.md) | Queue driver, coordinator/page/finalizer jobs, locks, attempts/backoff/timeout, run states, retry и recovery. |
| Производительность и кеш | [`performance.md`](performance.md) | Query budgets, duplicate-free counts, eager loading, cache boundaries и точная lifecycle-инвалидация. |
| Security controls | [`security.md`](security.md) | Валидация, IDOR/XSS/SSRF, secrets/log redaction, signed URLs, rate limits и dependency audits. |
| Browser/player lifecycle | [`frontend.md`](frontend.md) | Livewire/Alpine/Plyr/HLS responsibilities, `wire:ignore`, progress heartbeat, cleanup и frontend build. |
| Код и интерфейс | [`CODE_STANDARDS.md`](CODE_STANDARDS.md), [`UI_STANDARDS.md`](UI_STANDARDS.md) | Правила PHP/Laravel и визуальные/a11y соглашения без смешивания доменных контрактов. |
| Локальная разработка и Git | [`development.md`](development.md) | Установка, единственная ветка `main`, versioned hooks и локальные команды проверки. |
| Тесты и CI | [`testing.md`](testing.md), [`ci.md`](ci.md) | PHPUnit-паттерны, доступные проверки и точный GitHub Actions pipeline. |
| Production rollout | [`deployment.md`](deployment.md) | Environment, additive migrations, backup/maintenance order, workers, cache warmup и post-deploy checks. |
| История изменений | [`CHANGELOG.md`](../CHANGELOG.md) | Пользовательские и архитектурные изменения в установленном формате. |
| Журнал обслуживания | [`MAINTENANCE_LOG.md`](MAINTENANCE_LOG.md) | Датированные эксплуатационные работы, замеры и диагностика; не заменяет release changelog. |

## Как обновлять документацию

1. Измените основной документ темы из таблицы выше.
2. В соседнем документе добавьте ссылку только тогда, когда разработчику действительно нужен переход между контрактами.
3. Не создавайте второй changelog, второй Git workflow или параллельное описание архитектуры.
4. Не редактируйте вручную содержимое между маркерами `project-docs:start` и `project-docs:end`; его обслуживает `php artisan project:docs-refresh`.
5. Перед commit выполните `php artisan project:docs-refresh --check` и проверки, перечисленные в [`development.md`](development.md).

Если реализация расходится с документацией, сначала подтвердите фактическое поведение кодом и тестами, затем обновите основной документ темы и `CHANGELOG.md` в том же изменении.
