# Player Fullscreen Black and Scoped Facebook Palette Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Project rules prohibit a new branch/worktree and subagent delegation, so execution stays inline on the existing `main`.

**Goal:** Сделать CSS-управляемую область видео полностью чёрной во всех
fullscreen variants и применить полную функциональную Facebook-inspired
палитру только к существующему проигрывателю.

**Architecture:** Existing `[data-player-shell]` остаётся единственной
style-boundary. Scoped CSS custom properties управляют Plyr, media root,
central controls, menu и status states; explicit standard/WebKit/fallback
fullscreen selectors устраняют прозрачную подложку без JavaScript,
дублирующего player или fake fullscreen.

**Tech Stack:** PHP 8.5.8, Laravel 13.21.1, Livewire 4.3.3, Tailwind CSS
4.3.2, Vite 8.1.4, Plyr 3.8.4, HLS.js 1.6.16, PHPUnit 12.5, Playwright 1.61.1.

## Global Constraints

- Работать только в существующей `main`; branch, worktree, PR и subagent не
  создавать.
- Этот finite item одновременно регистрируется как Player Task 22 и System
  Task 45; прежние Tasks не перенумеровываются, следующий intake остаётся
  Player Task 23+ / System Task 46+ без верхнего лимита.
- Scope — только существующий player. Общая светлая slate/emerald тема портала
  и global Tailwind `@theme` не меняются.
- Сохранять один `<video>`, Plyr, HLS.js, `CatalogPlayerSession`, keyed
  `wire:ignore`, signed grants, entitlement, progress и in-place transition.
- Не добавлять route, migration, database write, cache key, queue, permission,
  translation key, dependency, environment variable, service worker или
  provider access.
- CSS не обещает управление OS-owned native iOS fullscreen; fake fullscreen
  запрещён, real-device state остаётся `unresolved_device`.
- Frontend delivery требует matching Vite manifest и hashed assets.
- Existing foreign dirty files не reset/stash/delete и не включать в commit.

---

## Exact File Map

Create:

- `docs/superpowers/specs/2026-07-24-player-fullscreen-facebook-palette-design.md`
- `docs/superpowers/plans/2026-07-24-player-fullscreen-facebook-palette.md`

Modify application/test:

- `tests/Unit/FrontendAssetContractTest.php` — static palette/fullscreen contract.
- `tests/browser/player-lifecycle.spec.js` — computed normal/fullscreen color regression.
- `resources/css/app.css` — scoped tokens, black media roots and player colors.

Modify canonical owners/evidence:

- `docs/UI_STANDARDS.md`
- `docs/frontend.md`
- `docs/audits/video-playback-report.md`
- `docs/plans/current-task-plan.md`
- `docs/superpowers/plans/2026-07-24-player-seamless-episode-switching.md`
- `docs/superpowers/plans/2026-07-24-system-maintenance-and-optimization-master-plan.md`
- `README.md`
- `CHANGELOG.md`

Must remain unchanged by this task:

- `resources/js/player.js`
- `resources/views/livewire/catalog-title-player.blade.php`
- `app/Livewire/CatalogTitlePlayer.php`
- `lang/ru/catalog.php`, `lang/en/catalog.php`
- `routes/*`, `database/migrations/*`, `config/*`
- `composer.json`, `composer.lock`, `package.json`, `package-lock.json`

## Interfaces

- Consumes: existing `[data-player-shell]`, `.plyr`,
  `video.js-catalog-player`, `data-player-state`, Plyr fullscreen classes and
  existing menu/center-control class names.
- Produces: scoped `--catalog-player-*` CSS variables and explicit black
  computed backgrounds without a JavaScript or server API.
- Preserves: player DOM identity, Livewire island, progress/source transitions,
  public routes, RU/EN copy and all persisted contracts.

---

### Task 1: Register Requirements, Scope and Rollback

**Files:**

- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: both unlimited master plans listed in Exact File Map.

- [x] **Step 1: Add the canonical player-specific palette exception**

Add to the player section of `docs/UI_STANDARDS.md`:

```markdown
Player media surface is the only intentional black UI surface. The catalog
outside the player remains light slate/white with emerald actions. Inside
`[data-player-shell]`, the canonical functional palette is scoped to
`#1877f2`, `#166fe5`, `#e7f3ff`, `#f0f2f5`, `#e4e6eb`, `#ccd0d5`,
`#1c1e21`, `#65676b`, `#42b72a`, `#f7b928`, `#fa383e`, white and black.
```

State that standard, WebKit and fallback fullscreen backgrounds are black
where author CSS applies, while native iOS system fullscreen remains
browser-owned.

- [x] **Step 2: Register exact task identities**

Append Player Task 22 and System Task 45 with the same finite scope,
cross-links, protected contracts, RED/GREEN commands, rollback and separate
`code|verification|commit|push|production|real_device` statuses. Keep the next
uncreated pointers at Player Task 23+ and System Task 46+.

- [x] **Step 3: Update current task compliance**

Record exact expected files, unchanged contracts and this matrix:

| Domain | Status before code |
| --- | --- |
| UI/player CSS | `affected_planned` |
| Fullscreen/browser behavior | `affected_planned` |
| One-player/Livewire lifecycle | `already_compliant_preserve` |
| Localization | `not_applicable_no_new_copy` |
| Auth/source/progress/privacy | `already_compliant_preserve` |
| Routes/API/schema/cache/queues/import | `not_applicable` |
| Production assets | `affected_matching_manifest_required` |
| Native iOS fullscreen | `unresolved_device` |
| Git delivery | `unresolved_shared_worktree_until_clean` |

- [x] **Step 4: Re-read this plan**

Expected: file map, protected interfaces, test order and rollback agree with
the approved design before any application/test edit.

---

### Task 2: Add the Static RED

**Files:**

- Modify: `tests/Unit/FrontendAssetContractTest.php`
- Test: `tests/Unit/FrontendAssetContractTest.php`

- [x] **Step 1: Write the failing static contract**

Inside
`test_player_assets_define_one_cleanup_safe_livewire_session_lifecycle()`,
assert all exact token declarations:

```php
foreach ([
    '--catalog-player-primary: #1877f2;',
    '--catalog-player-primary-hover: #166fe5;',
    '--catalog-player-primary-soft: #e7f3ff;',
    '--catalog-player-surface: #f0f2f5;',
    '--catalog-player-surface-strong: #e4e6eb;',
    '--catalog-player-border: #ccd0d5;',
    '--catalog-player-text: #1c1e21;',
    '--catalog-player-muted: #65676b;',
    '--catalog-player-success: #42b72a;',
    '--catalog-player-warning: #f7b928;',
    '--catalog-player-danger: #fa383e;',
    '--catalog-player-white: #ffffff;',
    '--catalog-player-fullscreen: #000000;',
] as $playerColorToken) {
    $this->assertStringContainsString($playerColorToken, $css);
}
```

Also require these selectors/values:

```php
$this->assertStringContainsString('.plyr:-webkit-full-screen', $css);
$this->assertStringContainsString('.plyr--fullscreen-fallback', $css);
$this->assertStringContainsString('video.js-catalog-player:-webkit-full-screen', $css);
$this->assertStringContainsString('video.js-catalog-player::backdrop', $css);
$this->assertStringContainsString(
    'background: var(--catalog-player-fullscreen);',
    $css,
);
```

- [x] **Step 2: Run and observe the correct RED**

Run:

```bash
php artisan test --filter=FrontendAssetContractTest
```

Expected: failure names the first absent
`--catalog-player-primary: #1877f2;`; no boot/database/network failure counts as
RED.

---

### Task 3: Add the Browser RED

**Files:**

- Modify: `tests/browser/player-lifecycle.spec.js`
- Test: `tests/browser/player-lifecycle.spec.js`

- [x] **Step 1: Extend the existing fullscreen scenario**

Before calling `requestFullscreen()`, capture:

```javascript
const normalBackground = await page.evaluate(() => {
    const player = document.querySelector('[data-player-shell] .plyr');
    const wrapper = player?.querySelector('.plyr__video-wrapper');
    const video = player?.querySelector('video.js-catalog-player');

    return [player, wrapper, video].map((node) => getComputedStyle(node).backgroundColor);
});

expect(normalBackground).toEqual([
    'rgb(0, 0, 0)',
    'rgb(0, 0, 0)',
    'rgb(0, 0, 0)',
]);
```

After fullscreen entry and after the episode transition, assert the same three
computed values plus the fullscreen root backdrop contract:

```javascript
const fullscreenBackground = await page.evaluate(() => {
    const player = document.fullscreenElement;
    const wrapper = player?.querySelector('.plyr__video-wrapper');
    const video = player?.querySelector('video.js-catalog-player');

    return [player, wrapper, video].map((node) => getComputedStyle(node).backgroundColor);
});

expect(fullscreenBackground).toEqual([
    'rgb(0, 0, 0)',
    'rgb(0, 0, 0)',
    'rgb(0, 0, 0)',
]);
```

The shared browser fixture now contains exactly three episodes after the
existing navigation-island work. Update the three stale episode-menu
`toHaveCount(2)` assertions in this browser file to `toHaveCount(3)`; keep the
non-current option selection deterministic with `.first()` because two options
are now non-current, and keep the episode-identity change assertion. This is a
test compatibility repair only and does not change application code or fixture
data.

- [x] **Step 2: Run and observe the correct RED**

Run:

```bash
npx playwright test tests/browser/player-lifecycle.spec.js \
  --project="Desktop Chromium" \
  --grep="fullscreen identity survives"
```

Expected before GREEN: normal `.plyr` and/or native video background is
transparent or slate instead of `rgb(0, 0, 0)`. Fullscreen capability/fixture
failure is not a valid RED.

---

### Task 4: Implement the Scoped CSS GREEN

**Files:**

- Modify: `resources/css/app.css`
- Test: `tests/Unit/FrontendAssetContractTest.php`
- Test: `tests/browser/player-lifecycle.spec.js`

- [x] **Step 1: Define the complete scoped token set**

Add the exact variables from Task 2 to:

```css
[data-player-shell],
.catalog-player-menu {
    /* exact token declarations from the static contract */
}
```

Do not put them in `:root` or `@theme`.

- [x] **Step 2: Make every media layer black**

Add application-owned rules for `[data-player-shell]`, `.plyr`,
`.plyr__video-wrapper`, `video.js-catalog-player`, standard fullscreen,
`:-webkit-full-screen`, `.plyr--fullscreen-active`,
`.plyr--fullscreen-fallback` and both `::backdrop` selectors. Each media layer
must resolve to `var(--catalog-player-fullscreen)`.

- [x] **Step 3: Map the palette to Plyr and portal controls**

Set supported Plyr variables for main/focus/toggle/control hover, range fill,
menu, tooltip, badge and video background. Replace player-menu emerald/slate
colors and center-control emerald/focus colors with scoped tokens.

Map status states:

- `loading|ready|playing|paused|ended` use primary/success roles;
- `buffering|retrying|expired` use warning;
- `error|fatal` use danger;
- caption warning and data-saver/notice keep their text/icon semantics.

Keep existing text, icon, `role`, `aria-live`, touch target, safe area,
visibility and reduced-motion behavior.

- [x] **Step 4: Run static GREEN**

Run:

```bash
php artisan test --filter=FrontendAssetContractTest
```

Expected: all `FrontendAssetContractTest` tests pass.

- [x] **Step 5: Build and run browser GREEN**

Run:

```bash
npm run build
npx playwright test tests/browser/player-lifecycle.spec.js \
  --project="Desktop Chromium" \
  --grep="fullscreen identity survives"
```

Expected: Vite builds and the standard fullscreen scenario passes with black
normal/fullscreen player, wrapper and video backgrounds.

---

### Task 5: Run Affected Regression and Visual Review

**Files:**

- Verify: player application/test files in Exact File Map.

- [x] **Step 1: Run focused PHP contracts**

```bash
php artisan test --filter='FrontendAssetContractTest|CatalogPlayerCopyTest|LivewireWireIgnoreContractTest|CatalogVisualSystemTest'
```

Expected: all focused tests pass with no new translation or DOM owner.

- [x] **Step 2: Run the full player browser matrix**

```bash
npx playwright test tests/browser/player-lifecycle.spec.js
```

Expected: all applicable Desktop/Mobile/Tablet scenarios pass; platform-only
skips remain explicit.

- [x] **Step 3: Inspect desktop, tablet and phone screenshots**

Verify `1440×1200`, `768×1024`, `390×844`: black media surface, blue primary
controls, readable menu/status states, no page overflow, one video/Plyr and
unchanged 56/68 px central targets. Store artifacts only under ignored
`output/playwright/`.

- [x] **Step 4: Run production asset and static checks**

```bash
npm run build
git diff --check
php artisan project:docs-refresh --check
```

Expected: matching Vite build, clean whitespace and no managed-doc drift.

---

### Task 6: Update Owners, Visitor History and Compliance

**Files:**

- Modify: `docs/frontend.md`
- Modify: `docs/audits/video-playback-report.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: current/master/task plans.

- [x] **Step 1: Record actual delivered behavior**

Document the scoped palette, explicit media/fullscreen backgrounds, standard
Chromium evidence and truthful native iOS limitation. Do not claim real-device
or production activation without evidence.

- [x] **Step 2: Update visitor-visible documentation**

Add one Russian visitor-history entry to the final README section describing
the black fullscreen background and refreshed blue player colors. Add a
separate Russian technical CHANGELOG bullet without rewriting earlier entries.

- [x] **Step 3: Re-read applicable canonical requirements**

Re-read `UI_STANDARDS.md`, `frontend.md`,
`audits/video-playback-report.md`, security/performance/cache/production
owners, this plan and the user request. Update every compliance row only from
fresh evidence.

- [x] **Step 4: Search for stale related implementation**

```bash
rg -n --hidden -g '!vendor/**' -g '!node_modules/**' \
  \"--plyr-color-main|--plyr-video-background|plyr--fullscreen|player-fullscreen|catalog-player-primary|#059669\" \
  resources tests docs
```

Expected: one canonical player palette/fullscreen implementation; historical
documentation may mention the previous emerald baseline only as dated
evidence.

- [x] **Step 5: Run policy checks**

```bash
scripts/check-readme-policy.sh README.md
scripts/check-changelog-policy.sh CHANGELOG.md
bash scripts/ci-check.sh docs
git diff --check
```

Any pre-existing unrelated failure remains explicit and is not bypassed.

---

### Task 7: Git Delivery Boundary

**Files:**

- Review only exact files in this plan.

- [x] **Step 1: Verify branch and shared worktree**

```bash
git status --short --branch
git diff -- resources/css/app.css \
  tests/Unit/FrontendAssetContractTest.php \
  tests/browser/player-lifecycle.spec.js
```

Expected: branch is `main`; exact task hunks are distinguishable from foreign
work.

- [x] **Step 2: Commit only when canonical clean-tree hook can pass**

Do not stage, reset, stash, delete or absorb foreign importer, collection,
dependency, calendar, review or player work. If any foreign tracked/untracked
file remains, set commit/push to `unresolved_shared_worktree` and stop before
staging.

If the tree becomes clean and ownership is verified, stage only the exact
manifest, run canonical pre-commit, commit in `main`, then perform ordinary
non-force `git push origin main`. Authentication/network failure is
`unresolved_auth`, not successful delivery.

Result: normal repository on existing `main`, but shared tracked and untracked
foreign work remains across importer, collections, calendar, player and other
owners. Nothing was staged, reset, stashed, deleted, committed or pushed.
Task 22 delivery is `unresolved_shared_worktree`; production activation is
`not_activated_old_asset_observed`.

## Execution Evidence

- Static RED: first missing `--catalog-player-primary`; browser RED:
  transparent `.plyr` and slate video.
- Static GREEN: `6/6`, `353` assertions; focused related PHPUnit: `45/45`,
  `801` assertions.
- Final full PHPUnit: `1572` tests, `1561` passed, `11` skipped, `123934`
  assertions. Its first run had one random factory uniqueness collision; the
  exact test and the complete rerun both passed.
- Full player Playwright: `18` passed, `12` expected skips; final focused
  standard fullscreen regression: `1/1`.
- Desktop/tablet/mobile visual review: `3/3`; Vite: `24` modules,
  `app-BLl6ilyS.css`.
- `project:docs-refresh --check`, docs profile, README policy and
  `git diff --check` passed. CHANGELOG policy advanced past the Task 22 entry
  and stopped at the pre-existing ordinary text `read-only` on current line 7;
  that foreign entry was preserved.
- Live HTTPS still referenced `app-BNI4GkbQ.css`; therefore local verification
  is complete, while production activation and real-device iOS remain
  unresolved independently.

## Plan Self-Review

- [x] Every approved design requirement maps to a task.
- [x] Exact palette, fullscreen selectors, test assertions, commands,
  compatible contracts and rollback are specified.
- [x] No schema, route, translation, dependency or JavaScript work is implied.
- [x] Native iOS and production activation remain separately unresolved.
- [x] Player Task 22 / System Task 45 are finite; later tasks have no ceiling.
- [x] No implementation placeholder or fake capability is present.
