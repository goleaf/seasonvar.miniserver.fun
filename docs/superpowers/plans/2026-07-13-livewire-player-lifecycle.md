# Livewire Player Lifecycle Implementation Plan

> **For agentic workers:** Execute inline on the existing `main` branch. Do not create a branch, worktree, dependency, or test file.

**Goal:** Make one Plyr/HLS browser session own each rendered episode source, report bounded progress, expose safe playback states, and always release browser resources across Livewire morphs and navigation.

**Architecture:** Laravel remains the only source authorization and progress-write boundary. `resources/js/player.js` owns a guarded client session per `<video>` in a `WeakMap`; `resources/js/app.js` lazily loads that module and connects it to Livewire 4 morph/navigation events. Blade supplies only signed source/session metadata and a `wire:ignore` player island.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4.3.3 with bundled Alpine 3.15.12, Plyr 3.8.4, HLS.js light 1.6.16, Vite 8.1.4, Tailwind CSS 4.3.2, PHPUnit 12.5.

## Global Constraints

- Extend existing PHPUnit files; do not add test files or npm dependencies.
- Never move authorization, source selection, or progress validation into JavaScript.
- Do not expose provider URLs or raw media errors; browser-visible source data remains the signed Laravel URL.
- One player session may emit progress only while its exact `title:episode:media` session key is current.

---

### Task 1: Lock the lifecycle contract

**Files:**

- Modify: `tests/Unit/FrontendAssetContractTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`

- [x] Assert the frontend contract contains session ownership, AbortController cleanup, heartbeat, seek stabilization, visibility/navigation cleanup, safe retry states, and no legacy `_catalog*` element properties.
- [x] Assert player markup keeps `wire:ignore`, a stable session key, an accessible local status region, and a retry control.
- [x] Run both focused tests and observe the expected RED failures.

### Task 2: Implement one guarded browser session per source

**Files:**

- Modify: `resources/js/player.js`

- [x] Replace anonymous listeners with a `CatalogPlayerSession` whose AbortController, timers, Plyr instance, and HLS instance are destroyed together.
- [x] Reserve videos before async imports and invalidate stale initializers with a generation token.
- [x] Use a 30-second playing-only heartbeat; force bounded saves on pause, stable seek, hidden visibility, navigation, pagehide, and ended.
- [x] Handle play/pause/seeking/seeked/timeupdate/ended/error/loadstart/loadedmetadata/canplay/waiting/stalled/emptied plus fatal HLS events.
- [x] Map loading, buffering, automatic retry, unavailable/expired source, and fatal errors to fixed Russian text without rendering exception/provider details.

### Task 3: Connect Livewire and Blade without DOM corruption

**Files:**

- Modify: `resources/js/app.js`
- Modify: `resources/views/livewire/catalog-title-player.blade.php`

- [x] Cache the dynamic player module, initialize idempotently after DOM load, Livewire morph, and `livewire:navigated`.
- [x] Flush/destroy synchronously on `morph.removing`, `livewire:navigating`, pagehide, and before unload; reinitialize safely on pageshow.
- [x] Reject a bubbled progress event unless its session key matches the current server-rendered player key.
- [x] Keep Plyr-managed DOM under `wire:ignore`; place safe status/retry controls inside the same player shell.

### Task 4: Verify and publish

**Files:**

- Modify: `README.md`
- Modify: `docs/frontend.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: `CHANGELOG.md`
- Modify: this plan

- [x] Run focused PHP tests, `node --check`, scoped Pint, and the production Vite build.
- [x] Run full Laravel tests, PHP syntax lint, dependency audits, and framework cache validation.
- [x] Use Playwright at desktop/mobile sizes to switch episodes repeatedly, exercise back/forward and viewport orientation changes, and assert one Plyr instance, no stale progress, no console/page/local-request failures, and cleanup after removal.
- [x] Inspect the complete diff, commit on `main`, push without force, and confirm `HEAD == origin/main` with a clean tree.
