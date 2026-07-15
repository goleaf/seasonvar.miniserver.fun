# Verification report

Проверено: 15.07.2026 на `main` commit `70df36b` до новых modernization changes. Этот документ фиксирует baseline, а не заявляет завершение production rollout.

## Passed baseline

| Gate | Result |
| --- | --- |
| `php artisan test` | 826 tests; 815 passed, 11 skipped; 6751 assertions; 93.554 s |
| Focused browser CI contract | 2 tests, 41 assertions |
| `PLAYWRIGHT_RUNTIME_NAME=audit-baseline npm run test:browser` | 18/18 passed; 1.4 min |
| `./vendor/bin/pint --test --format agent` | pass |
| PHP syntax lint | exit 0 |
| Configured `vendor/bin/phpstan analyse` | 0 diagnostics |
| Full `app/` Larastan level 6 | 547 diagnostics; not suppressed, modernization backlog |
| `composer validate --strict` | pass; root/plugin warning only |
| `composer audit --locked` | 0 advisories |
| `npm audit --audit-level=high` | 0 vulnerabilities |
| `npm run build` | pass, Vite 8.1.4 in 2.51 s |

Build assets: app CSS 154.51 kB / 32.90 gzip; app JS 8.51/3.61; player JS 11.73/3.84; Plyr lazy 111.81/32.86; HLS light lazy 331.90/104.61.

## Production checks that are not green

| Check | Exact evidence | Status |
| --- | --- | --- |
| Environment | production, debug enabled | Blocker; environment owner action required |
| Laravel caches | config/events not cached; routes/views cached | Blocker after safe environment setup |
| Migrations | two pending | Blocker until writer stop + verified backup |
| Import lifecycle | Baseline 11 active runs/8037 pending/5670 claims; later read-only snapshot: 12 running sitemap runs, 4155 failed group finalizers, 793 page jobs, 9 preparation jobs and 1601 active groups | Code fixed; rollout/reconciliation blocker remains |
| Health truthfulness | New scoped check reports `failed`: 32821 pending, 4 reserved; cache-warm oldest age 151156 s and import oldest age 3162 s | Code verified; worker restart/cache-warm installation still pending |
| Deployment check | Instrumented full run: 24.45 s wall, SQLite quick/FK 23655 ms, remaining checks 0–303 ms; integrity pass; safe exit 1 for debug, 2 migrations and stale FTS | Finite but expensive; `duration_ms` verified; retain >=30 s operational budget |
| Cache worker | versioned unit exists in Git but no process/installed unit | Blocker for cache-warm readiness |
| Blade passive boundary | Baseline 41 `request()` + 1 `config()`; current strengthened scan has zero request/config/auth/gate/facade/container/application-static violations across 52 files | Code, full PHPUnit and 21/21 browser scenarios verified |
| Public storage | link absent | Verify need; not automatically a blocker |

## Browser evidence

The deterministic suite covers guest and authenticated portal, registration/login/private navigation, saved player progress/Continue Watching, catalog URL/filter state, route taxonomy removal, directory alphabet/search, title player shell, desktop/mobile geometry and accessibility assertions. Same-origin request failures and console errors fail the suite; expected aborted Livewire poll during navigation is recognized only by parsed poll method metadata.

## Safety

No `.env` edit, destructive database command, queue clear, failed-job payload dump, media download, new production dependency, branch/worktree or force push occurred in the baseline phase. Concurrent unrelated working-tree changes are excluded from these results until their own RED/GREEN cycle completes.

## Verified modernization increment

- Queue health RED/GREEN: separate backlog without a matching pool heartbeat fails; an idle loop records liveness for every listened queue.
- `QueueWorkerObservabilityTest` + `InfrastructureHealthCheckTest`: 7 passed, 49 assertions, включая expiry regression, которая запрещает memoized stale heartbeat.
- Deployment timing RED/GREEN: every JSON check has a non-negative integer `duration_ms`; text output also shows the duration without diagnostic details.
- CLI/HTTP policy regression: operational `app:health` exits 1 for `degraded`, while `/health/ready` remains HTTP 200 when critical traffic transports are ready; response remains private/no-store and path-free.
- Combined preflight/queue/health increment: 12 passed, 88 assertions; changed-scope Larastan 0; targeted Pint pass.
- Live read-only `app:health --json`: `degraded`, `ready=true`, exit 1; queue component `failed`, 32325 pending across four named queues and no secret/path leakage. Current long-running workers still run pre-rollout code, so missing scoped heartbeat remains expected until restart.
- Import lifecycle RED/GREEN: admin/CLI/repeated dispatch shares one atomic active global run while targeted title refresh remains independent; rejected dispatch becomes a sanitized terminal failure.
- Finalizer RED/GREEN: incomplete siblings/live claims return without polling release; terminal page/group completion signals deduplicated `ShouldBeUniqueUntilProcessing` finalizers; unique ten-minute watchdog restores lost signals in bounded batches.
- Importer increment: 52 tests, 266 assertions; changed model/job/service Larastan 0 with `--memory-limit=1G`; targeted Pint pass; `schedule:list` confirms `*/10 * * * * seasonvar-import-finalization-watchdog`.
- Passive Blade increment: shared typed navigation state removes route/auth/config decisions from templates; 42 shell/auth/Blade tests / 339 assertions, broader 120/1126 catalog set and full 840/6882 suite pass; `view:cache`, Vite build and changed-scope Larastan pass. The 21/21 desktop/mobile/tablet browser matrix passes. Build output: app CSS 156.01/33.09 gzip, app JS 8.51/3.61; lazy player chunks unchanged.
- Layout/SEO increment: unreachable generated matrices removed after repository-wide flag/consumer tracing; explicit contract and prepared `Js::encode()` JSON-LD reduce AppLayoutData/layout from 1,928/783 to 411/96 lines. Same 100-iteration rich-payload harness improved median 23.894→0.536 ms and p95 25.323→0.834 ms; focused 130/1,198, full 848/6,928, changed-scope Larastan 0, compiled views, production build and 21/21 browser matrix pass. Build output: app CSS 155.97/33.09 gzip and app JS 8.51/3.61; player chunks unchanged.

## Task 10 collection verification (no automated tests)

The task explicitly prohibited creating or running automated tests, so its acceptance used static inspection, isolated schema/query exercises and manual Chromium flows only. No PHPUnit, Pest or Playwright test runner was invoked for this increment.

| Gate | Fresh evidence |
| --- | --- |
| Canonical routes | Isolated `route:list` exposes 14 web/API/sitemap/admin/localized collection routes plus the four documented `/lists`, `/my/lists` and `/selections` compatibility routes; owner mutations remain Livewire POST actions behind authenticated policy checks. |
| Domain static analysis | Collection DTOs/enums/controllers/requests/resources/Livewire/models/policy/services pass focused Larastan with 0 diagnostics. The final run caught and fixed singular taxonomy relation names before acceptance. |
| Syntax and formatting | Every changed or untracked PHP/Blade file passes `php -l`; `pint --dirty --format agent` exits 0. |
| Translation and template boundaries | `lang/en/collections.php` and `lang/ru/collections.php` each contain 228 leaf keys with zero missing keys or named-placeholder mismatches. Collection Blade contains no `@php`, inline style/script, model query, facade, `auth()`, `config()` or `request()` call; collection JavaScript contains no console/debug/TODO marker. |
| API contract | OpenAPI 3.1 JSON parses and declares the public collection directory, collection item page and title-to-collections endpoints. API pagination uses the conventional `page` key and resources expose opaque owner/collection IDs only. |
| Disposable schema | A fresh isolated SQLite applied the entire migration chain through `2026_07_15_235000`; manual collection queries for genre, country and status filters plus all option groups returned normally. Collection migrations then rolled back successfully: zero `catalog_collection%` tables, zero collection migration rows and no `users.public_id` remained. The wider historical rollback subsequently stopped at the unrelated released importer migration `2026_07_09_204238`; Task 10 does not rewrite that old migration. |
| Query plans and merge | Representative SQLite plans use the owner/public ordering, item manual/title lookup, translation locale/name and report queue indexes; public counts and membership use grouped/existence queries rather than per-card reads. A disposable two-collection title merge retained the earliest timestamp/lowest position, moved the duplicate-only row, normalized both positions and left zero duplicate-title memberships through bounded `eachById(500)`. |
| Framework compilation | Isolated configuration and routes cache successfully; all Blade templates compile through `view:cache`. |
| Production assets | Vite 8.1.4 builds successfully: app CSS 167.03 kB / 34.94 gzip and app JS 14.59 / 5.27 gzip; lazy player/Plyr/HLS chunks remain split. |
| Manual browser acceptance | Isolated Chromium covered guest/owner/second-user authorization; create/edit/sanitize/rename/history redirect; private/public/unlisted/editorial pages; staged multi-membership and create-and-add; duplicate apply; remove/re-add; manual move; soft-delete/restore; filters/no-results/pagination URL back-forward; report/deduplication; directory/profile eligibility; canonical/robots/JSON-LD/hreflang; desktop and 390×844 dialog/focus/overflow. Console, page and request failure counts were zero. |
| Cache/privacy evidence | Private pages and covers returned `private, no-store` and unauthorized requests returned safe 404s without name/count/email/internal-ID leakage. Public directory/profile variants use the collection version domain; the version advanced narrowly from 8 to 9 after an isolated-process namespace collision, with no global cache flush. |
