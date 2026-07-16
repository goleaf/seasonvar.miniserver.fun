# Player Lifecycle and Deterministic Browser Fixtures Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Localize every browser-player runtime string and prove one cleanup-safe Plyr/HLS session across source changes, Livewire navigation, browser lifecycle events, and deterministic MP4/HLS/WebVTT failures.

**Architecture:** Keep the existing `CatalogTitlePlayer` → Blade `wire:ignore` shell → `resources/js/player.js` pipeline. A focused ViewData object supplies active-locale copy through escaped JSON, while Playwright fulfills locally generated textual media fixtures at the existing allowlisted testing host without adding production routes or a subtitle storage domain.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4, PHP translation arrays, Vite 8, Plyr 3.8, hls.js 1.6 light build, PHPUnit 12.5, Playwright 1.61 Chromium, GStreamer 1.26 used only once to generate committed text fixtures.

## Implementation status — 16.07.2026

- Tasks 1–4 are implemented on `main`: paired RU/EN server copy contains all 35 scalar Plyr labels, Blade transports only escaped allowlisted JSON, and `player.js` owns one cleanup-safe session without embedded user-facing language.
- HLS URLs are isolated in `data-hls-src`. HLS.js/MSE is the managed path when supported; native `video.src` is the fallback. A fatal manifest retry must replace the broken HLS instance—`startLoad()` or same-instance `loadSource()` did not reload it reliably in real Chromium.
- Playwright intercepts both `/player-fixtures/**` and the first same-origin signed `/playback/**` request. It fetches the signed endpoint without following redirects, validates the allowlisted redirect origin/path, then fulfils the exact local fixture because a page route does not observe the redirect target as a second independently routable request.
- The corrupt fragment scenario records the actual `corrupt → valid` byte sequence: hls.js performs its own non-fatal fragment retry and reaches ready state, so the regression asserts observable recovery instead of forcing a false fatal state.
- RU and EN run in Desktop/Mobile/Tablet Chromium. The detailed media matrix runs once on Desktop; the complete suite result is `7 passed`, `2 skipped`. This proves the listed Chromium behaviors only, not universal codec, Safari, fullscreen or PiP support.
- Task 5 owner documentation and release communication are complete. Fresh final gates: Pint passed; four focused PHPUnit contracts passed with 9 tests / 168 assertions; Larastan reported 0 errors; Vite build, Blade compilation, documentation freshness and 110 paired non-empty RU/EN player translation leaves passed; the complete Playwright suite finished with `7 passed`, `2 skipped` in 2.7 minutes. Only isolated documentation commit/push delivery remains.

## Global Constraints

- Work only on the existing `main` branch; do not create a branch or worktree.
- Before each task, wait until every task path is absent from unrelated unstaged/staged work. Preserve and exclude concurrent recommendation, CI, search, profile, issue, and importer changes.
- Do not install Composer or npm packages and do not add a production-only or testing-only application route.
- Reuse the only current `CatalogTitlePlayer`, `CatalogPlaybackSourceResolver`, signed playback route, Plyr instance, HLS instance, and progress service.
- Do not add a subtitle-track table, JSON field, relation, API field, importer mapping, editor, or public capability claim; production currently stores only `LicensedMedia::has_subtitles`.
- Keep provider URLs, signed query values, raw HLS objects, exception messages, cookies, tokens, and source credentials out of visible copy, logs, fixture observations, and assertions.
- All visible/runtime/Plyr/captions copy must use semantic keys in both `lang/ru/catalog.php` and `lang/en/catalog.php`; JavaScript must contain no user-facing Russian or English sentence.
- Fixture bytes remain under `tests/browser/fixtures/player` as text and are fulfilled in memory. Nothing is copied to `public/`, application storage, or the production database.
- Use TDD for each behavior: focused failing test, observed failure, minimal implementation, observed pass, then task-owned commit.
- After PHP changes run task-file Pint and PHPStan; after JS/Blade changes run `npm run build`; after browser changes run the focused Playwright project before the complete matrix.
- Update the English `CHANGELOG.md` and meaningful Russian visitor history in `README.md`; `## История обновлений для посетителей` remains the final README H2.

---

## File map

**Create**

- `app/View/ViewData/CatalogPlayerCopy.php` — allowlisted active-locale runtime and Plyr presentation payload.
- `tests/Unit/CatalogPlayerCopyTest.php` — locale structure, missing-key safety, and Blade transport contract.
- `tests/Unit/PlayerBrowserFixtureContractTest.php` — fixture/helper/browser-spec repository contract.
- `tests/browser/support/player-media-fixtures.js` — external-host interception, byte ranges, scenario controls, and bounded observations.
- `tests/browser/fixtures/player/direct.mp4.b64` — locally generated small direct MP4 encoded as one base64 line.
- `tests/browser/fixtures/player/hls-init.mp4.b64` — locally generated fragmented MP4 initialization bytes encoded as one base64 line.
- `tests/browser/fixtures/player/hls-segment.m4s.b64` — locally generated fragmented MP4 media bytes encoded as one base64 line.
- `tests/browser/fixtures/player/valid.m3u8` — deterministic absolute-URL VOD manifest.
- `tests/browser/fixtures/player/subtitles-ru.vtt` — deterministic UTF-8 WebVTT sample.
- `tests/browser/player-lifecycle.spec.js` — Chromium lifecycle, localization, media, retry, Range, and captions matrix.

**Modify**

- `app/Livewire/CatalogTitlePlayer.php:333` — inject `CatalogPlayerCopy` into `render()` and pass `playerCopy` to Blade.
- `resources/views/livewire/catalog-title-player.blade.php:81` — escaped copy payload, captions status, and stable media-option marker.
- `lang/ru/catalog.php:359` — paired `player.runtime.*` and `player.controls.*` strings.
- `lang/en/catalog.php:359` — exact paired English keys and placeholders.
- `resources/js/player.js:18-683` — data-driven copy, bounded HLS load policy, recovery cancellation, caption listeners, and safe state transitions.
- `tests/Unit/FrontendAssetContractTest.php:85` — replace hardcoded-Russian assertion with localization/lifecycle assertions.
- `tests/browser/prepare-fixtures.php:108` — normal HLS and MP4 media variants for the isolated `browser-smoke` graph.
- `tests/Unit/BrowserCiContractTest.php:11` — require the new fixture helper/spec and no external fixture dependency.
- `docs/frontend.md` — localized copy transport and cleanup ownership.
- `docs/testing.md` — exact deterministic media/browser commands and scope.
- `docs/audits/video-playback-report.md` — close verified lifecycle/media rows and retain subtitle-domain limitation.
- `docs/plans/laravel-video-portal-modernization.md` — implementation and acceptance checklist.
- `CHANGELOG.md` — English technical release entry.
- `README.md` — Russian visitor-facing reliability entry.

---

### Task 1: Server-owned localized player copy

**Files:**

- Create: `app/View/ViewData/CatalogPlayerCopy.php`
- Create: `tests/Unit/CatalogPlayerCopyTest.php`
- Modify: `app/Livewire/CatalogTitlePlayer.php:333`
- Modify: `resources/views/livewire/catalog-title-player.blade.php:81-137`
- Modify: `lang/ru/catalog.php:359-406`
- Modify: `lang/en/catalog.php:359-406`

**Interfaces:**

- Consumes: `Illuminate\Contracts\Translation\Translator::get(string $key): mixed` and the active application locale already restored by web/Livewire middleware.
- Produces: `CatalogPlayerCopy::current(): array{runtime: array<string, string>, controls: array<string, string>}` and Blade variable `$playerCopy`.

- [ ] **Step 1: Confirm task paths are isolated**

Run:

```bash
git status --short --branch
git diff --name-only -- app/Livewire/CatalogTitlePlayer.php resources/views/livewire/catalog-title-player.blade.php lang/ru/catalog.php lang/en/catalog.php tests/Unit/CatalogPlayerCopyTest.php app/View/ViewData/CatalogPlayerCopy.php
```

Expected: branch is `main`; the listed paths are clean. If `lang/*/catalog.php` still belongs to the active header-search work, wait for that owner to commit and then reread both catalogs before continuing.

- [ ] **Step 2: Write the failing locale payload test**

Create `tests/Unit/CatalogPlayerCopyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\View\ViewData\CatalogPlayerCopy;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CatalogPlayerCopyTest extends TestCase
{
    public function test_player_copy_has_identical_complete_non_empty_ru_and_en_payloads(): void
    {
        $payloads = [];

        foreach (['ru', 'en'] as $locale) {
            app()->setLocale($locale);
            $payloads[$locale] = app(CatalogPlayerCopy::class)->current();

            $this->assertSame([
                'preparing', 'loading', 'ready', 'playing', 'paused', 'seeking',
                'buffering', 'retryingNetwork', 'retryingMedia', 'expired',
                'playbackError', 'fatal', 'ended', 'captionsUnavailable',
            ], array_keys($payloads[$locale]['runtime']));
            $this->assertSame([
                'restart', 'rewind', 'play', 'pause', 'fastForward', 'seek',
                'played', 'buffered', 'currentTime', 'duration', 'volume',
                'mute', 'unmute', 'enableCaptions', 'disableCaptions',
                'enterFullscreen', 'exitFullscreen', 'settings', 'pip',
            ], array_keys($payloads[$locale]['controls']));
            $this->assertNotContains('', Arr::flatten($payloads[$locale]));
        }

        $this->assertSame(array_keys(Arr::dot($payloads['ru'])), array_keys(Arr::dot($payloads['en'])));
        $this->assertNotSame($payloads['ru']['runtime']['expired'], $payloads['en']['runtime']['expired']);
    }

    public function test_player_blade_uses_escaped_copy_and_a_separate_caption_status(): void
    {
        $view = File::get(resource_path('views/livewire/catalog-title-player.blade.php'));

        $this->assertStringContainsString('data-player-copy=', $view);
        $this->assertStringContainsString('Js::encode($playerCopy)', $view);
        $this->assertStringContainsString('data-player-caption-status', $view);
        $this->assertStringContainsString('aria-live="polite"', $view);
    }
}
```

- [ ] **Step 3: Run the focused test and observe RED**

Run:

```bash
php artisan test --filter=CatalogPlayerCopyTest
```

Expected: FAIL because `App\View\ViewData\CatalogPlayerCopy` and the Blade payload do not exist.

- [ ] **Step 4: Add paired semantic translations**

Add the following nested branches under `catalog.player` in both PHP catalogs. Keep the same keys and no placeholders in either locale.

```php
// lang/ru/catalog.php
'runtime' => [
    'preparing' => 'Подготавливаем видео…',
    'loading' => 'Загружаем видео…',
    'ready' => 'Видео готово к просмотру.',
    'playing' => 'Видео воспроизводится.',
    'paused' => 'Воспроизведение приостановлено.',
    'seeking' => 'Переходим к выбранному моменту…',
    'buffering' => 'Видео загружается…',
    'retrying_network' => 'Повторяем загрузку видео…',
    'retrying_media' => 'Восстанавливаем воспроизведение…',
    'expired' => 'Ссылка на просмотр устарела.',
    'playback_error' => 'Не удалось воспроизвести видео.',
    'fatal' => 'Плеер не удалось запустить.',
    'ended' => 'Серия просмотрена.',
    'captions_unavailable' => 'Субтитры недоступны, но видео можно продолжить смотреть.',
],
'controls' => [
    'restart' => 'Сначала',
    'rewind' => 'Назад {seektime} секунд',
    'play' => 'Воспроизвести',
    'pause' => 'Пауза',
    'fast_forward' => 'Вперёд {seektime} секунд',
    'seek' => 'Перемотка',
    'played' => 'Просмотрено',
    'buffered' => 'Загружено',
    'current_time' => 'Текущее время',
    'duration' => 'Длительность',
    'volume' => 'Громкость',
    'mute' => 'Выключить звук',
    'unmute' => 'Включить звук',
    'enable_captions' => 'Включить субтитры',
    'disable_captions' => 'Выключить субтитры',
    'enter_fullscreen' => 'На весь экран',
    'exit_fullscreen' => 'Выйти из полноэкранного режима',
    'settings' => 'Настройки',
    'pip' => 'Картинка в картинке',
],
```

```php
// lang/en/catalog.php
'runtime' => [
    'preparing' => 'Preparing video…',
    'loading' => 'Loading video…',
    'ready' => 'Video is ready to play.',
    'playing' => 'Video is playing.',
    'paused' => 'Playback is paused.',
    'seeking' => 'Moving to the selected position…',
    'buffering' => 'Video is buffering…',
    'retrying_network' => 'Retrying video loading…',
    'retrying_media' => 'Recovering playback…',
    'expired' => 'The playback link has expired.',
    'playback_error' => 'The video could not be played.',
    'fatal' => 'The player could not be started.',
    'ended' => 'Episode watched.',
    'captions_unavailable' => 'Captions are unavailable, but the video can continue playing.',
],
'controls' => [
    'restart' => 'Restart',
    'rewind' => 'Rewind {seektime} seconds',
    'play' => 'Play',
    'pause' => 'Pause',
    'fast_forward' => 'Forward {seektime} seconds',
    'seek' => 'Seek',
    'played' => 'Played',
    'buffered' => 'Buffered',
    'current_time' => 'Current time',
    'duration' => 'Duration',
    'volume' => 'Volume',
    'mute' => 'Mute',
    'unmute' => 'Unmute',
    'enable_captions' => 'Enable captions',
    'disable_captions' => 'Disable captions',
    'enter_fullscreen' => 'Enter fullscreen',
    'exit_fullscreen' => 'Exit fullscreen',
    'settings' => 'Settings',
    'pip' => 'Picture in picture',
],
```

- [ ] **Step 5: Implement the allowlisted ViewData object**

Create `app/View/ViewData/CatalogPlayerCopy.php`:

```php
<?php

declare(strict_types=1);

namespace App\View\ViewData;

use Illuminate\Contracts\Translation\Translator;

final readonly class CatalogPlayerCopy
{
    public function __construct(private Translator $translator) {}

    /**
     * @return array{runtime: array<string, string>, controls: array<string, string>}
     */
    public function current(): array
    {
        return [
            'runtime' => [
                'preparing' => $this->text('runtime.preparing'),
                'loading' => $this->text('runtime.loading'),
                'ready' => $this->text('runtime.ready'),
                'playing' => $this->text('runtime.playing'),
                'paused' => $this->text('runtime.paused'),
                'seeking' => $this->text('runtime.seeking'),
                'buffering' => $this->text('runtime.buffering'),
                'retryingNetwork' => $this->text('runtime.retrying_network'),
                'retryingMedia' => $this->text('runtime.retrying_media'),
                'expired' => $this->text('runtime.expired'),
                'playbackError' => $this->text('runtime.playback_error'),
                'fatal' => $this->text('runtime.fatal'),
                'ended' => $this->text('runtime.ended'),
                'captionsUnavailable' => $this->text('runtime.captions_unavailable'),
            ],
            'controls' => [
                'restart' => $this->text('controls.restart'),
                'rewind' => $this->text('controls.rewind'),
                'play' => $this->text('controls.play'),
                'pause' => $this->text('controls.pause'),
                'fastForward' => $this->text('controls.fast_forward'),
                'seek' => $this->text('controls.seek'),
                'played' => $this->text('controls.played'),
                'buffered' => $this->text('controls.buffered'),
                'currentTime' => $this->text('controls.current_time'),
                'duration' => $this->text('controls.duration'),
                'volume' => $this->text('controls.volume'),
                'mute' => $this->text('controls.mute'),
                'unmute' => $this->text('controls.unmute'),
                'enableCaptions' => $this->text('controls.enable_captions'),
                'disableCaptions' => $this->text('controls.disable_captions'),
                'enterFullscreen' => $this->text('controls.enter_fullscreen'),
                'exitFullscreen' => $this->text('controls.exit_fullscreen'),
                'settings' => $this->text('controls.settings'),
                'pip' => $this->text('controls.pip'),
            ],
        ];
    }

    private function text(string $suffix): string
    {
        $key = 'catalog.player.'.$suffix;
        $value = $this->translator->get($key);

        return is_string($value) && $value !== '' && $value !== $key ? $value : '';
    }
}
```

- [ ] **Step 6: Pass and render the copy safely**

Change the Livewire signature to `public function render(CatalogPlayerCopy $playerCopy): View`, import the class, and add this exact view datum:

```php
'playerCopy' => $playerCopy->current(),
```

On the existing `[data-player-shell]` element add:

```blade
data-player-copy="{{ \Illuminate\Support\Js::encode($playerCopy) }}"
```

Immediately after `</video>`, add the dormant non-fatal status:

```blade
<p
    data-player-caption-status
    hidden
    aria-live="polite"
    class="bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800"
></p>
```

- [ ] **Step 7: Run focused checks and observe GREEN**

Run:

```bash
php artisan test --filter=CatalogPlayerCopyTest
./vendor/bin/pint --test app/View/ViewData/CatalogPlayerCopy.php app/Livewire/CatalogTitlePlayer.php tests/Unit/CatalogPlayerCopyTest.php lang/ru/catalog.php lang/en/catalog.php
vendor/bin/phpstan analyse app/View/ViewData/CatalogPlayerCopy.php app/Livewire/CatalogTitlePlayer.php --memory-limit=1G --error-format=json
```

Expected: test PASS; Pint reports `passed`; PHPStan reports zero errors.

- [ ] **Step 8: Commit Task 1 only**

```bash
git add app/View/ViewData/CatalogPlayerCopy.php app/Livewire/CatalogTitlePlayer.php resources/views/livewire/catalog-title-player.blade.php lang/ru/catalog.php lang/en/catalog.php tests/Unit/CatalogPlayerCopyTest.php
git diff --cached --check
git commit -m "feat: localize browser player runtime"
```

Expected: only the six task paths are staged; the commit is on `main`.

---

### Task 2: Cleanup-safe localized JavaScript lifecycle

**Files:**

- Modify: `resources/js/player.js:18-683`
- Modify: `tests/Unit/FrontendAssetContractTest.php:85-118`

**Interfaces:**

- Consumes: escaped `data-player-copy` with the exact `runtime` and `controls` keys from Task 1.
- Produces: existing exports `initializeCatalogPlayers(root)`, `flushCatalogPlayersWithin(root, reason)`, and `destroyCatalogPlayersWithin(root, options)` with bounded recovery and native track status handling.

- [ ] **Step 1: Write failing static lifecycle assertions**

Replace the old assertion for the Russian expired sentence and add these assertions to `test_player_assets_define_one_cleanup_safe_livewire_session_lifecycle()`:

```php
$this->assertSame(0, preg_match('/[А-Яа-яЁё]/u', $player));
$this->assertStringContainsString('const playerCopyFor = (video)', $player);
$this->assertStringContainsString('i18n: this.copy.controls', $player);
$this->assertStringContainsString('initializeCaptionTracks()', $player);
$this->assertStringContainsString('clearRecoveryTimer()', $player);
$this->assertStringContainsString("querySelectorAll('track[kind=\"subtitles\"], track[kind=\"captions\"]')", $player);
$this->assertStringContainsString('manifestLoadPolicy:', $player);
$this->assertStringContainsString('fragLoadPolicy:', $player);
$this->assertStringNotContainsString("'Ссылка на просмотр устарела.'", $player);
$this->assertStringNotContainsString("'The playback link has expired.'", $player);
```

- [ ] **Step 2: Run the focused test and observe RED**

Run:

```bash
php artisan test --filter=test_player_assets_define_one_cleanup_safe_livewire_session_lifecycle
```

Expected: FAIL on missing `playerCopyFor`, caption handling, and hardcoded Cyrillic copy.

- [ ] **Step 3: Replace hardcoded copy with an allowlisted parser**

Remove `playerTranslations`. Add these stable key lists and parser near the module constants:

```js
const playerCopyShape = {
    runtime: [
        'preparing', 'loading', 'ready', 'playing', 'paused', 'seeking',
        'buffering', 'retryingNetwork', 'retryingMedia', 'expired',
        'playbackError', 'fatal', 'ended', 'captionsUnavailable',
    ],
    controls: [
        'restart', 'rewind', 'play', 'pause', 'fastForward', 'seek',
        'played', 'buffered', 'currentTime', 'duration', 'volume',
        'mute', 'unmute', 'enableCaptions', 'disableCaptions',
        'enterFullscreen', 'exitFullscreen', 'settings', 'pip',
    ],
};

const emptyPlayerCopy = () => Object.fromEntries(Object.entries(playerCopyShape).map(
    ([branch, keys]) => [branch, Object.fromEntries(keys.map((key) => [key, '']))],
));

const playerCopyFor = (video) => {
    const copy = emptyPlayerCopy();
    const raw = video.closest('[data-player-shell]')?.dataset.playerCopy;

    if (!raw) return copy;

    try {
        const parsed = JSON.parse(raw);

        Object.entries(playerCopyShape).forEach(([branch, keys]) => {
            keys.forEach((key) => {
                const value = parsed?.[branch]?.[key];
                copy[branch][key] = typeof value === 'string' && value.trim() !== '' ? value : '';
            });
        });
    } catch {
        return copy;
    }

    return copy;
};
```

Set `this.copy = playerCopyFor(video)` in the constructor and change the Plyr option to `i18n: this.copy.controls`.

- [ ] **Step 4: Make status updates key-driven**

Change `setStatus(state, text, canRetry)` to:

```js
setStatus(state, copyKey, canRetry = false) {
    if (this.destroyed || !this.status) return;

    this.status.dataset.playerState = state;
    this.shell?.setAttribute('data-player-state', state);
    this.status.hidden = false;

    const text = this.copy.runtime[copyKey] || '';
    if (text && this.statusText) this.statusText.textContent = text;

    if (this.statusIcon) {
        this.statusIcon.className = playerStatusIcons[state] || 'fa-solid fa-circle-info text-emerald-700';
    }

    if (this.retryButton) this.retryButton.hidden = !canRetry;
}
```

Replace every call with stable copy keys: `loading`, `ready`, `playing`, `paused`, `seeking`, `buffering`, `retryingNetwork`, `retryingMedia`, `expired`, `playbackError`, and `ended`. `showFatalPlayerState()` must parse `playerCopyFor(video)` and replace status text only when `runtime.fatal` is non-empty.

- [ ] **Step 5: Bound HLS internal and application retries**

Add a fresh policy factory so hls.js does not perform hidden retries in addition to the application retry:

```js
const noInternalRetryPolicy = (maxLoadTimeMs) => ({
    default: {
        maxTimeToFirstByteMs: Math.min(10_000, maxLoadTimeMs),
        maxLoadTimeMs,
        timeoutRetry: null,
        errorRetry: null,
    },
});
```

Use it in `new this.Hls()`:

```js
manifestLoadPolicy: noInternalRetryPolicy(20_000),
playlistLoadPolicy: noInternalRetryPolicy(20_000),
fragLoadPolicy: noInternalRetryPolicy(30_000),
```

Add and call this exact helper before scheduling a replacement timer, entering terminal failure, manual retry, and destroy:

```js
clearRecoveryTimer() {
    if (this.recoveryTimer !== null) {
        window.clearTimeout(this.recoveryTimer);
        this.recoveryTimer = null;
    }
}
```

- [ ] **Step 6: Add abortable native caption listeners**

Store `this.captionStatus` from `[data-player-caption-status]`, call `initializeCaptionTracks()` during initialization, and add:

```js
initializeCaptionTracks() {
    const signal = this.abortController.signal;
    const tracks = this.video.querySelectorAll('track[kind="subtitles"], track[kind="captions"]');

    tracks.forEach((track) => {
        track.addEventListener('load', () => {
            if (this.captionStatus) {
                this.captionStatus.hidden = true;
                this.captionStatus.textContent = '';
            }
        }, { signal });
        track.addEventListener('error', () => {
            const text = this.copy.runtime.captionsUnavailable || '';
            if (text && this.captionStatus && !this.destroyed) {
                this.captionStatus.textContent = text;
                this.captionStatus.hidden = false;
            }
        }, { signal });
    });
}
```

- [ ] **Step 7: Run focused tests and build**

Run:

```bash
php artisan test --filter=FrontendAssetContractTest
npm run build
```

Expected: PHPUnit PASS; Vite completes without missing imports or syntax errors.

- [ ] **Step 8: Commit Task 2 only**

```bash
git add resources/js/player.js tests/Unit/FrontendAssetContractTest.php
git diff --cached --check
git commit -m "fix: harden player session lifecycle"
```

---

### Task 3: Deterministic local MP4, HLS, and WebVTT fixture boundary

**Files:**

- Create: `tests/browser/fixtures/player/direct.mp4.b64`
- Create: `tests/browser/fixtures/player/hls-init.mp4.b64`
- Create: `tests/browser/fixtures/player/hls-segment.m4s.b64`
- Create: `tests/browser/fixtures/player/valid.m3u8`
- Create: `tests/browser/fixtures/player/subtitles-ru.vtt`
- Create: `tests/browser/support/player-media-fixtures.js`
- Create: `tests/Unit/PlayerBrowserFixtureContractTest.php`
- Modify: `tests/browser/prepare-fixtures.php:108-124`

**Interfaces:**

- Consumes: `https://media.example.com/player-fixtures/*` fixture URLs and Playwright `Page::route`.
- Produces: `installPlayerMediaFixtures(page): Promise<{ scenario, observations, count }>` where `scenario.manifestStatuses`, `scenario.segmentStatuses`, `scenario.segmentBodies`, and `scenario.captionStatus` are mutable bounded test controls.

- [ ] **Step 1: Write the failing repository contract**

Create `tests/Unit/PlayerBrowserFixtureContractTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PlayerBrowserFixtureContractTest extends TestCase
{
    public function test_browser_player_fixtures_are_local_text_and_explicitly_routed(): void
    {
        foreach ([
            'direct.mp4.b64', 'hls-init.mp4.b64', 'hls-segment.m4s.b64',
            'valid.m3u8', 'subtitles-ru.vtt',
        ] as $fixture) {
            $path = base_path('tests/browser/fixtures/player/'.$fixture);
            $this->assertFileExists($path);
            $this->assertGreaterThan(0, File::size($path));
        }

        $router = File::get(base_path('tests/browser/support/player-media-fixtures.js'));
        $fixtures = File::get(base_path('tests/browser/prepare-fixtures.php'));

        $this->assertStringContainsString("page.route('https://media.example.com/player-fixtures/**'", $router);
        $this->assertStringContainsString('Content-Range', $router);
        $this->assertStringContainsString("Buffer.from", $router);
        $this->assertStringContainsString('player-fixtures/valid.m3u8', $fixtures);
        $this->assertStringContainsString('player-fixtures/direct.mp4', $fixtures);
        $this->assertStringNotContainsString('public/', $router);
    }
}
```

- [ ] **Step 2: Run the focused test and observe RED**

```bash
php artisan test --filter=PlayerBrowserFixtureContractTest
```

Expected: FAIL because the fixture files and router do not exist.

- [ ] **Step 3: Generate tiny original fixture bytes outside the repository**

Use the installed GStreamer tools only for one-time local generation:

```bash
rm -rf /tmp/seasonvar-player-fixtures
mkdir -p /tmp/seasonvar-player-fixtures
gst-launch-1.0 -q audiotestsrc num-buffers=5 wave=silence ! audio/x-raw,rate=8000,channels=1 ! fdkaacenc ! mp4mux faststart=true ! filesink location=/tmp/seasonvar-player-fixtures/direct.mp4
gst-launch-1.0 -q audiotestsrc num-buffers=20 wave=silence ! audio/x-raw,rate=8000,channels=1 ! fdkaacenc ! mp4mux fragment-duration=1000 streamable=true ! filesink location=/tmp/seasonvar-player-fixtures/fragmented.mp4
moof_type_offset=$(LC_ALL=C grep -abo moof /tmp/seasonvar-player-fixtures/fragmented.mp4 | sed -n '1s/:.*//p')
moof_offset=$((moof_type_offset - 4))
dd if=/tmp/seasonvar-player-fixtures/fragmented.mp4 of=/tmp/seasonvar-player-fixtures/hls-init.mp4 bs=1 count="$moof_offset" status=none
dd if=/tmp/seasonvar-player-fixtures/fragmented.mp4 of=/tmp/seasonvar-player-fixtures/hls-segment.m4s bs=1 skip="$moof_offset" status=none
file /tmp/seasonvar-player-fixtures/direct.mp4 /tmp/seasonvar-player-fixtures/hls-init.mp4 /tmp/seasonvar-player-fixtures/hls-segment.m4s
base64 -w 0 /tmp/seasonvar-player-fixtures/direct.mp4
base64 -w 0 /tmp/seasonvar-player-fixtures/hls-init.mp4
base64 -w 0 /tmp/seasonvar-player-fixtures/hls-segment.m4s
```

Expected: direct and init files identify as ISO Media; media segment starts with an MP4 `moof` box. Add each displayed base64 value as one UTF-8 line through `apply_patch`; do not copy binary files into the repository.

- [ ] **Step 4: Add manifest and caption text**

Create `valid.m3u8`:

```text
#EXTM3U
#EXT-X-VERSION:7
#EXT-X-TARGETDURATION:3
#EXT-X-MEDIA-SEQUENCE:0
#EXT-X-PLAYLIST-TYPE:VOD
#EXT-X-MAP:URI="https://media.example.com/player-fixtures/hls-init.mp4"
#EXTINF:2.560,
https://media.example.com/player-fixtures/hls-segment.m4s
#EXT-X-ENDLIST
```

Create `subtitles-ru.vtt`:

```text
WEBVTT

00:00:00.000 --> 00:00:01.500
Локальная проверка субтитров
```

- [ ] **Step 5: Implement the Playwright fixture router**

`tests/browser/support/player-media-fixtures.js` must read the three base64 files and two text files with `readFileSync(new URL(..., import.meta.url))`, decode with `Buffer.from(value.trim(), 'base64')`, and register the explicit host route after the generic network guard.

Use this response contract:

```js
export const installPlayerMediaFixtures = async (page) => {
    const scenario = {
        manifestStatuses: [],
        segmentStatuses: [],
        segmentBodies: [],
        captionStatus: 200,
    };
    const observations = [];

    await page.route('https://media.example.com/player-fixtures/**', async (route) => {
        const request = route.request();
        const path = new URL(request.url()).pathname;
        observations.push({ path, range: request.headers().range || null });

        // Shift a configured status first; otherwise fulfill the mapped local fixture.
        // A queued segment body of `corrupt` fulfills a bounded invalid payload so
        // hls.js exercises its real media-recovery branch without external bytes.
        // MP4 resources honor a single bytes=start-end request with 206 and Content-Range.
        // Unknown paths return 404 and no response includes a cookie or signed URL.
    });

    return {
        scenario,
        observations,
        count: (suffix) => observations.filter(({ path }) => path.endsWith(suffix)).length,
    };
};
```

Implement the three explicit content types: `application/vnd.apple.mpegurl`, `video/mp4`, and `text/vtt; charset=utf-8`. For Range responses, parse only `/^bytes=(\d+)-(\d*)$/`, clamp the end to `bytes.length - 1`, return `416` when start is outside the payload, and otherwise return `206` with `Accept-Ranges`, `Content-Length`, and `Content-Range`.

- [ ] **Step 6: Add normal HLS and MP4 media variants to isolated browser data**

Keep `$media` as the HLS progress row but change its fixture URL to `https://media.example.com/player-fixtures/valid.m3u8`, set `quality` to `1080p`, `variant_type` to `original`, and `variant_key` to `browser-hls`. Add a second published/active `LicensedMedia` for the same title/season/episode with `format=mp4`, `quality=720p`, `variant_key=browser-mp4`, and both path fields set to `https://media.example.com/player-fixtures/direct.mp4`.

- [ ] **Step 7: Run the contract GREEN**

```bash
php artisan test --filter=PlayerBrowserFixtureContractTest
php -l tests/browser/prepare-fixtures.php
```

Expected: PHPUnit PASS and no PHP syntax errors.

- [ ] **Step 8: Commit Task 3 only**

```bash
git add tests/browser/fixtures/player tests/browser/support/player-media-fixtures.js tests/browser/prepare-fixtures.php tests/Unit/PlayerBrowserFixtureContractTest.php
git diff --cached --check
git commit -m "test: add deterministic player media fixtures"
```

---

### Task 4: Real Chromium lifecycle and failure matrix

**Files:**

- Create: `tests/browser/player-lifecycle.spec.js`
- Modify: `tests/Unit/BrowserCiContractTest.php:11-75`
- Modify: `resources/views/livewire/catalog-title-player.blade.php:343-362`

**Interfaces:**

- Consumes: Task 3 `installPlayerMediaFixtures(page)` and the existing `/titles/browser-smoke` fixture page.
- Produces: viewport-wide lifecycle test plus desktop-only deterministic media/error test.

- [ ] **Step 1: Extend the failing CI contract**

In `BrowserCiContractTest`, load `tests/browser/player-lifecycle.spec.js` and `tests/browser/support/player-media-fixtures.js`, then assert:

```php
$this->assertStringContainsString('installPlayerMediaFixtures', $playerSuite);
$this->assertStringContainsString("testInfo.project.name !== 'Desktop Chromium'", $playerSuite);
$this->assertStringContainsString('PageTransitionEvent', $playerSuite);
$this->assertStringContainsString('data-player-session', $playerSuite);
$this->assertStringContainsString('Content-Range', $fixtureRouter);
```

Run `php artisan test --filter=BrowserCiContractTest`; expect RED because the new spec does not exist.

- [ ] **Step 2: Add a stable semantic marker to media option links**

On each existing `selectMedia()` link add production-safe diagnostics, not a test-only route or label:

```blade
data-player-media-option="{{ $episodeMedia->id }}"
data-player-media-format="{{ $episodeMedia->format }}"
```

- [ ] **Step 3: Create shared browser helpers**

At the top of `player-lifecycle.spec.js`, import `expect`, `test`, and `installPlayerMediaFixtures`. Implement:

```js
const login = async (page, localePrefix = '') => {
    await page.goto(`${localePrefix}/login`);
    await page.locator('input[name="email"]').fill('browser@example.com');
    await page.locator('input[name="password"]').fill('Browser-Strong-Password-42!');
    await page.locator('form').filter({ has: page.locator('input[name="email"]') }).locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/library(?:\?|$)/);
};

const currentVideo = (page) => page.locator('video.js-catalog-player');

const waitForPlayer = async (page) => {
    await expect(currentVideo(page)).toHaveAttribute('data-player-ready', '1');
    return currentVideo(page).getAttribute('data-player-session');
};
```

The local browser guard must abort every external URL except the explicit fixture router, collect same-origin responses `>=400`, console errors, and page errors, and tolerate only the already documented aborted Livewire refresh poll. The login helper selects stable form names and follows the requested localized route; it does not assume Russian labels after opening `/en`.

- [ ] **Step 4: Implement the all-viewport lifecycle test**

The first test runs in every configured project and performs these exact assertions:

1. Install generic guard, then fixture router; for each locale prefix `''` and `'/en'`, log in through the existing localized form and open the localized `titles/browser-smoke?format=m3u8` URL.
2. Wait for one ready video, save its session key, and assert the rendered runtime text and Plyr play label match copy from that locale's server-rendered `data-player-copy` rather than any literal embedded in the test.
3. Set `video.dataset.lifecycleIdentity = 'preserved'`, resize once, and assert the same connected node still has both markers.
4. Dispatch `livewire:navigated` twice and assert one video and the same session key remain.
5. Navigate through a real same-origin Livewire link to the localized catalogue page, call `page.goBack()`, wait for player restoration, call `page.goForward()` and `page.goBack()` again, then assert the selected `format=m3u8` URL and exactly one new active session are restored without a listener on the removed node.
6. Switch through `[data-player-media-format="mp4"]`; assert the session key changes and only one ready video exists.
7. Dispatch a persisted `pagehide`, assert readiness is cleared, dispatch persisted `pageshow`, and assert one session becomes ready again.
8. Record bubbled `catalog-progress` details, provide finite `currentTime`/`duration`, dispatch play then changed-position pause, and assert exactly two increasing sequence values without duplicate positions before navigation and one ordered continuation after reinitialization.
9. Assert keyboard focus can reach the localized play/retry controls, the status/caption regions retain polite live semantics, and there is no external leak, page error, console error, same-origin failure, or horizontal overflow.

Use `new PageTransitionEvent('pagehide', { persisted: true })` and the corresponding `pageshow` event so the actual global listeners are exercised without relying on browser-specific BFCache admission.

- [ ] **Step 5: Implement the desktop media/error matrix**

Start the second test with:

```js
test.skip(testInfo.project.name !== 'Desktop Chromium', 'Detailed media matrix runs once.');
```

Then assert:

- valid HLS requests one manifest, init, and segment;
- `scenario.manifestStatuses = [503]` produces one localized retrying state and a later successful manifest request;
- `scenario.manifestStatuses = [503, 410]` reaches `expired`, exposes retry, and after waiting beyond `HLS_RETRY_DELAY_MS` does not issue a third manifest request;
- `scenario.segmentBodies = ['corrupt']` reaches localized media recovery exactly once, then either succeeds with the next valid segment load or enters the bounded playback-error state without a second recovery;
- switching to MP4 observes a Range header and a `206` response with valid `Content-Range`;
- an injected native `<track kind="subtitles" srclang="ru">` served by the fixture router clears the caption warning on load;
- setting `scenario.captionStatus = 503` before replacing the track reveals the active-locale non-fatal caption warning while video controls remain enabled;
- manual retry after a recoverable HLS failure creates only one additional active load path.

The all-viewport test already covers both `/en` and Russian sessions. The desktop matrix may reuse either locale but must derive expected copy from `data-player-copy`; do not manually set translated text in the test.

- [ ] **Step 6: Run RED, implement corrections, then GREEN**

First run before the spec is complete:

```bash
npx playwright test tests/browser/player-lifecycle.spec.js --project="Desktop Chromium"
```

Expected RED: at least one missing marker/lifecycle assertion.

After implementing all scenarios, run:

```bash
npx playwright test tests/browser/player-lifecycle.spec.js --project="Desktop Chromium"
npx playwright test tests/browser/player-lifecycle.spec.js
php artisan test --filter=BrowserCiContractTest
```

Expected: desktop focused PASS, then Desktop/Mobile/Tablet PASS, then PHPUnit contract PASS. No request reaches the real `media.example.com` network.

- [ ] **Step 7: Commit Task 4 only**

```bash
git add tests/browser/player-lifecycle.spec.js tests/Unit/BrowserCiContractTest.php resources/views/livewire/catalog-title-player.blade.php
git diff --cached --check
git commit -m "test: verify player lifecycle in Chromium"
```

---

### Task 5: Documentation, broad verification, and delivery

**Files:**

- Modify: `docs/frontend.md`
- Modify: `docs/testing.md`
- Modify: `docs/audits/video-playback-report.md`
- Modify: `docs/plans/laravel-video-portal-modernization.md`
- Modify: `CHANGELOG.md`
- Modify: `README.md`
- Modify: `docs/superpowers/plans/2026-07-16-player-lifecycle-browser-fixtures.md`

**Interfaces:**

- Consumes: completed Tasks 1–4 and their exact command output.
- Produces: owner documentation, living-plan acceptance record, English changelog entry, Russian visitor history, verified commits on `main`, and a clean task handoff.

- [ ] **Step 1: Update topic-owner documentation with measured facts**

Document these exact boundaries:

- `frontend.md`: JSON copy comes from active-locale PHP catalogs; one WeakMap session owns all resources; caption failures are non-fatal; no production subtitle-track domain exists.
- `testing.md`: fixture payloads are local text, detailed matrix is desktop-only, lifecycle matrix runs across all viewports, and the focused commands are the two Playwright invocations in Task 4.
- video playback audit: mark deterministic MP4 Range, HLS retry/terminal, WebVTT failure, navigation, source-switch, resize, and page lifecycle evidence complete; retain normalized audio/subtitle storage as a separate limitation.
- living plan: add a dated player-lifecycle checklist and record exact test/project counts rather than claiming universal codec/browser coverage.

- [ ] **Step 2: Update release communication**

Add one English `CHANGELOG.md` bullet describing localized Plyr/runtime copy, bounded HLS recovery cancellation, deterministic local MP4/HLS/VTT fixtures, and the preserved signed/progress/subtitle-domain boundaries.

Add one concise Russian visitor-history line under 16 July 2026 explaining that player source switching, recovery, navigation, and Russian/English status messages became more reliable. Keep `## История обновлений для посетителей` as the final README H2.

- [ ] **Step 3: Run focused PHP and translation gates**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=CatalogPlayerCopyTest
php artisan test --filter=FrontendAssetContractTest
php artisan test --filter=PlayerBrowserFixtureContractTest
php artisan test --filter=BrowserCiContractTest
COMPOSER_ALLOW_SUPERUSER=1 composer analyse
```

Expected: Pint changes only task PHP files; all focused tests pass; PHPStan reports zero errors.

- [ ] **Step 4: Run frontend/browser gates**

```bash
npm run build
npx playwright test tests/browser/player-lifecycle.spec.js --project="Desktop Chromium"
npx playwright test tests/browser/player-lifecycle.spec.js
```

Expected: Vite production build succeeds; the focused desktop matrix and complete three-project lifecycle matrix pass with no external request, console error, or page error.

- [ ] **Step 5: Run Laravel/document/static gates**

```bash
APP_CONFIG_CACHE=/tmp/seasonvar-player-config.php APP_ROUTES_CACHE=/tmp/seasonvar-player-routes.php APP_EVENTS_CACHE=/tmp/seasonvar-player-events.php APP_PACKAGES_CACHE=/tmp/seasonvar-player-packages.php APP_SERVICES_CACHE=/tmp/seasonvar-player-services.php php artisan view:cache
php artisan project:docs-refresh
php artisan project:docs-refresh --check
git diff --check
php -r '$ru=require "lang/ru/catalog.php"; $en=require "lang/en/catalog.php"; $flatten=function(array $a,string $p="") use (&$flatten){$o=[];foreach($a as $k=>$v){$q=$p===""?(string)$k:$p.".".$k;if(is_array($v)){$o+=$flatten($v,$q);}else{$o[$q]=$v;}}return $o;}; $r=$flatten($ru["player"]);$e=$flatten($en["player"]);exit(array_keys($r)===array_keys($e)?0:1);'
```

Expected: Blade compiles; docs refresh is stable; no whitespace errors; player translation leaf keys match exactly.

- [ ] **Step 6: Review the complete task diff**

```bash
git diff --stat
git diff -- app/View/ViewData/CatalogPlayerCopy.php app/Livewire/CatalogTitlePlayer.php resources/views/livewire/catalog-title-player.blade.php resources/js/player.js lang/ru/catalog.php lang/en/catalog.php tests/Unit/CatalogPlayerCopyTest.php tests/Unit/FrontendAssetContractTest.php tests/Unit/PlayerBrowserFixtureContractTest.php tests/Unit/BrowserCiContractTest.php tests/browser/prepare-fixtures.php tests/browser/support/player-media-fixtures.js tests/browser/player-lifecycle.spec.js tests/browser/fixtures/player docs/frontend.md docs/testing.md docs/audits/video-playback-report.md docs/plans/laravel-video-portal-modernization.md CHANGELOG.md README.md
git status --short --branch
```

Expected: no raw source URL/token output, no second player/route/model, no hardcoded JS UI sentence, no unrelated staged path, and branch `main`.

- [ ] **Step 7: Commit documentation and push verified `main`**

```bash
git add docs/frontend.md docs/testing.md docs/audits/video-playback-report.md docs/plans/laravel-video-portal-modernization.md docs/superpowers/plans/2026-07-16-player-lifecycle-browser-fixtures.md CHANGELOG.md README.md
git diff --cached --check
git status --short --branch
git commit -m "docs: close player lifecycle verification"
git push origin main
git fetch origin main
test "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)"
```

Expected: task commits are pushed to `origin/main`. If concurrent work remains unstaged, report it explicitly and do not stage, revert, or absorb it.

---

## Plan self-review checklist

- [x] Every acceptance criterion in the approved design maps to Tasks 1–5, including real history navigation, both locales across all viewports, and one bounded HLS media recovery.
- [x] Runtime/control payload names are identical in PHP test, ViewData, Blade, and JavaScript.
- [x] Browser fixture URLs match in manifest, fixture router, and isolated database records.
- [x] Retry assertions account for disabled hls.js internal retries and one application retry.
- [x] WebVTT coverage remains a browser fixture and does not imply production track storage.
- [x] No task adds a package, route, migration, public binary, provider request, or second player boundary.
- [x] No task-owned commit absorbs currently active header-search, recommendation, CI, profile, technical-issue, comment, tag, media-import, or cache work.
