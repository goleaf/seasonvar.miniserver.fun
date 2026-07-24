# Task 21 — центральные touch-controls проигрывателя

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking. Project rules prohibit a new
> branch/worktree and subagent delegation, so execution stays inline on the
> existing `main`.

**Goal:** дать телефону, планшету и desktop один крупный центральный ряд
`−10 / play·pause / +10`, точно центрированный по обеим осям области видео.

**Architecture:** JavaScript создаёт три native buttons внутри текущего Plyr
container после его успешной initialization. Controls вызывают существующие
`seekMediaBy()` и `Plyr.togglePlay()`, используют существующий RU/EN
`CatalogPlayerCopy`, синхронизируются native media events и уничтожаются тем же
session lifecycle.

**Installed baseline:** PHP 8.5, Laravel 13.21.1, Livewire 4.3.3,
Tailwind CSS 4.3.2, Vite 8.1.4, Plyr 3.8.4, HLS.js 1.6.16,
FontAwesome 7.3.0, Playwright projects `1440×1200`, `390×844`, `768×1024`.

## Global Constraints

- Этот finite work item является `Task 21` безлимитного master plan
  [`2026-07-24-player-seamless-episode-switching.md`](2026-07-24-player-seamless-episode-switching.md);
  отдельный конкурирующий player roadmap не создаётся.
- Работать только в существующей `main`; branch, worktree, PR и subagent не
  создавать.
- Сначала наблюдать правильный browser/static RED, затем вносить минимальный
  GREEN и только после него выполнять refactor.
- Сохранять один `<video>`, Plyr, `CatalogPlayerSession`, `AbortController`,
  keyed `wire:ignore` и не более одного HLS.js.
- Не добавлять route, schema, cache key, queue, config/environment,
  dependency, translation key, provider URL или client-trusted access state.
- Все размеры, labels и действия обязаны соответствовать design и
  существующим `CatalogPlayerCopy.controls`/`seekMediaBy()` contracts.
- Не stage/reset/stash/delete активные importer/collection/system изменения.
- Production delivery требует matching Vite manifest/assets; native iOS
  fullscreen остаётся отдельным `unresolved_device`.

## Exact files

Create:

- `docs/superpowers/specs/2026-07-24-player-centered-touch-controls-design.md`
- `docs/superpowers/plans/2026-07-24-player-centered-touch-controls.md`

Modify application/test:

- `tests/browser/player-lifecycle.spec.js`
- `tests/Unit/FrontendAssetContractTest.php`
- `resources/js/player.js`
- `resources/css/app.css`

Modify owners/evidence:

- `docs/audits/video-playback-report.md`
- `docs/frontend.md`
- `docs/UI_STANDARDS.md`
- `docs/plans/current-task-plan.md`
- `docs/superpowers/plans/2026-07-24-player-seamless-episode-switching.md`
- `README.md`
- `CHANGELOG.md`

No change expected:

- player Blade and Livewire PHP;
- `lang/ru/catalog.php`, `lang/en/catalog.php`;
- routes, migrations, config, package manifests/locks and build tooling.

## Protected contracts

- Exactly one `<video>`, Plyr, optional HLS.js and `CatalogPlayerSession`.
- Full keyed player `wire:ignore`; no second ignored island.
- Server-owned entitlement, hierarchy, source grants and progress writes.
- Exact `−10/+10`, finite duration clamp and existing keyboard/Media Session behavior.
- In-place source transition, fullscreen DOM identity, Back/Forward and cleanup.
- Existing RU/EN copy keys and placeholder parity.
- Standard Plyr fallback if custom controls cannot initialize.
- No raw provider URL, telemetry, polling, schema or cache domain.

## Cross-feature matrix

| Domain | Status | Evidence / decision |
| --- | --- | --- |
| Player JS/CSS | `affected` | One additive overlay in current session/container |
| Mobile/tablet/a11y | `affected` | 56–80 px side, 68–96 px center, native buttons, focus and labels |
| Localization | `already_compliant` | Reuse existing RU/EN `rewind`, `play`, `pause`, `fastForward` |
| Livewire/Blade | `already_compliant` | Existing ignored shell owns client DOM; templates unchanged |
| Progress/history/preferences | `already_compliant` | Existing media events and seek method remain authority |
| Source/auth/privacy | `already_compliant` | No source or access state enters new controls |
| Routes/API/SEO/sitemap | `not_applicable` | URLs and server responses unchanged |
| Schema/cache/queues/import/admin | `not_applicable` | No persistent or derived state change |
| Dependencies/runtime | `not_applicable` | Existing packages and public APIs only |
| Production assets | `affected` | Code and matching Vite manifest/assets deploy and roll back together |
| Native iOS fullscreen | `unresolved_device` | Custom HTML in OS-owned fullscreen is not promised |

## Task 1: Add the browser RED

Modify `tests/browser/player-lifecycle.spec.js` with one scenario executed by
Desktop, Mobile and Tablet Chromium:

- [ ] Open deterministic MP4 player fixture.
- [ ] Assert `[data-player-center-controls]` contains exactly ordered
   `rewind`, `toggle`, `forward` buttons.
- [ ] Assert side buttons are at least `56×56`, center at least `68×68` and larger
   than side buttons.
- [ ] Assert all button vertical centers match and the toggle center matches the
   `.plyr` center within `2 px`.
- [ ] Assert no horizontal document overflow.
- [ ] Instrument media time/play/pause as the existing keyboard test does.
- [ ] Click rewind and forward, proving exact `50→40→50`.
- [ ] Click toggle twice, proving play then pause plus RU/EN-derived dynamic
   `aria-label`.

Run:

```bash
npx playwright test tests/browser/player-lifecycle.spec.js \
  --grep="center touch controls"
```

Expected RED: all three projects fail only because the center controls are
absent. Fixture/bootstrap/network failure is not a valid RED.

## Task 2: Add the static RED

Extend
`FrontendAssetContractTest::test_player_assets_define_one_cleanup_safe_livewire_session_lifecycle`
to require:

- [ ] Require `data-player-center-controls`.
- [ ] Require `initializeCenterControls()`.
- [ ] Require `syncCenterPlaybackControl()`.
- [ ] Require exact `seekMediaBy(-10)` and `seekMediaBy(10)`.
- [ ] Require the CSS ready marker to hide only
  `.plyr__control--overlaid`.
- [ ] Assert that a second `new this.Plyr` is absent.

Run:

```bash
php artisan test --filter=FrontendAssetContractTest
```

Expected RED: only the new control contract assertions fail.

## Task 3: Implement minimal JavaScript GREEN

Modify `resources/js/player.js`:

- [ ] Add bounded seek-label formatter replacing `{seektime}` with `10`.
- [ ] Add session fields for center cluster/toggle/icon.
- [ ] Call `initializeCenterControls()` immediately after Plyr construction.
- [ ] Create one wrapper and three native buttons with local FontAwesome classes,
   visible `10` on side controls and localized `aria-label`/`title`.
- [ ] Bind clicks with the existing abort signal and stop propagation.
- [ ] Use `seekMediaBy(-10)`, `Plyr.togglePlay()` and `seekMediaBy(10)`.
- [ ] Update toggle icon/label from actual paused/ended state in initial,
   `handlePlay()`, `handlePause()` and `handleEnded()`.
- [ ] Mark the Plyr container ready only after all controls are appended.
- [ ] Remove wrapper/ready marker in `destroy()` before `plyr.destroy()`.
- [ ] Do not add listener, player, timer, mutation observer, device sniffing or
    new exported API.

Run:

```bash
node --check resources/js/player.js
php artisan test --filter=FrontendAssetContractTest
```

## Task 4: Implement responsive CSS GREEN

Modify `resources/css/app.css` next to the current Plyr rules:

- [ ] Add absolute inset/flex centering and a non-blocking wrapper.
- [ ] Apply the exact bounded side/center sizes and gap from the design.
- [ ] Add contrast, visible focus, active feedback and
  `touch-action: manipulation`.
- [ ] Enable `pointer-events` only on buttons.
- [ ] Hide the old overlay only under the ready marker.
- [ ] Follow `.plyr--hide-controls`, while focus restores visibility.
- [ ] Add safe horizontal padding, reduced motion and no overflow.

Run:

```bash
npm run build
npx playwright test tests/browser/player-lifecycle.spec.js \
  --grep="center touch controls"
```

Expected GREEN: Desktop/Mobile/Tablet pass geometry and actions.

## Task 5: Run affected regression

- [ ] Run the focused PHP/static matrix:

```bash
php artisan test --filter='FrontendAssetContractTest|CatalogPlayerCopyTest|LivewireWireIgnoreContractTest|CatalogVisualSystemTest'
```

- [ ] Build matching frontend assets:

```bash
npm run build
```

- [ ] Run the complete player browser matrix:

```bash
npx playwright test tests/browser/player-lifecycle.spec.js
```

- [ ] Inspect screenshots for `1440×1200`, `390×844`, `768×1024` and store
task evidence under ignored `output/playwright/`. Verify one video/Plyr,
first-party request/console/page errors, no overflow, standard fullscreen,
menu, keyboard, hot swap and cleanup.

## Task 6: Update owners and visitor documentation

- [ ] Update:

- playback audit with exact three-control contract and acceptance;
- frontend lifecycle with JS ownership/cleanup;
- UI standards with central geometry/touch sizes;
- README capability and final dated visitor history;
- Russian CHANGELOG with a separate 24.07.2026 entry;
- current plan and unlimited master ledger with Task 21 evidence.

- [ ] Re-read all applicable requirement owners.

- [ ] Run the documentation and policy gates:

```bash
php artisan project:docs-refresh --check
scripts/check-readme-policy.sh README.md
scripts/check-changelog-policy.sh CHANGELOG.md
git diff --check
```

## Task 7: Delivery gate

- [ ] Review exact task diff and branch:

```bash
git status --short --branch
git diff -- resources/js/player.js resources/css/app.css \
  tests/browser/player-lifecycle.spec.js tests/Unit/FrontendAssetContractTest.php
```

The repository currently contains unrelated importer/collection/system changes.
Do not stage, stash, reset, delete or commit them. The canonical pre-commit
hook rejects any remaining unstaged/untracked file, so commit/push stay
`unresolved_shared_worktree` unless the foreign owners first deliver and clean
their scopes. If the tree becomes clean, stage only the exact Task 21 manifest,
commit directly on existing `main`, then ordinary push without force.

## Rollback

Revert Task 21 JS/CSS/tests/docs and deploy the previous code plus matching
Vite manifest/assets. No migration, backup restore, data repair, cache flush,
queue restart, provider change or environment edit is required.

## Plan self-review

- [x] Design requirements map to exact browser/static/implementation/docs
  tasks.
- [x] Exact paths, methods, dimensions, commands and expected RED/GREEN
  outcomes are present.
- [x] `initializeCenterControls()`, `syncCenterPlaybackControl()`,
  `seekMediaBy()` and ready-marker naming are consistent across tasks.
- [x] No placeholder, second player, fake capability, new dependency or
  production claim is present.
- [x] Task 21 remains finite; future evidence-driven work belongs to
  `Task 22+` in the existing master plan.
