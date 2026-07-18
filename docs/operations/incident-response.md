# Incident response

Проверено: 18.07.2026.

Internal severity codes are stable English identifiers, not translated labels:

- `informational` — no user/data impact;
- `minor` — bounded degradation with safe fallback;
- `major` — important feature unavailable, data reconciliation required or broad user impact;
- `critical` — data loss/exposure, payment/access/legal boundary failure or complete outage.

## Workflow

1. Record start, severity, affected modules and operational owner role.
2. Contain: stop unsafe deployment/write/provider action; revoke only affected access; preserve availability where correctness remains.
3. Preserve redacted logs, commit/build identity, migration status, queue/import state and file checksums. Never copy secrets, cookies, tokens, protected URLs or private documents into issue/public status.
4. Protect users, payments, legal evidence and private files. Access failures must fail closed when authorization cannot be established.
5. Communicate only public-safe effect and status. Do not publish exploit detail or personal contacts.
6. Recover using the relevant rollback/backup/provider runbook.
7. Reconcile persisted and external state idempotently.
8. Execute affected rows of the production checklist; record actual evidence and skipped checks.
9. Close only after return-to-service and owner approval. Add root cause, prevention, requirements/runbook changes and follow-up debt.

## Focused incident procedures

### Deployment

- Composer/npm/build failure: keep current code/assets active, do not delete current vendor/build, inspect lock-only resolution and rollback staged files.
- Missing Vite manifest/chunk: maintenance if rendering fails; restore matching manifest plus complete assets, then PHP/cache refresh.
- Config/route cache failure: keep prior cache or rebuild after verified boot; never expose `.env` while diagnosing.
- PHP-FPM reload failure: preserve current workers, inspect actual unit/panel; no blind kill/restart.

### Database

Unavailable/locked/full/corrupt/migration failure: stop writers, preserve DB/WAL and disk evidence, do not run destructive repair. Use consistent copy for integrity work. Duplicate rows after retry require domain idempotency reconciliation, not arbitrary deletion.

### Cache/session

Redis loss makes sessions, queues and locks unavailable; do not grant permission/premium/legal access from missing cache. Memcached loss is disposable degradation. Suspected poisoning invalidates targeted versioned keys after identifying scope; application-wide flush needs an explicit impact record.

### Payment/webhook

Signature failure is rejected and logged without payload/secrets. Duplicate/out-of-order events use the canonical idempotency ledger. Browser success never grants premium. Pending entitlement/refund/chargeback requires authorized provider reconciliation and preserves payment/audit rows.

### Authentication

Provider/mail outage does not create alternative unsafe login. Administrator compromise triggers session/token revocation and permission/audit review. APP_KEY leak is critical: rotation impact on encrypted data, sessions, cookies and signed links is assessed before any change.

### Storage/private files

Permission/disk/symlink failures disable the affected upload/download safely. Exposure closes access immediately, preserves evidence and audits every route/disk. Corrupt or missing files restore only from verified backup and preserve database references until reconciliation.

No repository automation currently delivers incident alerts. Operators review safe health, Laravel daily logs, systemd/cron state, importer status and provider dashboards manually.
