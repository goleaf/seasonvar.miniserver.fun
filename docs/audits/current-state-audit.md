# Аудит текущего состояния

Проверено: 15.07.2026. Корень приложения: `/www/wwwroot/seasonvar.miniserver.fun`. Этот документ — текущий evidence snapshot; устойчивые контракты остаются в тематических владельцах из [`docs/README.md`](../README.md), а исполняемый backlog — в [`docs/plans/laravel-video-portal-modernization.md`](../plans/laravel-video-portal-modernization.md).

## Методика и границы

Проверены 1088 tracked-файлов (17.6 MB), 414 application PHP-файлов, 52 Blade-файла, 60 migrations, 135 PHPUnit-файлов и 3 route-файла. Инвентаризация включала Composer/npm lockfiles, Laravel bootstrap/providers/middleware, 114 routes, controllers, Requests, Resources, Policies, Livewire Forms/components, models/casts/enums, jobs, commands, services, views, JS/CSS, конфигурацию, CI, deploy units, локальный SQLite runtime и безопасные агрегаты Redis/Memcached. Production secrets, raw failed-job payloads и upstream media URL не выводились.

Запрос пользователя на файлы `docs/audit/*`, `docs/architecture/*`, `docs/testing/test-matrix.md`, `docs/deployment/*` и `docs/upgrade-plan.md` выполнен через существующую ownership-карту без создания конкурирующих источников истины:

| Запрошенный результат | Файл-владелец |
| --- | --- |
| Current state | Этот документ |
| Dependencies / database / frontend / Livewire / playback | Соседние отчёты в `docs/audits/` |
| Security / performance | `security-audit.md`, `performance-audit.md` |
| Target architecture / data flow | `docs/architecture.md` |
| Cache strategy | `docs/caching.md` |
| Video delivery | `docs/frontend.md`, `docs/authorization.md` |
| Test matrix | `docs/testing.md` |
| Server requirements / deployment checklist | `docs/deployment.md` |
| Upgrade and implementation plan | `docs/upgrade.md`, `docs/plans/laravel-video-portal-modernization.md` |

## Подтверждённый стек

| Область | Подтверждённое состояние | Статус |
| --- | --- | --- |
| Runtime | Rocky Linux 10.2, PHP 8.5.8, Laravel 13.19.0 | Laravel 13.20.0 доступен как semver-compatible patch; controlled update запланирован |
| UI | Livewire 4.3.3, только class-based components; Volt отсутствует | Соответствует целевой границе |
| Frontend | Tailwind 4.3.2 через `@tailwindcss/vite`, Vite 8.1.4, Node 26.4.0, npm 12.0.1 | Node 26.5.0 и Vite plugin 3.1.3 требуют controlled patch update |
| Backend quality | Pint 1.29.3, PHPUnit 12.5.31, Larastan 3.10.0 | Bounded Larastan зелёный; полный `app/` содержит 547 diagnostics |
| Database | SQLite, 14+ GB live DB, WAL-oriented project config | Single-writer и рост snapshot/staging — главные capacity boundaries |
| State | Redis cache/session/queue/locks; Memcached disposable hot tier | Разделение корректное, но cache-warm worker не установлен |
| Media | Внешние allowlisted HTTPS URL, signed same-origin redirect, Plyr + HLS.js fallback | Laravel не проксирует и не скачивает video bytes |
| API | Legacy read-only API и `/api/v1`, Sanctum abilities, Resources, safe error envelope | 52 API routes; write endpoints owner/policy scoped |

## Функциональный inventory

| Возможность | Факт | Проверка / оставшийся риск |
| --- | --- | --- |
| Главная, каталог, popular/recently added/recently updated | Реализованы server-side builders и cached public snapshots | Feature/query-budget tests; production cold home path остаётся медленным |
| Genres/countries/years/actors/directors/studios и остальные directories | 11 registry-driven Livewire hubs | Route inventory + browser checks |
| Search, autocomplete, filters, sorting, pagination | SQLite FTS5 с bounded fallback, URL-bound Livewire state | FTS state stale относительно code version; rebuild нужен после safe maintenance |
| Title, seasons, regular/special episodes, previous/next | Реализованы; сезоны/серии принадлежат одному `CatalogTitle` | Characterization, navigation и playback tests |
| HLS, MP4, quality, subtitles, fullscreen, PiP, speed, volume | Plyr/player module; native HLS first, lazy HLS.js fallback | Deterministic browser shell проверен; реальный provider остаётся external operational dependency |
| Continue Watching, history, watchlist, ratings | Web library + API v1, monotonic progress service | Owner isolation, policy/API/browser tests |
| Authentication / verification / password / devices | Livewire web portal + Sanctum mobile API | Browser and feature tests; production mail driver сейчас `log` |
| Administration / moderation | `/admin/catalog`, `/admin/imports`, gate + policy + optimistic versions + audit events | Two admin routes; importer operational state требует исправления |
| Import/parser/background | Единственная публичная команда `seasonvar:import`, Redis fan-out/fan-in jobs | 11 overlapping runs и большой backlog подтверждены |
| SEO / sitemaps / feed / structured data | SSR builders, streamed XML/feed, canonical and JSON-LD | Layout содержит большой неиспользуемый SEO payload; требуется упрощение без semantic regression |
| Error/maintenance/localization/logging | Russian UI/errors, custom API envelope, daily logging config | Runtime debug включён; production error disclosure risk до внешней env-правки |
| Favorites / named multiple watchlists / collections | Watchlist реализован; отдельные favorite/collection domain models отсутствуют | Intentional product boundary, не дефект |
| Audio-track switching / normalized audio metadata / DRM | Не моделируется как отдельный продуктовый контракт | Нельзя симулировать без provider/product specification |
| Analytics | Read-only optional Google commands, по умолчанию выключены | Analytics navigation lifecycle не активен без credentials/product decision |

## Реестр выводов

Каждая строка различает факт, решение, статус, проверку и риск.

| ID | Класс | Наблюдение | Предлагаемое изменение | Статус | Verification / результат | Оставшийся риск |
| --- | --- | --- | --- | --- | --- | --- |
| CS-01 | Confirmed problem | Production `APP_DEBUG` включён; config/events не cached | Fail-closed deployment preflight; operator должен выставить `APP_DEBUG=false`, затем cache/reload | Blocked by external env authority | `artisan about`: production + debug enabled | До env-правки возможна утечка diagnostic details |
| CS-02 | Confirmed problem | Pending migrations `api_sync...` и `user_library_query_indexes` | Safe maintenance: stop writers, backup, migrate, verify, resume | Pending | `migrate:status` | Нельзя применять при 11 active imports без safe point |
| CS-03 | Confirmed problem | 11 active queued runs, 8037 pending import jobs, 5670 claims | Сделать dispatch single-flight по lifecycle run, завершать terminal/orphan state, затем изменить cron contract | Pending P0 | `seasonvar:import --status` | Backlog и failed jobs продолжают расти до rollout |
| CS-04 | Confirmed performance cost, instrumented | Первый `app:deployment-check --json` превысил внешний лимит 25 s; instrumented end-to-end run завершился за 24.45 s, SQLite quick/FK занял 23655 ms, остальные checks 0–303 ms | Сохранить полную integrity-проверку, фиксировать `duration_ms` и запускать её с бюджетом >=30 s вне активной write-нагрузки; оптимизировать только без ослабления проверки | Implemented; rollout pending | 4 command feature tests + live exit/result/timing | На растущей SQLite длительность может увеличиваться; это медленный gate, а не доказанное зависание |
| CS-05 | Confirmed problem, code fixed | Старый `app:health` сообщал queue pending=0 при import backlog >8000 из-за одного общего heartbeat/default queue; request memo мог удержать heartbeat после TTL в long-lived process; degraded CLI завершался exit 0 | Отдельные direct-store метрики/heartbeat для четырёх pools, idle loop/expiry и strict operational CLI exit при сохранении HTTP traffic readiness | Implemented; rollout pending | 7 health/queue tests, 49 assertions; combined 12/88; Larastan 0; live CLI `degraded`, 32325 pending, exit 1 | Запущенные worker-процессы получат scoped heartbeat только после verified deploy/restart; cache-warm worker пока отсутствует |
| CS-06 | Confirmed problem | Cache warm scheduled, но worker отсутствует; state `unknown` | Установить/проверить versioned unit после снижения backlog и измерить drain | Pending operations | `schedule:list`, process/unit inventory, health | Немедленный запуск может усилить SQLite contention |
| CS-07 | Confirmed problem | Blade содержит 41 `request()` и 1 `config()` call | Подготовить active-route flags/classes и maxlength до render; добавить zero-tolerance contract test | Pending architecture | `rg` по 52 Blade | Текущие templates нарушают passive presentation boundary |
| CS-08 | Confirmed problem | AppLayoutData 1734 строки, layout 784 строки, SEO collections вычисляются даже когда потом очищаются | Удалить unreachable generated SEO payload, оставить canonical useful metadata и prepared JSON-LD | Pending performance/SEO | Static call/flag tracing + benchmark | Нужны SEO/browser regressions, чтобы не удалить полезный контракт |
| CS-09 | Confirmed problem | Full Larastan level 6: 547 diagnostics; configured bounded scope: 0 | Расширять scope пакетами, исправлять real types, не создавать baseline | In progress | Direct `vendor/bin/phpstan` | Большой объём требует нескольких verified batches |
| CS-10 | Confirmed problem | 138/414 app PHP files без `strict_types` | Добавлять при изменении файлов и отдельными низкорисковыми batches | Pending | Static inventory | Массовая механическая правка без проверки запрещена |
| CS-11 | Confirmed problem | Snapshot/prepared storage и DB занимают гигабайты; volatile provider HTML меняет raw hash | Ввести semantic fingerprint/canonical snapshot policy и bounded retention | Pending importer/storage | DB/file size and 500-row normalization sample | Нельзя удалить forensic/source data без recovery contract |
| CS-12 | Confirmed problem | Recommendation v3 rebuild запускается каждым import cycle и занимает ~9 min | Перестраивать по changed title set/versioned dirty marker | Pending performance | Production timing + call graph | Нужен atomic consistency contract |
| CS-13 | Confirmed problem | Stats path и cold home path медленные под active import load | Snapshot/SQL profiling, bounded stats builder, cache recovery | Pending performance | Timed HTTP samples | Host load меняется; нужны before/after medians |
| CS-14 | Confirmed problem | CSP остаётся report-only и разрешает broad `https:` | Собрать/проверить violations, сузить origins, затем staged enforcement | Pending security | Header/browser checks | Нельзя включать enforcement вслепую из-за media/CDN |
| CS-15 | Confirmed problem | Public storage link отсутствует | Подтвердить, нужен ли public disk; создать link только если route/assets реально используют его | Probable, verify | `artisan about` | Не является дефектом при private/external-only media |
| CS-16 | Intentional behavior | SQLite, external media redirect, no local video, no DRM/transcode | Сохранить до отдельного domain/infrastructure decision | Accepted | Architecture/playback tests | Provider availability и SQLite writer ceiling остаются внешними границами |
| CS-17 | Intentional behavior | PHPUnit сохранён, Pest/Volt отсутствуют | Не мигрировать без доказанной выгоды | Accepted | Lockfiles/inventory | Нет |
| CS-18 | Proposed change | Laravel 13.20.0 и Vite plugin 3.1.3 доступны | Controlled patch groups с lockfile rollback и полным gate | Planned | Official release notes + outdated commands | Возможные latent behavior changes выявляются только suite/browser |

## Baseline quality gates

Проверено 15.07.2026 на `main` commit `70df36b`:

- PHPUnit: 826 tests, 815 passed, 11 skipped, 6751 assertions, 93.554 s.
- Playwright/axe: 18/18 passed, desktop и mobile, 1.4 min.
- Focused browser CI contract: 2 tests, 41 assertions.
- Pint: pass.
- PHP syntax lint: exit 0.
- Bounded Larastan: 0 errors; full `app/` level 6: 547 diagnostics.
- Vite production build: pass; largest lazy chunk `hls.light` 331.90 kB / 104.61 kB gzip.
- Composer audit and npm high audit: 0 advisories.
- Instrumented deployment preflight: wall time 24.45 s; SQLite quick/FK 23655 ms, migrations 13 ms, FTS 303 ms, failed-job aggregate 98 ms, importer profile 140 ms, остальные checks 0 ms после округления. Integrity прошёл; общий gate корректно failed из-за debug, двух migrations и stale FTS state.

## Текущий production verdict

Repository baseline функционально зелёный, но production-ready verdict — **нет**. Блокируют: debug mode, две pending migrations, overlapping import cycles, backlog/failed finalizers и отсутствие cache-warm worker. Deployment preflight доказан конечным, но дорогим (~23 s), а исправленный health теперь честно показывает неработающие pools вместо ложного green; оба изменения ещё требуют verified rollout. Эти пункты имеют более высокий приоритет, чем optional Octane, Pulse, Horizon, визуальный redesign или новые product capabilities.
