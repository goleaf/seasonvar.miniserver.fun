# Матрица runtime-совместимости

Аудит: 18.07.2026; повторная maintenance-сверка 19.07.2026. Матрица разделяет локально установленное, проверенное инструментами, требуемое проектом и внешнее production-состояние. Статус `verified` относится только к перечисленной проверке и не означает поддержку любого будущего patch release.

## Значения статусов

- `verified` — команда или реальный локальный flow выполнен в этой среде.
- `documented compatible` — constraint/config/официальная документация совместимы, но полный runtime flow в Task 29 не выполнен.
- `project-required` — постоянный production contract проекта.
- `optional` — путь настроен, но не является обязательным для корректности.
- `unsupported` — пакет/сервис отсутствует или явно не входит в текущий продукт.
- `unknown` — доказательства из внешней production-среды недоступны.
- `requires review` — работоспособность не равна одобренной production-политике.

## Framework, language и build runtime

| Boundary | Current evidence | Status | Compatibility decision / limitation |
| --- | --- | --- | --- |
| Laravel | lock `13.20.0`, constraint `^13.20` | verified installed; documented compatible | Laravel 13 officially requires PHP 8.3+. Framework major changes remain separate. [Laravel 13 release notes](https://laravel.com/docs/13.x/releases) |
| Livewire | lock `4.3.3`, constraint `^4.3` | verified installed and effective config | Class components retained; Volt absent. Project config overrides the package SFC generator default with `make_command.type=class` and disables generated JS/CSS/tests. Hashed update route uses the v4 two-argument callback; deprecated `commit`/`request` JS hooks are absent. `.blur` bindings intentionally synchronize on blur under v4.1+ semantics. [Livewire 4 upgrade guide](https://livewire.laravel.com/docs/4.x/upgrading) |
| Tailwind CSS | lock `4.3.2`, Vite plugin `4.3.2` | verified installed and production build | CSS-first `@import`/`@source` architecture is already v4; no v3 config migration is needed. [Tailwind upgrade guide](https://tailwindcss.com/docs/upgrade-guide) |
| Flux / Flux Pro | no Composer/npm package or source usage | unsupported, intentional | Do not install or claim licensed Pro components. Existing custom components remain canonical. |
| PHP requirement | Composer `^8.3`; production docs require 8.5 | project-required | Laravel minimum and project production baseline are different facts; do not raise Composer minimum without deployment review. |
| Local PHP CLI / FPM | `8.5.8` / `8.5.8` | verified version | Loaded: PDO SQLite/MySQL, Redis, Memcached, intl, mbstring, curl, fileinfo, sodium, Imagick, GD and OPcache. FPM pool/traffic behavior remains production verification. |
| PHP support policy | PHP 8.5 active support through 31.12.2027, security through 31.12.2029 | documented compatible | Future PHP minor requires official migration/deprecation review. [PHP supported versions](https://www.php.net/supported-versions.php), [PHP 8.5 migration](https://www.php.net/manual/en/migration85.php) |
| Composer | local `2.10.2`; lock validation succeeds | verified tool; requires review | `composer diagnose` reports missing global tag/dev verification pubkeys. No repository update used self-update; operator key setup is tracked before future Composer self-update. |
| Laravel Debugbar | lock `fruitcake/laravel-debugbar 4.4.0`; transitive core `3.8.0`, Symfony bridge `1.1.0` | verified installed; development only | PHP `^8.2` and Illuminate `^11|^12|^13` cover PHP 8.5/Laravel 13.20. Project config follows `APP_DEBUG`; package guard excludes `production|testing`, and production `--no-dev` omits it. [Official package repository](https://github.com/fruitcake/laravel-debugbar) |
| Node.js | local `26.4.0`; deployment docs currently say 26; no `.nvmrc`, `.node-version` or `engines` | documented build-compatible; requires review | Vite 8 supports this range, but Node 26 is still `Current` on audit date and official policy recommends LTS for production. Evaluate Node 24 LTS as a separate build-runtime migration; do not rewrite lock now. [Node release status](https://nodejs.org/en/about/previous-releases) |
| npm | local `12.0.1`, only lock format v3 | verified tool | Package manager remains npm. Global `--init.module` config warning is external to repository and must be removed before the next npm major. |
| Vite | lock `8.1.4`, Laravel plugin `3.1.3` | verified installed, build and manifest | One public entry `resources/js/app.js`; production build emitted 15 manifest entries, valid referenced assets and no source maps. Vite 8 requires Node 20.19+ or 22.12+. [Vite 8 announcement](https://vite.dev/blog/announcing-vite8) |
| GitHub Actions | explicit `ubuntu-24.04`; existing action majors pinned to reviewed full commit SHA | documented compatible; focused contract verified | PHP 8.5, Node 26, Redis 7 and Memcached 1.6 contracts are unchanged. Exact SHA removes mutable action-tag drift; `persist-credentials=false` matches `contents: read`. External GitHub/registry availability and future advisories remain fail-closed, not guaranteed. [GitHub secure use](https://docs.github.com/en/actions/reference/security/secure-use), [runner images](https://github.com/actions/runner-images#available-images) |

## Database, cache, session и queue

| Boundary | Current evidence | Status | Compatibility decision / limitation |
| --- | --- | --- | --- |
| SQLite | config default and `.env.example`; local CLI/PDO `3.46.1`; production-style repository runbooks | project-required; locally verified driver | All 110 project migrations are `Ran`. External batch 31 applied three comment/review repair/index migrations and batches 32–33 applied five additive administration migrations. Task 29 performed post-rollout read-only status/table/index checks, did not run DDL and does not claim unobserved pre-migration backup evidence. |
| MySQL | PDO MySQL/mysqlnd `8.5.8`, Laravel config present | optional / unknown server | Driver availability is not schema/query compatibility proof. Do not claim production MySQL support until migrations, JSON, indexes, FTS, locks, money and backup/restore are rehearsed. |
| PostgreSQL / SQL Server | framework sample config only; no current project evidence | unsupported | Adding a driver is a separate database migration, not a package toggle. |
| Redis client | PHP Redis extension `6.3.0`; no direct Predis package | project-required | Cache, sessions, queues, locks and broadcasting use separate connections/prefixes. Serializer/compression/prefix/client changes require stale-key/session/job rollout. |
| Redis server | Task 28 verified configured local endpoint responds for cache/session/queue/lock roles; no canonical systemd unit was found | verified reachable; process ownership/failover unknown | Application health confirms connectivity, but server version, persistence, restart owner and failover remain panel/production operations. |
| Memcached client | PHP extension `3.4.0`, libmemcached `1.0.18` | project-required for hot cache | Values are recomputable; eviction is expected. Serializer/persistent ID/pool changes require fallback review. |
| Memcached server | binary `1.6.39` present; local service/unit/port unavailable during Task 29 health inspection | requires review; local runtime unavailable | Application readiness remains available through the documented recomputable-cache fallback, but this is degraded operation rather than a verified hot-cache tier. Production Memcached service/config/metrics still require redacted host evidence. |
| Cache format | application `CACHE_SCHEMA_VERSION`/`CACHE_FORMAT_VERSION`; Redis domain + Memcached hot + failover | project-required | No Task 29 key, serializer, TTL or payload format change. Global `Cache::flush()` remains prohibited. |
| Session | production Redis connection `sessions`, encrypted/signed cookie framework contract | project-required | No driver, cookie, serialization or `APP_KEY` change. Any future change must preserve OAuth/payment returns and account switching. |
| Queue | production Redis, `after_commit=true`; synchronous fallbacks exist where documented | project-required | Thirteen application job classes and ten notification classes were inventoried. Class/constructor changes require pending-job compatibility and worker restart. |
| Scheduler | seven named bounded schedules using lock store | verified source configuration | No new cron/queue infrastructure. Scheduler availability in production remains operational evidence. |

## Web, browser, provider и storage compatibility

| Boundary | Current evidence | Status | Compatibility decision / limitation |
| --- | --- | --- | --- |
| nginx | active/enabled binary `1.31.2`; Task 28 read-only config inspection confirmed TLS listeners, HSTS, document root and FastCGI rules | verified current host boundary | Reverse-proxy/trusted-header ownership and certificate renewal remain panel-managed evidence; upgrades still require rewrite/header/range/upload/static review. |
| PHP-FPM | active/enabled `php-fpm-85.service`, `8.5.8`; Task 28 graceful reload succeeded | verified current host runtime | Pool permission/timeouts and OPcache are documented; future version/extension changes require separate review. |
| OPcache | extension `8.5.8`; enabled for FPM configuration, CLI cache disabled | documented compatible | Runtime deployment must reload FPM after PHP/config/package changes; CLI status does not prove FPM hit rate. |
| Chromium | Task 29 headless desktop/mobile smoke on the configured HTTPS host | partially verified | 19 desktop and 6 mobile journeys covering home, catalogue, representative title/player shell, search, calendar, requests, help, Premium, RU/EN authentication pages and guest private/admin redirects rendered without horizontal overflow, raw keys, console/page errors or service-worker registration. `/` took `39.5 s` and the first mobile `/titles` took `52.0 s` under concurrent SQLite/queue load; `TD-011` keeps this as an unresolved performance risk rather than a steady-state compatibility claim. Authenticated mutations, real media playback, external providers and non-Chromium devices were not exercised. |
| Firefox, Safari, iOS Safari, Android device/WebView | capability-aware code and documented fallbacks | unknown real-device | No emulation may be described as real-device verification. Player/PiP/fullscreen/safe-area/upload/payment/OAuth require device checks when affected. |
| Service worker / PWA | no browser manifest, registration, worker build entry or package; only the unrelated Vite asset manifest exists | unsupported, intentional | Private/payment/ticket/legal/advertiser/admin/media caching exclusions are preserved by absence. A future PWA is a separately designed allowlist architecture. |
| Payment provider SDK | none; provider registry/currencies/plans inactive by default | unsupported until reviewed provider rollout | Internal exact-money, signature, idempotency and reconciliation boundaries remain protected; no browser success trust. |
| OAuth/social login SDK | none | unsupported | Google analytics/search integrations are read-only HTTP services, not account OAuth. No callback route/account-link behavior exists to claim. |
| Mail | Laravel/Symfony Mailer transitive; configurable SMTP/sendmail/log/failover | project-required; external delivery unknown | Application acceptance is not delivery proof. Locale/verification/reset/security/premium/ticket/legal notifications remain compatibility journeys. |
| Local/private storage | Flysystem local; private uploads outside `public/` | project-required | PHP-FPM/CLI group and `0660/0770` contract preserved. No Task 29 file identity change. |
| S3/object storage | config stub exists; S3 adapter is not a direct installed package | unsupported until approved | Do not enable from environment alone. Package/license/credentials/private delivery/migration/rollback review is required. |
| Search provider | application Eloquent/SQLite FTS5; no Scout/remote engine | project-required | External search package is not installed and may not replace canonical identity/ranking without migration. |
| Media | Plyr `3.8.4`, hls.js `1.6.16`, native media APIs | project-required | MP4/HLS capability detection and authorized source resolver remain canonical. Package update requires player lifecycle/mobile/subtitle/quality/progress review. |
| Image processing | PHP Imagick `3.8.1` and GD `8.5.8` loaded | documented compatible | Format, pixel/memory, EXIF/metadata, private upload and existing reference behavior require separate extension/library upgrade review. |

## Production compatibility conclusion

Task 29 does not change PHP, Node, Composer/npm, framework/package constraints, database, Redis/Memcached, cache/session/job serialization, provider state or service worker. Therefore deployment uses the existing locked-install/build/cache/reload runbook. Final `app:health` remained ready but degraded because Memcached was unavailable; database, all critical Redis roles and the cache-warm/import/title-refresh pools were `ok`, while cache warming was running with zero recorded failures. Task 29 neither concealed the degraded hot tier nor reconfigured it. Outstanding production evidence is explicit: Node 26 LTS policy, Composer self-update pubkeys, Redis/Memcached server/failover state, actual FPM/nginx config, non-Chromium devices and external provider delivery.

## Повторная operational-сверка 19.07.2026

- Exact package/runtime versions не изменились; Composer/npm manifests и locks сохранили исходные SHA-256. Production `--no-dev` и npm locked-install dry-runs прошли без resolution changes.
- Effective runtime приведён к обязательному baseline: `APP_ENV=production`, `APP_DEBUG=false`, config cached, maintenance mode выключен. После `config:cache`/`route:cache` выполнены graceful reload подтверждённого `php-fpm-85.service` и `queue:restart`; `/` и `/up` возвращают `200`, production Debugbar routes отсутствуют.
- Все 110 migrations имеют состояние `Ran`; comment/review repair и exact-count index применены внешним rollout в batch 31, а administration access, role/permission definitions, account restrictions, safe audit-view fields и bounded operational events — в batches 32–33. Task 29 эти migrations не запускала. Post-rollout read-only evidence подтверждает новые tables/columns/indexes, `60` permissions, `14` roles, `166` role-permission rows, отсутствие преждевременных role assignments/restrictions/operational events и ранее проверенные tombstone/index/FK invariants.
- Task 29 не наблюдала и не подтверждает pre-migration backup evidence для внешних batches 31–33. Любой следующий SQLite DDL всё равно требует verified backup, writer pause и recorded checksum/integrity evidence до migration.
- Cache/session/queue serializers, prefixes, drivers, job constructors, database data, service worker и provider state этим repeat audit не менялись.
- Финальный `app:deployment-check --json` после внешних batches 32–33 завершился `ready`: production environment/debug/logging, все 110 migrations, SQLite quick/FK, required indexes, FTS `32929/32929/32929` и cache transports прошли. Integrity check занял `136028 ms` под активной нагрузкой; warnings по `32771` historical failed jobs и неподтверждённому отдельному forever-importer process требуют ручной disposition, но не скрыты как successful automation.
- Финальный `app:health --json` отдельно остаётся `ready=true`/`degraded`: database и все критические Redis roles доступны, `cache-warm-v2`, import и title-refresh pools имеют status `ok`, cache warming — `running` с `failed=0`; Memcached недоступен и остаётся причиной degraded state. На момент снимка queues содержали 493 cache-warm и 1610 import pending jobs с тремя reserved; это transient operational evidence, не SLA. Queue deletion/retry или масштабирование наугад не выполнялись. Readiness и health не подменяют друг друга.
- Post-reload HTTP probe: `/up` вернул `200` за `0.70 s`, `/titles` — `200` за `9.15 s`, guest `/admin` штатно завершился на login за `0.62 s`; `/` превысил 20-секундный timeout без тела. Последнее сохраняет `TD-011` открытым и не отменяется успешным health/preflight.

## Laravel Debugbar compatibility от 19.07.2026

Отдельное изменение добавляет только development dependency и минимальный config. Exact lock содержит три новых MIT packages без обновлений или удалений. Автоматизированный regression проверяет local debug, local non-debug, production и testing guard; production dry-run обязан подтвердить отсутствие dev packages. Database, cache/session/queue serialization, provider state, service worker и public assets не меняются.

## GitHub Actions compatibility от 19.07.2026

Отдельное CI-изменение не меняет package/runtime versions. `ubuntu-latest` заменён на поддерживаемый явный `ubuntu-24.04`; release commits соответствуют уже принятым `checkout v6`, `cache v5`, `setup-node v6`, `upload-artifact v7` и `setup-php v2`. Уже обязательное для растровых потоков расширение `gd` явно устанавливается в backend/browser jobs вместе с поддержкой WebP, поэтому runner image не является скрытым источником этой возможности. Миграции, runtime deployment и data rollback отсутствуют; controlled update этих SHA или набора extensions остаётся отдельным maintenance review с повторным backend/frontend/browser gate.
