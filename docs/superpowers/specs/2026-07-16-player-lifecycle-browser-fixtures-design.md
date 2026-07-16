# Player Lifecycle and Deterministic Browser Fixtures Design

**Date:** 2026-07-16
**Status:** Approved for implementation planning
**Scope:** Existing web `CatalogTitlePlayer`, Plyr/HLS browser lifecycle, localized runtime copy, and deterministic browser verification

## Objective

Make the existing Seasonvar browser player demonstrably safe across source changes, Livewire morphs and navigation, browser history, viewport changes, page lifecycle restoration, and recoverable media failures. The implementation must exercise real Chromium behavior with local deterministic MP4, HLS, and WebVTT samples while preserving the current playback authorization, progress, importer, and media-storage architecture.

This work extends the one existing player boundary. It does not create a second player, a test-only production route, another playback source resolver, or a subtitle data model.

## Confirmed baseline

- `resources/js/player.js` is the only Plyr/HLS owner. It lazy-loads local Plyr and `hls.js/light`, stores sessions in a `WeakMap`, uses an `AbortController`, and invalidates stale asynchronous initialization through a generation token.
- `resources/js/app.js` initializes after DOM ready, Livewire morph and Livewire navigation; it flushes and destroys sessions before navigation, page hide, and element removal.
- `CatalogTitlePlayer` renders one `wire:ignore` media shell around a signed same-origin playback URL. Progress is allowed only for verified authenticated markup and includes a server-issued opaque token plus increasing event sequence.
- The existing browser suite uses an isolated SQLite database, blocks external network requests, and verifies the player shell, saved progress, and Continue Watching. It does not currently provide decodable deterministic media or runtime lifecycle assertions.
- `LicensedMedia` stores only `has_subtitles`; there is no canonical subtitle-track record, URL, language relation, or administrative workflow. A production subtitle model cannot be inferred from that boolean.
- Runtime player status and Plyr control labels are currently hardcoded in Russian inside `player.js`. This violates the established `ru`/`en` localization contract even though the server-rendered player shell is localized.
- A recoverable HLS network error schedules one delayed retry, but a later terminal error can leave that timer alive until it fires. Terminal failure, manual retry, source replacement, and destroy must cancel stale recovery work.

## Architectural decision

Retain the existing `CatalogTitlePlayer` → Blade media shell → `player.js` → Plyr/HLS pipeline and extend the current Playwright suite. Use route interception to fulfill fixture media entirely in the browser test process. This exercises Chromium, Plyr, HLS.js, Livewire events, and the current DOM without introducing Vitest/JSDOM or application routes that exist only for tests.

The rejected alternatives are:

1. Vitest/JSDOM: it would add a dependency and cannot reproduce native media, HLS fetch, page lifecycle, or Livewire DOM behavior reliably.
2. A Laravel browser-fixture controller/route: it would place test infrastructure in production application routing and create a second rendering path for the player.
3. Static source assertions alone: they can enforce markers but cannot prove listener cleanup, retry cancellation, media request behavior, or node identity.

## Component boundaries

### Localized player copy

A focused presentation object under `app/View/ViewData` produces an allowlisted nested array for the active application locale. It reads semantic keys only from the existing `catalog.player` PHP catalog and has no model, request, cache, or database dependency.

The payload has two stable branches:

- `runtime`: preparing, loading, ready, playing, paused, seeking, buffering, retrying network, retrying media, expired, playback error, fatal initialization error, ended, and captions unavailable.
- `controls`: the Plyr labels for restart, rewind, play, pause, fast-forward, seek, played, buffered, current time, duration, volume, mute/unmute, captions on/off, fullscreen enter/exit, settings, and picture-in-picture.

`CatalogTitlePlayer::render()` passes this presentation array to Blade. Blade serializes it into an escaped `data-player-copy` attribute on the exact `wire:ignore` shell. The data belongs to the rendered locale and is replaced only when the shell is replaced for a new player session.

`player.js` parses only the two known branches and accepts only non-empty strings. It never performs translation lookup, treats external or user content as a key, or exposes a missing key. If parsing fails, the server-rendered localized preparation text remains visible; initialization failure can expose retry without inserting an internal identifier.

### Player session lifecycle

`CatalogPlayerSession` remains the only owner of listeners, timers, Plyr, and HLS for one exact `title:episode:media` session key.

The lifecycle rules are:

1. Reserve an uninitialized connected media node for the current initialization generation.
2. Load Plyr and HLS dynamically only when required.
3. Recheck generation, connectivity, reservation, and existing session before constructing.
4. Attach all DOM/window/document listeners through the session `AbortController`.
5. Allow at most one heartbeat, stable-seek timer, preference-write timer, and HLS recovery timer per session.
6. Cancel the recovery timer before terminal failure, manual retry, a replacement retry, or destroy.
7. Flush progress at most once per explicit lifecycle boundary and only after a real play event; cleanup cannot manufacture zero progress.
8. Destroy HLS, Plyr, listeners, and timers before a source shell is removed or a Livewire navigation commits.
9. Clear reservation/readiness markers from both the original and Plyr-restored video node.
10. Ignore every later callback once `destroyed` is true.

Viewport resize and orientation updates may refresh Plyr fullscreen geometry but cannot replace the video node or create a second session. Livewire `morph.removing`, `livewire:navigating`, `livewire:navigated`, `pagehide`, `pageshow` with persisted state, and `beforeunload` continue to use the shared exported initialize/flush/destroy functions.

### Subtitle fixture boundary

No production track URL or relation is invented. The player supports native `<track>` elements if a legitimate renderer supplies them in the future. During initialization it may observe existing subtitle tracks for `load` and `error` using the same abort signal.

A track error is non-fatal. It reveals a translated, accessible `data-player-caption-status` message explaining that subtitles are unavailable while playback can continue. A successful load clears that notice. When no `<track>` exists, the status remains hidden and no unsupported subtitle control or claim is rendered.

The browser fixture injects a native WebVTT track before the player initializes. This validates browser/Plyr behavior and failure cleanup only; it does not claim that the current catalogue stores subtitle tracks.

## Deterministic media fixtures

Fixture payloads live under `tests/browser/fixtures/player` and are never copied to `public/`, application storage, or the catalogue database as binary production content.

- A very small generated MP4 and fragmented-MP4 HLS init/segment are committed as textual base64 fixtures. Playwright decodes them in memory for `route.fulfill`.
- The HLS manifest and WebVTT captions are committed as UTF-8 text.
- Fixture URLs use the already allowlisted `media.example.com` testing host and a unique `/player-fixtures/` prefix.
- The browser guard continues to abort every external request not handled by the explicit fixture router.
- The fixture router records request count, Range headers, response status, and requested resource name without recording cookies, signed grants, or source credentials.
- No fixture is downloaded from an external provider. The samples are generated locally, short, silent, and contain no third-party content.

The isolated Playwright database receives deterministic HLS and MP4 variants for the existing `browser-smoke` episode. These records use the normal `LicensedMedia` schema and signed playback route. The fixture router fulfills only the eventual allowlisted media host requests after the normal same-origin grant boundary redirects them.

## Error and recovery behavior

| Condition | Expected behavior |
| --- | --- |
| Valid HLS manifest and segment | One HLS session attaches, reaches ready/playable state, and does not duplicate requests after unrelated morphs. |
| First fatal HLS network error | Show localized retrying state and schedule exactly one delayed `startLoad()`. |
| Repeated network error | Cancel pending recovery and enter localized terminal playback error. |
| First fatal HLS media error | Invoke exactly one `recoverMediaError()` and show localized recovery state. |
| HTTP 401, 403, or 410 | Cancel pending recovery, show localized expired state, and offer normal page reload. |
| Native MP4 error | Show localized terminal playback error without HLS recovery. |
| Manual retry | Cancel stale timer, reset bounded retry counters, and restart only the active source. |
| Plyr/HLS dynamic import or initialization failure | Keep a safe localized fatal state and reload action; never expose exception text. |
| WebVTT load failure | Keep video usable and announce the translated non-fatal captions warning. |
| Source change/navigation/destroy | Flush eligible progress once, abort listeners, clear all timers, and ignore stale callbacks. |

Provider URLs, response bodies, signed query values, raw HLS error objects, exception messages, and internal state identifiers never enter visible text, console output, or persisted diagnostics.

## Browser verification matrix

Common scenarios run on Desktop Chromium, Tablet Chromium, and Mobile Chromium:

- active player initializes exactly once;
- changing viewport size preserves the same video node and session marker;
- switching HLS/MP4 variant destroys the old session and initializes one new session;
- Livewire navigation away and back leaves no active listener on the removed node;
- browser history restores URL selection without duplicate sessions;
- persisted pagehide/pageshow lifecycle reinitializes one clean session;
- repeated morph hooks do not duplicate progress events or HLS requests;
- keyboard/touch controls remain reachable, status announcements are accessible, and no horizontal overflow appears;
- Russian and English pages provide the matching runtime/Plyr copy without mixed-locale strings.

The detailed media/error matrix runs once on Desktop Chromium to keep CI bounded:

- valid HLS manifest/init/segment;
- one network retry followed by success;
- network retry followed by terminal failure with no late timer request;
- one media recovery;
- expired 410 response;
- MP4 byte-range request and `206 Content-Range` response;
- WebVTT success and failure;
- manual retry after recoverable failure;
- one progress dispatch per simulated playback boundary before and after reinitialization.

The tests assert DOM state, network observations, player node identity, and event counts. They do not depend on audible output, exact decoded frames, autoplay permission, provider availability, or timing-sensitive full video completion.

## PHPUnit and static contracts

The focused PHP test verifies:

- both locale catalogs contain the complete copy structure with identical leaf keys and placeholders;
- the ViewData object resolves strings for `ru` and `en` through the current translator;
- Blade exposes escaped `data-player-copy` and the captions status region;
- `player.js` contains no Cyrillic user-facing copy and no complete English fallback sentences;
- one `WeakMap`, one `CatalogPlayerSession`, one generation boundary, and the existing initialize/flush/destroy exports remain;
- the stale HLS recovery timer is cleared on terminal failure, retry, and destroy.

Existing playback authorization, delivery, progress, title query, Livewire refresh, and browser portal tests remain unchanged unless their assertions directly encode the removed hardcoded Russian JavaScript.

## Multilingual and accessibility requirements

- Every new visible, status, retry, captions, and Plyr control string uses `lang/ru/catalog.php` and `lang/en/catalog.php` with semantic keys.
- The locale comes from the server-rendered player shell and therefore follows initial render, localized URL/session/account selection, and Livewire hydration.
- A cached payload from another locale is not introduced; the player copy lives in the locale-separated page HTML already governed by the current cache architecture.
- JavaScript never concatenates translated sentence fragments or uses external content as a translation key.
- Status changes use the existing polite live region; fatal initialization may change it to an alert. The captions warning is a separate non-fatal status so playback state remains truthful.
- Retry remains a real keyboard/touch button with the existing 44-pixel contract. Focus, reduced motion, fullscreen, picture-in-picture, and orientation behavior use the existing Plyr/design-system boundary.

## Non-goals

- No subtitle-track table, JSON column, relation, importer mapping, editor, API field, or localized slug.
- No DRM, analytics, casting protocol, transcoding, FFmpeg runtime dependency, offline video, or downloaded provider media.
- No change to playback entitlement, source URL validation, signed grant TTL, direct download, media health, or progress-completion rules.
- No new npm/composer package, Vitest environment, public fixture route, second Vite entry, or inline business script.
- No production claim that subtitles are selectable until a future canonical track domain exists.

## Documentation ownership

- `docs/frontend.md`: player copy transport, lifecycle ownership, captions warning, and fixture boundary.
- `docs/testing.md`: deterministic player browser matrix and commands.
- `docs/audits/video-playback-report.md`: close the verified lifecycle/media rows and retain the normalized subtitle-domain limitation.
- `docs/plans/laravel-video-portal-modernization.md`: implementation checklist and measured acceptance record.
- `CHANGELOG.md`: English technical release entry.
- `README.md`: concise Russian visitor-facing player reliability entry while preserving the visitor-history section as the final H2.

## Acceptance criteria

1. One player session exists per connected exact media shell across initial render, morph, source change, navigation, history, page restore, and resize.
2. All listeners and timers are released on destroy; stale initialization or HLS recovery cannot mutate a removed/replaced session.
3. Progress remains token-bound, sequence-ordered, post-play only, and duplicate-free through lifecycle transitions.
4. HLS network/media recovery is bounded and terminal HTTP states are localized safely.
5. MP4 Range, HLS, and VTT fixtures run without external network access or production data.
6. Every runtime/Plyr/captions string resolves through paired `ru`/`en` PHP catalogs; `player.js` contains no user-facing hardcoded language.
7. Subtitle fixture coverage does not introduce or imply a production subtitle-track domain.
8. Focused PHPUnit, Playwright matrix, Pint, PHPStan, translation audit, Vite build, Blade compilation, route/cache checks, documentation refresh, and diff review pass.
9. Existing playback, catalogue, Livewire, account preference, technical-issue link, download, and importer behavior remains operational.
10. Only task-owned files are committed on the existing `main`; concurrent work is preserved and excluded.

## Rollback

Rollback removes the ViewData payload, new catalog keys, caption status handling, fixture files/spec, and bounded recovery cleanup while restoring the previous static assertions. It requires no migration, data conversion, storage cleanup, queue action, cache flush, or importer interruption. Existing signed playback and progress records remain valid throughout deployment and rollback.
