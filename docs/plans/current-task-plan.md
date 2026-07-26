# Текущая задача — Task 105: admin-only catalog corrections

## Реестр активных workstreams

| Workstream | Status | Evidence |
|---|---|---|
| Task 105 — удалить публичное «Исправить данные» и закрыть correction workflow административным правом | `in_progress` | lease `task-105-admin-only-catalog-corrections`; [design](../superpowers/specs/2026-07-26-admin-only-catalog-corrections-design.md), [implementation plan](../superpowers/plans/2026-07-26-admin-only-catalog-corrections.md), [compliance](task-105-admin-only-catalog-corrections-compliance.md) |

## Реестр blocked/unresolved

| Workstream | Status | Evidence |
|---|---|---|
| Full repository verification | `unresolved: foreign baseline` | Full run: 2 191 tests, 2 162 passed, 11 skipped, 17 foreign failures and 1 foreign missing-class error; Task 105 focused/related tests pass |
| Managed documentation refresh | `unresolved: foreign PWA scope` | `project:docs-refresh --check` requests an update of already staged `docs/MAINTENANCE_LOG.md`; Task 105 does not rewrite it |
| Remote delivery | `unresolved: pending push attempt` | Ordinary `git push origin main` will run after exact commit and review |

## Task-specific compliance matrix

| Requirement | Status | Evidence |
|---|---|---|
| Public correction UI absent | `completed` | Feature and Playwright assertions; title/player builders and Blade controls removed |
| Admin-only server boundary | `completed` | Type-aware policy/form/action plus historical-row visibility tests |
| Documentation and data safety | `completed` | Owner docs, README/CHANGELOG and guarded migration roundtrip |
| Exact commit/review/push | `in_progress` | [Task 105 compliance](task-105-admin-only-catalog-corrections-compliance.md) |

## Живой checklist

| Stage | Status | Verification |
|---|---|---|
| Requirements/version/git/implementation discovery | `completed` | Canonical docs, Boost, repository/SQLite and cross-feature audit |
| Exclusive owner и exact path manifest | `completed` | Task 102 released after `6d7d30ed`; Task 105 acquired and declared paths |
| Canonical contract/design/compliance | `completed` | `docs/catalog-quality.md`, design, 56-point plan, matrix |
| RED tests | `completed` | Public UI/direct action/historical visibility/admin/help/cache/browser |
| Backend/frontend/data implementation | `completed` | Enum/policy/action/query/notification/help/Blade/migration |
| Focused/broad/full verification | `completed` | Pint/PHPUnit/migrations/build/Playwright/EXPLAIN; foreign baseline recorded above |
| Documentation/exact index/commit/review/push | `in_progress` | README/CHANGELOG/evidence complete; isolated index, main commit, review, ordinary push remain |

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

## Последнее подтверждённое evidence

- [Task 105 compliance matrix](task-105-admin-only-catalog-corrections-compliance.md)
- [Task 105 implementation plan](../superpowers/plans/2026-07-26-admin-only-catalog-corrections.md)
- [Task 102 player release verification](archive/2026-07-26-player-release-verification-evidence.md)
