# Checklist maintenance review

Review запускается при security advisory, production incident, package abandonment, framework/runtime EOL planning, database/server upgrade или крупной affected feature work. Периодический review — организационный процесс, не fake automation и не обязательный scheduler.

- [ ] Read order и conflict precedence пройдены до package/code changes.
- [ ] Current branch/status and protected user changes recorded.
- [ ] Composer/npm manifests, locks, runtime pins and deployment files inventoried.
- [ ] Exact installed versions and direct dependency purposes refreshed.
- [ ] Auto-discovery, providers, aliases, middleware, routes and public endpoints reviewed.
- [ ] Commands, jobs, listeners, scheduler and serialization dependencies reviewed.
- [ ] Official advisories and abandoned status checked through authoritative tooling.
- [ ] Outdated results treated as candidates, not automatic updates.
- [ ] Licensing, telemetry, post-install behavior and production purpose reviewed for new/replacement packages.
- [ ] Deprecated API searches and compatibility-adapter dependants refreshed.
- [ ] Technical debt is explicit; current correctness/security work is not deferred dishonestly.
- [ ] Drift scan covers Volt, `@php`, Blade calls, inline CSS/JS, untranslated text/identity, duplicate boundaries and client-trusted access state.
- [ ] Each change has a complete update decision and coherent grouping.
- [ ] Database/cache/session/queue/provider/service-worker production impact and rollback reviewed.
- [ ] All 28 protected portal modules classified affected/unaffected/N/A with reason.
- [ ] Translation, accessibility, mobile, browser and bundle impact reviewed where affected.
- [ ] Verification performed is separated from unavailable/unperformed checks.
- [ ] Inventory, matrix, decisions, deprecations, adapters, debt, requirements, production docs, README relevance and changelog updated.
- [ ] Git diff/status/branch inspected; lock diff explained; no secrets/artifacts included.
- [ ] Commit and push results recorded honestly.

## Review evidence header

Every review record should contain: date, reviewer/owner role, trigger, current commit, production evidence source, commands performed, unavailable evidence, decisions, affected modules, deployment/rollback status and next removal/review conditions.
