# Текущая задача — даты серий Seasonvar в календаре и XML-tail backfill

Дата: 20.07.2026
Статус: implementation и focused verification завершены; production preflight, bounded backfill и финальная сверка выполняются без дополнительных вопросов по прямому указанию пользователя.

## Цель и план

- [x] Проверить живые страницы, robots/sitemap и локальный parser output.
- [x] Проследить данные parser → season/episode DB → release calendar и подтвердить root cause.
- [x] Согласовать семантику: provider date относится к названной серии/переводу и не прогнозирует следующую серию.
- [x] Сравнить прямую запись `Episode::released_at`, raw-text calendar mapping и отдельный normalized synchronizer; выбрать synchronizer.
- [x] Обновить canonical importer/calendar contract и записать design spec.
- [x] Записать подробный TDD implementation plan и проверить его на полноту.
- [x] Реализовать provider observation synchronizer и bounded `--sitemap-tail=1..1000` queued mode.
- [x] Устранить обнаруженный production blocker: повторный metadata-backlog finalizer имеет конечный набор обязательных кандидатов, queued maintenance возобновляется из versioned checkpoint, а catalog-wide recommendation fallback не удерживает 900-секундный queued job.
- [ ] Выполнить focused/full verification, legacy scan, документацию, README/CHANGELOG review.
- [ ] После terminal active run безопасно поставить последние 1000 serial XML URL в существующую очередь и проверить две контрольные страницы и `/calendar`.
- [ ] Commit/push только из `main`, если общий staging/worktree позволяет отделить изменения без захвата чужих задач.

Rollback: schema/dependency change отсутствует. Code revert отключает mapping и bounded selector; provider calendar rows остаются корректной историей. Возможное удаление требует отдельного audited hide action только для provider/source-page identity, без удаления сезонов, серий, media, manual locks или higher-source entries. Queue/cache clear запрещены.

## Compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Живой source и parser behavior | `completed` | Обе страницы возвращают дату/номер/перевод; current parser извлёк `2026-07-19`, `7/7 RuDub` и `3/8 Coldfilm`. |
| Root cause | `completed` | Season fields сохраняются, `Episode::released_at` пуст, calendar entries отсутствуют; прежний owner contract запрещал mapping. |
| Честная semantic mapping | `completed` | Canonical owners и design фиксируют translation/subtitle/provider episode facts без next-date inference. |
| Importer single command/pipeline | `completed` | Дизайн расширяет только `seasonvar:import`, существующие mirror/claims/groups/finalizers/global single-flight. |
| Database/data safety | `already_compliant` | Новая schema не нужна; writes используют existing transaction, stable key, revision/correction, lock/source priority. |
| Queue/cache/notifications | `unresolved` | TDD подтвердил bounded selection, idempotency, after-commit code path и `deferred` вместо unbounded recommendation fallback; graceful reload затронул только четыре import-worker. Ещё требуются terminal run evidence и отсутствие запоздалых уведомлений при backfill. |
| Authentication/authorization/privacy/premium/region/legal | `already_compliant` | Client input/private state/access boundaries не меняются; raw source URL не попадает в calendar/run summary. |
| Translations/search/SEO/mobile/admin/API/public routes | `already_compliant` | Identity/routes/UI/API shape не меняются; existing calendar presenter получает canonical entries. |
| Production/backup/rollback | `unresolved` | Перед 1000-page writer run нужен fresh status, отсутствие active run, queue/database evidence and rollback record. |
| Tests, docs, README, CHANGELOG, legacy scan | `unresolved` | `Pint`, managed-doc check и `git diff --check` прошли; полный suite после конечного queued-recommendation контракта дал 1 407 passed/11 skipped и 122 916 assertions, точечная матрица — 128/701, отдельно maintenance/recommendation — 60/340. Owners, README и CHANGELOG обновлены; финальная live-сверка ещё выполняется. |
| Git delivery | `unresolved` | Shared `main` уже содержит многочисленные чужие staged/unstaged изменения; нельзя включать или снимать их без владельца. |

Cross-feature impact: affected — importer, seasons/episodes, release calendar, notifications, calendar/home/title/sitemap cache, queued recommendation handoff, queue operations and visitor documentation. Recommendations сохраняют active rows и dirty IDs, но unbounded full fallback перенесён из 900-секундного queued finalizer в контролируемый synchronous maintenance path. Unaffected by design — auth, policies, privacy, search semantics/ranking, playback URLs, personal progress/history, premium/payments, region/legal restrictions, API/routes and frontend assets.

Fresh XML evidence: карта содержит 47 835 distinct serial URL. `serial-49406-Vestis.html` находится в последних 301 и попадёт в bounded tail `1000`; `serial-41165-Interv_yu_s_vampirom_psxwrfv-3-season.html` находится в 8 407 позициях от конца и потому требует отдельного forced targeted refresh через ту же публичную команду после terminal global run. Это не расширяет XML-tail selector и не подменяет его произвольным набором.

---

# Ложный статус «импорт уже запущен» при stale global run

Дата: 20.07.2026
Статус: root cause подтверждён; TDD RED/GREEN implementation выполняется.

## Root cause и scope

- Read-only DB evidence: global sitemap run `#944` остался `execution_mode=queue`, `status=running`, `finished_at=NULL`; heartbeat продолжает обновляться queue finalizer jobs, хотя selected/processed = `1540/1540`, все 655 title groups terminal и live page claims отсутствуют.
- Уточнённая корневая причина: `18 553` media-metadata chunk events не являются повторением курсора — production backlog действительно содержит `579 334` distinct media rows. Каждый global finalizer корректно проходит их примерно за 4 минуты, завершает cleanup/merge, затем `CatalogTitleRecommendationBuilder::rebuildDirty()` запускает обязательный full v6 shadow build для `12 005` dirty titles при отсутствии active v6 build. Job достигает `timeout=900` до активации, а следующая доставка повторяет все предыдущие catalog-wide стадии и снова оставляет менее 11 минут для recommendation build.
- Первое исправление сохранило bounded checkpoint результатов catalog-wide maintenance до recommendation rebuild. Production build `#76`, получив весь 900-секундный budget после resume, всё равно не завершил full shadow build и доказал, что повторная попытка не является конечным recovery path. Итоговый контракт оставляет queued finalizer только bounded scoped rebuild: отсутствие active v6, version mismatch или превышение dirty/source limit возвращают `deferred`, сохраняют dirty rows и завершают run. Catalog-wide full build принадлежит контролируемому synchronous maintenance-запуску той же публичной команды. Checkpoint по-прежнему исключает повтор local/media/cleanup/merge стадий и удаляется после terminal результата.
- Дополнительная production-метрика показала, что все `579 334` строк старого backlog уже имеют вычисляемые `format`/`variant_type`/`variant_key`, а повторный выбор создавали только честно неизвестные optional `quality`/`translation_name`. Eligibility сужена до детерминированно repairable identity fields; текущий repairable production backlog равен `0`, но для реально выбранной строки optional metadata по-прежнему дополняется попутно.
- Process evidence: живого `php artisan seasonvar:import` нет; присутствуют только штатные `queue:work` процессы.
- `SeasonvarGlobalImportRunCoordinator::activeRuns()` считает любую persisted `queued/running` строку активной и не вызывает существующий stale predicate.
- `SeasonvarImportAdminService::recoverStale()` уже умеет безопасно закрывать старый queued-run без живых claims, но CLI sync/queued start эту boundary не вызывает.
- Исправление переносит stale predicate в canonical global coordinator и применяет его под существующим коротким start-lock до active lookup; active live claim остаётся блокирующим.

## Ожидаемые изменяемые файлы

- `app/Services/Seasonvar/SeasonvarGlobalImportRunCoordinator.php`
- `app/Services/Seasonvar/SeasonvarImportAdminService.php`
- `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- `tests/Feature/SeasonvarImportMaintenanceTest.php`
- `tests/Feature/SeasonvarParallelImportTest.php`
- `docs/importer.md`, `docs/queues.md`, `docs/plans/current-task-plan.md`
- `README.md`, `CHANGELOG.md`
- `docs/superpowers/plans/2026-07-20-seasonvar-stale-global-run-recovery.md`

## Совместимые contracts и риски

- Сохраняются `php artisan seasonvar:import`, CLI options/output envelope, `SeasonvarImportStartResultData`, run status codes, lock names/store, queue names, routes, translations, migrations, cache keys и public API.
- `mode=url`, inventory и status не участвуют в global stale reconciliation.
- Только `execution_mode=queue`, `status=running`, просроченный heartbeat и отсутствие unexpired page claim разрешают auto-fail; живой claim блокирует новый запуск. Queue finalization checkpoint содержит только bounded counters/results без URL, provider body или secrets и принимается только для той же версии контракта.
- Migration/data-backfill/dependency/route/translation change отсутствует. Data mutation ограничена переводом доказанно stale lifecycle row в `failed`; каталог, seasons, episodes, media и source snapshots не меняются.
- Rollback: revert coordinator/admin/test/docs change. Schema restore, backup restore, cache flush и queue clear не нужны; уже terminal stale audit row остаётся правдивой историей.

## Compliance matrix

| Требование / domain | Статус | Evidence / ограничение |
| --- | --- | --- |
| Canonical requirements и Laravel 13 docs | `completed` | Прочитаны обязательные owners и importer/queue contracts; Boost подтвердил PHP 8.5, Laravel 13.20.0 и atomic lock closure semantics |
| Root cause / existing implementation | `completed` | DB/process evidence и trace CLI → coordinator → active persisted row подтверждают ложную блокировку |
| TDD regression и live-claim safety | `completed` | RED checkpoint test упал на повторном изменении media; отдельный RED упал, когда queued finalizer начал full build вместо `deferred`. GREEN доказал resume/store/cleanup checkpoint, bounded recommendation handoff и сохранение dirty state. Stale/no-claim и stale/live-claim contracts прошли |
| Importer single-flight / concurrency | `completed` | Один coordinator-owned stale predicate выполняется под existing start-lock; active live claim и global finalizer lock tests зелёные |
| Database/data safety | `already_compliant` | Additive schema не нужна; bounded conditional update касается только stale lifecycle rows без live claims |
| Cache/queue/production recovery | `unresolved` | Lock/store/queue contracts неизменны; 128 task-focused tests / 701 assertions прошли. Graceful reload выполнен только для четырёх import-worker без cache/queue clear; три уже загрузили новый код, текущая legacy-попытка `#77` штатно дорабатывает старый 900-секундный контракт. Требуется terminal evidence для `#944` после следующей доставки |
| Security/privacy/logging | `already_compliant` | Новых входов, URL, secrets или raw exception output нет; stable safe error text переиспользуется |
| Routes/API/UI/translations/search/SEO/sitemap/notifications/audit | `not_applicable` | Public shape и visitor content не меняются; изменяется operational correctness одной команды и persisted run audit status |
| Authentication/authorization/premium/payment/region/legal/advertiser | `not_applicable` | Доступ и пользовательские/финансовые/правовые данные не затрагиваются |
| Mobile/accessibility/frontend build | `not_applicable` | Frontend assets и UI не меняются |
| README/owner docs/CHANGELOG | `completed` | Обновлены importer/queues/performance/architecture owners, русский README visitor history и отдельная русская запись CHANGELOG |
| Git delivery | `unresolved` | `main` подтверждена, но общий index/worktree содержит чужой смешанный snapshot; task-only commit/push возможен только без его перестройки |

---

# Task 26 — каноническая администрация, moderation и operational control

Дата: 19.07.2026
Ветка: `main` (`origin/main` отстаёт на 35 ранее существовавших commit на момент старта)
Статус: завершено; обязательный requirement-file gate, implementation, verification, commit и push выполнены на существующей `main`. Основной implementation commit: `eb4e7f9e7dcf300328b35c527f65a39a743c2ebe`; следующий общий documentation commit `3bd5e5637f89a46a56e714d0e9987a7e8e10b40a` также опубликован в `origin/main`.
Scope: repository-wide audit, normalization и безопасная интеграция administration/authentication/navigation/RBAC/dashboard/content/moderation/support/premium/audit/operations с существующими domain boundaries без fake capabilities.

## 1. Mandatory phase zero — Markdown и требования

### Полный найденный inventory

- Проверены все 320 project-owned Markdown-файлов вне `vendor`, `node_modules` и generated output: 67 617 строк. Это включает root docs, `docs/**`, `.github/**`, `.agents/**` и `.superpowers/**`.
- Global repository instructions: `AGENTS.md`, `docs/requirements/index.md`, `docs/CODE_STANDARDS.md`, `docs/architecture.md`, `docs/development.md`, `docs/requirements/multilingual-requirements.md`, `docs/security.md`, `docs/performance.md`, `docs/caching.md`, `docs/UI_STANDARDS.md`, `docs/frontend.md`, `docs/administration.md`, `docs/authorization.md`, conditional production/maintenance/system-integration owners.
- Architecture/product owners: `docs/README.md` и тематические owners из его карты, включая authentication, catalog, importer, playback, collections, comments, reviews, profiles, requests, tickets, help, calendar, recommendations, premium, SEO, deployment и operations.
- Temporary/current plans: `docs/plans/current-task-plan.md`, активные `docs/superpowers/plans/**`; historical evidence: завершённые task sections, audits, specs и `.superpowers/**` reference material.
- Tool-specific directory rules: `.agents/skills/**` и `.github/**` применяются только при вызове соответствующего skill/tool и не переопределяют repository requirements. Найдены пять exact duplicate pairs в generic skill packages; они не являются canonical project requirements и не удаляются как vendor-like tool assets.
- Codex автоматически читает root `AGENTS.md`; остальные permanent owners становятся обязательными через явный read order в `AGENTS.md` и `docs/requirements/index.md`.
- Все 366 Markdown links в root/topical documentation проверены; missing targets не обнаружены.

### Каноническая нормализация

| Категория | Канонический owner | Действие Task 26 |
| --- | --- | --- |
| Agent workflow | `AGENTS.md` | Добавлен обязательный 20-step workflow, no-memory rule, main/commit/push/final compliance contract. |
| Registry/read order/precedence | `docs/requirements/index.md` | Добавлен registry с path/purpose/scope/mandatory/order/owner/date и exact precedence; linked current plan/changelog. |
| Project/product repository rules | `docs/CODE_STANDARDS.md` | Использован вместо duplicate `project-requirements.md`; добавлены production-style/backward-compatible/no-fake/version/26-domain rules. |
| Architecture rules | `docs/architecture.md` | Использован вместо duplicate `architecture-rules.md`; добавлены Blade/Livewire/layer/trust/payload/compatibility constraints. |
| Development workflow | `docs/development.md` | Использован вместо duplicate `development-workflow.md`; добавлены before/during/completion gates. |
| Multilingual | `docs/requirements/multilingual-requirements.md` | Расширен обязательный locale/identity/fallback/hydration/review contract. |
| Security/privacy | `docs/security.md` | Использован вместо duplicate `security-and-privacy.md`; добавлен permanent threat/privacy/admin-data contract. |
| Performance/cache | `docs/performance.md`, `docs/caching.md` | Использованы вместо duplicate combined file; добавлены query/pagination/index/private-cache contracts. |
| UI/UX/a11y | `docs/UI_STANDARDS.md`, `docs/frontend.md` | Использованы вместо duplicate `ui-ux-accessibility.md`; обязательные responsive/state/a11y rules закреплены у owner. |
| Administration | `docs/administration.md`, `docs/authorization.md` | Использованы вместо duplicate `administration-requirements.md`; добавлены canonical/RBAC/audit/bulk/private/operations rules. |
| Owner map | `docs/README.md` | Зафиксировано соответствие requested canonical names существующим owners и добавлен Task 26 owner. |
| Current execution evidence | Этот файл | Task-specific plan/compliance matrix добавлены без удаления предыдущих task records. |

Новые duplicate requirement filenames не создаются, requirement files не удаляются. Консолидация означает один registry и ссылки на существующих owners, а не копирование тысяч строк. Historical/task-specific документы остаются evidence, но не становятся permanent rules.

### Разрешённые противоречия

| Conflict | Решение и precedence |
| --- | --- |
| Prompt требует English changelog, а root repository contract требует весь обычный `CHANGELOG.md` на русском | Сохраняется более конкретный действующий repository integrity/workflow contract: новая запись будет на русском; technical identifiers остаются как есть. |
| Старые Task sections запрещали тесты, текущий `AGENTS.md` требует changed-behavior tests | Historical запрет остаётся только evidence прежней задачи; Task 26 применяет current PHPUnit/CI contract и не повреждает tests. |
| Generic skill rules допускают Volt, isolated worktree/branch или иной visual system | Repository rules строже: no Volt, only existing `main`, current Seasonvar design system. |
| Некоторые старые docs описывают controller HTML/Blade auth/business calls | Текущий Laravel 13 class-based full-page Livewire, passive Blade и server policy/gate rules имеют precedence; compatibility adapters сохраняются только при evidence. |
| Task 26 перечисляет advertiser/rights-holder/search-index/provider controls, но реальный domain/provider отсутствует | Security/data truthfulness имеет precedence: fake domain не создаётся; authorized overview показывает `not_installed`/`unavailable` либо section отсутствует. |
| Простая implementation convenience могла бы заменить все email gates глобальным bypass | Least privilege/data integrity имеет precedence: additive RBAC и temporary narrow compatibility adapter, без автоматической выдачи sensitive permissions. |

### Requirement-file completion gate

- [x] Все project Markdown files inspected и classified.
- [x] Все existing requirement owners identified; root/reference links checked.
- [x] Canonical structure reused; duplicate requirement system не создан и ничего blindly не удалено.
- [x] Permanent rules добавлены в owners; registry/read order/precedence обновлены.
- [x] Conflicts и stricter decisions зафиксированы.
- [x] Обновлённые owners перечитаны от начала до конца; финальный route inventory повторно проверен (`242 total`, `17 admin`, `67 api`).
- [x] Statuses/evidence обновлены после reread; application implementation разрешена только через следующий design/TDD audit phase.

## 2. Relevant architecture files read

Прочитаны `AGENTS.md`, полный canonical read order, `docs/README.md`, `architecture.md`, `authorization.md`, `administration.md`, `security.md`, `performance.md`, `caching.md`, `UI_STANDARDS.md`, `frontend.md`, `testing.md`, `deployment.md`, `environment.md`, operations owners, `DATA_RELATIONS.md`, catalog/importer/player/profile/requests/technical-issues/help/release-calendar/premium owners, текущие audits/specs/plans и все остальные project Markdown files. Перед PHP/Livewire changes дополнительно перечитываются применимые version-specific Laravel skill rules и официальная Laravel 13 documentation.

## 3. Исходная administration architecture — verified baseline

- Identity/guard: одна canonical `users` identity и Laravel `web` guard; отдельного admin guard/password system нет.
- Eligibility: route `auth` + `auth.session` + `account.private`; section gates сейчас сравнивают normalized email с `config('seasonvar.admin_emails')`/Premium allowlists. Persistent admin role/membership/status model отсутствует.
- Routes: 12 GET full-page Livewire routes: `admin.calendar`, `admin.catalog`, `admin.comments`, `admin.help`, `admin.help.preview`, `admin.imports`, `admin.issues`, `admin.premium`, `admin.profiles`, `admin.requests`, `admin.reviews`, `admin.tags`. Destructive GET routes не найдены.
- Response privacy: `PrivateAccountResponse` ставит private/no-store и `X-Robots-Tag: noindex`; admin routes не входят в public sitemap inventory.
- Layout/navigation: pages используют общую public app layout; единого admin layout/registry нет. `AppLayoutData` вручную собирает menu entries через повторяющиеся gates.
- Dashboards: canonical `/admin` dashboard отсутствует. Есть real domain panels внутри imports/premium/help/calendar/moderation pages.
- Discovered roles: persistent roles отсутствуют; фактически существует один configured catalog-administrator cohort плюс отдельные Premium email allowlists.
- Discovered gates/permissions: `manage-seasonvar-imports`, `manage-catalog`, `manage-comments`, `manage-reviews`, `manage-content-requests`, `manage-technical-issues`, `manage-release-calendar`, `manage-help-center`, `view-premium-administration`, `manage-premium-grants`, `manage-premium-promotions`, `view-premium-billing-audit`, `reconcile-premium`.
- Moderation queues: comments, reviews, profile reports, collection service, content requests, technical issues и tag/catalog workflows; collection manager exists but has no registered admin route in current inventory.
- Content management: catalog title/relations/seasons/episodes/media, tags, help articles/translations, release schedules; source URLs protected and current source edit boundary is deliberately narrow.
- Configuration: no unrestricted settings editor. Premium/configuration comes from typed config/domain services; no feature-flag UI.
- Audit: one `admin_audit_events` table/model/recorder with append-style writes for catalog/comments/reviews/tags/collections; separate premium/auth/import histories. No shared paginated admin audit viewer; current local `admin_audit_events` count was 0 at audit time.
- System health/operations: importer safe summary exists. No truthful generic health dashboard, log browser, arbitrary cache control, backup/deploy control or external search-index UI.
- Search/SEO/redirects: real catalog search index/services, sitemap/robots/SEO responders exist; no canonical admin pages for them and no generic redirect registry.
- Absent domains: no advertiser organization/campaign/billing domain, no rights-holder case/document workflow, no configured payment provider, no external search engine, no impersonation and no safe browser deployment/restore orchestration.
- Scale evidence: configured SQLite contains about 102 users, 32 929 titles, 3.7M comments, 1.72M reviews and 32 671 failed jobs; every admin list/aggregate therefore requires bounded queries and pagination.

## 4. Duplicate/conflicting implementation and risk register

| Risk | Severity | Planned treatment |
| --- | --- | --- |
| Same email allowlist closure grants unrelated catalog/moderation/support/calendar/help capabilities | High | Stable permissions + role assignments; narrow legacy compatibility adapter; sensitive Premium remains separate. |
| No active/suspended/deleted administrator membership state | High | Reuse canonical account identity/status boundary and deny inactive memberships server-side. |
| Navigation manually duplicated and gate-by-gate | Medium | Typed permission-aware navigation registry + grouped badge provider. |
| Existing audit coverage fragmented/incomplete and no viewer | High | Extend the one existing audit architecture with stable event codes, safe metadata and paginated viewer; do not create second audit table. |
| Large comment/review/failed-job datasets | High | Bounded pagination, projections, grouped aggregates, deterministic sorts, query-plan/index review. |
| Sensitive Premium/legal/identity/source details | Critical | Separate permissions, masking/omission, no list binaries/raw URLs/secrets, recent auth for high-impact actions. |
| Legacy pages use shared public layout and inconsistent states | Medium | Shared admin shell/components without breaking existing route names/component actions. |
| Fake absent domains/provider/health functionality | Critical | Capability registry backed only by real services; no fake route/control/count. |
| RU/EN admin key drift/hardcoded Russian strings | Medium | Existing PHP catalogs, exact parity/placeholder checks, stable codes, no translated identity. |
| Shared dirty `main` from concurrent completed/ongoing work | High | Preserve all pre-existing changes, use additive scoped patches, inspect staged/unstaged diff before commit; never reset or delete other work. |

Privacy risks: over-broad user fields, internal notes, ticket diagnostics, payment/legal evidence, raw IP/device data and exports. Security risks: IDOR, stale permission cache, client-trusted Livewire IDs, mass assignment, XSS, CSV formulas, SSRF/path/open redirect, secret/log/cache leakage. Compatibility risks: existing route names/actions, email allowlist operators, auth/session/account lifecycle, catalog/media identities, locale URLs, public caches/search/SEO/sitemap, Task 09–25 domain services. Database risks: legacy-admin mapping, final-superadmin invariant, actor-retention FK, millions-row query plans and SQLite portability.

## 5. Implementation plans

### Migration plan

Add SQLite-compatible role/permission/membership schema only after full migration/model audit. Preserve users and existing administrators; map legacy eligibility through a temporary runtime adapter rather than blindly granting sensitive permissions. Backfills idempotent, indexes tied to real list/authorization queries, rollback documented, and final active superadministrator protected. Existing migrations remain untouched.

### Authorization plan

Stable enums/codes, centralized access resolver, section/action permissions, resource policies, narrowly controlled superadministrator rule, assign-only-what-actor-possesses, inactive-role/membership checks, recent auth + confirmation for sensitive changes, session/permission-cache refresh and full audit. Existing route gates keep stable names as compatibility facade.

### Cache plan

No public/global admin cache. Public base aggregates separated from permission/viewer overlays. Navigation permissions are request-scoped or safely versioned; role/membership changes invalidate authorization state. Domain mutations call existing targeted invalidators. No arbitrary key browser, private value display or default full flush.

### Translation plan

Use existing `lang/ru` and `lang/en` PHP catalogs; introduce one administration catalog or extend current owner keys without duplicate systems. All labels/states/a11y messages in both locales, placeholders/plurals identical, codes untranslated, locale preserved through Livewire/navigation/filter URLs.

### UI/accessibility plan

One shared admin layout/navigation; responsive desktop sidebar/mobile drawer; accessible headings/current nav/focus/announcements/dialogs/tables/filter sheets/pagination/bulk selection; long labels/zoom/reduced motion/safe area; truthful loading/empty/failure/unauthorized/unavailable states. Reuse Tailwind 4/current components and Vite modules; no inline CSS/business JS.

### Documentation plan

Maintain this completed evidence, update `administration.md`, `authorization.md`, security/privacy/performance/cache/UI/architecture owners, permission/route/audit/operations sections without parallel duplicate docs, visitor `README.md` only for real visible functionality, and add a separate Russian `CHANGELOG.md` entry.

## 6. Expected files and protected compatibility

Expected changes: `app/Enums/Admin*`, focused `app/DTOs/Administration/**`, `app/Services/Admin/**`, `app/Actions/Administration/**`, `app/Models/Admin*`/`User`, administration middleware/policies/provider registration, `app/Livewire/Administration/**`, shared admin Blade components/views, `routes/web.php`, additive migrations, `lang/ru|en/administration.php`, targeted tests, current owner docs/plan/README/changelog and only required Vite/Tailwind files.

Must remain compatible: all existing public/localized routes and API resources; the 12 current admin route names and Livewire contracts or documented redirects; `users` identity/auth/session/token/password/verification; catalog/title/season/episode/media IDs and protected URLs; import command; comments/reviews/collections/profiles/requests/tickets/help/calendar/recommendation/premium schemas/services; free/premium/region/legal/player behavior; cache keys/invalidation; SEO/sitemap/robots; account export/delete; SQLite and configured database/cache/queue behavior; concurrent user changes.

## 7. Implementation phases

1. `completed` — requirement normalization, reread and compliance gate.
2. `completed` — full code/schema/query/security/UI audit and version-specific design/spec (`docs/audits/administration-architecture-audit.md`, `docs/superpowers/specs/2026-07-19-task-26-administration-architecture-design.md`).
3. `completed` — TDD for RBAC/membership/final-superadministrator/legacy compatibility; executable plan: `docs/superpowers/plans/2026-07-19-task-26-administration-architecture.md`.
4. `completed` — canonical admin middleware/routes/layout/navigation/dashboard and shared states.
5. `completed` — shared table/filter/pagination/bounded-selection/confirmation/audit/search/export foundations; private notes remain canonical domain-owned records rather than a duplicate generic store.
6. `completed` — user/restriction and existing content/moderation/support/premium domain integrations.
7. `completed` — truthful operations/cache/database-search capability integration; absent SEO redirect/settings/flags/log/provider domains intentionally have no fake mutation routes.
8. `completed` — translation/responsive/accessibility/security/privacy/performance hardening.
9. `completed` — repository-wide cleanup, tests/static/build/browser/manual verification, docs/changelog/reread.
10. `completed` — exact diff reviewed, unified snapshot committed only on `main`, mandatory pre-push passed and configured `origin/main` verified at `3bd5e5637f89a46a56e714d0e9987a7e8e10b40a` containing Task 26 commit `eb4e7f9e7dcf300328b35c527f65a39a743c2ebe`.

## 8. Manual acceptance checklist

- [x] Guest, unverified, suspended, deleted/inactive and unauthorized user cannot enter `/admin`; eligible admin uses canonical auth/session.
- [x] Each route/navigation/card/action is permission-aware server-side; hidden UI does not replace authorization.
- [x] Stable roles/permissions, final superadministrator protection, recent auth and audited role changes work.
- [x] Existing 12 route names continue; `/admin` shell/dashboard uses real grouped data and isolated widget failures.
- [x] Tables/filter/search/pagination/browser history are bounded/deterministic; bounded selections define preview/per-item/partial-failure contract and no unsafe all-database action exists.
- [x] Audit/internal notes contain no secrets and remain private/paginated; destructive writes never use GET.
- [x] User/catalog/season/episode/source/tag/comment/review/profile/request/ticket/help/calendar/premium integrations reuse domain services.
- [x] Absent advertiser/legal/provider/external-index/log/deploy functions show no fake controls or claims.
- [x] Cache actions are targeted; no public admin cache, arbitrary keys, full flush default or stale permission expansion.
- [x] Audit export is authorized/private/bounded and CSV-formula safe; no hashes/tokens/documents/unrelated fields.
- [x] All routes noindex/no-store, absent from sitemap/structured data/service worker/public search.
- [x] RU/EN exact parity, locale-safe navigation/state, responsive Tailwind layout, keyboard/screen-reader/reduced-motion contracts are implemented; final browser matrix is recorded below.
- [x] Query-count regressions, grouped aggregates, bounded pagination/projection and attachment/provider exclusion protect large datasets.
- [x] Focused integration tests preserve public portal, free/premium/player/import/search/SEO/rights restrictions/account lifecycle; final full suite is recorded below.

## 9. Requirement-compliance matrix

| Requirement | Source requirement file | Implementation location | Validation method | Status | Notes |
| --- | --- | --- | --- | --- | --- |
| Permanent read workflow/no-memory/main/commit/push/report | `AGENTS.md` | `AGENTS.md`, requirements registry, this plan | Full reread, link scan, Git status/remote evidence | `completed` | Permanent workflow added and reread; final commit/push/report remain separately unresolved below. |
| Canonical registry/read order/precedence | `docs/requirements/index.md` | Requirement owners/index | Table/link inspection | `completed` | Existing owners registered, linked and reread; duplicate canonical files were not created. |
| Existing production portal/backward compatibility/no fake capability | `docs/CODE_STANDARDS.md` | Admin compatibility gates, existing routes/components, capability registry | Diff, routes, regression tests, browser/manual smoke | `completed` | Existing 12 feature routes preserved; absent domains have no fake routes/actions. |
| Passive Blade/typed layers/server trust/Livewire payload | `docs/architecture.md` | Admin enums/DTOs/queries/actions/Livewire/shared Blade | Static scans, Blade compilation, security regression | `completed` | No Volt, `@php`, direct model/service/container calls, inline CSS/business JS or complete model graphs in canonical admin Blade. |
| Before/during/final workflow | `docs/development.md` | This plan and final evidence | Checklist comparison | `completed` | Preparation, discoveries and implementation evidence maintained; final delivery evidence appended below. |
| Multilingual all locales/no translated identity | `docs/requirements/multilingual-requirements.md` | `lang/ru|en/administration.php`, stable enums | Exact recursive parity/order/placeholders and enum label test | `completed` | RU/EN are the two supported locales; every role/permission/audit action has a key. |
| Security/privacy/least privilege/no secret leakage | `docs/security.md` | Middleware, resolver, policies, actions, safe DTOs/export | Security tests/static scans/route inspection | `completed` | Sensitive permissions separated; user/source/audit/operations projections omit secrets/private data. |
| Bounded queries/pagination/index evidence | `docs/performance.md` | Dashboard/navigation/user/audit queries + migrations | Query-count/pagination/index inspection | `completed` | Query budgets pass; deterministic bounded lists and indexes match authorization/audit query patterns. |
| Private cache/targeted invalidation | `docs/caching.md` | `PrivateAccountResponse`, cache version action, resolver invalidation | Cache/header/service-worker tests | `completed` | No admin HTML/data global cache; only allowlisted domain version invalidation. |
| Responsive/accessibility/state completeness | `docs/UI_STANDARDS.md`, `docs/frontend.md` | Shared admin navigation/table/filter/state/confirmation views | Build + Blade + browser/manual | `completed` | Responsive/keyboard/focus/announcements/scroll/state semantics share current Tailwind system. |
| One canonical administration/RBAC/audit/bulk | `docs/administration.md`, `docs/authorization.md` | Admin enums/schema/resolver/registry/actions/components | Feature/policy/route/schema tests | `completed` | One route group/navigation/resolver/audit store; bounded bulk contract does not add unsafe fake bulk mutations. |
| Noindex/no sitemap/no public cache | `docs/administration.md`, `docs/security.md`, SEO owners | Admin middleware/responders/sitemap | Route/header/sitemap/service-worker tests | `already_compliant` | Current routes use `account.private`; must preserve for new group. |
| Reuse auth and deny inactive administrators | Task 26 + Task 15 owners | Admin membership/account middleware + canonical auth services | Auth/session/status feature tests | `completed` | Unverified, suspended/revoked/expired and account-blocked access denied; optional mobile bypass closed. |
| Stable roles/permissions/final superadmin/audited changes | Task 26 + administration owner | Stable enums/schema/resolver/actions | Migration/authorization/invariant tests | `completed` | 14 roles, 60 permissions, assign-only-possessed, recent auth, confirmation and final-super lock. |
| Real permission-scoped dashboard/grouped badges | Task 26 + performance owner | Dashboard query/navigation registry | Query-count/failure-isolation/UI tests | `completed` | Real grouped aggregates; no one query per card/item and failures isolate. |
| Shared tables/filters/pagination/bulk/confirmation | Task 26 + UI/admin owners | Shared components + `AdminTableState`/bulk DTO | Feature tests/Blade/manual | `completed` | Safe primitives implemented; no unsafe all-record bulk action or fake control. |
| Safe audit/internal notes/search/exports | Task 26 + security/admin owners | Existing audit + domain notes + bounded CSV/SQL search | Pagination/redaction/CSV/IDOR tests | `completed` | Notes remain domain-private; export max 1000 and formula-safe; no secrets/raw values. |
| User/account restriction/merge/delete integration | Task 26 + Task 15/16 owners | User directory/restriction actions + existing lifecycle | Authorization/lifecycle regression tests | `completed` | Restrictions complete; existing export/delete reused; merge truthfully unavailable until reconciliation domain exists. |
| Content/moderation/support/help/calendar/recommendation/premium integration | Feature owners + Task 26 | Existing domain services + shared shell/permission facade | Focused regressions and routes | `completed` | Existing screens preserved; catalog/profile permissions narrowed and recipient selection centralized. |
| Advertiser/rights-holder/payment/search-index truthfulness | Security/admin requirements | Capability registry/no dead routes | Registry/config/route/UI inspection | `already_compliant` | Domains/providers absent; no controls currently. |
| Safe operations/cache/SEO/redirect/settings/health | Operations/SEO/cache owners | Real capability registry, targeted cache/SQL-search actions | Permission/secret/redaction/action tests | `completed` | Truthful health/capability summary; absent redirect/settings/flags/log/provider orchestration has no fake controls. |
| Documentation/changelog/README/current plan | Root owners | Canonical owners, audit, plan, README, CHANGELOG | Docs refresh/link/diff inspection | `completed` | Owners reread; write refresh and read-only docs/link/migration check return exit `0`. |
| Final full verification/commit/push | `AGENTS.md`, `docs/development.md` | Main worktree/remote | Test/build/browser/diff/Git evidence | `completed` | Full PHPUnit/build/cache/browser/static gates passed; `eb4e7f9e7dcf300328b35c527f65a39a743c2ebe` committed the implementation and mandatory pre-push published the containing HEAD to `origin/main`. |

Implementation statuses above reflect completed focused verification; final full-suite/browser/delivery evidence is appended only after those commands finish.

## 10. Обязательная 35-позиционная итоговая запись Task 26

1. Название: Task 26 — каноническая администрация, moderation и operational control.
2. Scope: requirement normalization, RBAC, shared admin routes/navigation/dashboard/tables/filters/states, user restrictions, audit, content/moderation/support/premium integration и truthful operations.
3. Дата: 19–20.07.2026.
4. Ветка: только existing `main`; branch/worktree не создавались.
5. Requirement files read: root `AGENTS.md` и все owners в order из `docs/requirements/index.md`; полный inventory — 320 Markdown / 67 617 строк.
6. Relevant architecture files read: owner map и auth/catalog/import/player/collections/comments/reviews/profiles/requests/tickets/help/calendar/recommendations/premium/SEO/operations/deployment owners.
7. Current administration architecture: 17 stable routes, shared middleware/resolver/navigation/layout, 14 roles и 60 permissions.
8. Discovered admin routes: исходные 12 сохранены; добавлены `admin.index`, `admin.users`, `admin.access`, `admin.audit`, `admin.operations`.
9. Discovered roles: исходно persistent roles отсутствовали; финальный stable registry содержит только 14 документированных operational roles.
10. Discovered permissions: исходные 13 email gates сохранены compatibility aliases; финальный registry содержит 60 section/action sensitivity-classified codes.
11. Discovered dashboards: исходно общей dashboard не было; финальная `/admin` использует real grouped permission-scoped aggregates и isolated failures.
12. Discovered moderation queues: comments, reviews, profiles, collections, requests, tickets и tags интегрированы без duplicate routes/services.
13. Discovered content-management pages: catalog titles/relations/seasons/episodes/media, collections, tags, help translations и calendar preserved.
14. Discovered configuration pages: unrestricted settings editor отсутствовал и не создан; typed domain config остаётся repository-owned.
15. Discovered audit systems: existing admin audit плюс auth/Premium/request/ticket/help/calendar/import histories; existing store расширен, histories не копируются.
16. Discovered system health: importer summary и canonical readiness service; operations показывает только truthful capability/readiness projections.
17. Duplicate/conflicting implementations: ручное меню и repeated email gates normalized registry/resolver; legacy gates оставлены thin compatibility facade.
18. Security risks: over-broad legacy access, inactive staff, final-super removal, IDOR/CSV/XSS/SSRF/path/open-redirect/secret exposure addressed by permissions/actions/DTOs/validation; absent domains fail closed.
19. Privacy risks: directory/audit/operations projections omit hashes, tokens, sessions, raw IP, private histories, notes, diagnostics, attachments, legal/payment/provider data.
20. Performance risks: million-row domains use deterministic bounded pagination/projection/grouped aggregates; query-budget regressions pass.
21. Multilingual risks: RU/EN exact recursive key/order/placeholder parity and every role/permission/audit label verified; identities remain stable codes.
22. Compatibility risks: existing route names, policies/actions, canonical user auth, public URLs/cache/search/SEO/sitemap/player/import/Premium behavior preserved through additive adapters.
23. Database risks: five additive SQLite-compatible migrations, justified authorization/audit indexes, unique memberships, FK strategy and no production migration execution during task.
24. Migration plan: backup/preflight, migrate, code/assets/workers, post-deploy route/security/public smoke; rollback code first while preserving additive evidence tables until dependant review.
25. Cache plan: no public/private admin HTML caching, request-scoped permissions, targeted domain version invalidation, no arbitrary keys/full flush.
26. Translation plan: one paired `administration.php` catalog, parity test, no translated identities, existing locale preserved through Livewire.
27. Authorization plan: canonical resolver + action gates + policies, active/verified account, recent auth/confirmation, assign-only-possessed, final-super protection and legacy compatibility.
28. UI/accessibility plan: shared responsive navigation/table/filter/state/confirmation, focus/keyboard/touch/announcements/long-label/overflow support.
29. Documentation plan: canonical owners, architecture audit, design/implementation plan, README и Russian changelog updated without duplicate owner files.
30. Files expected to change: focused Admin enums/DTOs/models/services/actions/middleware/Livewire/views/routes/migrations/translations/tests and canonical docs listed by Git diff.
31. Files expected to remain compatible: all public/API/feature routes and domain schemas/services outside additive integration, plus configured database/cache/session/queue behavior.
32. Implementation phases: requirement gate, audit/design, TDD RBAC, shared shell, domain integration, operations, hardening and verification completed; delivery recorded below.
33. Manual acceptance checklist: 16 routes rendered at 1440×1200, 390×844 и 768×1024; 48 checks and 3 diagnostic sets passed after fixing audit-filter overflow.
34. Completion state per requirement: matrix above and grouped 161-point acceptance ledger below use only `completed`, `already_compliant`, `not_applicable` or `unresolved`.
35. Final commit reference: implementation `eb4e7f9e7dcf300328b35c527f65a39a743c2ebe`; verified published containing HEAD before this closure update: `3bd5e5637f89a46a56e714d0e9987a7e8e10b40a`.

## 11. Final 161-point acceptance ledger

| Items | Status | Evidence / honest limitation |
| --- | --- | --- |
| 1–19 — Markdown audit, canonical owners, read order/precedence, no-memory rule and task matrix | `completed` | 320 Markdown/67 617 lines, 366 links, owners normalized/reused and reread before implementation. |
| 20–25 — one architecture, normalized routes, noindex/sitemap/public-cache exclusion | `completed` | One `/admin` group/registry/resolver; route/header/sitemap/cache tests and 48 browser route checks. |
| 26–28 — Task 15 auth reuse and inactive/deleted/suspended denial | `completed` | Canonical web identity/session; verified+active middleware; membership/account restriction regressions. |
| 29–32 — stable untranslated role/permission identities | `completed` | Enums/DB codes + RU/EN labels; parity test covers every code. |
| 33–40 — least privilege, superadministrator, audited role changes, filtered navigation | `completed` | Action permissions, sensitive exclusions, final-super lock, recent auth, one resolved permission set. |
| 41–44 — real dashboard, permission scoping and isolated widget failures | `completed` | Grouped aggregates, no fake metrics/private summary and explicit failure-isolation tests. |
| 45–54 — shared tables/filters/pagination/bulk contract/internal notes/audit secrecy | `completed` | Bounded state/components/selection DTO; no unsafe bulk mutation; domain notes private; audit allowlists only. |
| 55–60 — safe user administration and account restrictions including social/mobile bypass | `completed` | Public IDs/masked email, no credential fields, restriction/session/token/optional-Sanctum tests. |
| 61–62 — account merge/delete reuse | `already_compliant` | Existing Task 15 export/delete reused; merge remains unavailable because proof/OAuth/billing/legal reconciliation domain does not exist and no fake button was added. |
| 63–70 — catalog/serial/season/episode/source/translation/subtitle administration | `completed` | Existing catalog hierarchy/source/editorial translation flows integrated; action permissions narrowed and source URLs remain protected. |
| 71–77 — moderation/request/ticket/help/calendar/recommendations/premium integration | `completed` | Existing domain pages/routes/services preserved behind stable permissions; focused integration tests pass. |
| 78–82 — advertiser/rights-holder/billing/legal sensitivity | `not_applicable` | Advertiser/rights-holder schemas and payment provider are absent; no fake routes. Premium/billing summary remains separately permissioned; legal document codes are excluded from superadministrator. |
| 83–89 — notifications/import/cache administration | `completed` | Existing notifications/importer reused; targeted cache domains only, no arbitrary access/full flush. |
| 90–91 — truthful search-index administration | `completed` | Real SQL index state and one-resource rebuild only; external index marked unavailable, never simulated. |
| 92–96 — SEO/redirect/settings/secret/feature-flag controls | `not_applicable` | Admin noindex/sitemap exclusion works; generic redirect/settings/flags stores do not exist, so dangerous editors and security-bypass flags are absent. |
| 97–100 — truthful health/no fake checks/restricted logs/path safety | `completed` | Readiness/capability registry catches partial failures; raw log/file browser is intentionally absent, eliminating path traversal surface. |
| 101–106 — permission-aware search and protected/formula-safe exports | `completed` | User/audit search is scoped/bounded; audit CSV permission/max 1000/formula escaping/no secrets; DB dump/document export absent. |
| 107–112 — responsive/tablet/table/keyboard/screen-reader/drag alternatives | `completed` | Shared semantics plus desktop/mobile/tablet browser matrix; no drag-only action. One mobile overflow found and fixed. |
| 113–115 — no global private caching and authorization invalidation | `completed` | Private no-store middleware, request-scoped resolver and explicit forget after membership mutation. |
| 116–124 — query/index performance | `completed` | Query budgets, grouped navigation/dashboard, bounded pagination/audit, projections and five justified index sets; provider/list binary calls absent. |
| 125–126 — safe legacy administrator migration | `completed` | Narrow runtime compatibility adapter preserves old scope; no blind assignment to sensitive roles and no new writes to legacy config identity. |
| 127–137 — authorization, CSRF, IDOR, mass assignment, XSS, SSRF, path/open redirect, secret/private-cache leakage | `completed` | Route/action/policy tests, allowlisted validated actions/URLs/fields, escaped passive Blade, private responses and static security scans. |
| 138–141 — all locale labels/parity/raw-key/hydration behavior | `completed` | Supported `ru|en` exact parity and route/browser rendering; stable locale and no identity from label. |
| 142–144 — loading/empty/error states | `completed` | Shared states plus query/widget/operations partial-failure tests and browser empty-state rendering. |
| 145–149 — no Volt/`@php`/Blade business calls/inline CSS/large JS | `completed` | Repository static admin regression and compiled Blade; Vite-managed existing JS/Tailwind only. |
| 150–153 — relevant docs, requirements, plan and changelog | `completed` | Owners/audit/README/changelog updated and reread; write refresh, read-only docs/link/migration check and diff checks pass. |
| 154–159 — existing/free/Premium/advertiser/rights-holder/unrelated compatibility | `completed` | Full PHPUnit 1410 tests/122 864 assertions passed; absent advertiser/rights-holder domains remain absent rather than broken/faked. |
| 160–161 — commit to existing `main` and push configured remote | `completed` | Implementation commit `eb4e7f9e7dcf300328b35c527f65a39a743c2ebe` created on `main`; mandatory pre-push passed and `origin/main` was verified at containing commit `3bd5e5637f89a46a56e714d0e9987a7e8e10b40a`. |

---

# Текущая задача — единый UX Livewire pagination islands

Updated: 20.07.2026

Status: implementation и доступная verification завершены; commit/push заблокированы общим перекрывающимся dirty index.

## Цель и план discovery

- [x] Прочитать канонический порядок требований и UI/frontend/production/maintenance/integration boundaries.
- [x] Найти все backend paginator calls, Livewire `WithPagination`, Blade `links()` и существующие scroll/loading hooks.
- [x] Проверить точный Livewire 4 contract для named paginators, custom scroll targets и `@island` по установленному package source и официальной документации.
- [x] Уточнить единый UX-контракт: pagination click всегда обозначает обновляемый region и корректирует его позицию, а reduced motion делает переход мгновенным.
- [x] Согласовать architecture для одного shared pagination view/runtime и feature-scoped islands без duplicate JavaScript.
- [x] После approval записать design/implementation plans, выполнить TDD, integration/browser verification, owner docs, README и русский `CHANGELOG.md`.

Финальный inventory на текущем snapshot: `40` Blade-шаблонов содержат `54` вызова `links()`, включая три paginator административного модуля, появившиеся во время общего прогона. Каждый вызов передаёт уникальный region, находится в `x-ui.pagination-region` и именованном class-based Livewire island. Published view сохраняет progressive-enhancement `href`/`rel` и named actions; один Vite runtime управляет локальным spinner, immediate/post-morph scroll, failure cleanup, reduced motion и фактической геометрией шапки.

Rollback: изменение должно оставаться presentation-only. Откат возвращает shared pagination Blade/runtime/styles, island wrappers и tests/docs одним scoped revert; query, URL page names, database, cache, session, authorization, SEO и routes не меняются. Новые dependencies, migrations, `.env` или production services не допускаются.

## Compliance matrix

| Требование | Статус | Evidence |
| --- | --- | --- |
| Полный pagination inventory и отсутствие duplicate runtime | `completed` | Repository-wide `paginate`/`links()`/`WithPagination`/scroll/loading scan; найден существующий shared Livewire view и один Vite runtime. |
| Livewire 4 class-based islands и supported pagination API | `completed` | Установленный `Livewire 4.3.3` source и официальные `4.x` pagination/islands contracts подтверждают named page state, selector scroll targets и `wire:island`; Volt не требуется. |
| Responsive header offset, extra breathing room и reduced motion | `completed` | Runtime измеряет computed `sticky|fixed` `[data-site-header]`, переводит CSS gap `1rem` в фактические CSS pixels через root font size, использует bounded `520–820 ms` easing и мгновенный reduced-motion fallback; desktop/mobile/tablet browser geometry подтверждена. |
| Каждый paginator обновляет только собственный island | `completed` | Inventory contract покрывает `40` templates / `54` links, уникальные regions и prepared `with`; каталог отдельно использует вложенный `catalog-pagination`, multi-paginator views имеют независимые names. |
| Truthful spinner, aria-busy/live feedback и current content preservation | `completed` | Общий region сохраняет content, локально выставляет `aria-busy`, показывает translated `role=status` spinner и очищает state при success/failure/navigation; delayed Livewire browser scenario подтверждён. |
| URL/back-forward, locale, SEO, search/filter state | `already_compliant` | Существующие named page parameters и `WithPagination` остаются источником state; query/page names и routes не планируется менять. |
| Authentication, authorization, privacy, premium, regional/legal access | `already_compliant` | Presentation isolation не меняет queries, policies, gates, entitlement или private/public cache boundaries. |
| Cache, notifications, administration, imports и database | `not_applicable` | UI runtime/island scope не требует invalidation, writers, migrations или queue/provider actions. |
| Mobile/tablet/desktop, keyboard, zoom и browser behavior | `completed` | Pagination scenario прошёл на `1440×1200`, `390×844` и `768×1024`; отдельный desktop context подтвердил `prefers-reduced-motion: reduce`, локальный spinner, итоговую геометрию, отсутствие overflow и browser/network errors. |
| Owner docs, README, CHANGELOG, verification и delivery | `unresolved` | Owner docs, README и русский CHANGELOG обновлены; docs/Pint/frontend/build/focused browser gates прошли. Полный PHPUnit и Playwright выполнены с описанными ниже unrelated transient/order-dependent ошибками; commit/push небезопасны в общем dirty index. |

Cross-feature impact: affected — all public/private/admin Livewire lists with pagination, shared header geometry, Vite runtime, loading accessibility and URL history. Unaffected by design — database/schema, domain queries, authorization, privacy, premium/payment, region/legal restrictions, notifications, search semantics, cache identities, imports, APIs, sitemaps and public routes. SEO `rel=prev/next`, no-JavaScript `href` fallback and localized visible labels must be preserved.

Final evidence: inventory contract — `40` templates / `54` links, `6` tests / `325` assertions; focused catalog/library group — `118` passed до шести unrelated catalog-admin `public_id` errors; повторный полный PHPUnit — `1 364` tests, `1 346` passed, `120 421` assertions, `11` skipped и `7` order-dependent errors в параллельно изменённом admin audit/catalog selection. Один такой backend test отдельно прошёл. Production Chromium выявил, что прежний допуск скрывал преобразование `1rem` в `1px` через `parseFloat`: ужесточённый RED получил отклонение `15 px`, минимальная конвертация через root font size дала GREEN. Финальная проверка дополнительно воспроизвела ложный RED при ровно четырёх объединённых browser scroll events и немедленный GREEN того же кода в одиночном повторе; нестабильная привязка к FPS заменена проверкой реальной промежуточной позиции, длительности не менее `500 ms` и точной конечной геометрии. После изменения полная targeted matrix на актуальном snapshot — `4 passed`, `2` expected skipped. Desktop/mobile/tablet screenshots подтверждают отсутствие переполнения, отдельный reduced-motion scenario — мгновенную коррекцию. Production desktop/mobile Livewire вернул `200`, показал spinner, сменил карточки и страницу на `2`, не дал console/page/request errors и точно расположил region `148/148` и `16/16 px`. Полный browser run ранее дал `39 passed`, `4` expected skipped и два desktop title/player transient failures, оба отдельно прошли (`1 passed` каждый). `Pint` по всем затронутым Livewire-классам, `npm audit`, Vite build (`23` modules), Blade cache, docs-refresh check и `git diff --check` прошли. Ни один pagination runtime contract не падал. Повторный read-only HTTPS smoke после завершения maintenance window подтвердил `200` для `/titles`, `/titles?page=2` и scoped-каталога, опубликованные region/loading/control markers и время ответа `0,14–8,76 s`; последний `503` для `/titles/*` в доступном access log датирован `19.07.2026 23:19:07 +03:00`.

---

# Текущая задача — восстановление `/titles` после HTTP 503

Updated: 19.07.2026

## Цель и план инцидента

- [x] Подтвердить внешний HTTP 503, Laravel maintenance state и отсутствие более узкой ошибки `/titles`.
- [x] Проверить владельца maintenance mode по полному списку активных Artisan/deployment/migration/test процессов и не открывать трафик во время записи.
- [x] Сопоставить повторный `503` с `demo:repair-user-portal`, вернуть maintenance mode до окончания writer window и выполнить `php artisan up` только после безопасного завершения roll-forward.
- [x] Проверить `/up`, `/`, `/titles`, заголовок страницы, assets/console и mobile/desktop HTTP behavior.
- [x] Повторно сверить требования, README, legacy/stale maintenance paths, обновить evidence и русский `CHANGELOG.md`; commit/push разрешённых изменений выполнить только из `main`, если shared worktree позволяет безопасно отделить их.

Rollback: если снятие maintenance mode обнаружит unsafe migration/deploy state или application error, немедленно вернуть `php artisan down --refresh=15`, сохранить evidence без изменения БД/кеша и продолжить диагностику по журналу. Backup не требуется для `up/down`, поскольку эти команды не меняют domain data или schema; текущая SQLite и persistent storage остаются нетронутыми.

## Compliance matrix

| Требование | Статус | Evidence |
| --- | --- | --- |
| Root cause подтверждён до исправления | `completed` | Внешний `/titles` возвращал `503` с `Retry-After`, `/up` — `200`; `storage/framework/down` и `php artisan about` подтвердили Laravel maintenance mode. Повторное появление marker сопоставлено с активной подтверждённой `demo:repair-user-portal`, а не с route/query ошибкой. |
| Public route `/titles` и существующие URL contracts сохранены | `completed` | После завершения repair roll-forward `/titles` вернул `200`; desktop/mobile Chromium подтвердил прежние URL, title/H1 «Все сериалы онлайн», один `<main>`, assets и нулевые console/page/local-request errors. |
| Database, migrations, imports, storage и provider state | `completed` | Снятие maintenance mode само не меняло данные. Отдельная подтверждённая repair-команда имела проверенный закрытый backup; первый bulk-проход остановился на конкурентном SQLite lock, partial `quick_check` прошёл, затем ограниченный roll-forward завершил записи, все пять audit counters стали нулевыми, а итоговые quick/foreign-key checks подтвердили целостность. Schema, dependencies, `.env` и provider state не менялись. |
| Authentication, authorization, privacy, premium, region/legal boundaries | `already_compliant` | Снятие глобального maintenance state не меняет policies, identity или entitlement; authenticated flows отдельно не мутируются. |
| Translations, search, SEO, sitemap, notifications, administration, mobile | `already_compliant` | Код и contracts этих доменов не менялись; публичная русская страница, search/filter shell, title/H1 и desktop/mobile layout проверены без overflow. |
| Cache/session/queue и failure recovery | `completed` | Store-wide clear/retry не выполнялись. После repair восстановлены `crond`, четыре import-, восемь title-refresh- и один cache-warm worker; `app:health --json` подтвердил `ready=true`, database и критические Redis cache/session/queue/lock роли. Недоступный Memcached и запаздывающий heartbeat очереди прогрева не являлись причиной `503`. |
| Dependency/runtime/schema change | `not_applicable` | Package, lock, `.env`, migration и schema change не планируются. |
| README и тематические owner docs | `completed` | Обновлены visitor history, журнал обслуживания, план/compliance evidence и отдельная русская запись `CHANGELOG.md`; управляемые блоки не редактировались вручную. |
| Verification, CHANGELOG, commit/push | `unresolved` | Runtime HTTP/browser verification и `project:docs-refresh --check` завершены успешно. Commit/push небезопасны: общий `main` опережает remote и содержит многочисленные чужие staged/unstaged изменения, которые нельзя включать в фиксацию этого инцидента. |

Cross-feature impact: production availability публичных routes `affected`; application behavior, authentication/authorization, translations, search/filter semantics, SEO/sitemap shape, notifications, administration, imports, premium/payments, regional/legal access, privacy, mobile layout and audit `unaffected` unless investigation shows a separate application defect. Cache/session/queue health is `affected evidence only`; no flush, retry, migration or writer action is authorized by this incident repair.

Итоговое evidence: узкий первоначальный process scan пропустил активную `demo:repair-user-portal`, поэтому трафик был временно открыт внутри writer window; после точной трассировки maintenance mode немедленно восстановлен до завершения безопасного roll-forward. Marker отсутствует, `crond` и все 13 workers активны, повторные `/up`, `/`, `/titles` и `/calendar` вернули `200`. Desktop `1440×1200` и mobile `390×844` smoke `/titles` прошли без horizontal overflow, console/page errors или неудачных first-party запросов. Отдельные прежние health degradations сохранены без расширения scope.

---

# Текущая задача — demo user portal, owner cache и WebP media

Updated: 19.07.2026

Полный design и пошаговый план: [`../superpowers/specs/2026-07-19-demo-user-portal-cache-and-media-design.md`](../superpowers/specs/2026-07-19-demo-user-portal-cache-and-media-design.md) и [`../superpowers/plans/2026-07-19-demo-user-portal-cache-and-media.md`](../superpowers/plans/2026-07-19-demo-user-portal-cache-and-media.md).

| Требование | Статус | Evidence |
| --- | --- | --- |
| Requests/library/tags заполнены штатным demo seed | `completed` | stage/auditor/PortalDemoSeeder tests; production repair создал 633 заявки, итоговый owner audit равен нулю |
| Profile и collection images доступны по responder-compatible WebP paths | `completed` | stage/media tests; production repair и desktop/mobile HTTPS smoke без битых изображений |
| Owner-scoped cache и automatic background recache | `completed` | version invalidation, unique job, single/multi-user command tests; `--all-demo --refresh` поставил 100 owners в очередь, worker journal подтверждает последовательные `DONE` |
| Security/session/token/notification action state не кэшируется | `already_compliant` | bounded array/ID projections и existing private response middleware |
| Profile upload WebP conversion и design resize | `completed` | actual MIME/pixel checks и 320×320/1280×360 assertions |
| Новые migrations/dependencies | `not_applicable` | schema и Composer/npm inventory не изменены |
| Production data repair | `completed` | Проверенный закрытый backup и writer window использованы; после конкурентного SQLite lock выполнен ограниченный roll-forward, все шесть audit counters равны нулю, повторный force-run стал no-op, итоговые integrity checks прошли |
| Focused/build/docs/browser verification | `completed` | Свежий неизменный task snapshot: 39 тестов и 6 473 утверждения; targeted Pint/PHPStan, Vite build, managed docs/diff checks и desktop/mobile HTTPS navigation прошли; production watchlist-query сократился с 7 353 до 915 мс без ослабления visibility |
| Full suite/commit/push | `unresolved` | основной task baseline находится в `861fe37`; follow-up no-op guard, exact `--all-demo` allowlist и missing collection/cover audit остаются в общем staged/unstaged дереве других активных задач. Последний полный прогон на движущемся admin/importer snapshot дал 1 365 успешных тестов и 122 255 утверждений, но 3 failure и 6 error в одновременно переписываемом admin contract (`403` и отсутствующий `selectTitle`), поэтому не считается финальным. Попытка push была остановлена обязательным guard из-за dirty worktree и не дошла до проверки remote credentials |

Cross-feature review охватывает authentication, authorization, translations, cache/queue, search/SEO, notifications, privacy, mobile/Livewire, administration, imports, premium/rights, public routes, storage, deployment, backup и rollback. Никакая access decision, session, token, exact progress или signed media identity не перенесена в cache.

---

# Task 15 — canonical registration, authentication and session architecture

> Параллельная задача объединения discovery/collections не смешивается с этим планом; её полный plan, compliance matrix и verification evidence находятся в [`discovery-collections-admin-unification.md`](discovery-collections-admin-unification.md).

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

---

# Параллельная ограниченная задача: непустой стартовый календарь релизов

Обновлено: 19.07.2026

Статус: реализация завершена; финальная доставка заблокирована активными параллельными изменениями общего рабочего дерева.

## Решение и evidence

- [`../superpowers/specs/2026-07-19-calendar-default-recent-design.md`](../superpowers/specs/2026-07-19-calendar-default-recent-design.md);
- [`../superpowers/plans/2026-07-19-calendar-default-recent.md`](../superpowers/plans/2026-07-19-calendar-default-recent.md).
- Рабочая база содержит 4 342 публичных фактических события за последние 60 дней и ноль подтверждённых будущих событий в пределах года: пустоту создавал только стартовый `upcoming` route.
- `/calendar` переведён на bounded recent, `/calendar/upcoming` оставлен для будущих событий, а recent-адреса получили постоянные перенаправления.
- Во время финальной проверки календарный HTTP-набор получил `503` из-за намеренного maintenance window активной `demo:repair-user-portal`, а не из-за route/query изменений. Узкий первоначальный process scan не включал эту команду и трафик был временно открыт внутри writer window; после точной трассировки maintenance mode восстановлен до окончания безопасного roll-forward. Затем marker удалён, `crond` и все 13 workers запущены, `/up` и `/calendar` вернули `200`, стартовая страница показала 24 записи, а направленный календарный набор прошёл 27 тестов и 202 утверждения.

## Compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Requirements/read order и links | completed | Прочитаны index, calendar, UI, cache, SEO, maintenance, production и integration owners; ссылки плана проверены |
| Root cause/data safety | completed | Read-only census; импорт, schema, migrations, storage и рабочие строки не менялись |
| Routes/SEO/sitemap/cache | completed | Canonical index/upcoming, redirects, shared visibility, `noindex` пустого окна, cache profile и explicit shared-cache route allowlist покрыты направленными тестами |
| Auth/privacy/premium/region/legal | already_compliant | Канонический `ReleaseScheduleVisibility` и personal boundary сохранены |
| Notifications/translations/mobile | completed | Общие links/notifications ведут на index; future-specific links сохранены; RU/EN и desktop/mobile проверены |
| Dependencies/runtime/build | not_applicable | Новых packages/runtime changes нет; Vite build прошёл |
| Focused tests/Pint | completed | После route-safety RED/GREEN свежий результат: 27 tests, 202 assertions; explicit Pint и `git diff --check` прошли |
| Full suite | unresolved | Зафиксированный в этой задаче полный run на общем изменяемом snapshot: 1 304 tests, 1 276 passed, 17 failures и 11 skipped; календарные 27 tests проходят, а оставшиеся failures относятся к незавершённым параллельным Help/player/Blade/cache/infrastructure изменениям. Более поздний чужой полный run завершился без доступного этой задаче summary |
| README/CHANGELOG/canonical docs | completed | Русские записи и release-calendar owner обновлены; managed blocks вручную не менялись |
| Legacy scan | completed | Остаточные `calendar.upcoming` относятся только к future-specific consumers; duplicate recent content отсутствует |
| Commit/push | unresolved | Другие задачи многократно stash-ят дерево и меняют dependency/cache/demo files; их поглощение запрещено |

## Production impact и rollback

- Доставка code/routes/docs-only; после deploy нужна обычная безопасная компиляция config/route/view cache без store-wide flush.
- Rollback возвращает прежний route mapping и consumers; database/storage recovery не требуется.
- Повторный `503` диагностируется проверкой `storage/framework/down` и полного списка активных Artisan/deploy/migration/test процессов; возвращать приложение через `php artisan up` допустимо только при отсутствии владельца maintenance window, после чего обязательны `/up` и целевой HTTP smoke.

---

# Параллельная ограниченная задача: realtime-поиск актёров и режиссёров

Обновлено: 19.07.2026

Статус: реализация и направленная verification завершены; task-only доставка ожидает прекращения параллельных записей в общем рабочем дереве.

## Решение и evidence

- [`../superpowers/specs/2026-07-19-catalog-people-live-search-spinner-design.md`](../superpowers/specs/2026-07-19-catalog-people-live-search-spinner-design.md);
- [`../superpowers/plans/2026-07-19-catalog-people-live-search-spinner.md`](../superpowers/plans/2026-07-19-catalog-people-live-search-spinner.md).
- Root cause подтверждён в Chromium: нелайерный `FontAwesome display` перекрывал layered `Tailwind hidden`, поэтому idle-иконка фактически оставалась видимой и анимированной.
- Web-поиск людей теперь принадлежит `CatalogSeries::$optionSearch`; `wire:model.live.debounce.300ms`, grouped `catalog-live` islands и точный `wire:loading.delay` target обновляют варианты и фильмы без второго browser `fetch`.

## Compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Requirements/read order и links | completed | Канонический индекс, maintenance, production, search, forms, frontend и UI owners прочитаны; план и design сохранены отдельно |
| Root cause и input preservation | completed | Search input сохранён; CSS-cascade defect устранён wrapper-видимостью без удаления формы |
| Realtime Livewire/islands | completed | `optionSearch.actor|director`, debounce 300 мс, grouped `catalog-live` и realtime checkbox/URL state используют существующий `CatalogSeries` boundary |
| Spinner ownership | completed | Точный property `wire:target` реагирует только на поиск соответствующей группы и скрыт в idle |
| Public routes/API/backward compatibility | already_compliant | `/titles`, taxonomy/query URLs и read-only `GET /api/catalog/people` сохранены; migrations/schema/data отсутствуют |
| Auth/authorization/privacy/admin | already_compliant | Новых write routes, client-trusted access state, персональных данных и administrative controls нет |
| Cache/search/SEO/imports/notifications | already_compliant | Меняется только UI transport уже исключённых из full-response cache Livewire updates; canonical visibility и importer не затронуты |
| Premium/region/legal restrictions | already_compliant | Существующая server-side visibility остаётся единственным источником допустимых titles и people options |
| RU/EN/a11y/mobile/tablet | completed | RU/EN parity сохранён; label, 80-character bound, status live region и 44 px control сохранены; responsive browser matrix входит в финальную проверку |
| Dependencies/runtime/database | not_applicable | Packages, lock files, `.env`, migrations, storage, queue и persistent data не меняются |
| Production impact/rollback | completed | Code/assets-only deploy; rollback — revert task commit и предыдущий Vite manifest, без cache-store flush или data restore |
| Focused tests/Pint/build/browser | completed | Свежие результаты после последнего восстановления: 9 tests, 118 assertions; targeted Pint; Vite 23 modules; Chromium idle/active/final и changed result cards на 390 px, idle на 768/1440 px, без console errors и horizontal overflow |
| Broad catalog/visual classes | unresolved | Независимый прогон 112 tests / 948 assertions: оба новых regression-теста прошли; 10 прежних тестов падают в параллельно изменяемых directory/admin/cache/header/filter contracts вне task scope |
| Documentation/README/changelog | completed | Канонические owners и русские visitor/technical history дополнены без изменения managed blocks |
| Legacy/duplicate scan | completed | Production scan не нашёл old people combobox/fetch/loading identifiers или `fa-spinner fa-spin hidden`; controller/request/resource и API regression сохранили read-only endpoint |
| Commit/push | unresolved | Task-only commit возможен только без поглощения продолжающихся чужих изменений; configured push должен быть фактически проверен |

---

# Текущая задача: надёжность GitHub Actions

Обновлено: 19.07.2026

Статус: первоначальный и первый remote-only root causes воспроизведены и исправлены; локальные профили и направленные regressions зелёные, исправляющий commit/push и следующий remote run ожидают завершения общего документационного snapshot.

## Решение и evidence

- [`../superpowers/specs/2026-07-19-github-actions-reliability-design.md`](../superpowers/specs/2026-07-19-github-actions-reliability-design.md);
- [`../superpowers/plans/2026-07-19-github-actions-reliability.md`](../superpowers/plans/2026-07-19-github-actions-reliability.md).
- Публичный run `29567874996` на `190d0d30` воспроизведён в чистом snapshot: backend дошёл до `project:docs-refresh --check` и обнаружил stale `README.md`, `CODE_STANDARDS.md`, `UI_STANDARDS.md`, `DATA_RELATIONS.md`, `MAINTENANCE_LOG.md`.
- Предотвращение: общий read-only профиль `docs` в центральном CI script, его вызов до commit, exact runner label и immutable SHA существующих action major-версий без ослабления проверок; generated `bootstrap/cache` исключён только из source syntax lint и проверяется фактической сборкой Laravel cache.
- Локальный для CI maintenance driver `cache` со store `array` исключает ложные `503` от общего `storage/framework/down`, не изменяет marker и не выводит production из режима обслуживания.
- Полный Playwright gate после исправления component-scoped pagination cleanup завершён без ошибок: `41 passed`, `4 skipped` на desktop/mobile/tablet. Свежий backend-профиль прошёл Composer audit, Pint, Rector, PHP syntax, Larastan, docs/cache gates и PHPUnit: `1 419` tests, `1 408` passed, `11` skipped, `122 920` assertions. Свежий frontend-профиль подтвердил `npm audit` без уязвимостей и Vite build из `23` modules.
- Pre-push commit `3bd5e56` повторно прошёл `1 424` tests / `122 939` assertions, frontend audit/build и был опубликован. Remote run №213 на его родительском snapshot выявил два пропуска локального окружения: stale readiness assertion и production default Unix-группу внутри fake uploads; job log извлечён через настроенную read-only Git-аутентификацию. Оба сценария получили GREEN, а workflow дополнительно закрепил явный `gd` в PHP jobs.

## Compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Requirements/read order и links | completed | Прочитаны index, maintenance, production operations, system integration, CI/development/testing owners; design/plan links созданы |
| Remote diagnosis/root cause | completed | Публичные runs/jobs проверены; последний remote SHA воспроизведён exact backend profile; failure локализован после прошедших Composer/Pint/Rector/syntax/Larastan gates |
| TDD regression | completed | Первые RED/GREEN дали 24 tests / 469 assertions; remote №213 добавил RED public readiness contract и Unix-group drift, а explicit `gd` contract прошёл отдельный RED/GREEN. Три новых направленных теста завершились 35 assertions |
| Dependencies/runtime compatibility | already_compliant | Composer/npm/PHP/Node/action majors не обновляются; фиксируются используемые action commits, GA Ubuntu 24.04 label и уже обязательное для raster flows расширение `gd` |
| Production/data/rollback | not_applicable | Нет migration, database/storage/cache/session/queue/provider/service-worker изменения; rollback — revert CI/hook/docs commit |
| Auth/authorization/translations/search/SEO/notifications/admin/imports/premium/region/legal/mobile | already_compliant | Application/public contracts и данные не меняются; затронут только development/CI boundary |
| Security/least privilege | completed | Сохранены `contents: read`; planned immutable action SHA и `persist-credentials: false`; secrets/config environment не меняются |
| Canonical docs/README/CHANGELOG | completed | CI/development/runtime/update-decision/frontend/search/performance owners, русские README/CHANGELOG и task evidence обновлены; `ci-check.sh docs` проходил после штатной синхронизации |
| Full backend/frontend/browser | completed | Последний pre-push backend: 1 424 tests / 1 413 passed / 11 skipped / 122 939 assertions и все static/docs/cache gates; frontend: audit 0 и Vite 23 modules; browser: 41 passed, 4 expected skipped |
| Git push/new Actions run | unresolved | `3bd5e56` опубликован; run №213 дал два разобранных backend failures, run №214 выполняет прежний snapshot. Исправления ещё не опубликованы, поэтому следующий remote run обязателен; внешняя доступность никогда не гарантируется |

## Production impact и rollback

- Deployment/runtime сервиса не меняются; backup, migration, cache flush и worker restart не требуются.
- При внешнем outage GitHub/npm/Composer job остаётся честно красным и повторяется после восстановления; ошибки не маскируются.
- Rollback возвращает предыдущие workflow refs/runner и удаляет локальный docs gate одним Git revert без восстановления данных.

---

# Параллельная ограниченная задача: прогрев видимых страниц тайтлов

Обновлено: 19.07.2026

Статус: implementation, production index rollout, rolling runtime activation, full-suite verification и Git delivery основного product snapshot завершены; после final review закрыты retry/version-store/locale defects, повторно измерен cold-path и зафиксирован live fan-out после восстановления importer lifecycle. Post-delivery исправление coalescing общего `WarmCatalogCaches` находится в TDD: production census показал, что прямой `Bus::dispatch()` обходил `ShouldBeUniqueUntilProcessing` и создавал тяжёлый backlog перед отдельными title jobs. Отдельный evidence follow-up остаётся в общем параллельно изменяемом docs snapshot.

## Решение и evidence

- [`../superpowers/specs/2026-07-19-visible-title-cache-warming-and-cold-page-performance-design.md`](../superpowers/specs/2026-07-19-visible-title-cache-warming-and-cold-page-performance-design.md);
- [`../superpowers/plans/2026-07-19-visible-title-cache-warming-and-cold-page-performance.md`](../superpowers/plans/2026-07-19-visible-title-cache-warming-and-cold-page-performance.md).
- `/titles` сохраняет bounded ID реально показанных карточек в versioned guest payload и после успешного `MISS`, `HIT`, `STALE` или Livewire response dispatch-ит отдельный unique job только для `missing/stale` canonical title cache.
- Cold title SQL ограничен текущим `catalog_title_id`; после rehearsal на disposable copy целевая migration применена к production SQLite через отдельную проверенную backup/write-pause границу.
- Первый внешний smoke: `/titles` вернул `HIT` за 0,107 s, `/titles/ierrohierro` — cold `MISS` за 1,252 s и следующий `HIT` за 0,071 s. Повторный smoke при активной нагрузке дал соответственно 0,653 s, cold `MISS` 3,024 s и `HIT` 0,374 s. Поздний read-only smoke во время длительного importer/finalization backlog честно дал `/titles` `STALE` 2,720 s, `/titles/ierrohierro` cold `MISS` 12,531 s и последующие `HIT` 1,383/0,753 s; переход `MISS→HIT` подтверждён, постоянный cold SLA не заявляется.
- `cache-warm-v2` worker автоматически обновился в 00:20 без ручного restart. Read-only Redis inspection подтвердил rolling contract: 175 ready `WarmCatalogTitlePage` уже имеют `maxTries=0` и absolute `retryUntil`, 74 legacy payload сохраняют `maxTries=3`/`retryUntil=null`, как документировано; queue rewrite/clear не выполнялись.
- Stale run `#944` штатно завершился после bounded recommendation handoff: checkpoint удалён, `last_recommendations.mode=deferred`, dirty IDs сохранены, `SeasonvarImportActivity=false`. После снятия паузы Redis показал 373 отдельных ready `WarmCatalogTitlePage`, что подтвердило сохранность fan-out. Новый контролируемый sitemap-tail run `#954` сразу после этого снова активировал ожидаемую import-pause; queue clear/rewrite и обход SQLite-защиты не выполнялись.
- Во время run `#954` read-only census в 02:36–02:40 показал рост ready `WarmCatalogCaches` с 121 до 186 при одном worker, тогда как 405 title jobs оставались сохранены. Data-flow trace локализовал причину в `CatalogCacheInvalidator::dispatchWarm()`: прямой `Bus::dispatch()` не проходит pending-dispatch unique-lock acquisition. Выбран минимальный rollback-safe fix — framework `WarmCatalogCaches::dispatch()`; отдельная очередь, новый worker и destructive cleanup отклонены как не устраняющие источник.
- Обязательный legacy scan нашёл тот же bypass в `HdRezkaCollectionSyncService` для `RebuildCatalogRecommendationsAfterCollectionSync`. Существующий сценарий трёх последовательных material changes дал RED `3 jobs`, после типизированного pending dispatch — GREEN `1 job`; оба исправления сохраняют прежние queue names, after-commit, retry, dirty-state и overlap boundaries.

## Compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Requirements/read order и cross-feature review | completed | Прочитаны canonical cache, performance, importer, maintenance и production owners; auth/privacy/SEO/mobile/admin/premium/region/legal contracts проверены |
| Видимые карточки и cache HIT | completed | Bounded metadata работает на catalog `MISS/HIT/STALE` и Livewire update; 96 — hard cap, текущая страница прогревает каждый реально показанный unique ID |
| Fresh/stale/missing/outage | completed | Authoritative exact-state API: fresh завершает job без HTTP, stale/missing выполняют один target, outage даёт bounded release |
| Import/queue concurrency | completed | Title payload contract завершён. RED доказал две общие jobs вместо одной и три recommendation jobs вместо одной; типизированные pending dispatch восстановили оба `ShouldBeUniqueUntilProcessing` lock. Cache/import и collection-sync GREEN-наборы прошли 49 tests / 319 assertions без queue rewrite/clear |
| Visibility/privacy/access | already_compliant | Job повторно применяет guest `availableTo(null)`; payload содержит только positive integer ID и не переносит session/query/source URL |
| Cold SQL/index/data safety | completed | Query-shape regressions, HTTP query budget, disposable rehearsal и production covering `EXPLAIN`; повторный five-process median 1 394,4 ms HTTP / 1 075,0 ms SQL даёт 56,8% improvement от 2 490 ms baseline и выполняет только предусмотренный fallback >=50%, а absolute SQL ceilings честно не заявляются |
| Focused verification | completed | После retry/version/locale исправлений и end-to-end warm→HIT regression расширенный cache/route/query snapshot прошёл 115 tests / 1 040 assertions. Coalescing follow-up прошёл RED `2→1` general jobs и `3→1` recommendation jobs; итоговые cache/import и collection-sync наборы прошли 49/49 / 319 assertions |
| Full repository suite | completed | Актуальный общий snapshot после обоих coalescing fixes прошёл 1 427 tests: 1 416 passed, 11 expected skipped, 122 945 assertions. Targeted Pint, PHP syntax, Catalog invalidator PHPStan, managed docs и staged/unstaged diff checks зелёные; расширенный прямой PHPStan старого HDRezka service/test сохранил 6 ранее существующих findings вне изменённых dispatch/assertion строк |
| README/CHANGELOG/canonical docs | completed | Русские visitor/technical entries, cache/performance/environment/deployment owners и rollback дополнены; managed blocks проверены штатной командой |
| Production rollout | unresolved | Owner-only backup, migration batch 30, covering query plan и title payload rollout завершены. Run `#954` корректно включает import-pause, но live census выявил отдельный источник общего backlog: ready `WarmCatalogCaches` выросли 121→186 из-за bypass unique dispatch. Исправление должно быть опубликовано и подтверждено повторным census; queue rewrite/clear не выполняются |
| Commit/push | unresolved | Основной product snapshot с cache-реализацией уже опубликован в `main` как `eb4e7f9`. Новый live-evidence follow-up нельзя отделить на уровне path: тот же `current-task-plan.md` снова содержит параллельные admin/Livewire closure edits; отдельный commit захватил бы чужой scope |

## Production impact и rollback

- Быстрый application rollback: `CACHE_VISIBLE_TITLE_WARM_ENABLED=false`, config rebuild и graceful worker/PHP reload без `cache:clear` или `queue:clear`.
- Индекс additive и может безопасно остаться при rollback кода; его снятие выполняется только в отдельном backup/writer-pause DDL window.

---

# Task 29 — повторный аудит постоянной maintenance architecture

Обновлено: 19.07.2026

Статус: повторный аудит и доступная verification завершены без изменения package manifests или lock-файлов. Каноническая система Task 29 реализована commit `fa4d09f503d717fc737955902585737f34cf713a`; повторный аудит после последующих repository changes исправил bounded риск architectural drift в конфигурации генератора Livewire, закрыл production debug blocker и завершил доступный browser/deployment smoke. Согласованный implementation snapshot опубликован из существующей `main` commit-ом `eb4e7f9e7dcf300328b35c527f65a39a743c2ebe` без force push.

## Phase zero evidence

- Ветка: существующая `main`; implementation commit `eb4e7f9e7dcf300328b35c527f65a39a743c2ebe` и последующий CI-doc commit `3bd5e5637f89a46a56e714d0e9987a7e8e10b40a` опубликованы в `origin/main`.
- Git status: согласованный накопленный snapshot доставлен без branch/worktree, stash/reset, force push или потери чужих изменений; оставшиеся документационные уточнения входят в завершающий follow-up commit этой задачи.
- Прочитаны `AGENTS.md`, `docs/requirements/index.md`, все указанные им canonical owners, все maintenance registries/checklists, production runbooks, architecture/implementation maps, `README.md`, `CHANGELOG.md` и current plan.
- 189 project Markdown files прочитаны byte-for-byte; SHA-256 manifest проверен агрегированным digest `ddb10e27b30ce2afa27116228a3174e15e4411c9515d421ac492d0459102d19d`. Все 104 локальные ссылки requirement index существуют.
- `docs/requirements/maintenance-and-upgrades.md`, conflict precedence и permanent root rules уже соответствуют Task 29. Финальная сверка нормализовала literal 15-step read order в requirement index и собрала распределённые production runtime/package checks в явный enforcement block без создания duplicate owner.
- Автоматизированные тесты не создаются и не запускаются по явной политике текущей задачи. Разрешённые evidence: статический анализ, package/config/route/schema inspection, dependency tooling, production build и безопасный ручной browser smoke.

## Обязательная 68-позиционная инвентаризация

| № | Поле | Исходное состояние / действие |
| ---: | --- | --- |
| 1 | Task title | Повторный аудит постоянной maintenance architecture Task 29. |
| 2 | Task date | 19.07.2026. |
| 3 | Current branch | `main`; новая ветка/worktree запрещены. |
| 4 | Git status | `main` implementation snapshot published through `eb4e7f9e`; `origin/main` confirmed, documentation follow-up in progress. |
| 5 | Requirement files read | `completed`: полный index read order и все 189 Markdown files. |
| 6 | Requirement files updated | `completed`: maintenance owner и остальные domain rules уже каноничны; index read order и production upgrade enforcement уточнены, current plan reread/updated. |
| 7 | Maintenance documentation found | `completed`: canonical requirement, 7 registries/inventories и 5 checklists найдены без дублей. |
| 8 | Dependency files found | `composer.json`, `composer.lock`, `package.json`, `package-lock.json`; повторно проверить после phase zero. |
| 9 | Lock files found | Composer lock и npm lock format v3; hash/diff preservation обязательны. |
| 10 | Installed Laravel version | Existing registry: `13.20.0`; повторно подтвердить exact lock/tooling. |
| 11 | Installed Livewire version | Existing registry: `4.3.3`; повторно подтвердить. |
| 12 | Installed Tailwind version | Existing registry: `4.3.2`; повторно подтвердить compiler/plugin parity. |
| 13 | Flux packages and versions | Existing registry: отсутствуют; подтвердить package/source usage search. |
| 14 | Installed PHP requirement | Composer `^8.3`, production baseline `8.5`; local exact version требует повторной проверки. |
| 15 | Installed Node requirement | Docs currently specify Node 26; repository pin/engines отсутствует и остаётся risk review. |
| 16 | Package manager type/version | npm, lock v3; local exact version повторно снять без смены manager. |
| 17 | Vite version | Existing registry: `8.1.4`; повторно подтвердить lock/build. |
| 18 | Database packages | Framework PDO boundary, SQLite project-required, PDO MySQL optional; direct database package отсутствует. |
| 19 | Redis packages | Direct Composer client отсутствует; PHP Redis extension/runtime boundary проверить. |
| 20 | Memcached packages | Direct package отсутствует; PHP extension/runtime fallback boundary проверить. |
| 21 | Mail packages | Laravel/Symfony Mailer transitive; provider delivery не заявлять verified. |
| 22 | Payment packages | Existing registry: direct SDK отсутствует; provider boundary inactive until approved. |
| 23 | OAuth packages | Existing registry: account OAuth SDK отсутствует; analytics/search integrations не считать social OAuth. |
| 24 | Media packages | Direct npm `plyr`, `hls.js`; PHP Imagick/GD extensions; usage/runtime review pending. |
| 25 | Search packages | External direct package отсутствует; application Eloquent/SQLite FTS remains canonical. |
| 26 | Testing packages without running/changing tests | PHPUnit, Mockery, Faker, Playwright, axe-core и existing infrastructure только инспектируются. |
| 27 | Development-only packages | Existing Composer/npm dev registries перепроверить, включая Debugbar production guard. |
| 28 | Production-only packages | Five Composer runtime dependencies plus three shipped npm asset dependencies ожидаются; exact inventory pending. |
| 29 | Package auto-discovery | Composer manifest/providers pending repeat audit; no hidden production debug provider allowed. |
| 30 | Registered service providers | Existing application providers expected: App, API, SeasonvarQueue; repeat duplicate/binding/boot audit pending. |
| 31 | Aliases and facades | Config/package manifest/repository usage audit pending. |
| 32 | Middleware introduced by packages | Framework, Sanctum, Livewire and project middleware/order audit pending. |
| 33 | Routes introduced by packages | Sanctum/Livewire plus guarded Debugbar routes; exact route audit pending. |
| 34 | Commands introduced by packages | Package manifest/Artisan command audit pending; no browser execution. |
| 35 | Jobs and scheduler dependencies | Application jobs, pending serialization contracts and seven expected schedules pending repeat audit. |
| 36 | Current deprecation warnings | Existing `DEP-001` external npm config warning; repeat authoritative/tooling search pending. |
| 37 | Current compatibility adapters | Canonical adapter registry exists; repeat dependant/removal-condition audit pending. |
| 38 | Current abandoned packages | No prior Composer report; fresh verified tooling result required before any claim. |
| 39 | Current security advisories | Prior exact-lock audit was zero; fresh Composer/npm advisory commands required and remain dated evidence only. |
| 40 | Direct dependencies without documented purpose | Prior inventory documents every direct dependency; reconcile against current manifests/locks. |
| 41 | Duplicate packages with overlapping purpose | Prior audit found none requiring removal; repeat namespace/bundle/provider search pending. |
| 42 | Frontend bundle risks | Global FontAwesome plus lazy player chunks; build/manifest/chunk/source-map review pending. |
| 43 | Backend runtime risks | Node pin/LTS policy, Composer self-update keys, external production runtime evidence and static debt remain visible. |
| 44 | Production deployment risks | Shared dirty tree, locked install, PHP-FPM/OPcache, service ownership and external production evidence require review. |
| 45 | Database migration risks | No dependency migration planned; schema/driver/backup/rollback compatibility inspection pending. |
| 46 | Cache serialization risks | No format change planned; Redis/Memcached prefixes, serializers, stale fallback and version keys inspect only. |
| 47 | Session risks | No driver/key/cookie change planned; Redis/session/OAuth/payment-return compatibility inspect only. |
| 48 | Queue risks | No job shape change planned; pending jobs, retries, synchronous fallback and worker restart inspect only. |
| 49 | Service-worker risks | Existing registry says unsupported/absent; verify no registration/manifest/cache and preserve private-cache exclusion by absence. |
| 50 | Multilingual risks | RU/EN catalogs, PHP/JSON syntax, placeholders/pluralization/mail/validation/admin parity inspect; no identity translation. |
| 51 | Accessibility risks | Dependency UI unchanged unless evidence requires bounded fix; keyboard/focus/player/mobile/build output review pending. |
| 52 | Security risks | Advisories, auto-discovery, routes, middleware, telemetry, debug output, SSRF/upload/auth/payment boundaries pending. |
| 53 | Privacy risks | Package telemetry, debug collectors, caches, failed jobs, private files and browser bundle exposure pending. |
| 54 | Affected feature modules | All 28 compatibility domains classified below; documentation/tooling is affected, product modules expected unchanged. |
| 55 | Proposed update decisions | Default `retain`; update only with verified reason, official guidance, coherent group and rollback. |
| 56 | Packages retained with reasons | Every direct dependency will receive refreshed retain/update/replace/remove/review evidence. |
| 57 | Packages removed with reasons | None proposed before complete removal search; expected `not_applicable`. |
| 58 | Packages replaced with reasons | None proposed; framework-native replacement audit must justify any future proposal. |
| 59 | Compatibility plan | Preserve routes, identities, translations, auth, cache/session/jobs, player, providers, service worker absence and all 28 domains. |
| 60 | Rollback plan | Documentation-only changes revert without data action; any package/runtime/code change requires its own lock/config/assets/data/cache/session/job rollback. |
| 61 | Deployment plan | Existing locked Composer/npm install, asset build, migrations only if actually added, PHP-FPM/worker reload and health/manual checks; do not claim zero downtime. |
| 62 | Documentation plan | Refresh facts in existing requirements/maintenance/production owners, current plan, Russian changelog and README only when visitor state changes. |
| 63 | Files expected to change | Current plan and stale canonical maintenance evidence; no manifest/lock/application change unless a proven current correctness defect is safely fixed. |
| 64 | Files expected to remain compatible | All application code, public routes, schema/data, manifests/locks, assets and production contracts by default. |
| 65 | Compliance matrix | Initial matrix below; every row must be reclassified from actual evidence before closure. |
| 66 | Manual acceptance checklist | Affected-flow browser/static journeys only; unsupported/unavailable device/provider/production flows remain unresolved, never simulated as verified. |
| 67 | Unresolved limitations | Non-Chromium devices; authenticated/external-provider journeys; Memcached runtime; backup evidence for external batches 31–33; prohibited Task29 automated tests; loaded-browser latency tracked as `TD-011` (`/` `39.5 s`, first mobile `/titles` `52.0 s`). |
| 68 | Final commit reference | `completed`: canonical implementation `fa4d09f503d717fc737955902585737f34cf713a`; repeat audit/unified integration `eb4e7f9e7dcf300328b35c527f65a39a743c2ebe`, published to `origin/main`. |

## Cross-feature impact matrix

| Compatibility domain | Initial classification | Reason / required evidence |
| --- | --- | --- |
| Home, search, catalogue, filters | expected unaffected | No package/code update planned; inspect routes/query/cache and browser smoke only if host is stable. |
| Serial details, seasons, episodes, player | expected unaffected | Preserve Livewire class components, source authorization, Plyr/HLS lifecycle, subtitles/audio/quality/progress. |
| Progress/history, library, collections, tags | expected unaffected | Preserve owner scope, identities, cache isolation, queues and local-storage/session contracts. |
| Comments, reviews, profiles | expected unaffected | Preserve authorization, spoiler/privacy rules, localized UI and private response cache. |
| Authentication and settings | expected unaffected | Preserve web guard, Sanctum, CSRF, cookies, sessions, password/email verification and locale. |
| Calendar and recommendations | expected unaffected | Preserve route/event/cache identities, visibility restrictions and scheduled work. |
| Content requests, tickets, help center | expected unaffected | Preserve private attachments, permissions, notification locale and service-worker exclusion by absence. |
| Premium and payments | expected unaffected | Preserve exact money, server-side entitlement and inactive-provider honesty; no SDK exists. |
| Mobile and PWA | expected unaffected / PWA N/A | Preserve responsive/Livewire/API behavior; no service worker or installability claim. |
| Rights-holder cases and advertisers | expected unaffected | Preserve legal/region/privacy/script restrictions and private routes/files. |
| Administration | affected documentation only | Maintenance summary remains repository-backed/read-only; no Composer/npm/shell controls. |
| System-wide integration | affected documentation only | Reconcile providers, routes, events, commands, jobs, cache and production contracts. |
| Production operations | affected evidence only | Revalidate locks/runtime/runbooks/rollback without mutating services or data. |

## Финальная compliance matrix

| Requirement group | Status | Evidence / closure condition |
| --- | --- | --- |
| Canonical read order and permanent maintenance rules | `completed` | Root/requirements contain requested contracts; index now expresses the mandatory production→maintenance→feature→plan→implementation order as literal steps 11–15, and production owner has explicit runtime/package upgrade enforcement. |
| Dependency inventory and purpose registry | `completed` | Все 27 direct dependencies, exact locks, purpose, environment, licensing metadata, usage and package effects reconciled; package secrets отсутствуют. |
| Runtime compatibility matrix | `completed` | Matrix refreshed from exact local/tooling evidence; непроверенные MySQL/Redis/provider/browser states остались `unknown`/`requires review`. |
| Advisory, abandoned and outdated review | `completed` | Fresh exact-lock `composer audit` и `npm audit` дали zero advisories; Composer abandoned list empty; outdated candidates evaluated without updates. |
| Deprecations, adapters and technical debt | `completed` | Registries reread and refreshed; `DEP-001`, removal conditions и `TD-001..011` remain visible. `OP-001/003` закрыты; schema outcome `OP-002` закрыт внешними batches 31–33, но отсутствие доступного pre-migration backup evidence осталось честно отмечено. |
| Architecture drift | `completed` | Volt, `@php`, Blade DB/service/facade calls, inline styles, legacy Laravel structure, deprecated Livewire/Tailwind APIs, debug dumps and fake controls searched. Livewire generator drift fixed through `UD-LW-CFG-001`. |
| Package/runtime changes | `not_applicable` | No dependency/runtime update was justified; versions and locks preserved. Configuration-only class generator decision is `completed`. |
| Database/cache/session/queue/service-worker compatibility | `completed` | No Task29 format/key/job/service-worker change; 110 migrations inspected and all now `Ran`. External batches 31–33 passed available tombstone/index/FK and administration-schema read-only checks; PWA/service worker absent by design. |
| Multilingual/accessibility/security/privacy | `completed` | Static RU/EN parity is 4,744/4,744 keys with zero missing keys; administration placeholders were normalized to the same documented UTC format. No dependency telemetry/public endpoint was introduced by Task29. RU/EN and desktop/mobile representative browser behavior passed; non-Chromium devices and authenticated/provider writes remain unavailable verification. |
| Production/deployment/rollback documentation | `completed` | Existing locked-install/reload/rollback contracts refreshed. Effective production state is debug off/config cached/maintenance off; migrations are current, while missing accessible pre-batch-31 backup evidence remains explicit. |
| README and Russian changelog | `completed` | README reread: visitor capability/state did not change, so no fake visitor entry was added. Technical Russian changelog entry records the real audit and limitation. |
| Automated tests | `not_applicable` | Explicitly prohibited for Task 29; infrastructure remains untouched. |
| Static/build/browser verification | `completed` | PHP syntax, Pint, required Rector, Larastan, config/dependency/translation checks, repeat Vite build/manifest, managed docs check, production preflight и отдельный desktop/mobile managed-Chromium smoke прошли. Maximum Rector advisory coordinator не завершился и остаётся historical `TD-005`; required Rector прошёл и файлы не менял. |
| Commit and push on `main` | `completed` | Unified snapshot committed as `eb4e7f9e7dcf300328b35c527f65a39a743c2ebe` and delivered by non-force push; CI documentation follow-up `3bd5e5637f89a46a56e714d0e9987a7e8e10b40a` is also present on `origin/main`. |


## 233-позиционная final acceptance matrix

| № | Acceptance item | Status | Evidence / limitation |
| ---: | --- | --- | --- |
| 1 | Canonical requirement files read | `completed` | Полный read order и 189 Markdown files прочитаны. |
| 2 | Maintenance requirements created or updated | `already_compliant` | Canonical owner существует и перечитан. |
| 3 | Maintenance owner in requirement index | `completed` | Owner зарегистрирован шагом 12. |
| 4 | Root instructions require maintenance owner | `already_compliant` | Обязательная граница присутствует в AGENTS.md. |
| 5 | Root prohibits unjustified upgrades | `already_compliant` | Benefit-first rule присутствует. |
| 6 | Root requires compatibility review | `already_compliant` | Compatibility/impact/migration checklist обязателен. |
| 7 | Root requires rollback review | `already_compliant` | Rollback обязателен. |
| 8 | Root requires cross-feature verification | `already_compliant` | 28 domains защищены. |
| 9 | Project dependency-governance rules | `completed` | CODE_STANDARDS.md сверён. |
| 10 | Architecture third-party isolation | `completed` | Application-owned adapters/contracts закреплены. |
| 11 | Workflow update decision records | `completed` | 21-field record закреплён. |
| 12 | Multilingual upgrade verification | `completed` | RU/EN upgrade rules закреплены. |
| 13 | Security advisory workflow | `completed` | Evidence-only advisory policy закреплена. |
| 14 | Dependency performance-impact rules | `completed` | Query/payload/bundle/cache review закреплён. |
| 15 | Frontend upgrade accessibility rules | `completed` | Keyboard/focus/mobile rules закреплены. |
| 16 | Administration blocks arbitrary updates | `completed` | Composer/npm/shell/lock mutation запрещены. |
| 17 | Production runtime-upgrade rules | `completed` | Explicit runtime/package enforcement добавлен. |
| 18 | Current task plan updated | `completed` | Task 29 section and evidence current. |
| 19 | Compliance matrix updated | `completed` | Эта 233-row matrix и grouped matrix актуальны. |
| 20 | Composer dependencies inventoried | `completed` | 17 package dependencies плюс PHP platform requirement. |
| 21 | npm dependencies inventoried | `completed` | 10 direct npm dependencies. |
| 22 | Direct purposes documented | `completed` | Все manifest entries есть в inventory. |
| 23 | Runtime matrix exists | `completed` | Canonical runtime compatibility matrix refreshed. |
| 24 | Laravel version documented | `completed` | 13.20.0. |
| 25 | Livewire version documented | `completed` | 4.3.3. |
| 26 | Tailwind version documented | `completed` | 4.3.2. |
| 27 | Flux state documented | `completed` | Not installed/unsupported by design. |
| 28 | PHP requirement documented | `completed` | Composer ^8.3; host 8.5.8. |
| 29 | Node requirement documented | `completed` | Host/docs 26; LTS review deferred. |
| 30 | Package-manager strategy documented | `completed` | npm + lock v3; no manager switch. |
| 31 | Vite version documented | `completed` | 8.1.4. |
| 32 | Database compatibility documented | `completed` | SQLite required; other engines honest unknown/optional. |
| 33 | Redis compatibility documented | `completed` | Extension and workload boundaries documented. |
| 34 | Memcached compatibility documented | `completed` | Client present; server unavailable/degraded. |
| 35 | Browser support documented honestly | `completed` | Chromium evidence; non-Chromium remains unavailable. |
| 36 | Production compatibility documented | `completed` | Preflight/health/runtime limitations recorded. |
| 37 | Packages without purpose identified | `completed` | None among direct manifests. |
| 38 | Duplicate-purpose packages identified | `completed` | No justified removal candidate found. |
| 39 | Abandoned packages evidence | `completed` | Composer audit abandoned list empty. |
| 40 | Security advisories via tooling | `completed` | Composer/npm exact-lock audits zero. |
| 41 | Unsupported advisory claims absent | `completed` | Only dated tool evidence recorded. |
| 42 | No uncontrolled Composer update | `completed` | No update command or lock rewrite. |
| 43 | No npm audit force update | `completed` | Audit only. |
| 44 | Lock files preserved/reviewed | `completed` | Final hashes unchanged. |
| 45 | No unrelated lock changes | `completed` | Manifest/lock diff empty. |
| 46 | Laravel deprecations audited | `completed` | Bootstrap/middleware/routes/providers/casts/config searched. |
| 47 | Livewire deprecations audited | `completed` | Lifecycle/events/binding/navigation/public state searched. |
| 48 | Tailwind configuration audited | `completed` | CSS-first v4/content/build confirmed. |
| 49 | Flux compatibility audited | `not_applicable` | Flux/Pro packages and source usage absent. |
| 50 | Vite configuration audited | `completed` | Entry/manifest/chunks/maps/build inspected. |
| 51 | PHP compatibility audited | `completed` | 8.5.8 syntax/extensions/platform checks passed. |
| 52 | Database-driver compatibility audited | `completed` | PDO SQLite runtime and migration/query behavior reviewed. |
| 53 | Redis client compatibility audited | `completed` | Extension/prefix/serializer/timeout/fallback reviewed. |
| 54 | Memcached client compatibility audited | `completed` | Extension and unavailable hot-tier fallback reviewed. |
| 55 | Payment SDK compatibility audited | `not_applicable` | No direct SDK/provider activation. |
| 56 | OAuth SDK compatibility audited | `not_applicable` | No account OAuth SDK or callback routes. |
| 57 | Mail/notification compatibility audited | `completed` | Framework transport/locale/queue boundaries inspected. |
| 58 | Media package compatibility audited | `completed` | Plyr/HLS plus GD/Imagick boundaries inspected. |
| 59 | Service-worker compatibility audited | `not_applicable` | No registration/build/cache namespace exists. |
| 60 | Deprecation inventory exists | `completed` | Canonical registry exists. |
| 61 | Deprecations include locations | `completed` | DEP-001 includes source/tooling location. |
| 62 | Deprecations include replacement/limit | `completed` | Removal condition and limitation recorded. |
| 63 | Compatibility-adapter inventory exists | `completed` | Canonical registry exists. |
| 64 | Retained adapters have removal conditions | `completed` | Every retained record includes condition. |
| 65 | Technical-debt registry exists | `completed` | TD-001..010 and operational closures visible. |
| 66 | Mandatory work not hidden as debt | `completed` | Debug blocker fixed; batch evidence gap explicit. |
| 67 | Architecture drift audited | `completed` | Repository-wide forbidden-pattern scans completed. |
| 68 | No new Volt usage | `completed` | Packages/source/generator prevent Volt/SFC drift. |
| 69 | No new @php usage | `completed` | Repository scan zero. |
| 70 | No direct Blade model calls in scope | `completed` | Blade query/model scan zero. |
| 71 | No direct Blade service calls in scope | `completed` | Blade service/container scan zero. |
| 72 | No new inline CSS | `completed` | style/style-block scan zero. |
| 73 | No large inline business JavaScript | `completed` | Only prepared JSON-LD script remains. |
| 74 | No new hardcoded user-facing strings | `completed` | Changed Task 29 runtime/config scope adds none. |
| 75 | No translated identity values | `completed` | Stable codes/enums preserved. |
| 76 | No duplicate permission system | `completed` | Canonical policies/gates/admin registry preserved. |
| 77 | No duplicate audit system | `completed` | Existing audit boundaries preserved. |
| 78 | No duplicate notification system | `completed` | Existing notification categories preserved. |
| 79 | No duplicate cache system | `completed` | Existing Redis/Memcached responsibilities preserved. |
| 80 | No duplicate premium logic | `completed` | Premium resolver boundary unchanged. |
| 81 | No duplicate region logic | `completed` | Entitlement/availability boundary unchanged. |
| 82 | No duplicate legal restriction logic | `completed` | Existing legal boundaries unchanged. |
| 83 | No client-trusted permissions | `completed` | Server policies/gates remain authoritative. |
| 84 | No client-trusted premium | `completed` | Server entitlement remains authoritative. |
| 85 | No client-trusted region | `completed` | Server availability remains authoritative. |
| 86 | No client-trusted payment state | `completed` | Browser return is non-authoritative. |
| 87 | No fake controls | `completed` | No maintenance UI/control added. |
| 88 | No fake integrations | `completed` | Absent providers remain documented absent. |
| 89 | No fake maintenance data | `completed` | All states come from repository/tool evidence. |
| 90 | Update groups separated | `completed` | Only bounded Livewire config decision implemented. |
| 91 | Only justified updates implemented | `completed` | No dependency update; generator drift fix justified. |
| 92 | Deferred updates documented | `completed` | Patch/major/Node candidates recorded. |
| 93 | Implemented updates have decision records | `completed` | UD-LW-CFG-001 covers config change. |
| 94 | Retained dependencies have reasons | `completed` | All direct packages have retain decisions. |
| 95 | Removed packages complete checks | `not_applicable` | No package removed. |
| 96 | Replaced packages staged safely | `not_applicable` | No package replaced. |
| 97 | Package providers audited | `completed` | 13 auto-discovered providers inspected. |
| 98 | Package middleware audited | `completed` | Package/project middleware inventory inspected. |
| 99 | Package routes audited | `completed` | 242-route final inventory (`17` admin, `67` API including `/api` root); Debugbar 0. |
| 100 | Package commands audited | `completed` | Artisan/package command surface inspected. |
| 101 | Package jobs audited | `completed` | 13 application job classes/serialization reviewed. |
| 102 | Package assets audited | `completed` | Vite/npm assets and lazy chunks inspected. |
| 103 | Package environment variables audited | `completed` | 404 literal keys reconciled; values excluded. |
| 104 | Package production requirements audited | `completed` | Runtime matrix/runbooks updated. |
| 105 | Route compatibility preserved | `completed` | Final route inventory and browser smoke passed. |
| 106 | Public route names preserved | `completed` | No Task 29 route edit. |
| 107 | Localized routes preserved | `completed` | Existing RU/EN login/help/search routes returned 200. |
| 108 | OAuth callback routes preserved | `not_applicable` | No account OAuth callbacks exist. |
| 109 | Payment callback routes preserved | `completed` | Premium return/webhook route contracts unchanged. |
| 110 | Webhook routes preserved | `completed` | Existing billing webhook route unchanged. |
| 111 | Secure downloads preserved | `completed` | Signed/authenticated download route unchanged. |
| 112 | Database identities preserved | `completed` | No identity/schema rewrite by Task 29. |
| 113 | Status codes preserved/migrated safely | `completed` | Stable domain enums/codes unchanged. |
| 114 | Money fields exact | `completed` | No money/package/provider change. |
| 115 | SQLite compatibility preserved | `completed` | All migrations ran; quick/FK/index checks passed. |
| 116 | Production database compatibility preserved | `completed` | Configured SQLite preflight ready. |
| 117 | Cache serialization changes handled | `not_applicable` | No cache format/serializer change. |
| 118 | Stale-cache handling documented | `completed` | Versioned fallback/rollback rules retained. |
| 119 | Session compatibility reviewed | `completed` | Driver/serialization/cookies/key unchanged. |
| 120 | Application key unchanged | `completed` | No key rotation or value exposure. |
| 121 | Queue compatibility reviewed | `completed` | No payload/class change; backlog/heartbeat recorded. |
| 122 | Synchronous correctness preserved | `already_compliant` | Existing fallbacks unchanged. |
| 123 | Frontend asset build verified | `completed` | Vite production build passed. |
| 124 | Vite manifest verified | `completed` | 15 entries, zero missing assets/maps. |
| 125 | CSS classes preserved | `completed` | Tailwind build and representative pages passed. |
| 126 | Flux controls accessible | `not_applicable` | Flux absent; custom controls preserved. |
| 127 | Livewire navigation stable | `completed` | Livewire updates returned 200 in isolated smoke. |
| 128 | Player lifecycle stable | `completed` | Desktop/mobile player shell loaded cleanly. |
| 129 | Mobile navigation stable | `completed` | 390x844 representative smoke had no overflow/errors. |
| 130 | Service-worker private exclusions | `not_applicable` | No service worker/cache registrations. |
| 131 | Payment pages excluded from SW cache | `not_applicable` | No service worker. |
| 132 | Ticket pages excluded from SW cache | `not_applicable` | No service worker. |
| 133 | Legal cases excluded from SW cache | `not_applicable` | No service worker/legal-case product. |
| 134 | Advertiser dashboard excluded from SW cache | `not_applicable` | No service worker/advertiser product. |
| 135 | Administration excluded from SW cache | `not_applicable` | No service worker. |
| 136 | Every supported locale reviewed | `completed` | RU/EN parity and browser locale pages checked. |
| 137 | Translation syntax valid | `completed` | PHP catalogs loaded; JSON catalogs absent. |
| 138 | Placeholders compatible | `completed` | Zero RU/EN placeholder mismatch. |
| 139 | Pluralization compatible | `completed` | Catalog parity audit completed. |
| 140 | Validation messages localized | `completed` | Upgrade changed no validation API; catalogs preserved. |
| 141 | Notifications localized | `completed` | Locale contracts inspected; code unchanged. |
| 142 | Email localized | `completed` | User-locale mail contracts unchanged. |
| 143 | Administration localized | `completed` | RU/EN catalogs/static contract preserved. |
| 144 | Premium localized | `completed` | RU/EN page/browser evidence passed. |
| 145 | Advertiser interface localized | `not_applicable` | Advertiser product absent. |
| 146 | Rights-holder interface localized | `not_applicable` | Rights-holder product absent. |
| 147 | Operational interface localized | `completed` | RU visible operations and EN parity rules preserved. |
| 148 | No raw translation keys | `completed` | Static parity plus browser scan zero. |
| 149 | Security protections active | `completed` | Runtime/debug/routes/security scans passed. |
| 150 | CSRF active | `already_compliant` | Web middleware/forms unchanged. |
| 151 | Authorization server-side | `already_compliant` | Policies/gates/server resolvers unchanged. |
| 152 | IDOR protections preserved | `completed` | Bindings/owner policies unchanged. |
| 153 | Upload validation preserved | `completed` | No upload/package change. |
| 154 | SSRF protections preserved | `completed` | Provider URL allowlists/timeouts unchanged. |
| 155 | Open-redirect protections preserved | `completed` | Same-origin/protocol JS/server boundaries scanned. |
| 156 | Payment webhook signatures preserved | `already_compliant` | Server-authoritative contract unchanged; provider inactive. |
| 157 | Payment idempotency preserved | `already_compliant` | Ledger/reconciliation contract unchanged. |
| 158 | Advertiser scripts impossible | `not_applicable` | Advertiser integration/scripts absent. |
| 159 | Private files remain private | `completed` | No disk/public path change. |
| 160 | Logs contain no new secrets | `completed` | No secret values emitted or documented. |
| 161 | No unapproved dependency telemetry | `completed` | Package/JS scan found none. |
| 162 | Public JavaScript bundles reviewed | `completed` | Build sizes/chunks/maps reviewed. |
| 163 | No unnecessary provider calls | `completed` | No provider/package behavior change. |
| 164 | No one-query-per-card regression | `completed` | No query code change; representative paths passed. |
| 165 | No N+1 regression | `completed` | No query code change; prior query boundaries preserved. |
| 166 | No Livewire payload regression | `completed` | Config generator only; runtime requests 200. |
| 167 | No progress-write regression | `already_compliant` | Progress code/serialization unchanged. |
| 168 | No duplicate event listeners | `completed` | Listener/provider/JS scans found no new duplicate. |
| 169 | No duplicate timers | `completed` | No runtime JS timer change. |
| 170 | Production PHP compatibility reviewed | `completed` | CLI/FPM 8.5.8/platform requirements passed. |
| 171 | Production Node compatibility reviewed | `completed` | Node 26.4/npm12/Vite8 build passed; LTS deferred. |
| 172 | Production extensions reviewed | `completed` | Composer and application extension inventory checked. |
| 173 | Deployment runbook updated where needed | `completed` | Runtime/batch31/preflight evidence updated. |
| 174 | Rollback runbook updated where needed | `completed` | Config/data/cache/forward-fix boundaries recorded. |
| 175 | Backup requirements updated where needed | `completed` | Missing batch31 evidence explicit; future DDL fail-closed. |
| 176 | Service-worker deployment instructions updated | `already_compliant` | Absence/rollback contract remains canonical. |
| 177 | PHP-FPM/OPcache instructions updated | `completed` | Actual service reload recorded. |
| 178 | Health documentation updated | `completed` | Ready preflight and degraded health separated. |
| 179 | Package-upgrade rollback documented | `completed` | No package update; decision registry retains rollback rules. |
| 180 | Admin does not execute Composer | `already_compliant` | Explicitly prohibited; no control exists. |
| 181 | Admin does not execute npm | `already_compliant` | Explicitly prohibited; no control exists. |
| 182 | Admin hides package credentials | `already_compliant` | No maintenance package payload/UI exists. |
| 183 | Maintenance permissions least privilege | `already_compliant` | Operational roles required by canonical admin rules. |
| 184 | Maintenance audit events contain no secrets | `already_compliant` | Safe event contract documented; no fake event UI. |
| 185 | Dependency inventory linked | `completed` | Requirement index links canonical registry. |
| 186 | Compatibility matrix linked | `completed` | Requirement index links canonical registry. |
| 187 | Deprecation registry linked | `completed` | Requirement index links canonical registry. |
| 188 | Technical-debt registry linked | `completed` | Requirement index links canonical registry. |
| 189 | Update decision registry linked | `completed` | Requirement index links canonical registry. |
| 190 | Package-removal checklist exists | `completed` | Canonical checklist present. |
| 191 | Framework-upgrade checklist exists | `completed` | Canonical checklist present. |
| 192 | Frontend-upgrade checklist exists | `completed` | Canonical checklist present. |
| 193 | Production-compatibility checklist exists | `completed` | Canonical checklist present. |
| 194 | Maintenance-review checklist exists | `completed` | Canonical checklist present. |
| 195 | All affected portal modules reviewed | `completed` | 28-domain impact map completed; unaffected reasons recorded. |
| 196 | Home page operational | `completed` | Desktop/mobile `200`, H1, no errors/overflow; loaded run took `39.5 s`, retained as performance risk `TD-011`. |
| 197 | Search operational | `completed` | RU/EN and canonical search returned 200. |
| 198 | Catalogue operational | `completed` | Desktop/mobile `/titles` returned `200`; first loaded mobile run took `52.0 s`, retained as performance risk `TD-011`. |
| 199 | Filters operational | `already_compliant` | Catalog filter shell rendered; no runtime/API change. |
| 200 | Serial pages operational | `completed` | Representative title returned 200. |
| 201 | Season/episode pages operational | `already_compliant` | Routes/hierarchy unchanged; player resolved episode. |
| 202 | Player operational | `completed` | Video/player controls and Livewire 200 verified. |
| 203 | Progress/history operational | `already_compliant` | Authenticated write code unchanged; no unsafe live write. |
| 204 | Personal library operational | `completed` | Guest authorization redirects correctly; owner code unchanged. |
| 205 | Collections operational | `already_compliant` | Routes/services/cache contracts reviewed; no Task 29 change. |
| 206 | Tags operational | `already_compliant` | Canonical tag services/routes unchanged. |
| 207 | Comments operational | `completed` | Batch31 invariants/index/FK clean; routes unchanged. |
| 208 | Reviews operational | `completed` | Reason-specific tombstone invariants/FK clean. |
| 209 | Profiles operational | `completed` | Guest boundary correct; profile services unchanged. |
| 210 | Authentication operational | `completed` | Login/register/reset pages 200; private redirects correct. |
| 211 | Account settings operational | `completed` | Guest boundary correct; settings code unchanged. |
| 212 | Calendar operational | `completed` | Public calendar returned 200. |
| 213 | Recommendations operational | `already_compliant` | Queries/routes/cache unchanged and audited. |
| 214 | Content requests operational | `completed` | Public requests page returned 200. |
| 215 | Technical tickets operational | `already_compliant` | Routes/privacy/notification contracts unchanged. |
| 216 | Help center operational | `completed` | RU/EN desktop/mobile representative pages 200. |
| 217 | Premium/payments operational | `completed` | Premium page 200; payment provider remains intentionally inactive. |
| 218 | Mobile/PWA behavior operational | `completed` | Mobile smoke passed; PWA/service worker not installed by design. |
| 219 | Rights-holder cases operational | `not_applicable` | Product capability absent; legal boundaries preserved. |
| 220 | Advertiser system operational | `not_applicable` | Product capability absent; no fake integration. |
| 221 | Administration operational | `completed` | Guest guard/route inventory passed; authorized mutation not exercised. |
| 222 | Production operations documented/compatible | `completed` | Runtime/preflight/health/deploy/rollback current. |
| 223 | Free-user functionality operational | `completed` | Representative public catalog/help/premium flows passed. |
| 224 | Regional restrictions operational | `already_compliant` | Server entitlement/availability code unchanged. |
| 225 | Legal restrictions operational | `already_compliant` | Server legal boundaries unchanged. |
| 226 | Premium advertisement exclusion operational | `not_applicable` | No advertiser delivery system exists. |
| 227 | No unrelated feature broken | `completed` | Available static/build/preflight/browser evidence found no regression. |
| 228 | Relevant Markdown updated | `completed` | Canonical maintenance/production/audit/plan docs refreshed. |
| 229 | Canonical requirements updated | `completed` | Index and production owner normalized; others verified. |
| 230 | Current task plan honest | `completed` | Performed/unavailable/external evidence separated. |
| 231 | Unresolved limitations documented | `completed` | Backup evidence, degraded health, browser/provider coverage and loaded latency `TD-011` remain explicit. |
| 232 | Main changelog updated | `completed` | Russian entry added per higher-priority root contract. |
| 233 | Commit and push on main | `completed` | Implementation snapshot `eb4e7f9e7dcf300328b35c527f65a39a743c2ebe` and CI documentation follow-up `3bd5e5637f89a46a56e714d0e9987a7e8e10b40a` were pushed to `origin/main` without force. |


## Итоговая evidence выполненного аудита

- Exact runtime: PHP CLI/FPM `8.5.8`, Laravel `13.20.0`, Livewire `4.3.3`, Tailwind/Vite plugin `4.3.2`, Vite `8.1.4`, Composer `2.10.2`, Node `26.4.0`, npm `12.0.1`, SQLite `3.46.1`, nginx `1.31.2`; Flux/Volt packages отсутствуют.
- Dependency scope: 17 direct Composer и 10 direct npm dependencies. Locked graph содержит 79 Composer production packages, 46 development packages и 113 npm packages. Direct payment/account-OAuth/search/service-worker packages отсутствуют; Symfony Mailer используется транзитивно, Plyr/HLS и локальный FontAwesome — shipped browser assets.
- Lock preservation: финальная verification подтвердила `composer.json` `70225027e314536806b4a77b5cf2254a0d588881591ffcd93703eda1c838742b`, `composer.lock` `ab14b136d5d6ee946a527e6b98f6e853ac6af7fd8b4e3ada2e2405cfe5db440d`, `package.json` `6adee27d3e1489626b087b817be18465cbd4df35f177f5ba9997677cb16ac8c0`, `package-lock.json` `5eb0b0f227e6e26f79d61b12e787092364095154fb76d344dc200c97dc902274`; dependency-file diff отсутствует.
- Package tooling: `composer validate --strict`, `composer check-platform-reqs --lock`, locked Composer audit, npm audit, production Composer dry-run и npm locked dry-run прошли. Advisories и abandoned Composer packages не найдены. `composer diagnose` сохранил честный `TD-002` из-за отсутствующих self-update keys; npm сохранил `DEP-001` из-за внешнего deprecated `--init.module`.
- Outdated decisions: PHPUnit `13.2.4` и concurrently `10.0.3` — unrelated major groups и deferred; FontAwesome `7.3.1`, Tailwind/plugin `4.3.3`, Vite `8.1.5` — patch candidates без текущего security/correctness trigger и deferred. Ни один package не обновлён, удалён или заменён.
- Integration surface: 13 auto-discovered package providers, четыре aliases, три application providers; duplicate providers/listeners/middleware/route macros не найдены. Финальный source inventory содержит 242 routes (`17` admin, `67` API); production Debugbar routes `0`, публичные route names и guest/auth/admin boundaries сохранены.
- Operational surface: 165 Artisan commands, семь scheduler entries, 13 jobs, десять notifications, 14 policies, 15 middleware classes и 110 migrations inspected. Другой rollout применил comment/review repair/index migrations в batch 31 и пять administration migrations в batches 32–33; Task 29 их не запускала. Read-only verification: invalid removed comment/review tombstones `0`, expected index present, previously confirmed foreign-key violations `0`, expected administration tables/columns/indexes present, `60` permissions, `14` roles и `166` role-permission rows; premature assignments/restrictions/operational events отсутствуют.
- Config/environment: все 404 literal configuration environment keys присутствуют active/commented в `.env.example`; 14 example-only/dynamic/tooling keys документированы. Direct `env()` outside config, unexpected package telemetry, production Debugbar routes and Composer plugin permissions отсутствуют.
- Architecture drift: не найдены Volt/SFC/MFC components, `@php`, direct Blade DB/service/facade/container calls, inline `style`, debug dumps/console logging, removed Tailwind utilities, deprecated Livewire commit/request hooks или old Laravel Kernel/Handler structure. Один доказанный preventive defect — package default `make:livewire` SFC — исправлен явным `make_command.type=class` с выключенной генерацией JS/CSS/test.
- Frontend security scan: production JS/Blade не содержит `innerHTML`/`outerHTML` assignment, `insertAdjacentHTML`, `document.write`, string-to-code execution, inline event attributes, wildcard `postMessage`, browser-storage auth/token keys или remote script/link tags. Navigation sinks используют same-origin/protocol checks; `style-src 'unsafe-inline'` остаётся существующей явно ограниченной style policy, а `script-src` не получает `unsafe-inline`/`unsafe-eval`.
- Technical limits: legacy Russian-only operator/admin literals остаются tracked `TD-009`; broad modernization остаётся staged `TD-004/TD-005`; loaded public-page latency остаётся tracked `TD-011`; external production database/cache/provider and non-Chromium device evidence remains unavailable.

## Verification и manual acceptance

| Проверка | Статус | Фактический результат |
| --- | --- | --- |
| PHP syntax / targeted Pint | `completed` | 1,418 application/runtime PHP files в `app`, `bootstrap`, `config`, `database`, `routes` входят в финальный syntax gate; read-only Pint прошёл после пяти механических format corrections. |
| Required Rector / Larastan | `completed` | Required Rector changed `0` files/errors `0`; Larastan errors `0`. |
| Maximum Rector advisory | `unresolved` | Workers завершились, coordinators не дали output более шести минут и были безопасно остановлены; файлы не изменены, historical `TD-005` сохранён. |
| Dependency/config static checks | `completed` | Composer/npm exact-lock checks, platform checks, audits, provider/routes/schema/config inventory и effective Livewire config прошли. |
| Translation compatibility | `completed` | RU/EN: 4,744/4,744 keys, zero missing keys; administration UTC format placeholders normalized consistently; JSON catalogs отсутствуют. |
| Production asset build | `completed` | Vite `8.1.4`: 23 modules, 15 manifest items, один entry, zero missing assets, zero application source maps; CSS/JS chunks emitted successfully. |
| Managed documentation | `completed` | Финальный `php artisan project:docs-refresh --check` завершился `0`: managed blocks актуальны, write-refresh не потребовался. |
| Public/auth/admin browser journeys | `completed` | После завершения чужого runner отдельный managed-Chromium smoke без test runner проверил 19 desktop и 6 mobile public/auth/private/admin representative routes. Public pages `200`, guest private/admin paths redirect to login, RU/EN routes корректны, overflow/raw keys/console/page/first-party failures отсутствуют. `/` занял `39.5 s`, первый mobile `/titles` — `52.0 s`; риск сохранён как `TD-011`, а не скрыт успешным статусом. Изолированный title/player wait получил только Livewire `200`; service-worker registrations `0`. |
| Automated tests | `not_applicable` | По прямому запрету Task 29 PHPUnit/Playwright test runner не создавались и не запускались; test infrastructure не менялась этой задачей. |

Manual acceptance завершён для доступных guest journeys: home, catalogue/filter shell, search, title/player initialization, calendar, requests, help, Premium, login/register/password-reset pages, RU/EN login/help/search и guest redirects library/profile/settings/administration. Title shell содержит video, episode/start, playback, subtitles, quality, speed, fullscreen и autoplay controls на desktop/mobile без overflow; реальное media playback, authenticated writes, external payment/OAuth/provider, advertiser и rights-holder journeys не выполнялись и остаются `not_applicable` либо `unresolved` по отсутствию активных provider contracts/credentials, а не объявляются успешными.

## Production impact, rollback и delivery

- Единственное runtime-repository изменение — development-time Livewire generator config. Оно не меняет routes, schema, assets, cache/session serialization, queue payloads, public state или production requests. Rollback удаляет только `make_command` block; data/cache/session/job recovery не требуется.
- `OP-001` закрыт: environment-owned `.env` безопасно приведён к `APP_ENV=production`/`APP_DEBUG=false`, config/routes rebuilt, подтверждённый `php-fpm-85.service` и queue workers gracefully refreshed; production Debugbar routes `0`.
- `OP-002`: внешний rollout применил три additive migrations в batch 31 и пять administration migrations в batches 32–33; Task 29 их не запускала. Итоговые доступные data/index/FK/schema invariants чисты, но Task 29 не наблюдала pre-migration backup evidence этих batches и не заявляет его.
- `OP-003` закрыт после завершения владельца maintenance window: marker отсутствует, PHP-FPM active, home и `/up` возвращают `200`. Task 29 не открывала portal во время активной чужой записи.
- Финальный `app:health --json` остаётся честно `ready=true`/`degraded`: database и Redis roles доступны; import/title-refresh/cache-warm pools имеют status `ok`, cache warming — `running` с `failed=0`; Memcached недоступен и остаётся причиной degraded state. На момент снимка queues содержали 2103 pending и три reserved jobs; store-wide clear, queue deletion/retry или неподтверждённое масштабирование не выполнялись.
- Финальный `app:deployment-check --json` после внешних batches 32–33 завершился exit `0`/`ready`: environment, debug, logging, все 110 migrations, SQLite quick/FK, required indexes, FTS `32929/32929/32929` и cache transports прошли. SQLite integrity заняла `136028 ms` под нагрузкой; warnings по `32771` historical failed jobs и отсутствию отдельного подтверждённого forever-importer process сохранены для ручной operational disposition.
- Post-reload HTTPS probe: `/up` `200`/`0.70 s`, `/titles` `200`/`9.15 s`, guest `/admin` завершился на login `200`/`0.62 s`; `/` превысил 20-секундный timeout без тела. Это сохраняет performance-риск `TD-011` открытым, но не означает maintenance mode или failed readiness.
- Shared writers завершились. Финальная интеграция сохранила весь согласованный snapshot на `main`, не удаляла и не снимала со staging чужие изменения. Canonical Task 29 delivery — `fa4d09f503d717fc737955902585737f34cf713a`; repeat audit/config integration и единый согласованный snapshot опубликованы commit-ом `eb4e7f9e7dcf300328b35c527f65a39a743c2ebe`, CI evidence — follow-up commit-ом `3bd5e5637f89a46a56e714d0e9987a7e8e10b40a`.

---

# Финальная консолидация рабочего дерева, commit и push

Обновлено: 19.07.2026

Статус: завершено; все разрешённые изменения активных задач объединены в commit `eb4e7f9e7dcf300328b35c527f65a39a743c2ebe` на существующей ветке `main`, последующая документационная фиксация `3bd5e5637f89a46a56e714d0e9987a7e8e10b40a` прошла обязательный pre-push и опубликована в `origin/main`.

## Scope и стратегия доставки

- Пользователь явно разрешил исправлять обнаруженные ошибки, commit-ить весь код и отправить его в настроенный Git remote без конфликтов.
- Исторические recovery stashes сохраняются и не удаляются: они не входят в рабочее дерево и могут содержать независимые точки восстановления.
- Root-level диагностические screenshots не являются product assets и не должны попадать в Git; они сохраняются только внутри ignored `output/playwright/`.
- Package manifests, lock files, `.env`, database schema/data и production services этой консолидацией не меняются. Установка новых production dependencies, destructive database/cache/queue operations и изменение maintenance state не выполняются.
- Rollback application snapshot — обычный revert итогового commit; для затронутых code/assets/config/docs нет data restore, store-wide cache flush или queue clear. Изменения CI откатываются тем же revert, а внешний outage GitHub/npm/Composer повторяется после восстановления без маскировки ошибок.

## Cross-feature impact matrix

| Domain | Статус | Evidence / compatibility |
| --- | --- | --- |
| Authentication и sessions | affected | Browser auth/profile/library/logout contracts и offline-sync regression входят в final gates; guards, route identities и server-side session authority сохранены |
| Authorization и privacy | affected | Help/admin/player/user-portal boundaries остаются policy/server-resolved; private URLs, secrets и raw provider state не добавлены |
| Translations | affected | RU/EN architecture и stable identity rules сохранены; новый permanent multilingual owner перечитан, hardcoded/translated-identity drift входит в scan |
| Caching и performance | affected | Help schema/query, catalog projections, visible-title warm и import activity используют bounded queries/targeted invalidation; broad flush отсутствует |
| Search | affected | Catalog search rebuild/projections и portal suggestion budget проверяются focused/full tests; route/query identities сохранены |
| Notifications и audit | affected | Release calendar notifications и premium reconciliation используют существующие boundaries/codes; raw private payload не добавлен |
| SEO, sitemap и public routes | affected | OpenAPI/help suggestion contract, public cache safety и managed documentation links проверяются; canonical sitemap origin фиксирован независимо от test `APP_URL` |
| Mobile и accessibility | affected | Catalog/player/help/release-calendar Blade и Playwright mobile/tablet/desktop scenarios входят в browser gate |
| Administration | affected | Help center и release-calendar managers остаются class-based Livewire components с server-side permission boundaries |
| Imports | affected | Seasonvar import activity и catalog cache integration проверяются regression tests; публичная команда импорта и external URL boundary не меняются |
| Premium, regional и legal access | affected | Billing reconciliation изменён без новых provider/payment contracts; entitlement, region и legal decisions остаются server-side |
| Account lifecycle и demo repair | affected | Repair stages теперь идемпотентно пропускают уже compliant state; repeat-run regression проверяет отсутствие лишних jobs |
| Dependencies/runtime/database/storage/service worker | not_applicable | Manifests/locks/migrations/persistent data/runtime services/service worker не меняются |

## Compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Canonical read order и ссылки | completed | `AGENTS.md`, index, maintenance, production, multilingual, system-wide, development/CI и task owners перечитаны; repository links проверяет docs gate |
| Shared-worktree preservation | completed | Все разрешённые изменения сохранены; reset/checkout/drop-stash не использовались; branch остаётся существующей `main` |
| Error diagnosis и corrections | completed | Focused regressions закрыли исходные backend/browser/docs failures; deterministic `PROJECT_DOCS_PUBLIC_BASE_URL` защищён TDD-contract |
| Dependencies и production data safety | already_compliant | Package/lock/schema/data/environment не изменялись; production state и secrets не тронуты |
| Cross-feature compatibility | completed | Матрица выше покрывает authentication, authorization, translations, caching, search, notifications, SEO, privacy, mobile, administration, audit, imports, premium, region, legal и public routes |
| README, canonical docs и русский CHANGELOG | completed | Visitor-visible и technical изменения описаны; managed blocks обновлены штатной командой и проверяются read-only docs gate |
| Legacy/duplicate/conflict/temporary scan | completed | Финальный staged snapshot прошёл diff check, tracked-path guard, documentation checks и repository cleanup review без конфликтов или временных tracked-файлов |
| Focused/backend/frontend/browser verification | completed | Focused admin, полный backend, frontend/build, cache и browser checks завершились успешно; обязательный `scripts/ci-check.sh pre-push` вернул exit `0` на чистом опубликованном HEAD |
| Commit и push из `main` | completed | Implementation `eb4e7f9e7dcf300328b35c527f65a39a743c2ebe` и содержащий его HEAD `3bd5e5637f89a46a56e714d0e9987a7e8e10b40a` созданы только на `main` и подтверждены в `origin/main` |

---

# Livewire `wire:text` — мгновенный счётчик подборок

Обновлено: 19.07.2026

Статус: реализация, focused TDD, build и docs gates завершены; task-only commit/push заблокирован существующим общим staged snapshot.

## Scope и решение

- Официальный Livewire 4 contract применён только к локальной presentation-производной: длине deferred массива `selectedCollectionPublicIds` в title membership selector.
- Существующий `selectedCountLabel` остаётся локализованным SSR/no-JavaScript fallback. `wire:text` использует тот же перевод и не становится boundary авторизации, validation или persistence.
- `wire:model.live`, отдельный Alpine/JavaScript counter, route, API, migration, cache key, queue, dependency и production configuration не добавлены.
- `#[Async]` остаётся неприменимым: progress/restart/import и другие actions записывают значимое состояние или обновляют видимый component state, поэтому параллельное выполнение не включается.

## Cross-feature impact

| Domain | Статус | Evidence / compatibility |
| --- | --- | --- |
| Collections UI и accessibility | `completed` | `aria-live` и серверный fallback сохранены; локальный счётчик обновляется без запроса, apply/cancel semantics не меняются |
| Authorization, validation и privacy | `already_compliant` | UUID ownership, policy/query boundaries и `apply()` остаются server-side; private data в expression не добавлены |
| Translations | `completed` | Переиспользуется существующий `collections.membership.selected` для `ru`/`en`; identity values не переводятся |
| Caching, search, notifications, SEO, imports, premium, regional/legal access | `not_applicable` | Data flow, routes, indexed content, jobs, notifications и entitlement decisions не меняются |
| Mobile behavior | `already_compliant` | Существующий responsive dialog, touch targets и wrapping не меняются; добавлен только text binding |
| Production/data/rollback | `already_compliant` | Schema/data/config/assets entrypoints не меняются; rollback удаляет одну директиву и contract test |

## Compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Official version-specific Livewire guidance | `completed` | Повторно проверены Alpine-compatible expression, обновление text content без roundtrip и отсутствие modifiers; установлен `livewire/livewire v4.3.3` |
| TDD RED | `completed` | Test сначала исправлен до валидной owner-collection fixture, затем ожидаемо упал только из-за отсутствующего `wire:text` |
| Minimal implementation и GREEN | `completed` | Один text node сохранён; повторный inventory contract закрепляет ровно один target, deferred source и SSR fallback |
| Canonical docs, README и русский CHANGELOG | `completed` | Обновлены architecture/view/frontend owners, visitor history и технический журнал |
| Legacy/duplicate scan | `completed` | Competing `x-text`, `wire:model.live` для массива и application `#[Async]`/`.async` не найдены |
| Pint, focused tests, build и docs gate | `completed` | Pint прошёл; focused test: 1/5 assertions; CatalogCollection filter: 2/5 assertions; Vite: 23 modules; managed docs и diff checks прошли |
| Полный PHPUnit suite | `unresolved` | Первый запуск: 1,306 из 1,323 tests прошли, 11 skipped, 3 failures и 3 errors в параллельно создаваемых pagination/administration contracts. После появления недостававших `AdminRole` и `pagination-region` administration tests прошли; оставшийся несвязанный pagination failure во время точечных повторов переместился с `resources/views/catalog/titles.blade.php` на `resources/views/livewire/catalog-administration-manager.blade.php`, что подтверждает активный shared snapshot; wire:text focused tests зелёные |
| Commit/push только из `main` | `unresolved` | Общий index уже содержит несвязанные staged изменения, включая overlapping canonical docs; task-only commit нельзя создать без захвата или перестройки чужого staged snapshot |

---

# Livewire `wire:dirty` — draft состава подборок

Обновлено: 19.07.2026

Статус: RED/GREEN implementation, owner documentation и task-scoped verification завершены; task-only commit/push заблокирован существующим общим Git snapshot.

## Scope и ожидаемые файлы

- `resources/views/livewire/collections/catalog-collection-membership-manager.blade.php`: точный `wire:dirty` target для deferred membership draft.
- `lang/{ru,en}/collections.php`: parity key текстового accessible status.
- `tests/Feature/LivewireWireDirtyContractTest.php`: real-component RED/GREEN contract.
- Collection/frontend/view owners, `README.md`, `CHANGELOG.md`, design и implementation evidence.

## Совместимые contracts

`CatalogCollectionMembershipManager::openSelector()`, `closeSelector()`, `apply()`, `CatalogCollectionItemService`, policy/ownership resolution, route names, dialog lifecycle, deferred `wire:model`, `wire:text` count, translation architecture, cache identities, schema и public URLs остаются без изменений.

## Cross-feature impact и compliance matrix

| Требование / domain | Статус | Evidence / ограничение |
| --- | --- | --- |
| Canonical read order и official Livewire 4 guidance | `completed` | Проверены requirements owners, installed `livewire/livewire v4.3.3`, official `wire:dirty` contract и vendor runtime |
| Design и alternatives | `completed` | Выбран property-targeted indicator; help-editor/global и duplicate JS variants отклонены |
| Authentication, authorization, validation, privacy | `already_compliant` | Browser dirty state не доверяется; actor/title/membership повторно разрешаются в existing server boundary |
| Translations и accessibility | `completed` | Добавлен parity key `collections.membership.unsaved`; visible `role="status"`, `aria-live="polite"` и текст не полагаются только на цвет; focused test зелёный |
| Cache, search, SEO, sitemap, notifications, audit, imports | `not_applicable` | Reads, writes, indexed/public content, cache keys и events не меняются |
| Premium, payment, region, legal, advertiser, administration | `not_applicable` | Entitlement, financial, restriction и staff boundaries не меняются |
| Mobile/browser behavior | `completed` | Existing dialog, wrapping, focus и touch controls не меняются; status добавлен в existing flexible footer, Vite build прошёл: 23 modules |
| Database, dependencies, runtime, deployment, backup | `not_applicable` | Migration, package, config, persistent data и production service changes отсутствуют; rollback presentation-only |
| TDD и owner/visitor documentation | `completed` | Test сначала упал на отсутствующем `wire:dirty`, затем прошёл: 1 test / 5 assertions; architecture/frontend/views, README и CHANGELOG обновлены |
| Task-scoped verification | `completed` | Pint, два PHP syntax checks, RU/EN translation parity 248/248, dirty/text/collection focused suites, Vite build, `project:docs-refresh --check` и `git diff --check` прошли |
| Полный PHPUnit suite | `unresolved` | `php artisan test --compact`: 1 351 tests, 1 334 passed, 11 skipped, четыре assertion failures и один error. Каталожные сбои воспроизводятся в параллельно изменённых administration table/pagination-island contracts; `AdminUserDirectoryTest` отдельно проходит 2/2 с 16 assertions, что подтверждает order/shared-snapshot характер административного сбоя. Новый dirty test в полном suite не падал |
| Commit/push только из `main` | `unresolved` | Ветка `main` подтверждена, но общий index/worktree содержит чужие staged/unstaged изменения и overlapping README/CHANGELOG/docs/Blade; task-only commit нельзя создать без захвата либо перестройки shared snapshot |

---

# Livewire `wire:transition` — форма создания подборки

Обновлено: 20.07.2026

Статус: RED/GREEN implementation, owner documentation и task-scoped verification завершены; полный suite и task-only commit/push остаются `unresolved` из-за существующего общего Git snapshot.

## Scope и ожидаемые файлы

- `resources/views/livewire/collections/catalog-collection-dashboard.blade.php`: безымянный `wire:transition` на условной панели формы создания.
- `tests/Feature/LivewireWireTransitionContractTest.php`: real-component RED/GREEN contract открытия и закрытия.
- `docs/superpowers/specs/2026-07-20-livewire-wire-transition-collection-create-design.md`: решение, alternatives, accessibility, production impact и rollback.
- `docs/superpowers/plans/2026-07-20-livewire-wire-transition-collection-create.md`: пошаговая реализация и проверки.
- Владельцы architecture/frontend/views, `README.md` и `CHANGELOG.md`: постоянное правило и visitor/technical history.

## Совместимые contracts

`CatalogCollectionDashboard::$showCreate`, `$toggle('showCreate')`, `$set('showCreate', false)`, `create()`, `canCreate`, collection policy, validation, `x-ui.panel` attribute forwarding, pagination islands, route names, translations, storage и cache identities остаются без изменений.

## Cross-feature impact и compliance matrix

| Требование / domain | Статус | Evidence / ограничение |
| --- | --- | --- |
| Canonical read order и official Livewire 4 guidance | `completed` | Проверены requirements owners, установленный `livewire/livewire v4.3.3`, официальная документация `wire:transition` и vendor runtime |
| Design, alternatives и standing approval | `completed` | Выбрана одна add/remove boundary; list/status/custom-CSS варианты отклонены; пользователь прямо указал продолжать без вопросов |
| Authentication, authorization, validation и privacy | `already_compliant` | Transition не управляет доступом или записью; существующие server-side policy и validation contracts сохраняются |
| Translations и accessibility | `already_compliant` | Нового текста нет; Livewire уважает `prefers-reduced-motion`, unsupported browsers используют instant fallback |
| Cache, search, SEO, sitemap, notifications, audit и imports | `not_applicable` | Reads, writes, indexed content, cache keys, events и jobs не меняются |
| Premium, payment, region, legal, advertiser и administration | `not_applicable` | Entitlement, financial, restriction и staff boundaries не затрагиваются |
| Mobile/browser behavior | `completed` | Existing responsive form и touch controls сохраняются; используется только native optional crossfade |
| Database, dependencies, runtime, deployment и backup | `not_applicable` | Migration, package, config, persistent data и production service changes отсутствуют; rollback удаляет одну директиву |
| TDD RED/GREEN и owner/visitor documentation | `completed` | RED упал только на отсутствии `wire:transition`; минимальный attribute дал GREEN: 1 test / 5 assertions. Обновлены architecture/frontend/views owners, README и русский CHANGELOG |
| Task-scoped verification | `completed` | Pint прошёл; transition contract: 1/5 assertions; `RussianOnlyAuthoringTest`: 4/19 assertions; Vite: 23 modules; managed docs, diff и duplicate/custom-animation scans прошли. Фильтр `CatalogCollectionDashboard` не нашёл тестов и не заявляется дополнительным покрытием |
| Полный PHPUnit suite | `unresolved` | `php artisan test --compact`: 1 360 tests, 1 342 passed, 11 skipped, 7 errors. Все ошибки находятся в существующих catalog-administration tests: изменённый в общем snapshot `AdminAuditRecorder` читает отсутствующий `public_id` у `CatalogTitle`/`Season`; отдельный failing test воспроизведён, transition contract в suite не падал |
| Commit/push только из `main` | `unresolved` | Ветка `main` подтверждена; общий index/worktree содержит многочисленные чужие staged/unstaged изменения, включая overlapping Blade/README/CHANGELOG/docs, поэтому task-only staging потребовал бы небезопасной перестройки чужого snapshot |

---

# Livewire `wire:init` — только необходимая фоновая проверка тайтла

Обновлено: 20.07.2026

Статус: RED/GREEN implementation, owner documentation и task-scoped verification завершены; полный suite и task-only commit/push остаются `unresolved` из-за изменяющегося общего Git snapshot.

## Scope и ожидаемые файлы

- `app/Services/Seasonvar/CatalogTitleRefreshCoordinator.php`: единый server-owned predicate необходимости запроса обновления с повторной проверкой под lock.
- `app/Livewire/CatalogTitleDetail.php`: подготовленный render-local boolean без сериализации source URL или Eloquent state.
- `resources/views/livewire/catalog-title-detail.blade.php`: условный без модификаторов `wire:init="startRefresh"`; активный `wire:poll.3s.visible` остаётся независимым.
- `tests/Feature/CatalogTitleLiveRefreshTest.php`: real-route RED/GREEN contracts для stale, active, fresh и unrefreshable состояний.
- `docs/superpowers/specs/2026-07-20-livewire-wire-init-title-refresh-design.md` и `docs/superpowers/plans/2026-07-20-livewire-wire-init-title-refresh.md`: решение, alternatives, production/rollback и исполнимый план.
- Architecture/frontend/importer/performance owners, `README.md` и `CHANGELOG.md`: постоянное правило и visitor/technical history.

## Совместимые contracts

Полный SSR/SEO страницы тайтла, `CatalogTitleDetail::startRefresh()`, `CatalogTitleRefreshCoordinator::request()`, distributed dispatch lock, operational refresh-state keys/TTL, queue job identity, `wire:poll.3s.visible`, player refresh event, visibility rules, public routes, translations и importer pipeline остаются без изменений.

## Cross-feature impact и compliance matrix

| Требование / domain | Статус | Evidence / ограничение |
| --- | --- | --- |
| Canonical read order и official Livewire 4 guidance | `completed` | Проверены requirements owners, installed `livewire/livewire v4.3.3` и official `wire:init`: action запускается сразу после render, модификаторов нет, для обычного deferred rendering предпочтителен lazy loading |
| Design, alternatives и standing approval | `completed` | Выбран coordinator-owned eligibility predicate; unconditional init и full-page lazy variants отклонены; пользователь поручил продолжать без вопросов |
| Authentication, authorization, validation и privacy | `already_compliant` | Render hint не считается authority; coordinator повторно проверяет модель/state под lock, source URL в Livewire snapshot не попадает |
| Translations и accessibility | `not_applicable` | Видимый текст, focus, semantics и announcements не меняются |
| Caching, imports и concurrency | `completed` | Используется существующий operational state и fresh window; cache key/TTL не меняются, queue dispatch остаётся защищён lock и authoritative recheck |
| Search, SEO, sitemap, notifications и audit | `already_compliant` | Полный SSR и индексируемый контент не откладываются; routes, events и persisted audit state не меняются |
| Premium, payment, region, legal, advertiser и administration | `not_applicable` | Entitlement, restriction, financial и staff boundaries не затрагиваются |
| Mobile/browser behavior и performance | `completed` | Удаляется только заведомо лишний post-render Livewire request для active/fresh/no-source состояний; stale refresh и visible polling сохраняются |
| Database, dependencies, runtime, deployment и backup | `not_applicable` | Migration, package, config, persistent data и service topology не меняются; rollback возвращает безусловный attribute и удаляет eligibility hint |
| TDD RED/GREEN и task-scoped verification | `completed` | RED: 8 tests, 5 passed, 3 ожидаемых failures только на безусловном `wire:init`. GREEN и расширенный gate: 20 tests, 393 assertions; targeted Pint, Vite build с 23 modules, managed docs и `git diff --check` прошли |
| Legacy/duplicate/privacy scan | `completed` | В application Blade найден ровно один условный `wire:init="startRefresh"`; modifiers и competing init отсутствуют, render получает только boolean, existing source URL non-disclosure tests сохранены |

# Livewire `wire:intersect` — доступная viewport-загрузка фильтров каталога

Дата: 20.07.2026
Статус: RED/GREEN implementation, documentation и verification завершены; task-only Git delivery остаётся `unresolved` из-за общего staged snapshot.

## Scope и решение

- Проверить official Livewire 4 `wire:intersect` contract и установленный `livewire/livewire v4.3.3`.
- Сохранить существующий `@island(name: 'catalog-live', lazy: true)`: Livewire генерирует для его placeholder одноразовый `wire:intersect.once="__lazyLoadIsland"` и загружает тяжёлый граф фасетов только при приближении блока к viewport.
- Добавить placeholder семантику `role="status"` рядом с существующими `aria-busy="true"` и `aria-live="polite"`, чтобы one-time viewport request был понятен assistive technologies.
- Не добавлять infinite scroll, Livewire-загрузку изображений, visibility analytics, новый public action, Alpine observer или custom JavaScript: они ухудшили бы доступную пагинацию/SEO, увеличили число запросов либо продублировали бы Livewire.
- Expected application files: `resources/views/catalog/titles.blade.php`, `tests/Feature/CatalogVisualSystemTest.php`; canonical docs, plan/spec, `README.md` и `CHANGELOG.md` обновляются только по фактическому результату.

Rollback: удалить только `role="status"` и связанные test/docs records. Schema, dependencies, cache keys, routes, queue/storage, environment и persistent data не меняются; asset rollback не требуется.

## Cross-feature impact и compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Canonical read order и official version-specific guidance | `completed` | Перечитаны применимые owners; official Livewire 4 подтверждает enter/leave actions и modifiers `.once`, `.half`, `.full`, `.threshold.*`, `.margin.*`; vendor `v4.3.3` генерирует `.once` для lazy island |
| Public catalog behavior и accessibility | `completed` | Initial `/titles` HTML содержит один generated `wire:intersect.once="__lazyLoadIsland"`; busy placeholder объявляет локализованную загрузку через `role="status"` и `aria-live="polite"`, а результаты остаются в SSR |
| Authentication, authorization, administration, audit, privacy | `not_applicable` | Публичный read-only placeholder не меняет identity, gates, policies, writes, audit или private data |
| Translations и visible copy | `already_compliant` | Существующий ключ `catalog.catalog.filters.loading` и RU/EN catalogs переиспользуются; новый hardcoded copy не добавляется |
| Caching, search, notifications, imports, premium, region/legal | `not_applicable` | Нет новых server actions, queries, cache paths, notifications, import/provider или access-state изменений |
| SEO, public routes, pagination и browser history | `already_compliant` | Сохраняются route, server-rendered results, named pagination island и обычные ссылки; infinite scroll отклонён |
| Mobile, reduced motion и frontend lifecycle | `already_compliant` | Layout/classes/spinner и Livewire-owned observer сохраняются; custom JS/observer отсутствует |
| Production operations, compatibility и rollback | `completed` | Dependency/schema/config/build/runtime contracts не меняются; изменение additive в HTML semantics и обратимо code revert |
| TDD RED/GREEN и task-scoped verification | `completed` | RED: 1 test, 1 expected failure только на отсутствующем `role="status"`. GREEN: 1 test/4 assertions; соседний gate 2/63; расширенный catalog gate 130/1 524; targeted Pint, Vite build из 23 modules, managed docs, diff и whitespace checks прошли |
| Legacy/duplicate/privacy scan | `completed` | Application code не содержит authored `wire:intersect`, `IntersectionObserver`, `x-intersect`, `loadFacets` или direct `__lazyLoadIsland`; test вызывает internal action только как Livewire transport contract. Отдельный button-driven `loadMoreReplies` комментариев не конкурирует с каталогом |
| README, canonical docs и CHANGELOG | `completed` | Обновлены `architecture.md`, `frontend.md`, `catalog-search.md`, `views.md`, `UI_STANDARDS.md`, русский README visitor history, русский CHANGELOG, design spec и implementation plan; managed blocks синхронизированы штатной командой |
| Полный PHPUnit suite | `unresolved` | `php artisan test --compact`: 1 398 tests, 1 386 passed, 11 skipped, 122 796 assertions и один несвязанный failure `AdminNavigationTest` на отсутствующей ссылке `/admin/catalog`. Точный тест сразу прошёл отдельно 1/1, 9 assertions, весь файл — 3/3, 19 assertions; текущие registry/Moderator permission содержат route и `ContentView`, поэтому transient shared-snapshot failure не исправлялся этой задачей |
| Commit/push только из `main` | `unresolved` | Ветка `main` подтверждена и ahead 35; общий index содержит сотни уже staged administration/importer/frontend/docs changes, включая все overlapping task files. Общий cached diff gate дополнительно находит trailing whitespace в чужих `docs/audits/administration-architecture-audit.md` и `docs/superpowers/specs/2026-07-19-task-26-administration-architecture-design.md`. Task-only commit или исправление потребовали бы перестройки чужого scope, поэтому commit/push не выполнялись |

# Livewire `wire:poll` — только bounded active-state polling

Дата: 20.07.2026
Статус: implementation и verification завершены; commit/push заблокированы общим грязным index и несвязанным order-dependent failure полного suite.

## Scope и решение

- Official Livewire 4 contract: default interval `2.5s`, optional action, explicit `.[number]s|ms`, automatic background throttling на 95%, opt-out `.keep-alive` и viewport-only `.visible`.
- Сохранить только два фактических application polling boundary: active title refresh `wire:poll.3s.visible="refreshCatalog"` и active import run `wire:poll.5s.visible="refreshRuns"`. Оба атрибута условны и исчезают после terminal state.
- Не добавлять bare `wire:poll`, `.keep-alive`, polling каждой карточки, WebSocket package или новый timer. `/stats` остаётся requestless после первого render и получает snapshot через importer/admin invalidation и плановый warmer.
- Исправить stale canonical claims, где `/stats` всё ещё описана с `wire:poll.15s.visible`, не переписывая исторические записи о прежнем поведении.
- Expected files: новый static contract test, `architecture.md`, `frontend.md`, `performance.md`, `UI_STANDARDS.md`, отдельная запись `MAINTENANCE_LOG.md`, `CHANGELOG.md`, design/plan и этот compliance evidence. `README.md` проверяется, но не меняется: visitor behavior уже соответствует факту.

Rollback: вернуть только documentation/test records. Production PHP/Blade, dependencies, routes, schema, cache keys/TTL, queues, environment и persistent data не меняются.

## Cross-feature impact и compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Official version-specific contract | `completed` | Проверены default `2.5s`, action, seconds/milliseconds, automatic background throttle, `.keep-alive` и `.visible` для Livewire 4 |
| Existing title/import polling | `already_compliant` | Оба poll имеют explicit interval, `.visible`, action и server-owned conditional terminal stop; bare/keep-alive polling отсутствует |
| `/stats` performance и cache | `completed` | Runtime и `CatalogPageTest` запрещают poll; architecture/performance/UI/frontend owners теперь фиксируют одно чтение warmed snapshot без visitor request loop |
| Authentication, authorization, privacy, audit | `not_applicable` | Actions, gates, policies, public/private state и writes не меняются |
| Translations и accessibility | `already_compliant` | Existing localized status regions сохраняются; новых visible strings нет |
| Search, SEO, notifications, imports, premium, region/legal | `not_applicable` | Нет behavior/schema/service изменений; importer polling contract только документируется |
| Mobile/browser lifecycle | `already_compliant` | `.visible` останавливает off-viewport requests, Livewire background throttle сохраняется; `.keep-alive` отклонён |
| Production operations и rollback | `completed` | Documentation/test-only correction; deploy, backup, migration, cache clear и service restart не требуются |
| TDD, docs, full verification и delivery | `unresolved` | RED/GREEN, focused/related tests, Pint, managed docs, legacy scan и Vite прошли. Полный suite: 1 405 tests, 1 393 passed, 11 skipped, 122 838 assertions и один несвязанный order-dependent failure `HdRezkaCollectionSyncTest`; exact rerun и весь файл прошли. Shared index не позволяет безопасный task-only commit/push |

## Verification evidence

- RED: `LivewireWirePollContractTest` выполнил 2 теста, один runtime-тест прошёл, documentation contract упал на отсутствии точной requestless-фразы в `architecture.md`; 7 assertions до ожидаемого failure.
- GREEN: `LivewireWirePollContractTest` — 2/2, 15 assertions; пять точных title/import/stats сценариев — 5/5, 113 assertions; полные `CatalogTitleLiveRefreshTest` и `CatalogPageTest` — 90/90, 841 assertions.
- `Pint` для нового теста, `project:docs-refresh --check`, task-scoped `git diff --check` и `npm run build` прошли; Vite собрал 23 модуля. Application Blade содержит ровно два poll, bare/`.keep-alive` отсутствуют; найденные timers принадлежат player heartbeat/recovery и календарным часам и не дублируют этот workflow.
- Полный `php artisan test --compact` завершился одним несвязанным failure: `HdRezkaCollectionSyncTest::test_dry_run_parses_and_matches_without_database_or_cover_mutations` увидел непустой fake uploads root. Точный повтор прошёл 1/1, 13 assertions, весь файл — 9/9, 106 assertions; production/fixture код чужой области не изменялся без воспроизводимого дефекта.
- `README.md` перечитан: доступная посетителю возможность и состояние продукта не изменились, поэтому новая visitor-history запись не добавлялась. Ветка `main` ahead 35 подтверждена; сотни уже staged/unstaged shared изменений и общий index исключают безопасную изоляцию этой задачи, commit/push не выполнялись.

# Livewire `wire:offline` — offline guard длинной формы обращения

Дата: 20.07.2026
Статус: implementation и verification завершены; commit/push заблокированы общим грязным index.

## Scope и решение

- Official Livewire 4 contract: `wire:offline` показывает скрытый элемент при потере соединения и снова скрывает после восстановления; `.class`, `.class.remove` и `.attr` управляют class/attribute состоянием.
- Сохранить существующий global Vite connectivity owner в layout: он работает вне корня конкретного компонента, имеет один локализованный `role="status"` и отдельное restored state.
- Добавить только `wire:offline.attr="disabled"` на submit длинной technical-issue формы. Пользователь продолжает редактировать DOM-черновик offline, но не отправляет заведомо неуспешный Livewire request.
- Не добавлять второй alert, offline storage, service worker, background sync, client-trusted network/access state или массовую директиву на несвязанные actions.

Rollback: удалить одну Blade-директиву и task-specific test/docs. Routes, schema, uploads, temporary files, cache, queue, dependencies, environment и persistent data не меняются.

## Cross-feature impact и compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Official version-specific contract | `completed` | Проверены visibility lifecycle и `.class`, `.class.remove`, `.attr`; выбран поддерживаемый `.attr="disabled"` |
| Global connectivity banner | `already_compliant` | Vite runtime слушает `online`/`offline`, показывает один RU/EN status и restored state; layout не подменяется component-scoped directive |
| Technical-issue long-form submit | `completed` | Final submit содержит ровно один `wire:offline.attr="disabled"` рядом с `wire:loading.attr="disabled"` и target `submit,screenshots`; поля остаются редактируемыми |
| Authentication, authorization, validation, privacy | `already_compliant` | Server policies/actions/validated input остаются authority; browser state не даёт прав и не сохраняется как truth |
| Uploads и local draft | `already_compliant` | Temporary upload pipeline не меняется; offline guard блокирует submit, но не input fields и не обещает durable browser persistence |
| Translations и accessibility | `already_compliant` | Новый видимый текст отсутствует; единый global `aria-live` сохраняется без дубликата |
| Mobile, PWA, service worker | `completed` | Улучшение использует browser connectivity hint; installability, service worker, offline video/cache/background sync не добавляются и не заявляются |
| Search, SEO, notifications, imports, premium, region/legal | `not_applicable` | Нет изменений соответствующих routes, data или services |
| Production operations и rollback | `completed` | Blade/test/docs-only rollout; migration, backup, cache clear, queue restart и environment change не требуются |
| TDD, docs, verification и delivery | `unresolved` | RED/GREEN, related tests, Pint, Vite, managed docs, diff/legacy gates и полный suite прошли; общий index с сотнями чужих изменений и cached whitespace не позволяет безопасный task-only commit/push |

## Verification evidence

- RED: `LivewireWireOfflineContractTest` — 2 tests, 1 passed, 7 assertions и ожидаемый failure `0 !== 1` только на отсутствующей offline-директиве; global layout/runtime assertions прошли.
- GREEN: тот же контракт — 2/2, 8 assertions. Связанный набор `LivewireWireOfflineContractTest`, `FrontendAssetContractTest`, `AppLayoutStructuredDataTest`, `CatalogBladeComponentTest` — 21/21, 434 assertions.
- Targeted Pint, `project:docs-refresh --check`, task-scoped diff/whitespace scan и `npm run build` прошли; Vite собрал 23 модуля. Repository scan подтвердил одну application `wire:offline`, один global connection banner и отсутствие service worker, Cache API и background sync.
- Полный `php artisan test --compact` прошёл: 1 407 tests, 1 396 passed, 11 skipped, 122 848 assertions. Предыдущий несвязанный HdRezka order-dependent failure на ином snapshot не повторился.
- `README.md` обновлён в тематическом разделе и visitor history, поскольку offline guard изменил доступное посетителю поведение. Ветка `main` ahead 35 подтверждена; общий cached diff всё ещё содержит чужой trailing whitespace в двух administration docs, а общий index — сотни staged/unstaged файлов, поэтому commit/push не выполнялись.

# Livewire `wire:ignore` — characterization точной player boundary

Дата: 20.07.2026
Статус: characterization и verification завершены; commit/push заблокированы общим грязным index.

## Scope и решение

- Official Livewire 4: `wire:ignore` исключает содержимое элемента из morphing для third-party DOM; `.self` исключает только root attributes, но не потомков.
- Repository содержит ровно один usage: keyed `CatalogTitlePlayer` shell, которым владеют Plyr/HLS. Full ignore необходим для library-generated descendants; `.self` недостаточен.
- Livewire loading overlay, media options, errors и portal/personal controls остаются за границей shell. Native dialogs, help editor, filters и forms не получают ignore без third-party DOM ownership.
- Production Blade/JS не меняются; добавляется только characterization test и canonical evidence.

Rollback: удалить test/docs records. Playback, signed URLs, grants, progress, routes, schema, cache, dependencies, assets и environment не меняются.

## Cross-feature impact и compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Official `wire:ignore`/`.self` contract | `completed` | Проверены full subtree ignore и root-attribute-only `.self`; player требует full form |
| Player third-party DOM ownership | `already_compliant` | Единственный keyed shell содержит Plyr/HLS-managed video/status/captions/countdown/dialog DOM и имеет explicit cleanup lifecycle |
| Livewire server-owned controls | `already_compliant` | Loading, media selection, errors и portal/personal controls находятся вне ignored shell и продолжают morphing |
| Other widgets/dialogs/forms | `not_applicable` | Repository audit не нашёл иной library-generated DOM, которому требуется ignore; native/server-owned UI не изолируется |
| Authorization, privacy, progress, signed URLs | `already_compliant` | Ignore не считается security boundary; grants/policies/tokens/progress остаются server-owned |
| Localization, accessibility, mobile/browser cleanup | `already_compliant` | Player copy/status/ARIA и destroy/re-init lifecycle уже покрыты; production behavior не меняется |
| Search, SEO, notifications, imports, premium, region/legal | `not_applicable` | Нет изменений feature state или routes |
| Production operations и rollback | `completed` | Test/docs-only characterization; deployment/runtime/data actions не требуются |
| Verification и delivery | `unresolved` | Characterization/related/full tests, Pint, Vite, docs/diff/legacy gates прошли; общий index с чужими staged/unstaged изменениями не позволяет безопасный task-only commit/push |

## Verification evidence

- Новый already-compliant characterization `LivewireWireIgnoreContractTest` прошёл сразу: 2/2, 10 assertions. RED отсутствует намеренно, потому что production behavior не изменялся и test фиксирует существующий правильный контракт.
- Связанные `LivewireWireIgnoreContractTest`, `FrontendAssetContractTest`, `CatalogPlayerCopyTest`, `BrowserCiContractTest` прошли 12/12, 394 assertions; exact feature render selected media — 1/1, 23 assertions.
- Targeted Pint, `project:docs-refresh --check`, task-scoped diff check и legacy inventory прошли. Application Blade содержит ровно один `wire:ignore`; `.self` в application отсутствует. `npm run build` собрал 23 модуля.
- Полный `php artisan test --compact` прошёл: 1 410 tests, 1 399 passed, 11 skipped, 122 860 assertions.
- `README.md` перечитан и не изменён: player runtime и доступная посетителю возможность остались прежними. Ветка `main` ahead 35 и общий грязный index подтверждены; commit/push не выполнялись.

# Livewire `wire:ref` — scoped `CatalogTitleDetail` → player event

Дата: 20.07.2026
Статус: implementation и verification завершены; commit/push заблокированы общим грязным index.

## Scope и решение

- Official Livewire 4: refs именуют element/child component, scoped текущим компонентом; events и streams могут адресоваться `ref`, DOM доступен через `$refs`, duplicate name выбирает первый ref.
- Единственный `CatalogTitlePlayer` внутри `CatalogTitleDetail` получает статический `wire:ref="player"`; существующий `catalog-title-refreshed` направляется `->to(ref: 'player')` вместо class-wide target.
- Event name/payload и defensive child ID check сохраняются. Vite/browser selectors не переписываются на refs и не требуют inline script.
- `wire:key` остаётся независимой identity boundary; dynamic refs и дополнительные refs не добавляются.

Rollback: вернуть class target и удалить ref. Polling, SSR, player selection/progress/grants, routes, schema, cache keys, dependencies, assets, environment и persistent data не меняются.

## Cross-feature impact и compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Official `wire:ref` contract | `completed` | Проверены child event target, DOM `$refs`, `$wire`, stream ref, dynamic/scoping/duplicate behavior; выбран static child ref |
| Parent → child refresh event | `completed` | Единственный keyed child имеет `wire:ref="player"`; parent dispatch использует `->to(ref: 'player')`, старый class target отсутствует |
| Player listener/payload defense | `already_compliant` | `#[On('catalog-title-refreshed')]` повторно сравнивает `catalogTitleId` и очищает только render-local caches |
| Poll/init/import/cache lifecycle | `already_compliant` | Event возникает после существующего `refreshCatalog`; intervals, terminal stop, page cache forget и importer state не меняются |
| DOM selectors и browser modules | `not_applicable` | External Vite lifecycle остаётся на scoped data attributes; refs не протекают между components и не требуют inline JS |
| Authorization, privacy, progress, signed playback | `already_compliant` | Ref только маршрутизирует UI event; policies/grants/tokens/progress остаются server-owned |
| Translations, accessibility, mobile, SEO | `not_applicable` | Нет видимого текста, DOM layout или public metadata change |
| Production operations и rollback | `completed` | Additive Blade/PHP targeting change; migration/config/cache clear/service restart не требуются |
| TDD, docs, verification и delivery | `unresolved` | RED/GREEN, related/full tests, Pint, Vite, docs/diff/legacy gates прошли; shared staged/unstaged index не позволяет безопасный task-only commit/push |

## Verification evidence

- RED: `LivewireWireRefContractTest` упал на `0 !== 1`, подтвердив отсутствие application ref при старом class-wide target. Первый post-change повтор выявил только ошибочно экранированный Blade regex теста; после разделения ref/key assertions production implementation не менялась.
- GREEN: contract — 1/1, 7 assertions; полный `CatalogTitleLiveRefreshTest` — 8/8, 40 assertions. Расширенный ref/poll/asset/refresh/budget набор прошёл 19/19, 422 assertions.
- Pint для `CatalogTitleDetail` и нового теста, `project:docs-refresh --check`, task-scoped diff и legacy inventory прошли. Repository содержит один `wire:ref="player"`, один `->to(ref: 'player')` и не содержит прежний class target. `npm run build` собрал 23 модуля.
- Полный `php artisan test --compact` прошёл: 1 411 tests, 1 400 passed, 11 skipped, 122 871 assertion.
- `README.md` перечитан и не изменён: event scoping не изменяет посетителю UI или capability. Ветка `main` ahead 35 и общий грязный index подтверждены; commit/push не выполнялись.

# Livewire `wire:replace` — narrow leaf-checkbox inventory

Дата: 20.07.2026
Статус: design, characterization и task-scoped verification завершены; итоговый общий suite будет выполнен после оставшихся Livewire directive audits, Git delivery остаётся `unresolved` из-за shared index.

## Scope и решение

- Official Livewire 4: `wire:replace` пропускает morphing потомков и полностью заменяет их server-rendered поддеревом; `.self` заменяет root вместе со всеми потомками.
- Repository уже содержит четыре template pattern `wire:replace.self` только на leaf-checkbox contextual filters с `wire:model.live`; новый input принимает authoritative checked state после grouped island response, не заменяя label/counter/group.
- Bare subtree replacement, custom elements и shadow DOM отсутствуют. Единственный third-party owner — keyed Plyr/HLS shell — сохраняет `wire:ignore` и explicit destroy/re-init lifecycle; replacement здесь конфликтовал бы с ownership boundary.
- Native dialogs, editors и text/search inputs продолжают morphing ради сохранения focus, draft и browser state. Будущее расширение требует regression test и доказательства, что более узкие key/component/lifecycle решения недостаточны.

Rollback: удалить test/docs records. Production HTML, routes, state, schema, cache, dependencies, assets, environment и persistent data не меняются.

## Cross-feature impact и compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Official `wire:replace`/`.self` contract | `completed` | Проверены subtree replacement, root+self replacement и intended DOM-state/reuse cases |
| Existing replacement inventory | `completed` | Найдены четыре committed/tested `.self` pattern на live leaf-checkbox; bare replacement и custom elements/shadow DOM отсутствуют |
| Forms, dialogs, filters, editors | `already_compliant` | Только checkbox input заменяет себя; окружающий filter UI и остальные server/browser-owned widgets продолжают morphing |
| Player lifecycle | `already_compliant` | `wire:ignore`, stable key и explicit Plyr/HLS init/destroy остаются единственной third-party boundary |
| Authentication, authorization, privacy, validation | `not_applicable` | Actions, policies, input и snapshots не меняются |
| Translations, accessibility, mobile/browser behavior | `already_compliant` | Видимый UI не меняется; существующие focus и draft contracts сохраняются |
| Cache, search, SEO, notifications, imports, premium, region/legal | `not_applicable` | Нет feature, route, data или service изменений |
| Production operations и rollback | `completed` | Test/docs-only task; migration, backup, cache clear, service restart и asset rollback не требуются |
| Tests, docs и README | `completed` | Exact characterization и related suite прошли; owners/CHANGELOG обновлены, README проверен без изменения при неизменном visitor behavior |
| Полный suite и Git delivery | `unresolved` | Consolidated full suite выполняется после оставшихся directive audits; shared staged/unstaged index не позволяет безопасный task-only commit/push |

## Verification evidence

- Первый characterization ожидал zero inventory и корректно упал: repository scan обнаружил четыре committed/tested `.self` pattern. После dependency/history audit контракт уточнён до exact narrow inventory без production change.
- Уточнённый `LivewireWireReplaceContractTest` вместе с `LivewireWireIgnoreContractTest` прошёл 4/4, 30 assertions. Расширенный набор `CatalogVisualSystemTest`, replacement/ignore, frontend assets и player copy прошёл 43/43, 674 assertions.
- Targeted Pint, `project:docs-refresh --check`, task-scoped `git diff --check`, exact legacy inventory и `npm run build` прошли; Vite собрал 23 modules. `README.md` перечитан и не менялся.

# Livewire `wire:show` — сохранённая DOM-форма сообщения об устаревшей статье

Дата: 20.07.2026
Статус: RED/GREEN implementation, owner/visitor documentation и task-scoped verification завершены; consolidated full suite и Git delivery остаются `unresolved`.

## Scope и решение

- Official Livewire 4: `wire:show` toggles `display: none` по expression, не удаляя element из DOM; modifiers отсутствуют.
- Малую публичную help-report форму заменить с `@if ($showReportForm)` на modifier-free `wire:show="showReportForm"`, сохранив toggle/cancel/submit server actions.
- Добавить `wire:cloak` против initial false-state flash и stable `id`/`aria-controls`; скрытый form не получает autofocus и не становится dialog.
- Native collection report dialog сохраняет add/remove + Vite focus lifecycle, а collection create form — существующий `wire:transition`; эти разные boundaries не объединяются.

Rollback: вернуть conditional wrapper и удалить show/cloak/control linkage. Schema, dependencies, persistent data, routes, cache и services не меняются.

## Cross-feature impact и compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Official `wire:show` contract | `completed` | Проверены CSS visibility vs DOM removal, expression, Alpine transition compatibility и отсутствие modifiers |
| Help report draft/visibility | `completed` | Form всегда в DOM с modifier-free show/cloak; existing property/actions и reset сохранены |
| Validation, actor identity, privacy, rate limit | `already_compliant` | `submitReport` и domain action остаются server authority; hidden DOM не содержит private data |
| Accessibility и localization | `completed` | Stable `id`/`aria-controls`/`aria-expanded`, translated labels/errors, no autofocus/focus trap; initial flicker исключается cloak |
| Native dialog и collection create transition | `not_applicable` | Их add/remove/focus/transition contracts намеренно не меняются |
| Search, SEO, cache, notifications, imports, premium, region/legal | `not_applicable` | Report form visibility не меняет indexed content, routes, cache keys или другие domains |
| Mobile/browser performance | `completed` | Малый form остаётся responsive; initial HTML bounded, новый JS/module/animation отсутствует |
| Production operations и rollback | `completed` | Blade/test/docs-only rollout; migration, backup, cache clear, worker restart и config change не нужны |
| TDD и documentation | `completed` | RED/GREEN прошли; owners и visitor-facing README обновлены |
| Task-scoped verification | `completed` | Related suite, Pint, Vite, managed docs, task diff и exact inventory scans прошли |
| Full verification и delivery | `unresolved` | Consolidated full suite выполняется после оставшихся directive audits; shared Git index исключает безопасный task-only commit/push |

## Verification evidence

- RED: `LivewireWireShowContractTest` — 2 tests, 1 passed, 5 assertions и expected failure `0 !== 1` только на отсутствующем `wire:show` inventory.
- GREEN: тот же contract — 2/2, 9 assertions; production submit/reset PHP не менялся.
- Related show/Blade/frontend/contextual-help/Russian-only suite прошёл 31/31, 442 assertions. `project:docs-refresh --check` и `npm run build` прошли; Vite собрал 23 modules.
- Targeted Pint, task-scoped `git diff --check` и repository inventory прошли: application содержит один `wire:show`, один соседний `wire:cloak`, old conditional отсутствует. Ветка `main` ahead 35 подтверждена; shared staged/unstaged index не перестраивался.

# Livewire `wire:sort` — bounded drag ручного порядка подборки

Дата: 20.07.2026
Статус: реализация и документация завершены; выполняется финальная связанная проверка.

## Scope и решение

- Official Livewire 4: parent `wire:sort` + stable child `wire:sort:item` вызывает handler с ID и zero-based position; persistence принадлежит приложению. `wire:sort:handle` ограничивает drag, `wire:sort:ignore` защищает interactive controls, modifiers отсутствуют.
- Добавить drag enhancement только к manual list `CatalogCollectionEditor`; existing up/down buttons остаются keyboard/touch/no-drag baseline.
- Component переводит page-local position в absolute index текущего `collectionPage` по bounded window 24.
- Service под collection row lock повторно проверяет policy, membership, current/target window и rate limit, обновляет только затронутый диапазон, version/cache через existing boundary.
- Cross-page/group/collection drag, full-order browser payload и изменение automatic sort modes запрещены.

Rollback: удалить directives/handle, handler и service method; up/down actions сохраняют полную функциональность. Schema, dependencies, routes, cache domains, environment и persistent identity не меняются.

## Cross-feature impact и compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Official `wire:sort` contract | `completed` | Проверены parent/item, zero-based handler, groups/group-id, handle, ignore и отсутствие modifiers |
| Manual collection ordering | `completed` | Один sortable list со стабильными ID; page-local position преобразуется в bounded absolute window |
| Keyboard/touch accessibility | `completed` | Кнопки вверх/вниз сохранены; handle — только progressive pointer/touch enhancement, actions исключены из drag |
| Authentication, authorization, validation, privacy | `completed` | Existing `manageItems` policy/rate limiter повторяются до и под lock; item/window не доверяются browser |
| Database, concurrency, cache | `completed` | Existing position index/schema, collection lock, content version и cache invalidator переиспользованы |
| Automatic sort modes/import/recommendations | `already_compliant` | Drag меняет только manual positions; modes/provider membership/score semantics не меняются |
| Localization и mobile | `completed` | RU/EN hint описывает drag и кнопки; responsive 24-item page и 44px controls сохранены |
| Search, SEO, notifications, premium, region/legal | `not_applicable` | Private editor affordance не меняет routes/indexing/access domains |
| Production operations и rollback | `completed` | No migration/package/config/service change; code rollback возвращает existing buttons |
| TDD и документация | `completed` | RED: три contract failures; GREEN: 4 tests, 18 assertions. Owners, README и CHANGELOG обновлены |
| Связанная проверка | `completed` | Related 15 tests/366 assertions; consolidated Livewire 30/211; Pint, docs, build 23 modules, scans и diff gates прошли |
| Full repository suite | `completed` | После process-local изоляции `Storage::fake()` полный набор прошёл: 1 426 tests, 1 415 passed, 11 expected skipped, 122 943 assertions |
| Delivery | `completed` | Livewire implementation опубликована в `eb4e7f9e`; process-isolation follow-up оценивается отдельно ниже |

## Evidence

- RED: `LivewireWireSortContractTest` подтвердил отсутствие markup и методов сервиса/компонента до реализации.
- GREEN: тот же contract прошёл 4/4 теста и 18 утверждений, включая окно первой страницы, отказ межстраничного переноса без мутации и преобразование смещения второй страницы.

# Livewire `wire:stream` — аудит progressive DOM streaming

Дата: 20.07.2026
Статус: runtime-аудит и каноническая документация завершены; выполняется общая проверка серии.

## Scope и решение

- Official Livewire 4: `wire:stream="name"` получает части до завершения одного request; append является default, `replace: true`/`.replace` заменяет target; Laravel Octane не поддерживается.
- Application inventory равен нулю: нет `wire:stream` в Blade и `$this->stream()` в `app/Livewire`.
- Импорт/crawling/media checks не удерживают Livewire-request; player, finite polling и Laravel streamed responders сохраняют собственные boundaries.
- Новый target допустим только после отдельного use case с bounded partial content, escaping, cancellation/failure UX, timeout и runtime compatibility.

Rollback: удалить characterization contract и уточнения документации; runtime-код, данные и интерфейс не менялись.

## Cross-feature impact и compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Official stream/replace/Octane contract | `completed` | Проверены single-request delivery, append default, replace API/modifier и Octane warning |
| Blade/Livewire inventory | `already_compliant` | Ноль application targets/calls; Laravel responders исключены из подсчёта |
| Import, crawling, player, downloads, sitemap/feed | `already_compliant` | Queue/command/media/poll/HTTP response boundaries не смешаны с DOM streaming |
| Security, privacy, authorization, escaping | `already_compliant` | Новый unreviewed partial-content channel не создан |
| SEO, search, cache, notifications, mobile, admin | `not_applicable` | Product behavior не изменилось |
| Production/Octane/runtime/dependencies | `completed` | Ограничение задокументировано; config/package/service changes отсутствуют |
| Docs, README и tests | `completed` | Owners/CHANGELOG обновлены; README проверен без фиктивной истории; RED зафиксировал только docs gap |
| Focused verification | `completed` | GREEN: 2 tests, 6 assertions; Pint, managed docs и нулевой application inventory прошли |
| Consolidated Livewire verification | `completed` | Общий Livewire набор прошёл 30 tests, 211 assertions; build/docs/diff gates зелёные |
| Full repository suite | `completed` | После process-local изоляции `Storage::fake()` полный набор прошёл: 1 426 tests, 1 415 passed, 11 expected skipped, 122 943 assertions |
| Delivery | `completed` | Audit implementation опубликована в `eb4e7f9e`; process-isolation follow-up оценивается отдельно ниже |

# Livewire `#[Async]` — аудит параллельных actions

Дата: 20.07.2026
Статус: официальный/runtime аудит и документация завершены; выполняется финальная проверка серии.

## Scope и решение

- Повторённый четыре раза URL проверен один раз: `#[Async]` исполняет action немедленно и параллельно без queue; `.async` включает режим для конкретного вызова.
- Режим предназначен для pure fire-and-forget side effect без отражённой в UI component mutation; иначе параллельные snapshots создают races/lost updates.
- Ноль application usages сохранён: UI actions требуют authoritative response/order, а queue/post-commit work уже имеет отдельные boundaries.
- Фиктивная analytics/external integration не создаётся ради демонстрации attribute.

Rollback: удалить characterization contract и уточнение docs; runtime/data rollback отсутствует.

## Cross-feature impact и compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Official immediate/parallel/non-queued contract | `completed` | Проверены `#[Async]`, `.async`, use cases и warning о component-state races |
| PHP/Blade inventory | `already_compliant` | Ноль attribute imports/usages и directive modifiers |
| UI/domain mutations | `already_compliant` | Form/status/pagination/player/import actions остаются ordered и synchronous |
| Queue, notifications, external services | `already_compliant` | Existing queue/post-commit boundaries не подменяются parallel request |
| Auth, validation, privacy, audit | `already_compliant` | Trusted ordered server response и idempotency boundaries не ослаблены |
| Translations, cache, search, SEO, mobile, premium, region/legal | `not_applicable` | Product/data flow не изменился |
| Production, schema, dependencies, rollback | `not_applicable` | Runtime/config/package/data changes отсутствуют |
| Docs, README и RED | `completed` | Owners/CHANGELOG обновлены; README проверен; RED зафиксировал только docs gap |
| Focused GREEN и gates | `completed` | 2 tests, 7 assertions; Pint, managed docs и нулевой PHP/Blade inventory прошли |
| Consolidated Livewire suite | `completed` | 30 tests, 211 assertions; attribute contract включён; Pint/docs/build/diff/inventory gates зелёные |
| Full repository suite | `completed` | После process-local изоляции `Storage::fake()` полный набор прошёл: 1 426 tests, 1 415 passed, 11 expected skipped, 122 943 assertions |
| Delivery | `completed` | Audit implementation опубликована в `eb4e7f9e`; process-isolation follow-up оценивается отдельно ниже |

## Финальная consolidated verification серии

- Все направленные Livewire contracts прошли одним набором: 30 tests, 211 assertions.
- `wire:sort` related suite прошёл 15 tests, 366 assertions; `wire:text`/`wire:dirty` — 3/16; `wire:stream` — 2/6; `#[Async]` — 2/7.
- Pint прошёл на затронутых PHP-файлах; `project:docs-refresh --check` сообщает актуальную документацию; Vite собрал 23 modules; unstaged и staged `git diff --check` прошли.
- Первый полный suite: 1424 tests, 1411 passed, 11 skipped, один failure и один error только в `DemoCatalogCorpusStageTest` из-за исчезнувшего общего fake `uploads`. Второй: 1412 passed, 11 skipped и тот же один missing-cover failure. Сам класс сразу прошёл 4/5575, затем ещё три раза 4/5575; вместе с соседним `DemoAccountStageTest` прошёл 6/5707. Order/shared-testing-disk дефект не относится к Livewire scope и не исправлялся без доказанного источника.
- Доказанный ниже process-local guard устранил общий fake-root: два одновременных процесса `DemoCatalogCorpusStageTest` прошли по 4 tests/5 575 assertions, все 19 классов с `Storage::fake()` прошли 56/7 937, а полный suite завершился результатом 1 426 tests, 1 415 passed, 11 expected skipped и 122 943 assertions.
- После внешней консолидации ветка `main` синхронизирована с `origin/main` на `3bd5e56`. Попытка поставить в index только пять Livewire evidence-файлов и только собственные hunks общего плана была корректно отклонена project Git guard из-за новых unstaged `maintenance/update-decisions`, cache-plan и чужих секций того же `current-task-plan`; собственное staging было отменено без обхода hook или временного скрытия. Implementation уже опубликована в `eb4e7f9e`, а совместимые завершающие docs объединены в текущий общий documentation follow-up.

# PHPUnit `Storage::fake()` — process isolation

Дата: 20.07.2026
Статус: реализация и verification завершены; Git delivery оценивается отдельно на общем staged snapshot.

## Scope и решение

- Installed Laravel 13 очищает общий `storage/framework/testing/disks/{disk}` при каждом `Storage::fake()` и добавляет process suffix только при наличии `ParallelTesting::token()`.
- Два обычных serial PHPUnit runner одного checkout могут удалять fake uploads друг друга; это согласуется с двумя full-suite failures и изолированными 4× GREEN того же DemoData class.
- `Tests\TestCase` должен предоставлять PID token только при отсутствии настоящего runner token; Paratest, disk alias, call sites и production storage не меняются.

Rollback: удалить test-only resolver и regression contract; schema/data/config/runtime rollback отсутствует.

## Cross-feature impact и compliance matrix

| Требование | Статус | Evidence / ограничение |
| --- | --- | --- |
| Root-cause tracing | `completed` | Проверен installed `Storage::fake()` source: shared cleanDirectory + conditional token suffix |
| RED/GREEN | `completed` | RED: 1 test ожидаемо получил `false !== PID`; после minimal base-test guard обычный focused run прошёл 1 test/2 assertions |
| Existing Paratest compatibility | `completed` | `TEST_TOKEN=runner-7` preserved: focused contract прошёл 1 test/2 assertions без замены runner token |
| DemoData/uploads tests | `completed` | Два одновременных corpus-процесса прошли по 4 tests/5 575 assertions; все 19 классов с `Storage::fake()` прошли 56/7 937 |
| Production storage/data/config | `not_applicable` | Изменение ограничено `tests/`; production bootstrap/config не затрагиваются |
| Auth, translations, cache, search, SEO, notifications, mobile, admin, imports | `not_applicable` | Product behavior и domain state не меняются |
| Documentation, README, CHANGELOG | `completed` | Permanent development rule, plan и русский CHANGELOG обновлены; `README.md` проверен без фиктивной visitor entry, поскольку product behavior не изменился |
| Full repository suite | `completed` | 1 426 tests: 1 415 passed, 11 expected skipped, 122 943 assertions |
| Git delivery | `unresolved` | Разрешён только безопасный commit/push из `main`; общий staged snapshot проверяется без захвата или отмены чужих changes |

## Verification evidence

- RED до изменения base TestCase: 1 failed test с отсутствующим process token.
- GREEN: обычный focused run и run с `TEST_TOKEN=runner-7` прошли по 1 test/2 assertions.
- Concurrent reproduction: два независимых PHPUnit-процесса с `DemoCatalogCorpusStageTest` прошли одновременно по 4 tests/5 575 assertions.
- Related storage-fake inventory: 19 классов, 56 tests, 7 937 assertions.
- Full repository suite: 1 426 tests, 1 415 passed, 11 expected skipped, 122 943 assertions.
