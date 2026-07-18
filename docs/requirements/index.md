# Канонический индекс требований

Обновлено: 18.07.2026

Этот файл определяет обязательный порядок чтения постоянных требований. Он ссылается на существующие документы-владельцы и не копирует их доменные контракты.

## Обязательный порядок чтения

1. [`AGENTS.md`](../../AGENTS.md) — корневые инструкции агента.
2. Этот индекс требований.
3. [`CODE_STANDARDS.md`](../CODE_STANDARDS.md) — общие требования проекта и кода.
4. [`architecture.md`](../architecture.md) — архитектурные границы.
5. [`development.md`](../development.md) — workflow разработки и upgrade decision record.
6. [`multilingual-requirements.md`](multilingual-requirements.md) — постоянные multilingual-требования.
7. [`security.md`](../security.md) — безопасность и privacy.
8. [`performance.md`](../performance.md) и [`caching.md`](../caching.md) — производительность и кеширование.
9. [`UI_STANDARDS.md`](../UI_STANDARDS.md) и [`frontend.md`](../frontend.md) — UI, UX, mobile и accessibility.
10. [`administration.md`](../administration.md) — администрация и least privilege.
11. [`deployment.md`](../deployment.md) и [`environment.md`](../environment.md) — production operations.
12. [`maintenance-and-upgrades.md`](maintenance-and-upgrades.md) — сопровождение, зависимости, deprecations и upgrades.
13. Feature-specific документы из [`docs/README.md`](../README.md), соответствующие затронутым системам.
14. [`docs/plans/current-task-plan.md`](../plans/current-task-plan.md) — текущий план и compliance matrix.
15. Релевантные architecture, audit, implementation и rollback документы из [`docs/README.md`](../README.md).

## Приоритет конфликтующих maintenance-решений

1. Security, privacy, legal integrity, financial integrity и защита данных.
2. Сохранность базы данных и постоянных файлов.
3. Постоянные архитектурные правила.
4. Подтверждённая совместимость production environment.
5. Обратная совместимость.
6. Поддерживаемые публичные API framework и packages.
7. Восстановимость и rollback.
8. Производительность.
9. Удобство разработки.
10. Предпочтение более нового синтаксиса.

Новый синтаксис никогда не отменяет безопасность, совместимость или сопровождаемость.

## Maintenance-документы

- [Dependency inventory](../maintenance/dependency-inventory.md)
- [Runtime compatibility matrix](../maintenance/runtime-compatibility.md)
- [Update decisions](../maintenance/update-decisions.md)
- [Deprecations](../maintenance/deprecations.md)
- [Compatibility adapters](../maintenance/compatibility-adapters.md)
- [Technical debt](../maintenance/technical-debt.md)
- [Security advisories](../maintenance/security-advisories.md)
- [Package removal checklist](../maintenance/package-removal-checklist.md)
- [Framework upgrade checklist](../maintenance/framework-upgrade-checklist.md)
- [Frontend upgrade checklist](../maintenance/frontend-upgrade-checklist.md)
- [Production compatibility checklist](../maintenance/production-compatibility-checklist.md)
- [Maintenance review checklist](../maintenance/maintenance-review-checklist.md)

Отсутствующий registry создаётся только после фактического аудита и не должен содержать выдуманные package states, advisories или результаты проверок.
