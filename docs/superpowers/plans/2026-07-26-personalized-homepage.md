# Personalized Homepage Implementation Plan

> **Execution:** implement sequentially in the current session with
> `superpowers:executing-plans`; do not create a branch, worktree or subagent.

**Goal:** сократить гостевую главную и превратить authenticated главную в
персональную стартовую страницу, сохранив API, cache, visibility и Laravel
boundaries.

**Architecture:** существующий `CatalogHomePageBuilder` остаётся единственным
owner web/API projection. Web-only sections получают данные из существующих
recommendation, viewing activity, personal update, collection, facet,
snapshot и count-loader services. Blade только выводит подготовленные
collections/DTO/URLs.

**Stack:** PHP 8.5.8, Laravel 13.22.0, Livewire 4.3.3, Blade, Tailwind CSS
4.3.2, Vite 8.1.4, PHPUnit 12.5.32, SQLite.

## Global constraints

- Работать и коммитить только в существующей `main`.
- Не включать foreign staged/unstaged/untracked scope.
- Сначала тест, подтвердить RED, затем production code.
- Не менять `/api/v1/home` response shape, public routes, SEO или cache keys.
- Не выполнять запросы и domain decisions из Blade.
- Не добавлять dependency, migration, seed data, fake content или TODO.
- Guest shared response никогда не содержит personal data.
- UI следует `docs/UI_STANDARDS.md`: light slate/white, emerald action,
  borders without card shadows, `text-slate-600` для metadata, 44px targets.

## Task 1 — canonical contract и executable plan

Priority/status: `critical / completed`.

- What: обновить UI/frontend/views/architecture/caching owners, design spec,
  current task plan и этот executable plan.
- Why: новый порядок и разрешённые две recommendation surfaces изменяют
  постоянный homepage contract.
- Files: `docs/UI_STANDARDS.md`, `docs/frontend.md`, `docs/views.md`,
  `docs/architecture.md`, `docs/caching.md`, task spec/plan/current plan.
- Dependencies: fresh root/index/requirements/related-doc reads and actual
  source audit.
- Risks: конфликт со старым правилом «не более одной recommendation
  section» и случайное изменение foreign Task 92 docs.
- Verify: inspect exact hunks, ensure the old rule is replaced explicitly,
  reread all new sections before app edits.

## Task 2 — RED: guest projection and ordering

Priority/status: `critical / completed`.

- What: добавить feature test для guest section order, compact metrics,
  five-item trending limit, six-item new-title limit, grouped updates and
  absence of personal markers.
- Why: current HTML starts with five `x-stat` cards and has no explicit
  trending/new-title projection.
- Files: create `tests/Feature/CatalogHomepageRedesignTest.php`.
- Dependencies: factories for title/season/episode/media/view activity,
  recommendation event tables and refreshed homepage snapshot.
- Risks: shared cache may mask fixture changes; sparse recommendation signals
  can produce an empty trend.
- Verify: forget/refresh current cache owners, request `route('home')`, assert
  ordered `data-home-section` offsets and bounded marker counts; run the
  focused class and record intended failures.

## Task 3 — RED: authenticated ownership and ordering

Priority/status: `critical / completed`.

- What: test owner-only continue watching/library updates/personalized rows,
  exact authenticated order and guest isolation.
- Why: personal data must never enter shared guest payload or leak between
  users.
- Files: `tests/Feature/CatalogHomepageRedesignTest.php`.
- Dependencies: current user-state, progress, release-schedule factories and
  `actingAs`.
- Risks: insufficient fixtures may exercise recommendation cold-start rather
  than personalization; update schema readiness must be real.
- Verify: create owner and foreign progress/state, assert owner title visible,
  foreign marker absent, guest personal markers absent; confirm RED before
  service implementation.

## Task 4 — compact personal library query

Priority/status: `high / completed`.

- What: add `UserLibraryQuery::homeUpdates(User, int): Collection` using the
  existing base visibility, personal update predicate, deterministic order,
  bounded limit, count loader and update indicator hydration.
- Why: reusing a full paginator on home would add an unnecessary count query
  and query-string coupling.
- Files: `app/Services/Catalog/UserLibraryQuery.php`;
  `tests/Feature/CatalogHomepageRedesignTest.php`.
- Dependencies: `CatalogPersonalUpdateQuery`, `PersonalLibrarySchema`,
  `CatalogTitleCardCountLoader`.
- Risks: schema-not-ready path, N+1 relations, incorrect owner binding.
- Verify: no paginator/count query, max six rows, exact owner ID predicate,
  visible title scopes and update labels; focused test GREEN.

## Task 5 — web-only homepage projection

Priority/status: `critical / completed`.

- What: extend `CatalogHomePageBuilder::webData()` with `isPersonalizedHome`,
  `trendingItems`, guest `newTitleItems`, authenticated
  `continueWatchingItems`/`libraryUpdateStates`, personal recommendation,
  bounded update groups and prepared account URLs.
- Why: Blade must receive final ordered data without querying or selecting
  recommendation types.
- Files: `app/Services/Catalog/CatalogHomePageBuilder.php`;
  `tests/Feature/CatalogHomeWebProjectionTest.php`;
  `tests/Feature/CatalogHomepageRedesignTest.php`.
- Dependencies: recommendation, viewing activity, user library and existing
  cache/snapshot services.
- Risks: changing API `data()` shape/queries, duplicate recommendation calls,
  remembering guest rows, private data in shared response.
- Verify: API resource regression unchanged; `webData(null)` never invokes
  personal queries; auth projection bounded; recommendation items already
  eager-load relations/counts/states.

## Task 6 — exact compact release summary

Priority/status: `high / completed`.

- What: preserve eight hydrated rows per title but carry exact window total
  from the existing ranked query, then prepare range/count/time and latest
  playable URL in `LatestMediaCard`.
- Why: ten episodes must render one compact card with an exact count, not
  eight rows or an invented value.
- Files: `app/Services/Catalog/CatalogHomeContentAdditionQuery.php`,
  `app/View/Components/Catalog/LatestMediaCard.php`,
  `resources/views/components/catalog/latest-media-card.blade.php`,
  `tests/Feature/CatalogHomeContentAdditionTest.php`.
- Dependencies: current window-function query and foreign Task 90 correlated
  season hunk, which must remain untouched.
- Risks: SQLite query-plan regression, media-only update, gaps in episode
  numbers, inaccessible release URL.
- Verify: sequential 185–194 renders exact range/count; gaps render a
  truthful count; media-only group is labelled correctly; existing query
  budget and availability tests pass; inspect EXPLAIN for the changed ranked
  hydration.

## Task 7 — localized relative time

Priority/status: `medium / completed`.

- What: add a time-only formatter method and ru/en home strings for today/date
  plus time, plural metric/update/card labels.
- Why: metadata must use account timezone/locale and cannot format dates in
  Blade.
- Files: `app/Services/Auth/AccountDateTimeFormatter.php`,
  `lang/ru/home.php`, `lang/en/home.php`,
  `tests/Unit/AccountDateTimeFormatterTest.php` or nearest existing test.
- Dependencies: PHP Intl and current formatter fallback.
- Risks: ru/en key mismatch, 12/24-hour locale behavior, timezone drift.
- Verify: fixed-time assertions in both locales/timezones and translation
  parity tests.

## Task 8 — compact reusable title-card layouts

Priority/status: `high / completed`.

- What: add `home`, `spotlight` and `trend` layouts to existing `TitleCard`
  and `PosterCard`, max two genres, one count line, no description for
  ordinary cards, four-line description and two actions for desktop
  spotlight.
- Why: ordinary homepage cards need a consistent 2:3 visual hierarchy
  without new duplicated card logic.
- Files: `app/View/Components/Catalog/TitleCard.php`,
  `app/View/Components/Ui/PosterCard.php`,
  create `resources/views/components/catalog/title-card-home.blade.php`,
  create `resources/views/components/catalog/title-card-trend.blade.php`,
  `tests/Feature/CatalogBladeComponentTest.php`.
- Dependencies: eager-loaded genres/ratings and batched counts.
- Risks: stretched link covering nested buttons, third genre leak, title
  truncation, mobile layout mismatch.
- Verify: component tests assert 2:3 marker, max two genres, no description,
  escaped title, valid watch/details links and visible focus classes.

## Task 9 — guest homepage Blade

Priority/status: `critical / completed`.

- What: replace the two-column panel stack with ordered divider sections,
  compact metric `dl`, responsive trending composition, grouped updates,
  title grids, featured collections and a single divider-led facet section.
- Why: this is the requested information architecture and removes the
  card-in-card hierarchy.
- Files: `resources/views/livewire/catalog-home-page.blade.php`.
- Dependencies: Tasks 5–8 prepared view data/components/translations.
- Risks: mobile overflow, duplicate title DOM, missing empty states, wrong
  section order.
- Verify: feature marker order, DOM source review, `detect.mjs --scope
  layout`, no forbidden overflow/shadow/text contrast classes.

## Task 10 — authenticated homepage Blade

Priority/status: `critical / completed`.

- What: render continue watching, library updates, personal recommendations,
  trending, updates, and account collections/calendar in exact order.
- Why: authenticated home is a personal return surface, not a copy of guest
  discovery.
- Files: `resources/views/livewire/catalog-home-page.blade.php`.
- Dependencies: owner-scoped data from Task 5.
- Risks: empty account sees a blank page, personal content accidentally
  duplicated in guest branch.
- Verify: owner/foreign/guest feature assertions, empty states with working
  links, browser test with seeded authenticated account.

## Task 11 — query, performance and security audit

Priority/status: `high / completed`.

- What: capture SQL count for guest/auth, inspect repeated/eager loads,
  `EXPLAIN QUERY PLAN` for new compact library and release queries, search
  Blade for DB/service calls and personal payload leakage.
- Why: home is a high-traffic page and authenticated private data is
  high-impact.
- Files: relevant tests and `docs/performance.md` only if new measured
  evidence is material.
- Dependencies: implementation GREEN.
- Risks: Redis-backed cache unavailable locally, test fixtures smaller than
  production, misleading timings.
- Verify: bounded query-count assertions, plan evidence tied to existing
  indexes, no cache-key change, SQL bindings only, no user identifiers in
  guest response.

## Task 12 — focused and regression verification

Priority/status: `critical / completed`.

- What: run syntax, focused home/content/component/auth/cache/API/security
  tests, exact Pint, PHPStan changed scope, then full `php artisan test` and
  Vite build.
- Why: homepage touches recommendations, cache, localization, user library
  and shared card presentation.
- Files: no intended files except formatter output.
- Dependencies: Tasks 2–11 complete.
- Risks: foreign dirty changes can cause unrelated failures; formatter may
  touch foreign PHP if invoked broadly.
- Verify: record every command/result; use exact path list where needed; do
  not hide failures. Task 94 focused suites, Pint, PHPStan and Vite are
  green; full sharded suite has separately attributed foreign-tree failures.

## Task 13 — browser and visual QA

Priority/status: `critical / completed`.

- What: verify the real guest route at desktop and 390px mobile, inspect
  screenshots and accessibility snapshots, measure hierarchy/overflow and
  check the console. Authenticated order and isolation are verified through
  Laravel feature tests because no safe browser credential was available.
- Why: responsive hierarchy and page length cannot be proven by PHP tests.
- Files: `tests/browser/catalog.spec.js` and generated untracked screenshots
  outside commit.
- Dependencies: successful Vite build and seeded browser fixtures.
- Risks: local HTTPS/runtime/cache state, foreign fixture changes.
- Verify: real route 200, section order, no horizontal overflow, mobile
  two-column grid, compact metrics `2+2+1`, screenshots opened with image
  viewer, no JS console errors.

## Task 14 — documentation and compliance closure

Priority/status: `high / completed`.

- What: update README visitor capability/history, Russian CHANGELOG, relevant
  owners/evidence and every compliance status; run docs refresh/check when
  applicable.
- Why: product-visible change and mandatory repository workflow.
- Files: `README.md`, `CHANGELOG.md`, current task plan and affected docs.
- Dependencies: verified implementation facts only.
- Risks: shared foreign doc hunks and managed `project-docs` blocks.
- Verify: exact diff, Russian prose, visitor history remains final H2,
  `project:docs-refresh --check`, documentation policy tests.

## Task 15 — final audit, commit and push

Priority/status: `critical / completed_commit_unresolved_push_authentication`.

- What: reread applicable requirements/spec/plan; scan legacy homepage
  implementations, TODO/debug/secrets; inspect status/diff/stat/index/remote;
  stage only Task 94 hunks using an alternate index if required; commit and
  non-force push `main`.
- Why: shared worktree contains unrelated staged/unstaged/untracked changes.
- Files: exact Task 94 manifest only.
- Dependencies: all verification and docs complete.
- Risks: pre-commit sees foreign docs, remote authentication currently
  unresolved, accidental inclusion of Task 90–92.
- Verify: `git diff --cached` exact review, staged secret scan, branch
  `main`, clean Task 94 alternate index, commit hash/message, actual push
  output. External refusal remains honestly `unresolved`.

Final pre-delivery evidence after rebasing the isolated Task 94 index onto
the latest `main`: PHPUnit matrix — `95` tests / `727` assertions; explicit
Task 94 Pint — passed; PHPStan — `0` errors; Vite `8.1.4` production build —
`26` modules. The sharded full suite remains honestly unresolved only
because of eight attributed foreign-tree failures listed in the
current-task matrix.

Delivery evidence: implementation commit
`b22a74774e79a5b0f18f76c16fae1c47c9ec2290`
(`feat: redesign personalized homepage`) создан в существующей `main`.
Configured non-force `GIT_TERMINAL_PROMPT=0 git push origin main` завершился
кодом `128` до передачи данных:
`fatal: could not read Username for 'https://github.com': terminal prompts disabled`.
