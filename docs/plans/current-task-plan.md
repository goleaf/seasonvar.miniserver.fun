# Task 14 — повторный аудит и безопасное усиление профилей пользователей

Обновлено: 19.07.2026

## Цель

Повторно проверить уже существующий canonical profile domain после интеграций Tasks 15–23, устранить только доказанные дефекты stable identity, username history, public/private presentation, privacy, media, moderation, search, cache, SEO, account lifecycle и cross-feature visibility и сохранить все accounts, usernames, profile URLs, media, user content, privacy choices, blocks/mutes, reports and historical compatibility.

## Обязательные ограничения

- Работа ведётся только в существующей `main`; branches, worktrees и subagents не создаются.
- Пользователю не задаются вопросы. Scope и design выводятся из repository contracts; отдельный competing profile spec/plan не создаётся.
- Автоматизированные тесты не создаются и не запускаются. Разрешены static/runtime read-only inspection, route/schema/query/translation/security/browser smoke, Pint, Larastan, Blade compilation и Vite build.
- Existing tests и test infrastructure не удаляются и не повреждаются.
- Новые dependencies, queue/scheduler/Supervisor, parallel profile/user/media/privacy/search/SEO/cache systems и fake roles/badges/ranks/follows/activity controls не добавляются.
- Schema/data mutations допускаются только после доказанного legacy reconciliation, compatibility, backup, writer-pause, rollback и production-impact review.
- `CHANGELOG.md` остаётся на русском по каноническому `AGENTS.md`, несмотря на конфликтующее требование задачи об английском changelog.
- `README.md` проверяется перед завершением и меняется только при visitor-visible результате.
- Финальный commit создаётся только в `main`; configured push выполняется, а внешний отказ GitHub authentication фиксируется без изменения remote или secrets.

## Документационный baseline

- В repeat Task 14 полностью прочитан tracked corpus: `284` Markdown-файла / `62 494` строки / SHA-256 `45f2b56ad3c31c08f6a5a0582b3b343c7bc57dfd55a10aa9d476ff8b076633bb`.
- Применён обязательный порядок `docs/requirements/index.md`, включая multilingual, security, performance/cache, UI, administration, production operations, maintenance/upgrades и system-wide integration.
- Feature owners: `architecture.md`, `DATA_RELATIONS.md`, `authorization.md`, `security.md`, `performance.md`, `caching.md`, `views.md`, `frontend.md`, `administration.md`, `deployment.md`, `catalog-search.md`, sitemap/SEO и account/profile/recommendation owners.
- Existing Task 14 living contract в `laravel-video-portal-modernization.md` перечитан полностью; current plan расширяет его только свежим audit/compliance evidence и не создаёт второй domain document.
- Task не меняет framework/runtime/package/database engine/build tooling по намерению. Любая доказанная schema/browser/cache/runtime поправка получает compatibility и rollout evidence до edit.

## Выбранный дизайн

Рассмотрены три подхода:

1. **Additive hardening существующего canonical domain — выбран.** Сохраняются `User`, `UserProfile`, public UUID, username history, policies/services/query/DTO/Livewire/media/report/admin/cache/SEO boundaries, route names, enum codes and rows; исправляются только воспроизводимые расхождения.
2. Полная перестройка profile/user/privacy/media — отклонена: создаёт competing system и рискует accounts, usernames, URLs, content, files, privacy and account lifecycle.
3. Автоматическое добавление roles/badges/ranks/follows/profile directory/favorite genres/location/activity — отклонено до доказательства существующей product architecture. Уже существующие public-profile suggestions в canonical portal search сохраняются и усиливаются, но отдельный profile index/directory или второй search system не создаётся.

UI сохраняет существующую светлую Seasonvar product vocabulary: текущие profile header, tabs, forms, native file inputs, cards, pagination, dialogs, focus/reduced-motion и mobile patterns. Визуальная переработка допускается только для доказанного accessibility/responsive/functionality defect; новая тема или рекламный profile landing не создаётся.

## Предварительная canonical architecture для проверки

- `users.id` должен оставаться internal FK, `users.public_id` — stable opaque cross-domain identity; public route username обязан разрешаться только в тот же user/profile row.
- `user_profiles` должен быть единственным profile presentation row per user; `users.name` остаётся display name, email/auth/permissions/session/provider data не входят в public DTO.
- `ProfileUsername`, current normalized username и `user_profile_username_histories` должны централизованно обеспечивать case/reserved/route-safe validation, collision handling and loop-free redirects.
- `ProfileVisibility`, `ProfileSection`, `ProfileModerationStatus`, report categories/status and media kinds должны быть единственными persisted value boundaries; translated labels не сохраняются.
- Public page должен загружать только выбранную privacy-eligible секцию через canonical Task 10/12/13/library queries; owner page/settings/export/delete должны оставаться separate private payloads.
- Avatar/cover должны храниться на private upload disk, иметь server-generated paths и authorization-aware `private, no-store` delivery без SVG/executable/path leakage.
- Public cache/SEO/sitemap eligibility должна зависеть от actual current privacy/moderation/content state; block/mute/owner controls остаются viewer-specific and never globally cached.

## Cross-feature impact matrix

| Domain | State | Required verification |
| --- | --- | --- |
| Authentication/registration/settings | affected | one user identity, registration defaults, password-confirmed username/media/privacy writes, no credential exposure |
| Comments/reviews | affected | canonical published/spoiler-safe profile queries, stable author links, block/mute and target eligibility |
| Collections | affected | public/unlisted/private owner boundaries, legacy UUID owner route and canonical username link |
| Library/progress/history/bookmarks | affected | explicit public watching/completed only; no episode/progress/history/timestamp/translation leakage |
| Blocks/mutes | affected | bilateral block 404, private mute overlay and direct-link behavior without shared-cache leakage |
| Reports/moderation/restrictions | affected | stable codes, reporter/private note secrecy, media/biography moderation and account-state separation |
| Notifications | affected | safe display name/profile links, preferences, block/mute and deleted-user behavior |
| Search/recommendations | affected | no email/private biography indexing; private activity signals remain owner-only |
| SEO/structured data/sitemap | affected | canonical username, localized alias policy, indexable public overview only, no private fields |
| Cache/performance | affected | no global viewer payload, targeted version bump, matching counts, grouped/eager paginated sections and real indexes |
| API/imports | affected | user/public ID and API field compatibility; no new public account/profile write surface |
| Administration | affected | `manage-catalog`, bounded report queue, safe identity/media context and no credential/role mutation |
| Account export/delete | affected | allowlisted profile export; media/profile/search/cache cleanup and historical content anonymization |
| Premium/region/legal | affected | no public premium badge inference, target eligibility reused, no access-state exposure |
| Mobile/a11y/translations | affected | RU/EN parity, long identity/biography/tabs, file/privacy/report/loading/error/focus states |

## Phased implementation checklist

### Phase A — requirements, routes, schema and data

- [x] Confirm `main`, clean Task 13 baseline, recent history and configured remote state.
- [x] Read complete tracked Markdown inventory and canonical requirements in required order.
- [x] Inventory public/localized/legacy/private/admin/media/API profile routes, names, middleware, binding and destructive methods.
- [x] Inventory user/profile/history/privacy/media/block/mute/report/notification/account tables, migrations, models, enums, FKs, unique constraints and indexes.
- [x] Measure production-style row counts, visibility/moderation/media/section distributions and bounded duplicate/orphan/invalid-value anomalies.
- [x] Inspect query plans for current/history route lookup, public search/sitemap and moderation queue; selected-section/count plans remain in the final query pass.

### Phase B — domain/security/privacy/concurrency

- [x] Inspect stable ID, username normalization/history/case/reserved/change/race/redirect and deleted-user behavior.
- [x] Inspect public DTO allowlist versus owner/settings/admin/export payloads and raw Livewire/HTML/JSON leakage.
- [x] Inspect biography/display-name normalization, Unicode/original language, XSS/link/control/bidi policy and empty/long states.
- [x] Inspect avatar/cover upload validation, private storage, dimensions/size/MIME, replacement cleanup, media delivery and moderation.
- [x] Inspect profile/section privacy defaults and exact public comments/reviews/collections/watching/completed predicates.
- [x] Inspect block/mute/report/moderation/account restriction/deletion/notification interactions and private evidence.
- [x] Inspect update actions for ownership, password confirmation, mass assignment, optimistic locking, idempotency and after-commit effects.
- [x] Inspect account export/delete, registration and title/user-data compatibility after later tasks.

### Phase C — SEO/performance/UI/integration

- [x] Inspect cache keys/versions/invalidation and prove viewer/private data never enters global cache.
- [x] Inspect public counts, selected projections, eager/grouped queries, pagination, tab lazy loading and actual index plans.
- [x] Inspect profile search absence/presence, author/profile link consumers and recommendation privacy.
- [x] Inspect SEO title/description/canonical/robots/hreflang/JSON-LD/sitemap eligibility and legacy redirects.
- [x] Inspect Blade/JS for raw UGC, DOM sinks, model/service calls, full graphs, hardcoded labels, Volt, `@php`, inline CSS/business JS and dead controls.
- [x] Inspect RU/EN key/placeholder/plural parity and locale hydration without translating usernames/display names/biographies.
- [x] Browser smoke public/private/localized/legacy/media states at desktop/mobile with accessibility/console/network evidence where safe; owner mutation was intentionally not executed against production-style data.
- [x] Implement only proven defects and immediately add each discovery below.

### Phase D — documentation, verification and delivery

- [x] Reread Task 14 and applicable requirements; complete compliance only from fresh evidence.
- [x] Update canonical owners, verification report, maintenance log, Russian changelog and visitor README for the actual behavior changes.
- [x] Run allowed Pint/PHP syntax/Larastan/Blade/Vite/docs/translation/security/browser checks; no test runner.
- [x] Repository-wide legacy/duplicate/dead/private-cache/profile-data scan and changed/related file review.
- [ ] Commit intentional tracked changes on clean `main`; attempt configured push without invoking prohibited automated tests.

## Audit discoveries — update immediately

- Existing Task 14 implementation already exposes one substantial Profiles namespace: `UserProfile`, username history, reports, typed actions/services/query/DTO/policy, public/private/admin full-page Livewire components, private media responder, RU/EN catalogs, sitemap/SEO integration and account lifecycle hooks.
- Route inspection confirms canonical `/users/{username}`, localized interface alias `/{locale}/users/{username}`, private `/profile*`, authorized `/admin/profiles`, authorized versioned `/profiles/media/*`, streamed `/sitemap-profiles.xml` and retained UUID collection-owner compatibility. All profile mutations remain Livewire POST requests; no destructive profile GET was found.
- Current product intentionally has no durable public roles, badges, ranks, follows, favorite genres, location, standalone profile directory/index or public activity feed. Later Task 02 did add public active-profile suggestions to the one canonical portal search using only username/display name; the old Task 14 statement that profile search was wholly absent is stale and must be corrected in canonical docs.
- Configured SQLite has `102` users and exactly `102` profile rows, zero missing/orphan profiles, zero normalized/shape-invalid usernames, invalid visibility/moderation values, over-limit/markup biography candidates, partial media metadata, current/history conflicts, profile reports, self blocks/mutes or duplicate block/mute relations. It contains `77` active public and `25` active private profiles; existing section choices are preserved.
- Exact query-plan inspection confirms unique-index lookup for current username and history, the moderation queue composite index, and directional block/mute unique indexes. Public search and sitemap correctly narrow through `user_profiles_public_listing_idx`, but use bounded temporary ordering; at the current 77-row public corpus this does not justify a speculative redundant index.
- `UserProfileService::changeUsername()` currently calls `RateLimiter::clear()` after every successful change and on a same-name retry. Therefore configured `5/hour` successful changes never accumulate and a no-op can erase prior attempts; this is a confirmed abuse-control defect to fix without changing username identity or rows.
- Final diff review additionally confirmed that password verification for a same-name request must remain after the limiter hit: putting the no-op before the limiter would create an unbounded current-password oracle. The delivered order rate-limits valid and invalid no-op attempts, verifies password, preserves identity/version/history and never clears the accumulated bucket.
- `UserProfileSchema::available()` checks only three table names. Portal search and profile sitemap directly query `user_profiles` without the schema boundary. A code-before/additive-migration or partial-schema deployment can therefore produce a public 500 instead of fail-closed empty search/sitemap or profile 404; complete required-column readiness and consumer guards are required.
- Public `PublicUserProfileData` is an explicit allowlist without email/provider/session/progress/history/private collection/report/moderation-note fields. Owner settings/export and admin moderation use separate private/no-store payloads; selected public review/comment/collection/watch sections reuse canonical queries and never emit exact progress.
- Avatar/cover delivery reauthorizes the current profile, checks exact public UUID/kind/version/private disk/owned prefix/MIME/existence/traversal and returns `private, no-store`, `nosniff`, `noindex`. Upload accepts bounded JPEG/PNG/WebP only; SVG/executable/public paths are absent and the current no-derivative/no-EXIF-processor limitation is already documented honestly.
- Public username/display-name suggestions already exist in the canonical Task 02 portal search. The repeat audit added a full-schema guard and corrected stale documentation; no profile biography/email search, standalone directory or competing index was added.
- Case/history/localized redirects previously discarded selected public tab/pagination. They now retain only the allowlisted active tab and its positive paginator while removing tracking and unrelated page keys; the browser reproduced canonical `/users/polzovatel?tab=reviews&reviewsPage=2` from an English uppercase alias.
- Owner profile actions previously had no explicit recoverable failure presentation, and the service key `profile_password` did not bind to Livewire `profilePassword`. The component now maps the field, announces localized safe failure and retains non-secret biography/privacy/file drafts while clearing secret password state.
- Biography/report details allowed part of the control-character space and long public biography had no accessible collapse. Canonical plain-text normalization and server validation now cover control/surrogate/bidi input; a prepared preview plus native `<details>` supplies keyboard/screen-reader expand/collapse without JavaScript business logic.
- A proposed registration/account-lifecycle bypass while profile schema is unavailable was rejected after inspecting the immutable backfill: a user created in that gap would be treated as legacy and become public, while deletion could skip private-media cleanup. Migration-before-code remains mandatory; only public read-only search/sitemap degrade to an empty result.
- Fresh HTTPS/Chromium evidence: public profile 200, one canonical/index metadata and ProfilePage/Person JSON-LD, `private,no-store`, no private field markers or horizontal overflow at 320px, zero public console errors; active private profile returned non-disclosing 404; sitemap contained 75 eligible URLs and no profile tabs.
- Prior Task 13 push remains externally blocked by missing GitHub HTTPS credentials; repeat Task 14 will still create its own local `main` commit and retry the configured push.

## Database, migration and production review

- Deployed migrations are immutable. No uniqueness/index is added before actual duplicate/anomaly reconciliation and `EXPLAIN` evidence.
- Potential changes are `safe_additive`, `additive_backfill` or `compatibility` only unless an unavoidable conflict is documented.
- Production-style database remains read-only during audit. Any migration is rehearsed on a new disposable SQLite database and documented with backup, writer pause, disk/locking, rollback and forward-fix behavior.
- No cache flush, `migrate:fresh`, `db:wipe`, hard-delete, queue/scheduler or provider call is used.

## Files expected to change

Only evidence may determine the final set. Likely owners if defects exist:

- `app/Actions/Profiles/*`, `app/Services/Profiles/*`, `app/Livewire/Profile/*`, `app/Models/UserProfile*.php`, `app/Policies/UserProfilePolicy.php`;
- `app/DTOs/Profiles/*`, `app/Enums/Profile*.php`, `app/ValueObjects/Profile*.php`, media/report/moderation/notification/account services;
- author-link consumers in comments/reviews/collections, search/recommendations/cache/sitemap/SEO/account lifecycle/API boundaries;
- `resources/views/livewire/profile/*`, `resources/views/components/profile/*`, Vite-managed profile module when one exists, `lang/{ru,en}/profiles.php`, `config/profiles.php`;
- canonical documentation owners, `docs/plans/current-task-plan.md`, verification report, maintenance log, `CHANGELOG.md` and `README.md` only for factual visitor-visible change.

## Contracts that must remain unchanged unless complete dependency migration is proven

- Stable `users.id`, `users.public_id`, username/history mappings, display names/emails/passwords/verification, public profile and legacy route names/URIs.
- Existing profile/privacy/moderation/report/media enum values, database columns, public IDs, storage paths, content/media versions and account lifecycle semantics.
- Comment/review/collection/title/season/episode IDs/slugs, library/progress/history/bookmarks/tags/recommendations/importer/player/premium behavior.
- Existing cache/version keys, notification types/preferences, gate names, API fields, Composer/npm locks and test infrastructure.

## Rollback and failure recovery

- Code-only hardening reverts as one repeat Task 14 commit while stable rows/schema/routes/media remain readable.
- Additive schema requires verified backup, stopped writers where locking is possible, disposable down/up rehearsal and forward-fix plan before production activation.
- Failed mutation rolls back domain/audit transaction; media cleanup, notifications, cache/search/sitemap effects occur only after commit and remain idempotent or best-effort.
- Cache outage reads authoritative privacy or fails closed for permissions; no shared viewer overlay is introduced.
- Partial asset build retains previous compatible manifest/assets; no broad asset/cache deletion is used as recovery.
- Media rollback never deletes an existing owned file until the replacement transaction succeeds; missing/corrupt files fail with non-disclosing response.

## Compliance matrix — final evidence state

| Requirement group | Status | Evidence / unresolved work |
| --- | --- | --- |
| One canonical model/stable identity | already_compliant | one `User` + one PK/FK `UserProfile`; exact 102/102 production-style census, public UUID and no competing profile table |
| Username/history/redirects | completed | limiter accumulates real changes, password/no-op/concurrent state is safe, current/history/case/localized redirects preserve only allowlisted tab/page state |
| Public/private payload separation | already_compliant | public DTO and selected relations are allowlisted; owner/export/admin use separate private boundaries and no public query selects email/security fields |
| Biography/display name/original language | already_compliant | display name stays separate; escaped Unicode plain text, control/bidi/HTML stripping, 1,200 limit and zero stored anomalies confirmed |
| Avatar/cover/private media | already_compliant | private raster-only upload and policy/version/path/MIME/existence-checked no-store responder confirmed; derivative/EXIF processing remains documented unsupported |
| Privacy/sections/history | already_compliant | stable public/private per-section codes, 77 public/25 private preserved choices and serial-level-only watch output confirmed; exact history/progress has no public field |
| Roles/badges/ranks/follows/genres/location/activity | not_applicable | no canonical product model documented; absence must be confirmed in code/schema/UI |
| Reviews/comments/collections | already_compliant | selected sections reuse canonical Task 10/12/13 queries/presenters, exact target/spoiler/block/mute rules and matching counts |
| Blocking/muting | already_compliant | bilateral block is a non-disclosing 404; mute is a viewer-only overlay/action and neither relation enters public cache/SEO |
| Reports/moderation/restrictions | completed | stable enum/auth/dedup/rate/admin boundaries confirmed; report/private-note prose now uses canonical plain-text sanitation and private evidence remains excluded |
| Search/recommendations | completed | active-public username/display-name suggestions are canonical, full-schema guarded and private fields absent; recommendations remain owner/private-signal scoped |
| Cache/performance/counts | already_compliant | direct public reads, selected paginators/grouped counts/eager profiles and existing selected indexes confirmed; no viewer HTML cache or speculative DDL |
| SEO/structured data/sitemap | already_compliant | overview-only canonical/ProfilePage/Person policy, no alternates for untranslated UGC, 75 eligible streamed sitemap URLs and private 404 confirmed |
| Multilingual/Livewire/a11y/mobile | completed | RU/EN 113-key/placeholder parity, correct password-field mapping, accessible native biography disclosure, safe failure state and 320px no-overflow browser evidence |
| Account export/delete/registration/admin/API | already_compliant | allowlisted export, media-first deletion, privacy-safe admin queue and API/auth contracts re-inspected; migration-before-code remains mandatory |
| Production data application | already_compliant | audit is read-only; no migration or destructive operation planned before evidence |
| Automated tests | not_applicable | user explicitly prohibited test creation/execution; existing infrastructure remains protected |
| Documentation/README/changelog | completed | canonical owners, search correction, verification/maintenance evidence, Russian changelog and visitor README updated without duplicate domain docs |
| Git commit/push | unresolved | final local `main` commit and configured push occur after the last clean verification pass |

## Final verification checklist

- Reread Task 14, requirements, this plan and every applicable profile owner.
- Inspect every changed file and directly related unchanged route/model/action/service/DTO/policy/query/cache/SEO/media/account/admin/API file.
- Verify stable identity, username/history/redirect, public/private payload, privacy defaults/sections, UGC sanitization, media, blocks/mutes, reports/moderation and account lifecycle.
- Verify canonical comments/reviews/collections/watch sections, matching counts, target visibility and absence of detailed history/progress/private collection leakage.
- Verify query plans/uniqueness/index usefulness, bounded pagination/lazy tabs, no N+1 and no private shared state.
- Verify route/localized/legacy/canonical/robots/hreflang/JSON-LD/sitemap/search/recommendation behavior.
- Verify Blade/JS/Livewire security and accessibility: no raw UGC/DOM sink/Volt/`@php`/inline CSS/business JS/model query/dead control.
- Run only allowed diagnostics and safe browser smoke; preserve tests without invoking them.
- Update documentation/compliance honestly, including not-performed or externally blocked evidence, then commit and attempt configured push from clean `main`.
