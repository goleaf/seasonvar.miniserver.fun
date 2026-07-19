# Livewire `wire:poll` bounded active-state design

Дата: 20.07.2026

## Цель

Закрепить polling только там, где видимый active server state действительно должен перейти в terminal state без ручного reload, и устранить документационное обещание polling на `/stats`, которого в приложении уже нет.

## Официальный контракт и inventory

Livewire 4 без modifiers выполняет component refresh каждые `2.5s`; значение directive может указывать action. `.[number]s` и `.[number]ms` задают interval. В background tab Livewire по умолчанию сокращает число запросов на 95%; `.keep-alive` отключает эту защиту. `.visible` полностью останавливает interval, когда element вне viewport.

Repository содержит два authored poll:

- `CatalogTitleDetail`: только при active refresh, `3s.visible`, action `refreshCatalog`, terminal state удаляет attribute;
- `SeasonvarImportManager`: только при active run, `5s.visible`, action `refreshRuns`, terminal state удаляет attribute.

`StatsDashboard` рендерит `CatalogStatsSnapshotCache::snapshot()` один раз и не содержит poll. Snapshot обновляется существующими invalidation/warming boundaries; feature test явно запрещает `wire:poll` на `/stats`. Три canonical owner statements всё ещё описывают прежний `15s.visible` contract и являются stale documentation.

## Рассмотренные варианты

1. Выбранный: сохранить два conditional `.visible` poll, добавить repository-wide static contract и исправить stale `/stats` owners.
2. Вернуть `/stats` polling каждые 15 секунд. Отклонено: страница показывает warmed snapshot, visitor не выполняет active operation, а каждый открытый tab создавал бы бессрочные Livewire requests без нового authoritative data.
3. Добавить `.keep-alive`. Отклонено: terminal transitions не требуют обновления скрытой вкладки, а Livewire background throttle является нужной защитой нагрузки.
4. Заменить poll WebSockets. Отклонено: подтверждённой realtime/SLA потребности и production broadcast infrastructure нет; новая dependency/service/operations boundary несоразмерна двум коротким active workflows.

Bare `wire:poll`, per-card polling и интервал без explicit units запрещены. Интервалы 3 и 5 секунд различаются осознанно: title refresh коротко ждёт один targeted job, importer показывает более долгую operational работу и не требует более частого чтения.

## Authority, performance и lifecycle

Directive не является authority. Каждый action повторно читает server-owned state; title refresh использует существующий coordinator/store, importer — admin service/run projection. Условие render решает только наличие следующего poll, а terminal state исчезает вместе с attribute.

`.visible` предотвращает viewport-hidden requests, стандартное background throttling Livewire остаётся включённым. `.keep-alive` отсутствует. `/stats` не создаёт transport loop: warmed/stale snapshot честно показывает build/serve timestamps и обновляется доменными invalidation/warmer paths.

## Cross-feature и production impact

- Runtime code, public/admin routes, auth/gates, cache keys/TTL, queries, queue jobs и schema не меняются.
- Existing title/import localized statuses, mobile layout, SEO, privacy и provider URL protection сохраняются.
- Новых dependencies, JavaScript, environment variables, service workers или production services нет.
- Correction deployment состоит только из test/docs; rollback удаляет эти records.

## Проверки

- Static test считает ровно два authored `wire:poll.*` в application views, требует exact `3s.visible`/`5s.visible`, запрещает bare/keep-alive и подтверждает отсутствие polling в stats view.
- Documentation assertion сначала падает на stale owners, затем проходит после исправления `architecture.md`, `performance.md`, `UI_STANDARDS.md` и explicit inventory в `frontend.md`.
- Existing title/import/stats feature tests, Pint, Vite/docs/diff gates, legacy scan и full suite выполняются до завершения.

## Самопроверка дизайна

Все official modifiers оценены, existing intervals и terminal behavior сохранены, stale runtime claim локализован, исторические записи не переписываются. Нет нового product behavior, package или hidden operations requirement.
