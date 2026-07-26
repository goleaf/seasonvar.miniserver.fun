# Текущая задача — Task 105: admin-only catalog corrections

## Реестр активных workstreams

| Workstream | Status | Evidence |
|---|---|---|
| Task 105 — удалить публичное «Исправить данные» и закрыть correction workflow административным правом | `in_progress` | lease `task-105-admin-only-catalog-corrections`; [design](../superpowers/specs/2026-07-26-admin-only-catalog-corrections-design.md), [implementation plan](../superpowers/plans/2026-07-26-admin-only-catalog-corrections.md), [compliance](task-105-admin-only-catalog-corrections-compliance.md) |

## Живой checklist

| Stage | Status | Verification |
|---|---|---|
| Requirements/version/git/implementation discovery | `completed` | Canonical docs, Boost, repository/SQLite and cross-feature audit |
| Exclusive owner и exact path manifest | `completed` | Task 102 released after `6d7d30ed`; Task 105 acquired and declared paths |
| Canonical contract/design/compliance | `completed` | `docs/catalog-quality.md`, design, 56-point plan, matrix |
| RED tests | `in_progress` | Public UI/direct action/historical visibility/admin/help/cache/browser |
| Backend/frontend/data implementation | `pending` | Enum/policy/action/query/notification/help/Blade/migration |
| Focused/broad/full verification | `pending` | Pint/PHPUnit/migrations/build/Playwright/EXPLAIN/docs |
| Documentation/exact index/commit/review/push | `pending` | README/CHANGELOG/evidence, isolated index, main commit, review, ordinary push |

## Scope и compatibility

- Сохраняются public routes и enum/storage values; direct URL существует, но
  административные типы на нём требуют server-side moderation permission.
- Сохраняются admin moderation queue, target resolver, status history и
  catalog editor/import boundaries.
- Historical correction rows fail-closed по type даже при `is_public=true`.
- Schema/package additions не планируются; нужна только guarded reversible
  help-content data migration.
- Shared dirty PWA work остаётся foreign и будет исключён exact alternate
  index.

## Предыдущие workstreams и unresolved

- Task 102 завершён commit `6d7d30ed`; evidence:
  [player release verification](archive/2026-07-26-player-release-verification-evidence.md).
- Physical iOS/Android/WebKit codec evidence остаётся unresolved в Task 102 и
  не относится к correction workflow.
- Shared full suite до Task 105 содержал 16 foreign failures и один foreign
  importer error; новый run будет атрибутирован отдельно.
- HTTPS push ранее отклонялся из-за отсутствия username; Task 105 повторит
  обычный push и зафиксирует фактический результат.
