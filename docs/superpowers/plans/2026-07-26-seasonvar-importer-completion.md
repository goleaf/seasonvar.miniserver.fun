# План завершения программы импортёра Seasonvar

Дата: 26.07.2026.

Статус: `completed`.

## Цель и порядок

Выполнить одобренный
[design](../specs/2026-07-26-seasonvar-importer-completion-design.md)
последовательными TDD-фазами. Каждая фаза завершается focused verification,
документацией, compliance evidence и отдельным безопасным commit в `main`,
если shared index/lease допускают точную фиксацию.

## Phase 1 — bounded dispatch и durable transport

- [x] Выполнить оставшиеся Tasks 3–7
  [детального Task 49 plan](2026-07-25-seasonvar-batch-dispatch-query-optimization.md).
- [x] Сначала подтвердить RED отсутствующего
  `SeasonvarImportDispatchBatcher`, затем добавить DTO/service.
- [x] Сохранить transaction/after-commit/outbox/CAS contracts.
- [x] Доказать `<=120` SQL queries для 100 serial pages.
- [x] Закрыть TD-022 и transport-часть TD-014 только по фактическому evidence.

## Phase 2 — resumable global finalization

- [x] Снять профиль длительности/повторов каждой finalization stage.
- [x] Написать RED на crash после каждой durable boundary.
- [x] Ввести versioned stage state без изменения public queue payload.
- [x] Отделить progress от heartbeat во всех terminal/wait paths.
- [x] Проверить restart и повторный вход в durable stages.
- [x] Закрыть TD-026 и оставшуюся global convergence часть TD-015 только
  после full retry verification.

## Phase 3 — staging/storage и SQLite writer admission

- [x] Измерить representative prepared payload и duplicate field cost.
- [x] Спроектировать versioned compact reader/writer с legacy fallback.
- [x] Написать migration/rollback/size RED и disposable rehearsal.
- [x] Ввести feature-gated bounded writer admission только вокруг write sections.
- [x] Проверить WAL growth, lock rate и throughput canary.
- [x] Не включать retention schedule без отдельного backup/restore gate.

## Phase 4 — media bulk sync и file-size projection

- [x] Профилировать query/write shapes существующего synchronizer.
- [x] Написать RED на ownership, soft-delete, concurrent source change и
  idempotent bulk upsert.
- [x] Устранить только подтверждённые per-row identity lookups/N+1.
- [x] Спроектировать feature-gated indexed file-size projection с bounded rebuild и fallback.
- [x] Сохранить HEAD/minimal Range, SSRF и URL secrecy contracts.

## Phase 5 — parser correctness и section provenance

- [x] Собрать redacted local fixtures для supported serial и
  partial/blocked/malformed cases.
- [x] Зафиксировать fingerprints и deterministic parser regression.
- [x] Добавить section-level provenance в DTO/source metadata path.
- [x] Доказать, что partial/unknown blocks не удаляют authoritative catalog.
- [x] Проверить title identity и merge без fuzzy matching.

## Phase 6 — decomposition, truthful UX и security/load matrix

- [x] Декомпозировать только измеренные oversized boundaries с preserved
  public contracts.
- [x] Свести CLI/admin status к одному read model.
- [x] Добавить реальные localized phase/transport/worker/progress states без fake ETA.
- [x] Выполнить SSRF, payload/log redaction, queue constructor и authorization
  regression.
- [x] Выполнить failure/load matrix и full Seasonvar/PHPUnit verification.

## Phase 7 — rollout и closeout

- [x] Перечитать canonical requirements и выполнить legacy/duplicate scan.
- [x] Обновить `docs/importer.md`, `docs/queues.md`, `docs/performance.md`,
  `docs/deployment.md`, `README.md` и `CHANGELOG.md` по фактическим изменениям.
- [x] Сверить master plan и technical debt statuses.
- [x] Для production-affecting фазы пройти backup/rollback/canary gates.
- [x] Зафиксировать unresolved external blockers отдельно от code readiness.
- [ ] Выполнить exact manifest/index review, commit в `main` и normal push:
  blocked чужим staged Task 107 index, delivery не смешивается.

## Ожидаемые contracts

- Единственная public command: `php artisan seasonvar:import`.
- Queue payloads: только scalar IDs/tokens/allowlisted flags.
- Seasons/episodes: один `CatalogTitle`.
- Video: external URL и bounded metadata, без хранения full body.
- SQLite: короткие retry-aware transactions, bulk writes и indexed bounded
  queries.
- UI: русский, permission-scoped, no raw provider URL/error и no fake ETA.

## Verification cadence

Для каждой фазы:

1. focused RED;
2. minimal GREEN;
3. Pint для PHP scope;
4. focused regression;
5. query/load/profile evidence;
6. broad `--filter=Seasonvar`;
7. PHPStan для changed PHP manifest;
8. полный PHPUnit перед phase close;
9. documentation gates;
10. production read-only/canary checks только через применимые gates.
