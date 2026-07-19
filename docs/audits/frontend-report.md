# Отчёт по frontend

Проверено: 15.07.2026. Design direction сохраняется: светлый русскоязычный технический каталог, slate/white/emerald, высокая информационная плотность, минимальное движение. Полный визуальный redesign без продуктовой причины не выполняется.

## Подтверждённое состояние

- Tailwind CSS 4.3.2 подключён официальным Vite plugin; CSS использует `@import "tailwindcss"`, theme tokens и reduced-motion rules.
- Vite production build проходит. CSS app — 154.51 kB / 32.90 kB gzip; app JS — 8.51/3.61; player — 11.73/3.84; Plyr lazy — 111.81/32.86; HLS lazy — 331.90/104.61.
- 27 shared Blade components покрывают poster, cards, form fields, status and layout primitives.
- Playwright/axe 18/18 проходит на desktop/mobile: auth/library, filters, directories, title/player shell, progress/Continue Watching; same-origin console/network guard зелёный.
- Primary above-fold content server-rendered; player lifecycle изолирован в `resources/js/player.js`, HLS импортируется lazy.

## Реестр выводов

| ID | Класс | Наблюдение | Изменение | Статус | Verification / риск |
| --- | --- | --- | --- | --- | --- |
| FE-01 | Confirmed problem, fixed | Baseline: 41 `request()`, 1 `config()` and auth/gate directives in Blade | Prepared request/config/audience/permission/class state plus zero-tolerance scan | Implemented and browser verified | Zero matches in 52 Blade; 42 tests/339 assertions; `view:cache`, build and 21/21 desktop/mobile/tablet scenarios pass |
| FE-02 | Confirmed problem, fixed | Layout had 783 lines including zero-count unsupported meta and ~590 lines behind a flag no producer enabled | Retain concise canonical/discovery/OpenGraph metadata and builder-owned JSON-LD; remove unreachable matrix | Implemented and browser verified | Layout 96 lines; metadata/schema tests, full suite, build and 21/21 browser matrix pass; official Google guidance recorded |
| FE-03 | Confirmed problem, fixed | JSON-LD was encoded inside Blade | `AppLayoutData` normalizes objects and prepares hex-safe strings with `Js::encode()`; one audited raw scalar boundary remains | Implemented and browser verified | Regression parses JSON and proves `</script><script>` is hex-escaped; Blade scan rejects `json_encode()`; 21/21 browser matrix passes |
| FE-04 | Confirmed problem, partially reduced | CSP remains report-only with broad HTTPS origins and inline style allowance; ordinary Livewire bundle required dynamic evaluation | Task 27 selected the package-supported CSP-safe bundle; inventory actual provider/style needs before staged enforcement | Pending security | Firefox filter/back-forward smoke has zero CSP errors; enforcement can still break media or inline styling if rushed |
| FE-05 | Confirmed problem | HLS chunk is the largest asset | Keep lazy; inspect whether `hls.light` features match used capabilities | Planned measurement | Do not remove required subtitle/audio/error support blindly |
| FE-06 | Confirmed problem, fixed | Header/footer active-state/audience markup duplicated | Shared immutable `LayoutNavigationItem` schema composed by request presenter | Implemented and browser verified | Guest/viewer/admin regression plus 21/21 browser scenarios preserve auth/admin visibility, responsive geometry and 44px targets |
| FE-07 | Intentional | Light-only UI; no dark mode implementation | Preserve unless product explicitly requires dark mode | Accepted | User requested capable design generally, but project UI standard is light source of truth |
| FE-08 | Intentional | JavaScript owns browser-only player/dialog/navigation cleanup only | Preserve | Accepted | No custom AJAX replacement of Livewire |

## Page audit matrix

| Surface | Responsive/a11y state | Required follow-up |
| --- | --- | --- |
| Home/catalog/directories | Browser-covered, no confirmed horizontal overflow | Cold performance and image/layout-shift measurement |
| Title/player | Shell, saved progress and navigation covered | Provider error matrix, keyboard/PiP/fullscreen manual matrix |
| Auth/profile/library | Desktop/mobile browser-covered | Password-manager/autofill and session-expiry checks |
| Admin catalog/imports | Feature tests exist; browser coverage is lighter | Mobile overflow, loading/error states, authorization browser smoke |
| Stats | Route exists; builder is oversized and production response can time out | Snapshot redesign/performance before visual polish |
| Error/maintenance pages | Russian error views exist | 403/404/419/500/503 browser/a11y smoke |

All future Tailwind class variants must remain statically detectable. Dynamic partial class concatenation is not allowed; semantic variant maps are prepared outside Blade.

## Task 10 collection UI audit

Collection directory/dashboard/editor/page/profile/title selector/admin queue reuse the existing light panels, form/status components, full-poster frame, list title cards and pagination. Names/descriptions wrap without truncating user content, forms collapse to one column, controls retain 44px targets, cover is 16:9 with fallback, and destructive actions are separated/confirmed. The manual editor adds a real Livewire drag handle within one page; up/down remains the keyboard/touch/no-drag ordering baseline, and no hover-only control exists.

Native dialog/share progressive enhancement is isolated in the Vite module, restores focus and handles Escape/clipboard failure. Livewire renders localized loading/status/error/empty/no-result/unavailable/moderation/restore-expiry states, while policy remains server-side. Disposable Chromium smoke covered desktop and 390×844 mobile geometry, keyboard focus return, Cancel semantics, canonical sharing feedback, responsive owner/public/profile screens and zero console/page/request failures; no automated browser test was created or run under the explicit Task 10 constraint.

## Task 12 discussion UI audit

One reusable component is embedded on title/player and eligible collection pages; title/selected season/selected episode scope is explicit. Root/item/reply/composer/report/admin/profile views use prepared DTOs, stable ID keys, existing panels/icons/status/pagination and no Volt, `@php`, raw body, inline CSS or inline business JavaScript. Mobile replies retain one indentation level, text/links/actions wrap and touch controls remain at least 44px.

Whole-body spoilers and long tails are server revealed, keyboard/screen-reader labelled and removable from the next payload. Loading/live/error/guest/unverified/restricted/disabled/empty/tombstone/end-of-replies states are present; dialog/editor/direct-anchor focus and reduced-motion behavior live in the Vite module. RU/EN catalogs have exact key parity. Managed Chromium confirmed the complete visible action set, stable focus and 390×844 geometry: document/viewport width stayed 390 px, the discussion used 366 px and the single flattened reply retained 328 px with no horizontal overflow. Exact snapshots and zero-error browser evidence are recorded in `audits/verification-report.md`.

## Task 13 review UI audit

One reusable `CatalogTitleReviews` section is embedded on the title page and explicitly labels complete-serial scope. Review card/composer/filter/report, private self history/inbox/preferences and admin queue consume prepared DTOs, stable ID keys and existing light panels/forms/buttons/status/pagination. No season/episode review tab, sentiment/emoji/Markdown/public-author/search-directory fake control exists. Blade contains no review model/service query, raw HTML, Volt, `@php`, inline CSS or inline business JavaScript.

Spoiler title and body are omitted until server reveal; title/body/rating/verified/edited/status/totals and all actions have textual accessible labels. Layout wraps long Unicode/URLs/names/counts, controls retain 44px targets, rating has a native keyboard/touch select fallback, direct focus respects reduced motion, and loading/empty/guest/unverified/restricted/pending/deleted/error/confirmation states are implemented. Session draft is opaque-account/target/edit scoped and clears only on success. Final real browser responsive/zoom/console evidence belongs to verification report rather than being claimed before execution.

## Task 23 mobile/responsive architecture audit

The audit confirmed one canonical SSR/Livewire tree, one layout/navigation presenter, one catalog/filter state, one Plyr/HLS player, standard Tailwind breakpoints and zoom-safe viewport metadata. No duplicate mobile route/Blade/player, fixed bottom navigation, user-agent layout, orientation lock or gesture-only essential action exists. Concrete defects were a dense icon-oriented phone nav, missing safe-area/dynamic-viewport primitives, eager optional modules, sub-16 px form controls, horizontal calendar precision, inline player bridge, duplicate unload flushing and missing Media Session/network hints.

The repository has no Web App Manifest, service worker/registration/cache namespace, install lifecycle, push subscription/backend, IndexedDB/offline license or valid offline-video architecture. This is accepted capability absence, not a prompt to add fake controls. Existing mobile v1 sync is a protected catalog/user-state API and existing direct-file download is online streaming only. No mobile schema/index, browser auth storage, PWA cache or device fingerprint is justified.

Implemented presentation uses one native mobile menu from desktop navigation data, deferred canonical filters, visual-viewport keyboard limits, safe-area/dynamic units, coarse-pointer targets, password visibility, public share fallback, truthful connection state and route-scoped modules. Player remains singular and receives public-only Media Session metadata, optional save-data behavior and cleanup. Screen width/orientation never changes server authorization, cache identity, locale/audio/subtitle preference or canonical URL.

Current remaining capability risks are real iOS Safari native fullscreen/PiP/keyboard/install behavior, Android Chromium PiP/Media Session/interruption, physical tablet/foldable split-screen, provider media/network and hosted checkout return; they require hardware/provider infrastructure and must not be inferred from Chromium emulation. PWA install, push and offline video remain unsupported until their complete backend/security/legal contracts exist.
