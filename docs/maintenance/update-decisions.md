# Реестр решений об обновлениях

Аудит: 18.07.2026. Решение относится к точным lock-файлам Task 29. `retain` не означает бессрочную заморозку: оно означает отсутствие достаточной причины менять dependency в этом change set. Отдельная запись `UD-C-017` от 19.07.2026 фиксирует последующую ограниченную установку Laravel Debugbar и не изменяет исторические выводы Task 29.

## Общий contract записей

Для каждой строки ниже:

- direct/transitive scope указан в dependency inventory; все записи здесь direct;
- security relevance проверена `composer audit --locked` или `npm audit`, оба результата — zero known advisories на дату аудита;
- package/runtime versions не изменяются; единственное configuration изменение — удаление двух неиспользуемых Composer plugin permissions в `UD-CFG-001`; frontend candidates отдельно перечисляют потенциально затрагиваемые assets;
- backward compatibility сохраняется неизменными manifest/lock/public contracts, а config correction не затрагивает installed или locked package;
- migration отсутствует; rollback для Task 29 — возврат documentation/UI-component commit, без schema/cache/session/job/provider transition;
- verification включает lock/manifest inspection, direct usage search, provider/route/command/assets review и перечисленные в текущем плане static/build/browser gates;
- canonical Task 29 implementation находится в `fa4d09f503d717fc737955902585737f34cf713a`; повторный audit/config integration вошёл в `eb4e7f9e7dcf300328b35c527f65a39a743c2ebe` и опубликован в `origin/main`.

## Все прямые Composer decisions

| Record | Dependency | Current / evaluated | Decision | Reason | Affected modules / production considerations | Limitation |
| --- | --- | --- | --- | --- | --- | --- |
| UD-C-001 | `laravel/framework` | `13.20.0` / current metadata | retain | No advisory, defect or approved major objective | All 28 portal domains, PHP, routes, DB, cache/session/queue, mail, storage, deploy | Framework update always requires a separate official upgrade record |
| UD-C-002 | `laravel/sanctum` | `4.3.2` / current metadata | retain | Canonical mobile token/ability boundary is current | Auth, API, token schema, pruning, rate limits | Token/pending-client migration not exercised in Task 29 |
| UD-C-003 | `laravel/tinker` | `3.0.2` / current metadata | retain/review | Real operator CLI purpose exists; no HTTP/admin exposure | Deployment tooling only | Production installation necessity must be confirmed before removal |
| UD-C-004 | `livewire/livewire` | `4.3.3` / current metadata | retain | Current v4 APIs and no confirmed deprecated hook usage | Every class-based page, navigation, upload, pagination, player cleanup | Browser matrix cannot be reduced to build success |
| UD-C-005 | `wddyousuf/eloquent-autocache` | `0.2.4` / current metadata | retain | Bounded opt-in cache has an explicit fallback and no advisory | Country/Genre Top filters, Redis/file cache | Direct DB writers still require explicit invalidation discipline |
| UD-C-006 | `driftingly/rector-laravel` | `2.5.0` / current metadata | retain | Required Laravel deprecation/static profile | Development only | Maximum profile remains debt discovery, not automatic rewrite |
| UD-C-007 | `fakerphp/faker` | `1.24.1` / current metadata | retain | Existing factories require it | Factories/tests only | Tests intentionally not executed |
| UD-C-008 | `larastan/larastan` | `3.10.0` / current metadata | retain | Existing bounded type gate | Development/static analysis | Full-project legacy diagnostics remain outside zero-error bounded scope |
| UD-C-009 | `laravel/boost` | `2.4.13` / current metadata | retain | Project development/Codex integration | Development providers/commands | Not a production feature or runtime health source |
| UD-C-010 | `laravel/pail` | `1.2.7` / current metadata | retain | Used by `composer dev` | Development logs | Never enable browser log access or production debug tooling |
| UD-C-011 | `laravel/pao` | `1.1.2` / current metadata | retain | Existing diagnostics output integration | Development only | Tests were not executed to exercise output formatting |
| UD-C-012 | `laravel/pint` | `1.29.3` / current metadata | retain | Canonical formatter | All changed PHP | Formatter changes still require diff review |
| UD-C-013 | `mockery/mockery` | `1.6.12` / current metadata | retain | Existing test doubles | Tests only | Protected until suite migration is separately verified |
| UD-C-014 | `nunomaduro/collision` | `8.9.5` / current metadata | retain | Existing CLI error presentation | Development console | No production dependency reason |
| UD-C-015 | `phpunit/phpunit` | `12.5.31` / `13.2.4` | retain; defer major | New major alone is not benefit; tests may not be run in Task 29 | Complete test suite/config/CI and Pao/Collision compatibility | PHPUnit 13 compatibility is unknown until a dedicated verified migration |
| UD-C-016 | `rector/rector` | `2.5.7` / current metadata | retain | Owns required and maximum modernization inventory | Development/static analysis | Suggested refactors are not proof of deprecation or correctness |
| UD-C-017 | `fruitcake/laravel-debugbar` | absent / `^4.4` (locked `4.4.0`) | add; package gates verified | Requested Laravel-native local request/SQL/Livewire diagnostics without a custom profiler | Development only; auto-discovery/config boot and local HTML responses; production uses `--no-dev` and remains fail-closed | Debugbar gates pass; historical unrelated baseline failures remain documented, and the complete `main` history was published through `eb4e7f9e` |

### UD-C-017 — добавить Laravel Debugbar только для разработки

1. Dependency/runtime: direct `fruitcake/laravel-debugbar 4.4.0`; exact material transitive packages are `php-debugbar/php-debugbar 3.8.0` and `php-debugbar/symfony-bridge 1.1.0`.
2. Current/proposed version: package was absent; direct constraint `^4.4` resolved to locked official stable `4.4.0` without updating or removing another package.
3. Scope/purpose: `require-dev` diagnostics for trusted local HTTP, database, views, requests and Livewire behavior; no production product capability.
4. Reason: explicit user request and maintenance value from supported Laravel-native observability; a custom profiler/provider would duplicate package lifecycle and increase disclosure risk.
5. Security/maintenance relevance: Debugbar can expose request, SQL binding, session and application context. It is allowed only when application debug mode is on outside `production|testing`; force enable and public runtime middleware are prohibited.
6. Compatibility requirements: PHP `^8.2`, Illuminate `^11|^12|^13`, current Laravel `13.20`, PHP `8.5` and Livewire 4 support are confirmed by official package metadata, installed Composer resolution and passing dev/non-dev platform checks.
7. Affected files/modules: `composer.json`, `composer.lock`, `config/debugbar.php`, package discovery, development HTML response pipeline, regression test and maintenance/development/deployment documentation.
8. Configuration/database/assets/production: minimal config binds `enabled` to `APP_DEBUG` and sets `force_allow_enable=false`; no `.env` edit/variable, migration, Vite entry, domain cache key, session/queue/job/provider change. Production installs `--no-dev` and keeps `APP_DEBUG=false`.
9. Deprecated/replacement APIs: old package name `barryvdh/laravel-debugbar` is replaced upstream and must not be introduced; no existing application API is replaced.
10. Backward compatibility: public/application routes, APIs, persisted data, translations, cache identities and production responses remain unchanged. Local debug responses gain package assets/routes only while the approved gate is true.
11. Rollback: remove direct dev package and minimal config, restore reviewed lock, rebuild Composer autoload and Laravel config/route caches; no database, asset, session, queue, cache-data or provider rollback.
12. Verification: TDD RED/GREEN, exact three-package lock diff, installed metadata, MIT licences, zero locked advisories, local/false/production route boot, HTML injection/non-injection, platform checks, production `--no-dev` dry-run, documentation policies and repository-wide legacy/override search passed. The complete repository run executed 1 268 tests: 1 214 passed, while 37 failures and 6 errors came from pre-existing Blade contract violations and the unrelated missing `CacheDomain::UserPortal`; the 3 Debugbar tests passed with 9 assertions.
13. Decision: `add` is accepted because all bounded package and environment gates pass. Historical full-suite limitations remain recorded rather than retroactively rewritten; final Git delivery is included in the published `main` history through `eb4e7f9e`.

### UD-LW-CFG-001 — закрепить class-based generator Livewire

1. Dependency or runtime: direct dependency `livewire/livewire`.
2. Current version: locked `4.3.3` with constraint `^4.3`.
3. Proposed version: unchanged `4.3.3`; no Composer resolution or lock rewrite.
4. Direct or transitive: direct production dependency; only development-time generator configuration changes.
5. Current purpose: canonical class-based full-page and nested interactive UI state boundary.
6. Reason for change: permanent project rules prohibit Volt and require class-based Livewire, while the installed package default for `make:livewire` is `sfc`; an explicit project override prevents future architectural drift.
7. Security relevance: no authorization, CSRF, hydration, public state, route or production collector behavior changes.
8. Maintenance relevance: future generated components start in the already canonical `app/Livewire` plus Blade-view structure instead of introducing a competing component format.
9. Compatibility requirements: use the documented Livewire 4 `make_command.type=class` public config; preserve current JS/CSS/test generation defaults as `false` and do not introduce Volt.
10. Affected files: `config/livewire.php`, development/maintenance documentation, current task plan and changelog.
11. Affected feature modules: generator workflow only; all 28 runtime compatibility domains are unchanged.
12. Configuration changes: add the complete `make_command` array with `type=class`, `emoji=false` and `with.js|css|test=false` so package config merging cannot supply the SFC default.
13. Database changes: none.
14. Asset changes: none; existing Vite/Tailwind/Livewire runtime assets remain unchanged.
15. Production changes: none after normal config build; the setting is consumed only by component generation commands.
16. Deprecated APIs: none; SFC is supported package behavior but conflicts with this project's permanent architecture.
17. Replacement APIs: documented class generator mode, not an application runtime replacement.
18. Backward-compatibility strategy: existing class components, routes, public URLs, state, translations and generated files remain untouched.
19. Rollback strategy: remove only the project `make_command` block to restore package defaults; no data, cache, session, queue, service-worker or asset rollback.
20. Verification strategy: inspect effective config in an isolated process, confirm package/version metadata, scan for Volt/SFC/MFC usage and run allowed PHP syntax/Pint/static/documentation checks without invoking the generator or automated tests.
21. Decision: `retain and configure`; package update/removal/replacement is not justified. Repeat-audit integration commit `eb4e7f9e7dcf300328b35c527f65a39a743c2ebe` is published in `origin/main`.

## Все прямые npm decisions

| Record | Dependency | Current / evaluated | Decision | Reason | Affected modules / production considerations | Limitation |
| --- | --- | --- | --- | --- | --- | --- |
| UD-N-001 | `@fortawesome/fontawesome-free` | `7.3.0` / `7.3.1` | retain; defer patch | No advisory/current defect; patch belongs to a visual asset group | All icon CSS/fonts, CSP/assets, responsive UI | Patch content and icon rendering not reviewed in a browser |
| UD-N-002 | `hls.js` | `1.6.16` / current metadata | retain | Canonical non-native HLS fallback is current | Player, manifest/segment/network recovery | Real Safari/Android/browser codec matrix remains device verification |
| UD-N-003 | `plyr` | `3.8.4` / current metadata | retain | Canonical player; replacement would create prohibited competing system | Player controls, accessibility, captions, PiP/fullscreen | Upstream maintenance status beyond registry metadata not independently asserted |
| UD-N-004 | `@axe-core/playwright` | `4.12.1` / current metadata | retain | Existing accessibility QA | Development only | Runner intentionally not executed |
| UD-N-005 | `@playwright/test` | `1.61.1` / current metadata | retain | Existing browser infrastructure | Development browsers/reports | Task 29 uses only an allowed manual smoke, not the test runner |
| UD-N-006 | `@tailwindcss/vite` | `4.3.2` / `4.3.3` | retain; defer patch | Must stay version-coherent with Tailwind and visual verification | CSS compiler, Vite plugin, all UI | Patch not treated as automatically safe |
| UD-N-007 | `concurrently` | `9.2.4` / `10.0.3` | retain; defer major | Development convenience has no security/correctness reason to justify major | `composer dev` process/signals only | Major exit/signal/engine behavior not reviewed |
| UD-N-008 | `laravel-vite-plugin` | `3.1.3` / current metadata | retain | Current Laravel/Vite manifest bridge | Manifest, HMR, Blade `@vite` | Any framework/Vite update must review jointly |
| UD-N-009 | `tailwindcss` | `4.3.2` / `4.3.3` | retain; defer patch | Visual/compiler patch needs coherent plugin update and browser review | Entire generated CSS, responsive/a11y/admin/player states | Build success alone cannot prove class/style parity |
| UD-N-010 | `vite` | `8.1.4` / `8.1.5` | retain; defer patch | No advisory/current build defect; patch needs Node/manifest/chunk review | All hashed public assets and deployment | Existing Node 26 policy is already under separate review |

## Runtime decision

### UD-R-001 — Node.js build runtime

1. Name: Node.js.
2. Current: local/deployment documentation `26.x`, observed `26.4.0`.
3. Evaluated: official status shows Node 26 `Current`; Node 24 and 22 are LTS on 18.07.2026.
4. Scope: production build/deployment runtime, not browser runtime.
5. Purpose: npm lock installation and Vite build.
6. Reason: production should prefer an LTS line, but changing runtime without lock/build/browser verification would be uncontrolled.
7. Security: no current npm advisory; lifecycle policy still matters.
8. Maintenance: machine-readable range is absent.
9. Compatibility: Vite 8 accepts Node 26 and the installed build currently resolves.
10. Affected files: deployment/runtime documentation and a future runtime pin; lock only if npm resolution changes.
11. Affected modules: all public assets indirectly.
12. Configuration/database/assets/production: production build hosts only; no database change.
13. Deprecated/replacement APIs: none in repository; lifecycle status is the issue.
14. Backward compatibility: preserve npm lock v3 and existing asset manifest.
15. Rollback: restore previous build image/runtime and previously built immutable assets.
16. Verification: separate clean install/build/manifest/browser/service-worker-absence check.
17. Decision: `review`, with a dedicated Node 24 LTS migration preferred over an undocumented immediate downgrade.

## Deferred groups

- PHPUnit 13 major.
- `concurrently` 10 major.
- Coherent Tailwind `4.3.3` + plugin `4.3.3` patch.
- Vite `8.1.5` patch.
- FontAwesome `7.3.1` patch.
- Node build-runtime LTS policy/pin.

None is deferred because installation failed. They are deferred because Task 29 found no security/correctness trigger and cannot provide the separately required compatibility evidence without changing unrelated groups.

## Implemented dependency changes

Package version changes: none. `composer.lock`, `package.json` and `package-lock.json` are byte-for-byte unchanged.

### UD-CFG-001 — удалить неиспользуемые Composer plugin permissions

1. Dependency/runtime: Composer plugin policy; direct package отсутствует.
2. Current/proposed: `pestphp/pest-plugin` и `php-http/discovery` были разрешены в `config.allow-plugins`; оба отсутствуют в installed/locked set; proposed — не pre-authorize absent plugins.
3. Purpose: current project purpose отсутствует. PHPUnit, HTTP client и PSR bridge работают без этих plugins.
4. Reason: least-privilege для будущего Composer resolution; не security-vulnerability claim.
5. Security/maintenance relevance: неиспользуемое разрешение могло автоматически допустить plugin code, если package появится в будущем dependency graph.
6. Affected files/modules: только `composer.json`; package/runtime/public features не меняются.
7. Configuration/database/assets/production: permission entries удалены; database/assets/runtime versions unchanged.
8. Deprecated/replacement API: нет; future package должен получить отдельный reviewed permission decision.
9. Backward compatibility: installed packages не используют permissions; `composer validate`, platform check и audit являются verification gates.
10. Rollback: вернуть только exact allowlist entry после подтверждённого package-purpose/provider/script/security review; lock/data/cache/session/job transition отсутствует.
11. Decision: remove stale configuration permission.

Final commit: canonical Task 29 implementation `fa4d09f503d717fc737955902585737f34cf713a`; repeat audit and unified publication `eb4e7f9e7dcf300328b35c527f65a39a743c2ebe` on `main`.

## CI-R-001 — воспроизводимый GitHub Actions без обновления major-версий

1. Scope: GitHub-hosted runner и пять уже используемых actions; Composer/npm/PHP/Node/application dependencies не меняются.
2. Current/proposed: `ubuntu-latest` заменяется на GA `ubuntu-24.04`; floating tags `checkout@v6`, `cache@v5`, `setup-node@v6`, `upload-artifact@v7`, `setup-php@v2` заменяются полными SHA соответствующих текущих release commits с читаемым major-комментарием.
3. Reason: последний remote failure вызван stale repository documentation, а не новой dependency; отдельный pre-commit docs gate закрывает root cause. Exact runner/action refs дополнительно устраняют OS migration и mutable-tag drift.
4. Security: GitHub указывает полный commit SHA как единственную immutable форму action release. Checkout credentials не сохраняются; существующее разрешение `contents: read` не расширяется.
5. Compatibility: PHP `8.5`, Node `26`, Redis `7`, Memcached `1.6`, action majors, inputs, cache keys, services и quality-gate команды сохранены. Каждый SHA проверен как commit канонического upstream repository. Уже требуемое приложением расширение `gd` теперь явно устанавливается в backend/browser jobs и не зависит от предустановленного состава runner image.
6. Affected modules: только `.github/workflows/ci.yml`, central CI/hook contract, CI tests и development/maintenance documentation; public application/runtime domains не меняются. Remote run №213 дополнительно выявил два test-environment drift: устаревшее ожидание подробного публичного readiness и зависимость fake uploads от production-имени Unix-группы; оба исправлены без изменения application contract.
7. Production/data: нет migration, schema/storage/cache/session/queue/provider/service-worker/deployment runtime change; backup не требуется.
8. Rollout: focused contract, shell syntax, stale/fresh docs rehearsal, backend/frontend/browser gates, затем push `main` и проверка нового remote run; каждый remote-only отказ разбирается по job log и получает отдельный regression до повторной отправки.
9. Rollback: Git revert CI/hook/docs commit; data restore, store-wide cache flush и worker restart не требуются.
10. Limitation: pinning не гарантирует доступность GitHub/registries и не должно скрывать новые advisories или реальные regressions. Такие отказы остаются честно `failed` и требуют отдельного разбора.
11. Decision: `retain majors; pin commits and runner` — достаточная reliability/security причина существует, unrelated upgrade не выполняется.

## CI-R-002 — repository-level GitHub hardening

1. Scope: GitHub Actions repository policy, dependency alerting и защита истории единственной ветки `main`; application code, package/runtime versions и workflow commands не меняются.
2. Current/proposed: разрешение всех actions без server-side SHA enforcement заменено на `allowed_actions=selected`, `sha_pinning_required=true`, GitHub-owned actions и exact external `shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240`; passive vulnerability alerts включаются, а ruleset запрещает deletion/non-fast-forward для `main`.
3. Reason: локальные immutable refs уже существовали, но repository settings не блокировали будущий mutable или произвольный action ref, history rewrite и отсутствие dependency alert signal.
4. Security: default workflow token остаётся read-only без права approval; checkout credentials не сохраняются; secret scanning/push protection уже включены. Passive alerts добавляют dated signal, но не объявляют dependency graph безусловно безопасным.
5. Compatibility: exact allowlist покрывает все текущие refs workflow. `setup-php` pin соответствует подписанному выпуску `2.37.2`; GitHub-owned refs также используют full SHA. Direct fast-forward push в `main`, `workflow_dispatch` и три существующих jobs сохранены.
6. Affected feature map: authentication, authorization, translations, caching, search, notifications, SEO, privacy, mobile, administration, audit, imports, premium, regional/legal access и public routes не меняются. Затронуты только repository governance, CI supply chain и dependency notification boundary.
7. Production/data: schema, database, storage, cache/session/queue, provider configuration, service worker, assets и deployment runtime не меняются; backup, migration, cache flush и worker restart не нужны.
8. Rollout/verification: применить настройки через authenticated GitHub API без сохранения credentials, выполнить read-back каждого значения, проверить active rules, получить список alerts и запустить workflow вручную. Run №223 для `c19504e3183f011ebb14aaf15cf24b330c95bd92` завершил `Backend`, `Frontend` и `Browser` со статусом `success`.
9. Rollback: при подтверждённой несовместимости вернуть Actions policy к `allowed_actions=all` и `sha_pinning_required=false`; удалить ruleset `19185964`; vulnerability alerts отключать только по отдельной явной security-причине. Откат не требует изменения кода или восстановления данных.
10. Limitation: required status checks и Dependabot security updates не включены, потому что обязательные PR branches противоречат каноническому direct-to-`main` workflow. Локальный pre-push проверяет изменение до отправки, remote run — после неё. Внешние outages и новые реальные regressions невозможно и нельзя маскировать.
11. Decision: `apply repository hardening without dependency upgrade` — уменьшить контролируемые configuration/supply-chain/history risks, сохраняя fail-closed проверки и существующий Git contract.
