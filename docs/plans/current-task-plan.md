# Текущая задача — Task 106: восстановление импорта Seasonvar

## Реестр активных workstreams

| Workstream | Status | Evidence |
|---|---|---|
| Устранить падение импорта, восстановить свежие серии и исключить вечную блокировку мёртвым sync-run | `completed: push_unresolved` | [plan](../superpowers/plans/2026-07-26-seasonvar-import-recovery.md), [compliance](task-106-seasonvar-import-recovery-compliance.md) |

## Реестр blocked/unresolved

| Workstream | Status | Evidence |
|---|---|---|
| Полный global queue cycle после стартового SQLite writer spike | `unresolved` | Пустой run `#1294` оставлен canonical stale recovery без ручного изменения ledger |
| Полный Seasonvar test filter | `unresolved` | 302/304 GREEN; два baseline blocker описаны в archived evidence и не входят в Task 106 |
| Push из общего dirty checkout | `unresolved` | Прерванная Task 107 оставила foreign paths, которые запрещено смешивать с Task 106 |

## Task-specific compliance matrix

| Requirement | Status | Evidence |
|---|---|---|
| Requirement/docs/version/implementation preparation | `completed` | Root/index/canonical owners, Laravel 13 docs, production state и current implementation проверены |
| TDD и scoped implementation | `completed` | RED 3/3, GREEN 88/88 focused tests, 591 assertions, scoped Pint GREEN |
| Production title refresh | `completed` | 14 groups completed, 30/30 pages applied, queues drained, Redis state completed, HTTP 200 |
| Scheduler, backup и data safety | `completed` | Один queued producer от `www`, 13 worker units active, verified backup, без queue/cache clear |
| Public/auth/API/cache/SEO compatibility | `already_compliant` | Contracts не менялись; targeted finalizer и cache invalidation использованы штатно |
| Dependencies/assets/build | `not_applicable` | Composer/npm/lock/Vite assets не изменялись |

## Последнее подтверждённое evidence

- [Task 106: production recovery и verification](archive/2026-07-26-seasonvar-import-recovery-evidence.md)
- [Прерванная Task 107 сохранена отдельно](archive/2026-07-26-title-similar-recommendations-interrupted-evidence.md)

## Discovery

- Production title-refresh finalizer падает на отсутствующем
  `licensed_media.subtitle_language`; соответствующая additive migration и две
  обязательные importer-ledger migrations существуют, но не применены.
- Worker запускается как `www`, а текущий daily log создан `root:root 0644`;
  поэтому исходная SQL-ошибка маскируется вторичной ошибкой записи журнала.
- Дополнительная aaPanel cron task каждую минуту запускает полный sync от
  `root`, тогда как штатные workers и отдельный queued cron работают от
  `www`. Именно этот постоянный producer снова создаёт daily log с неверным
  ownership; одного `chown` недостаточно.
- Global sync-run `#1274` принадлежит отсутствующему PID, но coordinator
  автоматически закрывает только stale queue-runs. После истечения cache lock
  доступный lock не запускает process inspection, и cron `--queued` навсегда
  переиспользует мёртвую строку.
- Семь visitor refresh runs полностью подготовили страницы, но их группы
  остановлены в `finalizing`; новые episodes уже появились, а media apply и
  cache invalidation не завершились.
- Фактический stack: PHP 8.5.8, Laravel 13.22.0, Boost 2.4.13, Livewire 4.3.3,
  Pint 1.29.3, PHPUnit 12.5.32, Node 26.4.0, npm 12.0.1, SQLite.
- Task 107 завершилась аварийно до commit. Её незакоммиченные product-файлы
  остаются нетронутыми, а вытесненный registry сохранён в
  [interrupted evidence](archive/2026-07-26-title-similar-recommendations-interrupted-evidence.md).
- Recovery-операция Task 106 также прервалась после создания проверенного
  SQLite backup и применения трёх точных migrations: maintenance marker
  остался включён, а 4 import и 8 title-refresh workers — остановленными.
  После снятия maintenance публичный UI ставит refresh jobs, но они не
  обрабатываются: `seasonvar-title-refresh` содержит 4 pending jobs, семь
  подготовленных групп остаются в `finalizing`, поэтому карточки бесконечно
  показывают «Обновляем данные».
- aaPanel task `import video` уже имеет private pre-change backup, но active
  generated script и panel DB всё ещё содержат `sudo -u root`; текущий global
  sync run `#1289` подтверждён живым root-процессом. Одновременное
  восстановление aaPanel sync и canonical queued cron нарушило бы обязательную
  взаимоисключаемость producer-профилей. Поэтому sync task отключается,
  её сохранённая команда переводится на `www` как fail-safe, а активным
  расписанием остаётся только queued cron пользователя `www`. Текущий run
  завершается штатным `SIGTERM`, который pipeline фиксирует как `cancelled`.
- После одновременного старта 13 writers первый global queued dispatcher
  получил transient SQLite lock уже после создания run `#1294`; claims,
  prepared rows и groups он не создал, но повторная запись terminal failure
  попала в тот же lock. Строка остаётся под штатной
  `SEASONVAR_QUEUE_STALE_AFTER_MINUTES` recovery без ручной mutation. Это не
  блокирует targeted title refresh: обе очереди дренированы, 14 проверенных
  groups завершены, 30/30 prepared pages применены, refresh state восьми
  карточек — `completed`.
- Финальный repository search нашёл stale requirement paths:
  `docs/environment.md` требовал проверять только forever-profile после
  reload, а `TD-015` всё ещё считал producer ownership нерешённым. Scope
  расширен на эти два owner-файла до их редактирования; application contract
  при этом не меняется.

## Scope и совместимость

- Ожидаемые tracked changes: import command, два regression-test файла,
  `phpunit.xml`, task plan/compliance/evidence, importer/queue/development/
  deployment/environment/technical-debt docs, `README.md`, `CHANGELOG.md`.
- Runtime changes: согласованные private backups SQLite и exact scheduler
  config, отключение дублирующей aaPanel sync task с fail-safe заменой
  пользователя `root` на `www`, восстановление единственного queued cron
  пользователя `www`, три точные additive migrations, ownership текущего
  Laravel daily log, graceful worker restart и штатный queued import.
- Сохраняются: единственная публичная команда `seasonvar:import`, её options и
  exit codes; route/API/Livewire contracts; queue names и serialized job
  payload; модели, source identities, claims, cache keys, права, локализации,
  sitemap/SEO и media delivery boundary.
- Не выполняются: `migrate:fresh`, общий migration rollout, queue/cache clear,
  массовый retry/forget failed jobs, ручное изменение import rows, загрузка
  видео, изменение `.env`, dependency update или schema rollback.

## Риски

| Область | Решение |
|---|---|
| SQLite/schema | maintenance + остановка writers + проверенная private backup; применяются только три заранее проверенные additive migrations |
| Конкурентный импорт | PID/command inspection закрывает sync-run только без подтверждённого Linux-процесса; canonical start-lock сохраняется |
| Queue/finalizer | jobs/claims не очищаются; после schema fix используются существующие retry/watchdog boundaries |
| Logging | PHPUnit переводится в `null` channel; production daily log получает только точное ownership исправление |
| Scheduler | root aaPanel cron остаётся снятым, panel task отключается, её body/generated script переводятся на `www` как fail-safe; восстанавливается только canonical `www` queued cron |
| Cache/search/recommendations | существующие finalizer и `CatalogCacheInvalidator` остаются единственными boundaries; store-wide flush запрещён |
| Rollback | code можно вернуть обычным revert; additive columns/indexes оставляются для roll-forward, восстановление backup допустимо только при остановленных writers и подтверждённой потере целостности |
| Foreign worktree | Task 107 paths не stage-ятся и не изменяются; они могут оставить push `unresolved`, но не смешиваются с Task 106 commit |

## Живой checklist

| Этап | Статус |
|---|---|
| Requirements, docs, versions, production diagnostics | completed |
| Exclusive lease, exact manifest, plan и compliance | completed |
| RED regression tests | completed |
| Minimal code/config fix | completed |
| Backup, migrations, permissions, worker recovery | completed |
| Queued import и visitor-facing verification | completed |
| Requirements reread, docs/evidence и exact `main` commit | completed |
| Normal push из clean worktree | unresolved |
