# Player Global Keyboard Controls Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `Space`/`K` toggle the active video and `ArrowLeft`/`ArrowRight` seek by exactly 10 seconds from anywhere on the title page without breaking forms, interactive controls, dialogs, modifiers, or player cleanup.

**Architecture:** Extend the existing per-video `CatalogPlayerSession` document listener. Plyr keeps ownership of focused controls; the application listener supplies only the four requested global fallback keys outside the player and continues to be removed by the session `AbortController`.

**Tech Stack:** JavaScript ES modules, Plyr 3.8.4, Livewire 4.3.3, Vite 8.1.4, Playwright 1.61.1, Chromium browser fixtures.

## Global Constraints

- Work only in the existing `main`; do not create a branch, worktree, or pull request.
- Preserve and exclude the pre-existing `composer.lock` change from every task commit.
- Do not add or update Composer/npm dependencies.
- Keep `resources/js/player.js` as the single player/session lifecycle owner.
- Keep `keyboardShortcutsEnabled` authoritative: disabled shortcuts remain disabled.
- Do not change routes, Blade markup, translation keys, database schema, cache keys, playback grants, source authorization, progress cadence, or persisted preference keys.
- Keep the existing RU/EN shortcut hint accurate without adding another keyboard controller or duplicate instructions.
- Update the canonical playback owner before final delivery because this task explicitly replaces its scoped-only keyboard rule.
- Publish Vite code and manifest/hashed assets as one production unit; rollback code and assets together.

---

### Task 1: Add a failing global-keyboard browser regression

**Files:**

- Modify: `tests/browser/player-lifecycle.spec.js`

**Interfaces:**

- Consumes: existing `installBrowserGuard(page, baseURL)`, `installPlayerMediaFixtures(page)`, `login(page)`, `waitForPlayer(page)`, `currentVideo(page)`.
- Produces: one Desktop Chromium regression named `global playback shortcuts work outside the player and respect interaction boundaries`.

- [x] **Step 1: Add the failing Playwright test**

Append the following test after the locale lifecycle loop and before the detailed HLS test:

```js
test('global playback shortcuts work outside the player and respect interaction boundaries', async ({ page, baseURL }, testInfo) => {
    test.skip(testInfo.project.name !== 'Desktop Chromium', 'Keyboard behavior runs once.');

    const errors = await installBrowserGuard(page, baseURL);

    await installPlayerMediaFixtures(page);
    await login(page);
    await page.goto('/titles/browser-smoke?format=mp4');
    await waitForPlayer(page);

    await currentVideo(page).evaluate((video) => {
        const state = {
            currentTime: 50,
            duration: 120,
            paused: true,
            plays: 0,
            pauses: 0,
        };

        window.__playerKeyboardState = state;
        Object.defineProperties(video, {
            currentTime: {
                configurable: true,
                get: () => state.currentTime,
                set: (value) => {
                    state.currentTime = Number(value);
                },
            },
            duration: {
                configurable: true,
                get: () => state.duration,
            },
            ended: {
                configurable: true,
                get: () => false,
            },
            paused: {
                configurable: true,
                get: () => state.paused,
            },
        });
        video.play = () => {
            state.paused = false;
            state.plays += 1;
            video.dispatchEvent(new Event('play'));

            return Promise.resolve();
        };
        video.pause = () => {
            state.paused = true;
            state.pauses += 1;
            video.dispatchEvent(new Event('pause'));
        };
    });

    const focusPage = () => page.evaluate(() => {
        if (document.activeElement instanceof HTMLElement) {
            document.activeElement.blur();
        }
    });

    await focusPage();
    await page.keyboard.press('Space');
    await expect.poll(() => page.evaluate(() => window.__playerKeyboardState.plays)).toBe(1);
    await page.keyboard.press('k');
    await expect.poll(() => page.evaluate(() => window.__playerKeyboardState.pauses)).toBe(1);

    const playButton = page.locator('[data-plyr="play"]').first();

    await playButton.focus();
    await page.keyboard.press('k');
    await expect.poll(() => page.evaluate(() => window.__playerKeyboardState.plays)).toBe(2);
    await page.keyboard.press('k');
    await expect.poll(() => page.evaluate(() => window.__playerKeyboardState.pauses)).toBe(2);

    await focusPage();
    await page.keyboard.press('ArrowRight');
    expect(await page.evaluate(() => window.__playerKeyboardState.currentTime)).toBe(60);
    await page.keyboard.press('ArrowLeft');
    expect(await page.evaluate(() => window.__playerKeyboardState.currentTime)).toBe(50);

    await page.evaluate(() => {
        window.__playerKeyboardState.currentTime = 5;
    });
    await page.keyboard.press('ArrowLeft');
    expect(await page.evaluate(() => window.__playerKeyboardState.currentTime)).toBe(0);

    await page.evaluate(() => {
        window.__playerKeyboardState.currentTime = 115;
    });
    await page.keyboard.press('ArrowRight');
    expect(await page.evaluate(() => window.__playerKeyboardState.currentTime)).toBe(120);

    const input = page.locator('#site-search');

    await input.focus();
    await page.keyboard.press('k');
    await page.keyboard.press('ArrowLeft');
    expect(await input.inputValue()).toBe('k');
    expect(await page.evaluate(() => window.__playerKeyboardState)).toMatchObject({
        currentTime: 120,
        plays: 2,
        pauses: 2,
    });

    await page.locator('[data-player-shortcuts-open]').click();
    await expect(page.locator('[data-player-shortcuts-dialog]')).toHaveAttribute('open', '');
    await page.locator('[data-player-shortcuts-dialog]').evaluate((dialog) => {
        dialog.tabIndex = -1;
        dialog.focus();
    });
    await page.keyboard.press('Space');
    expect(await page.evaluate(() => window.__playerKeyboardState.plays)).toBe(2);
    await page.locator('[data-player-shortcuts-close]').click();

    await focusPage();
    await page.keyboard.press('Control+k');
    expect(await page.evaluate(() => window.__playerKeyboardState.plays)).toBe(2);

    await page.evaluate(() => window.Livewire.navigate('/titles'));
    await expect(page).toHaveURL(/\/titles$/);
    await expect(currentVideo(page)).toHaveCount(0);
    await page.locator('body').evaluate((body) => {
        body.focus();
        body.dispatchEvent(new KeyboardEvent('keydown', {
            key: ' ',
            bubbles: true,
            cancelable: true,
        }));
    });
    expect(await page.evaluate(() => window.__playerKeyboardState.plays)).toBe(2);
    assertNoBrowserErrors(errors);
});
```

- [x] **Step 2: Run the focused test and verify RED**

Run:

```bash
npx playwright test tests/browser/player-lifecycle.spec.js --project="Desktop Chromium" --grep="global playback shortcuts"
```

Expected: FAIL at the first `plays` assertion with received value `0`, because the current `handleKeyboard()` rejects a body-targeted event outside the player.

- [x] **Step 3: Confirm the failure is behavioral**

Check that the browser starts, the player has `data-player-ready="1"`, fixtures load, and the failure is not a selector, login, fixture, syntax, or timeout error. If it is an infrastructure error, repair only the test setup and rerun until the expected `0` versus `1` failure is observed.

### Task 2: Implement the minimal session-owned global fallback

**Files:**

- Modify: `resources/js/player.js`
- Test: `tests/browser/player-lifecycle.spec.js`

**Interfaces:**

- Consumes: `CatalogPlayerSession.plyr`, `.video`, `.root`, `.shell`, `.preferences.keyboardShortcutsEnabled`, `.destroyed`, and existing `seekMediaBy(offset)`.
- Produces: `handleGlobalPlaybackKeyboard(event): boolean`; `true` means the requested global fallback handled the event.

- [x] **Step 1: Add the global playback helper**

Insert this method immediately before `handleKeyboard(event)`:

```js
    handleGlobalPlaybackKeyboard(event) {
        if (
            event.shiftKey
            || !this.plyr
            || ![' ', 'k', 'ArrowLeft', 'ArrowRight'].includes(event.key)
        ) {
            return false;
        }

        event.preventDefault();

        if (event.key === ' ' || event.key === 'k') {
            if (!event.repeat) {
                void Promise.resolve(this.plyr.togglePlay()).catch(() => {});
            }

            return true;
        }

        this.seekMediaBy(event.key === 'ArrowLeft' ? -10 : 10);

        return true;
    }
```

- [x] **Step 2: Restructure `handleKeyboard()` around safe global and scoped paths**

Replace the opening part of `handleKeyboard()` through its current `editable || !withinPlayer` return with:

```js
    handleKeyboard(event) {
        if (
            !this.preferences.keyboardShortcutsEnabled
            || this.destroyed
            || !this.root
            || !this.root.isConnected
            || !this.video.isConnected
        ) {
            return;
        }

        const target = event.target instanceof Element ? event.target : null;
        const interactive = target?.closest([
            'input',
            'textarea',
            'select',
            '[contenteditable="true"]',
            'button',
            'a[href]',
            '[role="button"]',
            '[role^="menuitem"]',
            '[role="slider"]',
            '[role="combobox"]',
            '[role="textbox"]',
            'summary',
        ].join(','));
        const withinPlayer = target !== null && (
            this.shell?.contains(target)
            || this.autoplayToggle?.contains(target)
            || this.restartButton?.contains(target)
            || this.shortcutsOpenButton?.contains(target)
        );
        const hasSystemModifier = event.altKey || event.ctrlKey || event.metaKey;
        const hasOpenDialog = document.querySelector('dialog[open]') !== null;

        if (interactive || hasSystemModifier) {
            return;
        }

        if (!withinPlayer && !hasOpenDialog && this.handleGlobalPlaybackKeyboard(event)) {
            return;
        }

        if (!withinPlayer) {
            return;
        }
```

Keep the existing scoped `Escape`, `?`, `Shift+N`, `Shift+P`, and `P` branches unchanged below this replacement.

- [x] **Step 3: Run the focused test and verify GREEN**

Run:

```bash
npx playwright test tests/browser/player-lifecycle.spec.js --project="Desktop Chromium" --grep="global playback shortcuts"
```

Expected: PASS with one global play/pause cycle, one focused non-duplicated play/pause cycle, exact `50→60→50`, clamps `5→0` and `115→120`, unchanged state in input/dialog/modifier cases, and no action after Livewire destroy.

- [x] **Step 4: Run the complete player browser file**

Run:

```bash
npx playwright test tests/browser/player-lifecycle.spec.js
```

Expected: all locale/lifecycle cases and the new keyboard case pass; detailed media cases retain only their existing project skips. There must be no first-party network, console, page, or duplicate-session error.

- [x] **Step 5: Review the implementation against the design**

Confirm:

- only one `document.addEventListener('keydown', ...)` remains in `player.js`;
- `Plyr keyboard.global` remains `false`;
- no new window listener, singleton, package, storage key, visible text, route, or Livewire call was added;
- disabled keyboard preference still returns before any action;
- `event.repeat` toggles play/pause once while repeated arrows remain allowed;
- `seekMediaBy()` remains the single clamp boundary.

### Task 3: Update canonical documentation and visitor history

**Files:**

- Modify: `docs/audits/video-playback-report.md`
- Modify: `docs/frontend.md`
- Modify: `lang/ru/catalog.php`
- Modify: `lang/en/catalog.php`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`

**Interfaces:**

- Consumes: delivered browser behavior and passing focused evidence.
- Produces: one canonical playback rule, one visitor summary, one Russian technical changelog entry, and completed compliance evidence.

- [x] **Step 1: Update the playback owner**

Replace the two scoped-only bullets in `docs/audits/video-playback-report.md` with:

```md
- Space/K и стрелки `-10/+10` секунд работают глобально, пока существует active player session и keyboard preference включена; editable/interactive controls, открытые dialogs и system modifier combinations сохраняют собственное keyboard behavior. M, F и C сохраняют scoped Plyr behavior; `Shift+N`, `Shift+P`, `P`, `?` и Escape остаются portal actions внутри player/tools.
- Pointer/touch controls остаются полным альтернативным способом действия. Global fallback принадлежит единственной `CatalogPlayerSession`, не дублирует focused Plyr handler и снимается тем же `AbortController` при source replacement, Livewire navigation и destroy.
```

Update the manual acceptance bullet to require global pause/seek, editable/interactive/dialog/modifier exclusions, exact 10-second bounds, no duplicate focused action, and cleanup after navigation.

- [x] **Step 2: Update the frontend owner**

Add this paragraph to `## Playback frontend lifecycle Task 07` in `docs/frontend.md`:

```md
`CatalogPlayerSession` дополнительно маршрутизирует глобальные `Space`/`K` и `ArrowLeft`/`ArrowRight` только для active connected video и включённой keyboard preference. Plyr сохраняет focused control ownership, portal-specific shortcuts остаются scoped, а editable/interactive targets, открытые dialogs и system modifiers не перехватываются. Тот же session `AbortController` исключает listener leak после смены source или Livewire navigation.
```

- [x] **Step 3: Update README visitor history**

Add a concise Russian playback capability statement to the existing player description and add this separate bullet under the `24.07.2026` visitor-history date:

```md
- Управление просмотром стало доступно на всей странице: `Space` или `K` ставят активное видео на паузу и продолжают его, а стрелки перематывают на 10 секунд, не мешая вводу в формы и работе диалогов.
```

Keep `## История обновлений для посетителей` as the final H2. Do not manually edit the managed `project-docs` block.

- [x] **Step 4: Update the Russian changelog**

Add this bullet under the `24.07.2026` section:

```md
- Плеер получил session-owned global keyboard fallback: `Space`/`K` переключают play/pause, `ArrowLeft`/`ArrowRight` выполняют bounded перемотку на `10` секунд; editable/interactive controls, dialogs, system modifiers, disabled preference и Livewire destroy защищены новым deterministic Playwright regression.
```

- [x] **Step 5: Complete current-task evidence**

Change every applicable `pending` status in the top task section of `docs/plans/current-task-plan.md` only after its stated command/evidence has passed. Record exact test counts, Vite module result, legacy scan, commit SHA, push result, and any unresolved pre-existing `composer.lock` condition without claiming it was resolved.

### Task 4: Final verification, compatibility scan, commit, and push

**Files:**

- Verify all task-owned files.
- Do not stage: `composer.lock`.

**Interfaces:**

- Consumes: green implementation and completed documentation.
- Produces: verified task commit on `main` and configured remote push evidence.

- [x] **Step 1: Run focused and frontend gates**

Run:

```bash
npx playwright test tests/browser/player-lifecycle.spec.js --project="Desktop Chromium" --grep="global playback shortcuts"
npx playwright test tests/browser/player-lifecycle.spec.js
npm run build
php artisan test --filter=CatalogPlayerCopyTest
php artisan test --filter=CatalogVisualSystemTest
php artisan project:docs-refresh
php artisan project:docs-refresh --check
git diff --check
```

Expected: browser and PHPUnit tests pass, Vite produces the player chunk without an unrelated route import, docs are current, and whitespace validation is clean.

- [x] **Step 2: Run repository compatibility scans**

Run:

```bash
rg -n "keyboard:\\s*\\{|global:\\s*true|addEventListener\\('keydown'|handleGlobalPlaybackKeyboard|handleKeyboard" resources/js tests/browser docs
rg -n "Space/K|keyboard|клавиат|горяч|ArrowLeft|ArrowRight|10 секунд" README.md CHANGELOG.md docs resources/js tests/browser
rg -n "TO[D]O|FIXM[E]|TB[D]|console\\.log|debugger" resources/js/player.js tests/browser/player-lifecycle.spec.js docs/superpowers/plans/2026-07-24-player-global-keyboard-controls.md docs/superpowers/specs/2026-07-24-player-global-keyboard-controls-design.md
```

Expected: one session-owned global implementation, `Plyr global: false`, no duplicate controller/listener, no stale scoped-only canonical rule, and no temporary/debug marker.

- [x] **Step 3: Re-read requirements and diff**

Re-read:

```bash
sed -n '1,240p' docs/requirements/index.md
sed -n '1,260p' docs/audits/video-playback-report.md
sed -n '1,320p' docs/frontend.md
sed -n '1,220p' docs/plans/current-task-plan.md
git diff -- resources/js/player.js tests/browser/player-lifecycle.spec.js docs/audits/video-playback-report.md docs/frontend.md README.md CHANGELOG.md docs/plans/current-task-plan.md
```

Expected: implementation, owner wording, tests, visitor text, rollback and compliance matrix describe the same behavior.

- [x] **Step 4: Commit only task-owned files**

Before commit, temporarily preserve the pre-existing `composer.lock` change without staging it, then run:

```bash
git status --short --branch
git add resources/js/player.js tests/browser/player-lifecycle.spec.js docs/audits/video-playback-report.md docs/frontend.md docs/plans/current-task-plan.md docs/superpowers/plans/2026-07-24-player-global-keyboard-controls.md README.md CHANGELOG.md
git diff --cached --check
git commit -m "feat: add global player keyboard controls"
```

Expected: commit succeeds on `main`; `composer.lock` is absent from `git diff --cached --name-only`.

- [ ] **Step 5: Push and preserve unrelated work**

With a clean task snapshot and the pre-existing `composer.lock` safely preserved outside the index, run:

```bash
git push origin main
git rev-parse HEAD
git rev-parse origin/main
git status --short --branch
```

Expected: local and remote SHA match. Restore the exact pre-existing `composer.lock` work after the push and report it as preserved unrelated work. If remote authentication or the configured hook fails, record the exact failure as `unresolved`; do not claim success.

Outcome: `unresolved`. Task-owned design `0785eff` and implementation `5531c5b` were committed on `main`, but `git push origin main` returned `could not read Username for 'https://github.com'`; `gh` is not installed in this environment. The pre-existing `composer.lock` patch remains preserved separately and is not part of either task commit.
