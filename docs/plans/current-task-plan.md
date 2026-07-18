# Task 27 — финальная системная интеграция и release preparation

Дата: 18.07.2026 (`Europe/Vilnius`)

## 1. Task title

Repository-wide requirement compliance, cross-feature reconciliation, stabilization, cleanup и release preparation существующего Seasonvar portal.

## 2. Task date

18.07.2026.

## 3. Current branch

Только существующая `main`; branch/worktree/PR branch не создавались.

## 4. Git status

Старт: clean `main...origin/main [ahead 13]`, `HEAD 81cb006`. Task 27 changes остаются в одном рабочем дереве; финальный status повторяется перед commit/push. Независимых user edits в начале не было.

## 5. Canonical requirement files read

Полностью прочитаны `AGENTS.md`, `docs/requirements/index.md`, `docs/CODE_STANDARDS.md`, `docs/architecture.md`, `docs/development.md`, multilingual, security, performance/cache, UI/accessibility, administration, production operations, maintenance/upgrades, current plan, README/CHANGELOG и owner map `docs/README.md`. Project-owned Markdown corpus проверен на ссылки/contradictions/legacy requirements; 436 Markdown references разрешались без missing target.

## 6. Requirement files updated

Обновлены `AGENTS.md`, requirement index, code/architecture/workflow, multilingual, security/privacy, performance/cache, UI/accessibility и administration owners. Добавлен единственный canonical `docs/requirements/system-wide-integration.md`; duplicate owner не создавался.

## 7. Feature documentation files read

Прочитаны owner documents для catalog/search/player/import/API/auth/storage/notifications/collections/comments/reviews/profiles/settings/calendar/recommendations/requests/issues/help/premium/mobile/administration/operations/maintenance и living modernization plan. Historical plans считаются evidence, но не получают precedence над current owners.

## 8. Installed Laravel version

`laravel/framework 13.20.0`.

## 9. Installed Livewire version

`livewire/livewire 4.3.3`; class-based components, Volt отсутствует.

## 10. Installed Tailwind version

`tailwindcss 4.3.2`, `@tailwindcss/vite 4.3.2`.

## 11. Installed Flux packages

Flux/Flux Pro отсутствуют и не устанавливались.

## 12. Relevant Composer packages

Laravel 13.20.0, Livewire 4.3.3, Boost 2.4.13, Pint 1.29.3 и существующие first-party/runtime packages. Полный registry остаётся в `docs/maintenance/dependency-inventory.md`; constraints/lock не менялись.

## 13. Relevant npm packages

Vite 8.1.4, Laravel Vite plugin 3.1.3, Tailwind 4.3.2, Plyr 3.8.4, HLS.js 1.6.16 и local FontAwesome. Patch/minor updates были только обнаружены, но не применены без отдельной причины/upgrade review; package/lock files не менялись.

## 14. Current application architecture summary

Laravel full-page class-based Livewire для HTML, API Resources/controllers/responders для JSON, thin signed/file/health routes, Eloquent/services/actions/queries/DTO, Vite/Tailwind. Verified runtime использует SQLite; Redis сконфигурирован для cache/session/queue/locks, Memcached optional и недоступен. Наличие worker/cron не выводится из config. 122 models, 425 services, 61 actions, 72 Livewire components, 34 Form Requests, 34 Resources, 14 policies, 124 enums, 11 jobs и 101 applied migrations.

## 15. All discovered public routes

121 public/framework routes: home; catalog/taxonomy/directory/top/discovery/search/stats; title/season/episode shell and signed playback; public collection/tag/profile/comment/review/request/help/calendar/premium-unavailable pages; auth entry/recovery/verification callbacks; feed/OpenSearch/LLM/health/SEO sitemap documents; Livewire assets/update boundary; localized equivalents; retained redirects. Source files `routes/web.php` и generated `route:list --json` остаются exact inventory owners.

## 16. All discovered private routes

44 authenticated web routes: library/history/activity, personal tags, own collections, notifications/discussions/reviews, settings/profile/security/export, calendar subscriptions, content requests, technical tickets/attachments, password confirmation, premium return и media download. Все имеют authenticated owner boundary; private account pages use `PrivateAccountResponse` except intentionally narrower signed/export callbacks with their own controls.

## 17. All discovered administration routes

13 routes: calendar, catalog, collections, comments, help + help preview, imports, issues, premium, profiles, requests, reviews и tags. Каждая использует `auth`, `auth.session`, `account.private` и explicit `can:*` gate.

## 18. All discovered API routes

67 API routes: discovery/OpenAPI/catalog; mobile auth/devices; config/health/home; public catalog/directories/filter/search/tag/collection/recommendation/review/season/episode sync; authenticated account/profile/password/delete; watchlist/rating/tag/library/history/progress/offline sync; signed playback. Private writes combine Sanctum ability, owner resolution, validation and domain policy; API errors remain JSON-safe.

## 19. All discovered middleware

Global groups add `AddSecurityHeaders`, `ApplyAccountPreferences`, `SetApiLocale`, `AssignApiRequestId`. Project aliases: `auth.optional.sanctum`, `public.cache`, `public.page`, `canonical.tag`, `collection.locale`, `collection.response`, `account.private`, `verified.api`, plus Sanctum `ability/abilities`. Host trust derives only from configured `APP_URL`; CSRF exception is limited to billing webhook, whose adapter performs provider signature validation.

## 20. All discovered guards

Laravel `web` session guard and Sanctum personal-access-token API boundary. Mobile abilities are `mobile:read`/`mobile:write`; API token never grants administration.

## 21. All discovered roles

Normalized general RBAC/organization roles do not exist. Administration is a configured catalog-administrator email allowlist; premium grant/promotion/billing-audit/reconciliation capabilities require both that boundary and separate capability allowlists. Advertiser/rightsholder roles are `not_applicable`.

## 22. All discovered permissions

Gates: `manage-seasonvar-imports`, `manage-catalog`, `manage-comments`, `manage-reviews`, `manage-content-requests`, `manage-technical-issues`, `manage-release-calendar`, `manage-help-center`, `view-premium-administration`, `manage-premium-grants`, `manage-premium-promotions`, `view-premium-billing-audit`, `reconcile-premium`, `view-account-settings`, `update-account-settings`. Resource permissions принадлежат 14 policies: account settings, title/media/progress/marker, collection/tag/user tag, comment/review/profile, request/issue/help.

## 23. All discovered feature modules

Areas 1–23 и 26 существуют в степени, указанной domain owners. Premium имеет entitlement/checkout/provider adapter boundary, но active provider отсутствует. Area 24 rights-holder cases и area 25 advertisers не имеют routes/schema/services и отмечены `not_applicable`; fake implementation не добавлялась.

## 24. Discovered duplicate implementations

Duplicate route method/URI: 0; duplicate route names: 0; duplicate PHP translation keys: 0; duplicate requirement owner для integration до Task 27 отсутствовал. Parallel user identity, entitlement, progress, personal-library, notification или audit architecture не обнаружена. Domain-specific moderation histories и `AdminAuditRecorder` имеют разные обязанности и не объединяются механически.

## 25. Discovered incomplete integrations

- API name update не bump-ил versioned public-profile/search projection, тогда как Livewire делал это отдельно.
- Account export не включал stored notification preferences и current database-notification read/history state.
- Generic morph notifications не имели FK cleanup в canonical hard deletion.
- `admin_audit_events.actor_id RESTRICT` мог превращать deletion административного actor в raw constraint failure.
- Profile hard deletion не очищал current summary key/search suggestion generation.
- Public 403/episode link/viewing history содержали hardcoded fallback text.
- После production Vite build реальный Firefox обнаружил старое кешированное HTML со ссылками на удалённые hashed CSS/JS; full-response key не учитывал manifest generation.
- Каталог показал report-only CSP violation от обычного Livewire bundle: установленный пакет уже содержал официальный CSP-safe build, но canonical config не включал его.
- Полного account merge workflow нет; узкие request/ticket `mergeUsers()` hooks нельзя считать coordinator.

## 26. Discovered dead controls

Textual/route/Livewire inspection не обнаружил fake advertiser/legal/PWA/payment-provider controls. Absent capabilities представлены unavailable state либо отсутствуют. Credential-dependent browser mutations ещё не считаются runtime-verified.

## 27. Discovered hardcoded strings

Public 403, episode link и viewing-activity fallbacks исправлены через существующие `ru`/`en` catalogs. Legacy `/stats` и `/admin/catalog` содержат большую русскоязычную operational string surface; она документирована как retained limitation и не была рискованно переписана в final stabilization task. Internal diagnostics/provider terms и linguistic search dictionaries не классифицируются как interface labels.

## 28. `@php` usage

Ноль Blade `@php`/`@endphp`.

## 29. Direct Blade class calls

Ноль прямых model/service/facade/database/cache/container queries в Blade по repository scan. Blade получает prepared values/components.

## 30. Inline CSS

Ноль Blade `<style>` и business `style=` attributes.

## 31. Large inline JavaScript

Inline business scripts отсутствуют; единственный inline JSON-LD boundary является prepared escaped SEO data. Application JS находится в Vite modules.

## 32. N+1 risks

Cards/queues/library/recommendation/profile builders используют eager/grouped/aggregate queries. Textual query inspection не обнаружил query from Blade или one-query-per-card path в changed scope. Полный query-profiler browser capture не выполнялся, поэтому абсолютное утверждение о нуле N+1 во всех 425 services не делается.

## 33. Stale cache risks

Подтверждены и исправлены два gap: canonical profile name/deletion invalidируют versioned summary и `SearchSuggestions`, а guest full-response dimensions теперь включают `Vite::manifestHash()`. Firefox после нового build получил только текущие CSS/JS с HTTP 200 и нулём console errors; store-wide cache flush не применялся. Service worker отсутствует.

## 34. Security risks

Исправлены audit-retention deletion failure, orphan morph notifications и allowlisted notification export. Route/CSRF/IDOR/owner/policy/signed playback/upload/storage/webhook/static secret scans подтверждают existing boundaries. Livewire `csp_safe` включён через package-supported config вместо добавления `unsafe-eval`. No advisory обнаружен project tooling. Credential/provider penetration journeys и production restore не выполнялись.

## 35. Privacy risks

Export раньше пропускал notification preference/history state; теперь включает только owner records и type-specific safe fields. Unknown future payload fail-closed не экспортируется. Account deletion очищает generic owner notification rows, сохраняет immutable admin audit через explicit retention block. Rights-holder/advertiser privacy flows `not_applicable`.

## 36. Multilingual risks

Actual locales: `ru`, `en`; по 20 PHP catalogs и 4,144 leaf keys. Финальный check: 0 missing keys, 0 unique-placeholder mismatch, 0 duplicate literal keys; новые auth/settings keys добавлены в обе локали. Large legacy admin/stats Russian-only surface остаётся documented limitation. Localized route group содержит 41 route, включая localized home.

## 37. Mobile risks

Mobile web и JSON API используют тот же backend/identities. No duplicate mobile URL backend, manifest/service-worker/install/push/offline claim. Safe-area/player/navigation modules inspected statically; real-device run unavailable.

## 38. Accessibility risks

Changed public fallbacks сохраняют semantic headings/links/alt text через translations. Existing focus/loading/reduced-motion/touch-target contracts inspected in shared UI. Screen-reader/virtual-keyboard real-device verification unavailable; legacy operational localization debt может создавать long-label risk после будущего translation migration.

## 39. SEO risks

Canonical/localized/robots/sitemap responders inspected; private/auth/admin/ticket/payment-return/signed routes не добавлялись в sitemap. No Task 27 route or SEO schema changed. Service-worker/legal/advertiser routes absent.

## 40. Migration risks

Task 27 migration не добавлялась. 101 migrations имеют applied status; schema metadata: 151 tables, 469 indexes. User-FK inventory found one `RESTRICT` audit edge and otherwise documented cascade/set-null edges. Production-like SQLite is about 27 GB, поэтому unbounded duplicate/integrity full-table scans были остановлены и не заявляются выполненными.

## 41. Backward-compatibility risks

245 route contracts, legacy aliases, DB identity/status codes, cache prefixes, API fields, lock files и migrations сохранены. 15 legacy route aliases остаются intentional adapters. Full account merge остаётся unsupported, а domain merge hooks retained. No destructive normalization or dependency upgrade.

## 42. Cross-feature dependency map

Canonical map находится в `docs/system-integration.md`: user/content identity; entitlement/visibility/access; notifications/audit; storage/cache/search/SEO; account merge limitation/deletion; imports; administration; mobile; security/privacy. Account mutation теперь обновляет profile/search/collection/comment/review projections централизованно.

## 43. Implementation phases

1. Requirement/index/reference normalization.
2. Route/schema/class/translation/cache/security/frontend inventory.
3. Minimal account/profile/export/i18n correctness corrections.
4. Cross-feature documentation/compliance reconciliation.
5. Static/build/browser verification без automated tests.
6. Final reread, README/CHANGELOG, commit и configured push attempt.

## 44. Files expected to change

Canonical requirements/architecture/security/cache/authorization/notification/integration/deployment docs; current plan; account export/deletion/profile cache services; public page cache policy; Livewire CSP config/profile orchestration; four translation catalogs; three public Blade fallbacks; README/CHANGELOG/maintenance review. No route, migration, dependency or frontend source change was required.

## 45. Files expected to remain compatible

All public/localized/private/admin/API routes, tables/migrations/data, progress/library/history/collections/comments/reviews/calendar/recommendations/import/premium state, cache key formats, API response contracts, lock files and test infrastructure.

## 46. Validation strategy

Allowed checks only: static PHP/Blade/JS/config scans, PHP syntax/Pint/PHPStan, route/middleware/schema/migration/FK/index inspection, translation parity/placeholder/duplicate syntax, Composer/npm advisory inspection, docs links/refresh, production asset build, safe browser smoke and git diff/status. Automated tests не создаются и не запускаются по explicit task rule.

## 47. Manual acceptance checklist

- Anonymous: Firefox подтвердил RU/EN home, search с 12 cards, catalogue, `year_from=2025` Livewire filter, browser back/forward, title/player shell, help и auth entry. На 390×844 home/search/catalog имели нулевой horizontal overflow.
- Player: signed same-origin grant дошёл до внешнего MP4 как `206`; выбранный файл не декодировался Firefox и bounded recovery повторил запрос, поэтому actual playback/subtitle/audio success не заявляется.
- Privacy: `/library` для гостя вернул `302` на `/login`; login имеет `noindex,nofollow`, current Vite assets и CSP-safe Livewire bundle получили `200`.
- Authenticated: route/policy/service/static verification; runtime writes only with safe available credentials, otherwise `not_performed`.
- Premium: unavailable/provider-free fallback and server entitlement inspected; no real charge.
- Advertiser/rightsholder: `not_applicable`, routes/domain absent.
- Administrator: all 13 routes/gates/services audited statically; no credential fabricated.
- Build/assets: production Vite 8.1.4 build passed; manifest references existing files. Первый smoke обнаружил stale guest HTML, исправление manifest dimension подтверждено новыми CSS/JS `200` и 0 console errors на home.

## 48. Requirement-compliance matrix

| Domain | Status | Evidence / limitation |
| --- | --- | --- |
| Requirements/read order/precedence | completed | owners read; integration owner/index/root rules added; Markdown links valid |
| Home/catalog/search/filters | affected/completed | RU/EN/mobile/search/catalog/filter/back-forward browser smoke; stale asset cache and Livewire CSP gaps repaired |
| Title/season/episode/player | already compliant | canonical IDs, entitlement, signed source/download boundaries retained |
| Progress/history/library | already compliant | owner policies/grouped state/unique schema retained; no duplicate progress system |
| Collections/tags/comments/reviews | affected/completed | account identity invalidation centralized; preferences exported |
| Profiles/auth/settings | affected/completed | API/Livewire name projection parity; deletion/export fixed |
| Calendar/recommendations | already compliant | canonical services/notifications/cache paths inspected |
| Requests/tickets/help | affected/completed | stored notification preferences exported; deletion hooks retained |
| Premium/payment boundary | already compliant | no active provider; deletion lifecycle guard and webhook signature adapter retained |
| Mobile/PWA | partial/not_applicable | API/mobile web exist; service worker/install/push/offline absent |
| Rights-holder cases | not_applicable | no route/schema/domain; permanent privacy rule only |
| Advertisers | not_applicable | no route/schema/domain; no portal-user data shared |
| Administration/audit | affected/completed | 13 gated routes; immutable audit deletion retention handled fail-closed |
| Notifications | affected/completed | six stable types, allowlisted export, owner deletion cleanup |
| Account merge | unresolved capability | no proof-of-control/full coordinator; domain hooks documented, email merge forbidden |
| Account deletion/export | affected/completed | FK map reviewed; morph cleanup, audit guard, safe export added |
| Cache/search/assets | affected/completed | profile lifecycle invalidation repaired; manifest hash separates guest HTML generations; live Firefox loaded only current assets |
| SEO/sitemap | already compliant | route responders/public-only boundaries inspected; no changed route |
| Multilingual | partial | ru/en parity; three public fallbacks fixed; legacy admin/stats debt retained |
| Security/privacy | affected/completed | concrete retention/orphan/export leaks fixed; official CSP-safe Livewire bundle enabled; no provider penetration claim |
| Performance/database | completed within safe scope | metadata/query inspection; no 27-GB full-table scan or invented measurements |
| Production readiness | partial | `app:deployment-check` ready, 101 migrations/SQLite integrity/FTS/indexes pass; health ready but degraded by unavailable Memcached; no restore/failover/credential journeys verified |
| Git delivery | pending final step | commit and push result recorded after verification |

## 49. Unresolved risks

- Local `main` начал задачу на 13 commits ahead of remote; configured HTTPS remote ранее не имел credentials, поэтому push может быть blocked внешней авторизацией.
- Full account merge, active OAuth/payment provider, advertiser/rightsholder domains и PWA/service worker отсутствуют.
- Legacy `/stats` и `/admin/catalog` не имеют complete `ru`/`en` translation catalog.
- Production backup/restore, provider callbacks, authenticated/premium/admin mutations и real-device journeys не выполняются без safe credentials/infrastructure.
- Огромная SQLite база исключает unbounded duplicate/integrity scans; uniqueness проверена schema constraints/indexes и targeted metadata inspection.
- Installed CSP-safe Livewire bundle emits four Firefox deprecation warnings for legacy browser capability probes; no application/CSP error remains, and package code was not forked.
- Browser reached a real signed MP4 source with `206`, but Firefox could not decode that provider file; source authorization was observed, actual playback/audio/subtitle success remains unverified.

## 50. Final completion summary

Task 27 normalized permanent integration rules, inventoried 245 routes/101 migrations/shared domains, corrected account identity projection parity, safe account export/deletion, profile invalidation, Vite-aware guest HTML caching and CSP-safe Livewire delivery, localized three public fallback surfaces, and documented actual limitations. Production build, isolated compiled-cache checks, route/translation/static analysis, deployment preflight and live Firefox smoke completed without automated tests; Git delivery result is recorded before handoff.

## 51. Final commit reference

Exact hash будет reported из `git rev-parse HEAD` после Task 27 commit. Этот документ находится внутри того commit; symbolic release reference до создания объекта Git — `main` Task 27 release commit.
