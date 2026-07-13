# Laravel video portal modernization

Дата аудита: 13.07.2026. Этот план ограничен доказанными gaps текущего продукта. Долгосрочный repository backlog остаётся в `docs/audit.md`; здесь фиксируется выполнение запроса Laravel/Livewire/video/MCP audit без создания недоказанных features.

## P0

### P0-1 — Boot, data loss и critical authorization

- **Проблема / evidence:** application boot проходит; destructive migration, public media proxy и unauthenticated write routes не найдены. Baseline 3 failures относились к runtime-specific assertions, а не к product regression.
- **Файлы:** routes, policies, middleware, migrations и full test inventory просмотрены; code change не нужен.
- **Решение:** сохранить существующие entitlement/policy/signed URL boundaries; не создавать speculative replacement.
- **Риск / migration:** нет. Live pending migrations не относятся к boot и автономно не применяются.
- **Test:** full PHPUnit, route/security/playback suites, isolated migrations.
- **Rollback:** не требуется.
- **Источник:** Laravel authorization, Livewire security, OWASP Authorization Cheat Sheet.
- **Статус:** verified complete.

## P1

### P1-1 — Полный MCP stack

- **Проблема / evidence:** Boost и Playwright были установлены, но Boost child process не наследовал local env; Context7 отсутствовал.
- **Файлы:** `.codex/config.toml`, `IntegrationDoctor.php`, `CheckIntegrationsCommandTest.php`, `docs/mcp.md`, `docs/tooling/*`.
- **Решение:** absolute cwd/timeouts, `APP_ENV=local`, optional Context7 и isolated/headless Playwright; doctor требует все три и не хранит keys.
- **Риск / migration:** новая session должна перечитать tool inventory; schema impact отсутствует.
- **Test:** MCP initialize/tools-list handshakes, `integrations:doctor --strict --json`.
- **Rollback:** удалить только новые project tables; installed Composer/npm packages не менялись.
- **Источник:** official Codex MCP/config, Laravel Boost, Context7, Playwright MCP docs.
- **Статус:** implemented and verified; current process restart limitation documented.

### P1-2 — Session driver correctness

- **Проблема / evidence:** array test session driver сохранял Redis connection `sessions`, нарушая driver isolation contract.
- **Файлы:** `config/session.php`, existing `CacheArchitectureTest`.
- **Решение:** задавать connection только для Redis driver.
- **Риск / migration:** низкий; production Redis behavior сохраняется, schema impact отсутствует.
- **Test:** focused cache architecture suite.
- **Rollback:** вернуть прежнее expression, если framework изменит contract.
- **Источник:** Laravel 13 session documentation.
- **Статус:** implemented and verified.

### P1-3 — Media/playback architecture

- **Проблема / evidence:** требовалось проверить ranges/HLS/private access. Код уже не проксирует bytes: signed route повторно авторизует media и redirect-ит к allowlisted external source.
- **Файлы:** playback resolver/controller/guard, frontend player, authorization/frontend docs; изменений нет.
- **Решение:** сохранить offload. Не добавлять fake PHP range controller или local HLS/DRM pipeline.
- **Риск / migration:** availability/CORS/range behavior зависит от licensed provider; DB impact отсутствует.
- **Test:** signed/expired/viewer/unpublished/source guard tests; real browser получил app 302 и provider 206.
- **Rollback:** не требуется.
- **Источник:** MDN Range/206/416, Apple HLS authoring, hls.js API.
- **Статус:** architecture verified complete; provider operational guarantees remain external.

### P1-4 — Controlled live migrations

- **Проблема / evidence:** в начале аудита были pending alias cleanup/unique/source availability migrations; в ходе общего deployment workflow были также применены новые search/import migrations. Последний status оставляет relation source identity migration pending при активном queued import run `#742`.
- **Файлы:** `database/migrations/*`, deployment/importer docs.
- **Решение:** isolated migrate/rollback для исходного набора доказан; cleanup/search/import migrations применены. Новая relation source identity migration остаётся pending до backup/importer stop point/maintenance preflight.
- **Риск / migration:** cleanup migration необратима по данным, хотя schema down безопасен; возможен lock/SQLite writer contention.
- **Test:** temporary SQLite full migrate; targeted relation-identity rollback; live `migrate:status` и importer status read-only.
- **Rollback:** восстановление DB backup для deleted duplicate rows; schema down только в maintenance window.
- **Источник:** Laravel migrations/deployment docs и project `docs/deployment.md`.
- **Статус:** исходный migration code/live status verified; relation identity migration pending, production backup artifact не проверялся.

## P2

### P2-1 — CSP observation boundary

- **Проблема / evidence:** security headers были без CSP.
- **Файлы:** `config/security.php`, `.env.example`, `AddSecurityHeaders.php`, `SecurityHardeningTest.php`, security/frontend docs.
- **Решение:** deterministic report-only policy for HTML, source validation, no public report collector and no `unsafe-eval`.
- **Риск / migration:** broad `https:` and inline styles remain; no DB impact.
- **Test:** 12 security tests / 78 assertions, browser console/network smoke.
- **Rollback:** `SECURITY_CSP_REPORT_ONLY=false` or revert middleware header; functional delivery unaffected while report-only.
- **Источник:** MDN CSP Report-Only, OWASP CSP Cheat Sheet.
- **Статус:** implemented and verified.

### P2-2 — Production asset-compatible tests

- **Проблема / evidence:** tests hardcoded `livewire.js`; installed Livewire production asset is `livewire.min.js`.
- **Файлы:** `CatalogPageTest.php`.
- **Решение:** exact singleton regex accepting official minified/non-minified names while still requiring ID and CSRF singleton.
- **Риск / migration:** none.
- **Test:** focused catalog asset/stat tests.
- **Rollback:** unnecessary unless Livewire changes asset URL contract.
- **Источник:** installed Livewire 4 output and official asset lifecycle.
- **Статус:** implemented and verified.

### P2-3 — SEO, accessibility and responsive verification

- **Проблема / evidence:** required browser validation and truthful structured data review.
- **Файлы:** existing SEO builder, Blade/components, sitemaps/tests; audit docs only.
- **Решение:** preserve server-rendered canonical/TVSeries/VideoObject/breadcrumb data and internal `player_loc`; no private `contentUrl`. Browser verified search, URL state, title hierarchy/player shell, zero horizontal overflow at 1440 and 390, skip-link focus, no console warning/error and successful assets.
- **Риск / migration:** live content/provider metadata quality remains importer-owned; no schema impact.
- **Test:** existing SEO/sitemap tests plus Playwright MCP/CLI smoke.
- **Rollback:** no product code change.
- **Источник:** Google Video structured data/sitemaps, Schema.org, WCAG-oriented semantic review.
- **Статус:** verified complete for representative public flows.

## P3

### P3-1 — CSP enforcement and browser CI

- **Проблема:** no stored CSP violation telemetry and no repository Playwright/axe test runner.
- **Affected files:** future CSP collector/monitoring config or `tests/browser`; existing `docs/audit.md` owns the broader backlog.
- **Solution:** first observe exact origins; separately add deterministic temporary-DB browser CI only with approved dev dependencies.
- **Risk / migration:** enforcement can break media; adding dependencies requires explicit project approval. No current migration.
- **Test:** CSP clean report window, mobile/desktop accessibility and network-blocked fixture suite.
- **Rollback:** disable enforcement/remove dev-only runner.
- **Source:** MDN/OWASP CSP, Playwright testing docs.
- **Статус:** deferred, not a completion blocker.

### P3-2 — Product capabilities without a model

- **Проблема:** rights/regions/subscriptions/profiles/DRM/uploads/transcoding are absent rather than broken.
- **Affected files:** none until a product specification exists.
- **Solution:** do not invent services, credentials or content.
- **Risk / migration/test/rollback:** requires separate domain design and legal/operational authority.
- **Source:** OWASP authorization/file upload and Apple/provider delivery guidance.
- **Статус:** rejected as speculative scope.
