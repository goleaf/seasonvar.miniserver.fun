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
| FE-01 | Confirmed problem | 41 `request()` и 1 `config()` в Blade | Передавать active flags/class maps/max length из view data/components | Pending P1 | Zero-tolerance Blade contract + full browser suite |
| FE-02 | Confirmed problem | Layout 784 lines renders many non-standard SEO meta/content blocks | Remove unreachable/no-value blocks, retain canonical/OpenGraph/JSON-LD | Pending P4 | SEO snapshots and browser metadata checks required |
| FE-03 | Confirmed problem | JSON-LD encoded inside Blade | Prepare an already hex-safe JSON scalar before render; retain one explicitly reviewed raw script boundary | Pending P1 | Validate JSON parse and XSS payload tests |
| FE-04 | Confirmed problem | CSP is report-only with broad HTTPS origins and inline style allowance | Inventory actual asset/provider origins, staged tightening | Pending security | Enforcement can break playback if rushed |
| FE-05 | Confirmed problem | HLS chunk is the largest asset | Keep lazy; inspect whether `hls.light` features match used capabilities | Planned measurement | Do not remove required subtitle/audio/error support blindly |
| FE-06 | Probable | Header/footer active-state markup duplicated | Shared prepared navigation view model/component API | Pending P1 | Preserve auth/admin conditional links and 44px targets |
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
