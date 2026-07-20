# Барьер завершения dispatch для queued-импорта Seasonvar

Дата: 20.07.2026

## Цель

Queued run `seasonvar:import --queued` не должен становиться terminal, пока coordinator ещё выбирает страницы, создаёт claims, staging rows и title groups. Исправление обязано сохранить единственную публичную команду импорта, текущие Redis-очереди, SQLite compatibility, rolling deployment и восстановление без `queue:clear`, `cache:clear` или прямого удаления данных.

## Подтверждённая причина

Production cron создал run `#964`. Пока `SeasonvarQueuedImportDispatcher::dispatchEligiblePages()` продолжал обход, первые title groups успели завершиться и поставить `FinalizeSeasonvarQueuedImport`. Global finalizer попал в краткое окно, когда уже созданные группы были terminal, а следующий chunk ещё не создал claims, и завершил run.

Фактическая последовательность:

- run получил `finished_at=2026-07-20 02:01:33 UTC`;
- новые claims создавались до `2026-07-20 02:01:38 UTC`;
- после terminal состояния сохранились 137 live claims того же run, 147 `queued` prepared rows и 91 `running` title group;
- очередь опустела, потому что jobs увидели terminal run и вернулись, не владея безопасной границей повторного открытия run.

Существующие проверки `outstandingForRun()` и active title groups корректны, но недостаточны: они наблюдают уже созданную работу и не знают, завершил ли coordinator весь dispatch.

## Рассмотренные варианты

### 1. Durable marker в `SeasonvarImportRun::summary` — выбран

`SeasonvarGlobalImportRunCoordinator` создаёт новый queued run с `dispatch_completed=false`. Global finalizer не завершает такой run, даже если текущие claims/groups временно равны нулю. Dispatcher атомарно объединяет актуальный summary с `dispatch_completed=true` только после окончания selection/dispatch и затем ставит обычный delayed finalizer.

Отсутствующий marker считается legacy-compatible состоянием: старые terminal/running rows и тестовые fixtures не блокируются автоматически. Гарантия для новых runs обеспечивается тем, что coordinator записывает явный `false` до появления первой page job.

### 2. Новая колонка или таблица

Отвергнуто: отдельная migration и production schema rollout не дают дополнительной корректности для одного versioned lifecycle-флага, уже принадлежащего run summary.

### 3. Redis-lock coordinator’а

Отвергнуто: ephemeral lock не является durable audit state, сложнее ведёт себя при crash/retry и связывает correctness terminal run с доступностью cache transport.

## Архитектура и поток данных

1. `SeasonvarGlobalImportRunCoordinator::acquire()` сохраняет `dispatch_completed=false` в summary нового global queued run.
2. `StartSeasonvarQueuedImport` переводит run в `running`; marker остаётся `false` при sitemap mirror, discovery, storage и page selection.
3. Page/group jobs могут завершаться и сигналить global finalizer, но `FinalizeSeasonvarQueuedImport` обновляет heartbeat и возвращается, пока marker равен строго `false`.
4. После полного `dispatchEligiblePages()` dispatcher под row lock перечитывает текущий summary, объединяет counters/evidence и записывает `dispatch_completed=true`, не стирая concurrent summary keys.
5. При `selected=0` тот же marker сохраняется до terminal update, чтобы audit отличал корректный пустой цикл от незавершённого dispatch.
6. После `true` существующие gates claims/groups/global lock остаются authority для catalog-wide finalization.

## Ошибки и retry

- Transient exception оставляет marker `false`; run возвращается в `queued`, а retry завершает dispatch и только затем меняет marker.
- Permanent failure переводит run в `failed`; global finalizer и так не работает с terminal/non-running run.
- Marker не разрешает завершение сам по себе: live claims и nonterminal groups продолжают блокировать finalizer.
- Legacy run без ключа сохраняет прежнее поведение. Новый run никогда не зависит от отсутствующего ключа.

## Production recovery run `#964`

Recovery выполняется только через существующие application services после deployment исправления. Сначала проверяются exact run ownership, terminal/nonterminal staging state, claims и отсутствие нового active global run. Нельзя очищать Redis queue/cache, удалять staging rows, обнулять claims массовым SQL или приписывать необработанные страницы к completed counters.

Безопасный путь должен вернуть `#964` в поддерживаемый retry/resume lifecycle либо создать новый canonical run, который сможет повторно принять эти страницы после точного освобождения claims через service boundary. Конкретный путь выбирается после чтения существующего `SeasonvarImportAdminService` и regression verification; ручная смена статуса без service contract запрещена.

## Тестирование

- RED regression воспроизводит global finalizer во время незавершённого dispatch: run с `dispatch_completed=false`, без текущих claims/groups, остаётся `running`, pipeline finalization не вызывается.
- Legacy regression подтверждает, что run без marker сохраняет прежний finalizer contract.
- Dispatcher test подтверждает `false` при acquire и `true` после полного dispatch, включая пустой selection.
- Existing claim/group/finalizer, command и calendar/importer tests должны остаться зелёными.

## Cross-feature impact

Затронуты importer lifecycle, queue observability, production cron, calendar freshness и catalog cache/recommendation handoff после импорта. Не меняются authentication, authorization, translations, public routes/API, search semantics, SEO, premium, payments, regional/legal access, player/media URL boundary, schema и frontend assets.

## Rollback

Code revert возвращает прежний finalizer behavior; schema/data rollback отсутствует. Summary key безопасно остаётся дополнительным audit field. Перед rollback необходимо дождаться terminal active run, иначе старый finalizer снова может завершить run во время dispatch.
