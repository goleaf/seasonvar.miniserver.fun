# Cross-device quality program implementation plan

> Реализация выполняется последовательно в существующей ветке `main`.
> Каждый phase перечитывает применимые requirements, обновляет compliance
> evidence и завершается independently green commit перед следующим phase.

**Goal:** довести phone/tablet/desktop/TV-like качество всех основных
Seasonvar surfaces до измеримого responsive, accessibility и performance
контракта без второго frontend и без изменения server-owned прав.

**Design:** [cross-device quality program design](../specs/2026-07-26-cross-device-quality-program-design.md).

**Stack:** Laravel 13.22, Livewire 4.3.3, Tailwind CSS 4.3, Vite 8.1,
Playwright Chromium, axe, PHPUnit 12.5.

## Global constraints

- Existing `main` only; no branch/worktree/PR branch.
- Preserve unrelated shared-tree changes through exact allowlisted commits.
- No device/user-agent authorization, route or cache variants.
- No new dependency, PWA/service worker, offline video, push, analytics or
  spatial-navigation library without separate explicit design and approval.
- No hidden mobile functionality, hover-only action, fake control, inline
  CSS/business JS, `@php`, duplicate Blade tree or database query in view.
- Phone/tablet/TV changes preserve RU/EN, long labels, no-JS fallback,
  privacy, SEO, cache and player boundaries.
- Physical device/TV evidence is never inferred from Chromium emulation.

## Baseline inventory

Expected foundation changes:

- `playwright.config.js`;
- new `tests/browser/cross-device-quality.spec.js`;
- existing browser helpers/specs only when duplication is safely extracted;
- `docs/UI_STANDARDS.md`, `docs/frontend.md`, `docs/testing.md`;
- this plan, current plan, README/CHANGELOG after visitor-visible behavior.

Potential later application changes after RED evidence:

- `resources/views/layouts/app.blade.php`;
- `resources/views/components/layout/{site-header,header-search,site-footer}.blade.php`;
- `resources/css/app.css`;
- high-traffic public components for home/catalog/discover/collections;
- title/player/season/episode components and existing player modules;
- private/auth/library/profile/settings/calendar components;
- administration/support/premium components and shared UI primitives;
- adjacent Livewire/page builders only if payload/query evidence requires it.

Protected files/contracts:

- `routes/web.php`, `routes/api.php` and all public route names/URLs;
- policies/gates/middleware and private response policies;
- catalog/media/import/storage/schema contracts;
- translation file/key identity and locale selection;
- cache domain/key/invalidation ownership;
- SEO canonical/hreflang/sitemap/feed;
- API response shapes and Livewire server-owned state.

## Risks and decisions

| Domain | State | Decision |
| --- | --- | --- |
| migrations/data | `not_applicable_foundation` | no DDL/DML |
| routes/API | `protected_unchanged` | presentation only |
| translations | `affected_validation` | reuse existing keys; add RU/EN only when truthful new copy is unavoidable |
| cache | `protected_review` | no device dimension/full flush |
| permissions/privacy | `protected_review` | no client capability authority |
| search/SEO | `protected_review` | same URL state and first SSR content |
| player | `high_risk_later_phase` | preserve keyed ignore/lifecycle/grants |
| performance | `affected_measured` | query/payload/assets recorded per phase |
| production | `asset_deploy_only_foundation` | Vite manifest atomically deployed; code revert + rebuild |
| physical TV/device | `unresolved_external_evidence` | repository cannot claim it |

---

## Phase 0 — contract and evidence harness

### Task 0.1: RED matrix

- [x] Add opt-in Playwright projects for narrow phone, phone landscape,
  tablet landscape and TV-like Chromium without multiplying the default CI
  suite unexpectedly.
- [ ] Add a representative cross-device spec that visits shell/home,
  catalog, `/discover/popular`, title/player, auth/private fallback and admin
  authorization/truthful state.
- [x] Collect HTTP, H1, horizontal overflow, first-party failure,
  console/page error, visible focus, accessible name, target size and dialog
  containment evidence.
- [ ] Add long RU/EN label checks and no raw translation-key rendering.
- [x] Reproduce at least one current layout/focus failure before application
  changes; do not weaken an already-correct contract to manufacture RED.

### Task 0.2: runtime isolation

- [x] Reuse process-scoped SQLite, config/routes cache paths, ports and
  array cache stores from existing Playwright configuration.
- [x] Block external requests and avoid full video downloads.
- [x] Store screenshots/reports only under ignored `output/playwright/`.
- [x] Document `PLAYWRIGHT_DEVICE_MATRIX=extended` (or equivalent exact
  name) and focused commands.

Acceptance:

- default browser suite retains its three current projects;
- extended matrix is deterministic and independently invokable;
- no production DB/cache/session/provider state is read or changed.

---

## Phase 1 — adaptive shell and navigation

### Task 1.1: header composition

- [ ] Write focused failing geometry tests for `320`, `768`, `1024`,
  `1440`, `1920` and long RU/EN navigation labels.
- [x] Replace the current `sm` full-navigation switch with a content-driven
  phone/tablet/desktop composition.
- [x] Keep one DTO/navigation list and one progressive GET search.
- [ ] Verify mobile disclosure, keyboard escape/return, no-JS links and
  private/header actions.

### Task 1.2: search and virtual keyboard

- [ ] Verify search dropdown containment with visual viewport and safe areas.
- [ ] Preserve autocomplete cancellation/stale guards, keyboard listbox
  semantics and neutral input focus frame.
- [ ] Ensure focused input and validation remain visible with representative
  virtual-keyboard viewport contraction.

### Task 1.3: footer/main/announcements

- [ ] Constrain prose/read measure without shrinking productive data regions.
- [ ] Verify safe-area padding, skip link, route/offline announcements and
  footer navigation at all contexts.
- [x] Add stronger TV-like keyboard focus only through common semantic
  styles; no custom spatial engine.

Acceptance:

- shell has no page overflow at all seven viewports;
- every primary route/action remains reachable without hover and without JS;
- long RU/EN labels do not clip;
- header/footer do not increase server queries or duplicate navigation data.

---

## Phase 2 — home, catalog, discover and collections

### Task 2.1: home and catalog density

- [ ] Audit first-screen priority, list poster geometry, filters, alphabet,
  sort, page size, pagination and empty/loading/error states.
- [ ] Use one-column phone, measured tablet grouping and productive desktop
  density without identical decorative card grids.
- [ ] Preserve SSR, indexability, query budgets, paginator URL/back-forward
  and constrained eager loads.

### Task 2.2: `/discover`

- [ ] Preserve text-only collection cards and remove any remaining image,
  fallback, upload/storage and dead presentation path.
- [ ] Keep collection category/subcategory navigation usable at `320`,
  tablet landscape, desktop and TV-like keyboard focus.
- [ ] Verify 500+ collection pagination/search/sort/category state, empty
  category honesty and section anchors.
- [ ] Measure query count, SQL plan and Livewire payload after any component
  restructuring; no query in Blade.

### Task 2.3: collection pages/editor

- [ ] Adapt public detail, owner dashboard/editor and admin classification
  for touch/tablet while preserving all policies, preview/confirm boundaries
  and text-only cards.
- [ ] Replace only proven problematic tables with semantic labelled rows or
  local scroll regions.

Acceptance:

- discovery Playwright passes all seven contexts RU/EN;
- no collection image element/storage path;
- category/subcategory/filter/pagination URLs remain compatible;
- existing query-budget tests stay green or become stricter with evidence.

---

## Phase 3 — title, player, seasons and episodes

### Task 3.1: title information hierarchy

- [ ] Audit title hero/poster/metadata/quick navigation/recommendations/reviews
  at narrow, landscape, tablet and TV-like viewports.
- [ ] Preserve first SSR metadata and prepared DTO boundaries.

### Task 3.2: player geometry and focus

- [ ] Extend existing player lifecycle matrix to `320×720`,
  `1024×768` and `1920×1080`.
- [ ] Verify fullscreen/PiP/browser ownership, center controls, menu
  containment, focus trap/return, reduced motion and error states.
- [ ] Preserve the single keyed `wire:ignore`, signed source grants,
  navigation history and progress ordering.
- [ ] Keep touch controls reachable and TV-like sequential focus visible.

### Task 3.3: seasons/episodes/media variants

- [ ] Adapt large season/episode sets without loading all rows.
- [ ] Keep audio/subtitle/quality/format truth scannable and no unsupported
  controls.
- [ ] Verify next/previous navigation across season boundaries with no
  duplicate queries or player recreation.

Acceptance:

- existing player lifecycle assertions remain green;
- no raw provider URL or private state reaches DOM/snapshot;
- no horizontal overflow/menu clipping;
- no regression in query count, player recreation or source authorization.

---

## Phase 4 — auth, private portal and calendar

### Task 4.1: auth and forms

- [ ] Test `320`, landscape, virtual keyboard, password reveal, validation,
  loading/offline and recovery flows.
- [ ] Keep 16px phone controls, `44px` targets, correct autocomplete and
  secure server flow.

### Task 4.2: library/profile/settings/security

- [ ] Audit tabs, filters, markers, dialogs, session tables and destructive
  confirmations for phone/tablet/TV-like keyboard.
- [ ] Preserve owner-only state, no-store/noindex, URL state and bounded
  Livewire payload.

### Task 4.3: calendar

- [ ] Adapt month/week/day/mine/feed controls in both orientations.
- [ ] Preserve private feed tokens, owner scopes, date semantics and
  accessibility announcements.

Acceptance:

- no private state leaks into public cache/HTML;
- all current owner actions reachable and keyboard/touch operable;
- no route, permission, session or token contract changes.

---

## Phase 5 — administration, support and Premium

### Task 5.1: administration shell/tables

- [ ] Audit all staff routes at `768×1024` and `1024×768`, plus emergency
  `390×844`.
- [ ] Prioritize summary, filter, row action and confirmation; use local
  semantic scrolling or labelled row adaptation without removing columns or
  permissions.
- [ ] Preserve navigation/gates, optimistic locks, audit and no-store/noindex.

### Task 5.2: requests/issues/help/comments/reviews/tags

- [ ] Verify dense queues, editors, attachments, previews, timelines and
  moderation controls at phone/tablet/desktop.
- [ ] Keep internal/private data out of normal-user presentation and browser
  artifacts.

### Task 5.3: Premium truthful states

- [ ] Adapt public pricing/unavailable state and admin flows without
  inventing plans/providers/payments.
- [ ] Preserve hosted checkout, amount/currency and entitlement server
  ownership.

Acceptance:

- all administration actions remain authorized and available on tablet;
- no fake control/provider state;
- dense pages do not create page overflow or hidden destructive actions.

---

## Phase 6 — performance, accessibility and rollout

### Task 6.1: repository-wide quality scan

- [ ] Search for remaining hover-only, tiny targets, fixed widths, page-level
  overflow, unbounded dialogs, duplicated device markup, inline CSS/JS and
  hardcoded visible text.
- [ ] Classify each hit before editing; text search is not proof of defect.

### Task 6.2: performance budgets

- [ ] Record query counts/plans for changed high-traffic pages.
- [ ] Record Livewire snapshot/request counts for changed components.
- [ ] Record Vite manifest/chunk gzip sizes before/after.
- [ ] Verify no one-query-per-card, duplicate listeners or external
  provider-per-render behavior.

### Task 6.3: accessibility matrix

- [ ] Axe serious/critical, keyboard forward/back, Enter/Escape, visible
  focus, reduced motion, high contrast/forced colors and `200%` zoom.
- [ ] Verify headings, landmarks, names/descriptions, live regions and
  focus restoration.

### Task 6.4: real-device acceptance

- [ ] Record physical iPhone and Android results when available.
- [ ] Record physical tablet portrait/landscape results when available.
- [ ] Record actual target Smart TV/browser/remote model when available.
- [ ] Keep missing hardware evidence `not_performed`, never inferred.

### Task 6.5: delivery

- [ ] Reread applicable requirements before every phase completion.
- [ ] Update UI/frontend/testing/feature docs, visitor README and Russian
  CHANGELOG only for factual results.
- [ ] Run focused tests, Pint for PHP, Vite, Playwright matrix, managed docs,
  diff/legacy scans.
- [ ] Commit exact phase files in `main`, push non-force, report external
  rejection honestly.

## Task-specific compliance matrix

| Requirement/domain | Status | Evidence / gate |
| --- | --- | --- |
| root/index/canonical requirements | `completed_preparation` | fresh read begun 26.07.2026; repeat per phase |
| existing implementation first | `completed_preparation` | shell/CSS/routes/views/browser inventory |
| installed versions/official docs | `completed_preparation` | PHP/Laravel/Livewire/Tailwind/Vite/Playwright verified; Tailwind 4 docs checked |
| design before code | `completed` | linked evidence-first design |
| expected/protected files/contracts | `completed_preparation` | manifests above |
| migrations/routes/API/env/dependencies | `not_applicable_foundation` | unchanged |
| translations | `affected_validation` | RU/EN long-label/parity gate |
| authorization/privacy | `protected_review` | no client device authority |
| cache/search/SEO | `protected_review` | no device cache/route variants |
| performance | `affected_measured` | query/payload/assets per phase |
| production/rollback | `completed_preparation` | asset/code revert + rebuild; no DDL/DML |
| physical device/TV evidence | `unresolved_external` | cannot be automated locally |
| implementation | `in_progress_phase_0` | extended browser contract next |
| docs/README/CHANGELOG | `in_progress` | factual updates per completed phase |
| commit/push | `pending` | exact phase commits on `main` |
