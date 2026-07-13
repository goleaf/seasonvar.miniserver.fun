# Живой аудит и план улучшений

Обновлено: 13.07.2026

Этот документ — бессрочный backlog технических и продуктовых улучшений. Он не ограничен текущим релизом: новые подтверждённые риски добавляются с постоянным ID, выполненные пункты переносятся в `CHANGELOG.md` и `docs/MAINTENANCE_LOG.md`, а приоритеты пересматриваются после каждого production incident, изменения нагрузки или provider contract.

## Подтверждённая исходная точка

- Ветка: только `main`.
- Runtime: PHP 8.5.8, Laravel 13.19.0, Livewire 4.3.3, PHPUnit 12.5, SQLite 3.46, Vite 8, Tailwind CSS 4.3, Plyr 3.8 и HLS.js 1.6.
- Production-like база на момент проверки: более 34 тысяч тайтлов, 596 тысяч серий и 655 тысяч media rows; `PRAGMA quick_check` вернул `ok`, foreign-key violations и проверенные дубли identity/hierarchy/pivot/user-state не обнаружены.
- Из десяти настроенных Redis workers девять работают, а `seasonvar-import-worker@21` остаётся в `failed` после подтверждённого 128-МБ падения старого recommendation builder. На момент проверки Redis queue была пуста; полный ручной repeat import не запускался поверх production-like workers, а идемпотентность проверена существующими PHPUnit-сценариями на SQLite in-memory и `Http::fake()`.
- Focused end-to-end suite: 117 тестов, 906 assertions, все прошли. Он охватывает каталог, multi-filter, URL state, playback/progress, history/Continue Watching, watchlist/rating, authorization, SSRF и повторный импорт.
- Реальная browser matrix после исправления URL: HTTP 200 на 390/768/1440 px, два результата для сложной комбинации фильтров, horizontal overflow 0, mobile dialog возвращает focus, title/player shell серверно отрисованы. Media requests в проверке намеренно блокировались.
- Главная до query-правки собиралась примерно за 16,8 с и 30 SQL queries под активным импортом; замена materialized media subquery снизила тот же builder до примерно 9,7 с. Публичные HTTP timings всё ещё нестабильны из-за SQLite write contention.
- `storage/logs/laravel.log` достиг примерно 1,2 ГБ. В проверенном хвосте не найдены authorization headers, private keys или явные token/password query values, но текущие `APP_ENV=local`, `APP_DEBUG=true`, `LOG_STACK=single` и `LOG_LEVEL=debug` неприемлемы для production.
- Laravel Boost был запрошен для application info и versioned docs, но обе попытки завершились `Transport closed`; версии подтверждены Composer/Artisan/npm/vendor metadata.

## Исправлено в рамках аудита

- Устранён публичный `500`, вызванный `session.connection=sessions` при фактическом database session driver: workload connection теперь выбирается только для Redis sessions, а `.env` не изменяется кодом.
- Unindexed URL arrays (`year[]=...`, `genre[]=...`) канонизируются сервером до гидратации Livewire. Это предотвращает лишние `year.`, `genre.`, `actor.` параметры, сохраняет допустимую страницу и даёт стабильный refresh/back-forward URL.
- Список доступных тайтлов главной больше не сортируется по дорогому агрегату всех media rows: применяется visibility-first `EXISTS` и детерминированная свежесть каталога.
- Тяжёлые публичные headline counts вынесены в отдельную cache boundary с точной инвалидизацией после административных изменений и финализации sync/queued import.
- Для глобальной ленты media добавлена additive reversible migration индекса `(status, published_at, id)`. К финальной read-only проверке она уже была применена внешним concurrent process в batch 7; `quick_check` остался `ok`, а EXPLAIN выбрал новый индекс.
- Recommendation index теперь заранее выбирает только разделяемые несколькими видимыми тайтлами признаки grouped SQL-запросами, вместо построения и последующего удаления десятков тысяч singleton PHP buckets. Eloquent hydration дополнительно ограничен измеренным chunk size 100: production-like profile index из 34 319 тайтлов завершился при `memory_limit=128M` с пиком 121 110 528 bytes; намеренно завышенный chunk 500 воспроизвёл OOM и теперь нормализуется до безопасного потолка.
- PHPUnit compiled views изолированы от production view cache, чтобы root test process не создавал файлы, недоступные PHP-FPM. Повреждённое runtime-владение было восстановлено без удаления cache files.

## Правила приоритета

- **P0** — текущий security/availability/data-integrity риск; блокирует уверенный production rollout.
- **P1** — высокий риск или подтверждённое узкое место ближайшего релиза.
- **P2** — существенное улучшение качества, масштаба или пользовательского опыта после P0/P1.
- **P3** — стратегическое развитие, требующее продуктового или инфраструктурного решения.
- **P4** — долгосрочная поддерживаемость и экспериментальное развитие без текущего production impact.

## P0

### AUD-001 — Production environment и debug exposure

- **Проблема:** публичный runtime сообщает `APP_ENV=local` и `APP_DEBUG=true`; изменение `.env` не входит в разрешённые code changes.
- **Влияние:** исключения могут раскрывать пути, SQL, внутренние классы и request context; debug rendering увеличивает стоимость ошибок.
- **Предлагаемое решение:** через secret/environment manager установить `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`, затем собрать config cache и перезагрузить PHP-FPM/workers.
- **Зависимости:** доступ к production environment и согласованное короткое deployment window.
- **Риски:** stale config cache или неодинаковые значения у web и queue processes.
- **Критерии приёмки:** `/up` и публичные error responses не раскрывают stack trace; `php artisan about --only=environment` показывает production/debug disabled во всех process pools.
- **Проверка:** безопасный запрос несуществующего маршрута, controlled exception в staging, `php artisan config:show app`, проверка process-manager environment.
- **Приоритет:** P0, security/deployment.

### AUD-002 — SQLite contention между web и десятью import workers

- **Проблема:** production-like SQLite одновременно обслуживает тяжёлые public reads и массовые importer writes; зафиксированы 12–30-секундные ответы и browser timeout.
- **Влияние:** каталог, статистика и фильтры становятся нестабильными; lock storms могут давать 5xx и задерживать jobs.
- **Предлагаемое решение:** немедленно ограничить importer concurrency по измеренному write budget, вынести тяжёлые finalizer/backfill окна из пикового трафика и определить порог миграции каталога на PostgreSQL.
- **Зависимости:** реальные p50/p95/p99, queue wait, SQLite busy/lock metrics и traffic profile.
- **Риски:** слишком сильное ограничение workers увеличит freshness lag; перенос СУБД требует проверки SQLite-specific SQL/FTS.
- **Критерии приёмки:** p95 public catalog ниже согласованного SLO при активном импорте, 5xx из-за locks равен нулю, queue lag остаётся в допустимом окне.
- **Проверка:** контролируемый load test с 0/2/5/10 workers, query timings, lock counters, повторный idempotent import.
- **Приоритет:** P0, database/performance/importer reliability.

### AUD-003 — Неограниченный single log и privacy retention

- **Проблема:** текущий single debug-log занимает 745 707 553 байта (около 711 MiB); rotation/retention в фактическом environment не включены.
- **Влияние:** риск заполнения диска, медленные incident searches и избыточное хранение request/personal context.
- **Предлагаемое решение:** переключить production на daily structured logs с ограниченным retention, настроить OS/container rotation, disk alerts и документированную redaction policy.
- **Зависимости:** AUD-001, доступ к process manager/log collector и утверждённый retention period.
- **Риски:** слишком короткий retention ухудшит расследования; `copytruncate` может потерять строки при высокой записи.
- **Критерии приёмки:** ни один app/worker log не превышает установленный размер, старые файлы удаляются по policy, секрет-скан остаётся чистым.
- **Проверка:** форсированная rotation в staging, проверка владельца/прав, disk alert и выборочный поиск token/password/private URL markers.
- **Приоритет:** P0, observability/security/maintenance.

### AUD-004 — Незавершённый rollout importer worker unit

- **Проблема:** на финальной проверке активные import workers отсутствуют, а `seasonvar-import-worker@21.service` остаётся в `failed` после старого recommendation OOM. Repository unit уже задаёт `memory_limit=256M`, Laravel recycle `--memory=192` и `Restart=always`, но установленный systemd rollout ещё нужно сверить и безопасно выполнить после deployment.
- **Влияние:** новые queued imports и browser-инициированные targeted refreshes останутся в Redis до запуска worker; повторный тяжёлый finalizer на не обновлённом unit может снова упасть.
- **Предлагаемое решение:** после merge/push установить versioned unit, выполнить `systemctl daemon-reload`, безопасно перезапустить все importer instances после опустошения очереди и отдельно восстановить instance 21; не поднимать старый 128-МБ unit поверх текущего кода вручную.
- **Зависимости:** завершённый deploy этого commit, maintenance window, проверка пустой/reserved queue и свободной памяти для десяти процессов.
- **Риски:** одновременный restart может прервать активную job; слишком высокий параллельный RSS может создать host-level pressure, поэтому concurrency надо сверить с AUD-002.
- **Критерии приёмки:** все десять units находятся в `active (running)`, используют `-d memory_limit=256M --memory=192`, повторный finalizer завершается без OOM, failed instance отсутствует.
- **Проверка:** `systemctl list-units 'seasonvar-import-worker@*.service'`, `systemctl show` для ExecStart/Restart, `queue:monitor`, journal OOM scan и controlled finalizer/recommendation rebuild.
- **Приоритет:** P0, importer reliability/deployment/operations.

## P1

### AUD-101 — Реальный playback contract и DRM/provider expiry

- **Проблема:** unit/feature coverage проверяет authorization и safe resolver, но browser audit блокировал media traffic и не доказывает полный licensed-provider lifecycle.
- **Влияние:** истёкшая подпись, provider token refresh, DRM или CDN CORS могут ломать просмотр после успешной серверной авторизации.
- **Предлагаемое решение:** добавить staging provider fixtures/approved test asset, проверить source fallback, expiry renewal, seek/range/CORS и документировать DRM flow без обхода защиты.
- **Зависимости:** официальные provider credentials/test media и юридически разрешённый contract.
- **Риски:** тестовые credentials могут утечь; внешняя нестабильность создаст flaky CI.
- **Критерии приёмки:** start/pause/seek/resume/end проходят в desktop/mobile staging, expired authorization восстанавливается безопасно, fallback не пересекает episode boundary.
- **Проверка:** Playwright staging run, provider request audit, progress/session DB assertions и safe-log scan.
- **Приоритет:** P1, playback/security/product.

### AUD-102 — Исторические failed jobs и importer recovery SLO

- **Проблема:** в production-like `failed_jobs` накоплены сотни исторических записей; без классификации нельзя отличить исправленные, retryable и permanent failures.
- **Влияние:** реальные regressions теряются в шуме, повторные запуски могут отставать, admin status хуже отражает actionable backlog.
- **Предлагаемое решение:** агрегировать failures по sanitized category/provider/run age, определить retry/forget retention workflow и alert на новые permanent/partial spikes.
- **Зависимости:** стабильные failure categories, queue metrics и согласованная retention policy.
- **Риски:** массовый retry создаст нагрузку и provider rate-limit; удаление до экспорта потеряет forensic context.
- **Критерии приёмки:** каждый failure имеет owner/category/disposition, retry выполняется bounded batches, stale running recovery проверен.
- **Проверка:** read-only failure report, controlled retry test на non-production data, повторная проверка idempotency/counts.
- **Приоритет:** P1, importer reliability/queues/observability.

### AUD-103 — Integrity gates для следующих additive indexes

- **Проблема:** metadata lookup и home media feed indexes уже попали в batch 7, но production workflow ещё не блокируе будущий SQLite schema change при живых writers и не требует автоматический pre/post integrity report.
- **Влияние:** cold queries остаются медленными, а неправильный rollout может удерживать schema lock.
- **Предлагаемое решение:** остановить writers, сделать проверенную backup-копию, выполнить duplicate/FK/quick checks, применить migrations строго по timestamp и перезапустить workers.
- **Зависимости:** maintenance window, свободное место минимум для backup и SQLite index build.
- **Риски:** продолжительный exclusive lock, недостаток диска или расхождение кода и schema при частичном deploy.
- **Критерии приёмки:** оба индекса присутствуют с точным порядком колонок, migrations отмечены выполненными, integrity checks чисты.
- **Проверка:** `migrate:status`, `PRAGMA index_list/index_info`, `PRAGMA quick_check`, `PRAGMA foreign_key_check`, EXPLAIN до/после.
- **Приоритет:** P1, database integrity/deployment/performance.

### AUD-104 — Tiered cache rollout и failure degradation

- **Проблема:** Redis/Memcached cache architecture требует согласованного production rollout; database cache и live workers всё ещё отражают прежние defaults.
- **Влияние:** неверный store/connection может дать public 500, cache stampede или смешение workload keys.
- **Предлагаемое решение:** включать named stores по этапам, оставить БД source of truth, проверить versioned invalidation, lock timeout, Memcached miss и Redis failover до переключения defaults.
- **Зависимости:** доступные Redis DB/prefixes, Memcached extension/service, AUD-001 и CI infrastructure tests.
- **Риски:** cache poisoning из неполных dimensions, stale authorization data, скрытая зависимость от disposable Memcached.
- **Критерии приёмки:** public caches не содержат private/profile/signed URL data, importer/admin invalidation повышает domain version, cold path корректен без обоих cache servers.
- **Проверка:** real-store integration tests с уникальными prefixes, forced miss/outage test, cold/warm query and latency measurements.
- **Приоритет:** P1, caching/performance/security.

### AUD-105 — Search index version rollout и quality monitoring

- **Проблема:** изменение normalization/document version требует полного production FTS rebuild после завершения import; старый index обязан безопасно уходить в legacy fallback.
- **Влияние:** смешанные версии ухудшат recall для `ё/е`, aliases, people и external IDs или скроют новые тайтлы.
- **Предлагаемое решение:** выполнить checkpointed rebuild в отдельном окне, публиковать ready version только после count/integrity gates и поддерживать acceptance corpus.
- **Зависимости:** завершённые import runs, свободное место и search command lock.
- **Риски:** долгий SQLite FTS rebuild конкурирует с web reads; плохой corpus создаёт ложную уверенность.
- **Критерии приёмки:** document/title counts совпадают, FTS orphan count равен нулю, acceptance precision/recall thresholds выполнены.
- **Проверка:** `catalog:search-rebuild`, state/version query, existing search acceptance/index/synchronization suites и representative browser queries.
- **Приоритет:** P1, search/localization/deployment.

### AUD-106 — CSP и browser security policy

- **Проблема:** базовые security headers есть, но строгая Content-Security-Policy/reporting не подтверждена для Livewire, Vite, Plyr и provider media.
- **Влияние:** XSS impact остаётся выше необходимого; слишком строгий CSP без инвентаризации может сломать player/Livewire.
- **Предлагаемое решение:** начать с report-only CSP, собрать реальные violations, allowlist только локальные assets и approved media origins, затем включить enforcement.
- **Зависимости:** AUD-101, endpoint/collector для CSP reports и полный asset inventory.
- **Риски:** блокировка Livewire scripts/styles, HLS workers, posters или licensed streams.
- **Критерии приёмки:** enforcement не требует `unsafe-eval`, inline exceptions минимальны и nonce/hash управляются централизованно, functional matrix зелёная.
- **Проверка:** browser console/CSP reports, direct XSS payload tests, headers inspection и Playwright playback/catalog suite.
- **Приоритет:** P1, security/frontend.

### AUD-107 — Browser matrix в CI после Redis/Memcached integration

- **Проблема:** backend CI уже поднимает реальные Redis/Memcached с run-specific prefixes и проверяет stores, tags, versions, locks, sessions, queue и outages; responsive browser journeys пока не являются автоматическим merge gate.
- **Влияние:** lifecycle, focus, history и responsive overflow defects могут обнаруживаться только при ручном browser QA.
- **Предлагаемое решение:** добавить компактную Playwright matrix после production build, не дублируя уже работающий Redis/Memcached service stack.
- **Зависимости:** стабильный container/service setup и bounded fixture dataset.
- **Риски:** рост времени CI и flaky external media; media должен быть локальным/approved fixture.
- **Критерии приёмки:** существующий real Redis/Memcached gate остаётся зелёным; Livewire URL/back-forward, mobile dialog/focus и no-overflow добавлены в воспроизводимую CI browser matrix.
- **Проверка:** повторяемый CI run, intentional service outage failure и artifact screenshots/report.
- **Приоритет:** P1, testing/CI/accessibility/responsive.

### AUD-108 — Атомарный deployment и worker coordination

- **Проблема:** SQLite migrations, FTS rebuild, config changes, cache versions и десять workers требуют единого порядка, иначе процессы читают несовместимую schema/config.
- **Влияние:** partial deploy вызывает 5xx, duplicate work или stale cache/search visibility.
- **Предлагаемое решение:** оформить executable runbook: drain/stop writers, backup, migrate, optimize, version bump/warm, restart workers/PHP-FPM, health checks, traffic restore.
- **Зависимости:** AUD-001/003/103/104/105 и process-manager access.
- **Риски:** maintenance дольше бюджета; rollback к старому коду может быть несовместим с новой schema.
- **Критерии приёмки:** rehearsal на свежей копии проходит в заданное окно, rollback decision points явны, post-deploy checks автоматизированы.
- **Проверка:** staging rehearsal с timestamps, schema/cache/search versions, queue heartbeat и HTTP smoke matrix.
- **Приоритет:** P1, deployment/maintenance/documentation.

## P2

### AUD-201 — Livewire payload и render-query budgets

- **Проблема:** публичное состояние уже ограничено scalars/small arrays, но фактические snapshot/request/response bytes и повторные render queries не имеют постоянного бюджета.
- **Влияние:** рост facet/options/admin forms может незаметно ухудшить mobile latency и hydration cost.
- **Предлагаемое решение:** измерить payloads основных actions, установить budgets, вынести только доказанно тяжёлые независимые регионы в lazy/defer/islands.
- **Зависимости:** browser instrumentation и стабильные representative fixtures.
- **Риски:** чрезмерная декомпозиция увеличит число запросов; lazy loading не должен скрыть SEO-critical content.
- **Критерии приёмки:** budgets зафиксированы тестом/метрикой, один action не повторяет page-builder queries, public collections не попадают в snapshot.
- **Проверка:** Livewire network captures, query listener, serialized snapshot size и mobile throttling profile.
- **Приоритет:** P2, Livewire/performance.

### AUD-202 — Facet cache cardinality и count freshness

- **Проблема:** contextual own-group-excluded facets корректны, но cache dimensions могут разрастись пропорционально произвольным комбинациям.
- **Влияние:** низкий hit ratio, Memcached evictions и expensive rebuild bursts нивелируют пользу кеша.
- **Предлагаемое решение:** кешировать только нормализованные bounded popular combinations, сохранять точную version invalidation и измерять hit/cardinality до расширения.
- **Зависимости:** AUD-104, traffic analytics без raw search labels и domain telemetry.
- **Риски:** stale counts после relation sync; ошибочный key dimension смешает region/profile context в будущем.
- **Критерии приёмки:** totals совпадают с distinct result IDs, query count не зависит от числа options, lifecycle mutations обновляют counts.
- **Проверка:** existing advanced-filter/query-budget tests, cache hit report и importer/admin invalidation scenario.
- **Приоритет:** P2, filtering/caching/database.

### AUD-203 — Автоматизированная accessibility проверка

- **Проблема:** semantic/focus/touch contracts покрыты Blade tests и ручным browser audit, но нет axe/ARIA regression gate.
- **Влияние:** новые dialogs, player controls и admin forms могут нарушить keyboard/screen-reader flow.
- **Предлагаемое решение:** добавить локальный accessibility runner для catalog/title/watching/admin states с явным allowlist только подтверждённых исключений.
- **Зависимости:** AUD-107 и аутентифицированные non-production fixtures.
- **Риски:** механическое исправление scanner warnings может ухудшить UX; player third-party markup требует аккуратной оценки.
- **Критерии приёмки:** нет critical/serious violations, focus order и restoration предсказуемы, icon-only controls имеют labels.
- **Проверка:** axe report, keyboard-only walkthrough, screen-reader smoke test и contrast check.
- **Приоритет:** P2, accessibility/quality.

### AUD-204 — Responsive visual regression

- **Проблема:** текущая 390/768/1440 matrix чиста, но screenshots не являются стабильным CI baseline для всех ключевых states.
- **Влияние:** long translations, empty/error/loading/player states могут получить overflow или исчезающие counts.
- **Предлагаемое решение:** зафиксировать bounded visual baselines для catalog filters, title seasons/player, watching и importer active/error states.
- **Зависимости:** deterministic fixtures/fonts/assets и AUD-107.
- **Риски:** шум от динамических timestamps/posters; baseline нельзя обновлять без review.
- **Критерии приёмки:** zero horizontal overflow, targets не меньше 44 px, player DOM не пересоздаётся при resize/orientation.
- **Проверка:** screenshot diff, DOM geometry assertions, repeated orientation and navigation run.
- **Приоритет:** P2, responsive/frontend/accessibility.

### AUD-205 — Локализованный content model

- **Проблема:** interface translations отделены от media languages, но полноценные локализованные title/description/slug records отсутствуют.
- **Влияние:** расширение beyond Russian приведёт к fallback ambiguity, duplicate canonical pages и сложному editorial ownership.
- **Предлагаемое решение:** спроектировать additive translation table с locale/source/ownership/fallback и historical localized slugs до добавления новых публичных locales.
- **Зависимости:** product locale policy, SEO hreflang/canonical design и importer field ownership.
- **Риски:** дубли slug, provider overwrite и неверное использование UI locale как audio preference.
- **Критерии приёмки:** deterministic fallback, unique locale slug, safe redirect history, media preferences независимы от interface locale.
- **Проверка:** migration/backfill rehearsal, plural/long-text tests, canonical/hreflang crawler audit.
- **Приоритет:** P2, localization/SEO/database.

### AUD-206 — Profiles, parental controls и entitlement persistence

- **Проблема:** текущий продукт поддерживает только authenticated `User` как profile и public/authenticated audience; plan/region/PIN/concurrency storage отсутствует.
- **Влияние:** нельзя честно обещать household profiles, parental PIN или subscription decisions во всех server boundaries.
- **Предлагаемое решение:** после product decision добавить additive profile/ownership/restriction/entitlement schema и подключить её к существующему structured entitlement service.
- **Зависимости:** billing/territory/licensing requirements, privacy policy и migration/backfill design.
- **Риски:** IDOR между profiles, plaintext PIN, race concurrent streams и cache scope leakage.
- **Критерии приёмки:** каждая write/read проверяет profile ownership, PIN хэширован, catalog/direct playback дают одинаковое structured decision.
- **Проверка:** cross-profile/direct-URL tests, expired trial/region/age/concurrency cases и cache-key isolation.
- **Приоритет:** P2, security/product/database.

### AUD-207 — Единые operational dashboards и alerts

- **Проблема:** status pages/logs есть, но нет сводных SLO для web latency, locks, queue lag, import failures, cache hit/eviction и playback errors.
- **Влияние:** деградация обнаруживается пользователями или ручным просмотром логов.
- **Предлагаемое решение:** экспортировать low-cardinality metrics, определить alerts/runbooks и подключить существующий разрешённый collector без установки всех observability packages.
- **Зависимости:** AUD-003/104/102 и выбранная monitoring platform.
- **Риски:** high-cardinality labels с URL/user/search данными создадут privacy/cost issue.
- **Критерии приёмки:** dashboard показывает p50/p95/p99, error rate, SQLite locks, queue age, cache layers и playback categories; alerts actionable.
- **Проверка:** synthetic incident injection и подтверждение alert → runbook → recovery.
- **Приоритет:** P2, observability/operations.

## P3

### AUD-301 — Переход с SQLite при превышении capacity threshold

- **Проблема:** текущий объём и write concurrency приближаются к границе single-file SQLite architecture.
- **Влияние:** вертикальное кеширование не устраняет writer serialization и schema-lock windows.
- **Предлагаемое решение:** определить количественный threshold и подготовить PostgreSQL compatibility matrix, export/import rehearsal и dual-read validation без преждевременного cutover.
- **Зависимости:** AUD-002 measurements, infrastructure budget и FTS/search decision.
- **Риски:** различия collations, JSON, upsert, partial indexes, transaction locking и query plans.
- **Критерии приёмки:** полная копия проходит migrations/tests, counts/hashes совпадают, importer idempotency и latency не хуже target.
- **Проверка:** shadow database rehearsal, integrity comparison, EXPLAIN ANALYZE и rollback drill.
- **Приоритет:** P3, database/scalability.

### AUD-302 — Внешний search engine только по доказанной необходимости

- **Проблема:** SQLite FTS покрывает текущий поиск, но future typo tolerance/morphology/load могут превысить его возможности.
- **Влияние:** преждевременный Meilisearch/Typesense добавит distributed consistency; поздний переход ограничит качество поиска.
- **Предлагаемое решение:** вести quality/latency corpus и запускать architecture decision record только после нарушения threshold.
- **Зависимости:** AUD-105, traffic/query analytics и operations capacity.
- **Риски:** stale external index, visibility leakage и дополнительная production dependency.
- **Критерии приёмки:** выбранный engine улучшает измеряемые relevance/latency показатели и сохраняет visibility-first semantics.
- **Проверка:** offline corpus benchmark, failure/fallback test и import/admin synchronization rehearsal.
- **Приоритет:** P3, search/architecture.

### AUD-303 — Персонализированные рекомендации и discovery

- **Проблема:** текущие похожие тайтлы используют metadata signals, но не документированную пользовательскую personalization policy.
- **Влияние:** Continue Watching решает resume, но discovery после завершения остаётся ограниченным.
- **Предлагаемое решение:** определить privacy-safe implicit/explicit signals, diversity/explanation rules и offline evaluation до изменения ranking.
- **Зависимости:** AUD-206 profiles/consent и observability без raw personal labels.
- **Риски:** filter bubble, sensitive inference и недоступные рекомендации.
- **Критерии приёмки:** recommendations всегда проходят entitlement visibility, имеют bounded diversity и измеряемое улучшение approved metric.
- **Проверка:** offline replay, inaccessible-content tests, A/B plan с stop criteria.
- **Приоритет:** P3, product development/recommendations.

### AUD-304 — Immutable admin audit trail

- **Проблема:** optimistic concurrency защищает от silent overwrite, но отдельная неизменяемая история publication/source/editorial действий не является полным продуктовым контрактом.
- **Влияние:** incident investigation и юридический provenance зависят от общих logs/import events.
- **Предлагаемое решение:** добавить append-only audit records с actor/action/resource/version diff category без секретов и raw provider payload.
- **Зависимости:** retention/privacy policy и admin identity model.
- **Риски:** большой объём, персональные данные и возможность логировать private URLs.
- **Критерии приёмки:** публикация, архив, source change и import override имеют безопасную корреляцию и не редактируются обычным admin flow.
- **Проверка:** authorization tests, redaction scan, retention/export drill.
- **Приоритет:** P3, security/admin/maintenance.

### AUD-305 — Playback QoE без утечки provider data

- **Проблема:** player сообщает progress и safe states, но aggregate startup/buffering/error/fallback metrics не централизованы.
- **Влияние:** трудно отличить CDN/provider degradation от browser/network проблем.
- **Предлагаемое решение:** отправлять bounded sampled QoE events с category, coarse device/network и internal media ID hash; не включать signed/raw URL.
- **Зависимости:** consent/privacy policy, AUD-207 и provider SLA.
- **Риски:** high event volume, fingerprinting и stale callbacks.
- **Критерии приёмки:** old playback session не пишет в новый, payload не содержит URL/token, dashboard показывает startup/rebuffer/error rates.
- **Проверка:** browser session-switch tests, payload inspection, sampling/load test.
- **Приоритет:** P3, playback/observability/product.

## P4

### AUD-401 — Расширение documentation automation

- **Проблема:** managed docs blocks и ownership map есть, но architecture decisions, environment matrix и migration inventory всё ещё обновляются вручную.
- **Влияние:** документация может отстать от кода после быстрых importer/cache changes.
- **Предлагаемое решение:** расширять `project:docs-refresh --check` только детерминированными read-only секциями и link validation.
- **Зависимости:** стабильные источники metadata и review владельцев документов.
- **Риски:** генератор перезапишет осмысленный текст или создаст noisy diffs.
- **Критерии приёмки:** generated boundaries явны, команда ничего не меняет вне них, broken links/schema drift ломают CI.
- **Проверка:** idempotent double run, dirty-tree assertion и intentional broken-link fixture.
- **Приоритет:** P4, documentation/maintenance.

### AUD-402 — Static analysis и dependency maintenance cadence

- **Проблема:** Pint, PHPUnit и audits есть, но Larastan/PHPStan baseline и регулярный patch-update cadence не установлены.
- **Влияние:** type/query contract regressions обнаруживаются поздно, security patches зависят от ручного запуска.
- **Предлагаемое решение:** сначала ввести низкий zero-new-errors level без giant baseline, затем повышать строгость по bounded modules; автоматизировать reviewed patch PR cadence.
- **Зависимости:** ownership времени CI и совместимость Laravel 13/PHP 8.5 packages.
- **Риски:** шумный baseline, ложные positives и unrelated major upgrades.
- **Критерии приёмки:** новые errors блокируют CI, baseline уменьшается, audit findings имеют SLA.
- **Проверка:** static-analysis job, dependency audit и controlled outdated/vulnerable fixture.
- **Приоритет:** P4, quality/CI/maintenance.

### AUD-403 — Data retention и cold archive

- **Проблема:** source snapshots, import events, failed jobs, playback/history и logs растут с каталогом; единый retention contract неполон.
- **Влияние:** диск/backup растут, privacy obligations усложняются, operational queries замедляются.
- **Предлагаемое решение:** определить отдельные retention windows, chunked prune/archive jobs и legal holds; catalog truth и editorial provenance не удалять общей cleanup-командой.
- **Зависимости:** legal/privacy/product policy и backup restore testing.
- **Риски:** необратимая потеря forensic/user history или долгие delete locks SQLite.
- **Критерии приёмки:** каждая большая таблица имеет owner/window/archive rule, pruning bounded/idempotent и observable.
- **Проверка:** dry-run counts, non-production prune, backup restore и FK/integrity checks.
- **Приоритет:** P4, maintenance/database/privacy.

### AUD-404 — Управляемый product experimentation framework

- **Проблема:** будущие autoplay, completed shelf, ratings presentation и discovery changes не имеют общего feature-flag/acceptance framework.
- **Влияние:** продуктовые изменения могут смешать server authorization с UI experiment или создать несовместимые состояния.
- **Предлагаемое решение:** определить server-owned feature flags для обратимых UX решений, metrics/stop criteria и запрет flags на обход authorization/publication.
- **Зависимости:** AUD-207 observability, product owner и privacy review.
- **Риски:** stale flag cache, combinatorial states и permanent experiments.
- **Критерии приёмки:** каждый experiment имеет owner, expiry, fallback, acceptance metric и одинаковые security boundaries.
- **Проверка:** on/off matrix, cache invalidation, direct-route authorization tests и cleanup review.
- **Приоритет:** P4, product development/maintenance.

## Порядок ведения

1. P0 проверяется перед каждым production deploy; открытый P0 явно указывается в release report.
2. P1 планируется ближайшим release window только после назначения владельца и зависимостей.
3. P2–P4 можно дробить на отдельные design/implementation документы, но этот ID и acceptance criteria остаются ссылочной точкой.
4. Выполненный пункт не удаляется молча: реализация и команды проверки попадают в `CHANGELOG.md` и `docs/MAINTENANCE_LOG.md`, после чего здесь остаётся ссылка на результат или пункт заменяется следующим измеряемым риском.
