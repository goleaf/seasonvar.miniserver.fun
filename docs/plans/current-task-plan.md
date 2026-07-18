# Task 13 — повторный аудит и безопасное усиление отзывов

Обновлено: 18.07.2026

## Цель

Повторно проверить уже существующий canonical reviews domain после поздних интеграций портала, устранить только доказанные дефекты identity, authorization, privacy, rating/helpfulness, verified watching, moderation, cache, SEO, structured data, query/UI и compatibility и сохранить все отзывы, оценки, голоса, отчёты, ограничения, уведомления, stable anchors и moderation evidence.

## Обязательные ограничения

- Работа ведётся только в существующей `main`; branches, worktrees и subagents не создаются.
- Пользователю не задаются вопросы. Design и scope выводятся из repository contracts; отдельный competing spec/plan не создаётся.
- Автоматизированные тесты не создаются и не запускаются. Разрешены static/runtime read-only inspection, route/schema/query/translation/security/browser smoke, Pint, Larastan, Blade compilation и Vite build.
- Existing tests и test infrastructure не удаляются и не повреждаются.
- Новые dependencies, queue/scheduler/Supervisor, произвольные review target classes, rich HTML/Markdown, translated identity values и отдельная comments/reviews architecture не добавляются.
- Schema/data mutations допускаются только после доказанного legacy reconciliation, compatibility, backup, writer-pause, rollback и production-impact review.
- `CHANGELOG.md` остаётся на русском по каноническому `AGENTS.md`, несмотря на конфликтующее требование задачи об английском changelog.
- `README.md` проверяется перед завершением и меняется только при visitor-visible результате.
- Финальный commit создаётся только в `main`; configured push выполняется, а внешний отказ GitHub authentication фиксируется без изменения remote или secrets.

## Документационный baseline

- В Task 13 потоково прочитан полный tracked corpus: `284` Markdown-файла / `62 489` строк / SHA-256 `8a4cced84b4dbb86bf5289d6f0309355f00dd4b75cf313050d9335d8209bfa01`.
- Применён обязательный порядок `docs/requirements/index.md`, включая multilingual, security, performance/cache, UI, administration, production operations, maintenance/upgrades и system-wide integration.
- Feature owners: `architecture.md`, `DATA_RELATIONS.md`, `authorization.md`, `security.md`, `performance.md`, `caching.md`, `views.md`, `frontend.md`, `administration.md`, `deployment.md`, SEO/sitemap owners и account/profile/recommendation requirements.
- Task не меняет framework/runtime/package/database engine/build tooling по намерению. Любая доказанная schema/browser/cache/runtime поправка получает compatibility и rollout evidence до edit.

## Выбранный дизайн

Рассмотрены три подхода:

1. **Additive hardening существующего canonical domain — выбран.** Сохраняются `CatalogTitleReview`, существующие actions/query/presenter/policy/notification/cache/admin boundaries, stable IDs, routes, enums и rows; исправляются только воспроизводимые расхождения.
2. Полная перестройка review/rating/helpfulness — отклонена: создаёт competing system и рискует потерять provider/community rows, votes, reports, aliases и public anchors.
3. Автоматическое расширение на season/episode reviews — отклонено до доказательства существующего product requirement. Unsupported target scopes останутся отсутствующими без fake controls.

UI сохраняет существующую светлую Seasonvar product vocabulary: текущие form, rating, badges, cards, dialogs, pagination, focus/reduced-motion и mobile patterns. Визуальная переработка допускается только для доказанного accessibility/responsive/functionality defect.

## Предварительная canonical architecture для проверки

- Dedicated `catalog_title_reviews` domain отделён от threaded `comments`; public/user review workflow использует `CatalogTitleReview`, imported/provider provenance не превращается в comment.
- Stable numeric review ID и alias/direct-link boundary должны оставаться независимыми от title/body/rating/locale/slug/author/sort/page.
- `ReviewTargetType`, `ReviewRating`, `ReviewTitle`, `ReviewBody`, `ReviewStatus`, `ReviewVoteType`, reports/restrictions/origin enums должны быть единственными persisted value boundaries.
- Web UI использует one page-level `CatalogTitleReviews`; profile/admin/direct-link/API consumers должны delegate canonical query/presenter rather than copy visibility.
- Review rating и general `CatalogTitleRating` semantics, verified-watching evidence, one-review rule, origin behavior and deletion/restore relationship требуют fresh code/schema/data verification before any conclusion.
- Public review payload and aggregate may be cacheable only without viewer vote, permissions, blocks/mutes, pending ownership, restriction/report/moderation/private progress evidence.

## Cross-feature impact matrix

| Domain | State | Required verification |
| --- | --- | --- |
| Title/season/episode/player | affected | exact supported scope, target visibility, direct focus, no progress/player mutation |
| General ratings/library/bookmarks/history | affected | rating independence/synchronization, verified evidence, no unintended state changes |
| Comments | affected | dedicated models/routes/renderers, no automatic conversion or duplicate notification |
| Profiles/privacy/account | affected | public section privacy, spoiler-safe excerpt, export/delete/anonymization |
| Blocks/mutes | affected | viewer-aware list/direct/vote/notification behavior without shared-cache leakage |
| Reports/moderation/restrictions/audit | affected | stable enums, authorization, expiry, private evidence, atomic transitions |
| Notifications | affected | preferences, deduplication, body/spoiler exclusion, deleted target/review behavior |
| Search/recommendations | affected | no review-body leakage; signals and invalidation remain bounded and privacy-safe |
| SEO/structured data/sitemap | affected | canonical/noindex, valid rating source, spoiler exclusion, no review sitemap entries |
| Cache/performance | affected | public/private dimensions, counts/aggregates, grouped overlays, real index plans |
| API/imports | affected | read-only API compatibility, provider provenance, no new write/public target surface |
| Administration | affected | `manage-reviews`, bounded queue/context/reports, private note/reporter protection |
| Premium/region/legal | affected | target eligibility reused; no fake premium review feature or policy bypass |
| Mobile/a11y/translations | affected | RU/EN parity, rating/spoiler/vote/filter/focus/loading/error states |

## Phased implementation checklist

### Phase A — requirements, routes, schema and data

- [x] Confirm `main`, clean Task 12 baseline, recent history and configured remote state.
- [x] Read complete tracked Markdown inventory and canonical requirements in required order.
- [x] Inventory all public/localized/profile/admin/API review routes, names, middleware, binding and destructive methods.
- [x] Inventory review/rating/vote/report/restriction/notification/alias tables, migrations, models, enums, FKs, unique constraints and indexes.
- [x] Measure production-style rows, target/origin/status/rating/vote distributions and bounded anomaly counts.
- [x] Inspect query plans for public page/count/aggregate, viewer vote/relationship overlay, profile, API and moderation queue.

### Phase B — domain/security/privacy/concurrency

- [x] Inspect target allowlist, identity/alias/direct-link, title merge/target deletion and comment separation.
- [x] Inspect title/body normalization, XSS/link policy, spoiler/long-body source omission and UGC locale handling.
- [x] Inspect create/edit/delete/restore one-review rule, idempotency, optimistic locking and rating behavior.
- [x] Inspect rating scale, optionality, external/general rating separation and aggregate definitions.
- [x] Inspect verified-watching service against canonical progress/history without exposing exact evidence.
- [x] Inspect helpful vote TOCTOU, uniqueness, self/block rules, totals and sorting formula.
- [x] Inspect reports/moderation/restrictions/audit, private evidence, expiration and safe errors.
- [x] Inspect notifications/preferences/dedup, profile/account export/delete, block/mute and cache separation.
- [x] Inspect anti-spam/rate/content duplicate/link controls and normal-user false-positive bounds.

### Phase C — SEO/performance/UI/integration

- [x] Inspect public counts/aggregates/cache invalidation/search/recommendation/title merge dependencies.
- [x] Inspect SEO canonical/robots/JSON-LD/aggregate rating/sitemap for valid source and spoiler/private exclusion.
- [x] Inspect selected columns/eager/grouped queries/pagination/filters/sorts/Livewire payload and actual indexes.
- [x] Inspect Blade/JS for raw UGC, DOM sinks, model calls, full graphs, hardcoded labels, Volt, `@php`, inline CSS/business JS and dead controls.
- [x] Inspect RU/EN key/placeholder/plural parity and locale hydration without translating reviews.
- [x] Browser smoke public/direct/localized title review UI, desktop/mobile/zoom/focus/spoiler/filter/pagination/console/network where safe.
- [x] Implement only proven defects and immediately add each discovery below.

### Phase D — documentation, verification and delivery

- [x] Reread Task 13 and applicable requirements; complete compliance only from fresh evidence.
- [x] Update canonical owners, verification report, maintenance log, Russian changelog and visitor README when behavior changes.
- [x] Run allowed Pint/PHP syntax/Larastan/Blade/docs/translation/security/browser checks; no test runner. Vite build is not applicable because no Blade/JS/CSS/Tailwind asset changed.
- [x] Repository-wide legacy/duplicate/dead/private-cache/spoiler-source scan and changed/related file review.
- [ ] Commit intentional tracked changes on clean `main`; attempt configured push without invoking prohibited automated tests.

## Audit discoveries — update immediately

- Existing inventory already exposes one substantial Reviews namespace: dedicated actions, DTOs, enums, value objects, model/policy/services, full-page Livewire title/profile/admin components, RU/EN catalogs, Vite module, direct route and read-only API resource/query.
- Review and comments class namespaces/tables are distinct; provider/community origin and rating relationship remain unverified until schema and service inspection.
- Public web inventory currently shows `reviews.show`, authenticated `profile.reviews`, gated `admin.reviews`; API exposes read-only title reviews and separate current-user general rating writes. Localized review route compatibility is not yet established.
- Prior Task 12 push remains externally blocked by missing GitHub HTTPS credentials; Task 13 will still create its own local `main` commit and retry configured push.
- Read-only production-style audit measured `1 720 085` reviews, `3 294 158` helpfulness votes, `79` aliases, zero reports/restrictions and `1 646 903` non-null portal ratings. Existing unique indexes cover ownership/submission and `(review,user)` vote identity.
- A concrete lifecycle anomaly exists: exactly one `origin=user,status=removed` row has `deleted_at IS NULL`; all `79` provider `removed` rows are merge tombstones with both deletion and merge state. Its provenance and existing demo/convergence path must be established before an additive idempotent repair; public visibility currently remains fail-closed because only `published` is public.
- Public title review lists/direct resolution exclude viewer-blocked and viewer-muted authors through `ReviewRelationshipService`, but `CatalogTitleReviewQuery::forPublicAuthor()` and `publicCountForAuthor()` currently omit that boundary. A blocked profile is denied by `UserProfilePolicy`, while a muted profile intentionally remains viewable, so its review tab/count leaks muted-author activity and diverges from canonical comment-profile behavior. Both public-profile reads must share one viewer-aware review builder.
- Locale-route parity is incomplete: comments expose both `comments.show` and `localized.comments.show`, while reviews expose only `reviews.show` and always generate that unprefixed direct URL. A backward-compatible `localized.reviews.show` alias must delegate the same responder, keep stable ID/404/block/no-store rules and preserve locale through the existing `collection.locale` session boundary before redirecting to the unchanged canonical title route.
- The removed-state anomaly is a shared writer defect, not only legacy data: `ModerateCatalogTitleReview` persists `status=removed` without moderator `deletion_reason/deleted_by_id/deleted_at` and does not restore a moderator tombstone when moderation returns to another state; `DemoCommunityStage` mirrors the same incomplete lifecycle. The action must preserve author/privacy deletion, create/restore only moderator deletion atomically, the demo writer/auditor must enforce the same invariant, and an additive idempotent convergence migration must repair only already-removed rows using existing moderator/moderated timestamps without touching text/identity/votes/reports.
- `ReviewSchema::communityAvailable()` does not currently require every column used by edit/delete/moderation (`edited_at`, deletion reason/actor, moderation actor/reason/note/time). The configured database is complete, but an incomplete rollout could incorrectly expose writable UI and then fail at SQL time. Capability detection must include the existing lifecycle columns while retaining the legacy provider read-only fallback.

## Database, migration and production review

- Deployed migrations are immutable. No uniqueness/index is added before actual duplicate/anomaly reconciliation and `EXPLAIN` evidence.
- Potential changes are `safe_additive`, `additive_backfill` or `compatibility` only unless an unavoidable conflict is documented.
- Production-style database remains read-only during audit. Any migration is rehearsed on a new disposable SQLite database and documented with backup, writer pause, disk/locking, rollback and forward-fix behavior.
- No cache flush, `migrate:fresh`, `db:wipe`, hard-delete, queue/scheduler or provider call is used.

## Files expected to change

Only evidence may determine the final set. Likely owners if defects exist:

- `app/Actions/Reviews/*`, `app/Services/Reviews/*`, `app/Livewire/Reviews/*`, `app/Models/CatalogTitleReview*.php`, `app/Policies/CatalogTitleReviewPolicy.php`;
- `app/DTOs/Reviews/*`, `app/Enums/Review*.php`, `app/ValueObjects/Review*.php`, `app/Notifications/ReviewActivityNotification.php`;
- title/profile/account/recommendation/cache/merge/direct-link/API/SEO boundaries;
- `resources/views/livewire/reviews/*`, `resources/views/components/reviews/*`, `resources/js/reviews.js`, `lang/{ru,en}/reviews.php`, `config/reviews.php`;
- canonical documentation owners, `docs/plans/current-task-plan.md`, verification report, maintenance log, `CHANGELOG.md` and `README.md` only for factual visitor-visible change.

## Contracts that must remain unchanged unless complete dependency migration is proven

- Stable review IDs, aliases, public anchors, route names/URIs, enum values, provider origin, persisted title/body/rating/spoiler/status/deletion/edit/moderation timestamps.
- `CatalogTitleRating` public/API semantics, comments, title/season/episode IDs/slugs, player/progress/history/library/tags/collections/recommendations/import command.
- Existing cache/version keys, notification types/preferences, report/restriction/vote codes, admin gate names, Composer/npm locks and test infrastructure.

## Rollback and failure recovery

- Code-only hardening reverts as one Task 13 commit while stable rows/schema/routes remain readable.
- Additive schema requires verified backup, stopped writers where locking is possible, disposable down/up rehearsal and forward-fix plan before production activation.
- Failed mutation rolls back domain/audit transaction; notifications/cache effects occur only after commit and remain idempotent.
- Cache outage rebuilds authoritative public state or fails closed for permissions; no shared viewer overlay is introduced.
- Partial asset build retains previous compatible manifest/assets; no broad asset/cache deletion is used as recovery.

## Compliance matrix — final evidence state

| Requirement group | Status | Evidence / unresolved work |
| --- | --- | --- |
| One canonical model/stable identity/targets | already_compliant | one `CatalogTitleReview`, numeric ID + aliases, title allowlist; season/episode review targets are absent by product contract |
| Separation from comments/imported reviews | already_compliant | dedicated comment/review models, tables, actions and engagement; `provider|user` origin preserves importer/API compatibility |
| Title/body/Unicode/XSS/link validation | already_compliant | `ReviewTitle`/`ReviewBody`/`UserPlainText`, escaped plain text, bounded links/lines/repetition, no raw renderer |
| Rating scale/general-rating relationship | already_compliant | optional integer `1–10` reuses unique `catalog_title_user_states`; review deletion preserves independent portal rating; external ratings remain separate |
| One-review rule/duplicates/idempotency | already_compliant | ownership/submission keys, locks and current data show zero duplicate current reviews/keys; historical merge aliases retained |
| Verified watching/privacy | already_compliant | server-only meaningful progress/completion snapshot, non-downgrading boolean, no client flag/exact evidence exposure |
| Spoiler/previews | already_compliant | unrevealed title/body absent from DTO/HTML/profile/notification/search/SEO/schema; server reveal/hide preserved |
| Edit/delete/restore | completed | owner lifecycle remains; moderator removed lifecycle now has complete reason/actor/time and restores only moderator tombstone |
| Helpful voting/sorting/filtering/pagination | already_compliant | one `helpful|not_helpful` vote, self/block enforcement, derived score, allowlisted deterministic filters/sorts and pagination |
| Profiles/direct links | completed | list/count share bounded block/mute-aware builder; localized route/helper added while `reviews.show` and aliases remain compatible |
| Reports/moderation/restrictions/audit | completed | stable private/gated domain retained; deletion reason/actor added to exact audit fingerprint and demo integrity audit |
| Notifications/preferences/dedup | completed | body-free deterministic payload retained; direct destination now reuses canonical presenter with account locale |
| Anti-spam/rate/duplicate prevention | already_compliant | per-action/global budgets, submission UUID, ownership/hash windows and bounded text/link controls re-inspected |
| Counts/aggregates/cache/performance | already_compliant | public/user predicates distinct, no stored drift, viewer overlay private; actual existing indexes selected and no speculative DDL justified |
| SEO/structured data/sitemap/search | already_compliant | review URLs/query state noindex/canonical; individual reviews absent from sitemap/search/schema and spoilers excluded |
| Multilingual/Livewire/a11y/mobile | completed | RU/EN 223/223 parity; locked scalar state, prepared DTO and existing accessible responsive UI preserved; RU desktop/EN mobile smoke passed |
| Account export/delete/merge/admin/API | already_compliant | owner export/anonymization, alias-preserving merge, gated queue and provider-only read API re-inspected without shape changes |
| Sentiment, season/episode reviews, emoji reactions, edit history, public directory | not_applicable | no current product/domain support; fake fields, routes and controls were intentionally not added |
| Production data application | unresolved | safe migration is implemented and rehearsed but remains pending until an operator provides verified backup and writer pause; working DB was not mutated |
| Automated tests / Vite asset build | not_applicable | user explicitly prohibited test creation/execution; no frontend asset changed, so Vite build would not verify this code/docs-only UI integration |
| Documentation/README/changelog | completed | canonical owners, audits, compatibility, rollout, plan, Russian changelog and visitor history updated; managed blocks refreshed |
| Git commit/push | unresolved | scoped local `main` commit created; configured HTTPS push attempted and rejected because this environment has no GitHub credential (`could not read Username`); remote and secrets were not changed |

## Final evidence summary

- Configured database: migration status inspected read-only; new repair remains `Pending`, no working rows were written.
- Disposable migration: fully in-memory SQLite, config isolation asserted, repair applied twice; malformed/merge/published fixture outcomes matched the documented invariant.
- Static checks: PHP syntax clean, focused PHPStan zero diagnostics, Pint passed, RU/EN 223/223 keys/placeholders, template/security/route duplicate/docs checks clean.
- Query evidence: public list, author activity, portal rating and vote totals select the documented existing indexes; author ordering has a bounded per-author temporary sort and does not justify another 1.7M-row write index.
- Browser: RU `1440×1200` and EN `390×844` direct links returned final 200, correct locale, stable initial anchor, clean canonical/noindex, no overflow or unlabeled visible controls. Five unrelated missing demo collection covers on mobile and six on desktop remain documented adjacent data debt.
- Preserved: review/provider/comment/rating/progress/library/profile/notification/report/restriction/cache/search/recommendation/API/SEO identities and test infrastructure; no dependency, queue, scheduler, cache flush or production migration.

## Final verification checklist

- Reread Task 13, requirements, this plan and every applicable review owner.
- Inspect all changed files and directly related unchanged routes/models/actions/services/DTOs/policies/queries/cache/SEO/notifications/account/merge/API files.
- Verify identity/targets/comments separation/title/body/rating/general rating/duplicates/verified watching/spoilers/edit/delete/restore/votes/reports/moderation/restrictions/notifications/anti-spam.
- Verify counts/aggregates/query plans/uniqueness/index usefulness, no N+1, bounded lists and no private shared state.
- Verify target merge/delete, account deletion/export, profile privacy, locale, search/recommendations, SEO/JSON-LD/sitemap and cross-feature independence.
- Verify Blade/JS/Livewire security and accessibility: no raw UGC/DOM sink/hidden spoiler source/Volt/`@php`/inline CSS/business JS/model query/dead control.
- Run only allowed diagnostics and safe browser smoke; preserve tests without invoking them.
- Update documentation/compliance honestly, including not-performed or externally blocked evidence, then commit and attempt configured push from clean `main`.
