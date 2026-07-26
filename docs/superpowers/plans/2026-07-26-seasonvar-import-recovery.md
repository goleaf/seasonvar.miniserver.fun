# План восстановления импорта Seasonvar

**Цель:** восстановить поступление новых серий и видео из Seasonvar, устранить
обнаруженные schema/logging/lifecycle ошибки и доказать, что автоматический
queued cron продолжает работу без ручного изменения базы или очистки очереди.

## 1. Зафиксированная причина

1. `FinalizeSeasonvarImportTitleGroup` доходит до catalog/media apply, но
   deployed code обновляет `licensed_media.subtitle_language` раньше
   применения migration `2026_07_26_234000`.
2. После SQL exception worker не может открыть текущий daily log, потому что
   root PHPUnit/CLI создал его с ownership `root:root 0644`.
   Production discovery уточнил постоянный источник: aaPanel каждую минуту
   запускает полный sync как `root`. Одновременное возвращение этой task и
   отдельного queued cron нарушило бы обязательную взаимоисключаемость
   producer-профилей, поэтому active production profile остаётся только
   queued и работает от `www`.
3. Семь targeted groups сохраняют durable prepared payload, поэтому episode
   writes видны, а media apply, terminal state и cache invalidation не
   завершаются.
4. Dead sync-run блокирует global single-flight: `recoverStale()` ограничен
   queue mode, а CLI process inspection вызывается только при занятом cache
   lock. После исчезновения lock строка остаётся вечным active lifecycle.

## 2. Tracked implementation

### Tests first

- В `SeasonvarImportMaintenanceTest` добавить regression для dead sync-run при
  свободном cache lock в sync и `--queued` режимах.
- Добавить защиту живого sync-run: process confirmation не допускает mutation
  и не создаёт второй global run.
- В `ProductionOperationsDocumentationTest` зафиксировать
  `LOG_CHANNEL=null` с `force=true` в `phpunit.xml`.
- Запустить новые тесты до кода и сохранить ожидаемый RED.

### Minimal code/config

- В `ImportSeasonvar` вынести выбор running sync sitemap-runs и безопасную
  process inspection/recovery boundary.
- Вызывать boundary перед queued dispatch и после получения sync cache lock,
  но до global reconciliation/reservation.
- Закрывать только `mode=sitemap`, `execution_mode=sync`, `status=running`
  строки и только когда Linux process не подтверждён.
- Не force-release свободно полученный новый lock, не изменять queue-runs,
  targeted URL runs, payload, claims или публичный command contract.
- В `phpunit.xml` принудительно направить default test logging в существующий
  `null` channel.

## 3. Production recovery

1. Снять safe before-state: queue/run/group/schema/log ownership, failed count,
   process units и disk capacity без URL, payload или secrets.
2. Exact private backups root/www crontab, generated aaPanel script и panel
   task DB уже сохранены. Живой sync-run штатно остановить `SIGTERM`: signal
   handler импортера запрашивает stop после безопасной итерации и фиксирует
   terminal status `cancelled`, не требуя ручной mutation ledger.
3. Включить maintenance mode; штатно остановить 4 import, 8 title-refresh и
   cache-warm workers. Проверить отсутствие reserved jobs/live importer
   processes и новых web/scheduled writes.
4. Создать согласованную SQLite `.backup` в ignored private storage с mode
   `0600`; проверить ненулевой размер, SHA-256, `PRAGMA quick_check` и
   `PRAGMA foreign_key_check`.
5. Отключить exact aaPanel sync task в panel DB и заменить только execution
   user её body/generated script: `root` → `www` как fail-safe. Root crontab
   entry не возвращать. Восстановить только canonical queued cron пользователя
   `www`; остальные задачи, working directory и расписания не менять.
   Проверить отсутствие duplicate producer и синтаксис сохранённого script.
6. Исправить ownership только текущего Laravel daily log на runtime user,
   сохранив mode `0644`.
7. С `--force --isolated` отдельно применить:
   - `2026_07_25_120000_add_active_run_recovery_index_to_seasonvar_import_prepared_pages.php`;
   - `2026_07_25_130000_add_batch_dispatch_progress_to_seasonvar_import.php`;
   - `2026_07_26_234000_add_playback_translation_preferences.php`.
8. Проверить columns/indexes/migration status и SQLite integrity. При ошибке
   не запускать writers, не выполнять `migrate:fresh`, drop или общий rollback.
9. Вернуть traffic, выполнить graceful queue restart и запустить только
   остановленные штатные units. Подтвердить единственный active producer:
   canonical queued cron пользователя `www`; aaPanel sync disabled, root
   crontab entry отсутствует.
10. Запустить публичный `php artisan seasonvar:import --queued`: он должен
   закрыть только неподтверждённый sync-run, создать новый global queue-run и
   dispatch-ить bounded work.
11. Дождаться recovery зависших targeted groups и наблюдать отсутствие новых
   `subtitle_language`, permission и SQL fatal signatures.

## 4. Cross-feature impact

| Domain | Статус/проверка |
|---|---|
| Authentication/authorization/admin | unaffected; policies/gates/routes не меняются |
| Translations/mobile/accessibility | unaffected; новый user-facing CLI текст остаётся русским |
| Cache | affected; проверяется существующая targeted/global invalidation без общего flush |
| Search/recommendations | indirectly affected; finalizer должен завершить существующие stages либо честно оставить deferred dirty state |
| Notifications/audit | existing import run/error counters сохраняются; новых recipients/events нет |
| SEO/sitemap | routes/schema не меняются; discovery остаётся внутри queued importer |
| Privacy/security | URL, HTML, media address, token, secret и private backup path не попадают в tracked docs/final |
| Premium/region/legal/ads | unaffected; entitlement/media delivery boundaries не меняются |
| Production/cache/session/queue | affected; maintenance, backup, exact migrations и graceful restart обязательны |
| Scheduler/process ownership | affected; дублирующая aaPanel sync task отключается и получает fail-safe `www`, единственным active producer остаётся queued cron того же `www`, который владеет queue workers |

## 5. Совместимые contracts

- `php artisan seasonvar:import` остаётся единственной публичной командой.
- Options, output exit semantics, queue names/connections, serialized jobs,
  source identity, run/group/prepared states и claim tokens сохраняются.
- Сезоны и серии остаются внутри одного `CatalogTitle`; importer не скачивает
  видео body.
- Public routes, APIs, Livewire, policies, translations, cache key formats,
  search index and sitemap response contracts не меняются.
- Все 14 unrelated pending migrations остаются pending.

## 6. Rollback/failure recovery

- Application rollback: обычный revert Task 106 и graceful reload. Новые
  additive schema elements остаются, потому что deployed playback/import code
  уже зависит от них.
- Migration failure: maintenance и остановленные writers сохраняются; исходная
  ошибка и backup проверяются, затем выполняется roll-forward. DDL rollback
  требует отдельного data-loss approval.
- Data-integrity failure: только verified private backup restore при
  остановленных writers; после новых visitor writes предпочтителен
  roll-forward.
- Scheduler failure: exact root/www crontab, script и panel DB восстанавливаются
  из private backups; до повторного старта выбирается только один producer
  profile, остальные cron tasks не меняются.
- Stale cache: штатная invalidation/targeted warm; `cache:clear` запрещён.
- Unavailable provider: существующие bounded timeout/retry/backoff; run может
  завершиться `partial`, raw response не сохраняется в документацию.
- Interrupted build не применим: assets/dependencies не меняются.

## 7. Verification

- RED → GREEN focused regression tests.
- Полный `SeasonvarImportMaintenanceTest`, importer finalizer/preparation,
  subtitle/media and queue-contract tests; затем Seasonvar-wide suite.
- `./vendor/bin/pint app/Console/Commands/ImportSeasonvar.php --test --format agent`;
  `--dirty` не используется, чтобы не форматировать сохранённые foreign paths.
- Migration/schema/index/SQLite integrity, queue counts, group terminal state,
  worker units/restart trend, failed-job delta and safe journal signatures.
- aaPanel task body/generated script и следующий import process подтверждают
  runtime user `www`; новый daily log не возвращается к `root`.
- New global queued run, fresh `licensed_media`/episodes and HTTP 200 for home
  and affected public title without exposing provider URL.
- Requirements reread, repository legacy search, docs checks, exact staged
  review, `main` commit and normal push if foreign worktree permits.

## 8. Выполненный production outcome

- Verified backup и три additive migrations сохранены; private backup
  permissions исправлены.
- Sync run остановлен через штатный signal boundary, producer profile
  нормализован до единственного queued cron от `www`, 4+8+1 workers активны.
- 14 targeted groups завершены, 30/30 prepared pages applied, обе очереди
  дренированы, Redis refresh states terminal `completed`, title/home/readiness
  HTTP verification успешна.
- Пустой global queue-run после стартового SQLite-lock оставлен штатной stale
  recovery без ручной mutation; wide suite сохраняет два unrelated blockers.
