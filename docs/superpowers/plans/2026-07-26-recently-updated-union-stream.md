# План реализации единого потока «Недавно обновлённые»

> **Для выполнения:** использовать TDD и выполнять inline в существующей
> `main`; sub-agents, branch, worktree и PR не создаются.

**Goal:** убрать второй SQL round trip, PHP merge/sort и materialization
полного event window в `recently_updated`, сохранив exact ranking и все
access/cache/SEO contracts.

**Architecture:** два прежних bounded source builder становятся bounded
subqueries одного `UNION ALL`; внешний query владеет deterministic order,
`cursor()` лениво собирает ordered unique title IDs, существующий
`eligibleOrderedIds()` остаётся canonical final boundary.

**Stack:** PHP 8.5.8, Laravel 13.22.0 Query Builder, SQLite 3.46.1,
PHPUnit 12.5.32, Pint 1.29.3.

**Design:** [2026-07-26-recently-updated-union-stream-design.md](../specs/2026-07-26-recently-updated-union-stream-design.md).

## Expected changed files

- `app/Services/Catalog/CatalogPublicDiscoveryQuery.php`;
- `tests/Feature/CatalogDiscoveryQueryBudgetTest.php`;
- `docs/performance.md`;
- `docs/plans/current-task-plan.md`;
- discovery sections end-to-end master plan;
- этот implementation plan и design;
- `README.md` после подтверждённого visitor performance change;
- `CHANGELOG.md` отдельным русским пунктом.

Не ожидаются migration, route, translation, cache, permission, config,
dependency, JavaScript, CSS, Blade или environment changes.

## Protected contracts

- `/discover/recently_updated`, localized route и public API/home consumers;
- enum/source/reason/score/rank и page contracts;
- per-source bounded windows и exact deterministic merge;
- common visibility/watchability, filters, exclusions и private state;
- cache namespace/dimensions/invalidation/warming/fallback;
- canonical/noindex/sitemap and UI responsive behavior;
- importer/media/episode write semantics and existing indexes;
- all foreign shared-worktree files and hunks.

## Risks and rollback

| Risk | Gate |
| --- | --- |
| `LIMIT` accidentally applies after union | Each source is wrapped before union; mixed-volume fixture |
| bindings reordered or literal exposed | Query Builder bindings plus query-shape test |
| episode/media equal-time order changes | Exact tie regression |
| source soft-deletion/publication semantics drift | Fixture covers deleted/draft/future rows |
| cursor changes unique order | Exact legacy/prototype hash and focused test |
| common entitlement/filter/exclusion bypass | Existing builder retained; focused regressions |
| DB portability | Framework builders only; no SQLite-only SQL |
| concurrent foreign edits | Exact-file/hunk audit; no foreign stage/reset/stash |

Rollback is a code/docs revert. No data restore, migration, cache flush,
reindex or worker restart beyond normal PHP deployment is needed.

## Execution checklist

1. `[completed]` Fresh root/index/canonical requirement and relevant feature
   documentation read.
2. `[completed]` Actual runtime/package/database and shared Git state checked.
3. `[completed]` Existing route/service/query/cache/test call graph inspected.
4. `[completed]` Production read-only baseline and exact union prototype
   compared.
5. `[completed]` Three approaches evaluated; bounded union selected under
   repeated explicit user authorization.
6. `[completed]` Design, expected files, protected contracts, risks and
   rollback documented.
7. `[completed]` Re-read this plan, then add the smallest failing semantic and
   one-statement query-shape regression.
8. `[completed]` Run focused test and capture expected RED only on the missing
   union contract.
9. `[completed]` Implement source builders, bounded union and lazy ordered
   unique-ID collection.
10. `[completed]` Run focused GREEN, Pint and task-scoped static analysis.
11. `[completed]` Re-run production read-only exact parity, SQL count/time and
    peak-memory observations in isolated processes.
12. `[completed]` Run related recommendation/discovery tests and appropriate
    broad gates.
13. `[completed]` Update canonical performance documentation, visitor README,
    Russian CHANGELOG and compliance evidence.
14. `[completed]` Re-read applicable requirements; search legacy duplicate
    event merge, stale docs/cache paths, debug output and unfinished code.
15. `[unresolved_shared_tree]` Exact diff and `main` audited. Canonical docs
    profile, and therefore pre-commit, is blocked by foreign managed
    `docs/MAINTENANCE_LOG.md` drift. No foreign refresh/stage/reset, mixed
    commit, hook bypass or push is performed.

## Requirement-compliance matrix

| Requirement/domain | Status | Evidence / next gate |
| --- | --- | --- |
| Fresh mandatory requirements | `completed` | 26.07.2026 before edits |
| Actual versions and official docs | `completed` | Boost app info and Laravel 13 query docs |
| Existing implementation first | `completed` | Query/event/eligibility/cache/tests traced |
| Evidence-backed need | `completed` | Exact hash; 2→1 SQL, 84.54→37.94 ms, 42→30 MiB diagnostic |
| Alternatives and design approval | `completed` | Three approaches; repeated explicit authorization |
| TDD | `completed` | RED 1/4 on statement count and RED 1/1 on legacy empty date; GREEN 2 tests/15 assertions |
| Database/schema/index/DML | `not_applicable` | Read-only code change only |
| Routes/API/cache/permissions | `already_compliant` | Related web/API/cache/sitemap matrix GREEN |
| Filters/access/security/privacy | `already_compliant` | Existing final builder remains owner; exclusions/privacy regressions GREEN |
| Localization/UI/mobile/a11y | `not_applicable` | No copy or presentation change |
| Production operations | `completed` | Same-snapshot parity, existing-index plan and code-only rollback verified |
| Documentation/README/CHANGELOG | `completed` | Performance/master/current plans, visitor notes and Russian changelog updated |
| Related verification | `completed_with_foreign_docs_limit` | 93 tests/588 assertions, Pint, scoped PHPStan/Rector and diff check GREEN; managed-doc check blocked only by foreign maintenance-log drift |
| Final audit | `completed` | Requirements/task/diff reread; legacy/debug/secret/stale-path scans clean for Task 80 |
| Commit/push | `unresolved_shared_tree` | `bash scripts/ci-check.sh docs` fails only on foreign managed maintenance-log drift; exact commit cannot pass hooks and push is not run |
