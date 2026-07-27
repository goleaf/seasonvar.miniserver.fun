# Завершение программы импортёра Seasonvar

Дата: 26.07.2026.

Статус: `approved_for_execution`.

## Цель

Завершить открытые работы импортёра последовательными обратимыми фазами,
сохранив единственную публичную команду `php artisan seasonvar:import`,
durable database ledger, ID-only queue payloads, один `CatalogTitle` для всех
сезонов, внешние URL вместо копий видео и текущие authorization/cache/search
boundaries.

Пользователь одобрил рекомендованный порядок без дополнительных вопросов:

1. bounded batch dispatch и durable transport recovery;
2. resumable global finalization и правдивый progress;
3. compact staging/storage и SQLite writer admission;
4. bulk media/file-size projection;
5. parser fixtures, fingerprints и section provenance;
6. bounded decomposition, status/recovery UX и security/load verification;
7. operator-gated production rollout и закрытие evidence.

Optional provider parity не активируется без отдельного подтверждения
legal/source authority. Он остаётся документированным gate, а не скрытой
частью rollout.

## Подтверждённое исходное состояние

- Фактический стек: PHP 8.5.8, Laravel 13.22.0, Laravel Boost 2.4.13,
  Livewire 4.3.3, PHPUnit 12.5.32, SQLite 3.46.1.
- Visitor refresh восстановлен Task 106; production workers и targeted
  finalizers снова работают.
- Additive schema, planner anti-join и bulk claim foundation bounded dispatch
  уже существуют.
- `SeasonvarImportDispatchBatcherTest` и migration контракты существуют, но
  application class `SeasonvarImportDispatchBatcher` и DTO отсутствуют.
- Полный dispatcher по-прежнему регистрирует serial pages через per-page
  transactions и не использует подготовленный outbox/progress contract.
- Старый план
  [`2026-07-25-seasonvar-batch-dispatch-query-optimization.md`](../plans/2026-07-25-seasonvar-batch-dispatch-query-optimization.md)
  является точным implementation owner первой фазы.
- Общая очередь улучшений принадлежит
  [`2026-07-24-seasonvar-importer-improvement-master-plan.md`](../plans/2026-07-24-seasonvar-importer-improvement-master-plan.md).

## Архитектурное решение

### 1. Transport сначала

Initial sitemap fan-out регистрируется bounded batch по
`min(100, seasonvar.import.chunk_size)`. Authority возобновления —
unique `(seasonvar_import_run_id, source_page_id)` в prepared ledger, а не
один cursor. Serial claims, группы, prepared rows и counters фиксируются в
короткой retry-aware transaction. Redis dispatch выполняется после commit;
неотправленные rows остаются due outbox.

`queued -> preparing` становится database CAS. Duplicate delivery не начинает
второй provider HTTP, а transient failure возвращает exact row в `queued`.
Continuation имеет одного владельца на batch.

### 2. Finalization как durable state machine

Global finalization разбивается на явно версионированные bounded stages.
Завершённая стадия получает checkpoint в existing run summary либо в
additive compact state только после проверки размера, write amplification и
rollback. Retry продолжает с первой незавершённой стадии и не повторяет
catalog-wide maintenance.

`last_progress_at` меняется только при durable transition или положительном
counter delta. Heartbeat, status poll, watchdog wake и enqueue-only replay не
изображают прогресс.

### 3. Storage и SQLite

Prepared payload сначала профилируется по реальным размерам и повторяемым
полям. Compact/versioned representation вводится только с backward-compatible
reader и canary comparison. Retention не включается автоматически и не
заменяет backup.

Writer admission ограничивает только database write sections, не provider
HTTP. Он переиспользует configured critical lock store, имеет bounded wait,
safe retry и измеряемые counters. Worker pool, timeout и `retry_after` не
меняются без отдельного evidence.

### 4. Media и status projection

Media synchronization использует grouped preload и bulk upsert там, где
ownership/soft-delete semantics остаются точными. File-size status получает
indexed/derived projection только после query-plan и rebuild/rollback design;
raw URL и full body не попадают в projection или UI.

### 5. Parser и provenance

Каждый parser change начинается с сохранённого fixture и fingerprint.
Section provenance различает `present`, `absent_in_source`,
`rejected_invalid` и partial response. Отсутствие блока не удаляет локальные
связи, seasons, episodes или media.

### 6. Operations

CLI и `/admin/imports` используют один read model и показывают run state,
durable progress, observer heartbeat, dispatch/finalization stage и bounded
backlog отдельно. UI не показывает fake ETA и не выполняет provider HTTP.
Recovery actions остаются policy/gate/audit controlled; queue/cache clear,
retry-all, arbitrary SQL/status rewrite и raw diagnostics не добавляются.

## Data safety и rollout

Каждая schema/data/runtime фаза проходит:

1. terminal importer и остановку новых dispatches;
2. consistent verified SQLite backup и restore limitation record;
3. duplicate/integrity/read-only query-plan preflight;
4. additive migration classification;
5. disposable rehearsal;
6. bounded canary;
7. graceful worker reload без queue/cache clear;
8. counters/claims/groups/failed-jobs/readiness verification;
9. code-only rollback preference; destructive schema rollback только по
   отдельному impact approval.

До подтверждения этих gates production activation остаётся `unresolved`, даже
если код и тесты готовы.

## Совместимость

Не меняются:

- public routes, API envelopes и route model binding;
- `seasonvar-import` и `seasonvar-title-refresh`;
- scalar ID/token job constructors, retry/backoff/timeout;
- current run/group/prepared statuses и terminal reason codes;
- catalog identity, editorial ownership и additive partial-snapshot behavior;
- authorization policies/gates, translations, SEO и service-worker denylist;
- cache key factories, search/recommendation/calendar handoffs;
- external media URL-only storage и bounded metadata/download boundaries.

## Self-review

- Фазы отсортированы по риску и зависимости: transport/recovery предшествуют
  throughput и storage changes.
- Никакая фаза не требует новой public command или второго importer.
- Provider HTTP, Redis dispatch и catalog-wide derived work не помещаются в
  длинную SQLite transaction.
- Production activation отделена от code readiness и не симулируется.
- Optional parity остаётся отдельным legal/authority gate.
- Detailed batch behavior не дублируется: единственный владелец реализации —
  существующий Task 49 plan.
