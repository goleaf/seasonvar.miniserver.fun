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
| Import lifecycle | 11 active runs, 8037 pending, 4 reserved, 5670 claims, oldest pending 2117 s | Blocker |
| Health truthfulness | New scoped check reports `failed`: 32821 pending, 4 reserved; cache-warm oldest age 151156 s and import oldest age 3162 s | Code verified; worker restart/cache-warm installation still pending |
| Deployment check | Instrumented full run: 24.45 s wall, SQLite quick/FK 23655 ms, remaining checks 0–303 ms; integrity pass; safe exit 1 for debug, 2 migrations and stale FTS | Finite but expensive; `duration_ms` verified; retain >=30 s operational budget |
| Cache worker | versioned unit exists in Git but no process/installed unit | Blocker for cache-warm readiness |
| Blade passive boundary | 41 `request()` and 1 `config()` calls | Architecture acceptance not met |
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
