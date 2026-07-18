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
| SEC-06 | Confirmed problem, partially reduced | CSP is report-only, broad `https:` and inline styles remain; ordinary Livewire bundle also produced `unsafe-eval` violations | Task 27 enabled the installed CSP-safe Livewire bundle without weakening `script-src`; observe remaining provider/style violations and narrow origins before enforcement | Pending provider/style enforcement | Firefox verified `livewire.csp.min.js`, filter mutation and zero CSP errors; media/CDN enforcement can still break playback if rushed |
| SEC-07 | Confirmed problem, fixed | JSON-LD was encoded inside a Blade raw boundary | Normalize and hex-encode each structured-data object before render; keep one scalar-only audited output | Implemented and browser verified | `Js::encode()` plus parse/closing-script hostile-string regression; Blade rejects `json_encode()`; full 848/6928 and browser 21/21 pass; one reviewed raw script boundary remains necessary |
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

## Task 10 collection security review

Confirmed controls: opaque UUID management identity, policy on model plus every action/cover/legacy redirect, safe 404 for private/moderated/deleted IDOR, server-derived owner/type/moderation/feature, enum/length/UUID/reorder validation, Unicode plain-text sanitization, escaped Blade, raster private uploads, owned-path/version/traversal checks, CSRF Livewire mutations, user/action rate budgets, transaction locks, item unique key and optimistic content version.

Privacy/cache review found and corrected two implementation risks before completion: versioned covers initially used immutable cache despite mutable visibility, and collection API/sitemap initially inherited nonzero shared stale windows. Covers/pages are now private/no-store and API/sitemap collection profiles force immediate revalidation; visibility/moderation changes bump public domains after commit. Membership/owner controls/moderation notes/private counts do not enter Resource, JSON-LD or shared HTML. Disposable smoke also exposed an operational namespace hazard when explicit hot/domain/version stores retained the production namespace despite overriding Laravel's default cache store; the affected `collections` scope alone was advanced from version 8 to 9, then all diagnostics used isolated array stores, environment/application prefixes and config-cache paths.

Residual operational risk is rollout, not a known bypass: production code/schema/cache must be deployed atomically with migration and cache rebuild. Unlisted is documented direct-link discoverability, not secret-token security. No claim is made that absent likes/follows/collaborator abuse workflows exist.

## Task 12 discussion security review

Confirmed controls: verified-email writes, `CommentPolicy` on every mutation, target enum/resolver instead of arbitrary morph class, same-target root reply validation, no reparent/cycle path, locked Livewire identity, explicit fields, CSRF, optimistic edit version/row locks, UUID idempotency, user/target rate limits, short duplicate detection, enum report/moderation/restriction values and gate-protected admin context.

Stored/reflected XSS review confirms canonical NFKC plain-text sanitizer, HTML/script/style/control/bidi removal, dangerous-scheme/link/line/length/repetition limits and escaped Blade only. Unrevealed spoiler/full long body is absent from DTO/HTML/profile/inbox/SEO rather than CSS-blurred. Reports/reporter, private notes, block/mute/restriction/current reaction/pending ownership and notification state never enter guest DTO/shared cache.

Direct IDs reauthorize comment and visible target; hidden content returns 404, while moderator-only deleted/hidden context remains private. Account export excludes чужие/private moderation data; deletion anonymizes author/reporter and removes private engagement/relationships/preferences. Static Blade scan and targeted Larastan passed; manual second-user/admin Chromium confirmed the hidden-link 404, neutral relationship placeholders, private report/note boundary, reversible moderation and comment-only restriction enforcement. These checks do not replace penetration testing or rollout observation.

## Task 13 review security review

Confirmed design controls: title-only enum/resolver rather than arbitrary morph class; `CatalogTitleReviewPolicy` plus action reauthorization; server actor/status/verified snapshot; canonical rating service; locked/scalar Livewire state; CSRF; row locks/optimistic version; deterministic ownership/submission identity; unique atomic vote/open-report dedup; user/review/target rate budgets; active expiry checks; gate-protected moderation and safe audit fingerprints.

Stored/reflected XSS boundary is Unicode plain text through `ReviewTitle`/`ReviewBody`/`UserPlainText`, escaped Blade and no raw/Markdown/auto-link renderer. Dangerous schemes/control/bidi/excessive links/lines/repetition are rejected. Unrevealed spoiler title and body are absent from HTML/profile/notification/search/SEO/schema. Reporter/note/block/mute/current vote/restriction/pending ownership/exact watch evidence never enters public API/cache.

Direct ID resolves alias then reauthorizes public review and visible title; unavailable content cannot disclose body. Owner deletion remains available even when creation is disabled/restricted. Account export excludes foreign/private evidence and deletion anonymizes author/reporter while removing private engagement. Remaining risk is normal rollout/operational observation, not a known bypass; static checks do not replace penetration testing and Task 13 runs no automated tests.
