# Аудит текущего состояния

Проверено: 16.07.2026. Корень приложения: `/www/wwwroot/seasonvar.miniserver.fun`. Этот документ — текущий evidence snapshot; устойчивые контракты остаются в тематических владельцах из [`docs/README.md`](../README.md), а исполняемый backlog — в [`docs/plans/laravel-video-portal-modernization.md`](../plans/laravel-video-portal-modernization.md).

## Повторная operational-сверка Task 29 — 19.07.2026

- Versions и exact locks остаются Laravel `13.20.0`, Livewire `4.3.3`, PHP `8.5.8`, Tailwind `4.3.2`, Vite `8.1.4`, Node `26.4.0`, npm `12.0.1`; uncontrolled update не выполнялся.
- Production debug blocker закрыт: effective runtime сообщает `APP_ENV=production`, `APP_DEBUG=false`, cached config и выключенный maintenance mode; config/routes rebuilt, PHP-FPM и queue workers gracefully refreshed, production Debugbar routes отсутствуют.
- Все 110 migrations теперь `Ran`; другой rollout применил три comment/review repair/index migrations в batch 31 и пять additive administration migrations в batches 32–33. Post-rollout read-only audit подтвердил прежние tombstone/index/FK invariants и новые administration tables/columns/indexes с каноническими role/permission definitions. Task 29 не запускала DDL и не наблюдала pre-migration backup evidence этих внешних batches, поэтому process evidence остаётся явно неполным.
- После завершения чужого maintenance window `/` и `/up` возвращают `200`. Отдельный managed-Chromium smoke без test runner подтвердил 19 desktop и 6 mobile representative public/auth/private/admin routes, RU/EN locale pages и title/player shell без overflow, raw translation keys, console/page или устойчивых first-party failures; service-worker registrations `0`. При этом `/` занял `39.5 s`, а первый mobile `/titles` — `52.0 s` под активной общей SQLite/queue-нагрузкой; это не выдаётся за steady-state benchmark и остаётся открытым риском `TD-011`. Authenticated writes и внешние provider journeys не выполнялись.
- Fresh locked Composer/npm audits по-прежнему сообщают zero known advisories; это dated evidence. Effective Livewire config теперь предотвращает новый SFC/Volt drift через `make_command.type=class` без package/runtime change.

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
| Administration / moderation | `/admin/catalog`, `/admin/imports`, gate + policy + optimistic versions + audit events | `/admin/catalog` объединяет catalog и collection management; importer operational state требует исправления |
| Import/parser/background | Единственная публичная команда `seasonvar:import`, Redis fan-out/fan-in jobs; lifecycle single-flight и event-driven finalization реализованы | Production сохраняет 12 legacy running sitemap runs и большой backlog до rollout/reconciliation |
| SEO / sitemaps / feed / structured data | SSR builders, streamed XML/feed, canonical and JSON-LD | Layout содержит большой неиспользуемый SEO payload; требуется упрощение без semantic regression |
| Error/maintenance/localization/logging | Russian UI/errors, custom API envelope, daily logging config | Runtime debug включён; production error disclosure risk до внешней env-правки |
| Favorites / named multiple watchlists / collections | Watchlist реализован; отдельные favorite/collection domain models отсутствуют | Intentional product boundary, не дефект |
| Audio-track switching / normalized audio metadata / DRM | Не моделируется как отдельный продуктовый контракт | Нельзя симулировать без provider/product specification |
| Analytics | Read-only optional Google commands, по умолчанию выключены | Analytics navigation lifecycle не активен без credentials/product decision |

## Реестр выводов

Каждая строка различает факт, решение, статус, проверку и риск.

| ID | Класс | Наблюдение | Предлагаемое изменение | Статус | Verification / результат | Оставшийся риск |
| --- | --- | --- | --- | --- | --- | --- |
| CS-01 | Confirmed problem, resolved | Production `APP_DEBUG` был включён; config не cached | Fail-closed deployment preflight; `APP_DEBUG=false`; cache/reload | Resolved 19.07.2026 | Effective production config: debug false, config cached; Debugbar routes absent; PHP-FPM active | Повторная ошибка должна блокироваться deployment preflight |
| CS-02 | Confirmed problem, resolved externally | Migration backlog, позднее включавший три comment/review repair/index и пять administration migrations | Safe maintenance: stop writers, backup, migrate, verify, resume | Current schema complete; process evidence incomplete | Все 110 migrations `Ran`; tombstone/index/FK и administration table/index checks clean | Task 29 не наблюдала pre-migration backup evidence batches 31–33; следующий DDL требует recorded backup evidence |
| CS-03 | Confirmed problem, code fixed | Production snapshot вырос до 12 legacy running sitemap runs; failed jobs: 4155 group finalizers, 793 page jobs, 9 preparation jobs с `MaxAttemptsExceededException`; 1601 active groups | Shared lifecycle single-flight; event-driven unique-until-processing finalizers без polling; bounded scheduled watchdog | Implemented; rollout/reconciliation pending | 52 importer tests / 266 assertions; changed-scope Larastan 0; schedule contract pass | Existing runs/jobs не очищены и не retry-ились; после deploy нужен safe disposition по текущему run/claim state |
| CS-04 | Confirmed performance cost, instrumented | Первый `app:deployment-check --json` превысил внешний лимит 25 s; instrumented end-to-end run завершился за 24.45 s, SQLite quick/FK занял 23655 ms, остальные checks 0–303 ms; unavailable SQLite раньше прерывал FTS capability probe исключением | Сохранить полную integrity-проверку, фиксировать `duration_ms`, fail closed на каждом backend-dependent check и запускать с бюджетом >=30 s вне активной write-нагрузки | Implemented; rollout pending | 8 command feature tests / 60 assertions + live exit/result/timing | На растущей SQLite длительность может увеличиваться; это медленный gate, а не доказанное зависание |
| CS-05 | Confirmed problem, code fixed | Старый `app:health` сообщал queue pending=0 при import backlog >8000 из-за одного общего heartbeat/default queue; request memo мог удержать heartbeat после TTL в long-lived process; degraded CLI завершался exit 0 | Отдельные direct-store метрики/heartbeat для четырёх pools, idle loop/expiry и strict operational CLI exit при сохранении HTTP traffic readiness | Implemented; rollout pending | 7 health/queue tests, 49 assertions; combined 12/88; Larastan 0; live CLI `degraded`, 32325 pending, exit 1 | Запущенные worker-процессы получат scoped heartbeat только после verified deploy/restart; cache-warm worker пока отсутствует |
| CS-06 | Confirmed problem, operationally changed | Cache warm worker ранее отсутствовал | Установить/проверить versioned unit после снижения backlog и измерить drain | Worker active; health still degraded | Один `cache-warm-v2` worker активен вместе с 4 import и 8 title-refresh workers | Pending/delayed warming и heartbeat должны стабилизироваться; не масштабировать наугад при SQLite contention |
| CS-07 | Confirmed problem, fixed | Baseline Blade содержал 41 `request()`, 1 `config()` и auth/gate directives | Typed prepared navigation, route/class/permission flags, prepared directory maxlength and zero-tolerance contract | Implemented and browser verified | Zero matches across 52 Blade; 42/339 focused tests; full 840/6882 suite; 21/21 browser scenarios; view cache/build; changed-scope Larastan 0 | Remaining route/translation helper migration is tracked separately; forbidden infrastructure/request/config boundary is closed |
| CS-08 | Confirmed problem, fixed | Measured source had AppLayoutData 1,928 lines and layout 783 lines; no producer enabled `extended_seo`/`show_public_seo_blocks`, yet the complete generated matrix was built and reset | Remove unreachable query/keyword/schema matrix, return an explicit layout contract and pre-encode JSON-LD | Implemented and browser verified | 411/96 lines; rich-payload median 23.894→0.536 ms and p95 25.323→0.834 ms; 130/1198 focused, full 848/6928, Larastan 0, build and browser 21/21 | External Rich Results/URL Inspection remains a post-deploy check; no local test guarantees search appearance |
| CS-09 | Confirmed problem | Full Larastan level 6: 547 diagnostics; configured bounded scope: 0 | Расширять scope пакетами, исправлять real types, не создавать baseline | In progress | Direct `vendor/bin/phpstan` | Большой объём требует нескольких verified batches |
| CS-10 | Confirmed problem | 138/414 app PHP files без `strict_types` | Добавлять при изменении файлов и отдельными низкорисковыми batches | Pending | Static inventory | Массовая механическая правка без проверки запрещена |
| CS-11 | Confirmed problem | Snapshot/prepared storage и DB занимают гигабайты; volatile provider HTML меняет raw hash | Ввести semantic fingerprint/canonical snapshot policy и bounded retention | Pending importer/storage | DB/file size and 500-row normalization sample | Нельзя удалить forensic/source data без recovery contract |
| CS-12 | Confirmed problem | Recommendation v3 rebuild запускается каждым import cycle и занимает ~9 min | Перестраивать по changed title set/versioned dirty marker | Pending performance | Production timing + call graph | Нужен atomic consistency contract |
| CS-13 | Confirmed problem | Stats path и cold home path медленные под active import load | Snapshot/SQL profiling, bounded stats builder, cache recovery | Pending performance | Timed HTTP samples | Host load меняется; нужны before/after medians |
| CS-14 | Confirmed problem, partially reduced | CSP остаётся report-only и разрешает broad `https:`; обычный Livewire bundle ранее сообщал `unsafe-eval` | Task 27 включил установленный CSP-safe Livewire bundle; собрать remaining violations, сузить origins, затем staged enforcement | Pending security | Firefox catalog/filter/back-forward: CSP-safe asset `200`, 0 CSP errors | Нельзя включать enforcement вслепую из-за media/CDN и inline styles |
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

Repository baseline функционально зелёный, но безусловный production-ready verdict по-прежнему — **нет**. Debug mode выключен, все 110 migrations применены, maintenance mode снят, config/routes cached и PHP-FPM active. Финальный deployment preflight завершился `ready`, однако `app:health --json` честно остаётся `ready=true`/`degraded`: Memcached недоступен, а `cache-warm-v2` после worker restart имеет delayed work без свежего heartbeat; import/title-refresh pools отвечают. Кроме того, Task 29 не наблюдала pre-migration backup evidence внешних batches 31–33. Эти операционные пункты имеют более высокий приоритет, чем optional Octane, Pulse, Horizon, визуальный redesign или новые product capabilities.

## Task 10 — collections baseline and implemented boundary

Repository-wide audit не нашёл прежнего named collection domain, public collection URLs, cover/order/visibility rows, collaborators/likes/follows или duplicate architecture. Watchlist (`catalog_title_user_states.in_watchlist`) был единственным personal grouping и сохранён независимо. Generic discussion появился как общий target domain и переиспользован для collection comments/reactions/reports, без новой comment table.

Реализация добавляет одну serial-only aggregate с UUID identity, user/editorial/system types, private/unlisted/public semantics, moderation/reporting/feature, slug history, covers, item uniqueness/order, public/owner/admin/API/search/recommendation/profile/account/merge/cache/SEO/sitemap integrations и locale-aware editorial translations. Первоначальные `/collections`, `/lists`, `/selections` и `/admin/collections` позднее удалены без redirects по продуктовому решению: public explorer теперь вложен только в `/discover/popular`, а управление коллекциями — только в `/admin/catalog`; detail/profile/cover/API contracts сохранены. Collection likes/follows/collaborators/smart rules остаются explicit unsupported boundaries, а не fake controls.

Production verdict не меняется до normal migration/rollout: live DB ещё не содержит Task 10 tables или пользовательских rows. При этом отдельная SQLite копия прошла полную migration/rollback, schema/index plans, guest/owner/second-user privacy, API/sitemap, Chromium desktop/mobile, CRUD/restoration, staged membership, ordering, reporting, SEO и locale acceptance. Task 10 запретил automated tests; это ограничение явно сохраняется в verification report и не превращается в ложный «полностью протестировано» claim.

## Task 12 — discussions baseline and implemented boundary

Repository-wide Markdown/routes/schema/model/service/view/cache/notification/privacy audit подтвердил полное отсутствие legacy user comments, replies, reactions/votes, mentions, blocks/mutes, reports, restrictions, database notifications, public comment URLs или competing tables. `catalog_title_reviews` оказался provider review source и сохранён отдельным. Поэтому новая additive `comments` architecture является первой и единственной, а unique constraints не требуют destructive reconciliation.

Реализация покрывает allowlisted title/season/episode/collection targets, stable ID/anchors, flattened bounded replies, escaped Unicode plain text, server-hidden spoiler/long body, edit/soft-delete/restore/tombstone, up/down, body-free notifications/preferences, blocks/mutes, reports/moderation/restrictions, rate/idempotency/duplicate controls, profile export/deletion, admin, title merge, locale routes, target cache versions, SEO exclusion и accessible Livewire UI. Task 14 позднее добавил явный privacy-controlled public-profile comments tab; follow-up вернул его rows/count в канонические `CommentProfileQuery`/`CommentPresenter`, не создавая author-activity domain. Mentions, edit history, premium assets, общий public activity feed и persistent drafts остаются отсутствующими product domains без fake controls.

Во время Task 12 live production-style SQLite не изменялась. Позже отдельно раскрытый cached-config rehearsal Task 11 применил общий pending batch 14, включая discussion schema; это не считается rollout Task 12 и зафиксировано в `MAINTENANCE_LOG.md`. Disposable SQLite подтвердил четыре базовые discussion migrations и focused relationship-pagination index migration, clean down/up, eight-table schema, required indexes/query plans и foreign-key integrity; source route inspection с bypass stale cache зарегистрировал canonical/localized/private/admin discussion names. Task 12 прямо запрещает automated tests: verification использует static analysis, disposable schema, syntax/routes/translations/Blade/security/cache/build и полный manual author/reader/moderator browser smoke, сохраняя честный residual risk отсутствия regression suite и production rollout observation.

## Task 13 — reviews baseline and implemented boundary

Repository-wide Markdown/routes/schema/model/API/import/UI/rating/progress/social/account/cache/SEO audit confirmed exactly one title-only `catalog_title_reviews` source and one legacy read-only API route. Production-style SQLite contains 73 101 provider reviews across 22 474 titles, no duplicate title/body-hash groups, orphan targets/sources, empty/invalid hashes or detected unsafe HTML-like content. There were no user reviews, vote/report/restriction/moderation/profile/direct routes, season/episode review products or competing rating rows to reconcile.

Follow-up 18.07.2026 preserves that historical pre-community baseline and audits the now-populated canonical domain separately: 1 720 085 rows/3 294 158 votes/79 aliases, no competing review/comment/rating architecture, duplicates, invalid codes or orphan relations. One incomplete moderator-removal tombstone led to a scoped writer/demo/schema/privacy/localized-route hardening and pending idempotent evidence repair; no review text, identity, rating, vote, report, provider mapping or working database row was changed during the audit.

Implementation additively extends the same stable ID table with provider/user origin and community lifecycle, while reusing the unique 1–10 portal rating, authorized episode progress evidence, generic blocks/mutes/database notifications, account lifecycle, title merge and cache domains. It adds title/body/plain-text validation, whole spoiler, one current user review, helpful/not-helpful, sorting/filtering/pagination, private self history, reports/moderation/review restrictions, body-free notifications, direct aliases and accessible RU/EN title/profile/admin Livewire UI. Comments remain independent and own season/episode conversation.

Task 13 не выполнял production migration/rollback. Однако read-only comparison после отдельно раскрытого cached-config incident Task 11 обнаружил `220000` в batch 14 с ранней in-flight формой: provider data сохранились, но четыре final review columns и nullable report dedup contract отсутствовали. Additive repair `235100` покрывает этот rolling-schema разрыв и позже был штатно применён. Isolated SQLite migration/schema/index/query inspection and allowed static/route/translation/security/cache/SEO/build/browser checks are recorded in the verification report; Task 13 explicitly prohibits creating or running automated tests. Sentiment, Markdown, emoji reactions, review search/index pages and review JSON-LD remain intentional audited boundaries. Task 14 later introduced an explicit-privacy public-profile review tab, not a second author-review domain; its read/count projection now reuses the canonical Task 13 query/presenter.
