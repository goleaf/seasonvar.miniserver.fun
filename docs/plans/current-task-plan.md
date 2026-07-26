# Текущая задача — Task 105: admin-only catalog corrections

## Реестр активных workstreams

| Workstream | Status | Evidence |
|---|---|---|
| Task 105 — удалить публичное «Исправить данные» и закрыть correction workflow административным правом | `in_progress` | Реализация уже доставлена commit `2ed775b0` и `1807d92e`; reviewer не нашёл Critical, две Important исправлены; follow-up safeguards/evidence готовятся через lease `task-105-admin-only-catalog-corrections` |

## Реестр blocked/unresolved

| Workstream | Status | Evidence |
|---|---|---|
| Full repository verification | `unresolved: foreign baseline` | Full run: 2 191 tests, 2 162 passed, 11 skipped; после исправления единственного Task 105 plan failure остаются 16 foreign failures и 1 foreign missing-class error |
| Safeguard follow-up delivery | `unresolved: pending push attempt` | Смешанный commit удалил tracked hooks/CI; их exact restoration и reviewer fixes будут отправлены обычным `git push origin main` после одобренного index |

## Task-specific compliance matrix

| Requirement | Status | Evidence |
|---|---|---|
| Public correction UI absent | `completed` | Feature and Playwright assertions; title/player builders and Blade controls removed |
| Admin-only server boundary | `completed` | Type-aware policy/form/action plus historical-row visibility tests |
| Documentation and data safety | `completed` | Owner docs, README/CHANGELOG and guarded migration roundtrip |
| Exact commit/review/push | `in_progress` | Reviewer завершён без Critical; exact follow-up commit/push остаются |

## Живой checklist

| Stage | Status | Verification |
|---|---|---|
| Requirements/version/git/implementation discovery | `completed` | Canonical docs, Boost, repository/SQLite and cross-feature audit |
| Exclusive owner и exact path manifest | `completed` | Task 102 released after `6d7d30ed`; Task 105 acquired and declared paths |
| Canonical contract/design/compliance | `completed` | `docs/catalog-quality.md`, design, 56-point plan, matrix |
| RED tests | `completed` | Public UI/direct action/historical visibility/admin/help/cache/browser; reviewer regressions дали ожидаемые 3 + 2 failures |
| Backend/frontend/data implementation | `completed` | Enum/policy/action/query/notification/help/Blade/migration; missing target fail-closed и cache contracts добавлены |
| Focused/broad/full verification | `completed` | Pint/PHPUnit/migrations/build/Playwright/EXPLAIN; foreign baseline recorded above |
| Senior review | `completed` | Нет Critical; исправлены обязательный target key и legacy search/API/sitemap cache isolation; 33/33, 135 assertions |
| Documentation и safeguard verification | `completed` | README/CHANGELOG/evidence и managed docs обновлены; hooks/CI exact hashes, 120 tests/621 assertions, PHPStan 0, Composer/build/docs policies зелёные |
| Exact index/commit/push | `in_progress` | Declared manifest проверен; index approval, main follow-up commits и ordinary push остаются |

## Scope и compatibility

- Сохраняются public routes и enum/storage values; direct URL существует, но
  административные типы на нём требуют server-side moderation permission.
- Сохраняются admin moderation queue, target resolver, status history и
  catalog editor/import boundaries.
- Historical correction rows fail-closed по type даже при `is_public=true`.
- Schema/package additions не планируются; нужна только guarded reversible
  help-content data migration.
- Смешанная PWA-история не переписывается; удалённые ею tracked hooks/CI
  восстанавливаются побайтно из последнего канонического состояния отдельным
  follow-up.

## Последнее подтверждённое evidence

- [Task 105 compliance matrix](task-105-admin-only-catalog-corrections-compliance.md)
- [Task 105 implementation plan](../superpowers/plans/2026-07-26-admin-only-catalog-corrections.md)
- [Task 105 archive evidence](archive/2026-07-26-admin-only-catalog-corrections-evidence.md)
