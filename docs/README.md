# Карта документации проекта

Обновлено: 15.07.2026

Этот файл — единый индекс проектной документации. У каждого контракта есть один основной файл-владелец; остальные документы должны ссылаться на него, а не копировать длинные правила. `README.md` остаётся точкой входа, `AGENTS.md` содержит только обязательные инструкции для агентов, а история изменений ведётся только в `CHANGELOG.md`.

## Источники истины

| Тема | Основной документ | Что в нём поддерживается |
| --- | --- | --- |
| Обзор и быстрый старт | [`README.md`](../README.md) | Назначение проекта, стек и основные команды. |
| Границы Laravel/Livewire | [`architecture.md`](architecture.md) | Тонкие контроллеры, page builders, query/services/actions, locked Livewire state и server-rendered оболочки. |
| JSON и mobile API | [`api.md`](api.md) | Legacy compatibility, `/api` discovery, версионирование `/api/v1`, OpenAPI, error envelope, pagination и публичные поля. |
| Каталог, поиск, directory hubs и URL state | [`catalog-search.md`](catalog-search.md) | Формат query-параметров, нормализация, OR/AND filters, public directories, allowlisted sorting, pagination и browser history. |
| Домен и пользовательское состояние | [`DATA_RELATIONS.md`](DATA_RELATIONS.md) | Видимость, связи, regular/special ordering, episode navigation, watchlist/rating, progress, Continue Watching и Viewing History. |
| Авторизация и playback access | [`authorization.md`](authorization.md) | Public/profile boundary, policies/gates, entitlement decisions, signed playback route и ограничения отсутствующих product-моделей. |
| Импорт Seasonvar | [`importer.md`](importer.md) | Response → DTO → identity → transaction/upsert → relation sync → counters/cache/index/UI, идемпотентность и владение полями. |
| Паритет источника | [`SOURCE_PARITY.md`](SOURCE_PARITY.md) | Последний подтверждённый inventory sitemap, типы source pages, локальные parser/routes и юридические ограничения. |
| Очереди | [`queues.md`](queues.md) | Queue driver, coordinator/page/finalizer jobs, locks, attempts/backoff/timeout, run states, retry и recovery. |
| Производительность БД | [`performance.md`](performance.md) | Query budgets, plans/indexes, duplicate-free counts, eager loading и cold-path измерения. |
| Redis/Memcached и cache lifecycle | [`caching.md`](caching.md) | Stores/connections, keys, TTL, stale, locks, invalidation, warming, health, metrics и failure recovery. |
| Environment reference | [`environment.md`](environment.md) | Production baseline и безопасные Redis/Memcached/cache переменные без секретов. |
| Security controls | [`security.md`](security.md) | Валидация, IDOR/XSS/SSRF, secrets/log redaction, signed URLs, rate limits и dependency audits. |
| Browser/player lifecycle | [`frontend.md`](frontend.md) | Livewire/Alpine/Plyr/HLS responsibilities, `wire:ignore`, progress heartbeat, cleanup и frontend build. |
| Код и интерфейс | [`CODE_STANDARDS.md`](CODE_STANDARDS.md), [`UI_STANDARDS.md`](UI_STANDARDS.md) | Правила PHP/Laravel и визуальные/a11y соглашения без смешивания доменных контрактов. |
| Локальная разработка и Git | [`development.md`](development.md) | Установка, единственная ветка `main`, versioned hooks и локальные команды проверки. |
| Тесты и CI | [`testing.md`](testing.md), [`ci.md`](ci.md) | PHPUnit-паттерны, доступные проверки и точный GitHub Actions pipeline. |
| Датированные аудиты и модернизация | [`audits/current-state-audit.md`](audits/current-state-audit.md), [`audits/dependency-report.md`](audits/dependency-report.md), [`audits/database-report.md`](audits/database-report.md), [`audits/security-audit.md`](audits/security-audit.md), [`audits/performance-audit.md`](audits/performance-audit.md), [`audits/frontend-report.md`](audits/frontend-report.md), [`audits/livewire-report.md`](audits/livewire-report.md), [`audits/video-playback-report.md`](audits/video-playback-report.md), [`plans/laravel-video-portal-modernization.md`](plans/laravel-video-portal-modernization.md) | Evidence snapshots различают confirmed/probable/intentional/proposed, implementation status, verification и remaining risk; living plan владеет выполнением. |
| Dependency/runtime upgrades | [`upgrade.md`](upgrade.md) | Installed/latest compatibility, controlled package groups, gates и rollback. |
| MCP runbook | [`tooling/mcp-setup.md`](tooling/mcp-setup.md) | Project Boost/Context7/Playwright transport, verification, errors и restart requirement. |
| Production rollout | [`deployment.md`](deployment.md) | Environment, additive migrations, backup/maintenance order, workers, cache warmup и post-deploy checks. |
| История изменений | [`CHANGELOG.md`](../CHANGELOG.md) | Пользовательские и архитектурные изменения в установленном формате. |
| Журнал обслуживания | [`MAINTENANCE_LOG.md`](MAINTENANCE_LOG.md) | Датированные эксплуатационные работы, замеры и диагностика; не заменяет release changelog. |
| Реестр Markdown-аудита | [`markdown-review-2026-07-13.md`](markdown-review-2026-07-13.md) | Реестр просмотренных project-owned Markdown-файлов; завершённые временные implementation plans сохранены в Git history и удалены из текущей документации. |

## Как обновлять документацию

1. Измените основной документ темы из таблицы выше.
2. В соседнем документе добавьте ссылку только тогда, когда разработчику действительно нужен переход между контрактами.
3. Не создавайте второй changelog, второй Git workflow или параллельное описание архитектуры.
4. Не редактируйте вручную содержимое между маркерами `project-docs:start` и `project-docs:end`; его обслуживает `php artisan project:docs-refresh`.
5. Перед commit выполните `php artisan project:docs-refresh --check` и проверки, перечисленные в [`development.md`](development.md).

Если реализация расходится с документацией, сначала подтвердите фактическое поведение кодом и тестами, затем обновите основной документ темы и `CHANGELOG.md` в том же изменении.
