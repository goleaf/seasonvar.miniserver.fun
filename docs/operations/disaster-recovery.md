# Disaster recovery

Проверено: 18.07.2026. RTO/RPO и автоматический failover не утверждены. Recovery зависит от доступного known-good code, locked dependencies, защищённого `.env`, verified database/file backup и оператора с aaPanel/system access.

## Общая последовательность

Detect → contain → preserve evidence/current state → protect writes/private data → recover or restore → reconcile providers/derived state → verify → return service → post-incident review.

## Сценарии

| Сценарий | Containment и user behavior | Recovery |
| --- | --- | --- |
| Failed code/assets deployment | Maintenance при boot/write failure; не продолжать migration | Rollback code/lock-built assets, caches и PHP runtime по runbook. |
| Failed migration/data conversion | Остановить writers; сохранить DB/WAL/current logs | Forward-fix при совместимой schema либо authorized verified restore; Git rollback недостаточен. |
| Database unavailable/corrupt/deleted rows | Fail closed для writes/access state, не выполнять repair автоматически | Проверить disk/lock/permissions, восстановить consistent backup и reconcile post-backup writes. |
| Disk failure/full | Остановить новые uploads/import/backups, не удалять evidence | Освободить только approved reproducible/expired files, заменить volume, restore DB/private files. |
| Redis unavailable | Authenticated/session/lock/queue operations degraded; access rules не fail-open | Восстановить service, проверить prefixes/serialization/backlog/locks; не flush domain state вслепую. |
| Memcached unavailable | Disposable hot cache miss/fallback; correctness сохраняется | Восстановить optional service или оставить degraded; не дублировать durable state. |
| Mail/OAuth/source provider outage | Безопасное localized unavailable/pending; bounded retry только idempotent | Restore provider/config, reconcile requests; не считать accepted mail delivered. |
| Payment provider/webhook outage | Browser redirect не даёт entitlement; показывается pending | Проверить signatures/events, idempotently reconcile, исключить double grant/refund loss. |
| Private-file exposure | Немедленно закрыть route/storage access, preserve evidence | Revoke signed links/secrets where affected, permission review, notify responsible roles, restore private ACL. |
| Leaked secret/application key | Revoke affected provider secret; app-key rotation только отдельным emergency plan | Rotate narrowly, review logs/sessions/encrypted fields/signed links, invalidate affected credentials and audit. |
| Malicious upload/advertiser URL | Disable record/publication without deleting evidence | Validate files/URLs, quarantine outside public path, review affected users/logs and moderation controls. |
| DNS/certificate/nginx/FPM failure | Public-safe outage; no insecure HTTP fallback for sensitive callbacks | Restore panel/service configuration, TLS chain, FastCGI and secure URL generation; smoke before return. |
| Stale service worker | Сейчас not installed | Future recovery must bump version, clear only owned caches and preserve private denylist. |

## Recovery dependencies and limitations

- Current in-place deployment has no verified atomic switch or secondary server.
- Current alerting is manual; external monitoring destination is absent.
- Panel archives exist, but current database backup and full restore are not verified.
- Redis process ownership/restart unit is not documented by repository; use verified panel/system ownership rather than guessed service names.
- Memcached is configured in Laravel but not listening; it is optional and currently degraded.

Every incident closes only after reconciliation of authentication, progress/library, premium/payment, region/legal, advertiser exclusion, imports, private files, queues and public SEO/assets as applicable.
