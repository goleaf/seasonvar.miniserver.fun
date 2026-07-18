# Task 12 — повторный аудит и безопасное усиление комментариев и обсуждений

Обновлено: 18.07.2026

## Цель

Повторно проверить уже существующий canonical comments domain после последующих интеграций портала, устранить только доказанные дефекты безопасности, privacy, concurrency, cache, notifications, query/UI и compatibility и сохранить все комментарии, ответы, реакции, отчёты, ограничения, блокировки, уведомления, stable anchors и moderation evidence.

## Обязательные ограничения

- Работа ведётся только в существующей `main`; ветки, worktree и subagents не создаются.
- Вопросы пользователю не задаются; решения выводятся из repository contracts и текущих данных.
- Автоматизированные тесты не создаются и не запускаются. Разрешены static/runtime read-only inspection, route/schema/query/translation/security/browser smoke, Pint, Larastan, Blade compilation и Vite build.
- Existing tests можно корректировать только если подтверждённое поведение изменено, но test runner не запускается.
- Новые dependencies, queue/scheduler/Supervisor, публичные API, translated enum values, arbitrary morph types, rich HTML/Markdown и public-user identity не добавляются.
- Schema/data mutations допускаются только после доказанного legacy reconciliation, backup/rollback/production-impact review. До этого все проверки production-style SQLite остаются read-only.
- `CHANGELOG.md` остаётся на русском по каноническому `AGENTS.md`, несмотря на конфликтующее требование пользователя об английском changelog.
- `README.md` проверяется перед завершением и меняется только при visitor-visible результате.
- Финальный commit создаётся только в `main`; configured push выполняется, а отказ GitHub authentication фиксируется без изменения remote/secrets.

## Документационный и maintenance baseline

- Финальный tracked corpus: `284` Markdown-файла / `62 489` строк после Task 12 documentation changes. В Task 12 повторно прочитаны все tracked Markdown-файлы; отдельно повторно сверены канонический порядок требований, все comment/discussion owners и поздние integration/verification records.
- Обязательный порядок из `docs/requirements/index.md`, а также `maintenance-and-upgrades.md`, `production-operations.md` и `system-wide-integration.md` применён.
- Task не меняет package/runtime/framework/database engine/build tooling по намерению. Если аудит выявит необходимость schema/browser/cache/runtime изменения, compatibility, rollout, recovery и rollback дополняются до code edit.
- `docs/plans/laravel-video-portal-modernization.md` и `docs/audits/verification-report.md` подтверждают первоначальную полную реализацию Task 12 и post-Task-14 reconciliation. Новый competing spec/plan не создаётся.

## Выбранный дизайн

Рассмотрены три подхода:

1. **Additive hardening существующего canonical domain — выбран.** Один `Comment`, один allowlisted target resolver, один query/presenter/action/policy/notification/cache boundary; исправляются только воспроизводимые расхождения.
2. Полная перестройка comments/replies/reactions — отклонена: разрушает stable rows/routes/anchors и создаёт конкурирующую архитектуру.
3. Отдельные таблицы/компоненты для title/season/episode/replies — отклонены: текущий enum target и single-table bounded replies уже покрывают эти scopes без arbitrary polymorphism.

UI остаётся существующим светлым product interface: reuse panels/forms/buttons/avatar/pagination/focus styles, no new visual language, no hover-only actions, no body in hidden spoiler markup. Изменение Blade/Tailwind/JS допускается только при доказанном accessibility/mobile/security defect.

## Текущая canonical architecture, требующая повторной проверки

### Identity, targets и routes

- `comments.id` — единая стабильная database/public anchor identity; body, locale, author name, target slug, sort/page и edit state в identity не входят.
- `CommentTargetType` allowlists `title`, `season`, `episode`, `collection`; request не передаёт model class. `CommentTargetResolver` проверяет relation, publication/visibility/commentability и canonical URL.
- `comments.target_type + target_id` задают exact scope; nullable `catalog_title_id` связывает catalog-rooted discussions с canonical title invalidation/merge.
- Direct route `comments.show` и localized alias разрешают stable ID, policy-check exact comment до redirect и ведут к target route + `#comment-{id}`. Hidden/inaccessible targets fail closed; comments не входят в sitemap.
- Mutations выполняются только Livewire actions/CSRF transport; destructive GET routes отсутствуют.

### Replies, nesting и counts

- Одна таблица/модель хранит top-level comments и replies. `parent_id` у reply указывает на structural root; `reply_to_id` хранит logical author context.
- Structural depth равен одному уровню; logical reply-to-reply flatten-ится без recursive rendering. Self/cross-target/non-root/cycle relations запрещены server-side.
- Replies chronological и progressive; один bounded thread разворачивается по запросу. Top-level page paginated, stable sorts: `newest`, `oldest`, `popular`.
- Public count — все non-deleted published comments, включая replies; thread reply count — non-deleted published replies. Viewer block/mute overlays не меняют public aggregates.

### Body, spoilers, edits и deletion

- `CommentBody` — один Unicode plain-text normalizer/validator. HTML/Markdown/provider HTML/automatic links не интерпретируются; Blade output escaped, line breaks prepared safely.
- Whole-comment `is_spoiler` — stable boolean. Unrevealed body отсутствует из initial HTML/DTO/profile/notification/SEO и загружается только explicit authorized server action.
- Long body storage не обрезается; initial DTO содержит bounded excerpt, full body загружается explicit show-more action.
- Owner edit uses window + optimistic `version`, preserves target/author/parent/created time/replies/reactions and sets `edited_at` only for material change.
- Owner delete soft-deletes with stable reason; reply-bearing parent keeps neutral tombstone. Restore is bounded and cannot override moderator/privacy removal. Privacy/legal lifecycle is separate and evidence-preserving.

### Reactions, reports, moderation и restrictions

- `CommentReaction` stores one unique `up|down` per user/comment; desired-state action is idempotent, self-reaction forbidden, totals derived/grouped.
- `CommentReport` uses stable categories/statuses, one unresolved dedup key per reporter/comment/category, private reporter/detail/note and bounded moderation previews.
- Stable comment statuses: `published`, `pending`, `hidden`, `rejected`, `spam`, `removed`; translated labels are presentation-only.
- `CommentRestriction` stores comment-only temporary/permanent type, stable reason, optional expiry, private moderator note and actor. Expiry is evaluated synchronously; no cron required.
- Admin route/actions require `manage-comments`, reauthorize after hydration/transaction lock, write immutable audit inside the domain transaction and never expose owner-only relationship state.

### Blocks, mutes, notifications и profile/account lifecycle

- Directional block prevents bilateral reply/reaction/notification and hides bodies for both viewers without exposing the relationship. Directional mute hides only muted author body for muter and suppresses their notifications.
- Reply/reaction/moderation/report-resolution use `CommentActivityNotification` with stable body-free payload and deterministic deduplication. Preferences, self suppression, block/mute, target/comment visibility are checked at delivery and presentation.
- Private self activity uses `CommentProfileQuery`; Task 14 public profile rows/counts delegate to the same query and `CommentPresenter`, section privacy and spoiler omission.
- Account export includes only owner comments/reactions and public-safe targets; deletion anonymizes author linkage/submission key, removes private engagement/notifications and preserves thread/moderation integrity.

### Anti-spam, cache, SEO и optional capabilities

- `CommentAntiSpamService` owns normalized short-window duplicate detection and bounded content signals; `CommentRateLimiter` owns action-specific Laravel limits. Weak signals may produce `pending`, never permanent ban.
- Guest SSR contains public DTOs only; viewer reaction/permissions/block/mute/pending/moderation/notification state is request-specific. Comment data has no competing shared cache; material mutations use `CommentCacheInvalidator` after commit and existing target page domains.
- Comments/direct routes do not create sitemap or standalone indexable pages, structured data or metadata excerpts. Sort/page/direct state canonicalizes to target/noindex policy.
- Mentions are unsupported: `@text` remains plain text and emits no link/notification. Premium-only emoji/stickers/formatting are unsupported; discussion remains equal for eligible verified users.
- Imported provider reviews remain the separate `catalog_title_reviews` domain and are never converted into comments.

## Audit discoveries — update immediately

- Initial repository/history review confirms one canonical implementation introduced by `111033c`, hardened by `b8f4a71`, and later public-profile duplication removed by `b038399`.
- Existing verification predates Tasks 21–29 and the latest full-page cache origin hardening; all direct and indirect integrations must therefore be traced again rather than accepted from history alone.
- В начале повторного аудита старые результаты не принимались за доказательство: schema/data/routes/counts/anomalies были заново измерены до финальной оценки требований.
- Fresh production-style SQLite measurement found `3 707 221` comments and `9 265 514` reactions, zero invalid target/status/reaction values, orphan/cross-target/self/deep reply links, duplicate reactions/submission keys, overlapping active restrictions or orphan comment notifications. Existing unique/index architecture is therefore retained without a speculative migration.
- One `removed` demo comment (`comments.id=10`) has no `deleted_at`/`deletion_reason`. It is already excluded from public queries, but `DemoCommunityStage` can reproduce this structural mismatch because its deletion fixture does not include `CommentStatus::Removed`; writer source and the narrowly scoped deployed-state convergence path must both be corrected without deleting or rewriting comment content.
- Повторная инвентаризация Markdown после обновления плана подтвердила `284` tracked-файла и `62 489` строк; прежняя меньшая оценка была неполной и заменена фактическим repository inventory.
- `CommentProfileQuery::publicActivityQuery()` checks only visible `catalog_title_id`. A published season/episode comment could therefore expose an excerpt on a public profile after the exact season/episode becomes unpublished, region/premium unavailable or soft-deleted while its title remains public. Public profile reads/counts must reuse exact target availability and viewer mute semantics.
- The largest measured target currently has 139 comments (50 roots/89 replies), and the largest thread has 8 replies. The current incremental reply limit is nevertheless unbounded by code, so a hostile repeated Livewire action could eventually request an arbitrarily large thread; a server-owned ceiling/failure state is required even though current data is small per thread.
- Fresh runtime query tracing measured the 15-item public root page at about `1.83 ms`, but the corresponding 139-row public count at about `4 906 ms`: SQLite selected global `comments_moderation_queue_idx(status,...)` because `comments_target_list_idx` places `parent_id` between target identity and status. One additive `(target_type,target_id,status,deleted_at)` public-count index is justified; no other speculative index is planned.
- Fresh disposable SQLite applied the complete migration chain through `2026_07_18_193801`; the new index exists and `EXPLAIN QUERY PLAN` selects `comments_target_public_count_idx` as a covering exact-target lookup. The configured 26+ GiB database was not migrated or rewritten; production index creation remains a stopped-writer, verified-backup deployment step.
- The canonical reply loader now has a server-owned 200-row ceiling with a localized terminal state. Direct focus may add the one requested reply as context, so arbitrary repeated Livewire calls cannot serialize an unbounded thread while stable direct anchors remain usable.
- `DemoDataAuditor` now rejects moderator-removed demo rows without `deleted_at`, deleted rows without a stable reason, and cross-target/deep demo replies; canonical demo regeneration repairs the one known fixture mismatch idempotently rather than deleting user content ad hoc.
- A dedicated idempotent `2026_07_18_193800` convergence migration closes the already-deployed legacy mismatch without a broad rewrite: only missing deletion reason/actor/timestamp on stable `removed` rows are filled from existing moderation/update/create evidence. Disposable rehearsal repaired the malformed fixture while preserving body/identity and left the public-count index present; configured production-style data remains untouched until the stopped-writer rollout.
- Browser/direct-link smoke found two compatibility defects not visible in route inventory alone. Title discussion was always lazy, so the requested anchor did not exist to trigger viewport loading; `CatalogTitleDetail` now keeps the validated positive comment ID in locked state and disables lazy loading only for that direct request. The final query-state page is also explicitly `noindex,follow` with clean canonical. Separately, the localized route closure omitted its leading `locale` scalar, causing Laravel positional dispatch to validate `en|ru` as the comment ID and return 404; the existing URI/name/middleware/responder are preserved with the correct signature.
- Configured Git remote is already externally blocked in this environment: HTTPS lacks credentials, `gh` is absent, SSH key is unauthorized. A final push will still be retried after the new commit and documented honestly.

## Cross-feature impact matrix

| Domain | Impact / required verification |
| --- | --- |
| Home/search/catalog/alphabet/filters | no comment text/private overlays in shared cards/search; counts do not add N+1 |
| Title/season/episode/player | exact scope, canonical target visibility, lazy discussion, player/navigation unaffected |
| Progress/history/bookmarks/library/tags/collections | comment mutations do not change user/catalog state; collection target lifecycle remains integrated |
| Reviews/ratings/recommendations | separate review domain; published comment signal/invalidation remains bounded and private text excluded |
| Profiles/privacy | self/public activity delegates canonical query/presenter; spoiler/deleted/blocked/private evidence excluded |
| Authentication/session/account settings | verified owner, server actor, locale/preferences, export/delete/anonymization consistent |
| Notifications | body-free stable payload, preference/self/block/mute/dedup/read boundaries and no orphan race |
| Administration/audit | `manage-comments`, bounded queue/context/reports, atomic audit, private notes/reporter protected |
| Imports/title merge/target deletion | no provider comment creation; title merge and target retirement preserve threads/anchors/evidence |
| API | no competing mobile comments API; existing unrelated review/API shapes unchanged |
| Cache/search/SEO/sitemap | public/private separation, targeted invalidation, no comment indexing/schema/sitemap/spoiler metadata |
| Premium/region/legal | no fake Premium comment enhancement; target eligibility and privacy/legal retirement reused |
| Mobile/a11y | focus, spoilers, long text, flattened replies, dialogs, 320/390px/zoom, reduced motion, live regions |
| Production/rollback | no runtime/schema mutation without backup/compatibility; cache/build failure recovery documented |

## Phased implementation checklist

### Phase A — documentation, routes, schema and data

- [x] Confirm all Markdown files remain readable and every comment owner/legacy plan/late integration has been reread.
- [x] Inventory all web/localized/admin/profile/notification routes, names, middleware, bindings and mutation methods.
- [x] Inventory comment/reaction/report/restriction/block/mute/notification tables, migrations, FKs, indexes, enum values and actual row counts.
- [x] Run bounded read-only anomaly checks: orphan/cross-target/cycle/depth, duplicate reaction/report/submission, invalid status/type, expired restriction, privacy/notification orphan.
- [x] Inspect exact query plans for public page, reply page, viewer overlay, moderation queue, profile activity and notification inbox.

### Phase B — domain/security/privacy/concurrency

- [x] Inspect target resolver/locks/merge/retirement for title/season/episode/collection visibility and deletion races.
- [x] Inspect body normalization/XSS/link policy, spoiler/long-body source omission and direct-link authorization.
- [x] Inspect create/reply/edit/delete/restore idempotency, owner/version predicates, parent/root invariants and count semantics.
- [x] Inspect reaction/report TOCTOU, self/block rules, uniqueness and grouped aggregates.
- [x] Inspect moderation/report/restriction atomic audit, private evidence, expiry, bounded work and safe errors.
- [x] Inspect block/mute/query/presenter/profile/account export/delete for private-state leakage and per-row queries.
- [x] Inspect notification delivery/presentation/preferences/dedup/orphan concurrency and body/spoiler exclusion.
- [x] Inspect anti-spam/rate-limit/duplicate/link/repetition signals for server authority and false-positive bounds.

### Phase C — cache/performance/UI/integration

- [x] Inspect guest full-page cache, Livewire lazy island, target invalidation, search/recommendation/SEO/sitemap dependencies.
- [x] Inspect selected columns/eager loads/grouped overlays/pagination/reply bounds and index usefulness; add no speculative index.
- [x] Inspect Blade/JS/Livewire for raw body, DOM sinks, model calls, full graph state, stale actions, hardcoded labels, Volt, `@php`, inline CSS/JS, TODO/debug/dead controls.
- [x] Inspect RU/EN key/placeholder/enum parity and locale preservation without translating UGC.
- [x] Browser smoke canonical title/direct/localized/private/admin availability, desktop/mobile/zoom/focus/spoiler/long-body/console/network where safe fixtures exist. Authenticated write/admin paths were re-inspected statically and retained prior controlled evidence rather than mutating production-style data.
- [x] Implement only proven defects, update this section immediately for each discovery and repeat focused inspection.

### Phase D — completion and delivery

- [x] Reread Task 12 and applicable requirements; convert final compliance only with fresh evidence.
- [x] Update canonical Markdown owners, verification report, maintenance log, `CHANGELOG.md`, README visitor history if behavior changed.
- [x] Run allowed Pint/PHP syntax/Larastan/Blade/docs/translation/static security/Vite/browser checks; no test runner.
- [x] Repository-wide legacy/duplicate/dead/private-cache/spoiler-source scan and full changed/directly-related file review.
- [x] Commit intentional tracked changes on clean `main`; configured HTTPS push retried without invoking the prohibited test hook and failed only because this environment has no GitHub credentials.

## Database, migration and production review

- Existing migrations `2026_07_15_210000` through `210300` and focused `235200` are deployed history and must not be edited.
- New uniqueness/index/schema is permitted only after current data reconciliation and `EXPLAIN` evidence. A missing index alone is not proof if bounded query already uses a covering prefix.
- No data is deleted by audit. Permanent privacy/legal actions remain explicit services, not migration cleanup.
- Potential schema change classification: `safe_additive` or `compatibility` only unless an unavoidable conflict is documented; backup/writer-stop/rollback required before production rollout.
- Cache changes use versioned dimensions/target invalidation, never application-wide flush.
- Build change rollback retains previous manifest/assets until code and assets switch together; no service worker is installed.

## Files expected to change

Only evidence may determine the exact set. Likely owners if defects are found:

- `app/Actions/Comments/*`, `app/Services/Comments/*`, `app/Livewire/Comments/*`, `app/Models/Comment*.php`, `app/Policies/CommentPolicy.php`;
- `app/DTOs/Comments/*`, `app/Enums/Comment*.php`, `app/ValueObjects/Comment*.php`, `app/Notifications/CommentActivityNotification.php`;
- direct target/profile/account/notification/cache/merge integration boundaries;
- `resources/views/livewire/comments/*`, `resources/views/components/comments/*`, `resources/js/comments.js`, `lang/{ru,en}/comments.php`;
- canonical comment sections in architecture/data/authorization/security/validation/forms/views/frontend/caching/performance/notifications/administration/API/deployment/model/UI docs;
- `docs/plans/current-task-plan.md`, `docs/audits/verification-report.md`, `docs/MAINTENANCE_LOG.md`, `CHANGELOG.md`, and `README.md` only for visitor-visible change.

## Actual change set

- Canonical read/UI boundaries: `CommentProfileQuery`, `CommentDiscussion`, `CatalogTitleDetail`, the title/discussion/comment-item Blade views, `config/comments.php` and RU/EN comment catalogs.
- Compatibility/data boundaries: localized comment route scalar signature, `DemoCommunityStage`, `DemoDataAuditor`, idempotent removed-state convergence migration `193800` and reversible public-count index migration `193801`.
- Canonical documentation owners: `architecture.md`, `DATA_RELATIONS.md`, `authorization.md`, `security.md`, `caching.md`, `performance.md`, `views.md`, `deployment.md`, this plan, verification report, maintenance log, Russian changelog and visitor README history.
- No action/reaction/report/notification/policy/model/API/cache-key/dependency/lock-file/test file required modification; their existing canonical contracts were inspected and preserved.

## Files/contracts that must remain unchanged unless a complete dependency migration is proven

- Public/localized comment route names, stable numeric IDs, `#comment-{id}` anchors, target enum values and all persisted rows/timestamps/status/evidence.
- `catalog_title_reviews`, review routes/API/ratings, user auth/session/profile IDs, catalog title/season/episode/collection IDs and slugs.
- Player/progress/history/watchlist/library/tags/collections/recommendations/import command and media delivery.
- Existing cache domains, notification type names/preferences, report/restriction/reaction enum codes and admin gate names.
- Composer/npm dependencies and lock files, `.env`, production services and existing test infrastructure.

## Rollback and failure recovery

- Code-only hardening can be reverted as one Task 12 commit; stable rows/schema/routes remain readable.
- Any additive schema change requires verified backup, disposable down/up rehearsal and forward-fix plan before writers resume.
- Failed mutation rolls back database/audit atomically; notification/cache side effects occur only after commit and may retry idempotently.
- Cache outage falls back to authoritative queries or fail-closed permissions; no permission is granted from stale cache.
- Notification failure does not undo committed comment content and must not create duplicate/orphan payload on retry.
- Partial asset build keeps previous compatible manifest/assets active; no broad deletion or `optimize:clear` is used as recovery.

## Compliance matrix — final evidence state

| Requirement group | Status | Evidence / unresolved work |
| --- | --- | --- |
| One canonical model/stable identity/targets | already_compliant | one `Comment`, stable numeric ID/anchor, enum/resolver allowlist and no competing reply/API table confirmed |
| Title/season/episode/collection scopes | completed | exact target visibility traced; public-profile season/episode audience/region/premium gap corrected |
| Replies/depth/cycles/tombstones | completed | root+logical reply structure/data scan valid; 200-row progressive ceiling added; removed-state convergence added |
| Body/Unicode/XSS/link validation | already_compliant | canonical plain-text normalizer, escaped renderer, dangerous-scheme/link/line/repetition bounds and empty/error paths rechecked |
| Create/reply/edit/delete/restore | already_compliant | policy/action locks, stable author/target/version, idempotency, tombstone/restore window and controls rechecked |
| Spoiler/long-body protection | already_compliant | unrevealed full text absent from DTO/DOM/profile/notification/SEO; server reveal/collapse and a11y flow retained |
| Reactions/up-down/self policy | already_compliant | unique pair, desired-state idempotency, self/block/deletion enforcement and grouped totals confirmed |
| Mentions | not_applicable | product deliberately has no mention parser/link/notification; `@text` stays plain |
| Premium enhancements | not_applicable | no trusted emoji/sticker/formatting capability; basic discussion equal for eligible users |
| Reports/moderation/restrictions/audit | already_compliant | stable enums, private evidence, atomic fingerprint, bounded report/context work and request-time expiry confirmed |
| Blocks/mutes | completed | private bilateral block/directional mute semantics retained; public-profile viewer mute omission corrected without public count leakage |
| Notifications/preferences/dedup | already_compliant | body-free stable payload, preferences/self/relationship filters, deterministic UUID and recipient lock rechecked |
| Anti-spam/rate/duplicate prevention | already_compliant | exact+global server buckets, UUID key, 90-second normalized duplicate window and bounded weak-signal pending retained |
| Counts/pagination/progressive loading | completed | 15-root paginator, chronological replies, 200 ceiling and authoritative count index/rehearsal complete |
| Profile/account export/deletion | completed | exact target/relationship public projection corrected; spoiler-safe presenter/anonymization/export cleanup retained |
| Cache/private overlays/invalidation | already_compliant | shared target versions remain separate from viewer state; profile projection is request-private and no new cache introduced |
| SEO/direct links/sitemap | completed | focused initial anchor, localized positional route, clean canonical/noindex fixed; hidden policy and sitemap absence confirmed |
| Query performance/indexes/N+1 | completed | grouped/eager overlays and bounded page traced; one measured covering public-count index added, no speculative indexes |
| Multilingual/Livewire/a11y/mobile | completed | 235/235 RU/EN keys, locked scalar state, labelled terminal state, desktop/mobile no-overflow/focus smoke complete |
| Administration | already_compliant | route and repeated gate/policy, bounded queue/context/reports, private notes/reporter and safe failure panel rechecked |
| Documentation/README/changelog | completed | canonical owners, deployment, verification, maintenance, visitor history and Russian changelog updated |
| Git commit/push | unresolved | Task 12 commit created on clean `main`; configured `origin/main` push returned `could not read Username for 'https://github.com'`, so external publication remains blocked without changing remote or secrets |

## Final verification checklist

- Reread this task, project requirements, this plan and every applicable comment owner.
- Inspect all changed files and directly related unchanged routes/models/actions/services/DTOs/policies/queries/cache/notifications/account/merge files.
- Verify stable identity/targets/replies/depth/body/spoiler/edit/delete/restore/reactions/reports/moderation/restrictions/blocks/mutes/notifications/anti-spam/counts/direct links.
- Verify query plans, uniqueness/index usefulness, no N+1, bounded reply/moderation/report/notification work and no private shared state.
- Verify target merge/delete, account deletion/export, profile privacy, locale, SEO/sitemap, cache invalidation and cross-feature independence.
- Verify Blade/JS/Livewire security and accessibility: no raw body/DOM sink/hidden spoiler source/Volt/`@php`/inline CSS/business JS/model query/dead control.
- Run only allowed diagnostics and direct browser smoke; preserve test infrastructure without invoking automated tests.
- Update documentation/compliance honestly, including blocked/not-performed evidence, then commit and attempt configured push from clean `main`.
