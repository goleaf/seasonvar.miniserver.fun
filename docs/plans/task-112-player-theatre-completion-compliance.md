# Task 112 — compliance matrix режима «Театр»

Дата: 27.07.2026

| Требование | Статус | Evidence |
|---|---|---|
| Root `AGENTS.md`, requirements index и применимые owners прочитаны | `completed` | Повторная проверка после `397247ee` до edits |
| Работа только в существующей `main` | `completed` | `main...origin/main [ahead 5]` |
| Exact workspace lease и NUL manifest | `completed` | `task-112-player-theatre-completion`, paths declared |
| Current task registry и task plan до реализации | `completed` | Этот matrix, design, implementation plan и registry |
| Theatre остаётся scoped document flow, не overlay | `completed` | Scoped CSS; extended Playwright без nested scroll/overflow |
| Вход удерживает toggle/player во viewport | `completed` | Геометрия проверена во всех device projects |
| Выход возвращает прежний scroll и focus | `completed` | Click/Escape assertions с точной позицией и `preventScroll` |
| Dialog/fullscreen Escape priority сохраняется | `completed` | Browser priority assertion + Chromium/Firefox lifecycle |
| Video DOM/progress identity не разрушается | `completed` | Exact node identity до/после Livewire refresh и exit |
| Один полный `wire:ignore`, root `wire:ignore.self` сохраняется | `completed` | Feature contract: два marker, один full и один `.self` |
| Mobile, tablet, landscape, 44 px, safe area | `completed` | Default `8/1`, extended `19/2` |
| Русский UI и ru/en parity | `already_compliant` | Existing theatre translation keys переиспользуются |
| Auth/authorization/playback grants | `already_compliant` | Server boundaries и public actions не меняются |
| Routes/API/schema/cache/search/SEO/import/admin | `not_applicable` | Public/data contracts не меняются |
| PWA не кеширует media/playback/download | `already_compliant` | Worker/denylist не менялись; lifecycle/release checks пройдены |
| Production operations/rollback | `completed` | Vite `28 modules`; release `30 sources`/`19 assets`; rollback documented |
| README, owners, русский CHANGELOG | `completed` | Visitor result, owners и dated technical entry обновлены |
| Legacy/duplicate/dead theatre implementation search | `completed` | Один JS owner и scoped CSS; duplicate route/store/service не найден |
| Exact staged review, commit и push | `in_progress` | Pending verification |

## Cross-feature impact

| Domain | Impact | Evidence / стратегия |
|---|---|---|
| Authentication/authorization/privacy | `unaffected` | Client presentation state; grants и policies неизменны |
| Player/progress | `affected` | Сохраняется тот же media DOM и session identity |
| Livewire | `affected` | Root `wire:ignore.self` и один media `wire:ignore` сохраняются |
| Mobile/accessibility | `affected` | Viewport, touch, focus, Escape и landscape проверяются |
| Translations | `unaffected` | Existing ru/en keys без новых labels |
| Caching/PWA | `unaffected` | Cache keys/worker отсутствуют в change set |
| SEO/search/routes/API | `unaffected` | SSR/public contracts неизменны |
| Imports/admin/premium/region/legal | `not_applicable` | Нет доменных записей или решений доступа |
| Deployment | `affected` | Нужен Vite/player release build; rollback согласованный |
