# Аудит архитектуры администрации — Task 26

Дата проверки: 19.07.2026
Ветка: `main`
Scope: routes, middleware, authentication, authorization, Livewire, Blade, data model, moderation, content, support, premium, audit, cache, search, SEO и operations.

## Итог

Исходный baseline содержал рабочие административные функции, но не единую административную архитектуру: двенадцать full-page Livewire routes использовали canonical `users` identity и безопасные private response headers, а доступ определялся повторяющимися email allowlists. Persistent roles, permissions, administrator membership/status, `/admin` dashboard, shared navigation registry, role editor и общий audit viewer отсутствовали.

Task 26 выполнен additive: существующие route names, Livewire components, policies, actions и domain histories сохранены, поверх них добавлен canonical administration access layer. Существующий `admin_audit_events` остался единственным общим audit store; параллельная generic audit table не создана. Финальный inventory содержит 17 `/admin` routes, одну middleware group, 14 stable roles, 60 stable permissions, shared navigation/dashboard/users/access/audit/operations surfaces и узкий legacy compatibility adapter.

## Проверенный исходный inventory

### Routes и middleware

| Route | Component | Current gate |
| --- | --- | --- |
| `admin.calendar` | `ReleaseCalendarAdministrationManager` | `manage-release-calendar` |
| `admin.catalog` | `CatalogAdministrationPage` | `manage-catalog` |
| `admin.comments` | `CommentAdministrationManager` | `manage-comments` |
| `admin.help` | `HelpCenterAdministrationPage` | `manage-help-center` |
| `admin.help.preview` | `HelpArticlePreviewPage` | `manage-help-center` |
| `admin.imports` | `SeasonvarImportManager` | `manage-seasonvar-imports` |
| `admin.issues` | `TechnicalIssueAdministrationManager` | `manage-technical-issues` |
| `admin.premium` | `PremiumAdministrationManager` | `view-premium-administration` |
| `admin.profiles` | `UserProfileAdministrationManager` | `manage-catalog` |
| `admin.requests` | `ContentRequestAdministrationManager` | `manage-content-requests` |
| `admin.reviews` | `ReviewModerationManager` | `manage-reviews` |
| `admin.tags` | `TagAdministrationManager` | `manage-catalog` |

Все routes — `GET|HEAD` full-page Livewire и защищены `auth`, `auth.session`, `account.private`, `can:*`. Destructive GET mutation не найдена. `PrivateAccountResponse` устанавливает `Cache-Control: private, no-store, max-age=0`, `Pragma: no-cache` и `X-Robots-Tag: noindex, nofollow`. Admin URLs не входят в sitemap builders.

### Identity и authorization

- Используется один `User`, web guard и существующий login/session/password-confirm flow; отдельной admin password system нет.
- `AppServiceProvider` определяет 13 gates. Девять общих gates используют `seasonvar.admin_emails`; четыре Premium gates дополнительно требуют отдельный email allowlist.
- Persistent role, permission, membership, inactive-role, suspended-administrator и final-superadministrator schema отсутствует.
- Blade authorization не используется как security boundary; mutations в существующих Livewire components повторяют `Gate::authorize()` или domain policy checks.
- Current user account domain имеет profile/comment/review restrictions, secure self-deletion/export и session/token management. Полный user-to-user account merge намеренно отсутствует: нет proof-of-control/OAuth reconciliation coordinator.

### Navigation и layout

- Все pages расширяют `layouts.app`; dedicated admin shell отсутствует.
- `AppLayoutData` отдельно вычисляет девять admin booleans и вручную добавляет links. Это duplicate gate/menu logic и не масштабируется.
- Canonical `admin.index` отсутствует; `admin.catalog` фактически служит одной из entry points.
- Permission-aware grouped navigation, mobile admin drawer, current-section registry и central feature availability отсутствуют.

### Domain pages

- Catalog: titles, relations, seasons, episodes, media, collections.
- Taxonomy/metadata: tags, provider mappings, localized tag content.
- Moderation: comments, reviews, profile reports, collection reports.
- Workflow/support: content requests, technical tickets, help center, calendar.
- Commercial: Premium summary/grants/promotions/audit with separately configured sensitive gates.
- Operations: approved Seasonvar importer page and domain-specific status only.

`CatalogCollectionAdministrationManager` существует как child component внутри `admin.catalog?section=collections`; отдельного duplicate route нет. Recommendation services/search index/sitemap/SEO/cache/health services существуют, но administrative control pages для них не реализованы.

### Audit и internal notes

- `admin_audit_events` содержит actor, stable action code, resource type/id, safe before/after SHA-256 fingerprints, changed field allowlist и timestamp.
- Model запрещает update/delete; FK actor использует `RESTRICT`, поэтому immutable history не исчезает при удалении actor.
- Recorder покрывает catalog, seasons, episodes, media, collections, comments, reviews и tags.
- Premium/auth/import/request/ticket/help/calendar domains имеют собственные histories или operational events. Они не должны механически копироваться в новую table.
- Shared paginated audit viewer, correlation identity и audit events для role/membership/cache/system settings отсутствуют.
- Internal notes существуют в domain-specific ticket/moderation records; generic public/internal note UI отсутствует. Legal/billing note domain отсутствует.

### Query и UI audit

- Проверено 13 class-based administration components; крупные компоненты используют `WithPagination`, `#[Url]`, deterministic filters и repeated action authorization.
- Проверено 12 admin/moderation Blade views: `@php`, inline `<style>`, inline `<script>`, `DB::`, `Cache::`, `Gate::`, `Auth::`, container calls и direct model queries не найдены.
- Найдено 79 строк hardcoded Russian copy в legacy admin views. Новая shared architecture использует `lang/ru` и `lang/en`; legacy strings переводятся только при безопасном touched-surface migration.
- Найдено 71 loading binding, 83 ARIA/role declarations и 25 stable `wire:key`; state/a11y coverage не унифицировано.
- Production-like SQLite scale на момент audit: около 102 users, 32 929 titles, 3.7M comments, 1.72M reviews и 32 671 failed jobs. Unbounded admin reads недопустимы.

## Security и privacy findings

1. Broad email cohort получает несвязанные catalog/moderation/support capabilities; это основной least-privilege gap.
2. Нельзя приостановить только administrative membership и немедленно инвалидировать его access state.
3. Нет final-superadministrator invariant и assign-only-what-actor-possesses boundary.
4. Navigation logic раскрывает только разрешённые links, но делает отдельную gate check на каждый item и дублирует definition.
5. Общий audit viewer отсутствует; сырые logs не должны становиться заменой.
6. Premium provider secrets не выводятся; реальный provider не настроен. Billing/legal/identity document permissions нельзя симулировать.
7. Ticket attachments и diagnostics уже имеют private responders; list pages не должны загружать binary payload.
8. Account merge нельзя добавлять как кнопку до proof/conflict/reconciliation/rollback coordinator.

## Capability truthfulness

Следующие системы не установлены и не должны получать fake routes/controls/metrics: advertiser platform, rights-holder case/document domain, configured payment gateway, external search engine, impersonation, service worker, unrestricted log browser, browser deployment/restore, verified high-availability/failover monitoring.

Допустимо показывать только translated `not_installed`/`unavailable` summary внутри разрешённого operations overview, если summary основан на repository/config evidence и не предлагает dead action.

## Совместимая целевая архитектура

1. Stable `AdminPermission`/`AdminRoleCode` enums и additive role/permission/membership tables.
2. Request-scoped `AdminAccessResolver`; current gate names становятся compatibility facade.
3. `EnsureAdministrator` middleware требует authenticated, verified, active eligible user и отказывает suspended/revoked membership.
4. Legacy email allowlist сохраняет только прежние capabilities; sensitive Premium rights остаются exact allowlist. Новые sensitive права не выдаются автоматически.
5. Один admin route group, `admin.index`, navigation registry, shared shell и permission-scoped dashboard.
6. Existing domain pages сохраняют route names/actions и подключаются к registry.
7. Existing audit store расширяется безопасными event types/metadata и получает paginated viewer.
8. Shared table/filter/bulk contracts применяются минимум в users, roles и audit pages; public Livewire state остаётся scalar/bounded.
9. Operations page использует только существующие safe cache version/search/health/import summaries; arbitrary keys, shell, SQL, env, raw logs и full flush запрещены.
10. RU/EN parity, noindex/no-store, responsive tablet/mobile navigation и accessibility states обеспечиваются одной shared boundary.

## Финальное состояние после интеграции

### Routes, identity и privacy

- 17 stable routes: `admin.index`, `admin.users`, `admin.access`, `admin.audit`, `admin.operations` и прежние 12 feature routes. Все — full-page Livewire `GET|HEAD`; destructive route отсутствует.
- Каждый route проходит `auth`, `throttle:administration`, `auth.session`, `verified`, `account.private`, `account.active`, `admin.access` и action permission. Responses имеют private/no-store и noindex headers, в sitemap/structured data/service-worker public cache routes не входят.
- Отдельный guard/password system не создан. Web identity, password confirmation, session regeneration/revocation и Authentication audit переиспользуют Task 15. Blocking restriction дополнительно закрывает optional Sanctum playback и отзывает существующие tokens/sessions.

### RBAC и superadministrator

- Таблицы `admin_roles`, `admin_permissions`, `admin_role_permissions`, `admin_user_roles` и enums содержат 14/60 stable codes; labels и descriptions находятся только в RU/EN catalogs.
- `AdminAccessResolver` загружает active non-expired memberships/permissions bounded graph; suspended membership fail-closed. `AdminLegacyAccessMap` сохраняет exact прежний scope email allowlists и не выдаёт новые sensitive permissions.
- Role assign требует recent authentication, verified target, active role, stable reason и assign-only-what-actor-possesses. Revoke/suspend требует explicit confirmation. Final active superadministrator защищён transaction + lock и каждое изменение audited.
- Superadministrator не получает автоматически refund/reconcile и legal identity/authority document permissions. Advertiser/legal routes отсутствуют, потому что соответствующие schemas отсутствуют.

### Shared surfaces и queries

- `AdminNavigationRegistry` — одна definition source с server-side permission filter и current state; нет per-item queries или public cache. Mobile/desktop navigation использует общую accessible разметку.
- Dashboard строит только реальные grouped aggregates, скрывает недоступные domains и изолирует section failure. User, access и audit lists используют deterministic bounded pagination/projection; query-budget tests фиксируют отсутствие N+1.
- Shared table/filter/state/confirmation components используют allowlisted sort/filter codes, page sizes `15|25|50`, максимум 50 explicit selected UUID и accessible loading/empty/error/unauthorized/unavailable presentation. Generic bulk database selection не реализован.
- User directory выводит public UUID/masked email и safe summary. Password hash/reset/remember/OAuth/MFA/payment/session tokens, raw IP, viewing history, private collections/legal/ticket details не выбираются.

### Audit и operations

- Existing append-only `admin_audit_events` расширен public UUID, safe public resource identity, UUID correlation identity и query indexes. Viewer paginated; export ограничен 1000 rows и защищён от CSV formula injection. Raw values, private notes, credentials, URLs, documents и provider payload не записываются/не экспортируются.
- `account_restrictions` поддерживает stable type/reason, optional expiry, public notice/private note, audit/notification и cache/session/token effects. Social-equivalent/mobile login bypass закрыт.
- `admin_operational_events` хранит immutable safe cache/search actions. Operations page допускает только targeted domain cache-version invalidation и rebuild одного existing SQL search document с confirmation/idempotency/audit.
- Capability registry честно показывает real database search/cache/scheduler/queue/provider/schema evidence. External index, raw logs, arbitrary cache keys/full flush, shell, SQL/filesystem/env editor, generic settings/flags, advertiser/rights-holder, deployment/restore controls отсутствуют.

### Content и domain integration

- Catalog route теперь требует `content.view`; metadata/create/publish/delete, source view/manage/disable, collection moderation и recommendations разделены. Moderator может открыть collection queue без metadata edit; media manager не получает content edit; content editor не может publish/archive.
- Profile moderation отделена от `manage-catalog` и использует `moderation.profiles`. Existing comments/reviews/requests/tickets/help/calendar/premium/import/tag routes сохранили components/actions и получили stable permission route gates через legacy-compatible aliases.
- Existing domain histories/private-note/revision/notification/cache/index/SEO/sitemap behavior переиспользованы. Account merge, advertiser, rights-holder, external search, generic redirects/settings/flags/logs и payment provider workflows не симулируются при отсутствии canonical domain.

### Verification evidence

- Admin-focused suite: 70 tests / 2672 assertions; RU/EN exact key/order/placeholder parity; every role/permission/audit label covered.
- Полный PHPUnit snapshot: 1410 tests, 1399 passed, 11 skipped, 122864 assertions, exit `0`.
- Static security checks подтверждают отсутствие `@php`, direct model/service/container calls, inline CSS/large JS, raw admin output и hardcoded admin URL в canonical admin Blade. `view:cache`, `route:cache`, 17-route inventory и `project:docs-refresh --check` проходят.
- Production Vite build собрал 23 modules. Managed Chromium проверил 16 staff destinations в desktop/mobile/tablet: 48 route checks и 3 diagnostic sets без overflow, raw key, console, page, same-origin request, noindex и no-store failures.
- Query budgets, widget/query failure isolation, route middleware/action gates, noindex/no-store/sitemap exclusion, CSV safety, final-super protection, legacy mapping и permission-specific content access закреплены regression tests.

## Rollback

Schema changes выполняются отдельными additive migrations. Runtime compatibility adapter позволяет откатить route/middleware/resolver integration без удаления legacy allowlist. RBAC rows не меняют canonical user identity и не переписывают domain history. Rollback migration удаляет только новые administration tables/columns после подтверждения отсутствия dependants; существующие admin routes/audit records не удаляются.
