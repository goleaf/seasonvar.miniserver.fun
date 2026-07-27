# Текущая задача — Task 112: завершение режима «Театр»

## Реестр активных workstreams

| Workstream | Status | Evidence |
|---|---|---|
| Requirements, versions и current implementation audit | `completed` | [Task 112 evidence](archive/2026-07-27-player-theatre-completion-evidence.md) |
| Scroll lifecycle и visual theatre completion | `completed` | [Design](../superpowers/specs/2026-07-27-player-theatre-completion-design.md), [plan](../superpowers/plans/2026-07-27-player-theatre-completion.md) |
| Responsive, lifecycle и release verification | `completed` | PHPUnit `2286`/`208335`; browser/release details в [Task 112 evidence](archive/2026-07-27-player-theatre-completion-evidence.md) |
| Exact commit и push в `main` | `planned` | Ожидает финального full gate, staged review и lease approval |

## Реестр blocked/unresolved

| Workstream | Status | Evidence |
|---|---|---|
| Physical iOS/native fullscreen | `unresolved` | Общая playback-матрица требует реального устройства; theatre responsive contract проверен в Chromium/Firefox |

## Task-specific compliance matrix

| Requirement | Status | Evidence |
|---|---|---|
| Постоянные requirements и protected contracts | `completed` | [Compliance matrix](task-112-player-theatre-completion-compliance.md) |
| Viewport, scroll, focus и media DOM identity | `completed` | [Task 112 evidence](archive/2026-07-27-player-theatre-completion-evidence.md) |
| Routes, schema, cache, permissions и PWA boundaries | `already_compliant` | Публичные/data contracts не менялись |
| Production build и rollback | `completed` | Vite/release evidence и rollback сохранены в archive |
| Physical iOS/native fullscreen | `unresolved` | Нет реального устройства в доступной инфраструктуре |
| Commit и remote delivery | `in_progress` | Выполняются финальный full gate, exact staged review, commit и push |

## Последнее подтверждённое evidence

- [Task 112: завершение режима «Театр»](archive/2026-07-27-player-theatre-completion-evidence.md)

## Цель

Исправить потерю player viewport при включении «Развернуть театр», вернуть
исходную прокрутку и focus при выходе, сохранить тот же media DOM и завершить
контрастную responsive-сцену без fixed overlay.

## Активный checklist

| Priority | Workstream | Status | Evidence |
|---|---|---|---|
| critical | Requirements, versions и current implementation audit | `completed` | Повторно проверены после commit `397247ee` |
| critical | Workspace lease и exact manifest | `completed` | `task-112-player-theatre-completion`, paths declared |
| critical | Root-cause browser reproduction | `completed` | Desktop toggle `-252 px`, mobile toggle `-777 px` после входа |
| high | RED viewport/scroll/DOM tests | `completed` | PHPUnit `2 failed`; Playwright отсутствовал icon marker |
| high | Scroll lifecycle и visual theatre completion | `completed` | Cancellable frame, scroll restore, coherent dark scene |
| high | Desktop/mobile/tablet/landscape verification | `completed` | `8/1 skip`, extended `19/2 skips`, lifecycle `16/6 skips` |
| high | Player release/build/production compatibility | `completed` | Vite `28 modules`; `30 sources`, `19 assets` |
| medium | Owners, README, CHANGELOG, final compliance | `completed` | Owners, visitor/technical history и full PHPUnit `2286`/`208335` |
| critical | Exact commit и push в `main` | `planned` | Pending approved index |

## Подтверждённая причина

CSS скрывает большие блоки страницы, но JavaScript не компенсирует прежний
`window.scrollY`. После уменьшения документа workspace и toggle оказываются
выше viewport, хотя class/ширина формально корректны. Дополнительно site shell
и светлая seasons panel оставляют театр визуально незавершённым.

## Решение

- сохранить pre-theatre scroll только в памяти;
- после layout mutation мгновенно привязать viewport к workspace;
- после выхода сначала восстановить layout, затем scroll и focus без
  дополнительной прокрутки;
- отменять pending frame при lifecycle cleanup;
- не перемещать, не клонировать и не заменять `<video>`;
- сохранить один полный `wire:ignore` и root `wire:ignore.self`;
- скрыть вторичную оболочку и дать основной/seasons panels общую тёмную сцену;
- ограничить player доступной высотой landscape viewport;
- переключать label, `aria-pressed` и `fa-expand/fa-compress`.

## Compatibility и риски

- Routes, API, schema, cache keys, translations, permissions и importer
  contracts не меняются.
- Playback grants, progress, entitlement, provider URLs и service worker
  остаются защищёнными существующими boundaries.
- Rollback — согласованный возврат Blade/CSS/JS/tests/docs и Vite rebuild;
  data rollback/backup не требуются.

## Детальные документы

- [Design](../superpowers/specs/2026-07-27-player-theatre-completion-design.md)
- [Implementation plan](../superpowers/plans/2026-07-27-player-theatre-completion.md)
- [Compliance matrix](task-112-player-theatre-completion-compliance.md)
