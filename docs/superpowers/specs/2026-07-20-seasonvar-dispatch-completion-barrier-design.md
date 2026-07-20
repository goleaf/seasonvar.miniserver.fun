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
5. При `selected=0` marker и terminal status сохраняются одной row-locked transaction, чтобы watchdog не наблюдал открытый barrier у ещё running пустого цикла и stale model-save не затёр concurrent summary.
6. После `true` существующие gates claims/groups/global lock остаются authority для catalog-wide finalization.

## Ошибки и retry

- Transient exception оставляет marker `false`; run возвращается в `queued`, а retry завершает dispatch и только затем меняет marker.
- Permanent failure переводит run в `failed`; global finalizer и так не работает с terminal/non-running run.
- Marker не разрешает завершение сам по себе: live claims и nonterminal groups продолжают блокировать finalizer.
- Legacy run без ключа сохраняет прежнее поведение. Новый run никогда не зависит от отсутствующего ключа.

## Production recovery run `#964`

Recovery выполняется только через internal `SeasonvarPrematurelyFinalizedRunRecovery` после verification исправления. `SeasonvarGlobalImportRunCoordinator` под canonical start-lock и row lock проверяет exact sitemap/queue run, historical `queued_pages`, orphan staging/groups, соответствие каждого live claim prepared row и отсутствие другого active global run. Затем service повторно закрывает barrier, requeue-ит только `queued/preparing` prepared rows существующими unique jobs, сигналит nonterminal title groups и открывает barrier атомарным summary merge перед обычным global signal.

Исключение до финального merge оставляет `dispatch_completed=false`, поэтому повторный вызов продолжает тот же recovery безопасно. Нельзя очищать Redis queue/cache, удалять staging rows, обнулять claims массовым SQL, приписывать необработанные страницы к completed counters или создавать отдельную публичную recovery command. `#964` принят этой boundary с 147 nonterminal prepared rows и 91 active group при отсутствии другого global run; terminal invariants проверяются через bounded aggregate queries.

## Тестирование

- RED regression воспроизводит global finalizer во время незавершённого dispatch: run с `dispatch_completed=false`, без текущих claims/groups, остаётся `running`, pipeline finalization не вызывается.
- Legacy regression подтверждает, что run без marker сохраняет прежний finalizer contract.
- Dispatcher test подтверждает `false` при acquire и `true` после полного dispatch, включая пустой selection.
- Recovery tests подтверждают положительный resume, interrupted idempotent resume и fail-closed отказ для нормального terminal run, конкурирующего global run и неподдерживаемого claim ownership.
- Existing claim/group/finalizer, command и calendar/importer tests должны остаться зелёными.

## Cross-feature impact

Затронуты importer lifecycle, queue observability, production cron, calendar freshness и catalog cache/recommendation handoff после импорта. Не меняются authentication, authorization, translations, public routes/API, search semantics, SEO, premium, payments, regional/legal access, player/media URL boundary, schema и frontend assets.

## Rollback

Code revert возвращает прежний finalizer behavior; schema/data rollback отсутствует. Summary key безопасно остаётся дополнительным audit field. Перед rollback необходимо дождаться terminal active run, иначе старый finalizer снова может завершить run во время dispatch.
