# Реестр решений об обновлениях

Аудит: 18.07.2026. Решение относится к точным lock-файлам Task 29. `retain` не означает бессрочную заморозку: оно означает отсутствие достаточной причины менять dependency в этом change set.

## Общий contract записей

Для каждой строки ниже:

- direct/transitive scope указан в dependency inventory; все записи здесь direct;
- security relevance проверена `composer audit --locked` или `npm audit`, оба результата — zero known advisories на дату аудита;
- package/runtime versions не изменяются; единственное configuration изменение — удаление двух неиспользуемых Composer plugin permissions в `UD-CFG-001`; frontend candidates отдельно перечисляют потенциально затрагиваемые assets;
- backward compatibility сохраняется неизменными manifest/lock/public contracts, а config correction не затрагивает installed или locked package;
- migration отсутствует; rollback для Task 29 — возврат documentation/UI-component commit, без schema/cache/session/job/provider transition;
- verification включает lock/manifest inspection, direct usage search, provider/route/command/assets review и перечисленные в текущем плане static/build/browser gates;
- final commit заполняется после commit; до этого используется `pending Task 29 commit`.

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

Final commit: Task 29 commit on `main` (exact hash reported after commit).
