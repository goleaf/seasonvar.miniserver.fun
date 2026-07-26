# Task 107 — прерванный registry

Task `task-107-title-similar-recommendations` завершила owner process до
commit, index approval и lease release. PID отсутствовал, поэтому stale lease
восстановлена штатной командой; product paths, tests, full plan и compliance
этой задачи оставлены без изменений и не входят в Task 106.

Сохранённое состояние вытесненного `current-task-plan.md`:

## Workstream

| Задача | Статус | Evidence |
|---|---|---|
| 6+6 компактных рекомендаций с объяснением сходства и feedback | `in_progress` | [design](../../superpowers/specs/2026-07-26-title-similar-recommendations-compact-design.md), [plan](../../superpowers/plans/2026-07-26-title-similar-recommendations-compact.md), [compliance](../task-107-title-similar-recommendations-compliance.md) |

## Discovery

- Task 102 и Task 105 завершены; baseline `main` включает player safeguards и
  admin-only corrections. Task 107 не возвращает удалённые public correction
  controls.
- Stale `task-100-honest-pwa` восстановлен только после подтверждения
  отсутствующего PID; Task 107 владела exclusive lease и объявила 27 paths.
- Existing service уже возвращает bounded precomputed/fallback rows, title
  loader eager-loads counts/ratings, а enum/service поддерживает
  `not_similar`.
- Текущий title builder дважды загружает subject feedback options: три SQL
  запроса для related и три для similar. Compact title-page feedback не
  использует эти options, поэтому их удаление является проверяемой
  оптимизацией.
- Public routes/API/DTO/Livewire method names сохраняются. Migration, index,
  package, cache key и JavaScript не требуются.

## Последний checklist

| Этап | Статус |
|---|---|
| Requirements/version/git/architecture discovery | completed |
| Exclusive lease и exact manifest | interrupted |
| Canonical UI rule/design/compliance | completed |
| RED tests | interrupted |
| Backend/component/frontend implementation | unresolved |
| Security/query/a11y/browser verification | unresolved |
| Documentation/evidence | unresolved |
| Exact index/commit/push/release | unresolved |
