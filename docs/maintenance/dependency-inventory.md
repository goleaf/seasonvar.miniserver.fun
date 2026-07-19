# Канонический реестр прямых зависимостей

Аудит: 18.07.2026; дополнено 19.07.2026 после установки Laravel Debugbar. Источники: `composer.json`, `composer.lock`, `composer show --direct --format=json`, `composer licenses --format=json`, `package.json`, `package-lock.json`, `npm ls --depth=0`, package manifest и полный repository usage search. Основные таблицы содержат только direct dependencies; transitive packages упоминаются лишь там, где они образуют production boundary. Версия означает состояние lock-файла, а не рекомендацию обновиться.

## Composer: назначение и решение

| Package | Installed / constraint | Scope | Purpose and primary modules | Configuration / integration | Decision |
| --- | --- | --- | --- | --- | --- |
| `laravel/framework` | `13.20.0` / `^13.20` | production | HTTP, Eloquent, auth, validation, cache, queue, mail, filesystem, console; весь портал | `bootstrap/app.php`, `config/*.php`, application providers | retain; canonical framework |
| `laravel/sanctum` | `4.3.2` / `^4.3` | production | Mobile/API token authentication and abilities | `config/sanctum.php`, API middleware, `User::HasApiTokens` | retain; required by mobile API |
| `laravel/tinker` | `3.0.2` / `^3.0` | production/operations | Controlled local REPL for repository operators; not exposed to HTTP or admin | auto-discovered command only | retain; review production install policy before any removal |
| `livewire/livewire` | `4.3.3` / `^4.3` | production | All full-page interactive HTML, pagination, uploads, navigation and server state | `config/livewire.php`, `app/Livewire`, hashed endpoints, `resources/js/app.js` | retain; canonical UI state boundary; no Volt |
| `wddyousuf/eloquent-autocache` | `0.2.4` / `^0.2.3` | production | Opt-in cache for the bounded public Country/Genre Top lists only | `config/autocache.php`, `CachesCatalogFilterOptions` | retain; isolated and disableable fallback |
| `driftingly/rector-laravel` | `2.5.0` / `^2.5` | development | Laravel-aware Rector rules | `rector.php`, `rector-max.php` | retain; static upgrade audit |
| `fakerphp/faker` | `1.24.1` / `^1.23` | development | Factories and existing fixture/test data only | `database/factories`, tests | retain; no runtime bundle |
| `fruitcake/laravel-debugbar` | `4.4.0` / `^4.4` | development | Trusted local HTTP, SQL, view, request and Livewire diagnostics | auto-discovery, minimal `config/debugbar.php`; enabled only by `APP_DEBUG` outside `production|testing` | add; optional development diagnostics, excluded by production `--no-dev` |
| `larastan/larastan` | `3.10.0` / `^3.10` | development | Laravel-aware PHPStan analysis | `phpstan.neon.dist`, `composer analyse` | retain; bounded static gate |
| `laravel/boost` | `2.4.13` / `^2.4` | development | Laravel/Codex development context and diagnostics | auto-discovered development provider and commands | retain; development only |
| `laravel/pail` | `1.2.7` / `^1.2.5` | development | Local log tailing in `composer dev` | auto-discovered `pail` command | retain; development only |
| `laravel/pao` | `1.1.2` / `^1.0.6` | development | Agent-oriented output around existing PHP diagnostics | package auto-discovery | retain; development only |
| `laravel/pint` | `1.29.3` / `^1.27` | development | Canonical PHP formatter | `./vendor/bin/pint`, development workflow | retain |
| `mockery/mockery` | `1.6.12` / `^1.6` | development | Existing test doubles | existing tests | retain; tests were not run in Task 29 |
| `nunomaduro/collision` | `8.9.5` / `^8.6` | development | Development CLI exception rendering | auto-discovered development provider | retain |
| `phpunit/phpunit` | `12.5.31` / `^12.5.12` | development | Existing PHPUnit 12 test infrastructure | `phpunit.xml`, tests | retain; major 13 deferred |
| `rector/rector` | `2.5.7` / `^2.4` | development | Required and maximum read-only modernization profiles | `rector.php`, `rector-max.php` | retain |

## npm: назначение и решение

| Package | Installed / constraint | Scope | Purpose and primary modules | Configuration / integration | Decision |
| --- | --- | --- | --- | --- | --- |
| `@fortawesome/fontawesome-free` | `7.3.0` / `^7.3.0` | production asset | Local UI icons without third-party runtime requests | CSS imports in `resources/js/app.js` | retain; `7.3.1` patch deferred with frontend group |
| `hls.js` | `1.6.16` / `^1.6.16` | production asset | HLS fallback where native HLS is unavailable | dynamic import in `resources/js/player.js` | retain; player boundary |
| `plyr` | `3.8.4` / `^3.8.4` | production asset | Canonical accessible player controls | dynamic import and local CSS in player module | retain; do not introduce competing player |
| `@axe-core/playwright` | `4.12.1` / `^4.12.1` | development | Existing browser accessibility assertions | Playwright specs/config | retain; no runtime bundle |
| `@playwright/test` | `1.61.1` / `^1.61.1` | development | Existing Chromium browser QA infrastructure | `playwright.config.js`, `npm run test:browser` | retain; tests were not run in Task 29 |
| `@tailwindcss/vite` | `4.3.2` / `^4.0.0` | development/build | Tailwind 4 Vite integration | `vite.config.js` | retain; `4.3.3` patch deferred with Tailwind group |
| `concurrently` | `9.2.4` / `^9.0.1` | development | Orchestrates server, worker, Pail and Vite in `composer dev` | Composer `dev` script | retain; major 10 deferred |
| `laravel-vite-plugin` | `3.1.3` / `^3.1` | development/build | Laravel manifest/HMR bridge | `vite.config.js`, Blade `@vite` | retain |
| `tailwindcss` | `4.3.2` / `^4.0.0` | development/build | Canonical CSS utility/compiler system | CSS-first `resources/css/app.css` | retain; `4.3.3` patch deferred with visual QA |
| `vite` | `8.1.4` / `^8.0.0` | development/build | Production asset build, manifest and code splitting | `vite.config.js`, npm scripts | retain; `8.1.5` patch deferred with build/runtime review |

## Operational effects per direct package

`Routes/commands/migrations` lists package-owned effects, not project features built on the package. “None” means full search found no package-specific effect in that category.

| Package | Routes / commands / migrations | Bundle and runtime | Mandatory / replacement difficulty | Removal condition and compatibility limitation | Maintenance / license / owner |
| --- | --- | --- | --- | --- | --- |
| `laravel/framework` | `/up` health plus framework commands; application owns 101 migrations | PHP `^8.3`; all requests and CLI | mandatory / very high | only staged major framework migration preserving every public contract | active installed line; MIT; architecture/operations |
| `laravel/sanctum` | `/sanctum/csrf-cookie`, `sanctum:prune-expired`; project migration owns `personal_access_tokens` | no frontend bundle; token/session runtime | mandatory / high | replacement must migrate tokens, abilities, pruning and mobile auth | audit clean; MIT; authentication/API |
| `laravel/tinker` | `tinker`; no routes/migrations | CLI only | optional operations / low code, medium workflow | prove no runbook/operator dependency and remove config/provider/docs | audit clean; MIT; development/operations |
| `livewire/livewire` | hashed asset/update/upload/preview routes and generator/config commands; no project data migration | package browser runtime plus hydration requests | mandatory / very high | only full page/state/navigation migration; preserve hashed endpoint/firewall contract | audit clean; MIT; frontend/Livewire |
| `wddyousuf/eloquent-autocache` | `autocache:clear|flush|stats|warm`; no routes/migrations | server cache only; no bundle | optional with fallback / medium | disable, rebuild config, reload processes, prove Country/Genre query fallback, then remove trait/config/cache namespace | audit clean, not abandoned; MIT; catalog/cache |
| `driftingly/rector-laravel` | `rector` rules via vendor binary; no runtime effects | development PHP memory/process | optional gate / low | replace rules/config and prove required profile parity | audit clean; MIT; development |
| `fakerphp/faker` | none | development only | required by existing factories / low | usage-free factories/tests or compatible generator migration | audit clean; MIT; testing |
| `fruitcake/laravel-debugbar` | `_debugbar/*` routes and four local diagnostic commands only while the package guard can boot; no migrations | development HTTP collectors and package-served assets; no Vite entry | optional / low | remove direct package/config, rebuild autoload/config and prove local app boots; production already installs `--no-dev` | audit clean on 19.07.2026; MIT; development diagnostics |
| `larastan/larastan` | `phpstan` integration; no runtime effects | development analysis memory | required by static gate / medium | replace only with equivalent Laravel type coverage | audit clean; MIT; development |
| `laravel/boost` | Boost/MCP development commands; no intended production route | auto-discovered development tooling | optional / low | remove project/Codex integrations and docs together | audit clean; MIT; development |
| `laravel/pail` | `pail`; no route/migration | development log reader | optional / low | remove from `composer dev` and operator docs | audit clean; MIT; development |
| `laravel/pao` | diagnostic integration; no app routes/migrations | development output only | optional / low | prove no script/provider dependency | audit clean; MIT; development |
| `laravel/pint` | formatter binary | development only | required workflow / low | adopt documented equivalent formatter with zero style drift | audit clean; MIT; development |
| `mockery/mockery` | none | development only | existing test dependency / medium | migrate every existing mock without changing test semantics | audit clean; BSD-3-Clause; testing |
| `nunomaduro/collision` | CLI rendering provider | development CLI only | optional / low | verify console/debug output remains usable | audit clean; MIT; development |
| `phpunit/phpunit` | `phpunit` binary | development only | protected test infrastructure / high | separately migrate suite/config/extensions; Task 29 cannot prove PHPUnit 13 | audit clean; BSD-3-Clause; testing |
| `rector/rector` | `rector` binary | development analysis only | required static gate / medium | preserve both required and maximum rule inventories | audit clean; MIT; development |
| `@fortawesome/fontawesome-free` | no routes/commands/migrations | global local CSS/font assets; public bundle impact | mandatory current icons / medium | replace every icon name/import and confirm licensing/visual/accessibility parity | audit clean; CC-BY-4.0 + OFL-1.1 + MIT; UI |
| `hls.js` | none | lazy player chunk; browser Media Source requirement | mandatory for non-native HLS / high | prove all supported browsers use native HLS or staged adapter replacement | audit clean; Apache-2.0; player |
| `plyr` | none | lazy player JS plus CSS | mandatory canonical player / high | application-owned player adapter migration with control/accessibility/state parity | audit clean; MIT; player/UI |
| `@axe-core/playwright` | npm QA command only | development, excluded from public bundle | optional QA / medium | preserve equivalent automated accessibility assertions | audit clean; MPL-2.0; QA/accessibility |
| `@playwright/test` | `playwright` CLI | development browser binaries only | protected QA infrastructure / high | migrate config/spec/reporting and browser install workflow separately | audit clean; Apache-2.0; QA |
| `@tailwindcss/vite` | build plugin | build-time; affects generated CSS | mandatory build / medium | only coordinated Tailwind/Vite migration with CSS parity | audit clean; MIT; frontend/build |
| `concurrently` | invoked by `composer dev` | development process orchestration | optional / low | replace `composer dev` orchestration and signal/exit behavior | audit clean; MIT; development |
| `laravel-vite-plugin` | build/HMR plugin | manifest/HMR and Blade asset resolution | mandatory build / high | coordinated Laravel/Vite integration migration preserving manifest/base URLs | audit clean; MIT; frontend/build |
| `tailwindcss` | compiler CLI through Vite | generated public CSS; no browser runtime | mandatory build / high | complete class/content scan, responsive/a11y/long-label/browser review | audit clean; MIT; UI/build |
| `vite` | `vite`, `vite build` | build runtime; generated hashed assets | mandatory build / high | coordinated plugin/Node/deployment/manifest/source-map migration | audit clean; MIT; frontend/build |

## Transitive boundaries that matter

- Laravel resolves Symfony 8.1 components, Guzzle 7.15, Flysystem 3.35, CommonMark 2.8, Monolog 3.10 and Carbon 3.13. They are not independent direct decisions; a framework update must inspect their relevant mail, HTTP, storage, Markdown, logging and date contracts.
- `fruitcake/laravel-debugbar` 4.4.0 adds development-only `php-debugbar/php-debugbar` 3.8.0 and `php-debugbar/symfony-bridge` 1.1.0. Both are MIT and share the same local-debug/no-production purpose; they are not separate application integration decisions.
- No direct payment, OAuth, external search, image-processing, Redis-client or Memcached-client Composer package is installed. Payment providers are unconfigured, search uses application/Eloquent/SQLite FTS boundaries, images use installed PHP Imagick/GD extensions, and cache clients are PHP extensions/runtime services.
- Composer plugin policy has no installed or locked plugin. Task 29 removed stale permissions for `pestphp/pest-plugin` and `php-http/discovery`; any future plugin needs its own purpose/security/scripts decision before permission is granted.
- Composer auto-discovery registers framework/dev providers plus Sanctum, Tinker, Livewire and AutoCache. Application providers remain exactly `AppServiceProvider`, `ApiServiceProvider` and `SeasonvarQueueServiceProvider`; no duplicate registration was found.

## Application command audit

| Classification | Commands | Maintenance decision |
| --- | --- | --- |
| Read-only diagnostics | `app:deployment-check`, `app:failed-job-audit`, `app:health`, `cache:metrics`, `google:analytics:summary`, `google:search-console:summary`, `integrations:doctor`, `seasonvar:import --status|--inventory-only`, `project:docs-refresh --check` | Retain; bounded output, no browser shell, secrets redacted |
| Bounded maintenance/retention | `api:sync-prune`, `catalog-collections:prune`, `technical-issues:prune-private-data`, `cache:warm-catalog`, `catalog:search-rebuild`, `project:docs-refresh` | Retain; explicit limits/locks/runbooks; mutation modes are operator workflows |
| Canonical import/synchronization | `seasonvar:import`, `catalog-collections:sync-hdrezka` | Retain with existing allowlists, idempotency, locks and single Seasonvar public command contract |
| Auxiliary diagnostic importer | `zona:import` | Retain as existing bounded operator-only metadata/table workflow; it is not a second Seasonvar command or admin/browser action |
| Destructive framework commands | `cache:clear`, migration/queue destructive families provided by framework | Never exposed to browser/admin; production destructive database commands remain prohibited and task policy requires explicit approval |
| Removed scaffold | `inspire` | Removed in Task 29 after repository-wide search found no consumer, documentation, data or operational purpose |

Seven scheduler entries remain bounded and named; no cron, queue, provider call or external HTTP executes during service-provider boot. Package commands are listed in the operational package table above.

## Lock and licensing policy

- The four dependency files were hashed before maintenance work and are protected from unrelated rewrite. Task 29 changed no package version or lock entry; the separate 19.07.2026 Debugbar decision adds exactly three Composer lock entries with no update or removal.
- `composer audit --locked` reports no advisory, malware-policy or abandoned-package record. `npm audit` reports zero known vulnerabilities across the 113 locked npm dependencies. These are dated tooling results, not a permanent safety claim.
- Direct Composer licenses are MIT except Mockery/PHPUnit (BSD-3-Clause). Direct npm licenses are documented above; FontAwesome attribution/license obligations and axe-core MPL-2.0 must remain in any distribution/legal review.
- A successful install or clean advisory command never replaces feature, production, privacy, accessibility or rollback verification.
