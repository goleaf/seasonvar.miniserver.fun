# Player Theatre Video-First Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** В активном режиме «Театр» поднять существующее видео к верхней
safe-area границе, скрыть только избыточную верхнюю навигацию/метаданные и
оставить реальные source controls под видео.

**Architecture:** Добавить стабильные Blade data markers и реализовать
presentation-only перестановку через scoped Tailwind/CSS selectors под
`body.player-theatre-active`. Не менять Livewire state, JavaScript lifecycle,
маршруты, авторизацию, source resolution или media DOM.

**Tech Stack:** Laravel 13.22, Livewire 4.3, Blade, Tailwind CSS 4.3, Vite 8,
PHPUnit 12.5, Playwright.

---

### Task 1: Зафиксировать RED-контракты разметки и theatre CSS

**Files:**

- Modify: `tests/Feature/CatalogPlayerWorkspaceTest.php`
- Modify: `tests/Unit/PlayerWorkspaceAssetContractTest.php`
- Modify: `tests/browser/player-workspace.spec.js`

**Step 1: Write the failing tests**

Добавить ожидания `data-layout-breadcrumbs`, `data-player-context-summary`,
`data-player-context-actions`, `data-player-video-region` и scoped CSS
selectors. В browser test добавить normal-mode visibility и theatre geometry:
video сверху, toggle поверх video, source controls ниже.

**Step 2: Run test to verify it fails**

Run:
`php artisan test tests/Feature/CatalogPlayerWorkspaceTest.php tests/Unit/PlayerWorkspaceAssetContractTest.php`

Expected: FAIL из-за отсутствующих markers/selectors.

### Task 2: Реализовать video-first presentation

**Files:**

- Modify: `resources/views/layouts/app.blade.php`
- Modify: `resources/views/livewire/catalog-title-player.blade.php`
- Modify: `resources/css/app.css`
- Modify: `resources/js/player-navigation.js`
- Modify: `resources/player-release.json`

**Step 1: Write minimal implementation**

Добавить presentation markers, скрыть breadcrumbs/title/summary только в
theatre, переставить video region перед context actions через flex order,
разместить toggle absolute поверх video и убрать лишний top padding.
Сохранить memory-only compact-state скрытой site header перед theatre и
восстановить его до возврата normal layout, чтобы scroll anchoring не добавлял
`16 px` после выхода.
На narrow/low-landscape скрыть только видимый текст toggle, сохранив
динамический accessible `aria-label` и размер `44×44`.

**Step 2: Run focused tests**

Run:
`php artisan test tests/Feature/CatalogPlayerWorkspaceTest.php tests/Unit/PlayerWorkspaceAssetContractTest.php`

Expected: PASS.

**Step 3: Build the release unit**

Run: `npm run build`

Expected: Vite и `player:release-check` PASS; layout входит в source list.

### Task 3: Проверить реальный browser lifecycle

**Files:**

- Test: `tests/browser/player-workspace.spec.js`

**Step 1: Run focused Playwright matrix**

Run:
`npx playwright test tests/browser/player-workspace.spec.js`

Expected: desktop/mobile/tablet scenarios PASS с допустимыми documented skips.

**Step 2: Inspect screenshots and geometry**

Проверить desktop, mobile portrait и landscape: верх video, overlay toggle,
controls под video, отсутствие overflow и неизменный normal mode.

### Task 4: Обновить canonical documentation и evidence

**Files:**

- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `docs/audits/video-playback-report.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `docs/plans/task-114-player-theatre-video-first-compliance.md`
- Create: `docs/plans/archive/2026-07-27-player-theatre-video-first-evidence.md`

**Step 1: Record verified behavior**

Описать video-first theatre contract, normal-mode compatibility, release
fingerprint, test evidence, production/rollback assessment и unresolved Git
delivery state без выдуманных результатов.

**Step 2: Run documentation checks**

Run:
`php artisan project:docs-refresh --check`

Expected: PASS.

### Task 5: Final verification and delivery

**Files:**

- Verify exact declared task paths only

**Step 1: Run scoped formatting and related suites**

Run exact Pint targets для изменённых PHP tests, затем focused/related PHPUnit,
Vite release gate и Playwright.

**Step 2: Reread requirements and scan legacy contracts**

Повторно прочитать применимые canonical owners; найти оставшиеся theatre
selectors/duplicate implementations/dead markers.

**Step 3: Review and commit**

Проверить exact staged paths, staged diff и sensitive/debug scan, выполнить
`approve-index`, commit в `main`.

**Step 4: Push**

Выполнить штатный push только при чистом worktree; внешний или pre-existing
workspace blocker зафиксировать как `unresolved`.

## Execution status

Tasks 1–4 и verification Task 5 завершены. Focused PHPUnit прошёл `4 tests`/
`83 assertions`; final full suite — `2294 tests`/`208369 assertions` при
`11` ожидаемых skips. Extended Playwright прошёл `19` сценариев при `2`
ожидаемых skips, visual matrix — `7/7`. Vite/player release gate подтвердил
`31 sources`, `19 assets` и fingerprint
`336fae52fc497e43ce048abefc6c25363bfd64275993abb5477046afad46d152`.
Commit/push остаются `unresolved`, пока отдельный Task 113 занимает общий
staged index.
