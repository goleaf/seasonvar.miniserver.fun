# Отчёт по Livewire 4

Проверено: 15.07.2026. В проекте 25 Livewire PHP-файлов: 19 components/pages и 6 Form objects. Volt отсутствует и не будет добавляться.

## Подтверждённые controls

- Class-based components, typed state and `#[Locked]` identifiers используются в catalog/player/admin flows.
- Нетривиальные auth/catalog/library формы вынесены в Livewire Form objects.
- Mutations повторно авторизуют user/resource; locked property не считается authorization.
- Public state не содержит query builders, service objects или permanent upstream media URL.
- Catalog/directory/library use paginator links and stable entity keys; browser tests validate URL restoration and hydration.
- Player uses the smallest practical `wire:ignore` boundary and a dedicated browser lifecycle module.

## Реестр выводов

| ID | Класс | Наблюдение | Изменение | Статус | Verification / риск |
| --- | --- | --- | --- | --- | --- | --- |
| LW-01 | Confirmed problem | `CatalogAdministrationManager` 903 lines and player 661 lines carry multiple workflows | Split by cohesive workflow only after payload/action characterization | Pending P5 | Mechanical fragmentation may create event/state coupling |
| LW-02 | Confirmed problem | Title page polls every 3 seconds; aborted poll is expected during navigation | Measure request count/payload, stop polling when no active refresh, ensure cleanup | Pending P4 | Must retain near-live import feedback |
| LW-03 | Confirmed problem | Dead `ViewingActivity` component remains behind legacy redirect | Prove no route/test/consumer uses it, then remove with regression | Pending P4 | Avoid silently removing legacy URL behavior |
| LW-04 | Probable | Large computed/build render paths may re-query or serialize extra state | Instrument SQL and snapshot payload per critical component | In verification | Existing budgets cover selected paths, not every state |
| LW-05 | Proposed | Use lazy/defer/islands for independent expensive regions | Apply only after measurement; catalog facets already use deferred isolation | Planned | Islands can complicate state/SEO if used mechanically |
| LW-06 | Proposed | Evaluate `wire:navigate` | Defer until title/player cleanup, metadata, analytics and scroll tests exist | Rejected for current P0 | Player listener/source leaks are higher risk than potential navigation gain |
| LW-07 | Intentional | JavaScript remains for media/device/browser events | Preserve small explicit boundary | Accepted | No custom AJAX |

## Required per-component audit

For each component record responsibility, public/locked properties, actions, validation/authorization, query count, serialized snapshot bytes, render frequency, global events/listeners and browser cleanup. Acceptance requires pending/success/empty/validation/permission/failure states for every user action, deterministic `wire:key`, and no unbounded model graph in public state.

Global event producers/consumers must be listed in `docs/frontend.md` when introduced. Event-dispatch chains without a named producer and consumer are rejected.
