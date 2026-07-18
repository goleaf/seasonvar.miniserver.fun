# Сопровождение и безопасные обновления

Обновлено: 18.07.2026

Этот документ обязателен для framework, PHP, Composer, npm, Livewire, Tailwind, Flux, Vite, JavaScript, database, Redis, Memcached, web-server, PHP-FPM, service-worker, browser-support, deprecated-API, technical-debt, architecture-consolidation, package-removal, integration-replacement, runtime и security-advisory задач.

> New versions are not automatically better for this project. An update must provide a justified benefit and must preserve the complete existing portal architecture.

> Framework and package upgrades must be divided into reviewable stages. Do not combine unrelated major upgrades when separation improves safety, verification, and rollback.

> Compatibility adapters are temporary architecture. Every adapter must document why it exists, what depends on it, and the condition required for removal.

> Technical debt must be visible. Do not hide unresolved debt behind comments, aliases, silent fallbacks, duplicate implementations, or undocumented configuration.

> A successful dependency installation is not proof of functional compatibility. Compatibility must be verified across the affected portal features.

## 1. Maintenance principles

- Обновление начинается с требований, inventory, официального version-specific guidance и decision record, а не с изменения constraints или lock files.
- Сохраняются все 28 compatibility domains, публичные contracts, persisted identities, privacy/security/legal/financial boundaries и rollback.
- Fake automation, fake dashboards, fake advisories, fake health и неподтверждённая совместимость запрещены.
- Mandatory correctness/security work исправляется в текущей задаче, когда это безопасно и проверяемо; остальное получает явный registry record.

## 2. Dependency inventory

`docs/maintenance/dependency-inventory.md` хранит direct Composer/npm dependencies, installed/allowed versions, purpose, modules, configuration, integration, runtime, owner, status, limitations и decision. Существенные transitive dependencies добавляются только с причиной. Secrets/private repository credentials отсутствуют.

## 3. Runtime compatibility matrix

`docs/maintenance/runtime-compatibility.md` использует только states `verified`, `documented compatible`, `project-required`, `optional`, `unsupported`, `unknown`, `requires review`. Проверка одной команды не повышает state остальных runtime/features.

## 4. Direct dependency purpose registry

Каждая direct dependency имеет один документированный purpose, production/development scope, primary consumers, optionality, removal condition и owner area. Неиспользуемость подтверждается полным repository search.

## 5. Package-approval policy

Новый package требует purpose, maintenance status, verified advisory evidence, license metadata, bundle/memory/runtime/deployment impact, data/public-contract analysis, rollback и removal assessment. Package не добавляется ради замены малого стабильного internal function без доказанной ценности.

## 6. Package-removal policy

До удаления ищутся imports, config, providers, aliases/facades, routes, middleware, commands, jobs, events, migrations, Blade/Livewire/JS/CSS usage, storage data, cache/local-storage keys, environment variables, deployment и docs. Persisted/public dependencies сначала мигрируются через совместимый переход.

## 7. Package-replacement policy

Замена использует application-owned interface/adapter, сохраняет stable domain values, data, routes, events, notifications, cache/admin behavior и rollback. Старый package удаляется после проверенного перехода.

## 8. Framework-upgrade policy

Framework upgrade — отдельная стадия с официальным guide, PHP/first-party constraints, bootstrap/middleware/exceptions/console/auth/database/queue/cache/session/filesystem/mail/notification/deployment audit и cross-feature matrix.

## 9. Laravel upgrade policy

Используются Laravel-supported public APIs, но рабочая custom structure не заменяется skeleton blindly. Route names, model binding, event codes, cache identities, translations, permission codes и DB values сохраняются либо получают полный compatibility migration.

## 10. Livewire upgrade policy

Проверяются class lifecycle, locked/public state, serialization, validation/uploads/pagination/events/navigation, JS hooks, nested/lazy/polling behavior, duplicate actions/listeners, stale responses, locale, focus/a11y, player cleanup и progress requests. Volt запрещён; class-based components и route history сохраняются.

## 11. Tailwind upgrade policy

Проверяются CSS-first config/imports, content scanning, dynamic classes, plugins, utilities, responsive/safe-area/reduced-motion/print behavior, long translations, admin/player/premium/advertiser/legal/ticket states, generated CSS и bundle size.

## 12. Flux upgrade policy

Сначала фиксируются installed package/licensing и реально используемые components. API/slot/property, focus, modal/sheet/dropdown/table/editor/upload/a11y изменения проверяются по официальному guidance. Flux Pro не предполагается установленным; Flux не требует Volt.

## 13. Vite upgrade policy

Проверяются Laravel plugin, entrypoints, manifest, source maps, chunks, imports, service-worker build, CSS pipeline, base URL/HMR, browser targets и exposed `VITE_*` data. Public/admin/player/payment/upload bundles не расширяются без review.

## 14. PHP upgrade policy

Production requirement не повышается до проверки packages, extensions, deprecated language behavior, typing/serialization, opcache, PHP-FPM и deployment rollback. Future candidate остаётся планом, а не заявленной поддержкой.

## 15. Composer upgrade policy

Запрещены broad update, удаление lock file и необъяснённый lock rewrite. Проверяются direct changes, relevant transitive changes, plugin permissions, scripts, autoload warnings, abandoned flags, advisories, platform requirements и production install with locked dependencies.

## 16. Node and package-manager upgrade policy

Package manager не меняется без migration plan. Проверяются engine/lock compatibility, Vite, build/postinstall scripts, service worker, source maps, CSS/JS bundles, deployment runtime и rollback к прежним runtime/lock.

## 17. JavaScript dependency policy

Предпочитаются локально собранные, purpose-documented packages без скрытой telemetry. Проверяются global loading, DOM sinks, browser support, lifecycle cleanup, bundle weight, postinstall scripts и private data exposure.

## 18. Database upgrade policy

Проверяются Laravel driver, migrations, SQL/JSON/index/foreign-key/transaction/locking/date/timezone/money/pagination/full-text behavior, backup/restore, merge/delete/import/reporting и rollback. SQLite compatibility сохраняется; production engine не заявляется verified без evidence.

## 19. Redis upgrade policy

Проверяются client/extension, TLS/auth, DB/prefix/serializer/compression/timeouts/retry, locks, rate limits, cache/session/queue/pub-sub responsibilities, restart и stale keys. Serializer/key changes требуют versioning/cleanup/rollback; Redis не является permanent domain storage.

## 20. Memcached upgrade policy

Проверяются extension, servers/auth, prefix/serializer/timeouts/persistence/eviction/item size/TTL/restart/fallback. Memcached не хранит надёжное состояние и не дублирует Redis без отдельной ответственности.

## 21. Web-server and PHP-FPM upgrade policy

Проверяются rewrites, forwarded headers, CORS/CSP, uploads, proxy/range/static assets, pool user/permissions/timeouts, opcache и restart order. Runtime config не меняется без production/rollback документации.

## 22. Service-worker and PWA upgrade policy

Проверяются scope/version/install/activation/stale cleanup/offline fallback/update prompt/rollback и строгие exclusions private, payment, ticket, legal, advertiser, admin и protected-media routes. Generic cache-all PWA package запрещён.

## 23. Payment-SDK upgrade policy

Проверяются official guidance, checkout, server truth, webhook signature/events/idempotency/refund/subscription/invoice/customer/exceptions/retry/sandbox/config/callback/account lifecycle/audit и historical data. Browser success не доверяется.

## 24. OAuth-SDK upgrade policy

Проверяются state, PKCE, callbacks/scopes, provider identity/verified email, token storage, linking/unlinking/collision, session regeneration, redirect allowlist, outages и account merge. SDK update не создаёт duplicate accounts или unsafe email linking.

## 25. Mail-provider upgrade policy

Проверяются transport, queue/sync fallback, locale/templates, verification/reset/security/billing/advertiser/ticket/legal mail, exceptions/retries/timeouts и secret redaction. Accepted message не считается delivered.

## 26. Storage-provider upgrade policy

Проверяются disk visibility, signed URLs, path normalization, streaming/range, metadata, encryption, retention, deletion/export, credentials и private-file behavior.

## 27. Search-provider upgrade policy

Проверяются canonical filtering/ranking, locale, visibility, indexing lifecycle, data minimization, stale index, outage fallback, queries/payloads и removal. Текущий SQLite search не заменяется без доказанной необходимости.

## 28. Media-library upgrade policy

Проверяются MIME/codec/format, input validation, metadata stripping, orientation, animation/SVG, memory/quality, thumbnails/responsive variants, private attachments, posters/avatars/OG/ads/legal files и existing references. Player/source authorization остаются server-side.

## 29. Deprecated-API policy

Каждая подтверждённая deprecation получает stable ID, source/version evidence, locations, replacement, impact, phase, adapter/removal condition, status и verification. Suppression без removal plan запрещён.

## 30. Compatibility-adapter policy

Adapter registry фиксирует purpose, source/target, dependants, risk, owner, audit date и measurable removal condition. Неизвестные dependants блокируют удаление, но не скрываются.

## 31. Technical-debt registry

Debt records имеют stable ID, modules, risk/business/security/performance/compatibility impact, resolution, dependencies, complexity category, status, deferral reason и completion criteria. Registry не является способом отложить обязательную текущую безопасность/корректность.

## 32. Architecture-drift prevention

Maintenance review ищет Volt, `@php`, direct Blade model/service/facade/DB/cache calls, inline CSS/business JS, hardcoded UI, translated identity, competing permissions/audit/notification/cache/merge/delete/premium/region/legal systems, client-trusted decisions, fake controls/integrations/health/analytics/progress, N+1/unbounded lists, private shared/service-worker cache и stale invalidation.

## 33. Security-advisory handling

Workflow: authoritative source/tool → installed affected version → used functionality/exposure → project-context severity → patched versions → breaking changes → update/mitigation → affected-feature verification → deployment/registry update. Public admin не показывает exploit details. Без evidence package не называется vulnerable/compromised.

## 34. Package licensing review

Для new/replacement packages фиксируется доступная metadata classification: `compatible`, `requires attribution`, `commercial restrictions`, `unknown`, `requires legal review`. Это не юридическое заключение; unknown блокирует production adoption до review.

## 35. Package abandonment review

Composer abandoned metadata, archived repository, unsupported runtime/framework или authoritative maintenance notice фиксируются с evidence. Persisted/public dependencies получают staged replacement, а не автоматическое удаление.

## 36. Update staging

Каждая группа проходит requirements → inventory → official guidance → breaking/deprecation map → affected modules → plan → smallest coherent change → config/code/translations/cache/production/docs → available verification → old-API search → matrices/changelog. Security patches, framework, Livewire, frontend, runtime, DB/cache/provider SDK и removals разделяются при различном rollback.

## 37. Rollback

Decision record фиксирует old constraints/lock/config/assets/service-worker, data migration, cache/session/pending jobs/provider compatibility, safe rollback procedure, unsafe conditions и forward-fix. Revert commit не считается достаточным при persisted changes.

## 38. Production deployment

Production устанавливает lockfiles, не разрешает новые versions на сервере, учитывает backups/migrations/workers/scheduler/service worker/cache/session/opcache/PHP-FPM/provider callbacks и выполняет documented post-deploy checks. Dependency changes явно перечисляются в runbook.

## 39. Documentation

Обновляются requirements index, dependency inventory, runtime matrix, update decisions, deprecations, adapters, debt, advisories, production/rollback, architecture owner, current plan/compliance matrix, changelog и README только при visitor-visible/product-state change.

## 40. Final verification

Перед commit повторно читаются требования, task и diff; проверяются constraints/locks, routes/providers/middleware/commands/jobs/events/migrations/config/cache/session/queue/Livewire/Blade/JS/Tailwind/Flux/Vite/service-worker/translations/security/privacy/performance/production/rollback. Выполняются только доступные и разрешённые static, audit, build и browser/manual gates; не выполненное обозначается честно.

## Обязательный update decision record

Перед dependency/runtime change записываются: имя, current/proposed version, direct/transitive scope, purpose, reason, security/maintenance relevance, compatibility requirements, affected files/modules, config/database/assets/production changes, deprecated/replacement APIs, backward compatibility, rollback, verification и решение `update|retain|replace|remove`.

Lock files не меняются без понимания diff. Каждый direct change и существенный transitive change рассматривается отдельно; unrelated changes не прячутся в lock rewrite; lock files не удаляются для принудительного resolution и не пересоздаются другим package manager без migration plan.

## Канонические maintenance registries и checklists

- [`dependency-inventory.md`](../maintenance/dependency-inventory.md) и [`runtime-compatibility.md`](../maintenance/runtime-compatibility.md)
- [`update-decisions.md`](../maintenance/update-decisions.md), [`deprecations.md`](../maintenance/deprecations.md), [`compatibility-adapters.md`](../maintenance/compatibility-adapters.md)
- [`technical-debt.md`](../maintenance/technical-debt.md) и [`security-advisories.md`](../maintenance/security-advisories.md)
- [`package-removal-checklist.md`](../maintenance/package-removal-checklist.md), [`framework-upgrade-checklist.md`](../maintenance/framework-upgrade-checklist.md), [`frontend-upgrade-checklist.md`](../maintenance/frontend-upgrade-checklist.md), [`production-compatibility-checklist.md`](../maintenance/production-compatibility-checklist.md), [`maintenance-review-checklist.md`](../maintenance/maintenance-review-checklist.md)
