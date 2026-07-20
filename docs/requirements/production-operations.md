# Требования к production operations

Обновлено: 20.07.2026

Этот документ — обязательный постоянный contract для deployment, environment, storage, database, cache, sessions, queues, scheduler, backups, restore, rollback, health и incidents. Фактические версии и состояния принадлежат [`../environment.md`](../environment.md), а последовательности deployment — [`../deployment.md`](../deployment.md) и связанным runbooks. Не копируйте туда secret values.

> Приложение должно оставаться корректным при временной недоступности опциональных cache, queue, scheduler, notification, monitoring или external-provider services. Опциональная инфраструктура может улучшать performance или delivery, но не должна молча повреждать state или предоставлять неверный доступ.

> Production deployment должен быть воспроизводим по repository documentation. Недокументированные ручные шаги считаются operational defect.

> Никогда не храните secret values в requirement files, runbooks, changelog, issue reports, screenshots или final summaries. Документируются только variable name, purpose, expected format и validation method.

## 1. Проверенное production environment

- Фактические OS, web server, PHP-FPM/CLI, extensions, database, cache/session/queue drivers, process manager, scheduler, storage и panel фиксируются датированным read-only evidence в `docs/environment.md`.
- `configured`, `reachable`, `degraded`, `unavailable`, `not_installed` и `unknown` — разные operational states. Старый snapshot не считается текущей проверкой.
- Local/test configuration не доказывает production compatibility. Неизвестное состояние документируется без догадки.

## 2. Политика environment variables

- Application code читает runtime values через `config()`, а `env()` допускается только в `config/*.php`.
- Inventory содержит имя, purpose, required/optional, safe format, sensitivity, subsystem, validation, fallback и необходимость cache/runtime refresh — без реальных значений.
- `.env` не tracked; `.env.example` содержит только безопасные placeholders. Client-exposed `VITE_*` переменные разрешены только для publishable values.

## 3. Политика управления secrets

- Application key, database/cache/mail/provider credentials, webhook/OAuth secrets, private storage/backup keys и monitoring tokens хранятся вне Git.
- Secrets не появляются в logs, exceptions, health/admin payloads, browser assets, service worker, screenshots или docs.
- Rotation имеет provider-specific reconciliation и rollback plan. `APP_KEY` не меняется как обычная операция: оцениваются sessions, cookies, encrypted values и signed links.

## 4. Политика версий dependencies

- Production устанавливает locked Composer/npm dependencies; uncontrolled update в production запрещён.
- Любое изменение версии следует [`maintenance-and-upgrades.md`](maintenance-and-upgrades.md), имеет compatibility/production/rollback review и не смешивает unrelated major upgrades.
- Platform requirements проверяются до activation; development packages и debug tooling не включаются в production unintentionally.
- Framework/dependency update подтверждает совместимость фактического production runtime; PHP update — required extensions; Node update — package manager, lock format и Vite; database update — driver и schema behavior.
- Redis update проверяет client, serializer, persistence и failover where relevant; Memcached update — client, serializer, eviction и connection; web-server update — rewrites, headers, upload limits, proxy и static assets; PHP-FPM update — pool, permissions, timeouts, OPcache и restart procedure.
- Package release имеет явные deployment/rollback steps. Pending queue jobs и scheduled tasks проверяются при изменении class/serialization/runtime contracts; frontend release отдельно оценивает service-worker rollback, даже когда current state честно `not_installed`.
- High-risk package/data migration запрещена без verified backup. Provider SDK update проверяет webhooks/signatures, idempotency, callbacks, retries и reconciliation; mail-library update — verification, password reset, security, billing, advertiser, ticket и legal messages с сохранением locale и safe failure behavior.
- Deployment runbook явно перечисляет dependency/runtime changes и устанавливает только lock-resolved versions; production никогда не выполняет uncontrolled dependency resolution.

## 5. Требования к PHP extensions

- Required/optional extensions выводятся из `composer.json`, `composer.lock`, application usage и фактического runtime; список по памяти не допускается.
- PDO driver, OpenSSL, mbstring, fileinfo, XML/DOM, cURL, image, Intl, Redis/Memcached и archive extensions имеют явный статус `required|optional|not_used|unknown`.
- Missing required extension останавливает deployment до activation; optional extension имеет documented fallback.

## 6. Требования к web server

- Document root указывает на `public/`; private storage, `.env`, vendor metadata, backups и source files не обслуживаются напрямую.
- HTTPS, canonical host, static assets, range/streamed responses, upload limits, error/maintenance routing и script-execution prohibition для uploads проверяются по фактическому server configuration.
- Reverse-proxy headers не доверяются без allowlisted proxy boundary; CORS не расширяется автоматически.

## 7. Требования к PHP-FPM

- Pool user/group, socket/port ownership, timeouts, upload/body limits, OPcache и reload procedure документируются без private paths/hostnames.
- Web runtime и CLI используют совместимые PHP version/extensions/config. Reload/restart выполняется только после успешной pre-activation verification.
- In-flight requests, queued workers и rollback оцениваются до restart; zero downtime не заявляется без доказательства.

## 8. Требования к database

- Database — authoritative state; engine/driver/timezone/foreign keys/transactions/backup compatibility проверяются до schema change.
- SQLite compatibility сохраняется там, где она является permanent project contract; production engine фиксируется отдельно.
- Connection failures не приводят к access grant или silent write loss. Migrations, indexes, locks и disk capacity оцениваются до deployment.

## 9. Требования к Redis, когда он используется

- Connection, authentication/TLS, prefixes/DB or endpoint separation, serializer, timeout/retry, sessions, queues, locks, rate limits и cache responsibilities документируются отдельно.
- Redis не является permanent domain database. Serializer/prefix changes требуют stale-key, pending-job, session и rollback plan.
- Недоступность Redis не обходится небезопасным fail-open для permissions, premium, region, legal, idempotency или critical locks.

## 10. Требования к Memcached, когда он используется

- Memcached применяется только для disposable cache, если service/extension/config реально доступны.
- Servers, authentication where used, prefix, serializer, timeout, connection, eviction/item-size behavior и fallback документируются.
- Memcached не хранит sessions, queues, locks, idempotency или reliable state; miss/eviction не повреждает correctness.

## 11. Обязанности cache drivers

- Redis и Memcached не зеркалируют одинаковые data без documented reason. Cache inventory задаёт family, driver, purpose, key pattern, privacy, TTL, invalidation, failure и deployment behavior.
- User/private state не глобально cacheable. Security-sensitive cache failure rebuilds authoritative state либо fail closed.
- Store-wide flush не является обычной deployment операцией; используются versioned/targeted invalidations.

## 12. Обязанности session driver

- Driver, lifetime, encryption, cookie secure/httpOnly/sameSite/domain/path, cleanup, logout и permission/account invalidation документируются.
- Deployment сохраняет compatible sessions, если их planned invalidation не одобрена. Session backend outage не создаёт authentication ambiguity.
- `APP_KEY` и serializer changes имеют отдельный high-risk rollout/rollback review.

## 13. Обязанности queue и scheduler

- Queue/scheduler считаются существующими только при подтверждённых driver, workers/process manager и cron/timer. Fake health запрещён.
- Jobs/commands bounded, idempotent, retry-safe, privacy-safe; queues не становятся mandatory без synchronous/request-driven correctness.
- Для каждого scheduled workload фиксируются frequency, lock, timeout, failure, logging, safe manual invocation и required services.
- Fan-out run не может стать terminal, пока durable dispatch marker явно показывает незавершённую постановку работы; моментальный ноль уже созданных jobs/claims не доказывает завершённый dispatch. Восстановление ошибочно terminal run допускается только через fail-closed application service под canonical single-flight lock после exact ownership/preflight, повторно ставит только persisted nonterminal work и не использует direct status rewrite, массовое освобождение claims, `queue:clear` или `cache:clear`.
- Если одна логическая queue group может содержать достаточно элементов для превышения job timeout, полезная работа checkpoint-ится после каждого ограниченного идемпотентного элемента. Retry не начинает уже подтверждённые элементы заново, а checkpoint атомарно связывает durable item state, aggregate progress и счётчики; увеличение timeout не заменяет эту конечную границу.
- Multi-table merge большой доменной группы не должен удерживать одну транзакцию до queue timeout. Каждый целостный ограниченный дочерний ресурс фиксируется собственной транзакцией и сам служит durable progress marker; failure откатывает только текущий ресурс, а retry повторно обнаруживает оставшийся набор через канонический merger.

## 14. Storage и file permissions

- Disks используют `config/filesystems.php`; public/private/temp/export/backup/media roles разделены. Private files не symlinked publicly.
- Runtime получает least-privilege write только к required directories. Recursive `777`, executable uploads и arbitrary paths запрещены.
- Disk-full, account deletion, legal hold, backup/restore, cleanup и ownership после deployment описаны.

## 15. Asset build и Vite deployment

- Frontend dependencies устанавливаются reproducibly из единственного lock file; production build failure блокирует activation.
- Manifest и все referenced hashed assets проверяются и разворачиваются совместимо с code. Private env values и unapproved source maps не публикуются.
- Active assets не удаляются до переключения code references; obsolete artifacts чистятся только после rollback assessment.

## 16. Service-worker deployment

- Service worker считается поддерживаемым только при реальной registration/build implementation. При отсутствии state фиксируется `not_installed`, а не TODO-имитация.
- При наличии cache name versioned; allowlist исключает authenticated/private/payment/ticket/legal/advertiser/admin/protected-media routes.
- Activation, stale cache cleanup, logout/account switch, playback-safe update prompt и rollback документируются.

## 17. Deployment procedure

- Canonical runbook определяет prerequisites, intended commit, clean `main`, backup/maintenance decision, locked installs, build/manifest verification, migration classification, storage/cache/runtime refresh, smoke checks и audit.
- Strategy (`in_place|release_directory|panel|other`) соответствует фактической инфраструктуре. Atomic/zero-downtime claims требуют evidence.
- Partial deployment имеет stop/contain/rollback path; persistent storage и server-only configuration не перезаписываются Git.

## 18. Maintenance-mode procedure

- Maintenance включается только когда change не совместим с live traffic; page не раскрывает debug details.
- Webhook, payment callback, OAuth, ticket/legal submissions и health behavior оцениваются отдельно. Long-lived bypass tokens запрещены.
- Entry/exit, failure recovery, current-state preservation и verification документируются.

## 19. Database migration procedure

- Каждая migration классифицируется `safe_additive|additive_backfill|compatibility|potentially_locking|destructive|manual_review`.
- Backup обязателен до high-risk schema/data changes. Backfills bounded/idempotent; old/new code compatibility применяется при staged rollout.
- Rollback limitations, cache/search/import/admin/account lifecycle impacts фиксируются до execution.

## 20. Cache rebuild procedure

- Порядок: compatible code → approved migrations → stale compiled cache handling → config cache → route cache only when compatible → view/event cache as applicable → targeted feature invalidation → runtime refresh → boot verification.
- `optimize:clear` и `Cache::flush()` не используются как универсальный production fix, если default store shared.
- Environment changes не оставляют stale config cache.

## 21. PHP-FPM и OPcache refresh

- Фактическая service/panel procedure и права известны до deployment; команды используют safe placeholders.
- Refresh выполняется после successful code/config/assets/migration checks и имеет failure/rollback path.
- Смена PHP version/extensions требует separate compatibility review.

## 22. Rollback procedure

- Rollback раздельно покрывает code, Composer, frontend assets, service worker, schema/backfills, config, cache и providers.
- Previous known-good commit/release фиксируется до activation; `.env`, database и persistent files сохраняются.
- Git rollback не считается database restore. Forward-only migrations используют compatibility/forward-fix, если down небезопасен.

## 23. Backup procedure

- Canonical runbook определяет engine-specific consistency, compression/encryption availability, private destination, naming, access, retention category, verification, capacity, cleanup, owner role и audit.
- Backup не располагается под public web root и не содержит credentials в commands/docs.
- Success требует non-empty artifact и safe structure/archive validation; exit code сам по себе недостаточен.

## 24. Restore procedure

- Restore требует authorization, target identification, maintenance decision и preservation текущего state до overwrite.
- Database, persistent files, secure environment restoration, dependencies/assets, permissions, caches/runtime, service worker, search/provider reconciliation и smoke checks входят в runbook.
- Полный DR не заявляется без реально выполненного restore test; limitations фиксируются честно.

## 25. Disaster-recovery procedure

- Scenarios охватывают failed deploy/migration, data/file corruption/deletion, disk/service/provider/DNS/TLS outages, stale service worker и security/privacy incidents.
- Для каждого описаны detection, containment, user behavior, preservation, recovery, reconciliation, role notification, audit и post-incident review.
- RTO/RPO не обещаются без approved policy и measured capability.

## 26. Log-management policy

- Channels/purpose, rotation owner, retention category, compression, permissions, incident hold и disk-full behavior документируются.
- Logs используют bounded structured context без secrets, request bodies, cookies, tokens, private documents, payment-card data или protected media URLs.
- Raw log access permission-scoped, redacted и path-safe; conflicting rotation systems не настраиваются без reason.

## 27. Health-check policy

- Public health минимален и side-effect-free. Detailed health требует operational permission и возвращает safe status codes without paths/hostnames/package lists/errors.
- Checks lightweight и не создают users/payments/mail, не flush caches и не запускают migrations/commands/providers unnecessarily.
- Expensive checks cached briefly when safe; unknown/not-installed никогда не изображаются healthy.

## 28. External-provider failure policy

- Inventory фиксирует configured/optional, timeout, retryability, idempotency, fallback, user-safe message, reconciliation, secret/privacy и region behavior.
- Non-idempotent calls не retry-ятся blindly. Provider outage не раскрывает raw response и не меняет entitlement/access fail-open.
- Monitoring/alerts заявляются только при реальном transport; иначе documented manual review.

## 29. Payment и webhook production policy

- Browser redirect никогда не подтверждает payment. Server webhook signature, stable provider identity, idempotency и reconciliation являются authority.
- Duplicate/out-of-order/delayed events, pending entitlement, refunds/chargebacks и provider outage имеют runbook и audit без secrets.
- Maintenance/deployment сохраняет callback reachability либо явно содержит safe reconciliation plan.

## 30. Incident-response workflow

- Internal severity codes stable (`informational|minor|major|critical`); labels переводятся только для UI.
- Workflow: identify → record → assign owner role → contain → preserve evidence → protect data/payments/legal state → communicate safely → recover → reconcile → verify → close → review.
- Public docs не содержат exploit details или private contacts; roles используются вместо personal data.

## 31. Operational audit rules

- Deployment, maintenance, backup verification, restore/rollback, targeted invalidation, idempotent retry, runtime/service-worker change и incident lifecycle могут записываться только реальными application-owned events.
- Audit содержит actor identity where authorized, stable event code, safe target/status/timestamp, public-safe notes и correlation ID; no secret values or raw payloads.
- Fake historical deployment/backup/health events запрещены.

## 32. Retention и cleanup

- Categories для logs/backups/exports/temp files/old releases задаются policy owners; legal/financial retention не выдумывается.
- Cleanup bounded и не удаляет active release, only rollback release, latest verified backup, referenced files, legal evidence или required financial state.
- Scheduler dependency не добавляется, если его нет; используется documented manual/request-driven alternative с limitations.

## 33. Package-upgrade workflow

- Причина, installed/proposed version, official guidance, platform/breaking/lock/security impact, affected features, deployment и rollback фиксируются до update.
- Production использует reviewed lock; broad Composer/npm updates и audit force fixes запрещены.
- Upgrades не активируются до cross-feature verification и документации.

## 34. Server-upgrade workflow

- OS/kernel/panel/web server/PHP/database/Redis/Memcached upgrade не выполняется repository task автоматически без отдельной authority.
- Preparation включает compatibility, backup, config preservation, extensions, maintenance, service restart, rollback и verification.
- Proposed support не отмечается verified до фактической проверки.

## 35. Известные infrastructure limitations

- Unknown/absent HA, failover, monitoring, alerting, backup automation, restore tests, object storage, CDN или process supervision фиксируются в actual environment/runbook.
- Optional outage может снижать performance/delivery, но не correctness/access control. Несуществующая capability не получает UI или fake status.
- Ограничения остаются в current plan до verified resolution.

## 36. Emergency roles

- Используются только role names: deployment operator, database operator, security incident owner, payment reconciliation operator, legal/privacy owner и application owner.
- Private names, phone numbers, emails, credentials и escalation tokens не хранятся в repository. Реальные contacts принадлежат внешнему approved incident directory.
- Emergency access least-privilege, time-bounded, recently authenticated, audited и revoked после use.

## 37. Production acceptance checklist

- Проверяются boot/home/localized routes/search/catalog/title/season/episode, authorized playback, progress, auth/session, administration permission, premium/region/legal/ad exclusion и safe provider state.
- Проверяются manifest/assets, storage, caches, service-worker exclusions when installed, logs redaction, minimal health, backup state documentation и rollback readiness.
- Real payment charge, unsafe external login/mail и destructive restore не выполняются без separate authorization. Каждая проверка помечается `verified|not_applicable|blocked|not_performed` с evidence/reason.

## Canonical operational runbooks

[`../operations/README.md`](../operations/README.md) связывает единственные владельцы deployment, rollback, backup/restore, disaster recovery, incidents, logging/health, providers, service-worker state и production acceptance. Дублирующие runbook’и не создаются.
