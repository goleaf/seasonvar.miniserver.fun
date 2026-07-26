# Текущая задача — Task 102: безопасный workflow общей `main`

Дата: 26.07.2026.

Owner: `task-102-shared-main-workflow`.

Текущий этап: `in_progress` — implementation и scoped verification завершены;
точный изолированный index пересобран поверх актуального `HEAD`, следующий
gate — cached diff/security review и index approval.

## Реестр активных workstreams

| Workstream | Status | Evidence |
| --- | --- | --- |
| Task 102 — owner/manifest/index/registry activation | `in_progress: approved commit gate` | [Design](../superpowers/specs/2026-07-26-shared-main-workflow-design.md), [план](../superpowers/plans/2026-07-26-shared-main-workflow.md) |

## Реестр blocked/unresolved

| Workstream | Status | Evidence |
| --- | --- | --- |
| Pre-existing Tasks 98/100/101 и другие shared-tree scopes | `unresolved: нет commit-backed handoff` | Полные тела сохранены в [lossless archive](archive/2026-07-26-shared-main-workflow-evidence.md); Task 102 не включает их application files |
| Remote delivery накопленной `main` | `unresolved: ahead 111 до Task 102` | Во время active lease внешний процесс добавил `6660dac` и `8f12e95`; Task 102 сохраняет их и запишет точный push результат после approved commit |
| Production activation | `not_applicable: repository tooling only` | Runtime, БД, cache, queue, assets и production services не изменяются |

## Task-specific compliance matrix

| Requirement | Status | Evidence |
| --- | --- | --- |
| Root `AGENTS.md` и requirements index прочитаны до edits | `completed` | Обязательные owners перечитаны 26.07.2026 |
| Applicable maintenance/production/integration docs прочитаны | `completed` | Scope признан build-tooling-only; runtime/data rollout не применяется |
| Фактические версии и stack проверены | `completed` | PHP 8.5.8, Laravel 13.22.0, Boost 2.4.13, Livewire 4.3.3, PHPUnit 12.5.32, Pint 1.29.3, Node 26.4.0, npm 12.0.1, SQLite |
| Branch/status/upstream/index baseline | `completed` | Pre-edit: `main`, HEAD `d9c2c71957ec17f188e8d834c9e35958dca97cd1`, `origin/main`, ahead 109, shared index tree `b6703cc571906542b69cd394eab7d89af1b1659e`; перед delivery обнаружен новый HEAD `8f12e954709732b07720e0093adfacfe88843fff`, ahead 111 |
| Один task owner | `completed` | Active lease `task-102-shared-main-workflow`; safe status не раскрывает token/digest |
| Exact total file scope объявлен до edits | `completed` | NUL-safe manifest из 19 путей; `paths_declared=yes`, `index_approved=no` |
| Existing implementation audited before replacement | `completed` | Standalone Tasks 30/32/33/34 reused; новый parallel workflow не создаётся |
| Design alternatives и rollback | `completed` | [Task 102 design](../superpowers/specs/2026-07-26-shared-main-workflow-design.md) |
| Detailed prioritized plan reread | `completed` | [Task 102 implementation plan](../superpowers/plans/2026-07-26-shared-main-workflow.md) |
| Historical evidence preserved | `completed` | Source: 1 195 905 bytes, SHA-256 `181ee22258c05ae5a99bd6e8d5117e859b1b3b475d1397c8cffdb3ff90776a49`; после механической нормализации 176 relative link targets archive SHA-256 `a12519c9cc789dcd0ed7e740c15fb43df8ac6f1f9cad39d82045eb40d4b2ea9e` |
| Hook integration | `completed` | Owner проверяется до updater; exact paths и index — после updater; final index повторно проверяется после docs policies |
| Current-plan policy activation | `completed` | Repository current file GREEN; policy выполняется первой в docs profile |
| Security review | `completed` | Missing/wrong owner fail closed до mutation; token/digest не выводятся; symlink/NUL/metadata/index regressions GREEN |
| Compatibility review | `already_compliant` | Public routes/API/schema/DB/cache/frontend/translations не входят в scope |
| README/CHANGELOG/canonical docs | `completed` | Development owner обновлён первым; AGENTS содержит только mandatory boundary; README roadmap и docs map синхронизированы |
| Existing CHANGELOG policy debt | `completed` | В staged-варианте от актуального `HEAD` нормализовано только оформление технических терминов в трёх ранее commit-backed записях; факты/пункты не удалены, foreign working hunks исключены, `check-changelog-policy.sh --staged` GREEN |
| Focused verification | `completed` | 84 tests / 453 assertions; Pint, PHP/shell syntax, current-plan policy, docs profile и PHPStan GREEN |
| Broad verification | `completed: foreign blockers recorded` | Unit 573/574: чужой PWA Blade failure; full suite memory exhaustion в чужом PWA middleware; Rector — только два чужих collection-файла |
| Managed docs in shared tree | `unresolved: foreign PWA migration` | Временный managed refresh/docs profile GREEN; `docs/MAINTENANCE_LOG.md` восстановлен с SHA-256 `49803a8a17684d0affb459164c3b1bb7f417f04aa281da7484a3f1a2aabbfda3` и не входит в scope |
| Concurrent commit reconciliation | `completed` | Commits `6660dac` и `8f12e95` проверены по path list; пересекающиеся `README.md`, `CHANGELOG.md` и current plan сохранены, isolated index содержит ровно 19 объявленных Task 102 paths относительно нового HEAD |
| Shared index recovery evidence | `completed` | После диагностической ошибки выбора index shared index немедленно восстановлен в исходное дерево `b6703cc571906542b69cd394eab7d89af1b1659e`; worktree не изменялся, дальнейшие staging-команды выполняются только с явным `GIT_INDEX_FILE` |
| Commit exact approved index | `planned` | Только `main`, без force, reset, stash, worktree или foreign paths |
| Push configured remote | `planned` | Обычный push; фактический внешний отказ останется `unresolved` |

## Exact declared files

1. `.githooks/lib/git-guard.sh`
2. `.githooks/pre-commit`
3. `.githooks/pre-push`
4. `AGENTS.md`
5. `CHANGELOG.md`
6. `README.md`
7. `docs/README.md`
8. `docs/development.md`
9. `docs/plans/archive/2026-07-26-shared-main-workflow-evidence.md`
10. `docs/plans/archive/README.md`
11. `docs/plans/current-task-plan.md`
12. `docs/superpowers/plans/2026-07-24-system-maintenance-and-optimization-master-plan.md`
13. `docs/superpowers/plans/2026-07-26-shared-main-workflow.md`
14. `docs/superpowers/specs/2026-07-26-shared-main-workflow-design.md`
15. `scripts/ci-check.sh`
16. `scripts/task-workspace-lease.sh`
17. `tests/Unit/CiQualityGateContractTest.php`
18. `tests/Unit/CurrentPlanPolicyScriptTest.php`
19. `tests/Unit/GitWorkspaceLeaseScriptTest.php`

Discovery, меняющее этот список, требует явной повторной declaration; она
инвалидирует старый index approval.

## Protected compatibility contracts

- существующая ветка и direct-to-`main` history;
- foreign staged/unstaged/untracked files и shared index baseline;
- `SEASONVAR_SKIP_GIT_GUARD=1` только как существующая аварийная boundary;
- branch/conflict/sensitive/temporary/README/CHANGELOG/docs/clean-tree gates;
- публичные routes, route names, JSON shapes, models/relations, migrations,
  translations, cache keys и application behavior;
- production DB, storage, queues, workers, scheduler, cache и built assets;
- raw lease token, credentials, private paths и file contents не выводятся и
  не попадают в tracked evidence.

## Cross-feature impact

| Domain | Status | Evidence |
| --- | --- | --- |
| Git ownership/staging/commit/push | `in_progress` | Единственный affected domain |
| Documentation/CI | `in_progress` | Current registry и docs profile activation |
| Authentication/authorization/privacy | `not_applicable` | Нет application request/user data |
| Search/SEO/sitemap/notifications | `not_applicable` | Нет runtime/public content change |
| Import/player/Premium/payments/ads/region/legal | `already_compliant` | Public contracts не меняются |
| Database/migrations/indexes/queries | `not_applicable` | Нет DDL/DML/Eloquent/SQL |
| Cache/session/queue/scheduler | `not_applicable` | Нет config/runtime mutation |
| Frontend/mobile/accessibility/translations | `not_applicable` | Нет UI/assets |
| Dependencies/runtime versions | `already_compliant` | Инвентаризированы, не обновляются |
| Production operations | `not_applicable` | Rollout/data/backup не требуются |

## Последнее подтверждённое evidence

- [Lossless pre-Task-102 current-plan snapshot](archive/2026-07-26-shared-main-workflow-evidence.md)
- [Archive index с размером и SHA-256](archive/README.md)
