# Канонический индекс требований

Обновлено: 20.07.2026

Этот файл определяет обязательный порядок чтения постоянных требований. Он ссылается на существующие документы-владельцы и не копирует их доменные контракты.

Файлы ниже обязательны, а не рекомендательны. Эквивалентные уже существующие owner-документы используются вместо создания параллельных `project-requirements.md`, `architecture-rules.md`, `development-workflow.md`, `security-and-privacy.md`, `performance-and-caching.md`, `ui-ux-accessibility.md` и `administration-requirements.md`.

## Реестр канонических требований

| Порядок | Канонический путь | Назначение | Scope | Обязательность | Владелец | Последнее существенное обновление |
| ---: | --- | --- | --- | --- | --- | --- |
| 1 | [`AGENTS.md`](../../AGENTS.md) | Ограничения выполнения задач агентами | Весь repository | Каждая задача | Project workflow | 19.07.2026 |
| 2 | [`docs/requirements/index.md`](index.md) | Registry, read order и precedence | Весь repository | Каждая задача | Requirements governance | 19.07.2026 |
| 3 | [`docs/CODE_STANDARDS.md`](../CODE_STANDARDS.md) | Permanent product/repository и PHP/Laravel rules | Весь portal | Каждая coding-задача | Application engineering | 19.07.2026 |
| 4 | [`docs/architecture.md`](../architecture.md) | Backend/frontend layers, identities и integration boundaries | Весь portal | Каждая architecture/code-задача | Application architecture | 19.07.2026 |
| 5 | [`docs/development.md`](../development.md) | Preparation, implementation, verification и Git workflow | Весь repository | Каждая задача | Development workflow | 19.07.2026 |
| 6 | [`docs/requirements/multilingual-requirements.md`](multilingual-requirements.md) | Locale, translations и multilingual compatibility | Весь portal | Каждая задача | Localization | 19.07.2026 |
| 7 | [`docs/security.md`](../security.md) | Security, privacy, secrets и protected data | Весь portal | Каждая задача | Security and privacy | 19.07.2026 |
| 8 | [`docs/performance.md`](../performance.md), [`docs/caching.md`](../caching.md) | Query, payload, cache и invalidation contracts | Весь portal | Каждая data/UI задача | Performance and caching | 19.07.2026 |
| 9 | [`docs/UI_STANDARDS.md`](../UI_STANDARDS.md), [`docs/frontend.md`](../frontend.md) | UI, UX, mobile и accessibility | User/admin interfaces | Каждая UI-задача | Frontend experience | 19.07.2026 |
| 10 | [`docs/administration.md`](../administration.md), [`docs/authorization.md`](../authorization.md) | Administration, roles, permissions и moderation | Administration/private staff | Каждая admin-задача | Administration and authorization | 19.07.2026 |
| 11 | [`docs/requirements/production-operations.md`](production-operations.md) | Production/data/deployment/runbook boundaries | Production-affecting work | При любом production impact | Operations | 20.07.2026 |
| 12 | [`docs/requirements/maintenance-and-upgrades.md`](maintenance-and-upgrades.md) | Dependency/runtime/architecture upgrades | Maintenance-affecting work | При любом maintenance impact | Maintenance | 18.07.2026 |
| 13 | [`docs/requirements/system-wide-integration.md`](system-wide-integration.md), [`docs/README.md`](../README.md) и feature owners | Cross-feature и feature-specific requirements | Затронутые domains | По scope задачи | System integration и тематические владельцы | По каждому owner-файлу |
| 14 | [`docs/plans/current-task-plan.md`](../plans/current-task-plan.md) | Task scope, discoveries, compliance и evidence | Текущая задача | Каждая задача | Task owner | Обновляется в каждой задаче |
| 15 | Связанные architecture, implementation, audit и runbook docs из [`docs/README.md`](../README.md) | Historical evidence и operational detail | Затронутые domains | По scope задачи | Тематические владельцы | По каждому owner-файлу |

Global rules задают неизменяемые repository boundaries; feature-specific owners могут только уточнять их для своего домена. Current task plan фиксирует исполнение, но не переопределяет постоянные правила. Ссылки на текущую реализацию и историю: [`current-task-plan.md`](../plans/current-task-plan.md) и [`CHANGELOG.md`](../../CHANGELOG.md).

## Обязательный порядок чтения

1. [`AGENTS.md`](../../AGENTS.md) — корневые инструкции агента.
2. Этот индекс требований.
3. [`CODE_STANDARDS.md`](../CODE_STANDARDS.md) — permanent product/repository rules.
4. [`architecture.md`](../architecture.md) — архитектурные границы.
5. [`development.md`](../development.md) — workflow разработки.
6. [`multilingual-requirements.md`](multilingual-requirements.md) — постоянные multilingual-требования.
7. [`security.md`](../security.md) — безопасность и privacy.
8. [`performance.md`](../performance.md) и [`caching.md`](../caching.md) — производительность и кеширование.
9. [`UI_STANDARDS.md`](../UI_STANDARDS.md) и [`frontend.md`](../frontend.md) — UI, UX, mobile и accessibility.
10. [`administration.md`](../administration.md) и [`authorization.md`](../authorization.md) — administration requirements.
11. [`production-operations.md`](production-operations.md) — production operations.
12. [`maintenance-and-upgrades.md`](maintenance-and-upgrades.md) — maintenance and upgrades.
13. Feature-specific требования из [`docs/README.md`](../README.md); при cross-feature scope сначала читается [`system-wide-integration.md`](system-wide-integration.md).
14. [`docs/plans/current-task-plan.md`](../plans/current-task-plan.md) — текущий план и compliance matrix.
15. Релевантные architecture, implementation, audit и rollback документы из [`docs/README.md`](../README.md).

Cross-feature integration обязательна: feature нельзя считать завершённой, пока не проверены все затронутые shared domains и related existing modules.

## Общий приоритет конфликтующих требований

Канонический порядок precedence:

1. Security, privacy, legal restrictions и data integrity.
2. Постоянные project architecture rules.
3. Постоянные multilingual и compatibility rules.
4. Feature-specific requirements.
5. Current task plan.
6. Implementation convenience.

Implementation convenience никогда не переопределяет постоянное требование. Для более детального выбора внутри одной категории применяется расширенная шкала ниже.

1. Security, privacy, legal integrity, financial integrity и защита user/private data.
2. Сохранность database и persistent files.
3. Постоянные architecture и system-integration rules.
4. Подтверждённая production compatibility и server limitations.
5. Backward compatibility и сохранение public/persisted contracts.
6. Functional correctness и cross-feature consistency.
7. Supported framework/package APIs.
8. Recoverability и rollback.
9. Performance.
10. Developer convenience и preference for newer syntax.

Новый синтаксис и удобство реализации никогда не отменяют безопасность, сохранность данных, совместимость, correctness или восстановимость.

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

## Приоритет конфликтующих production-решений

1. Security и защита user, payment, legal и private data.
2. Сохранность базы данных и постоянных файлов.
3. Постоянные архитектурные правила.
4. Подтверждённые ограничения сервера.
5. Обратная совместимость.
6. Операционная простота и восстановимость.
7. Производительность.
8. Удобство реализации.

Оптимизация производительности никогда не отменяет безопасность данных, privacy, восстановимость или проверенную server compatibility.

## Канонические feature areas 1–26

1. Home page — [`frontend.md`](../frontend.md), [`architecture.md`](../architecture.md), [`caching.md`](../caching.md).
2. Search — [`catalog-search.md`](../catalog-search.md), [`api.md`](../api.md).
3. Alphabetical catalogue — [`catalog-search.md`](../catalog-search.md), [`frontend.md`](../frontend.md).
4. Advanced filters — [`catalog-search.md`](../catalog-search.md), [`DATA_RELATIONS.md`](../DATA_RELATIONS.md).
5. Serial details — [`architecture.md`](../architecture.md), [`DATA_RELATIONS.md`](../DATA_RELATIONS.md), [`audits/video-playback-report.md`](../audits/video-playback-report.md).
6. Seasons and episodes — [`DATA_RELATIONS.md`](../DATA_RELATIONS.md), [`importer.md`](../importer.md).
7. Player — [`audits/video-playback-report.md`](../audits/video-playback-report.md), [`frontend.md`](../frontend.md), [`security.md`](../security.md).
8. Progress and history — [`DATA_RELATIONS.md`](../DATA_RELATIONS.md), [`frontend.md`](../frontend.md), [`api.md`](../api.md).
9. Personal library — [`DATA_RELATIONS.md`](../DATA_RELATIONS.md), [`authorization.md`](../authorization.md), [`plans/laravel-video-portal-modernization.md`](../plans/laravel-video-portal-modernization.md#task-09--canonical-personal-library-statuses-markers-and-update-tracking).
10. Collections — [`architecture.md`](../architecture.md), [`DATA_RELATIONS.md`](../DATA_RELATIONS.md).
11. Tags — [`architecture.md`](../architecture.md), [`DATA_RELATIONS.md`](../DATA_RELATIONS.md).
12. Comments — [`architecture.md`](../architecture.md), [`DATA_RELATIONS.md`](../DATA_RELATIONS.md).
13. Reviews — [`architecture.md`](../architecture.md), [`DATA_RELATIONS.md`](../DATA_RELATIONS.md).
14. Profiles — [`architecture.md`](../architecture.md), [`authorization.md`](../authorization.md), [`DATA_RELATIONS.md`](../DATA_RELATIONS.md).
15. Authentication — [`authorization.md`](../authorization.md), [`security.md`](../security.md).
16. Account settings — [`architecture.md`](../architecture.md), [`DATA_RELATIONS.md`](../DATA_RELATIONS.md), [`frontend.md`](../frontend.md).
17. Release calendar — [`release-calendar.md`](../release-calendar.md).
18. Recommendations — [`superpowers/specs/2026-07-13-recommendation-v3-list-design.md`](../superpowers/specs/2026-07-13-recommendation-v3-list-design.md), [`caching.md`](../caching.md).
19. Content requests — [`architecture.md`](../architecture.md), [`DATA_RELATIONS.md`](../DATA_RELATIONS.md).
20. Technical tickets — [`technical-issues.md`](../technical-issues.md).
21. Help center — [`help-center.md`](../help-center.md).
22. Premium — [`premium.md`](../premium.md), [`authorization.md`](../authorization.md).
23. Mobile and PWA — [`UI_STANDARDS.md`](../UI_STANDARDS.md), [`frontend.md`](../frontend.md), [`operations/service-worker-deployment.md`](../operations/service-worker-deployment.md).
24. Rights-holder cases — текущая product capability не установлена; permanent privacy/storage/legal boundaries принадлежат [`security.md`](../security.md), [`storage.md`](../storage.md) и [`administration.md`](../administration.md).
25. Advertisers — текущая product capability не установлена; permanent consent/privacy/premium/admin boundaries принадлежат [`security.md`](../security.md), [`premium.md`](../premium.md) и [`administration.md`](../administration.md).
26. Administration — [`administration.md`](../administration.md), [`authorization.md`](../authorization.md).

System-wide evidence и финальная dependency matrix принадлежат [`system-integration.md`](../system-integration.md); текущий status/compliance — [`current-task-plan.md`](../plans/current-task-plan.md), техническая история — [`CHANGELOG.md`](../../CHANGELOG.md).

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

## Production runbook’и

- [Verified environment and variable inventory](../environment.md)
- [Deployment](../deployment.md)
- [Rollback](../operations/rollback-runbook.md)
- [Backup and restore](../operations/backup-and-restore.md)
- [Disaster recovery](../operations/disaster-recovery.md)
- [Incident response](../operations/incident-response.md)
- [Logging and health](../operations/logging-and-health.md)
- [External providers](../operations/external-providers.md)
- [Service-worker deployment](../operations/service-worker-deployment.md)
- [Production acceptance](../operations/production-checklist.md)
