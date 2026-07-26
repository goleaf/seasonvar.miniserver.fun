# Task 88 — план реализации новой шапки и глобального поиска

Дата: 26.07.2026.

Design:
[`2026-07-26-portal-header-global-search-design.md`](../specs/2026-07-26-portal-header-global-search-design.md).

Цель: реализовать согласованный desktop/mobile shell и search UX внутри
существующих Laravel 13, Blade, Livewire 4, Tailwind 4 и Vite boundaries,
проверить security/performance/backward compatibility, обновить
документацию, создать exact commit в `main` и выполнить обычный push.

Статусы checklist: `pending`, `in_progress`, `completed`, `skipped` с
причиной, `unresolved` только для реально внешнего блокера.

## Манифест ожидаемых изменений

Application:

- `app/View/ViewData/AppLayoutData.php`;
- `app/Services/Catalog/Search/CatalogTitleSuggestionQuery.php`;
- `app/Services/Catalog/Api/V1/CatalogSearchSuggestionQuery.php`;
- `app/Services/Catalog/Search/HeaderSearchSuggestionCache.php`;
- `app/Http/Resources/Api/V1/SearchSuggestionResource.php`;
- `resources/views/components/layout/site-header.blade.php`;
- `resources/views/components/layout/header-search.blade.php`;
- `resources/views/livewire/auth/logout-button.blade.php`;
- `resources/js/header-search.js`;
- `resources/js/mobile-runtime.js`;
- `resources/css/app.css`;
- `lang/ru/calendar.php`, `lang/en/calendar.php`;
- `lang/ru/catalog.php`, `lang/en/catalog.php`;
- `lang/ru/mobile.php`, `lang/en/mobile.php`.

Tests:

- `tests/Feature/Api/V1/HeaderSearchSuggestionApiTest.php`;
- `tests/Feature/HeaderSearchAutocompleteTest.php`;
- `tests/Feature/PublicOutputTerminologyTest.php`;
- `tests/Feature/CatalogBladeComponentTest.php`;
- `tests/Feature/CatalogVisualSystemTest.php`;
- `tests/Unit/AppLayoutOptionalNavigationTest.php`;
- `tests/Unit/CatalogTitleSuggestionQueryTest.php`;
- `tests/Unit/FrontendAssetContractTest.php`;
- `tests/Unit/HeaderSearchSuggestionCacheTest.php`;
- existing affected web/auth tests where old two-row assertions require
  replacement;
- `tests/browser/catalog.spec.js`;
- `tests/browser/cross-device-quality.spec.js`;
- `tests/browser/auth-portal.spec.js`;
- optional focused `tests/browser/portal-header.spec.js` if isolation makes
  evidence clearer.

Documentation:

- canonical `docs/UI_STANDARDS.md`, `docs/frontend.md`,
  `docs/catalog-search.md`, `docs/caching.md`, `docs/security.md`,
  `docs/architecture.md`, `docs/performance.md`, `docs/api.md`,
  `docs/views.md`, `docs/testing.md`, `docs/ci.md`;
- this design/plan and `docs/plans/current-task-plan.md`;
- `README.md`;
- `CHANGELOG.md`.

## Защищённые contracts и файлы

- existing `main`, foreign staged/unstaged work и чужие new files;
- route names/URLs for home, catalog, discovery, calendar, Top 100,
  requests, help, profile, notifications, library, auth and admin;
- `GET /search`, localized aliases and `/titles` Livewire URL/history;
- `GET /api/v1/search/suggestions` legacy and header scopes;
- `GlobalSearchRequest`, `SearchSuggestionRequest` validation and Russian
  error behavior;
- `CatalogTitleQuery::matchingTitles()` visibility/ranking;
- public page/shared suggestion cache privacy and invalidation;
- profile/library/request/notification/admin middleware/policies;
- no queries/business logic in Blade, no inline JS/CSS, no Volt;
- no migration, DML, dependency, `.env`, service worker or production
  provider change.

## Этап 1 — анализ текущего поведения

### 1.1 `[completed] [critical]` Fresh requirements и actual versions

- Что: прочитать root/index/applicable canonical requirements, related
  header/search plans, stack/versions, branch/status/remotes.
- Почему: repository rules являются обязательными и текущие permanent
  двухрядная/mobile-no-bottom-nav rules конфликтуют с новым explicit prompt.
- Файлы: read-only `AGENTS.md`, `docs/requirements/*`, relevant `docs/*.md`,
  lockfiles, Git metadata.
- Зависимости: нет.
- Риски: опереться на устаревшую Task 23 память; случайно принять foreign
  dirty state за Task 88.
- Проверка: actual PHP/Laravel/Livewire/Tailwind/Vite/PHPUnit/SQLite
  evidence, `git status --short --branch`, `git remote -v`.

### 1.2 `[completed] [critical]` Existing route/backend/frontend trace

- Что: проследить `AppLayoutData → site-header/header-search → Vite modules
  → API Request/Controller/Resource → query/cache`, tests and browser
  fixtures.
- Почему: сохранить работающий split-scope search и не создать duplicate
  framework/state.
- Файлы: exact application/test paths из manifest.
- Зависимости: 1.1.
- Риски: пропустить localized/guest/private link или stale lifecycle.
- Проверка: route list, source searches, neighbouring tests, actual database
  indexes and official Laravel/Livewire/Tailwind docs.

### 1.3 `[completed] [high]` Cross-feature/database/security audit

- Что: оценить auth, profile, notifications, requests, library, admin,
  cache, SEO, locale, service worker, player shortcuts, browser storage and
  production assets.
- Почему: header виден на всех routes и является cross-system boundary.
- Файлы: auth/policy/middleware/search/cache/docs and browser tests.
- Зависимости: 1.2.
- Риски: client-side hiding mistaken for access control; query history
  privacy drift; Ctrl+K conflict with player.
- Проверка: middleware list, canonical security rules, no new shared private
  cache and player state regression.

## Этап 2 — требования, дизайн и living plan

### 2.1 `[completed] [critical]` Canonical conflict resolution

- Что: зафиксировать, что новый explicit prompt replaces only old
  two-row/no-bottom-navigation rules; all security/no-duplicate/search
  boundaries remain.
- Почему: permanent rule must change in its canonical owner before code.
- Файлы: design, then `UI_STANDARDS/frontend/catalog-search/security`.
- Зависимости: 1.1–1.3.
- Риски: случайно отменить Task 23 one-tree/routes/policy/safe-area rules.
- Проверка: design maps old/new rule and explicitly lists preserved
  contracts.

### 2.2 `[completed] [high]` Alternative review and chosen architecture

- Что: сравнить duplicate desktop/mobile search, Livewire shell and single
  responsive Blade/Vite search.
- Почему: выбрать simplest maintainable design.
- Файлы: design spec.
- Зависимости: 1.2.
- Риски: single DOM root may require careful focus trap/repositioning.
- Проверка: state/data/lifecycle/rollback comparison and self-review.

### 2.3 `[completed] [critical]` Canonical docs and current checklist

- Что: сначала изменить permanent owners, создать exact compliance matrix,
  expected/protected files and live status.
- Почему: mandatory project workflow and user-requested living checklist.
- Файлы: canonical docs and `docs/plans/current-task-plan.md`.
- Зависимости: approved design.
- Риски: shared dirty documentation; foreign lines must be preserved and
  staged scope must be exact.
- Проверка: task-only diff against captured baseline, links resolve, no
  duplicate owner.

## Этап 3 — TDD RED

### 3.1 `[completed] [critical]` RED layout data contract

- Что: require four desktop primary items in exact order, requests/help in
  More, server-prepared account/notification actions, five mobile slots,
  active non-colour marker and legacy `navigation` compatibility.
- Почему: presentation groups must be server-owned and authorization-safe.
- Файлы: layout/visual/component/AppLayout tests.
- Зависимости: 2.3.
- Риски: brittle full-HTML assertions or optional-route fixtures.
- Проверка: focused PHPUnit must fail for absent new keys/markup only.

### 3.2 `[completed] [critical]` RED search API country/cache contract

- Что: fixture country, assert public nullable `country`, localized meta,
  no hidden data, stable legacy response and format version.
- Почему: user explicitly requires country and stale cache must not serve
  previous shape.
- Файлы: API/query/cache tests.
- Зависимости: 1.2.
- Риски: adding all taxonomy graphs or N+1.
- Проверка: focused test fails only because country/format v2 absent.

### 3.3 `[completed] [critical]` RED interaction/accessibility contract

- Что: assert shortcut/recent/mobile-open/focus/request-CTA/full-catalog
  markers, neutral frame and keyboard-visible bottom-nav CSS.
- Почему: core requested UX is JavaScript/CSS behavior.
- Файлы: header search and asset contract tests plus browser spec.
- Зависимости: 2.2.
- Риски: source-string-only tests that repeat implementation.
- Проверка: pair structural assertions with later observable Playwright
  behavior; RED records exact failures.

## Этап 4 — backend implementation

### 4.1 `[completed] [critical]` Split prepared layout groups

- Что: keep legacy full navigation, add primary/more/account/mobile/action
  prepared values and safe localized URLs.
- Почему: Blade must not discover routes/auth/policies.
- Файлы: `AppLayoutData.php`.
- Зависимости: RED 3.1.
- Риски: guest/mobile library, localized discovery fragment, optional
  routes, admin leakage.
- Проверка: guest/viewer/admin/localized/optional route tests.

### 4.2 `[completed] [high]` Bounded country eager-load

- Что: add only countries `id/name` to title suggestions; take at most two
  names in presentation.
- Почему: enrich quick row without per-card query.
- Файлы: title suggestion and API query.
- Зависимости: RED 3.2, existing pivot index.
- Риски: missing Eloquent primary key, unbounded relation payload.
- Проверка: exact JSON, query count and SQLite `EXPLAIN`.

### 4.3 `[completed] [high]` Additive Resource/cache format

- Что: expose nullable `country`, bump autocomplete format from 1 to 2.
- Почему: explicit API allowlist and rolling deployment cache safety.
- Файлы: Resource and cache.
- Зависимости: 4.2.
- Риски: break legacy no-scope clients or require broad invalidation.
- Проверка: legacy API tests, key separation, no raw query in keys.

### 4.4 `[skipped: no schema change required] [medium]` Database migration

- Что: no migration/index.
- Почему: existing PK begins with `catalog_title_id` and supports exact
  bounded country eager-load.
- Файлы: none.
- Зависимости: schema/EXPLAIN evidence.
- Риски: adding redundant write-cost index.
- Проверка: schema inspection and actual query plan.

## Этап 5 — frontend implementation

### 5.1 `[completed] [critical]` Desktop one-row header

- Что: Seasonvar wordmark, four primary links, More, flexible search, bell,
  account dropdown and active marker.
- Почему: exact user desktop IA.
- Файлы: site header, logout row, CSS/translations.
- Зависимости: 4.1.
- Риски: width pressure at 1024, details overlap, missing focus.
- Проверка: Blade tests and 1024/1280/1440/1920 Playwright geometry.

### 5.2 `[completed] [critical]` Compact top and bottom navigation

- Что: top logo/search/profile, fixed five-item bottom nav, safe area,
  content clearance and keyboard hiding.
- Почему: thumb reach and exact mobile requirement.
- Файлы: header/CSS/mobile translations/runtime.
- Зависимости: 4.1.
- Риски: player/connection/footer overlap, 320px labels, unauthorized
  library.
- Проверка: 320/390/768 geometry, active states, virtual keyboard class and
  player route smoke.

### 5.3 `[completed] [critical]` Fullscreen single-root search

- Что: open/close/focus return/trap/Escape/route cleanup/page scroll lock
  around the one existing search root.
- Почему: avoid duplicate input/state while meeting fullscreen UX.
- Файлы: header-search Blade/JS/CSS.
- Зависимости: RED 3.3.
- Риски: stuck body lock, stale ARIA, shortcut in editable control.
- Проверка: Playwright open/close/Tab/Escape/navigation/back-forward and
  no-JS desktop fallback.

### 5.4 `[completed] [high]` Recent queries and shortcuts

- Что: bounded locale-versioned sessionStorage with memory fallback,
  explicit clear, save on real submit/selection, Ctrl/Meta+K and `/`.
- Почему: requested discovery speed with smallest privacy scope.
- Файлы: header-search JS/Blade/translations/security docs.
- Зависимости: 5.3.
- Риски: storage exception, sensitive persistence, duplicate document
  listeners after Livewire navigation.
- Проверка: Playwright reload in same tab, clear, editable exclusion,
  lifecycle count and sessionStorage payload bounds.

### 5.5 `[completed] [high]` Result/empty/failure UX

- Что: poster/original/year/country/season/episodes, always-separated full
  catalog row, request CTA only after true zero, visible focus.
- Почему: exact functional search requirement and honest errors.
- Файлы: API renderer/Blade/translations.
- Зависимости: 4.2 and 5.3.
- Риски: false request CTA during partial failure; invalid listbox tree.
- Проверка: delayed/failed/zero mocked Playwright scenarios and axe.

### 5.6 `[completed] [medium]` Compact-on-scroll runtime

- Что: passive scroll + rAF toggles header compact data state; never hides
  shell.
- Почему: exact desktop behavior with low runtime cost.
- Файлы: mobile runtime/CSS.
- Зависимости: 5.1.
- Риски: layout shift, multiple listeners, pagination offset.
- Проверка: scroll screenshot/geometry, one listener lifecycle and
  pagination regression.

## Этап 6 — validation, authorization, security and errors

### 6.1 `[completed] [critical]` Input/URL validation preservation

- Что: preserve `SearchSuggestionRequest` scalar/NFKC/1..80/scope
  allowlist and server-created URLs; bound session recent entries.
- Почему: frontend validation cannot authorize or protect SQL.
- Файлы: Request unchanged unless test exposes gap; JS normalization.
- Зависимости: 4/5.
- Риски: array/object query, `%/_`, cross-origin URL.
- Проверка: existing malicious input/API tests and same-origin browser
  assertions.

### 6.2 `[completed] [critical]` Authorization/IDOR review

- Что: verify guest/auth/admin menu shapes and middleware/policies for each
  action.
- Почему: header is global and conditional rendering is not authorization.
- Файлы: layout/tests, no policy changes expected.
- Зависимости: 4.1/5.1.
- Риски: exposing private/admin destination or data.
- Проверка: guest/viewer/admin HTTP tests and route middleware output.

### 6.3 `[completed] [high]` XSS/privacy/cache review

- Что: ensure `textContent`, Blade escaping, same-origin links, public-only
  shared cache and session-only query history.
- Почему: autocomplete renders server/user-derived text.
- Файлы: JS/Resource/cache/docs/tests.
- Зависимости: 4.3/5.4.
- Риски: innerHTML, raw cache key, long-lived personal data.
- Проверка: repository scans, hostile query fixture and cache key test.

### 6.4 `[completed] [high]` Predictable failure cleanup

- Что: AbortController/sequence, partial failure, storage failure,
  overlay/focus/body-class cleanup.
- Почему: navigation and request races must not leave unusable shell.
- Файлы: header-search/mobile runtime.
- Зависимости: 5.3–5.5.
- Риски: empty catch hiding real network state.
- Проверка: mocked failure/abort/navigation browser scenarios; no
  console/page errors.

## Этап 7 — performance and query verification

### 7.1 `[completed] [high]` SQL count/plan

- Что: measure exact suggestion query before/after, inspect country pivot
  plan and returned projection.
- Почему: one bounded extra statement is intentional; no N+1/index guess.
- Файлы: tests/evidence docs only.
- Зависимости: 4.2.
- Риски: concurrent SQLite noise; wall time mistaken for SLA.
- Проверка: structural count plus `EXPLAIN QUERY PLAN`; measured values
  labeled local.

### 7.2 `[completed] [medium]` Frontend request/bundle lifecycle

- Что: verify maximum two requests per query, debounce/abort, one
  initialization, bounded cache/recent list and build output.
- Почему: global header loads everywhere.
- Файлы: JS/tests/docs.
- Зависимости: 5.
- Риски: duplicate listeners/fetch after Livewire navigation.
- Проверка: request interception, source contract, Vite chunk/build sizes
  reported without unsupported performance claim.

## Этап 8 — tests and quality gates

### 8.1 `[completed] [critical]` Focused GREEN

- Что: run exact RED classes, fix production code minimally, repeat.
- Почему: TDD evidence.
- Файлы: changed PHP/Blade/JS/CSS.
- Зависимости: stages 3–7.
- Риски: unrelated foreign failures.
- Проверка: commands and exact test/assertion counts recorded.

### 8.2 `[completed] [high]` Wider regression

- Что: related catalog/search/API/auth/layout/cache/player/pagination tests,
  then broad suite as shared environment permits.
- Почему: header is cross-system and shortcuts affect player.
- Файлы: tests.
- Зависимости: 8.1.
- Риски: parallel foreign work creates unrelated failures.
- Проверка: classify every failure by exact file/stack; never hide with
  `|| true`.

### 8.3 `[completed] [high]` Style/static/build

- Что: Pint dirty, syntax, available scoped PHPStan/Rector, translations,
  routes, views, docs, npm build.
- Почему: project completion contract.
- Файлы: changed scope.
- Зависимости: GREEN.
- Риски: formatter touches foreign code or generated artifacts.
- Проверка: exact commands, task-only diff after each tool.

### 8.4 `[completed] [critical]` Browser/accessibility/visual QA

- Что: run focused Playwright desktop/tablet/mobile/compact flows, inspect
  PNGs manually and check console/network/axe/overflow.
- Почему: layout, safe area, focus and keyboard behavior require a browser.
- Файлы: browser tests/output ignored artifacts.
- Зависимости: Vite build.
- Риски: local test server/cache/fixture interference.
- Проверка: process-scoped browser DB/port/cache and explicit screenshot
  inspection via image viewer.

## Этап 9 — documentation and final audit

### 9.1 `[completed] [critical]` Canonical/docs/README/CHANGELOG

- Что: update exact owners, visitor-facing README history and Russian dated
  changelog; no fake production claims.
- Почему: mandatory repository workflow and visible product change.
- Файлы: documentation manifest.
- Зависимости: verified behavior.
- Риски: duplicate content, managed-block manual edit, foreign doc scope.
- Проверка: docs refresh/check, Russian prose policy and task-only diff.

### 9.2 `[completed] [critical]` Final requirement/compliance reread

- Что: reread applicable requirements, prompt, design and plan; update every
  matrix row to honest status.
- Почему: no unchecked assumption may become completed.
- Файлы: current plan/task plan.
- Зависимости: 9.1.
- Риски: stale plan after discoveries.
- Проверка: links/evidence per row or explicit `unresolved/not_applicable`.

### 9.3 `[completed] [high]` Repository-wide legacy/debug/secret scan

- Что: search old two-row/mobile-details implementations, duplicate search
  forms, stale data selectors, TODO/debug/console, secrets and unexpected
  binaries.
- Почему: remove only proven task-related stale paths and protect delivery.
- Файлы: whole repository read-only; task files if fix required.
- Зависимости: 9.2.
- Риски: deleting historical docs/compatibility based only on text hit.
- Проверка: dependencies examined before any removal; final exact diff.

## Этап 10 — commit and push

### 10.1 `[completed] [critical]` Exact pre-commit review

- Что: branch/status/remotes, unstaged/staged/untracked, diff/stat,
  secret/debug/mass-format/unrelated checks and cached diff.
- Почему: shared working tree contains foreign work.
- Файлы: Git metadata/exact Task 88 paths.
- Зависимости: all verification.
- Риски: staging foreign MM/AM files.
- Проверка: alternate index from current HEAD and path/hunk-limited Task 88
  content; never `git add .`.

### 10.2 `[completed] [critical]` Commit

- Что: create one logical implementation/docs commit unless evidence must be
  split for exact isolation.
- Почему: user requires completed commit in existing `main`.
- Файлы: only Task 88.
- Зависимости: 10.1 and hooks.
- Риски: pre-commit doc mutation or concurrent HEAD move.
- Проверка: `git diff --cached`, branch `main`, commit hash/message and
  `git show --stat`.

Planned message:

`feat: redesign portal header and global search`

### 10.3 `[completed_unresolved_authentication] [critical]` Push

- Что: run normal non-force push to configured current `main`.
- Почему: explicit user/project delivery requirement.
- Файлы: Git remote only.
- Зависимости: clean tree and commit.
- Риски: known missing GitHub HTTPS credentials or foreign dirt blocks
  strict pre-push.
- Проверка: record exact command/output; external refusal remains
  `unresolved`, never claimed success.

## Initial compliance matrix

| Requirement | Status | Evidence / next gate |
| --- | --- | --- |
| Fresh root/index/canonical read | `completed` | 26.07.2026 pre-edit audit |
| Relevant feature Markdown read | `completed` | Header/search/mobile/auth/cache/ops owners and prior spec/plan traced |
| Actual versions/database/stack | `completed` | PHP 8.5.8, Laravel 13.22, Livewire 4.3.3, Boost 2.4.13, Tailwind 4.3.2, Vite 8.1.4, PHPUnit 12.5.32, SQLite |
| Official version-specific docs | `completed` | Boost Laravel/Livewire docs and Context7 Tailwind 4 docs |
| Existing implementation first | `completed` | Complete Blade/Vite/API/query/cache/test trace |
| Expected/protected manifests | `completed` | Above |
| Cross-feature impact | `completed` | Auth/search/cache/SEO/mobile/player/production mapped |
| Canonical rule update before code | `completed` | UI/frontend/search/security owners updated before application code |
| Detailed plan reread | `completed` | Full 504-line plan reread after canonical gate |
| TDD RED/GREEN | `completed` | RED failures recorded; focused GREEN: 22 tests, 167 assertions |
| No migration/index guess | `already_compliant` | SQLite EXPLAIN uses existing covering pivot PK; no DDL |
| Validation/auth/security/privacy | `completed` | Existing Form Request/middleware preserved; same-origin/textContent/session-only audit |
| Performance/cache | `completed` | Fixed query budget, EXPLAIN, format v2 and bounded browser caches |
| RU/EN translations | `completed` | Translation parity: 3 tests, 76 989 assertions |
| Accessibility/responsive/browser | `completed` | 18 focused header/auth Playwright cases across desktop/mobile/tablet plus manual PNG review |
| Documentation/README/CHANGELOG | `completed` | Canonical owners, themed docs and visitor/product history updated |
| Final legacy/secret/diff audit | `completed` | Old two-row selectors/docs, debug sinks and exact 45-file staged scope scanned |
| Commit/push `main` | `completed_with_unresolved_push` | Commit `d36b6a6`; normal `GIT_TERMINAL_PROMPT=0 git push origin main` failed with code 128 before transfer because GitHub HTTPS credentials are unavailable |

## Verification evidence

- Focused RED/GREEN: final `22` tests, `167` assertions; post-static focused
  rerun `7` tests, `39` assertions.
- Related search/auth/layout/localization: `64` tests, `77 356` assertions.
- Unit suite: `557/557`, `183 212` assertions.
- Root Feature A–D: `600` passed, `9` skipped, `4 274` assertions.
- Root Feature E–P after wordmark contract update: `177/177`,
  `1 409` assertions.
- Remaining root Feature Q–Z: `364` passed, `2` skipped and one unrelated
  error because foreign shared-tree class
  `SeasonvarImportDispatchBatcher` is absent.
- Feature subdirectories: `310` passed and one unrelated existing
  `WebAccountManagementTest` flash-state failure, reproduced alone.
- Full monolithic `php artisan test`: first process exhausted cumulative
  `256M` in the intentionally large public-page cache test; that test passed
  alone (`1/1`, `9` assertions). A `1G` CLI-only repeat was externally
  stopped with exit `143`, so split suites above are the authoritative broad
  evidence.
- Pint exact scope, PHP syntax, task-scoped PHPStan and Rector dry-run:
  passed.
- Translation parity: `3` tests, `76 989` assertions.
- `composer validate`, route inspection, `project:docs-refresh --check`,
  `view:cache`/`view:clear`: passed.
- Vite `8.1.4`: `26` modules, successful production build.
- Playwright: Desktop `6/6`, Mobile `6/6`, Tablet `6/6`; no task scenario
  failure. Manual desktop/mobile empty-search and sticky screenshots
  inspected.
- SQLite `EXPLAIN`: existing covering
  `sqlite_autoindex_catalog_title_country_1` plus country integer PK; no
  migration/index.
- Exact hook-enabled implementation/docs commit:
  `d36b6a6` (`feat: redesign portal header and global search`), 45 files.
- Normal non-force `GIT_TERMINAL_PROMPT=0 git push origin main` reached the
  configured GitHub HTTPS remote and failed before transfer with code 128:
  `fatal: could not read Username for 'https://github.com': terminal prompts disabled`.
  Force push, remote changes and history rewriting were not performed.
