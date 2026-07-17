# Отчёт по зависимостям

Проверено: 17.07.2026. Перед любым update сохраняется текущий `composer.lock`/`package-lock.json`; rollback — восстановить оба lockfile из фазового commit, выполнить `composer install` и `npm ci`, затем повторить build/tests.

## Текущее состояние

| Runtime / package | Installed | Latest compatible / decision | Evidence | Status / remaining risk |
| --- | --- | --- | --- | --- |
| PHP | 8.5.8 | 8.5.8 | [Official PHP 8.5 release JSON](https://www.php.net/releases/index.php?json&version=8.5) | Current; FPM and CLI share the same build family |
| Laravel | 13.20.0 | Locked compatible patch | Composer metadata; [official 13.20.0 notes](https://github.com/laravel/framework/releases/tag/v13.20.0) | Current locked Laravel 13 runtime |
| Livewire | 4.3.3 | 4.3.3 | Composer metadata; [official release](https://github.com/livewire/livewire/releases/tag/v4.3.3) | Current; Volt intentionally absent |
| `wddyousuf/eloquent-autocache` | 0.2.3 | `^0.2.3` controlled updates | Composer metadata; package source and MIT `LICENSE` | Laravel 13 compatible; production scope limited to opt-in Country/Genre filter queries |
| Tailwind CSS / Vite integration | 4.3.2 / 4.3.2 | Same | npm metadata; [official Vite setup](https://tailwindcss.com/docs/installation/using-vite) | Current and already CSS-first |
| Vite | 8.1.4 | 8.1.4 | npm metadata; [Vite 8 migration](https://vite.dev/guide/migration.html) | Current; Node requirement satisfied |
| Laravel Vite plugin | 3.1.0 | 3.1.3 | `npm outdated`; [official 3.1.3 release](https://github.com/laravel/vite-plugin/releases/tag/v3.1.3) | Planned isolated npm patch update |
| Node.js | 26.4.0 | 26.5.0 Current | [official release index](https://nodejs.org/en/blog/) | Server patch update deferred to system package/runtime procedure; project builds now |
| npm | 12.0.1 | 12.0.1 | [npm registry latest](https://registry.npmjs.org/npm/latest) | Current; global `--init.module` warning is host config, not repository config |
| Composer | 2.10.2 | 2.10.2 | [official download page](https://getcomposer.org/download/) | Current |
| PHPUnit | 12.5.31 | 13.2.4 major available | Laravel 13 guide specifies PHPUnit 12; current suite green | Reject major during this modernization pass |

Laravel 13 requires PHP >=8.3 and recommends compatible `^13`, Boost `^2`, Tinker `^3`, PHPUnit `^12`; current constraints comply with the [official upgrade guide](https://laravel.com/docs/13.x/upgrade). Vite 8 requires Node 20.19+ or 22.12+; Node 26 satisfies the [official requirement](https://vite.dev/blog/announcing-vite8).

## Package decisions

| Candidate | Existing equivalent / problem | Decision | Operational/security/performance impact | Rollback |
| --- | --- | --- | --- | --- |
| `larastan/larastan` | Already installed; full scope has 547 findings | Keep; expand scope incrementally | Dev-only; no runtime cost; no broad baseline | Revert config batch |
| `rector/rector` | Not installed; no controlled ruleset yet | Reject for now | New dev dependency adds churn before static debt is reduced | No install performed |
| Pest / plugins | PHPUnit 12 has 826 tests | Reject migration | Overlapping framework and unnecessary rewrite | None |
| Dusk | Playwright + axe already cover critical browser journeys | Reject overlapping browser stack | Avoid duplicate browser dependencies/runtime | None |
| Telescope | Pail and targeted query instrumentation exist | Defer; only restricted development if a measured diagnostic gap remains | Stores sensitive request/query data | Remove package/provider/tables if later trial rejected |
| Pulse | No production metrics UI; health/metrics already partial | Evaluate after queue/readiness fixes | Requires storage/retention/access-control design | Remove package/migration/provider |
| Horizon | Redis queues exist, but workers are systemd-managed | Evaluate after lifecycle repair | Useful visibility, but changes queue operations and access surface | Return to systemd workers |
| Octane | Traditional PHP-FPM; no benchmark or long-lived audit | Reject now | High state-leak/Livewire risk without evidence | Not installed |
| Reverb | No real-time product requirement | Reject | Extra daemon/network surface | None |
| Sanctum | Already required by mobile API | Keep | Token hashing/abilities/expiry already tested | Not removable without API migration |
| `wddyousuf/eloquent-autocache` | Repeated bounded public Country/Genre filter reads | Keep in strict `opt-in` mode | 300 s TTL; array/scalar payloads; automatic model-version invalidation; no private data, tags, row cache or transaction caching | Set `AUTOCACHE_ENABLED=false`, rebuild config and gracefully reload before a separate package removal |
| Pennant | No staged feature rollout requirement | Reject | Avoid unused tables/abstraction | None |
| `hls.js` | Native HLS fallback required for MSE browsers | Keep lazy 331.9 kB chunk | Loaded only for compatible non-native playback | Revert package/player import |
| Prettier / ESLint | Two focused JS modules, existing build/browser checks | Defer until an actual style/defect gap is measured | Adds dev dependencies and config | Remove config/package |

## Controlled update gates

1. Search for affected framework APIs and review official notes.
2. Commit/record pre-update lock hashes and working baseline.
3. Update Laravel patch separately from frontend patch.
4. Run Composer validation/audit/platform requirements, Pint, bounded + changed-scope Larastan, focused tests and full PHPUnit.
5. Update Vite plugin separately; run `npm ci`, audit, production build, asset-size comparison and full browser suite.
6. Revert the isolated lockfile commit if any behavior, asset or compatibility gate regresses.

Current audits: Composer 122 locked packages and npm 113 lock packages; `composer audit --locked` and npm security audit report zero advisories. `wddyousuf/eloquent-autocache` is the only new runtime dependency in this integration and is covered by focused dependency and lifecycle tests.
