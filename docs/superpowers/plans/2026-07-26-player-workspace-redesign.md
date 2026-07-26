# Task 104 implementation checklist

Каждый пункт содержит действие, причину, scope/зависимости, риск и проверку.

1. `[completed][critical]` Прочитать AGENTS/index/all applicable canonical
   docs; без этого permanent requirements могут быть потеряны. Scope:
   requirements/UI/player docs. Risk: stale memory. Verify: fresh reads.
2. `[completed][critical]` Проверить PHP/Laravel/Livewire/Tailwind/Plyr/HLS,
   routes/schema/tests/git. Scope: manifests, Boost, repository. Risk:
   version-incompatible API. Verify: actual commands/Boost output.
3. `[completed][critical]` Получить exclusive task lease и объявить paths.
   Risk: чужой workstream. Verify: lease status.
4. `[completed][critical]` Зафиксировать canonical theatre/context/recovery
   contract до code. Risk: conflicting UI rules. Verify: canonical reread.
5. `[completed][critical]` Написать PHP/JS/browser RED contracts. Scope:
   new focused tests + nearby regressions. Risk: brittle selectors. Verify:
   expected failures name missing behavior.
6. `[completed][high]` Добавить `subtitle_language` в обе selected-media
   projections. Dependency: existing schema. Risk: missing FK/id columns.
   Verify: query test and SQL/query inspection.
7. `[completed][high]` Подготовить translation/quality/subtitle labels и compact
   options in view model. Risk: fake subtitle capability. Verify: fixtures
   with/without subtitle metadata.
8. `[completed][high]` Расширить transition payload safe labels, чтобы in-place
   context не устаревал. Risk: internal JS contract regression. Verify:
   transition factory/browser tests.
9. `[completed][high]` Добавить layout markers в title detail без изменения
   route/public variables. Risk: theatre hides player. Verify: rendered HTML.
10. `[completed][high]` Перекомпоновать player Blade в context/media/recovery/
    nav/actions/seasons. Risk: lost report/download/progress action. Verify:
    contract and authenticated/guest renders.
11. `[completed][high]` Сохранить ровно один keyed `wire:ignore`. Risk: Livewire
    morph destroys Plyr. Verify: wire-ignore regression.
12. `[completed][high]` Добавить scoped CSS theatre and state layers with design
    tokens. Risk: global dark leak/nested scroll. Verify: screenshots/computed
    styles.
13. `[completed][high]` Реализовать theatre enter/exit/focus/cleanup in existing
    navigation lifecycle. Risk: stale body class. Verify: static + Playwright.
14. `[completed][high]` Уважить dialog/fullscreen Escape priority. Risk:
    unexpected exit. Verify: keyboard browser scenarios.
15. `[completed][high]` Mirror runtime state, skeleton/status/recovery in
    `player.js`. Risk: false fatal/retry loop. Verify: state tests/browser.
16. `[completed][high]` Открывать translation level существующего menu из
    context/recovery. Risk: duplicate menu state. Verify: focus/menu scenario.
17. `[completed][high]` Sync context labels/active options after in-place
    transition. Risk: stale season/quality. Verify: browser transition.
18. `[completed][medium]` Обновить localized RU/EN copy with placeholder parity.
    Risk: raw keys. Verify: translation contract tests.
19. `[completed][high]` Проверить 320/390/tablet/desktop/landscape, 44 px,
    safe-area, no overlap. Verify: Playwright geometry/screenshots.
20. `[completed][critical]` Проверить XSS/URL/grant/report/auth/PWA boundaries.
    Risk: provider disclosure/IDOR/cache. Verify: scans and focused tests.
21. `[completed][medium]` Проверить queries/N+1/projections/indexes and avoid
    speculative migration. Verify: query tests/schema/EXPLAIN if meaningful.
22. `[completed_with_external_regressions][critical]` Run focused tests, Pint,
    relevant/full PHPUnit, Vite build and Playwright; debug failures
    systematically. Task scope is green; full suite has documented concurrent
    regressions.
23. `[completed][medium]` Reread canonical requirements and close compliance
    matrix with exact evidence/unresolved.
24. `[completed][medium]` Update README visitor history, CHANGELOG and archived
    evidence. Verify: docs checks.
25. `[completed][critical]` Inspect status/diff/stat/untracked/staged, secret/
    debug/format scans and exact lease scope.
26. `[unresolved_external_auth][critical]` Staged exactly 32 Task 104 paths via
    approved alternate index and committed `ed77c9eb` on `main`; ordinary
    `git push origin main` reached the HTTPS remote and failed because
    terminal authentication was unavailable. Lease is released after this
    evidence commit attempt.

Rollback: revert Task 104 commit; no DDL/data/package/cache/route rollback.
