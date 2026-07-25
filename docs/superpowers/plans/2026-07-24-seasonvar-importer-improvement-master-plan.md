# Seasonvar Importer Improvement Master Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan one change set at a time. `superpowers:subagent-driven-development` is allowed only after a new explicit user request permitting sub-agents.

**Goal:** Последовательно сделать импортёр Seasonvar наблюдаемым, конечным, экономным по SQLite/storage и устойчивым к остановке workers, не меняя публичную модель каталога, access boundaries и правило хранения только внешних media metadata.

**Architecture:** Сохраняется существующий fan-out/fan-in pipeline `seasonvar:import → SourcePage → prepared title group → network-free catalog apply → durable checkpoint → global finalization`. Улучшения не создают второй публичный импортёр и не переписывают зрелые boundaries целиком: сначала восстанавливается production process ownership, затем ограничиваются telemetry/retention, из apply устраняется сеть, staging и dispatch становятся batch-oriented, SQLite writes сериализуются измеренным способом, а крупные importer/parser classes раскладываются по уже существующим доменным обязанностям.

**Tech Stack:** PHP `8.5.8`, Laravel `13.21.1`, Laravel Boost `2.4.13`, Livewire `4.3.3`, SQLite `3.46.1`, Redis queues/locks, PHPUnit `12.5.31`, Pint `1.29.3`, Node.js `26.4.0`, Vite `8.1.4`, Tailwind CSS `4.3.2`.

**Plan status:** rolling implementation без верхнего лимита задач. Tasks 2–4 завершены в коде и тестах без production activation. Общая Tasks 2–4 delivery остаётся `unresolved` до отдельного решения владельца unrelated `composer.lock`, завершения активного system/lease documentation scope, получения clean shared worktree и повторной проверки единого importer snapshot. Из-за обязательного clean-worktree hook и уже общих code/config/docs hunks Tasks 2–4 доставляются одним явно записанным атомарным importer commit; это разовое delivery-исключение, а не объединение их implementation evidence или production activation. Этот документ не разрешает очистку Redis/failed jobs/database, запуск или остановку production workers, изменение `.env`, provider requests, migration или destructive data action без отдельного task-specific preflight и необходимой авторизации.

## 1. Измеренный baseline — 24.07.2026

### 1.1 Что уже сделано правильно и должно быть сохранено

- Единственная публичная команда импорта — `php artisan seasonvar:import`.
- `CatalogTitle → Season → Episode` остаётся единой иерархией; сезоны не создают отдельные каталожные тайтлы.
- Queue payloads передают ID, а не Eloquent graphs, raw HTML или provider credentials.
- Глобальный single-flight, page claims, title groups, `dispatch_completed`, per-page apply checkpoints и per-season merge checkpoints уже существуют.
- Подготовка страницы хранится durable в `seasonvar_import_prepared_pages`; finalizer способен продолжить после hard timeout.
- Provider HTTP выполняется до основной catalog transaction; episode rows пишутся chunked `upsert`.
- HTTP-клиенты имеют timeout/retry/URL validation; media availability использует bounded metadata requests.
- Полные видео не скачиваются и не сохраняются. Разрешены только `HEAD` или минимальный `Range: bytes=0-0`.
- Ошибки и event contexts проходят sanitization; public/API/admin не получают raw provider URLs.
- Recommendation rebuild, search sync, cache invalidation, API sync changes, release calendar и title merge уже включены в terminal flow.
- Source parity остаётся gate-driven: `serial` разрешён, RSS является freshness signal, actor/genre/country/tag handlers не включаются автоматически.

Эти свойства являются protected compatibility contracts. План улучшает их стоимость и управляемость, но не заменяет другим importer framework.

### 1.2 Фактические признаки риска

| Область | Evidence | Вывод |
| --- | --- | --- |
| Queue consumers | `app:health --json` сообщил отсутствие heartbeat у `seasonvar-import`, `seasonvar-title-refresh`, `cache-warm-v2`; `pgrep` показал только generic workers | Сначала нужен controlled worker/process recovery, иначе code optimization не восстановит freshness |
| Backlog | Всего `43 102` pending и `29 045` delayed jobs; `seasonvar-import` имел `41 103` pending, `26 971` delayed, `0` reserved | Producer продолжал создавать работу без intended consumers |
| Active run | Run `#1254`: `41 043` selected, `0` parsed, heartbeat `23.07.2026 13:51:55` | Run удерживается claims до их expiry и не продвигается |
| Dispatch time | Run `#1254` начат `11:08:19`, `dispatch_completed` сохранён `13:51:55` | Per-page claim/group/staging/dispatch path занял около `2 ч 43 мин 36 с` для `41 043` страниц |
| SQLite size | Main database: `28 154 552 320` bytes | Любые migrations, cleanup и indexes требуют backup/writer-pause/space review |
| Snapshots | `161 343` rows, `6 983 950 892` logical body bytes; table около `7 144 165 376` bytes | Четырнадцатидневное окно требует компактного representation и capacity budget |
| Prepared pages | `80 454 applied`, `20 557 failed`, `8 584 prepared`, `45 116 queued`; table около `4 422 078 464` bytes | Payload retention и повторная запись `_application_result` дают высокую write amplification |
| Prunable staging | `89 945` terminal rows, около `4 067 830 847` logical payload bytes, уже подходят под семидневное окно | Retention не должен зависеть от успешной global finalization |
| Import events | `3 690 976` rows, `557 880 495` bytes только context; `1 700 077` rows старше семи дней | Per-item info events используются как telemetry и раздувают SQLite |
| Event cardinality | `579 117` metadata chunk events, `540 254` media URL events, `417 762` media update events, более `700 000` file-size start/result events | Run counters и aggregate events должны заменить durable per-item info flood |
| File-size backlog | `880 115` eligible, `436 661` known, `443 454` due | Отдельная metadata задача остаётся большой и не должна удлинять title finalizer |
| Status latency | Cold `seasonvar:import --status` занял около `27` секунд | Operational status не должен синхронно full-scan `licensed_media` |
| Query plan | `LicensedMediaFileSizeBacklog::status()` и due selection дают `SCAN licensed_media` | Существующий `(file_size_check_status, file_size_checked_at, id)` index не покрывает eligibility expression и OR predicate |
| Finalizer network | Baseline обнаружил, что `syncParsedMedia(..., allowNetwork: false)` всё равно вызывал `InspectLicensedMediaFileSize::execute()`; Task 4 удалил этот путь и закрепил zero-HTTP tests | Риск закрыт в коде; delivery/production activation остаются отдельными gates |
| Large title merge | `TD-013`: 29 duplicate seasons, 930 episodes, 957 media ранее превысили `900` секунд | Checkpoints устранили полный повтор, но query/write amplification последнего season/final title остаётся |
| Class size | Importer `1 834` LOC / 26 dependencies; parser `1 788`; pipeline `1 715`; finalizer `622`; command `737` | Поведение трудно профилировать и безопасно менять одним change set |

### 1.3 Compression feasibility, не production promise

Read-only sample benchmark без вывода содержимого:

- последние `1 000` snapshots: `48 258 535 → 12 903 635` bytes при `gzip level 6`, ratio `0.2674`;
- последние `1 000` prepared payloads: `57 384 693 → 7 773 634` bytes, ratio `0.1355`.

Это только feasibility evidence. Production codec выбирается после CPU, memory, corruption, backup и dual-read benchmark; sample ratio не объявляется гарантией.

## 2. Неподвижные ограничения

- Не скачивать полный video body, не объединять HLS segments, не превращать storage/cache/database в video archive.
- Source catalog URLs допускаются только внутри `https://seasonvar.ru/`; external media URLs проходят существующую public-DNS/redirect/format boundary.
- Network calls запрещены внутри database transaction. После Task 4 они также запрещены внутри title-group catalog apply.
- Не удалять отсутствующие provider relations/media/episodes по неполному snapshot. Additive convergence остаётся default.
- Не менять route names, `CatalogTitle` slug binding, API v1 shapes, player grants, premium/region/legal checks и public cache identities.
- Не вводить polymorphic catalog metadata.
- Не добавлять Horizon, Supervisor, Kafka, RabbitMQ, object storage, PostgreSQL или новую dependency без отдельного измеренного решения и прямого разрешения там, где оно требуется.
- Не увеличивать `worker_timeout=900`, `retry_after=1200` или worker pool как способ скрыть долгую транзакцию.
- Не использовать `queue:clear`, `queue:flush`, `retry all`, `cache:clear`, `migrate:fresh`, `db:wipe` или broad data deletion.
- Queue names и serialized job payloads остаются backward-compatible при rolling restart. Изменение constructor job является отдельной migration boundary.
- Laravel jobs с database writes dispatch-ятся after commit; `retryUntil()` и `$tries=0` сохраняют time-based retry contract.
- Любая SQLite DDL: zero writers/claims/reserved jobs, verified backup, space assessment, `quick_check`/foreign-key evidence, additive migration, rollback rehearsal.
- Visible admin/UI copy остаётся русской и при затрагивании multilingual surface получает RU/EN key parity.
- По умолчанию каждая фаза — отдельный commit на существующей `main`; unrelated phases не объединяются. Единственное текущее исключение — атомарная delivery Tasks 2–4 после освобождения shared worktree: clean-worktree hook запрещает частичный commit при оставшихся unstaged/untracked importer files, а временно извлекать уже проверенную работу запрещено. Implementation и production statuses при этом остаются раздельными.

## 3. Целевой поток

```text
one scheduler/process owner
    ↓
global run reservation
    ↓
bounded sitemap discovery + durable dispatch cursor
    ↓
bulk source/group/staging registration
    ↓
page job claims lease just before work
    ↓
guarded HTTP + parser + media availability
    ↓
versioned compact prepared payload
    ↓
SQLite-aware writer admission
    ↓
network-free catalog/media apply
    ↓
per-page checkpoint + bounded title merge
    ↓
after-commit search/sync/cache/recommendation handoff
    ↓
terminal run finalization

independent lanes:
  scheduled bounded retention
  scheduled media health/file-size metadata
  low-cardinality operational snapshots
```

## 4. Dependency order

| Фаза | Tasks | Gate для следующей фазы |
| --- | --- | --- |
| 0. Production safety | 1 | Один process/scheduler owner, live heartbeat, controlled backlog trend |
| 1. Stop amplification | 2–5 | Event growth bounded, retention independent, finalizer network-free |
| 2. Throughput | 6–10 | Dispatch/apply/merge/media/file-size paths имеют budgets и query plans |
| 3. Correctness | 11–13 | Parser/provenance/refactoring покрыты fixtures и equivalence tests |
| 4. Operations/security | 14–16, 19 | Status быстрый и честный, failure drills воспроизводимы, finalization возобновляема, sensitive data bounded |
| 5. Optional parity | 17 | Только явно разрешённые page types |
| 6. Rollout/closeout | 18 | Полная compliance и rollback evidence |

Tasks 2–18 нельзя начинать как production rollout до закрытия operational prerequisites общей программы [`2026-07-24-system-maintenance-and-optimization-master-plan.md`](2026-07-24-system-maintenance-and-optimization-master-plan.md), как минимум Tasks 1–5. Code и tests можно готовить изолированно, но активация workers/migrations/retention под broken process ownership запрещена.

## 5. Task 1 — восстановить process ownership и безопасно возобновить consumers

**Priority:** P0 operational prerequisite.

**Status:** `blocked_by_external_operations`; application planning завершено, production process mutation не выполнялась.

**Files/evidence:**

- Follow: `docs/superpowers/plans/2026-07-24-system-maintenance-and-optimization-master-plan.md`, Tasks 1–5.
- Verify: `docs/queues.md`
- Verify: `docs/deployment.md`
- Verify: `docs/operations/logging-and-health.md`
- Update after execution: `docs/maintenance/technical-debt.md`
- Update after execution: `docs/plans/current-task-plan.md`
- Update after execution: `CHANGELOG.md`

**Steps:**

1. Зафиксировать новый redacted snapshot: Redis persistence, pending/delayed/reserved, oldest age, актуальный active run, live claims, worker heartbeats, SQLite latency/busy events, disk space and backup state.
2. Подтвердить ровно одного scheduler owner. Устранение duplicate `schedule:work`/cron producer требует operator action; planning task его не выполняет.
3. До старта consumers подтвердить Redis recovery boundary: persistent volume, контролируемый shutdown, восстановление очередей после restart и выбранный durability profile (`AOF everysec` либо эквивалентная внешняя durable queue). Периодический RDB без application reconciliation не считать достаточным контрактом доставки.
4. Не очищать backlog. Запускать consumer pools по одному: cache-warm → title-refresh → один import worker.
5. После каждого шага измерять heartbeat, reserved work, oldest age slope, failed delta, SQLite busy/locked rate, public cold/warm latency.
6. Добавлять workers только при отрицательном backlog trend и отсутствии lock storm. `4 import + 8 title-refresh + 1 cache-warm` — ceiling, а не обязательная цель.
7. Зависший active run восстанавливать только после implementation/rehearsal Task 6 transport reconciliation и проверки leases; не менять status/claims SQL вручную и не считать пустую Redis queue доказательством завершения.
8. Зафиксировать exact unresolved items, если systemd bus/process manager недоступны текущему оператору.

**Acceptance:**

- intended queues имеют свежие heartbeats;
- backlog/oldest age уменьшаются;
- ровно один global import profile;
- нет broad retry/flush и ручного удаления claims;
- SQLite/public latency не ухудшилась относительно recorded baseline.

**Rollback:** остановить только последний добавленный worker instance штатным manager action; producers/backlog/history не очищать.

## 6. Task 2 — ввести admission policy для import events

**Priority:** P0 storage/write amplification.

**Status:** `implementation_complete`, `delivery_unresolved`, `production_rollout_not_started`.

**Files:**

- Create: `app/Enums/SeasonvarImportEventPersistence.php`
- Create: `app/Services/Seasonvar/SeasonvarImportEventRecorder.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Modify: `app/Services/Seasonvar/SeasonvarSourceInventory.php`
- Modify: `app/Services/Seasonvar/SeasonvarTaxonomyPageImporter.php`
- Modify: `app/Services/Seasonvar/SeasonvarRssFreshnessImporter.php`
- Modify: `app/Services/Catalog/CatalogStatsPageBuilder.php` if the aggregate event needs an explicit administration label
- Modify: `config/seasonvar.php`
- Modify: `.env.example`
- Create: `tests/Unit/SeasonvarImportEventRecorderTest.php`
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`
- Modify: `tests/Feature/SeasonvarParsePageCommandTest.php`
- Modify: `docs/importer.md`
- Modify: `docs/operations/logging-and-health.md`
- Modify: `CHANGELOG.md`

**Contract:**

`SeasonvarImportEventRecorder::record()` становится единственной записью в `seasonvar_import_events`. Event получает одну из политик:

- `always`: run lifecycle, terminal status, failure, warning, blocked, recovery, checkpoint transition;
- `aggregate`: high-volume successful per-media/per-taxonomy/per-chunk события считаются в run summary/counters и сохраняются только bounded periodic aggregate;
- `sampled`: диагностические success события сохраняются с deterministic sampling по stable ID/hash;
- `transient`: CLI callback/log only, без durable row.

Raw URL sanitization выполняется до любой durable/log boundary. Event code остаётся стабильным; меняется только persistence cardinality.

**RED tests:**

1. `test_failures_and_terminal_lifecycle_events_are_always_persisted`.
2. `test_successful_media_item_events_are_aggregated_without_one_row_per_item`.
3. `test_sampling_is_deterministic_for_the_same_run_and_entity`.
4. `test_aggregate_flush_is_bounded_and_does_not_lose_run_counters`.
5. `test_url_values_and_messages_remain_redacted`.
6. `test_event_recorder_failure_never_aborts_catalog_import`.

**Implementation steps:**

1. Зафиксировать allowlist существующих event codes из `OutputsSeasonvarProgress` и current database distribution.
2. Создать enum policy и typed recorder; unknown event по умолчанию не должен молча становиться durable high-volume info. Unknown warning/error сохраняется, unknown info остаётся transient и фиксируется в test/log diagnostics.
3. Перевести `SeasonvarImportPipeline::recordImportEvent()`, `SeasonvarCatalogImporter::recordPageEvent()` и `SeasonvarSourceInventory::recordEvent()` на recorder.
4. Сохранить run counters `media_sizes_*`, `media_*`, `parsed`, `failed`; event sampling не меняет business counters.
5. Добавить bounded aggregate flush на chunk/run terminal boundary, а не timer в каждом item.
6. Admin/event pages должны показывать truthful sampled/aggregate nature; не выдавать выборку за полный per-item audit.

**Verification:**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=SeasonvarImportEventRecorderTest
php artisan test --filter=SeasonvarImportMaintenanceTest
php artisan test --filter=SeasonvarParsePageCommandTest
```

**Acceptance:**

- 500 file-size checks не создают 1 000+ durable info rows;
- failures/recovery/checkpoints остаются расследуемыми;
- run counters совпадают с фактическими результатами;
- public/admin не получают raw provider URL;
- event write rate имеет documented budget.

**Execution evidence — 24.07.2026:**

- создан единый scoped writer `SeasonvarImportEventRecorder`; прямые application writes удалены из pipeline/catalog/inventory/taxonomy/RSS services;
- default budget равен `100` aggregate events на durable summary и deterministic sample divisor `100`;
- неполная сводка flush-ится перед terminal lifecycle event и на queue apply boundary; job constructors, queue names и serialized payloads не изменены;
- 503 media success events создают шесть bounded aggregate rows, а не 503 per-item rows; exact run counter остаётся `503`;
- focused recorder, maintenance, parse, storage, queue-contract, finalizer и parallel-import tests зелёные; итоговый `--filter=Seasonvar` прошёл `256` тестов и `1553` утверждения;
- production rollout, удаление прежних rows и изменение runtime environment не выполнялись.

**Rollback:** config policy может вернуть `always` только временно и bounded; schema не меняется.

## 7. Task 3 — сделать retention независимым и time-budgeted

**Priority:** P0 storage.

**Status:** `implementation_complete`, `delivery_unresolved`, `production_activation_blocked_by_task_1`.

**Files:**

- Create: `app/DTOs/Seasonvar/SeasonvarImportStoragePreview.php`
- Create: `app/Jobs/PruneSeasonvarImportStorage.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportStorageMaintenance.php`
- Modify: `routes/console.php`
- Modify: `config/seasonvar.php`
- Modify: `.env.example`
- Modify: `tests/Unit/SeasonvarImportStorageMaintenanceTest.php`
- Modify: `tests/Unit/SeasonvarQueueJobContractTest.php`
- Create: `tests/Feature/SeasonvarImportStoragePruneJobTest.php`
- Modify: `docs/importer.md`
- Modify: `docs/queues.md`
- Modify: `docs/deployment.md`
- Modify: `docs/environment.md`
- Modify: `docs/architecture.md`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Design:**

- Retention остаётся тем же: events `7` days, snapshots `14` days с сохранением latest per source page, terminal prepared groups `7` days.
- Cleanup запускается независимо от успешной global run finalization.
- Scheduled job не является второй import command, не выполняет provider HTTP и не трогает nonterminal run/group/page.
- Один запуск ограничен `max_chunks`, `max_rows` и monotonic time budget.
- Preview сообщает только counts/bytes/age/run status без raw URL/body/payload.

**RED tests:**

1. `test_preview_counts_only_rows_outside_retention_and_terminal_runs`.
2. `test_job_deletes_no_more_than_configured_rows_and_time_budget`.
3. `test_job_preserves_latest_snapshot_even_when_it_is_old`.
4. `test_job_never_deletes_queued_running_or_finalizing_work`.
5. `test_job_is_unique_and_does_not_overlap_an_active_copy`.
6. `test_deleting_a_terminal_group_cascades_only_its_prepared_rows`.
7. `test_scheduler_does_not_dispatch_when_storage_maintenance_is_disabled`.

**Query-plan work:**

- events: перейти от повторного PK scan к bounded candidate selection, измерив `(created_at, id)` index;
- title groups: добавить `(status, finished_at, id)` или эквивалент только после `EXPLAIN QUERY PLAN`; current plan использует unrelated index и temp B-tree;
- snapshots: сохранить existing latest index и проверить candidate cutoff query.

Новый index не добавляется без before/after plan и production disk-space estimate.

**Verification:**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=SeasonvarImportStorageMaintenanceTest
php artisan test --filter=SeasonvarImportStoragePruneJobTest
php artisan schedule:list
```

**Production activation:**

1. verified backup/restore evidence;
2. zero unexpected writers for any index migration;
3. dry-run preview;
4. one small apply chunk;
5. database latency/size/WAL check;
6. gradual increase only if safe;
7. compaction is a separate copy-and-swap task, not part of row deletion.

**Rollback:** disable scheduled job; retained rows are not recoverable without backup, поэтому delete activation requires explicit evidence and exact selection.

**Execution evidence — 24.07.2026:**

- `SeasonvarImportStorageMaintenance` использует один общий бюджет `max_chunks`, `max_rows` и monotonic time для events, snapshots, groups и каскадных prepared pages; candidate selection ограничен до каждого delete batch;
- eligibility требует terminal parent run, а staging дополнительно terminal group; active work и latest snapshot сохраняются, строки без подтверждённого terminal run fail closed;
- typed `SeasonvarImportStoragePreview` возвращает только counts, estimated bytes, oldest timestamps и active status counts без URL/body/payload/error;
- создан empty-payload `PruneSeasonvarImportStorage` на existing `seasonvar-import` connection/queue с configured lock store, `ShouldBeUnique`, `WithoutOverlapping`, bounded retry/timeout/backoff и safe `failed()` context;
- daily schedule `04:17` зарегистрирован через `withoutOverlapping(10)`/`onOneServer()`, но `SEASONVAR_IMPORT_STORAGE_MAINTENANCE_SCHEDULED_ENABLED=false` блокирует dispatch по умолчанию;
- измеренный `EXPLAIN QUERY PLAN` оставил events и outer snapshot scans и group temp B-tree; index не добавлен из-за disk/backup/writer-pause gate;
- 16 новых/смежных retention и queue-contract тестов прошли 172 утверждения, существующий importer maintenance набор — 43 теста и 256 утверждений, wide Seasonvar — 268 тестов и 1642 утверждения, full suite — 1510 тестов / 1499 passed / 11 skipped / 123588 утверждений; Pint, targeted PHPStan/Rector и docs gates прошли;
- production rows, `.env`, Redis, scheduler/worker processes, provider HTTP, migrations, indexes и compaction не изменялись.

## 8. Task 4 — удалить сеть из title apply/finalizer

**Priority:** P0 correctness/performance.

**Files:**

- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogPagePreparer.php`
- Modify: `app/Services/Seasonvar/SeasonvarPreparedMediaResolver.php`
- Modify: `app/Actions/Media/InspectLicensedMediaFileSize.php`
- Modify: `app/Jobs/FinalizeSeasonvarImportTitleGroup.php`
- Modify: `tests/Feature/SeasonvarCatalogPreparedApplyTest.php`
- Modify: `tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php`
- Modify: `tests/Feature/SeasonvarCatalogPagePreparationTest.php`
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`
- Modify: `docs/importer.md`
- Modify: `docs/architecture.md`
- Modify: `docs/queues.md`
- Modify: `CHANGELOG.md`

**Target contract:**

- prepare phase may fetch source HTML, playlists and bounded availability metadata;
- `SeasonvarCatalogImporter::applyPreparedPage()` performs zero HTTP;
- new/changed direct media gets `file_size_check_status=pending` when metadata is absent/stale;
- file-size inspection runs only through the existing bounded file-size backlog lane;
- finalizer lock covers catalog writes, not provider latency.

**RED tests:**

1. Add prepared payload with direct MP4 media to `SeasonvarImportTitleGroupFinalizerTest`.
2. Call `Http::preventStrayRequests()` without `Http::fake()` for the media host.
3. Assert finalizer completes, media is published, availability from prepared payload is persisted and file size remains pending.
4. Assert no `seasonvar-media-size-check-started` event is emitted from apply.
5. Assert scheduled size-only path still performs `HEAD`, then bounded `Range` fallback.
6. Assert HLS remains unsupported as complete-file size and is never assembled/downloaded.

**Implementation steps:**

1. Replace ambiguous `allowNetwork` boolean in `syncParsedMedia()` with explicit prepared-apply policy or extracted synchronizer.
2. Remove `InspectLicensedMediaFileSize::execute()` from prepared catalog apply.
3. Keep `resetFileSizeInspection()` when effective URL changes.
4. Do not add file-size bytes to prepared payload unless prepare already obtained them from the same bounded request and integrity rules are defined; default is separate backlog.
5. Preserve media health availability already calculated by `SeasonvarPreparedMediaResolver`.
6. Update method PHPDoc from aspirational to enforced network-free contract.

**Verification:**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=SeasonvarCatalogPreparedApplyTest
php artisan test --filter=SeasonvarImportTitleGroupFinalizerTest
php artisan test --filter=SeasonvarCatalogPagePreparationTest
php artisan test --filter=SeasonvarImportMaintenanceTest
```

**Acceptance:**

- no HTTP client invocation reachable from `applyPreparedPage`;
- title finalizer runtime no longer scales with media metadata network timeout;
- file-size backlog and counters remain truthful;
- direct/HLS playback contracts remain unchanged.

**Rollback:** code-only revert; pending size rows remain valid and will be processed by the existing backlog.

**Implementation evidence — 24.07.2026:**

- RED с prepared direct MP4 и `Http::preventStrayRequests()` дошёл до всех state assertions и ожидаемо упал только на прежнем `seasonvar-media-size-check-started`.
- Из `SeasonvarCatalogImporter` удалены `allowNetwork`, зависимости `SeasonvarMediaAvailabilityChecker`/`InspectLicensedMediaFileSize`, fallback availability HTTP и size action; `resetFileSizeInspection()` сохранён.
- Finalizer integration сохраняет prepared availability, published media и `file_size_check_status=pending` без HTTP. Existing size-only command подтверждает `HEAD` → `Range: bytes=0-0`; HLS inspector возвращает `unsupported` без HTTP.
- Focused suites: prepared `5/27`, finalizer `16/97`, preparation `3/25`, size `2/13`, maintenance `43/256`. Широкий `--filter=Seasonvar`: `270/1659`. Полный snapshot: `1547` тестов, `1536` успешных, `11` skipped, `123832` assertions. Направленные `Pint`, `PHPStan` для service/prepared/size boundary и `Rector --dry-run` прошли.
- `SeasonvarCatalogPagePreparer`, `SeasonvarPreparedMediaResolver`, size action и job orchestration не потребовали изменения; queue names/payloads/retry/checkpoints, routes, schema, translations, cache identities, permissions и dependencies сохранены.

## 9. Task 5 — компактное versioned staging storage

**Priority:** P1 storage/write amplification; activation only after Task 3 and backup gate.

**Files:**

- Create: `database/migrations/2026_07_24_130000_add_compact_payload_storage_to_seasonvar_import_tables.php`
- Create: `app/Services/Seasonvar/SeasonvarImportPayloadCodec.php`
- Modify: `app/Models/SeasonvarImportPreparedPage.php`
- Modify: `app/Models/SourcePageSnapshot.php`
- Modify: `app/DTOs/Seasonvar/SeasonvarPreparedCatalogPage.php`
- Modify: `app/Services/Seasonvar/SeasonvarSourcePageFetcher.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportStorageMaintenance.php`
- Create: `tests/Unit/SeasonvarImportPayloadCodecTest.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`
- Modify: `tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php`
- Modify: `tests/Unit/SeasonvarImportStorageMaintenanceTest.php`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/importer.md`
- Modify: `docs/deployment.md`
- Modify: `CHANGELOG.md`

**Additive schema proposal:**

- prepared pages: nullable `payload_blob`, `payload_codec`, `payload_uncompressed_bytes`, `application_result`;
- snapshots: nullable `html_blob`, `html_codec`, `html_uncompressed_bytes`;
- legacy `payload` and `html` columns остаются для rolling dual-read;
- no destructive rewrite and no immediate backfill of rows already eligible for retention.

**Codec contract:**

- versioned values such as `gzip-v1`, never implicit “compressed” boolean;
- strict maximum decompressed bytes derived from current parser/HTTP payload limits;
- checksum/content hash validation before DTO construction;
- corruption returns sanitized stable failure, never partial decoded data;
- new writes use compact column, reads prefer compact and fall back to legacy;
- `_application_result` moves to the small dedicated column so `markApplied()` does not rewrite megabyte payload.

**RED tests:**

1. round-trip Cyrillic/Unicode/URLs without value drift;
2. reject corrupt/truncated/zip-bomb-shaped data before allocation exceeds cap;
3. read legacy JSON/text row;
4. read new compact row;
5. rolling state with both columns prefers current codec;
6. `markApplied()` changes only status/result/timestamps, not payload bytes;
7. retry/finalizer accepts payload created by previous deployed code;
8. retention cascades both representations;
9. 2.6 MB representative payload stays within worker memory budget.

**Rollout:**

1. migration in paused-writer window;
2. deploy dual-reader before compact-only writer;
3. restart workers gracefully;
4. canary targeted title group;
5. enable new writes;
6. let old terminal rows expire naturally;
7. do not null legacy columns for nonterminal rows;
8. removal of legacy columns, if ever justified, is a separate release after zero legacy-reader evidence.

**Acceptance:**

- new prepared/snapshot rows materially reduce stored bytes under representative corpus;
- decoder CPU/memory stays inside measured budget;
- rolling jobs survive deployment;
- applied checkpoint no longer rewrites the full payload;
- exact raw provider content remains private.

**Rollback:** disable compact writer, continue dual-read; additive columns remain. Do not down-migrate while jobs may contain compact rows.

## 10. Task 6 — durable transport ledger, batch-oriented dispatch и active-run reconciliation

**Priority:** P0 availability/recovery, затем throughput.

**Files:**

- Create: `database/migrations/2026_07_25_130000_add_batch_dispatch_progress_to_seasonvar_import.php`
- Create: `app/DTOs/Seasonvar/SeasonvarImportDispatchBatch.php`
- Create: `app/Services/Seasonvar/SeasonvarImportDispatchBatcher.php`
- Create: `app/Services/Seasonvar/SeasonvarActiveRunReconciler.php`
- Create: `app/Jobs/ReconcileSeasonvarQueuedImportRun.php`
- Modify: `app/Models/SeasonvarImportRun.php`
- Modify: `app/Models/SeasonvarImportPreparedPage.php`
- Modify: `app/Services/Seasonvar/SeasonvarQueuedImportDispatcher.php`
- Modify: `app/Services/Seasonvar/SeasonvarRefreshPlanner.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportTitleGroupDispatcher.php`
- Modify: `app/Services/Seasonvar/SeasonvarPageClaimManager.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportRunRecorder.php`
- Modify: `app/Jobs/StartSeasonvarQueuedImport.php`
- Modify: `app/Jobs/FinalizeSeasonvarQueuedImport.php`
- Modify: `app/Jobs/PrepareSeasonvarImportTitlePage.php`
- Modify: `routes/console.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`
- Modify: `tests/Feature/SeasonvarImportTitleGroupDispatcherTest.php`
- Modify: `tests/Unit/SeasonvarQueueJobContractTest.php`
- Create: `tests/Feature/SeasonvarImportDispatchBatcherTest.php`
- Create: `tests/Feature/SeasonvarActiveRunReconciliationTest.php`
- Modify: `docs/importer.md`
- Modify: `docs/queues.md`
- Modify: `docs/performance.md`
- Modify: `CHANGELOG.md`

**Current cost to remove:**

Per selected serial page выполняются отдельные claim, active-run query, prepared existence query, title resolution, `firstOrCreate` group, transaction, source insert/select, prepared insert, two counter updates, prepared select and Redis dispatch. На `41 043` страницах это даёт сотни тысяч SQLite operations.

**Target contract:**

- process pages in bounded chunks;
- resolve existing titles/group hashes with grouped queries;
- bulk insert missing groups and prepared rows;
- aggregate `expected_pages`/`selected` increments per group/run;
- preserve ID-only `PrepareSeasonvarImportTitlePage` payload;
- treat exact unique `(seasonvar_import_run_id, source_page_id)` prepared rows as
  the resume authority and exclude them through an indexed anti-join in every
  planner phase;
- do not use one global `dispatch_cursor_id` as correctness authority: the
  current planner has overlapping reason queries and metadata phases, so one
  high-water ID could skip work from a different phase;
- save selected count, batch evidence, `last_progress_at` and
  `dispatch_completed` under fresh row lock;
- after crash, resume only unregistered portion and idempotently redispatch incomplete staging rows;
- treat each nonterminal staging row as the durable work ledger: store bounded enqueue attempt metadata, periodically redispatch due `queued` rows and require a database compare-and-swap before processing;
- recover an active `running` + `dispatch_completed=false` run after coordinator loss; do not wait for terminalization or lease expiry when durable progress is stale;
- make CLI and administration entry paths invoke the same resumable coordinator contract; a 900-second `StartSeasonvarQueuedImport` attempt must never own an hours-long serial registration loop;
- separate `last_progress_at` from observer/health-check timestamps; polling, watchdog dispatch and finalizer wake-up must not manufacture progress;
- Redis uniqueness/overlap locks remain an optimization only: restart or RDB rollback may lose them, while database state remains the idempotency authority;
- never hold one SQLite transaction while pushing thousands of Redis jobs.

**RED tests:**

1. `test_dispatch_batch_registers_one_hundred_pages_without_per_page_group_queries`.
2. `test_dispatch_crash_after_database_commit_redispatches_missing_jobs_without_duplicate_rows`.
3. `test_dispatch_crash_before_batch_commit_reselects_from_the_unique_run_page_ledger`.
4. `test_existing_prepared_rows_do_not_increment_expected_or_selected_twice`.
5. `test_cancelled_run_stops_before_next_batch`.
6. `test_targeted_title_refresh_and_global_dispatch_preserve_group_identity`.
7. `test_job_constructor_and_queue_name_remain_rolling_compatible`.
8. query-count assertion uses a representative fixed fixture and records an approved budget.
9. `test_redis_transport_loss_redispatches_queued_staging_without_duplicate_apply`.
10. `test_incomplete_dispatch_resumes_from_durable_cursor_after_coordinator_timeout`.
11. `test_watchdog_poll_does_not_advance_last_progress_without_state_transition`.
12. `test_duplicate_prepare_delivery_is_rejected_by_database_compare_and_swap`.

**Claim strategy:**

Phase 6A keeps the current lease semantics and introduces `claimMany()` only if it can atomically prove ownership:

- each page remains scoped by `(source_page_id, run_id, token)`;
- a shared cryptographically random token per bounded batch is acceptable only because page ID remains part of every ownership/release predicate;
- pages not actually acquired are excluded from staging/dispatch;
- no claim duration is increased.

Moving claims fully to worker start is deferred until stale-run logic also treats queued staging rows as recoverable work; it must not be slipped into the batching change.

**Verification:**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=SeasonvarImportDispatchBatcherTest
php artisan test --filter=SeasonvarImportTitleGroupDispatcherTest
php artisan test --filter=SeasonvarParallelImportTest
php artisan test --filter=SeasonvarQueueJobContractTest
```

**Acceptance:**

- dispatch has explicit stage timing/query budgets;
- 100-page registration removes every per-page title/group/prepared/counter
  query shape and is at least one order of magnitude below the measured
  1 239-query baseline;
- crash at every batch boundary is resumable;
- loss of all Redis queue and lock keys while SQLite survives is recoverable from the durable ledger;
- an active incomplete run either resumes or reaches a truthful terminal failure; it cannot remain fresh forever solely because a watchdog wakes it;
- rows/counters/jobs remain idempotent;
- measured full inventory dispatch is inside an approved budget and materially faster than the `2:43:36` baseline;
- no public command, queue name or job payload change.

**Rollback:** disable batcher via temporary rollout flag and use legacy dispatcher only while both schemas/contracts remain compatible.

## 11. Task 7 — SQLite-aware writer admission

**Priority:** P1 availability.

**Files:**

- Create: `app/Services/Seasonvar/SeasonvarCatalogWriteAdmission.php`
- Modify: `app/Jobs/FinalizeSeasonvarImportTitleGroup.php`
- Modify: `app/Services/Seasonvar/SeasonvarDatabaseTransaction.php`
- Modify: `config/seasonvar.php`
- Modify: `.env.example`
- Create: `tests/Feature/SeasonvarCatalogWriteAdmissionTest.php`
- Modify: `tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php`
- Modify: `tests/Unit/SeasonvarDatabaseTransactionTest.php`
- Modify: `docs/queues.md`
- Modify: `docs/deployment.md`
- Modify: `docs/performance.md`
- Modify: `CHANGELOG.md`

**Design:**

- HTTP prepare remains parallel.
- SQLite catalog apply gets a short global writer admission lock in the configured critical Redis store.
- Lock is acquired before group apply and released in `finally`; no network work occurs under it after Task 4.
- Lock TTL remains `worker timeout + bounded grace`.
- Lock contention uses delayed release within existing `retryUntil()`; `$tries=0` remains.
- Group-key lock and writer lock have one documented acquisition order to prevent deadlocks.
- Non-SQLite engines keep the abstraction but may use no-op/engine-specific policy only after measurement.

**RED tests:**

1. two different groups cannot enter SQLite catalog write simultaneously;
2. second finalizer releases with bounded delay and does not mark group failed;
3. lock owner crash expires safely;
4. network prepare does not acquire writer lock;
5. transaction retry still handles `database is locked`;
6. lock acquisition order is identical for targeted/global paths;
7. disabled rollout flag restores old behavior without schema change.

**Benchmark gate:**

Compare at least:

- no admission lock;
- one catalog writer;
- two controlled writers only if implementation supports a real semaphore.

Record throughput, p50/p95 group completion, SQLite busy errors, public route latency and worker memory. Keep the lowest-concurrency stable option; do not assume more writers improve throughput.

**Acceptance:** no lock storm, bounded queue progress, no public latency regression, no increased timeouts.

**Rollback:** config disables writer admission; no data/schema rollback.

## 12. Task 8 — профилировать и сократить TD-013 merge amplification

**Priority:** P1 tail latency/data correctness.

**Files:**

- Create: `tests/Performance/SeasonvarTitleMergeProfileTest.php`
- Modify: `tests/Feature/SeasonvarTitleMergeTest.php`
- Modify: `tests/Feature/SeasonvarReleaseObservationSynchronizerTest.php`
- Modify: `app/Services/Seasonvar/SeasonvarTitleMerger.php`
- Modify only after evidence: title-move services in `app/Services/Catalog`, comments, content requests, technical issues, calendar, user data, collections and tags
- Modify: `docs/maintenance/technical-debt.md`
- Modify: `docs/importer.md`
- Modify: `docs/performance.md`
- Modify: `CHANGELOG.md`

**Representative fixture:**

- 30 sibling pages;
- 29 duplicate seasons;
- at least 930 episodes;
- at least 957 licensed media;
- comments, progress, reviews, technical issues, content requests, calendar observations, slugs, aliases, ratings, collections, tags and recommendations attached across canonical/duplicate titles.

**Profile stages:**

1. duplicate discovery;
2. target season lookup;
3. episode identity lookup;
4. media matching/move;
5. dependent-domain moves;
6. relation sync;
7. final season/title delete;
8. after-commit sync/cache/search work.

**Likely optimizations, only after query evidence:**

- prefetch target episodes keyed by `(kind, number)` instead of one query per episode;
- prefetch canonical media by `source_media_key` and fallback playback hash in bounded chunks;
- group direct foreign-key updates where no merge conflict exists;
- reserve per-entity service calls for actual identity conflicts;
- bulk upsert aliases/ratings and delete exact source IDs;
- avoid repeated reload of the same catalog relations;
- update release/calendar observations in bounded grouped operations;
- preserve final season + duplicate title deletion atomicity.

**RED/correctness tests:**

- crash after each season checkpoint resumes without double move;
- duplicate and canonical media conflicts preserve known file size/health fields;
- comments/user progress/content requests/technical issues keep valid target IDs;
- old slugs redirect;
- source relations and editorial values are preserved;
- second merge is an idempotent no-op;
- query count and largest transaction duration stay within recorded budget.

**Acceptance:** largest approved fixture completes or advances a durable checkpoint under `900` seconds without writer lock storm; no domain row loss; TD-013 evidence updated.

**Rollback:** revert one measured optimization at a time; checkpoints and schema remain compatible.

## 13. Task 9 — вынести media apply в bulk-aware synchronizer

**Priority:** P1 query/write amplification.

**Files:**

- Create: `app/DTOs/Seasonvar/SeasonvarMediaSyncResult.php`
- Create: `app/Services/Seasonvar/SeasonvarCatalogMediaSynchronizer.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogRelationSyncer.php`
- Modify: `tests/Feature/SeasonvarCatalogPreparedApplyTest.php`
- Modify: `tests/Feature/SeasonvarParsePageCommandTest.php`
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`
- Create: `tests/Unit/SeasonvarCatalogMediaSynchronizerTest.php`
- Modify: `docs/importer.md`
- Modify: `docs/architecture.md`
- Modify: `CHANGELOG.md`

**Current issues:**

- up to two media identity queries per candidate;
- one model save per candidate;
- per-item info event;
- translation relation rebuild reads all title media after every page;
- file-size request was mixed into apply until Task 4.

**Target:**

1. Normalize and validate all candidates before writes.
2. Load seasons/episodes and existing media in bounded grouped queries.
3. Resolve identity by stable `source_media_key`, then controlled playback fallback.
4. Partition into `insert`, `update`, `unchanged`, `invalid`.
5. Use bulk writes where model events are not a protected contract; otherwise use bounded model writes with an explicit reason.
6. Preserve soft-deleted rows, health state, availability windows, known file sizes and editorial visibility.
7. Sync translation taxonomy once per title-group terminal apply, not once per page.
8. Return typed counters; event recorder handles aggregate diagnostics.

**Tests:**

- 1 000 candidates with duplicates and translations;
- unchanged forced refresh performs zero media updates;
- changed effective URL resets only file-size metadata;
- health-disabled media remains disabled;
- trailers without episode remain supported;
- HLS variants remain distinct;
- two pages of one group do not duplicate media or translations;
- query/write budget is fixed by fixture.

**Acceptance:** equivalent rows/counters with substantially fewer queries/writes and lower memory than current per-item path.

## 14. Task 10 — индексируемая file-size projection и быстрый status

**Priority:** P1 operational latency/background load.

**Files:**

- Create: `database/migrations/2026_07_24_131000_add_file_size_schedule_projection_to_licensed_media.php`
- Create: `app/Services/Media/LicensedMediaFileSizeScheduleProjection.php`
- Modify: `app/Services/Media/LicensedMediaFileSizeMetadataWriter.php`
- Modify: `app/Services/Media/LicensedMediaFileSizeBacklog.php`
- Modify: `app/Actions/Media/InspectLicensedMediaFileSize.php`
- Modify: `app/Models/LicensedMedia.php`
- Modify: `app/Services/Seasonvar/SeasonvarQueueStatus.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportAdminService.php`
- Create: `tests/Feature/LicensedMediaFileSizeBacklogTest.php`
- Modify: `tests/Feature/SeasonvarQueueStatusTest.php`
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/importer.md`
- Modify: `docs/administration.md`
- Modify: `docs/performance.md`
- Modify: `docs/deployment.md`
- Modify: `CHANGELOG.md`

**Proposed projection:**

- `file_size_eligible` — derived, server-owned boolean;
- `file_size_next_check_at` — next due instant derived from status/checked time/config at write;
- composite index `(file_size_eligible, file_size_next_check_at, id)`.

The exact schema is accepted only after before/after `EXPLAIN QUERY PLAN`, backfill cost and rollback review. If a smaller covering index solves both queries without derived columns, prefer it.

**Status strategy:**

- `--status` and `/admin/imports` read a last captured aggregate immediately;
- aggregate refresh occurs in the bounded background lane;
- UI shows `captured_at` and stale status honestly;
- cold cache does not synchronously scan 880k rows during admin render/CLI status;
- periodic reconciliation compares projection/aggregate with authoritative rows and records drift without auto-deleting data.

**RED tests:**

1. metadata status transition calculates the correct next due instant;
2. URL/format change recalculates eligibility;
3. due query orders by due time/id and uses approved index in SQLite plan assertion;
4. status returns stored snapshot without invoking full aggregate builder;
5. stale snapshot is labelled stale, not fresh;
6. reconciliation detects and repairs only derived projection fields;
7. HLS remains ineligible for complete-file size;
8. rollback reader works before backfill completion.

**Production rollout:**

- pause writers/workers;
- verified backup and free-space check;
- additive migration;
- chunked derived-field backfill with time budget;
- plan/count reconciliation;
- graceful worker restart;
- canary status and one bounded scheduled batch.

**Acceptance:** status latency stays inside an approved low-latency budget; due query is index-driven; scheduled batch no longer full-scans `licensed_media`.

**Rollback:** disable projection reader and return to current query; additive fields remain until a later cleanup release.

## 15. Task 11 — parser fixture corpus, invariants and semantic fingerprints

**Priority:** P1 correctness/maintainability.

**Files:**

- Create: `tests/Fixtures/seasonvar/README.md`
- Create: sanitized/minimal fixtures under `tests/Fixtures/seasonvar/*.html` and `*.json`
- Modify: `tests/Unit/SeasonvarCatalogParserTest.php`
- Modify: `tests/Feature/SeasonvarCatalogPagePreparationTest.php`
- Create: `tests/Unit/SeasonvarPreparedPayloadFingerprintTest.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogParser.php`
- Modify: `app/DTOs/Seasonvar/SeasonvarCatalogData.php`
- Modify: `app/DTOs/Seasonvar/SeasonvarPreparedCatalogPage.php`
- Modify: `docs/importer.md`
- Modify: `CHANGELOG.md`

**Corpus categories:**

- complete serial page;
- season family with 1/30+ seasons;
- missing info list;
- missing season list;
- malformed/balanced JavaScript payload;
- nested playlist folders;
- HLS master/media playlists;
- volatile playlist query;
- trailer without episode;
- region/rights blocked page;
- duplicate taxonomies/aliases/ratings;
- Cyrillic/Latin titles and Unicode punctuation;
- oversized but bounded 2 600-episode fixture;
- malformed URLs and partial HTML.

Fixtures must be minimal/sanitized and must not contain credentials, tokens, private paths or full copyrighted pages.

**Invariants:**

- deterministic DTO/fingerprint for semantically equivalent markup;
- stable ordering/deduplication;
- one title family;
- strict positive season/episode numbers;
- partial section never claims complete;
- invalid title/payload fails before writes;
- parser version bump occurs only when stored interpretation changes and has backfill/compatibility plan.

**Tests:**

- golden normalized DTOs;
- permutation tests for whitespace/attribute/order noise;
- bounded malformed input corpus;
- payload round-trip through codec;
- no provider HTTP in unit tests;
- memory regression for 2 600 episodes.

**Acceptance:** parser behavior is reviewable from fixtures, not giant inline strings alone; semantic noise does not trigger unnecessary catalog writes.

## 16. Task 12 — section-level provenance и safe convergence

**Priority:** P2 data quality.

**Files:**

- Create: `app/DTOs/Seasonvar/SeasonvarSectionPresence.php`
- Modify: `app/DTOs/Seasonvar/SeasonvarCatalogData.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogParser.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogRelationSyncer.php`
- Modify: `app/Services/Seasonvar/SeasonvarTitleManifestBuilder.php`
- Modify: `app/Models/SourcePage.php`
- Add additive migration only if current `metadata_presence` cannot represent the required state
- Modify: `tests/Feature/SeasonvarCatalogPreparedApplyTest.php`
- Modify: `tests/Feature/SeasonvarCatalogMetadataBackfillTest.php`
- Modify: `tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php`
- Modify: `docs/CODE_STANDARDS.md` only if a new permanent convergence rule is approved
- Modify: `docs/importer.md`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `CHANGELOG.md`

**States per section:**

- `complete`: provider section was present and parsed without truncation;
- `partial`: some observations are usable, absence is not authoritative;
- `absent`: provider explicitly supplied an empty complete section;
- `unknown`: source structure did not prove completeness;
- `invalid`: section rejected and must not mutate authoritative local data.

**Rules:**

- `partial|unknown|invalid` are additive-only;
- removal/sync-detach is allowed only for an explicitly approved `complete|absent` section and after local/editorial ownership review;
- media remains non-destructive by default;
- source observations and local editorial state remain distinguishable;
- manifest reports provider/local differences without auto-deleting unobserved rows.

**Tests:** complete→partial→complete sequences for taxonomies, aliases, ratings, recommendations, seasons, episodes and media; interrupted sibling group; outdated parser payload; local editorial values.

**Acceptance:** repeated partial provider pages cannot silently erase verified catalog data; complete snapshots converge deterministically.

## 17. Task 13 — bounded decomposition без behavior rewrite

**Priority:** P2 maintenance; execute one extraction per commit after Tasks 4–12 stabilize contracts.

### 13A. Catalog write boundary

- Create: `app/Services/Seasonvar/SeasonvarCatalogTitleWriter.php`
- Move only title/alias/rating/review/season/episode writes from `SeasonvarCatalogImporter`.
- Preserve public methods `discover`, `pagesForArgument`, `parsePages`, `parsePage`, `applyPreparedPage`.
- Add equivalence tests comparing database state and emitted typed result.

### 13B. Media boundary

- Complete `SeasonvarCatalogMediaSynchronizer` extraction from Task 9.
- Remove media-specific dependencies from `SeasonvarCatalogImporter`.

### 13C. Maintenance stages

- Create: `app/Services/Seasonvar/SeasonvarImportMaintenancePipeline.php`.
- Move storage, availability, metadata, media, relation, merge and recommendation stage orchestration from `SeasonvarImportPipeline`.
- Keep versioned queued finalization checkpoint shape compatible.

### 13D. Parser collaborators

- Create only cohesive existing domains:
  - `SeasonvarStructuredDataParser`
  - `SeasonvarEpisodeScriptParser`
  - `SeasonvarMediaCandidateParser`
  - `SeasonvarTaxonomyParser`
- `SeasonvarCatalogParser` remains the facade returning the same normalized DTO.
- No generic repository/action layer and no speculative interfaces.

### 13E. Command presentation

- Extract status/inventory/progress formatting from `ImportSeasonvar` and `OutputsSeasonvarProgress` into typed presenter classes.
- Command signature/options and Russian operator output remain compatible.

**Verification per extraction:**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=SeasonvarCatalogParserTest
php artisan test --filter=SeasonvarCatalogPreparedApplyTest
php artisan test --filter=SeasonvarParsePageCommandTest
php artisan test --filter=SeasonvarImportMaintenanceTest
php artisan test --filter=SeasonvarParallelImportTest
```

**Acceptance:** smaller dependency surfaces, no changed rows/events/routes/commands from equivalence fixture, no new architecture pattern outside existing services/DTOs/jobs.

## 18. Task 14 — truthful fast operational status и recovery UX

**Priority:** P0 operational correctness, затем P1 UX/query cost.

**Files:**

- Modify: `app/DTOs/Seasonvar/SeasonvarQueueStatusData.php`
- Modify: `app/Services/Seasonvar/SeasonvarQueueStatus.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportAdminService.php`
- Modify: `app/Livewire/SeasonvarImportManager.php`
- Modify: `resources/views/livewire/seasonvar-import-manager.blade.php`
- Modify: `app/Console/Commands/ImportSeasonvar.php`
- Modify: `tests/Feature/SeasonvarQueueStatusTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `docs/administration.md`
- Modify: `docs/operations/logging-and-health.md`
- Modify: `CHANGELOG.md`

**Status fields:**

- canonical run phase: dispatching, preparing, applying, merging, finalizing, terminal;
- `dispatch_completed`, dispatch cursor, durable `last_progress_at` and separate observer timestamp;
- expected/prepared/applied/failed group/page counts;
- pending/delayed/reserved/oldest age per exact queue;
- worker heartbeat and stale reason;
- live claims with earliest/latest expiry, without raw source URL;
- storage retention preview age/count/bytes;
- file-size snapshot with `captured_at`/stale marker;
- last terminal reason code.

**Rules:**

- no network/provider request during render/status;
- no cold full-catalog scan;
- a fresh heartbeat without a durable counter/cursor/status transition must not classify a run as healthy;
- `queue=0` with nonterminal durable staging must be reported as transport loss/reconciliation required, not as successful drain;
- no ETA until sufficient measured stage history exists; otherwise “недостаточно данных”;
- admin recovery actions remain protected by `imports.execute`;
- no manual UI claim deletion or broad job retry.

**Tests:** zero-worker backlog, active dispatch, expired claims, prepared/finalizing group, stale aggregate, authorization, query budget.

**Acceptance:** CLI/admin answer quickly and distinguish producer, consumer, lease and finalizer failures truthfully.

## 19. Task 15 — security/privacy hardening of importer storage and diagnostics

**Priority:** P1 continuous.

**Files:**

- Modify: `app/Services/Seasonvar/SeasonvarUrl.php`
- Modify: `app/Services/Seasonvar/SeasonvarDiscovery.php`
- Modify: `app/Services/Seasonvar/SeasonvarSitemapMirror.php`
- Modify: `app/Services/Crawler/PoliteHttpClient.php`
- Modify: `app/Services/Media/ExternalMediaUrlGuard.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportErrorSanitizer.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportEventRecorder.php`
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`
- Modify: `tests/Feature/SeasonvarCatalogPagePreparationTest.php`
- Modify: `tests/Feature/SeasonvarSitemapMirrorTest.php`
- Modify: `tests/Feature/ExternalPlaylistImportTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `docs/security.md`
- Modify: `docs/importer.md`
- Modify: `CHANGELOG.md`

**Checks:**

- every redirect hop is disabled or revalidated as required by the existing client boundary;
- public-DNS pin/revalidation remains enforced for external media metadata;
- no provider URL/query/token/body in logs, events, admin, failed job exception text or plan evidence;
- sitemap and payload decompression have a hard output limit before unbounded allocation/write;
- parser nesting/collection sizes are bounded;
- job IDs/run IDs/group hashes are safe stable context;
- error category is allowlisted and operator text stays sanitized;
- API never exposes importer payload, snapshots, source HTML or raw media URL.

**Tests:** DNS rebinding/redirect, private/reserved IP, URL inside exception message, corrupt/oversized compressed sitemap and payload, oversized playlist nesting, admin/API serialization.

**Acceptance:** existing security contract is demonstrably enforced after every refactor; no fake integration or client-trusted access state.

## 20. Task 16 — failure injection, concurrency and load matrix

**Priority:** P1 release gate.

**Files:**

- Create: `tests/Feature/SeasonvarImporterFailureInjectionTest.php`
- Create: `tests/Performance/SeasonvarImporterLoadProfileTest.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`
- Modify: `tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php`
- Modify: `tests/Feature/SeasonvarTitleMergeTest.php`
- Modify: `tests/Unit/SeasonvarQueueJobContractTest.php`
- Modify: `docs/testing.md`
- Modify: `docs/importer.md`
- Modify: `docs/deployment.md`
- Modify: `CHANGELOG.md`

**Crash windows:**

1. after source HTTP, before snapshot;
2. after snapshot, before prepared row;
3. after prepared commit, before Redis dispatch;
4. after page apply, before application checkpoint;
5. after checkpoint, before group status;
6. during final season merge;
7. after catalog commit, before sync/cache invalidation;
8. after all groups terminal, before global finalization checkpoint;
9. worker shutdown while holding Redis lock;
10. queue unavailable while database is healthy;
11. Redis restarts from an older RDB snapshot while SQLite keeps an active incomplete run, claims and staging rows;
12. all Redis queue and uniqueness-lock keys disappear after successful staging commit;
13. Redis lock unavailable while queued transport is healthy;
14. SQLite busy/locked during each write stage.

**Load profiles:**

- 48k source pages dispatch;
- 30-page title family;
- 2 600 episodes;
- 1 000 media candidates;
- 880k file-size projection/backlog;
- concurrent public home/catalog/title reads;
- one, two and four import workers with SQLite admission.

External HTTP remains faked and stray requests prevented. Production provider is never part of CI.

**Budgets to record, not invent:**

- stage wall time;
- query count and slowest query;
- transaction duration/retries;
- peak memory;
- payload bytes/compression time;
- queue oldest age slope;
- SQLite busy count;
- public p50/p95/cold/warm latency;
- exact rows/counters before/after retry.

**Acceptance:** every crash window either rolls back or resumes from a durable checkpoint without duplicate canonical identity or data loss.

## 21. Task 17 — optional source parity expansion, permanently gated

**Priority:** P3, not part of core stabilization.

**Current state:** inventory confirms serial/RSS; actor/genre/country/tag are disabled or publication-unauthorized by default.

**Before any handler activation:**

1. rerun `--inventory-only` and update `docs/SOURCE_PARITY.md`;
2. confirm business/legal/publication authorization for the exact page type;
3. define identity, parser, importer, local public route or explicit metadata-only outcome;
4. add sanitized fixtures and idempotency tests;
5. ensure linked serial URL fan-out is bounded;
6. keep disabled-by-default rollout flags;
7. validate search/SEO/sitemap/translations/cache/admin/privacy;
8. activate one page type per change set;
9. no fake public pages or marketing placeholder content.

**Not allowed:** automatically enabling all discovered types, deriving public authorization from parser capability, adding separate import commands.

## 22. Task 18 — rollout sequence and completion

### 18.1 Commit sequence

1. operations evidence/worker ownership;
2. event admission;
3. independent retention;
4. network-free finalizer;
5. compact staging;
6. batch dispatch;
7. SQLite writer admission;
8. merge optimization;
9. media synchronizer;
10. file-size projection/status;
11. parser corpus;
12. provenance;
13. one bounded refactor per commit;
14. admin/status;
15. security verification;
16. load/failure evidence;
17. optional parity only after separate approval;
18. final acceptance/docs.

### 18.2 Required verification ladder

For each PHP change:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=<FocusedTestClass>
```

Before importer phase completion:

```bash
php artisan test --filter=Seasonvar
php artisan test
./vendor/bin/phpunit
bash scripts/ci-check.sh docs
```

Run `npm run build` only when Blade/Tailwind/JS/assets are changed. Browser QA is required when `/admin/imports` markup or behavior changes.

### 18.3 Production canary

1. re-read requirements and this plan;
2. `git status --short --branch`, exact staged scope, `main`;
3. verify backup/restore and disk space for migration/data tasks;
4. stop or drain exact writers for SQLite DDL;
5. apply additive migration;
6. rebuild required caches without broad data-cache clear;
7. graceful restart workers;
8. targeted one-title import;
9. bounded sitemap tail;
10. one controlled full queued run;
11. compare counts/manifests/search/calendar/cache/API sync;
12. observe queues/SQLite/public latency for the full retry window;
13. preserve failed/backlog evidence.

### 18.4 Rollback principles

- Code-only phases revert code/config flag and restart workers gracefully.
- Schema phases keep additive columns; do not down-migrate while old jobs/readers may need them.
- Data deletion requires restore-capable backup; row deletion and SQLite compaction are separate operations.
- Queue payload changes require drain/versioned compatibility; this plan avoids them by default.
- No rollback uses queue/cache flush or force push.

## 23. Cross-feature impact matrix

| Domain | Impact | Required evidence |
| --- | --- | --- |
| Authentication/authorization | Admin recovery/status only | `imports.execute`, policy/gate tests |
| Catalog identity | Direct | one title per family, slug/redirect/merge equivalence |
| Search | After title apply/merge | scoped index sync and no stale documents |
| Recommendations | Terminal finalization | dirty IDs, checkpoint/deferred full rebuild |
| Calendar | Episode/media/merge | observation move/idempotency tests |
| Cache | After-commit | targeted invalidation, warm deferral under import |
| API sync | Title/merge | one safe upsert after committed state |
| Player/media | Media identity/health/size | external URL never exposed; HLS/direct behavior unchanged |
| Premium/region/legal | Availability | no client-trusted state; provider block preserved |
| SEO/sitemap | Catalog identity | canonical URL and streamed outputs unchanged |
| Notifications/content requests | Run/title links | exact IDs survive merge/retry |
| Administration/audit | Status/events | fast safe aggregates, no raw payload/URL |
| Privacy | Snapshots/events/logs | retention, sanitization, no secrets |
| Mobile/API | Indirect catalog data | no route/resource shape change |
| Deployment/backups | Direct | writer pause, additive migration, rollback |

## 24. Expected files for the planning task

- `docs/superpowers/plans/2026-07-24-seasonvar-importer-improvement-master-plan.md`
- `docs/plans/current-task-plan.md`
- `docs/maintenance/technical-debt.md`
- `CHANGELOG.md`

`README.md` is reviewed but not changed because this planning-only task does not alter visitor/product/development/deployment behavior.

Application code, migrations, routes, configuration, dependencies, lock files, assets, environment and production state are unchanged by this planning task.

## 25. Protected compatibility contracts

- `php artisan seasonvar:import` signature and its role as the only public import command.
- `CatalogTitle` slug binding and one-title season family.
- SourcePage identity/hash/status semantics.
- Current queue names, ID-only job payloads, retry windows and durable checkpoints.
- Additive/partial provider convergence and editorial ownership.
- Search/recommendation/calendar/cache/API-sync terminal handoffs.
- External media-only storage, no full video download, no HLS assembly.
- Player access, premium, region/rightsholder, user progress and public API boundaries.
- RU operator output and multilingual public/admin catalog contracts.

## 26. Migrations, routes, translations, cache keys, permissions and compatibility risks

| Area | Planning task | Future execution risk |
| --- | --- | --- |
| Migrations | `not_applicable` | Compact payload/file-size projection indexes require paused writers, backup, space and dual-read |
| Routes/API | `not_applicable` | No new public route planned; admin remains full-page Livewire |
| Translations | `not_applicable` | Admin/status copy must keep RU/EN parity where existing surface is multilingual |
| Cache keys | `not_applicable` | New operational snapshot must use versioned key and preserve existing domain invalidation |
| Permissions | `already_compliant` | Existing `imports.execute` remains canonical |
| Queue serialization | `not_applicable` now | Job constructor changes are avoided; rolling compatibility is mandatory |
| Database behavior | `unresolved` | SQLite one-writer, large DDL/backup/compaction and retention delete cost require future execution gates |
| Production services | `unresolved` | Missing intended workers and duplicate scheduler ownership are Phase 0 blockers |
| Dependencies | `not_applicable` | No package addition proposed |

## 27. Requirement-compliance matrix

| Requirement | Status | Evidence / constraint |
| --- | --- | --- |
| Root `AGENTS.md` and canonical read order | `completed` | Re-read before audit |
| `docs/requirements/index.md` and mandatory requirements | `completed` | Multilingual, maintenance/upgrades, production operations and system-wide integration applied |
| Applicable Markdown owners | `completed` | Importer, architecture, code standards, data relations, queues, performance, cache, security, testing, admin, authorization, deployment/environment/health/source parity read |
| Installed versions and official Laravel behavior | `completed` | Boost application info and Laravel 13 queue/transaction/upsert/HTTP docs checked |
| Existing implementation before replacement | `completed` | Services/jobs/models/migrations/tests and actual query plans/data distributions inspected |
| Legacy/duplicate/stale/unfinished importer scan | `completed` | One public command confirmed; no importer TODO/FIXME markers or broad cache/queue clears; identified HTTP/query/storage paths are assigned to explicit tasks |
| Task plan and compliance matrix | `completed` | This document plus current-task pointer |
| Expected files and protected contracts | `completed` | Sections 24–25 |
| Migration/routes/translations/cache/permissions risks | `completed` | Section 26 |
| Cross-feature impact | `completed` | Section 23 |
| No full video storage | `already_compliant` | Plan preserves HEAD/minimal Range only |
| One public import command | `already_compliant` | No second importer planned |
| Partial provider data preservation | `already_compliant` | Strengthened by Task 12 |
| Production data safety | `completed` for planning | No state mutation; future deletes/migrations gated by backup and approval |
| README review | `already_compliant` | No visitor/product change |
| CHANGELOG planning entry | `completed` | Separate Russian planning-only entry added for 24.07.2026 |
| Commit only to `main` | `unresolved` | Shared dirty worktree contains unrelated concurrent code, documentation, tests and lock-file work |
| Push configured remote | `unresolved` | Task-owned commit cannot safely pass the clean-worktree guard, so no push was attempted |

## 28. Program completion criteria

- intended workers and scheduler have one owner and current heartbeats;
- queue backlog/oldest age stays inside an approved budget without SQLite lock storm;
- title apply is provably network-free;
- high-volume info telemetry no longer creates one durable row per item;
- retention runs independently and keeps technical rows inside configured windows;
- new staging payloads are compact/versioned and rolling-compatible;
- dispatch has a durable cursor and measured bounded query/time budget;
- SQLite writer admission is measured, configurable and prevents lock churn;
- largest approved title group advances checkpoints under `900` seconds with no data loss;
- media apply and file-size scheduling are index/batch driven;
- status/admin do not perform cold full-catalog scans;
- parser/provenance fixtures cover complete, partial, invalid and large inputs;
- crash/retry/load matrix passes with no duplicate canonical identity;
- search, recommendations, calendar, cache, API sync, player, premium/region/legal and public routes remain compatible;
- canonical docs, README review, CHANGELOG, completed plan/compliance evidence, commit and push result are recorded honestly.

## 29. Безлимитный rolling protocol

Tasks 1–18 задают первый измеренный проход, а не конечную дату развития импортёра. План безлимитен по приёму подтверждённой работы, но каждая отдельная задача остаётся малой, конечной, проверяемой и обратимой.

- [x] Сохранить Tasks 1–18 как стабильную историю и dependency graph; завершённые номера не переиспользовать и не перенумеровывать.
- [x] Task 19 присвоен только после live evidence 24.07.2026 о monolithic global finalization, которого не было в Tasks 1–18 или `TD-013`/`TD-021`–`TD-024`.
- [ ] Хранить единственный importer execution ledger в этом документе; `docs/plans/current-task-plan.md` пока содержит исторические и параллельные тела. Их lossless archive и запрет повторного накопления принадлежат system Tasks 27/33; этот refresh добавляет только верхнюю активную волну и не удаляет историю.
- [x] Отделять `code preparation`, `delivery`, `production activation` и `measured rollout`: один статус не подразумевает другой.
- [x] Запрещать пустые задачи, package updates ради номера версии, предполагаемые оптимизации без baseline и повтор уже существующего task/technical-debt owner.
- [x] Сохранять постоянные provider/legal/video/data boundaries независимо от количества будущих волн.

Rolling-цикл после каждого change set:

- [ ] Снять новый read-only baseline по backlog age/rate, run latency, SQLite writes/size/lock rate, event cardinality, staging/snapshot bytes, provider latency и public cold/warm latency.
- [ ] Сравнить baseline с утверждённым budget и предыдущей волной; единичный быстрый запуск не считать доказательством устойчивости.
- [ ] Сопоставить discovery с Tasks 1–18, technical debt и общей operations-программой; duplicate task не создавать.
- [ ] Для нового подтверждённого риска присвоить следующий монотонный Task ID и записать exact files, interfaces, RED tests, rollback, cross-feature impact и production gate.
- [ ] Выполнить RED → GREEN → refactor, focused tests, широкий importer набор и применимую cross-feature verification.
- [ ] Провести canary только после delivery и operational prerequisites; destructive cleanup, worker/process action, migration и provider activation требуют отдельной authority.
- [ ] Закрыть задачу только с measured after-state, документацией, README review, русским `CHANGELOG.md`, commit/push evidence либо честным `unresolved`.
- [ ] Повторить цикл, пока новые измерения выявляют полезную работу.

## 30. Текущий execution ledger

| Importer task | Code | Delivery | Production | Следующее действие |
| --- | --- | --- | --- | --- |
| Task 1 — process ownership | `not_applicable` | `not_applicable` | `blocked_by_external_operations` | Выполнить общую operations-программу Tasks 3–5 с отдельной authority; importer не сигналит процессы сам |
| Task 2 — event admission | `implementation_complete` | `unresolved_combined_gate` | `not_started` | После завершения всех non-importer owners повторно проверить объединённый importer snapshot и доставить с Tasks 3–4 одним атомарным commit; после rollout измерить row/write rate |
| Task 3 — independent retention | `implementation_complete` | `unresolved_combined_gate` | `blocked_by_task_1` | Войти в тот же атомарный Tasks 2–4 commit; production enablement только после backup/owner/preview/canary |
| Task 4 — network-free finalizer | `implementation_complete` | `unresolved_combined_gate` | `blocked_by_task_1` | После освобождения shared worktree повторно проверить объединённый Tasks 2–4 snapshot и доставить одним атомарным importer commit без production activation |
| Task 5 — compact staging | `planned` | `not_started` | `blocked_by_backup_and_task_1` | Не начинать schema/codec rollout без backup, space и dual-read evidence |
| Task 6 — transport reliability slice | `implementation_complete_query_hardened` | `unresolved_shared_worktree` | `active_recovery_verified_index_rollout_gated` | Sentinel убирает prepared `exists()`, projected groups устраняют N+1, additive recovery index проверен на clone и в query-plan test; production migration только после backup/space/writer-pause gate |
| Task 6 throughput и Tasks 7–10 | `planned` | `not_started` | `blocked_by_tasks_1_5` | Добавить dispatch cursor/bulk registration/status stages по dependency order; текущий recovery не выдавать за throughput completion |
| Tasks 11–13 — correctness/decomposition | `planned` | `not_started` | `not_started` | Fixtures/provenance precede decomposition; behavior rewrite запрещён |
| Tasks 14–16 — operations/security/load | `planned` | `not_started` | `blocked_by_prior_phases` | Подтвердить truthful status, privacy и crash/load matrix |
| Task 17 — optional parity | `gated` | `not_started` | `blocked_by_legal_publication_authority` | Не активировать типы страниц автоматически |
| Task 18 — rollout/closeout | `planned` | `not_started` | `blocked_by_tasks_1_17` | Выполнить acceptance, docs и measured rollout |
| Task 19 — resumable global finalization | `planned` | `not_started` | `blocked_by_tasks_1_6` | Реализовать durable per-stage checkpoints после transport recovery и до full queued canary |
| Tasks 20+ | `rolling_intake` | `not_started` | `not_started` | Добавлять только по правилам Sections 31–35 |

Tasks 2–4 не считаются доставленными или активированными только потому, что code/tests готовы. Их единый delivery commit допустим только как записанное следствие общего verified snapshot, прямого указания продолжить code preparation и обязательного clean-worktree hook; ledger, acceptance evidence и production gates не объединяются. Task 3 schedule зарегистрирован только как выключенный config contract; cleanup job activation и удаление существующих rows остаются отдельной production boundary.

## 31. Постоянные rolling lanes

| Lane | Trigger evidence | Обязательные метрики | Canonical owners |
| --- | --- | --- | --- |
| Reliability | stale run/claim, failed job, retry storm, missing heartbeat, broken recovery | completion ratio, oldest age, retry count, checkpoint progress, duplicate identity count | `docs/importer.md`, `docs/queues.md`, operations runbooks |
| Storage/write cost | table/WAL/backup growth, high event/payload cardinality, repeated JSON rewrite | rows/day, bytes/day, writes/item, transaction time, retained/prunable bytes | `docs/importer.md`, `docs/environment.md`, backup/restore owners |
| Throughput/latency | dispatch/apply/merge/status exceeds measured budget | wall time, queries, slowest query, busy retries, peak memory, queue slope | `docs/performance.md`, `docs/maintenance/technical-debt.md` |
| Correctness | parser drift, partial snapshot, duplicate title/season/media, retry divergence | manifest comparison, idempotent rerun, fixture coverage, exact counters | `docs/DATA_RELATIONS.md`, `docs/SOURCE_PARITY.md` |
| Integration | catalog mutation leaves search/cache/recommendation/calendar/API sync stale | affected IDs, after-commit handoffs, cache versions, sync changes, public route parity | `docs/requirements/system-wide-integration.md` and feature owners |
| Security/privacy/legal | URL/credential leak, unsafe provider path, region/rightsholder regression | redaction tests, allowlist decisions, retained sensitive fields, entitlement results | `docs/security.md`, provider/legal owners |
| Maintenance | framework/package/runtime behavior changes or deprecated API appears | installed/proposed versions, compatibility matrix, rollback and production impact | maintenance requirement and registries |

Новая задача получает одну primary lane и любые affected secondary lanes. Lane не даёт разрешения на data/process/provider mutation; она определяет владельцев требований и verification scope.

## 32. Следующая исполнимая очередь

### Wave A — освободить и перепроверить shared delivery boundary

- [ ] Повторно проверить `git status --short --branch` и подтвердить существующую `main`.
- [x] Player owner доставил runtime и evidence отдельными локальными commit’ами `1ded102` и `ab6532a`; player files больше не входят в importer index.
- [x] System-plan documentation owner доставил roadmap/evidence локальными commit’ами `cb432c7` и `14203ec`; index освобождён.
- [ ] Дождаться отдельного решения владельца unrelated `composer.lock` и завершения нового parallel system/lease documentation scope; проверить отсутствие staged/unstaged файлов вне importer scope и не stash/reset/delete/stage foreign diffs.
- [ ] Сверить полный importer path manifest с Tasks 2–4 expected files и явно исключить dependency/system/player/lease files.
- [ ] Повторить recorder, retention, maintenance, parse, queue/finalizer, wide Seasonvar, Pint, PHPStan/Rector, docs и diff checks на согласованном snapshot.
- [ ] Не менять `.env`, workers, Redis, SQLite rows или scheduler.

### Wave B — Task 4 network-free apply

- [x] Зафиксировать RED с `Http::preventStrayRequests()` для direct-media prepared finalization.
- [x] Удалить неоднозначный `allowNetwork` и `InspectLicensedMediaFileSize::execute()` из prepared apply.
- [x] Сохранить `resetFileSizeInspection()` при смене effective URL; новая/изменённая direct media остаётся `pending` и обрабатывается существующей bounded file-size backlog lane.
- [x] Сохранить external URL-only, HEAD/minimal Range и HLS prohibition.
- [x] Проверить sync, queue, retry, counters, cache/search/recommendation/calendar/API-sync handoffs.
- [x] Выполнить focused/wide tests и owner docs; code delivery остаётся только в атомарной Wave C до любого production canary.

### Wave C — атомарная delivery Tasks 2–4

- [ ] Stage только подтверждённый объединённый Tasks 2–4 importer manifest; `composer.lock` и system/player/lease files не включать.
- [ ] Проверить `git diff --cached --name-status`, `git diff --cached --check`, отсутствие secrets/raw URLs и соответствие README/CHANGELOG policies.
- [ ] Создать один commit `feat: bound and streamline importer processing` как документированное clean-hook exception.
- [ ] Выполнить normal fast-forward push; отсутствие credentials или remote rejection записать `unresolved`, без force/history rewrite.
- [ ] Оставить production activation Task 3 выключенной; delivery commit не запускает scheduler/job и не удаляет rows.

### Wave D — сначала восстановить transport durability, затем выбрать storage/throughput

- [ ] Повторно снять staging/snapshot bytes, SQLite/WAL/backup budget, dispatch wall time, pending/delayed slope и writer contention после Tasks 2–4.
- [x] Выполнить минимальную reliability-часть Task 6: existing staging как durable work ledger, bounded active-run reconciliation, database CAS по timestamp попытки, CLI/watchdog integration и truthful heartbeat. Полный dispatch cursor, bulk registration и throughput batching остаются ниже отдельным незавершённым scope Task 6.
- [x] Оптимизировать измеренный recovery hot path: читать один sentinel сверх прежнего batch без dispatch, переиспользовать eager-loaded groups, удалить повторный prepared-ledger `exists()` и подготовить reversible composite index. Production migration не активировать без backup, места, paused writers, integrity/EXPLAIN и canary.
- [ ] Выбрать Task 5 только при подтверждённом storage/write bottleneck и наличии backup/space/dual-read prerequisites.
- [ ] Завершить throughput-часть Task 6, потому что измеренный serial dispatch уже занимает часы; query budget и batching не подменяют recovery contract.
- [ ] Task 19 выполнять после transport recovery и до full queued canary; он не должен быть объединён со storage codec или parser decomposition.

## 33. Definition of Ready для любого Task 20+

Новый Task `20+` допускается в план, только когда все пункты подтверждены:

- [ ] Есть датированный redacted evidence и измеримый ущерб correctness, security, storage, latency, availability или maintenance.
- [ ] Repository-wide поиск доказал отсутствие уже существующего task/service/owner, закрывающего ту же проблему.
- [ ] Указаны primary/secondary rolling lanes и зависимость от Tasks 1–18.
- [ ] Перечислены exact create/modify/test/docs paths и публичные/persisted interfaces.
- [ ] Перечислены migrations, routes, translations, cache keys, permissions, queue serialization и backward-compatibility risks.
- [ ] Записаны data-safety, backup, rollback, production impact и failure-recovery strategy.
- [ ] Названы конкретные RED tests и ожидаемая причина их первоначального падения.
- [ ] Установлены численные или структурные acceptance criteria без выдуманного SLA.
- [ ] Проверен shared-worktree owner и возможность отдельного commit на `main`; исключение допустимо только как заранее записанное clean-hook delivery deviation для уже совместно проверенного snapshot.

Если хотя бы один пункт отсутствует, discovery остаётся evidence или `unresolved`, но не получает номер implementation task.

## 34. Definition of Done для каждой rolling-задачи

- [ ] RED действительно падал по целевой причине; GREEN и refactor evidence сохранены.
- [ ] Focused tests, `php artisan test --filter=Seasonvar`, применимые static/docs/frontend/browser gates прошли либо failure честно классифицирован.
- [ ] Measured after-state сопоставлен с baseline; ухудшение соседнего budget блокирует rollout.
- [ ] Exact counters, canonical identity, retries/checkpoints и partial-data preservation проверены.
- [ ] Search, recommendations, calendar, cache, API sync, player, premium/region/legal, SEO, privacy и administration отмечены `completed`, `already_compliant`, `not_applicable` или `unresolved`.
- [ ] Legacy/duplicate/stale/unfinished scan выполнен по всему repository без механического удаления найденного кода.
- [ ] Тематические owners, current plan, README review и отдельная русская запись `CHANGELOG.md` актуальны.
- [ ] Code delivery выполнена отдельным commit в `main` либо имеет явное task-specific delivery-deviation evidence; push подтверждён remote SHA либо отмечен `unresolved`.
- [ ] Production canary имеет rollback evidence; code preparation без canary не называется production completion.

## 35. Триггеры повторного аудита

| Trigger | Обязательное действие |
| --- | --- |
| После каждого importer commit | Focused regression, wide Seasonvar matrix, policy/legacy scan, current ledger update |
| После изменения queue/job contract | Serialized payload/retry/after-commit compatibility и rolling worker restart review |
| После schema/index/codec change | Backup/space/writer pause, dual-read, integrity/reconciliation и rollback rehearsal |
| После full queued run | Run counters, terminal groups, event/write rate, oldest backlog age, SQLite busy/public latency comparison |
| После provider/parser drift | Sanitized fixture, semantic fingerprint, partial snapshot и publication/legal gate review |
| После Laravel/PHP/package/runtime change | Official version-specific guidance, compatibility matrix, production requirements и full affected-feature verification |
| После security/privacy incident | Containment/evidence preservation, redaction/retention audit, affected integrations и incident owner workflow |
| Когда все известные tasks закрыты | Новый baseline; при отсутствии измеренного риска план остаётся открытым, но фиктивная задача не создаётся |

Безлимитность означает непрерывный evidence-driven intake, а не бесконечное выполнение без checkpoints. Каждый запуск агента выбирает только первый `ready` пункт dependency graph и завершает его отдельным проверяемым change set; записанное delivery-исключение Tasks 2–4 не создаёт общего правила для следующих задач.

## 36. Актуальный координационный срез — 24.07.2026

### Подтверждённое состояние

- Branch остаётся существующей `main`; наблюдённый локальный HEAD `14203ec` находится на 21 commit впереди `origin/main` и включает отдельные player commits `1ded102`/`ab6532a` и system-plan commits `cb432c7`/`14203ec`. Remote delivery общего локального хвоста не подтверждена.
- Player и system-plan owners освободили предыдущий index и сохранили свои результаты отдельными commit’ами. Во время финальной проверки появились новые unstaged изменения system/player plan docs, workspace-lease script/test и system master; они не относятся к importer delivery и не изменяются этим планом.
- Importer Tasks 2–4 остаются общим unstaged/untracked implementation snapshot. `composer.lock` содержит отдельное dependency update и исключён из importer scope.
- `.githooks/pre-commit` требует одновременно отсутствие unstaged tracked changes и untracked files. Поэтому последовательные commit’ы для уже совместно присутствующих Tasks 2–4 невозможны без временного изъятия готовой работы; план выбирает один атомарный delivery commit и сохраняет раздельные task/production statuses.
- Task 4 завершён в коде: prepared apply больше не имеет availability fallback или size-inspector dependency, direct/finalizer tests запрещают HTTP, а отдельный size-only regression сохраняет bounded `HEAD`/minimal `Range` и HLS prohibition.
- Live read-only audit обнаружил отдельный риск monolithic global finalization вне прежних Tasks 1–18; он принят как Task 19 в Section 37. Transport loss и ложный heartbeat не создают дубликаты задач: они уточняют и повышают приоритет существующих Tasks 6, 14 и 16.

### Task-specific compliance matrix этого refresh

| Requirement | Статус | Evidence / решение |
| --- | --- | --- |
| Fresh canonical/feature read | `completed` | Повторно сверены root instructions, requirements index, architecture/code/development/multilingual/security/performance/caching/production/maintenance/integration owners и importer/queue/environment/health/technical-debt docs |
| Installed versions | `completed` | Boost подтвердил PHP `8.5`, Laravel `13.21.1`, SQLite, Livewire `4.3.3`, Boost `2.4.13`, Pint `1.29.3`, PHPUnit `12.5.31`, Tailwind CSS `4.3.2` |
| Existing implementation | `completed` | Проверены actual Tasks 2–3 snapshot и Task 4 call graph; network path удалён минимальным diff, queue/finalizer orchestration не переписана |
| TDD и focused/wide verification | `completed` | RED упал только на прежнем size-start event; focused `69`, wide Seasonvar `270/1659`, full `1547/123832`, направленные Pint/PHPStan/Rector прошли |
| Documentation verification | `completed` | Owner docs, README, CHANGELOG и TD-021 обновлены; docs-refresh, syntax, legacy implementation scan и diff checks прошли |
| Stable unlimited roadmap | `completed` | Tasks 1–18 не перенумерованы; Task 19 принят по новому live evidence, rolling intake/DoR/DoD для Tasks 20+ остаются открытыми |
| Current-plan archive/policy | `unresolved` | Исторические тела сохранены; lossless archive и single-active-plan gate принадлежат system Tasks 27/33 |
| Public importer command | `already_compliant` | `php artisan seasonvar:import` остаётся единственной публичной command |
| Media/data/security boundaries | `already_compliant` | План сохраняет external URL-only, bounded metadata, no HLS assembly, sanitization и запрет production mutation |
| Cross-feature impact | `completed` | Task 4 сохраняет search/recommendation/calendar/cache/API-sync/player/access contracts; Tasks 2–4 не активируются планом |
| Migrations/routes/translations/cache/permissions | `not_applicable` | Planning refresh не меняет schema/runtime/public contracts |
| README review | `completed` | Import section и visitor history описывают network-free prepared apply без обещания production activation |
| Delivery | `unresolved` | Финальный preflight: `main` ahead `27`, index пуст, `46` tracked dirty и `9` untracked файлов из importer + unrelated dependency/system/player/collection/lease scopes; no-unstaged/no-untracked hook исключает безопасный commit/push без поглощения чужой работы |

### Expected files актуализации

- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Modify/add tests: prepared apply, title-group finalizer и `ExternalMediaFileSizeInspectorTest`
- Modify owners: `docs/importer.md`, `docs/architecture.md`, `docs/queues.md`, technical debt, importer/current plans, `README.md`, `CHANGELOG.md`
- Preserve unchanged: schema/config/runtime orchestration, `composer.lock`, system/player/collection plans, workspace-lease files and every parallel-owner commit/diff

### Compatibility и rollback

- Public routes, queue names/job payloads, DB schema, translations, cache keys, permissions, catalog identity, player grants and production services остаются без изменений.
- Rollback Task 4 — code-only revert importer/test/docs diff; `pending` size rows остаются валидными и доступны существующей backlog lane, schema/data rollback не требуется.
- Любое новое evidence, меняющее owner, dependency, risk или readiness, немедленно обновляет Sections 30, 32 и 36 без перенумерации закрытых Tasks.

## 37. Live reliability audit и Task 19 — resumable global finalization

### Подтверждённое состояние — 24.07.2026

- Active queued run `#1255` имеет `32 523` selected claims, `32 522` durable staging rows и `20 869` running title groups, но `0` parsed/prepared/applied pages; `dispatch_completed=false`.
- Redis queue перешла со `121 027` pending/delayed сообщений к `0` после restart. Redis загрузил RDB, созданный примерно за `36` часов до restart; `AOF` выключен. SQLite run, claims, groups и staging сохранились, поэтому это подтверждённая потеря transport state, а не нормальное завершение.
- Четыре import workers имеют свежие process heartbeats, но не получают работу. Watchdog продолжает будить `FinalizeSeasonvarQueuedImport`; ветка `dispatchIsIncomplete()` обновляет run heartbeat и возвращается без durable progress. Поэтому stale recovery может быть заблокирован бессрочно.
- Producer регистрирует страницы последовательно: предыдущий run зарегистрировал `41 043` страницы примерно за `2:43`, текущий — `32 522` примерно за `2:16` до restart. Administration coordinator имеет `timeout=900`, но повторный job больше не входит в `handle()`, когда run уже изменён с `queued` на `running`.
- Глобальная финализация выполняет retention, несколько backfill/media lanes, deduplication, полный merge и recommendations одним job. Единственный checkpoint создаётся только после первых десяти тяжёлых стадий, а catch удаляет его; catchable failure поздней стадии повторяет уже выполненную дорогую работу.
- Текущий storage baseline: SQLite около `28.17 GB`; `source_page_snapshots` около `7.14 GB`, `seasonvar_import_prepared_pages` около `4.42 GB`, `seasonvar_import_events` около `0.94 GB`. Это подтверждает прежний приоритет Tasks 2, 3 и 5, но storage cleanup не исправляет transport loss.
- Read-only health также подтвердил два scheduler owners: active `schedule:work` и системный cron `schedule:run`, а отдельный cron напрямую запускает `seasonvar:import --queued`. Task 1 остаётся обязательным production prerequisite.

### Проверенное восстановление — 25.07.2026

- Reliability slice Task 6 реализован без migration: existing `seasonvar_import_prepared_pages` стал authoritative transport ledger, а `updated_at` — compare-and-swap границей последней enqueue attempt. Отдельные durable cursor/attempt columns, bulk registration и throughput rewrite остаются незавершённой частью Task 6.
- `FinalizeSeasonvarQueuedImport` больше не обновляет heartbeat при закрытом `dispatch_completed`; реальный barrier recovery и каждый успешно отправленный bounded batch обновляют его как подтверждённый progress.
- Canonical stale-run query сохраняет старый `running` run при наличии `queued|preparing|prepared` staging либо `discovering|running|finalizing` group даже после истечения claim; пустой stale run без durable work по-прежнему закрывается.
- Scheduled watchdog первым запустил reconciliation для `#1255`: summary получил `dispatch_completed=true` и безопасный reason `redis_transport_reconciled`, после чего существующие четыре worker снова начали `PrepareSeasonvarImportTitlePage`.
- Точная команда `php artisan seasonvar:import` штатно сообщила о повторной постановке bounded пакета из `250` задач и сохранила global single-flight. Итоговое наблюдение показало Redis backlog `4799`, рост `parsed` с `0` до `1023`, `172 applied`, `104` завершённые группы, уменьшение live claims с `32 523` до `31 501`, свежий heartbeat и сохранение run `running`.
- Не применялись queue/cache clear, failed-job retry-all, manual SQL status/claim changes, migration, environment/service restart или удаление данных. `app:deployment-check --json` вернул `ready=true`; SQLite quick/FK checks и required indexes прошли. Имеющиеся failed jobs и неподтверждённое имя process manager остаются отдельными warning/operations scope.

### Решение по scope

Transport loss, producer timeout и ложная свежесть принадлежат уже существующим Tasks 6, 14 и 16. Новый Task 19 создаётся только для отдельной проблемы: глобальная финализация не имеет durable stage machine и безопасного resume по каждой тяжёлой стадии.

### Task 19 — durable stage machine для global finalization

**Priority:** P0 recovery/availability после Task 6 и до full queued canary.

**Primary lane:** reliability.  
**Secondary lanes:** storage/write cost, integration, operations.

**Dependencies:** Task 1 process ownership; reliability-часть Task 6; Task 4 network-free apply. Task 19 не зависит от parser decomposition и не требует смены database engine.

**Expected files:**

- Create: `app/Enums/SeasonvarImportFinalizationStage.php`
- Create: `app/Services/Seasonvar/SeasonvarImportFinalizationCoordinator.php`
- Create: `database/migrations/2026_07_24_181000_add_finalization_progress_to_seasonvar_import_runs.php` after schema/space review
- Modify: `app/Jobs/FinalizeSeasonvarQueuedImport.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportRunRecorder.php`
- Modify: `app/Models/SeasonvarImportRun.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`
- Create: `tests/Feature/SeasonvarImportFinalizationRecoveryTest.php`
- Modify: `docs/importer.md`
- Modify: `docs/queues.md`
- Modify: `docs/deployment.md`
- Modify: `docs/operations/logging-and-health.md`
- Modify: `CHANGELOG.md`

**Protected contracts:**

- единственная публичная команда `php artisan seasonvar:import`;
- queue connection/name и ID-only serialized job payload;
- external URL-only media, network-free catalog apply и bounded metadata lanes;
- canonical catalog identity, editorial fields и partial snapshot preservation;
- after-commit search, API sync, cache, calendar and recommendation handoffs;
- policies/gates, routes, translations and player access behavior.

**Target contract:**

1. Каждая тяжёлая finalization stage имеет stable enum, durable status, attempt, started/finished timestamps, sanitized failure code и versioned result/checkpoint.
2. Coordinator исполняет одну bounded stage за job attempt или time budget, затем сохраняет checkpoint и переотправляет следующий ID-only job after commit.
3. Повторная доставка завершённой stage становится no-op; running stage с истёкшим lease безопасно возобновляется по её собственному idempotency contract.
4. Catchable failure не удаляет checkpoints предыдущих стадий. Retry повторяет только текущую незавершённую stage.
5. Recommendation activation, terminal run status и final cache handoff остаются последней атомарно упорядоченной границей.
6. Storage retention остаётся независимым scheduled lane и не возвращается внутрь обязательного success path.
7. Status показывает фактическую finalization stage и durable progress, а не общий heartbeat.

**RED tests:**

1. failure after each finalization stage preserves all earlier checkpoints;
2. retry skips completed stages and resumes only the failed stage;
3. worker timeout between stage commit and next dispatch is recovered without duplicate canonical writes;
4. duplicate finalizer delivery cannot execute the same stage concurrently;
5. Redis transport loss after stage commit is recovered from durable stage state;
6. stale running-stage lease is reclaimable, while a fresh lease is not;
7. recommendation/cache/API-sync activation occurs once and only after required stages;
8. terminal run counters and status equal a clean uninterrupted run;
9. rollback to the previous compatible reader does not misclassify an in-flight run.

**Migration/data safety:**

- migration additive only; no rewrite of historical payload/snapshot bodies;
- index choice requires `EXPLAIN QUERY PLAN`, current database-size estimate, backup/restore evidence and paused-writer window;
- rolling deploy order: schema → dual-compatible reader/coordinator → graceful worker restart → targeted canary;
- rollback disables the new coordinator and keeps additive metadata; no down migration while jobs may reference a new stage.

**Acceptance:**

- every injected crash boundary resumes or fails truthfully without repeating completed heavy stages;
- no observer or watchdog call advances durable progress;
- one stage attempt remains inside measured worker timeout/memory/query budgets;
- full queued canary reaches terminal status after an induced worker/Redis restart;
- search, recommendations, calendar, API sync and cache results match the uninterrupted fixture;
- production activation and recovery drill have separate operator evidence.

### Пересмотренная рекомендуемая очередь

1. Task 1: один scheduler/producer owner и durable Redis profile; никаких broad queue flush/retry.
2. Task 6 reliability: durable staging outbox, coordinator cursor, database CAS, active-run reconciliation; только затем безопасное восстановление run `#1255`.
3. Task 14: честные phase/progress/transport-loss состояния и быстрый indexed status.
4. Доставить и отдельно активировать уже подготовленные Tasks 2–4 после освобождения shared worktree и всех production gates.
5. Task 19: durable per-stage global finalization.
6. Tasks 3/5: retention preview/canary, затем compact dual-read storage по backup/space gate.
7. Tasks 7–10: измеренный SQLite writer admission, bounded title merge/media sync и indexed file-size projection.
8. Tasks 11–12/15/16: parser/provenance fixtures, decompression limits, failure injection and load matrix.
9. Task 13: decomposition только после зафиксированных equivalence fixtures и budgets.

### Task-specific compliance matrix live audit

| Requirement | Статус | Evidence / решение |
| --- | --- | --- |
| Fresh canonical/feature read | `completed` | Перечитаны root instructions, requirements index и применимые owners architecture, code, development, multilingual, security, performance, caching, production, maintenance, integration, importer, queues, environment, deployment, testing, data relations и technical debt |
| Installed versions | `completed` | Фактически проверены PHP `8.5.8`, Laravel `13.21.1`, Boost `2.4.13`, Livewire `4.3.3`, PHPUnit `12.5.31`, Pint `1.29.3`, Larastan `3.10`, Rector `2.5.7`, Tailwind CSS `4.3.2`, Node `26.4.0` |
| Existing implementation | `completed` | Прослежен command → inventory → prepare → staging/group apply → global finalizer и все status/recovery owners до планирования изменений |
| Production mutation | `not_applicable` | Audit использовал только read-only status/process/database/log evidence; workers, Redis, scheduler, SQLite rows, cache and environment не менялись |
| Public importer/media contracts | `already_compliant` | Одна public command, one-title family, external URL-only media, bounded `HEAD`/minimal `Range`, no HLS assembly сохранены |
| Cross-feature impact | `completed` | План защищает auth/policies, translations, search, recommendations, calendar, cache, API sync, player, premium/region/legal and administration |
| Migrations/routes/translations/cache/permissions | `completed` | Для Tasks 6/19 migration additive и gated; routes/translations/permissions/cache identities остаются совместимыми |
| Production/rollback | `completed` | Для Task 1/6/19 записаны persistence, backup, rolling deploy, worker restart, canary, failure recovery и non-destructive rollback |
| README review | `already_compliant` | Planning-only audit не изменяет visitor/product behavior, install or operations contract; фиктивная visitor history entry не нужна |
| Verification | `completed` | `--filter=Seasonvar`: `270` tests / `1659` assertions; full suite: `1568` total, `1557` passed, `11` skipped, `123891` assertions; managed docs check passed |
| Static analysis | `unresolved` | Scoped PHPStan нашёл три существующих importer issues: два неверных `?callable` invocation и protected `incrementEachQuietly()` call; исправление включается в ближайший code task, audit code не меняет |
| Current active run | `completed_recovery_in_progress` | Run `#1255` восстановлен штатным Task 6 reconciler: transport снова обрабатывается, parsed растёт, claims уменьшаются; terminal completion не заявляется до фактического drain/finalization |
| Delivery | `unresolved` | `main` содержит многочисленные importer и unrelated dirty files; clean-worktree hook исключает безопасный отдельный commit/push этого planning refresh без поглощения чужих изменений |
