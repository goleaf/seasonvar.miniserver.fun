# Эксплуатационные runbook’и

Проверено: 18.07.2026.

Это навигация по production operation lifecycle. Постоянные обязательные правила находятся в [`../requirements/production-operations.md`](../requirements/production-operations.md), фактическое окружение и inventory переменных — в [`../environment.md`](../environment.md), deployment — в [`../deployment.md`](../deployment.md), cache/session — в [`../caching.md`](../caching.md), storage — в [`../storage.md`](../storage.md), queues/scheduler — в [`../queues.md`](../queues.md).

Отдельные runbook’и дополняют этих владельцев без создания параллельной архитектуры:

- [`rollback-runbook.md`](rollback-runbook.md) — откат к предыдущему known-good состоянию;
- [`backup-and-restore.md`](backup-and-restore.md) — database/persistent-file backup, проверка и восстановление;
- [`disaster-recovery.md`](disaster-recovery.md) — сценарии потери сервиса или данных;
- [`incident-response.md`](incident-response.md) — severity, containment, evidence и reconciliation;
- [`logging-and-health.md`](logging-and-health.md) — безопасные логи, публичный readiness и operator diagnostics;
- [`external-providers.md`](external-providers.md) — source, mail, payment, OAuth и optional providers;
- [`service-worker-deployment.md`](service-worker-deployment.md) — честное состояние PWA/service worker;
- [`production-checklist.md`](production-checklist.md) — ручной acceptance перед возвратом трафика.

Текущий verified deployment — in-place checkout под aaPanel/nginx/PHP-FPM; release directories, atomic symlink switch, external monitoring, alert delivery и off-host backup не подтверждены. Эти возможности нельзя отмечать как работающие до отдельной проверки.
