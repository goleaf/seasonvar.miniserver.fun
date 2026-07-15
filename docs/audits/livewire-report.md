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

## Task 10 collection components

Добавлены class-based directory/dashboard/editor/page/profile/membership/admin components; Volt отсутствует. Stable public UUID/title IDs и content version locked, URL properties normalized, draft membership bounded UUID list, Eloquent models/query/services не сериализуются. Web/API paginator keys разделены явно; query/filter/sort state uses browser history, locale is restored at mount/hydration.

Membership Apply/Cancel/create-and-add, report dialog, cover, delete/restore/permanent delete, up/down ordering and share have localized pending/success/error/confirmation states. `collections.js` owns named `collection-selector-opened/closed` dialog focus lifecycle and native share/clipboard progressive enhancement. No polling or nested component graph was introduced; title membership is an isolated child so collection actions do not rebuild the player.

Disposable SQLite + Chromium acceptance confirmed stable-ID hydration, create/edit/delete/restore, staged multi-membership Apply/Cancel, create-and-add, idempotent repeat Apply, up/down ordering, report deduplication and URL Back/Forward state. At 390×844 the modal measured 352×664 inside a 390×844 viewport, produced no horizontal overflow, focused an internal control, returned focus to its trigger and discarded an unapplied checkbox change. Automated Livewire tests were intentionally neither added nor run because Task 10 explicitly forbids them.

## Task 12 discussion Livewire review

`CommentDiscussion`, private `DiscussionPage` and `CommentAdministrationManager` are class-based Livewire 4 components. Stable target IDs, locale, submission tokens, edit version and reveal/expand lists are locked; public state is bounded scalars/arrays, not models. Render re-resolves target/policy and delegates all queries/actions; Blade remains passive. URL state covers scope/sort/page/thread/focus, while component hydration reapplies locked collection locale.

Progressive replies load only one root and no polling/nested recursive component exists. Forms preserve content on recoverable failures, rotate idempotency tokens only on success and expose scoped loading/live regions. Direct moderator `comment` URL is normalized and reauthorized before dialog state. Managed Chromium then confirmed real hydration/transport, direct focus, spoiler/long-body replacement focus, create/edit/delete/restore, reply/reaction/report, block/mute, moderation and restriction/revocation; fresh reader/admin sessions had zero console errors and observed Livewire writes returned 200. Task 12 still explicitly prohibits adding/running automated tests, so repeated regression and production rollout observation remain residual operational gates.

## Task 13 review Livewire review

`CatalogTitleReviews`, private `ReviewHistoryPage` and `ReviewModerationManager` are class-based Livewire 4 components without Volt. Stable title/review IDs and locale are locked; URL state is bounded sort/rating/spoiler/verified/page, forms are scalar/enum/string/UUID/version fields, and render delegates Eloquent/query/policy/presenter work. Full models/relations, watch evidence and private moderator data are never serialized as public component state.

Create/edit draft survives recoverable errors, UUID rotates only on success, optimistic version rejects stale edit, and scoped loading does not blank the list. Server spoiler reveal, current vote, restrictions and permissions rehydrate from trusted services. Direct focus and session draft persistence are the only Vite behavior; no poll/nested item component/recursive graph exists. Browser hydration/history/focus evidence remains an allowed manual smoke item because Task 13 forbids creating/running automated tests.
