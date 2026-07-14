# Деплой

Обновлено: 14.07.2026

## Mobile offline-sync rollout от 14.07.2026

Миграция `2026_07_14_164423_create_api_sync_tables_and_add_state_versions` additive и обратима: она создаёт append-only `api_sync_changes`, idempotency receipts `api_sync_mutations` и добавляет независимые `watchlist_version`/`rating_version` с default `0`. Backfill не требуется. Код безопасен между deploy и migrate: sync publishers/prune пропускают работу без таблиц, а три sync routes возвращают очищенный `sync_unavailable`/503; остальные маршруты `/api/v1` продолжают работать.

SQLite migration нельзя запускать одновременно с импортом, pending/delayed/reserved jobs или live page claims. Порядок: `seasonvar:import --status` и `app:deployment-check` → дождаться нулевых активных writers/claims и остановить их штатно → backup SQLite → `migrate:status` → `migrate --force` → `config:cache` и `route:cache` → reload PHP-FPM/`queue:restart` → вернуть workers → повторить preflight/health и smoke manifest, public pull, authenticated owner pull/push. Очереди и claims не очищаются ради миграции.

После миграции внешний scheduler должен ежедневно запускать Laravel schedule, чтобы `api:sync-prune` выполнялся в 03:23 с `withoutOverlapping`/`onOneServer`. Changes старше 30 дней и receipts старше 90 дней удаляются пачками до 500; canonical user state/history/catalog эта команда не удаляет. Rollback сначала требует убрать sync routes/publishers из обслуживаемого кода, затем `migrate:rollback` удаляет только transport tables и version columns.

## Cache/queue rollout от 13.07.2026

Production environment должен соответствовать `docs/environment.md`: default domain cache — `redis-domain`, hot cache — `memcached-hot`, sessions — Redis `sessions`, queues — Redis `queues`, critical locks — `redis-locks`. Реальный `.env` не изменяется репозиторием. На одном standalone Redis DB/prefix separation допустима как начальная topology; managed production предпочтительно разделяет cache, queues, sessions и critical locks. Redis Cluster не поддерживает ту же DB-number стратегию. Horizon и Octane не устанавливались.

Безопасный rolling/maintenance порядок:

1. Проверить `git status`, backup SQLite и отсутствие активной catalog transaction; временно остановить writers, если pending migration добавляет SQLite index/table.
2. `composer install --no-dev --classmap-authoritative --no-interaction` и `npm ci && npm run build` на release artifact.
3. `php artisan migrate --force` только после read-only `migrate:status`/backup; текущие `180000` и `190000` migrations additive/reversible.
4. При несовместимой семантике ключа увеличить `CACHE_SCHEMA_VERSION`, при payload/serializer change — `CACHE_FORMAT_VERSION`. Не выполнять scan/flush.
5. `php artisan optimize` для config/events/routes/views. Не использовать обычный `optimize:clear`, потому что default application store shared.
6. `php artisan cache:warm-catalog --queue --refresh`, дождаться job и проверить `php artisan cache:metrics --json`.
7. `php artisan queue:restart`; перезапустить systemd import/cache-warm workers. Reverb/Horizon/Octane отсутствуют и не требуют reload.
8. `php artisan app:health --json`, public API conditional GET, sitemap GET/HEAD и три warm smoke requests.
9. Вернуть traffic/writers и наблюдать queue wait, cache failure/lock timeout, Redis memory, Memcached evictions и warm p95.

Установить cache-warm worker:

```bash
sudo cp deploy/systemd/seasonvar-cache-warm-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now seasonvar-cache-warm-worker.service
```

`/health/ready` разрешается только monitoring/load-balancer boundary и не раскрывает topology. Failed Redis sessions/queues/locks делает readiness failed; Redis cache/Memcached outage — degraded и требует снижения traffic/устранения причины до исчерпания cold-path capacity.

## Индекс ленты media и runtime safety от 13.07.2026

Миграция `2026_07_13_190000_add_home_media_feed_index` additive и обратима: она добавляет к `licensed_media` индекс `licensed_media_home_feed_idx (status, published_at, id)` для глобальной ленты новых видео. К финальной read-only проверке migration уже была `Ran` в batch 7 после внешнего concurrent rollout; EXPLAIN выбрал этот индекс, `PRAGMA quick_check` вернул `ok`. Для любой следующей SQLite migration всё равно нужно дождаться активных jobs, остановить writers, сделать backup и только затем выполнить `php artisan migrate --force`.

`SESSION_CONNECTION=sessions` допустим только при `SESSION_DRIVER=redis`. При database sessions переменная `SESSION_CONNECTION` должна быть пустой/отсутствовать, чтобы Laravel использовал default SQL connection. После любого изменения driver/connection нужно пересобрать config cache и проверить гостевой GET до возврата трафика.

Production обязан использовать `APP_ENV=production`, `APP_DEBUG=false`, `LOG_STACK=daily`, `LOG_LEVEL=warning` и bounded `LOG_DAILY_DAYS`. Репозиторий не изменяет реальный `.env`; эти значения задаются secret/process manager перед `php artisan config:cache`.

## История публичных slug и локализованные metadata от 13.07.2026

Миграция `2026_07_13_150000_create_catalog_title_slugs_table` additive: создаёт пустую таблицу прежних slug с unique `slug`, foreign key на `catalog_titles` и cascade delete. Источника для достоверного backfill прошлых адресов в базе нет, поэтому существующие slug не копируются: история начинает фиксироваться при следующем редакционном изменении или importer merge.

Порядок rollout: дождаться активных catalog/import writes → сделать backup SQLite → развернуть migration и код в одном maintenance window → выполнить `php artisan migrate --force` → `php artisan config:cache` → `php artisan queue:restart`. Web и workers не должны запускать новый код до migration, потому что admin validation, importer slug allocation и route binding обращаются к новой таблице. Rollback удаляет только накопленную историю redirects и не меняет текущие `catalog_titles.slug`; перед rollback нужно учитывать, что старые ссылки после этого перестанут перенаправляться.

После deploy проверить текущий URL карточки, `301` со старого slug без query string, canonical/OG/JSON-LD и русские plural-формы. Добавление языковых prefixed routes в этот rollout не входит: публичные URL остаются прежними.

## Health monitoring видеоисточников от 13.07.2026

Миграция `2026_07_13_164039_add_provider_availability_to_source_pages_table` additive: добавляет nullable provider availability status/check timestamp и индекс `source_pages_provider_availability_retry_idx` без удаления или переписывания каталога. После deploy первый полный `seasonvar:import` постепенно классифицирует сохранённые serial snapshots без HTTP. Настройки `SEASONVAR_PROVIDER_AVAILABILITY_RETRY_HOURS`, `SEASONVAR_PROVIDER_AVAILABILITY_BACKFILL_CHUNK_SIZE` и `SEASONVAR_PROVIDER_AVAILABILITY_BACKFILL_PAGE_LIMIT` применяются после пересборки config cache и перезапуска workers.

Миграция `2026_07_13_021800_add_health_state_to_licensed_media_table` additive: добавляет health status, success/error/failure/latency/retry timestamps и индекс `licensed_media_health_due_idx`. Existing `available` backfill-ится как `active`; legacy `status=unavailable` и `check_failed/unavailable/invalid_url` — как `unavailable` с одной failure и немедленным `next_check_at`. Строки не удаляются, URL не изменяются, unique constraints не перестраиваются.

Порядок production rollout для SQLite: дождаться finalizer/page jobs → остановить workers → сделать backup → развернуть код → `php artisan migrate --force` → задать/проверить `SEASONVAR_MEDIA_CHECK_FAILURE_THRESHOLD`, retry intervals и официальный `PLAYBACK_ALLOWED_HOSTS` → `php artisan config:cache` → `php artisan queue:restart` и запустить workers. Web/worker код не должен обслуживать запросы между deploy и migration, потому что новый resolver читает `health_status`.

Отдельный `schedule:run` не нужен: внешний cron запускает queued importer, а finalizer берёт только due sources. `disabled` не проверяется автоматически. Перед добавлением host в allowlist нужно подтвердить лицензионный/provider contract; private, reserved, link-local, metadata IP, credentials, redirects и HTTP блокируются независимо от allowlist.

## Admin queue interface от 13.07.2026

Перед deploy дождаться active import jobs и сделать backup SQLite. Затем применить additive `2026_07_13_140000_add_administration_fields_to_seasonvar_import_runs`, задать comma-separated `SEASONVAR_IMPORT_ADMIN_EMAILS`, пересобрать config cache и выполнить `php artisan queue:restart`. Migration добавляет nullable foreign keys/timestamps и indexes; данные не переписывает и backfill не требует.

Production scheduler в этом репозитории — внешний cron ниже, а не Laravel `schedule:run`. Admin UI не заменяет cron: он даёт ручной authorized старт/retry/cancel и recovery для `running` run без heartbeat и live claims. Threshold задаёт `SEASONVAR_QUEUE_STALE_AFTER_MINUTES=120`; перед ручным recovery нужно убедиться, что workers не остановлены на долгую maintenance-паузу.

## Import identity и editorial baseline от 13.07.2026

После уже существующих migrations применяются по timestamp:

1. `2026_07_13_130100_add_provider_field_values_to_catalog_titles_table` добавляет nullable JSON без backfill. Первый повторный import сохраняет заполненные существующие поля как потенциально редакционные и только фиксирует provider baseline.
2. `2026_07_13_130101_add_provider_identity_indexes_to_people_tables` добавляет неуникальные indexes для bounded actor/director lookup по `source_url`. Unique constraint намеренно не добавляется: read-only аудит текущих данных обнаружил неоднозначный обрезанный person URL.

Rollout: дождаться import jobs → backup SQLite → deploy → `php artisan migrate --force` → `php artisan queue:restart` → безопасный targeted repeat import. Обе migrations additive и обратимы, не удаляют и не объединяют строки. Подробный контракт находится в `docs/importer.md`.

## Миграции publication integrity от 12.07.2026

Миграции выполняются только в порядке timestamp:

1. `2026_07_12_174216_add_publication_integrity_columns_to_catalog_domain` добавляет nullable-поля без блокирующих unique-изменений.
2. `2026_07_12_174218_backfill_catalog_domain_publication_integrity` заполняет status/audience/kind/sort order существующих строк без удаления данных.
3. `2026_07_12_174219_enforce_catalog_domain_publication_integrity` проверяет NULL и дубли, затем добавляет `NOT NULL`, lookup/order indexes и special-aware unique keys.

Перед production-запуском дождитесь завершения активного import batch и сделайте резервную копию SQLite-файла. Затем разверните код и выполните обычный `php artisan migrate --force`. Constraint-миграция прервётся с явной ошибкой, если обнаружит конфликт; автоматически объединять или удалять строки она не будет. Перестройка четырёх таблиц на текущей SQLite-базе в проверке копии заняла около минуты, поэтому нужен отдельный maintenance window.

Rollback третьей миграции остановится, если special и regular записи уже используют одинаковый номер: старый unique key не способен представить такие данные. Сначала экспортируйте или безопасно перенумеруйте conflicts, затем повторите rollback.

## Пользовательское состояние карточки от 12.07.2026

Additive migration `2026_07_12_235500_create_catalog_user_state_tables` создаёт только новые таблицы `catalog_title_user_states` и `episode_view_progress`. Backfill не требуется: отсутствие строки означает пустой watchlist/rating/progress. Foreign keys удаляют приватное состояние вместе с user/title/episode, а unique keys запрещают дубли одного user/title и user/episode.

Изменение списка просмотра и внутренних оценок от 13.07.2026 не требует новой миграции: существующий unique `(user_id, catalog_title_id)` уже является нужной границей целостности. После deploy нужно обновить config cache, если он используется, чтобы все web workers получили одинаковый диапазон `config/catalog.php`; затем проверить повторное добавление/удаление и пересчёт среднего. Provider ratings в `catalog_title_ratings` не backfill-ятся и не изменяются.

Порядок rollout: штатно дождаться завершения текущих database writes, сделать backup SQLite, развернуть код, выполнить `php artisan migrate --force`, затем проверить гостевую и authenticated карточку. Код не требует остановки импортера из-за изменения catalog tables, но backup и короткое согласованное окно исключают конкуренцию schema lock SQLite. Rollback сначала удаляет `episode_view_progress`, затем `catalog_title_user_states`; catalog data при этом не меняется.

Миграции `2026_07_12_235600_add_persistent_playback_fields_to_episode_view_progress_table` и `2026_07_12_235601_backfill_episode_view_progress_first_started_at` выполняются строго после создания user-state таблиц. Первая additive добавляет nullable source/percent/start/session поля и sequence с default 0, сохраняя unique `(user_id, episode_id)` и существующие индексы; вторая отдельно backfill-ит `first_started_at` из `created_at`/`last_watched_at`. Rollout: backup SQLite → deploy code → `php artisan migrate --force`. Rollback выполняется в обратном порядке; backfill timestamp намеренно не восстанавливается, после чего schema rollback удаляет новые поля, не меняя прежнюю позицию.

Progress policy настраивается через `PLAYBACK_PROGRESS_SESSION_TTL_SECONDS`, `PLAYBACK_PROGRESS_MAX_DURATION_SECONDS`, `PLAYBACK_PROGRESS_POSITION_TOLERANCE_SECONDS`, `PLAYBACK_PROGRESS_COMPLETION_PERCENT` и `PLAYBACK_PROGRESS_COMPLETION_REMAINING_SECONDS`. Менять completion thresholds нужно согласованно на всех web workers после `config:cache`.

Централизация entitlement boundary от 13.07.2026 не добавляет миграций и не меняет данные: текущая схема по-прежнему поддерживает только `public/authenticated` audience и текущего `User` как активный профиль. Deploy выполняется обычным обновлением кода и cache warmup. Нельзя включать plan/region/profile/concurrency решения конфигурацией: сначала нужны отдельные additive schema/data migrations, backfill, ownership constraints и только затем подключение источника решения к `CatalogEntitlementService`.

## Окружение

Production-значения должны задаваться сервером, process manager или зашифрованным environment-файлом. Нельзя коммитить `.env` и настоящие секреты.

Обязательные production-ключи:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_KEY` с сгенерированным Laravel key
- `APP_URL` с публичным HTTPS URL
- `DB_CONNECTION` и `DB_DATABASE` для SQLite или соответствующие host/database/user/password ключи для другого драйвера
- `CACHE_STORE=redis-domain`
- `CACHE_HOT_STORE=memcached-hot`, `CACHE_DOMAIN_STORE=redis-domain`, `CACHE_LOCK_STORE=redis-locks`
- `QUEUE_CONNECTION=redis`
- `SESSION_DRIVER=redis`, `SESSION_CONNECTION=sessions`
- `FILESYSTEM_DISK=local`
- `LOCAL_FILESYSTEM_SERVE=false`
- `UPLOADS_DISK=uploads`
- `UPLOADS_MAX_IMAGE_KILOBYTES=2048`
- `NOTIFICATIONS_MAIL_QUEUE=default`
- `LOG_CHANNEL=stack`, `LOG_STACK=daily`, `LOG_LEVEL=warning` и bounded `LOG_DAILY_DAYS`

Проектные ключи Seasonvar описаны в `.env.example`. Значения по умолчанию консервативные: `SEASONVAR_BASE_URL=https://seasonvar.ru`, `SEASONVAR_IMPORT_CHUNK_SIZE=100`, `SEASONVAR_CRAWL_DELAY=3`, а проверки медиа включены с ограниченными тайм-аутами.

Google-интеграции по умолчанию выключены. Если включаются Search Console или Google Analytics, реальные credential-файлы и OAuth-секреты должны задаваться сервером или secret store, а в Git остаются только имена переменных из `.env.example`.

## Переменные проекта

Ключи, которые чаще всего меняются между окружениями:

- `APP_URL` — публичный HTTPS URL приложения; от него зависят canonical, sitemap, OpenSearch и публичные ссылки.
- `DB_BUSY_TIMEOUT`, `DB_JOURNAL_MODE`, `DB_SYNCHRONOUS` — настройки SQLite для устойчивой работы импорта.
- `DB_QUEUE_RETRY_AFTER` и `REDIS_QUEUE_RETRY_AFTER` — должны оставаться больше максимального timeout job импорта.
- `SEASONVAR_IMPORT_SLEEP_SECONDS` — пауза между циклами `--forever`.
- `SEASONVAR_IMPORT_REFRESH_AFTER_HOURS` и `SEASONVAR_IMPORT_MISSING_DATA_RETRY_HOURS` — частота повторной проверки страниц источника.
- `SEASONVAR_IMPORT_LOCK_SECONDS` и `SEASONVAR_IMPORT_STALE_AFTER_MINUTES` — защита от параллельного и зависшего импорта.
- `SEASONVAR_MEDIA_CHECK_*` — включение, timeout/retries/максимальный fragment, размер пачки, successful refresh age, failure threshold и bounded retry intervals внешних media URL.
- `PLAYBACK_SIGNED_URL_TTL_SECONDS`, `PLAYBACK_ALLOWED_HOSTS`, `PLAYBACK_ENFORCE_PUBLIC_DNS` — срок внутренней playback-ссылки и HTTPS/DNS allowlist provider sources. После изменения выполнить `php artisan config:cache`; в allowlist добавляются только официальные media-домены лицензированных провайдеров.
- `SEASONVAR_MEDIA_METADATA_CHUNK_SIZE` и `SEASONVAR_MEDIA_SOURCE_KEY_CHUNK_SIZE` — размеры сервисных дозаполнений старых media rows.
- `GOOGLE_APPLICATION_CREDENTIALS`, `GOOGLE_CLOUD_PROJECT`, `GOOGLE_PROJECT_ID` — runtime credential/project значения для Google API или локальных MCP, если они включены.
- `GOOGLE_SEARCH_CONSOLE_*` — read-only настройки Search Console.
- `GOOGLE_ANALYTICS_*` — read-only настройки GA4 reporting.
- `UPLOADS_DISK` — приватный disk для будущих user-upload файлов; по умолчанию `uploads`.
- `UPLOADS_MAX_IMAGE_KILOBYTES` — максимальный размер изображения для reusable upload-валидации.
- `NOTIFICATIONS_MAIL_QUEUE` — queue для email notification jobs.
- `SEASONVAR_IMPORT_FAILURE_MAIL_TO` и `SEASONVAR_IMPORT_FAILURE_MAIL_TO_NAME` — optional получатель письма об ошибке queued import; пустое значение отключает отправку.
- `SEASONVAR_QUEUE_CONNECTION=redis`, `SEASONVAR_QUEUE_NAME=seasonvar-import` и `SEASONVAR_QUEUE_LOCK_STORE=redis-locks` — отдельная очередь и critical-lock store параллельного импортера; domain cache для блокировок не используется.
- `SEASONVAR_TITLE_REFRESH_QUEUE=seasonvar-title-refresh` — отдельная очередь browser-triggered групп; `SEASONVAR_TITLE_REFRESH_FINALIZER_DELAY_SECONDS` задаёт задержку fan-in retry, но не ограничивает число page jobs. `SEASONVAR_IMPORT_PREPARED_RETENTION_DAYS` задаёт bounded очистку terminal staging groups.
- `SEASONVAR_IMPORT_ADMIN_EMAILS` — comma-separated email allowlist gate `/admin/imports`; пустое значение закрывает страницу для всех.
- Тот же `SEASONVAR_IMPORT_ADMIN_EMAILS` защищает `/admin/catalog`; write actions дополнительно проходят policy, validation и optimistic version checks.
- `SEASONVAR_QUEUE_STALE_AFTER_MINUTES` — минимальный возраст stale running run для recovery, не меньше 5 минут в runtime.
- `SEASONVAR_QUEUE_CLAIM_SECONDS=86400`, `SEASONVAR_QUEUE_WORKER_TIMEOUT=900` и `SEASONVAR_IMPORT_REFRESH_AFTER_HOURS=24` — lease, timeout и период повторной проверки источника.
- `SEASONVAR_QUEUE_BUSY_THRESHOLD=5000` и `SEASONVAR_QUEUE_BUSY_LOG_SECONDS=3600` — порог backlog и минимальный интервал повторного warning в журнале.

В коде приложения эти значения читаются через `config('seasonvar.*')`, `config('queue.*')`, `config('database.*')` и другие config-файлы, а не через прямой `env()`.

## Постоянный однопоточный импорт Seasonvar

Для сервера, где все XML sitemap и catalog pages должны непрерывно обновляться строго одним PHP-процессом, используется `deploy/systemd/seasonvar-import-forever.service`. Unit запускает единственную публичную команду `seasonvar:import --forever`, после завершения или сбоя поднимает её снова и автоматически стартует после reboot.

Последовательный и Redis queued-профили взаимоисключающие. Перед включением однопоточного профиля дождитесь текущих jobs, затем отключите import/title-refresh worker instances и удалите или закомментируйте cron-строку `seasonvar:import --queued`. Failed jobs и Redis backlog при переключении не очищаются.

```bash
sudo systemctl disable --now 'seasonvar-import-worker@*.service'
sudo systemctl disable --now 'seasonvar-title-refresh-worker@*.service'
sudo cp deploy/systemd/seasonvar-import-forever.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now seasonvar-import-forever.service
systemctl --no-pager --full status seasonvar-import-forever.service
journalctl -u seasonvar-import-forever.service -f
```

Пауза между циклами задаётся `SEASONVAR_IMPORT_SLEEP_SECONDS`, default — 60 секунд. Один sync process самостоятельно выполняет discovery sitemap, parsing, catalog write и обслуживание производных данных. Он не обрабатывает `seasonvar-title-refresh` queue: открытая карточка всё равно получает изменения от следующего полного цикла через Livewire polling, но отдельный browser-triggered job останется в очереди до возврата queued-профиля.

Для возврата к параллельному профилю сначала остановите и отключите `seasonvar-import-forever.service`, затем включите нужное число worker instances и верните queued cron. Одновременно оба профиля не запускаются.

## Пулы workers импорта Seasonvar

Перед запуском примените additive migrations и проверьте Redis:

```bash
cd /www/wwwroot/seasonvar.miniserver.fun
redis-cli ping
php artisan migrate --force
php artisan seasonvar:import --queued
```

Для временного фонового запуска без нового process-manager:

```bash
cd /www/wwwroot/seasonvar.miniserver.fun
for worker in $(seq 1 10); do
  nohup /usr/bin/php -d memory_limit=256M artisan queue:work redis --queue=seasonvar-import --sleep=1 --tries=0 --timeout=900 --memory=192 --max-time=3600 \
    >> "storage/logs/seasonvar-worker-${worker}.log" 2>&1 &
done
```

`nohup` подходит для немедленного ручного запуска. Для постоянной работы после перезагрузки установите версионируемые systemd templates:

PHP hard limit `256M` даёт rebuild рекомендаций запас над измеренным пиком, а Laravel `--memory=192` завершает worker после job до исчерпания hard limit. Systemd templates используют `Restart=always`, поэтому штатные exits по `--memory`, `--max-time`, `--max-jobs` и `queue:restart` автоматически возвращают настроенное число процессов.

```bash
sudo cp deploy/systemd/seasonvar-import-worker@.service /etc/systemd/system/
sudo cp deploy/systemd/seasonvar-title-refresh-worker@.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now seasonvar-import-worker@{1..4}.service
sudo systemctl enable --now seasonvar-title-refresh-worker@{1..8}.service
systemctl --no-pager --type=service 'seasonvar-import-worker@*'
systemctl --no-pager --type=service 'seasonvar-title-refresh-worker@*'
```

Import template слушает только `seasonvar-import`; title-refresh template — только `seasonvar-title-refresh`. Для текущего четырёхъядерного SQLite host стартовый production pool ограничен 4 import + 8 title-refresh instances. Все известные и динамически найденные страницы по-прежнему получают jobs; повышать concurrency следует только по измеренной queue latency при отсутствии SQLite writer contention. После additive migration `seasonvar_import_title_groups` хранит fan-in состояние, а `seasonvar_import_prepared_pages` — bounded промежуточные payload; старые terminal groups удаляются по `SEASONVAR_IMPORT_PREPARED_RETENTION_DAYS`.

Управление и диагностика:

```bash
sudo systemctl stop 'seasonvar-import-worker@*.service'
sudo systemctl stop 'seasonvar-title-refresh-worker@*.service'
sudo systemctl restart 'seasonvar-import-worker@*.service'
sudo systemctl restart 'seasonvar-title-refresh-worker@*.service'
journalctl -u 'seasonvar-import-worker@*' -f
journalctl -u 'seasonvar-title-refresh-worker@*' -f
php artisan seasonvar:import --status
php artisan queue:failed
php artisan queue:retry all
```

Добавьте в crontab пользователя `www` dispatcher с десятью запусками в сутки и read-only монитор очереди каждые пять минут:

```cron
0 0,2,5,7,10,12,14,17,19,22 * * * cd /www/wwwroot/seasonvar.miniserver.fun && /usr/bin/php artisan seasonvar:import --queued >> storage/logs/seasonvar-cron.log 2>&1
*/5 * * * * cd /www/wwwroot/seasonvar.miniserver.fun && /usr/bin/php artisan queue:monitor 'redis:seasonvar-title-refresh,redis:seasonvar-import' --max=5000 >> storage/logs/seasonvar-queue-monitor.log 2>&1
```

Повторный cron не дублирует живые jobs: coordinator lock сериализует постановку, а page lease защищает конкретный URL. Все страницы title group готовятся параллельно; только короткий fan-in apply сериализован по каноническому тайтлу. Каждая просроченная страница запрашивается заново и сравнивается по `content_hash`; видео не скачивается.

Sitemap-тесты используют отдельный каталог `storage/app/seasonvar/tests/*` и не очищают рабочее зеркало `storage/app/seasonvar/sitemaps`. Это устраняет файловую гонку между безопасными inventory-запусками и PHPUnit; полный тестовый набор всё равно не следует запускать на production во время ресурсоёмкого импорта.

## Правила конфигурации

- Код приложения читает значения окружения через `config()`.
- Прямые вызовы `env()` допустимы только в `config/*.php`; это обязательно для `php artisan config:cache`.
- `.env.example` содержит только безопасные значения по умолчанию и пустые placeholders без секретов.
- Для production SQLite файл базы, `storage` и `bootstrap/cache` должны быть доступны PHP-процессу на запись.

## Проверки деплоя

### Read-only deployment preflight

Перед остановкой writers и после возврата процессов запускайте:

```bash
php artisan app:deployment-check
php artisan app:deployment-check --json
```

Команда ничего не мигрирует, не очищает и не перезапускает. Она проверяет production/debug/logging, pending migrations, SQLite quick/FK checks и обязательные индексы, состояние FTS, production cache/session/queue profile, безопасную агрегатную сводку failed jobs и профиль процесса импортёра. Ненулевой exit code означает, что traffic или writers возвращать нельзя. JSON содержит только стабильные имена проверок, статусы, русские сообщения и scalar counts; failed-job payload, exception text, URL и токены не выводятся.

Атомарный порядок maintenance: preflight → дождаться безопасной точки одного `seasonvar:import --forever` и остановить writers → backup SQLite → `migrate:status` и обычный `migrate --force` → при необходимости штатный FTS rebuild/cache warm → `config:cache` → перезапуск PHP-FPM и единственного importer process → повторный preflight, `app:health --json` и гостевой HTTP smoke. `queue:retry`/`queue:forget` остаются отдельным ручным решением после сверки run state и claims; preflight их не вызывает.

### Владение retention и failed jobs

- Владелец технического retention импортёра — production operator, выполняющий runbook Seasonvar. `SeasonvarImportStorageMaintenance` применяет только существующие окна: import events 7 дней, source snapshots 14 дней и terminal prepared groups 7 дней через `SEASONVAR_IMPORT_*_RETENTION_DAYS`; изменение окон требует capacity/privacy review и обновления `docs/importer.md`.
- Transport retention mobile offline-sync принадлежит API operator: `api:sync-prune` удаляет только sync invalidations старше 30 дней и idempotency receipts старше 90 дней. Эти окна зафиксированы в `config/mobile-api.php`; изменение требует обновления manifest/OpenAPI, capacity/retry review и recovery contract в `docs/api.md`.
- Владелец disposition `failed_jobs` — тот же production operator. `app:deployment-check` даёт только безопасную агрегатную сводку; retry/forget выполняются вручную после сверки import run и live claims. Автоматического удаления failed jobs нет.
- User history/progress/watchlist/rating, admin audit и потенциально юридически значимые строки не имеют автоматического retention/delete job. Их нельзя включать в общий technical prune без утверждённой продуктовой/legal policy, отдельного owner и тестируемой процедуры удаления/экспорта.

### Production runtime и журналы

Перед возвратом трафика установите versioned-профиль ротации, пересоберите config cache и выполните graceful reload PHP-FPM. `copytruncate` не требует остановки PHP-процессов и не удаляет текущий журнал:

```bash
sudo install -m 0644 deploy/logrotate/seasonvar /etc/logrotate.d/seasonvar
sudo logrotate --debug /etc/logrotate.d/seasonvar
php artisan config:cache
php artisan about --only=environment
systemctl --no-pager --full status seasonvar-import-forever.service
```

Команда reload зависит от process manager сервера: для systemd используется `sudo systemctl reload <php-fpm-unit>`, а для отдельного PHP-FPM master — сигнал `USR2` его master PID. Не запускайте второй importer во время проверки. После reload выполните гостевой HTTP GET, `php artisan app:health --json` и убедитесь, что `seasonvar-import-forever.service` остаётся единственным активным импортёром.

Запускать при деплое после установки зависимостей и настройки environment-значений:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
npm run build
```

Для локальной проверки после правок конфигурации:

```bash
php artisan config:cache
php artisan config:clear
php artisan test --filter=ConfigurationEnvironmentTest
```

Перед деплоем после изменения маршрутов или sitemap/robots-документации также проверять:

```bash
php artisan project:docs-refresh --check
```
