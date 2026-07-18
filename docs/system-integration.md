# Системная интеграция портала

Обновлено: 19.07.2026

Этот документ — единый владелец финальной cross-feature dependency matrix и production-readiness evidence. Постоянные правила находятся в [`requirements/system-wide-integration.md`](requirements/system-wide-integration.md), а детальные domain contracts — в [`README.md`](README.md). Статусы здесь означают только подтверждённое состояние repository; отсутствующие capabilities не изображаются реализованными.

## 1. Architecture overview

Portal использует Laravel 13.20.0, class-based Livewire 4.3.3, Eloquent, server-side policies/gates, typed actions/services/queries/DTO и Vite/Tailwind frontend. HTML pages принадлежат full-page Livewire components, JSON — API Resources/responders, streamed/signed/file endpoints — узким responders. SQLite является canonical database в текущем verified runtime; Redis сконфигурирован для cache/session/queue/locks, Memcached optional hot tier сейчас недоступен. Наличие Redis worker/cron не выводится только из конфигурации: production runbooks отдельно требуют проверять процессы.

## 2. Feature map

Каноническая карта 26 areas находится в [`requirements/index.md`](requirements/index.md#канонические-feature-areas-126). Реально существующие portal domains включают catalog/search/filter/title/season/episode/player/progress/library/collections/tags/comments/reviews/profiles/auth/settings/calendar/recommendations/requests/issues/help/premium boundary/mobile web/admin. Rights-holder case и advertiser campaign systems не установлены и остаются `not_applicable`, а не fake implementation.

## 3. Shared services

Shared services владеют entitlement/playback (`CatalogEntitlementService`), progress и canonical user overlay, library transitions, cache invalidation, search, recommendations, notifications, audit, imports и account lifecycle. `AccountService` теперь одинаково обновляет identity-dependent profile/search/collection/comment/review projections для Livewire и API. Feature components не создают parallel resolver.

Authentication остаётся одной Laravel-native границей: `web` guard/Eloquent provider/Password Broker/signed verification для browser и Sanctum abilities для mobile. Verified guest-progress migration не создаёт новый user state service: existing settings responder передаёт bounded snapshot в `CatalogUserStateService`, который batch-разрешает canonical episode/title identity, сохраняет existing account row precedence и non-completion provenance `anonymous`.

Review integration использует один `CatalogTitleReviewQuery`/`ReviewPresenter` для title, own history, public profile и notification destinations. Profile list/count передают только relevant author в общий block/mute service, а localized/unlocalized direct routes делегируют одному responder; следовательно locale, profile privacy и notification presentation не создают параллельный review visibility или URL resolver.

## 4. Identity model

`User`, `CatalogTitle`, `Season`, `Episode`, `LicensedMedia` и stable UUID/code columns являются identity. Translated text, display title, slug, provider URL и episode number используются только как presentation/routing/provider context. Контентные merge выполняются application services до destructive identity removal; полного merge двух user accounts в продукте нет.

## 5. Visibility model

Public visibility складывается server-side из publication, audience, time window и source availability через canonical entitlement/query boundaries. Premium entitlement не отменяет эти ограничения. Отдельные user-region, title legal-restriction, advertiser и rights-holder resolvers/schema в текущем продукте отсутствуют; поэтому audit не заявляет их применение и не создаёт client-side substitutes.

## 6. Access-context model

Authentication, user identity, locale/timezone, verified state, entitlements и resource authorization разрешаются на server. Полный access graph не сериализуется в Livewire/Blade/API; presentation получает минимальные prepared flags/DTO.

## 7. Notification model

Notification category codes стабильны: `comment.activity`, `review.activity`, `content-request.activity`, `technical-issue.activity`, `release-calendar.activity`, `premium.activity`. User preferences и locale применяются server-side, deterministic IDs подавляют duplicate delivery, body/private notes/secrets не попадают в payload. Account export теперь включает только allowlisted fields этих типов и соответствующие stored preferences; неизвестный будущий payload автоматически не экспортируется. Registry owner — [`notifications.md`](notifications.md).

## 8. Audit model

Administrative/catalog moderation использует `AdminAuditRecorder`/`admin_audit_events` как append-only safe fingerprint boundary. Исторические user/domain events остаются в своих canonical histories; parallel generic audit tables не создаются. Raw values, secrets и private notes исключены. Так как `actor_id` имеет `RESTRICT`, account deletion административного actor теперь останавливается до mutation с локализованным retention-сообщением, а не падает на SQL constraint и не стирает audit history.

## 9. Storage model

Public assets, private uploads, ticket screenshots, exports и generated artifacts используют configured disks. Private legal/advertiser/invoice classes не заявляются существующими без domain evidence; при будущем добавлении они обязаны иметь separate authorized storage/download boundary. Inventory owner — [`storage.md`](storage.md).

## 10. Cache model

Public snapshots/facets/stats/search/recommendations и private overlays разделены. Keys/invalidation принадлежат `App\Support\Cache` и cache-aware services, user-specific payload не входит в shared cache. Гостевой HTML включает hash текущего Vite manifest в key dimensions: новый asset release не читает HTML со ссылками на прежние hashes и не требует global flush. Service worker отсутствует, поэтому browser private cache state — `not_installed`.

## 11. Search model

Public catalog/portal/help/profile/collection search использует отдельные scope/query boundaries и исключает private resources. Staff queues выполняют permission-scoped database search и не индексируются публично. Locale, visibility и deterministic pagination входят в query contract. Profile deletion и name change bump-ят search-suggestion version после commit, поэтому удалённое или переименованное публичное имя не остаётся в прежнем cache generation.

## 12. SEO model

Canonical/localized URLs, `hreflang`, robots, structured data и sitemap включают только public resources. Auth/account/admin/ticket/payment-return/signed endpoints не индексируются; legal/advertiser/service-worker routes отсутствуют. Проверено 246 registered routes: 66 под `/api`, 13 под `/admin` и 167 остальных web/framework entries; 41 route входит в localized boundary, legacy aliases сохранены. Duplicate method/URI и duplicate names не обнаружены, destructive GET mutation не зарегистрирована.

## 13. Account merge flow

Полный account-to-account merge намеренно не поддерживается: Social/OAuth provider identity и proof-of-control workflow отсутствуют, а совпадение email запрещено считать authority. `ContentRequestAccountService::mergeUsers()` и `TechnicalIssueAccountService::mergeUsers()` — domain migration hooks для безопасного переноса заявок/тикетов при отдельно подтверждённой будущей операции; они не являются публичным account merge coordinator и не покрывают progress, library, premium или sessions. Anonymous playback/settings migration остаётся отдельным idempotent guest-to-authenticated flow по owner-scoped keys: только verified user, не более 50 recent positions, visible/watchable targets, no trusted completion, existing account progress always wins, accepted-snapshot cleanup и optional failure без отмены login. До появления явного proof-of-control и полного conflict matrix user accounts не объединяются.

## 14. Account deletion flow

`AccountService` является canonical deletion coordinator: проверяет пароль/rate limit и premium lifecycle, отзывает sessions/tokens/reset state, удаляет owner-private state/collections/notifications, а community/request/ticket/help/payment histories сохраняет либо обезличивает по FK/domain policy. Profile media удаляется after commit; profile summary и search suggestion generations инвалидируются. Immutable admin audit блокирует hard deletion до отдельного retention решения. Legal/advertiser histories не описываются как очищенные, потому что таких domains нет.

## 15. Import synchronization flow

`seasonvar:import` remains the only public Seasonvar import command. Import preserves stable title hierarchy and locked editorial ownership; user library/progress rows reference canonical content and are not recreated importer-ом. After-commit publishers/invalidation update search/recommendations/cache without deleting absent historical user data. Полный remote provider parity не заявляется без успешного current inventory run.

## 16. Administration integration

13 administration routes используют `auth`, `auth.session`, `account.private` и explicit capability gates; mutations повторно делегируют authorization/policies domain services. Панель показывает только real content/import/moderation/request/issue/help/calendar/premium-boundary state; unrestricted shell/Artisan/SQL/env/file/dependency controls и fake advertiser/legal/operations dashboards отсутствуют.

## 17. Mobile and PWA behavior

Mobile web использует те же canonical URLs/backend, responsive Tailwind UI, capability detection и bounded lifecycle modules. Mobile JSON API существует для public/account/state/playback flows и использует Sanctum abilities для private writes. Browser manifest/service worker/install/push/offline-download отсутствуют; следовательно, private-route cache exclusion проверен как `not_installed`, а не как якобы работающий PWA.

## 18. Security boundaries

Authorization is server-side; inputs/URLs/uploads are allowlisted/validated; playback/downloads use signed and reauthorized boundaries; private routes use no-store/noindex; audit/logs exclude secrets. Livewire serves its installed CSP-safe bundle, so browser expressions do not require weakening `script-src` with `unsafe-eval`. Final audit evidence is recorded in current plan and security audit without overstating unexecuted credential/provider flows.

## 19. Privacy boundaries

Exact progress/history/library/markers/settings/tickets and internal notes are owner/private or permission-scoped. Public profiles/collections expose only policy-approved fields. Advertisers never receive portal-user data because an advertiser domain is not installed; this statement is a boundary, not an implemented reporting promise.

## 20. Known limitations

- No verified current backup/restore rehearsal, atomic deployment, failover, external monitoring or alert transport.
- Memcached service unavailable; Redis/database fallbacks preserve documented correctness.
- No browser service worker/PWA install/push/offline-download implementation.
- No active payment/OAuth provider, advertiser platform or rights-holder case domain.
- No full user-account merge workflow; only domain-specific migration hooks and anonymous state reconciliation exist.
- Legacy `/stats` и `/admin/catalog` остаются русскоязычными operational screens; их большая строковая поверхность не была механически переписана без отдельной UI contract migration. Public 403, episode link и viewing-activity fallback strings в Task 27 переведены через `ru`/`en` catalog.
- Credential-dependent authenticated/premium/provider/manual journeys may remain not performed and must be reported honestly.
- In-place production deployment всё равно не является atomic/zero-downtime: manifest fingerprint закрывает stale server HTML cache, но не заменяет согласованную публикацию и retention asset files.

## 21. Legacy compatibility adapters

Legacy route aliases, bookmark/favorite/watchlist mappings, user state/status mappings, importer source identity, cache/local-storage keys and merged-resource redirects are inventoried in [`maintenance/compatibility-adapters.md`](maintenance/compatibility-adapters.md). Adapter deletion requires verified zero dependants and a rollback-safe migration.

## 22. Future maintenance rules

Every future change follows [`requirements/index.md`](requirements/index.md), updates affected owners/current plan/compliance matrix, verifies cross-feature impact, preserves stable identities/routes/codes/data, records limitations, updates Russian `CHANGELOG.md`, commits only to `main` and attempts the configured push. Installation success, HTTP 200 or an empty search result alone never prove full compatibility.
