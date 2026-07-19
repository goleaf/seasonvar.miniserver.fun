# Task 15 — canonical registration, authentication and session architecture

Updated: 19.07.2026

Status: implementation, documentation and local commit complete on existing `main`; configured HTTPS push was attempted and remains externally blocked by absent GitHub credentials.

## Goal and architecture

Audit and harden the existing Laravel authentication domain without a second guard, starter kit, provider model or account system. Browser authentication remains native Laravel `web` guard + encrypted/HttpOnly session cookie + CSRF + class-based Livewire; mobile API remains Sanctum bearer authentication with explicit abilities. Transport-neutral account services, Laravel Password Broker/Hash/email verification, the existing profile/account lifecycle and existing owner-state services remain the only mutation boundaries.

The user explicitly prohibits creating or running automated tests for Task 15. Existing tests and CI remain untouched; evidence is limited to static inspection, route/config/schema/data/query inspection, syntax/Pint/static analysis, Blade/Vite, safe browser/cookie/session smoke and the manual acceptance matrix.

## Immutable constraints

- Work only on existing `main`; no branch, worktree, PR branch, dependency or `.env` mutation.
- Preserve every user, password hash, verification timestamp, remember token, session, Sanctum token, profile, privacy choice, entitlement, restriction and owned portal record.
- No destructive production-like migration or writer operation; database inspection is read-only.
- Do not add Socialite, Fortify, Breeze, Jetstream, Volt, custom hashing, custom cryptography, mandatory queue/cron or fake provider controls.
- Social login/link/unlink, magic link, MFA, trusted-device UI and account merging are added only if a real current product model/provider/workflow exists; otherwise their absence and security boundary are documented.
- All state-changing browser actions remain CSRF-protected Livewire/POST operations; OAuth callback state is applicable only if an OAuth provider exists.
- Do not run any automated test command. Run `Pint`, syntax/static inspection, routes/config/schema, translation parity, Blade/Vite and safe browser evidence only.
- Changelog and README prose follow repository Russian-language policy despite the conflicting Task 15 request for an English changelog.
- Final delivery uses local `main` commit and attempts the configured push; external authentication failure remains `unresolved` and is not disguised.

## Documentation intake

- [x] Read `AGENTS.md`, `docs/requirements/index.md` and resolve required reading order.
- [x] Scan all existing Markdown files byte-for-byte: 175 files, 39,164 lines, 4,354,460 bytes; record SHA-256 inventory before implementation.
- [x] Read the applicable canonical owners in index order and validate linked-file existence.
- [x] Read the prior canonical Livewire auth design and implementation plan; treat implemented contracts as protected compatibility boundaries.
- [x] Re-read applicable requirements and Task 15 before final compliance closure.

## Current architecture — verified inventory

- Framework: Laravel 13 with native authentication; no Breeze, Fortify, Jetstream, Laravel UI, Socialite, Passport or external OAuth dependency.
- Browser guard: one configured `web` session guard using the Eloquent `users` provider. No separate administrator guard; admin access uses project gates on the same user identity.
- API guard: Sanctum bearer tokens with `mobile:read`/`mobile:write` abilities and owner-scoped controllers/resources.
- Passwords: Laravel `Hash` through the model `hashed` cast/guard; shared 12-character mixed-case/number/symbol `Password::defaults()` policy.
- Password recovery: one `users` Password Broker, `password_reset_tokens`, 60-minute expiry and 60-second broker throttle.
- Browser auth UI: one set of full-page class-based Livewire login/register/forgot/reset/verify/confirm components and one logout component; localized guest aliases reuse the same classes.
- Shared domain: `AccountRegistrationService`, `AccountService`, `AccountPasswordResetService`, `AccountEmailVerificationService`, `WebAuthenticationService`, `WebAuthenticationRateLimiter`, `AuthenticationRedirectService`, `AuthenticationAuditService`, `BrowserSessionService`, mobile token/auth services and registration availability.
- Registration: configurable `AUTH_REGISTRATION_ENABLED`; Web/API routes are conditionally registered and share account creation.
- Sessions: repository default is Redis; database sessions are supported without assuming production uses them. Cookie defaults are HttpOnly, SameSite=Lax, root-only domain unless configured and JSON session serialization.
- Verification: signed expiring Web/API completion routes and locale middleware; resend is authenticated and rate limited.
- Anonymous state: one existing `/settings/preferences/migrate` boundary migrates supported device preferences and, after this repair, a verified account's bounded `seasonvar.playback-progress.v1` snapshot. The canonical progress service accepts only visible/watchable episodes, preserves every existing account row, ignores client completion and returns accepted IDs for safe local cleanup. Anonymous bookmarks/statuses do not exist.
- Social authentication: no installed Socialite/OAuth provider package, provider routes, external-identity model/table or visible provider control found in initial inventory.
- Account merging: no user-account merge model/action/route found in initial inventory; content-target merge services are unrelated and must not be repurposed.
- Optional magic links/MFA/trusted-device identities: no initial model, package or route evidence; do not fabricate support.

## Audit and implementation phases

### Phase A — architecture, routes, configuration and schema

- [x] Inspect every Web/API auth route/name/method/middleware, localized alias, signed handler, legacy contract and conditional registration behavior.
- [x] Inspect `config/auth.php`, `config/session.php`, `config/sanctum.php`, `config/authentication.php`, cookie/CSRF middleware, exception redirects and trusted proxy/host behavior.
- [x] Inspect guard/provider/broker/Hash/version contracts against installed Laravel 13 source and version-matched official documentation.
- [x] Inspect users, profiles, reset tokens, sessions, personal access tokens, audit events and username history migrations/schema/indexes/foreign keys.
- [x] Inspect database for duplicate normalized emails/usernames, invalid hashes, missing/duplicate profiles, invalid verification/remember state, orphan tokens/sessions/audits and provider/merge artifacts.
- [x] Inspect registration, login identifier, email normalization, password policy/hashing/rehash, safe defaults, restrictions and retry behavior.
- [x] Inspect verification/resend/email-change and recovery/reset notification locale, signatures, expiry, hashing, replay and enumeration behavior.
- [x] Inspect login/logout/remember/session regeneration, `auth.session`, logout-other-devices, database/Redis limitations and token revocation.
- [x] Inspect redirect validation for intended/return/next/callback/reset/localized destinations, encoded/protocol-relative/external inputs and loops.
- [x] Inspect rate-limit definitions/keys/responses for Web and API registration/login/recovery/reset/verification/token refresh.
- [x] Inspect authentication audit payloads/retention/privacy and verify no password/token/session secret enters logs or exports.
- [x] Inspect access-status behavior for unverified, profile-limited/hidden/suspended/deleted and premium/restricted users across Web/API/social absence.

### Phase B — providers, collisions, anonymous state and lifecycle

- [x] Search code/schema/routes/config/UI/docs for real social providers, OAuth state, PKCE, external subjects, tokens, linking, unlinking and collision flows.
- [x] If absent, document social login/link/unlink/provider recovery/PKCE as unsupported and ensure no dead provider control or permissive callback exists.
- [x] Search for duplicate-account/merge workflows; verify matching email never triggers destructive automatic merge and document explicit administrator review requirement.
- [x] Inspect anonymous browser state stores and migration boundary: progress, history, bookmarks/statuses versus device-only locale/player/settings.
- [x] Verify login/registration cannot lose or overwrite stronger/newer authenticated state; nonessential migration failure cannot corrupt authentication.
- [x] Inspect account export allowlist for linked provider names/session summaries versus forbidden hashes/tokens/cookies/audit secrets.
- [x] Inspect deletion ordering for password confirmation, media/content policies, reset/session/Sanctum/remember revocation, future login and callbacks.
- [x] Inspect administration exposure: verification/status/provider metadata/session revocation boundaries without hashes/tokens/private payload.

### Phase C — Livewire, translations, UI, cache and SEO

- [x] Inspect Livewire public properties/actions for model serialization, password retention, stale/double submission, validation, locale and safe intended state.
- [x] Inspect Blade for direct queries/services, raw secrets/UGC, `@php`, inline CSS/business JS, missing labels/autocomplete/error association/loading and dead controls.
- [x] Inspect RU/EN auth catalogs and notification mail text for key/placeholder/plural parity and raw-key fallback.
- [x] Inspect responsive/accessibility states at narrow mobile, desktop and zoom/long-label equivalents; verify keyboard/touch/error/loading/unavailable behavior.
- [x] Inspect private response middleware/cache isolation; auth/session/reset/provider/intended state must never enter global cache or public page cache.
- [x] Inspect auth page robots/canonical/structured-data/sitemap behavior: noindex, no tokens or private state, no sitemap entries.
- [x] Implement only proven defects with the smallest compatible typed boundary; update this plan immediately per discovery.

### Phase D — documentation, verification and delivery

- [x] Update canonical authentication/security/authorization/data/UI/operations owners, known limitations and rollback/manual checklist without duplicate domain docs.
- [x] Update Russian `README.md` visitor history only for real visitor-facing change and add a separate Russian `CHANGELOG.md` entry without changing older entries.
- [x] Inspect all changed and directly related unchanged files; repository-wide duplicate/legacy/dead/token/cache/debug scan.
- [x] Run allowed fresh Pint/PHP syntax/focused PHPStan/routes/config/schema/query/translation/Blade/Vite/browser checks; do not invoke tests.
- [x] Reconcile every Task 15 acceptance item to `completed`, `already_compliant`, `not_applicable` or honest `unresolved` evidence.
- [x] Commit intentional tracked changes on clean `main`, then attempt configured push without invoking the prohibited test hook; GitHub rejected HTTPS publication with `could not read Username`, so no remote/security configuration was changed.

## Discoveries — append immediately

- Existing auth implementation is the completed 15.07.2026 native Laravel/Livewire design, not a starter kit. Web and API share account services rather than calling each other over HTTP.
- All listed HTML auth routes are GET full-page Livewire surfaces; mutations occur through Livewire update POST. Verification completion is the intentional signed thin route handler. API mutations use controllers/requests/resources.
- The route inventory contains localized aliases only for login/register/forgot/reset; provider callback routes do not exist. Provider codes in billing/import domains are unrelated to authentication.
- Repository default session driver is Redis, with database sessions as a supported conditional visibility/revocation path; no implementation may claim raw Redis session enumeration or device identity.
- Task 14 delivery left local `main` 19 commits ahead because configured GitHub HTTPS credentials are absent; Task 15 still commits independently and retries the configured remote.
- The configured SQLite census contains 102 users/profiles, all verified with bcrypt hashes, zero case-folded email duplicates, zero reset rows, 175 non-orphan database-session rows and 204 unique 64-character non-orphan Sanctum token hashes. No external-identity/social/MFA/magic-link/account-merge table exists.
- Contrary to the earlier Task 15 note, `resources/js/player.js` has a real bounded anonymous progress store. It contains only stable episode ID, position, duration, completion hint and timestamp, but the existing preference migration sends none of it. The compatible repair must reuse `episode_view_progress`, tag imported rows with non-verified provenance, preserve any pre-existing account row and keep authentication successful when optional migration fails.
- `anonymous-playback-progress.js` now owns the unchanged storage key. A verified-only migration returns accepted visible/watchable episode IDs in a private `204` header, so the client clears only the identical accepted snapshot; unavailable targets and positions written during the request remain local.
- Catalog-title merge is a directly related writer: its completion-source precedence is now `manual > playback/legacy playback session > anonymous > none`, preventing imported local state from replacing stronger evidence.
- Managed Chromium used the documented demo account and an existing canonical episode row to exercise the real HTTPS flow: login regenerated the private session, migration returned `204` with accepted ID `1`, the exact local snapshot was removed, the pre-existing database position/duration/source/completion remained byte-for-byte equivalent, and logout removed authenticated presentation without exposing the HttpOnly session cookie.

## Data safety, rollback and production impact

- Production-style database remains read-only. No `migrate`, destructive cache/session command, session scan, password/reset operation or real authentication mutation is allowed during audit.
- Any necessary migration must be additive, SQLite-compatible, idempotent, rehearsed only on a disposable database, preceded by duplicate reconciliation and documented with backup/writer-pause/locking/rollback/forward-fix steps.
- Code-before-migration must fail closed for writes without creating an account with unsafe defaults. Migration-before-code remains the deployment default where existing backfills would misclassify new rows.
- Rollback preserves hashes, verification timestamps, reset/session/remember/token records and old route names; newly emitted state must remain readable by the previous release or be guarded by deployment order.
- Authentication failures must not flush global cache, disclose infrastructure, invalidate unrelated user data or block login merely because nonessential anonymous preference migration fails.

## Compliance matrix — living evidence

| Requirement group | Status | Evidence / unresolved work |
| --- | --- | --- |
| One canonical auth architecture/guards | already_compliant | one native Laravel `web` guard + Eloquent provider; Sanctum is the separate existing API transport, not a competing user identity |
| Registration/defaults/email normalization | already_compliant | shared transactional service, canonical lowercased `NormalizedEmail`, conditional Web/API routes, profile privacy defaults and normalized preflight plus database uniqueness race handling verified; census has zero case-fold duplicates |
| Password policy/hashing | already_compliant | one Laravel `Password::defaults()` boundary, hashed model cast/guard and `Hash` service; census has 102 bcrypt hashes and zero blank/short hashes |
| Email verification | already_compliant | temporary signed ID/hash routes, 60-minute expiry, idempotent verification event, authenticated throttled resend and locale-aware project notification inspected |
| Recovery/reset/password change | already_compliant | Laravel broker owns hashed expiring tokens; generic recovery, replay removal, shared Password policy, remember rotation, current-password locks, session/Sanctum revocation inspected |
| Login/remember/logout | already_compliant | email-only canonical Livewire service, generic failures, explicit remember, guard rehash, session regeneration and CSRF logout invalidation inspected and browser-smoked |
| Sessions/logout-other/devices | already_compliant | Redis is opaque current-session storage; database driver exposes bounded HMAC summaries/revocation, Sanctum exposes owner-scoped hashed devices, and limitations are explicit |
| Redirect/open-redirect protection | already_compliant | one internal/same-origin resolver rejects protocol-relative, external, control, malformed/double-encoded and auth-loop destinations across all consumers |
| Rate limiting/brute force | already_compliant | HMAC identifier/network/scope buckets cover Web/API login/register/recovery/reset/verification/refresh without raw passwords/identifiers in keys |
| Social login/link/unlink/collisions | not_applicable | repository/package/route/schema/UI scan found no Socialite/OAuth identity boundary or provider controls; provider-email matching cannot link accounts because no callback exists |
| Safe account merging | not_applicable | repository/schema scan found no user merge capability or mapping; matching email is rejected by uniqueness and never merged automatically |
| Anonymous state migration | completed | existing bounded browser progress now best-effort migrates to canonical verified-account progress with target revalidation, existing-row precedence, non-completion provenance and accepted-snapshot cleanup; anonymous bookmarks/statuses remain absent |
| Locale/translations/emails | already_compliant | 116 RU/EN auth leaves have exact key/placeholder parity; Livewire routes, signed mail links and notification locale use the allowlisted active/stored locale |
| Livewire/a11y/responsive | already_compliant | single class-based components validate scalar/form state; visible labels/autocomplete/errors/loading/touch/keyboard states and 390/1440 Chromium layouts passed |
| CSRF/cookies/session fixation | already_compliant | native Web CSRF stack covers mutations, login regenerates session, logout invalidates/regenerates token; HTTPS headers confirm Secure, HttpOnly session and SameSite=Lax |
| Audit/privacy/cache | already_compliant | HMAC-only bounded auth audit, private/no-store middleware and shared-cache bypass exclude secrets, tokens, session/user state and anonymous payload |
| Account status/restrictions/premium | already_compliant | no separate login-status model exists; verified/restriction/premium permissions remain domain-owned, profile moderation does not become login authority, and deleted users cannot authenticate |
| Database uniqueness/indexes | already_compliant | users email/public ID, reset email, session ID and Sanctum hash uniqueness plus user/activity/tokenable/expiry indexes inspected; no duplicate/orphan auth records and no new auth DDL justified |
| Administration/export/deletion | already_compliant | existing gates/services expose no hashes/tokens/raw sessions; export allowlist excludes secrets and deletion requires fresh password then revokes reset/session/Sanctum/remember access |
| SEO/noindex/sitemap | already_compliant | guest and owner browser smoke returned noindex/nofollow; auth/reset/verification/callback management routes are absent from streamed sitemap and token-free metadata |
| Optional magic link/MFA/trusted devices | not_applicable | repository-wide package/model/config/route/schema/UI scan confirms these capabilities are absent and no fake control was added |
| Credential-dependent production delivery | unresolved | real verification/reset mail delivery and unavailable OAuth/provider callbacks were not invoked; repository/config/notification paths are inspected, OAuth is not installed, and no credentials or real user recovery action was requested |
| Automated tests | not_applicable | Task 15 explicitly prohibits creating or running them; existing test infrastructure is protected |
| Documentation/README/changelog | completed | architecture/security/authorization/data/frontend/cache/player/adapter/maintenance/plan owners plus Russian README and CHANGELOG reflect verified implementation |
| Git commit/push | unresolved | completed changes are committed on local `main`; `git push --no-verify origin main` was attempted after a clean-tree/main check and GitHub rejected it with `could not read Username`, so publication requires external credential restoration |

## Final verification checklist

- Re-read Task 15, this plan and every applicable canonical owner; map all 158 acceptance items honestly.
- Inspect routes, guards/providers/broker, registration/login/logout, verification/recovery/reset/change, remember/session/Sanctum devices and status checks.
- Inspect social/provider/identity/link/unlink/collision/merge absence or implementation, anonymous-state migration and locale/intended redirect.
- Inspect schema/data uniqueness/indexes, account deletion/export/admin, audit/cache/notifications and no secret/token exposure.
- Inspect Livewire/Blade/translation/email/a11y/responsive/loading/error/unavailable states and auth page noindex/sitemap exclusion.
- Inspect repository-wide duplicate/legacy/dead/auth control, custom hash, raw token, public cache and unfinished/debug patterns before delivery.
- Run only allowed fresh verification, update compliance/docs, commit on clean `main` and attempt configured push.

---

# Laravel Debugbar по `APP_DEBUG`

Обновлено: 19.07.2026

Статус: ограниченная реализация и package-specific verification завершены; полный repository suite сохраняет независимые baseline failures, Git delivery выполняется отдельно.

Исполняемый план и полная evidence matrix: [`../superpowers/plans/2026-07-19-laravel-debugbar-app-debug.md`](../superpowers/plans/2026-07-19-laravel-debugbar-app-debug.md).

## Compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Requirements, maintenance и production owners | completed | Канонический порядок прочитан до реализации и повторно сверен перед delivery |
| Dependency и compatibility | completed | `fruitcake/laravel-debugbar 4.4.0` только в `require-dev`; PHP/Laravel/Livewire metadata и exact lock проверены |
| Configuration/security | completed | Только `APP_DEBUG`; `force_allow_enable=false`; local true показывает панель, local false и production/testing блокируют её |
| Production/rollback | completed | `--no-dev`, `APP_DEBUG=false`, config/route cache rebuild; migration/data/storage/queue/Vite changes отсутствуют |
| Cross-feature domains | already_compliant | Auth, privacy, translations, cache data, search, SEO, notifications, admin, premium, region/legal и public routes не меняются |
| Tests/package/docs policies | completed | Focused 3/3 и 9 assertions, Pint, Composer validation/platform/audit/dry-run, environment gates, docs policies и legacy scan прошли |
| Full repository suite | unresolved | 1 268 tests выполнены: 1 214 passed; 37 failures и 6 errors принадлежат существующим Blade/`CacheDomain::UserPortal` проблемам вне Debugbar |
| README/CHANGELOG/canonical docs | completed | Отдельные русские записи и реестры обновлены; managed project-docs block проверен штатной командой и не требовал изменения |
| Commit/push | unresolved | Завершается на `main`; remote success не заявляется без фактического результата |
