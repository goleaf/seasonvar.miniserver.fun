# Security audit

Проверено: 15.07.2026. Review охватывает routes/middleware/Requests/Resources/Policies, Livewire mutations, Blade/JS sinks, authentication/session/token flows, importer/media URL boundaries, uploads, logs/config and dependency advisories. Это evidence snapshot; устойчивые правила находятся в [`docs/security.md`](../security.md) и [`docs/authorization.md`](../authorization.md).

## Реестр выводов

| ID | Класс | Наблюдение | Изменение | Статус | Verification / remaining risk |
| --- | --- | --- | --- | --- | --- |
| SEC-01 | Confirmed critical runtime problem | Production debug mode enabled | Environment owner sets `APP_DEBUG=false`; preflight must fail while true; rebuild config and graceful reload | External action pending | `artisan about`; disclosure risk remains until changed |
| SEC-02 | Confirmed control | Admin/import writes use gates/policies; personalized API uses Sanctum abilities and owner scoping | Preserve and add mutation inventory contract | Verified by route and feature tests | New mutations must enter inventory |
| SEC-03 | Confirmed control | Form Requests/Livewire Forms normalize validation; API errors use safe envelope/request ID | Preserve | 22 Requests, API contract tests | Full static typing still incomplete |
| SEC-04 | Confirmed control | Playback grant is signed/viewer-bound and redirects only to guarded HTTPS provider; no byte proxy/raw URL API | Preserve | Playback/source guard tests | Provider/CDN is external boundary |
| SEC-05 | Confirmed control | Import/crawler URL paths use host/scheme/public-DNS/redirect/size/timeout checks | Re-audit every URL ingress while refactoring importer | Existing SSRF tests | DNS rebinding/redirect tests must remain deterministic |
| SEC-06 | Confirmed problem | CSP is report-only, broad `https:` and inline styles allowed | Add observable violation path or controlled browser corpus; narrow origins before enforcement | Pending | Enforcement now would risk playback/UI outage |
| SEC-07 | Confirmed problem | JSON-LD is encoded in Blade raw boundary | Prepare trusted hex-escaped scalar before render and test hostile strings | Pending P1 | One reviewed raw script boundary may remain necessary |
| SEC-08 | Confirmed control, strengthened | Baseline had no PHP blocks or direct database calls, but still contained 41 `request()`, 1 `config()` and authorization directives | Prepare request/config/audience/permission state before render and enforce a zero-tolerance template scan | Implemented and browser verified | Zero forbidden request/config/auth/gate/facade/container/application-static matches across 52 Blade files; focused 42/339, full 840/6882 and browser 21/21 pass; remaining general URL/label presenter migration is tracked separately |
| SEC-09 | Confirmed control | Composer/npm audits report zero advisories | Keep in CI and phase gates | Verified 15.07.2026 | Future advisories remain time-dependent |
| SEC-10 | Probable | Public storage link missing | Confirm no user/public upload consumer before changing | Verify | Creating an unused link expands exposure unnecessarily |
| SEC-11 | Intentional | Private upload service exists but no generic public upload endpoint | Preserve content/MIME/size/private-disk boundary | Verified by unit tests | SVG/active-content rules required if product adds uploads |
| SEC-12 | Probable | Report-only CSP header is HTML-only; API correctly omits it | Preserve response-type separation | Verified | Need production violation observation before enforcement |

## Threat classes inspected

- Broken authorization / IDOR: route model binding is followed by owner or entitlement checks; cross-user API tests exist.
- Mass assignment: Requests/allowlists and model fillable/guarded patterns reviewed; new writes must use validated data.
- SQL injection: dynamic sort/filter identifiers are allowlisted; raw SQL inventory requires continued review during query work.
- XSS: escaped Blade is default; only JSON-LD raw boundary found and uses JSON hex flags, but encoding belongs before render.
- CSRF/session: web group and Laravel 13 request-forgery middleware apply; session cookies observed Secure/HttpOnly/SameSite=Lax.
- SSRF: Seasonvar host restriction and public-IP guards exist for external inputs; no arbitrary media proxy.
- Command/path/deserialization: no confirmed user-controlled process execution or unsafe unserialize path in app inventory.
- Secrets/logging: `.env` is untracked; docs/examples use placeholders; reports exclude tokens, upstream credentials and failed payloads.
- CORS/embed: no broad application CORS override confirmed; provider behavior remains external and CSP report-only.

## Acceptance

Production security gate cannot be green while debug is enabled, a critical mutation lacks server-side authorization/validation, a dependency has an undocumented critical advisory, Blade contains application/service/query logic, or a private media URL appears in HTML/API/Livewire/log output. Zero-future-bug claims are explicitly prohibited.
