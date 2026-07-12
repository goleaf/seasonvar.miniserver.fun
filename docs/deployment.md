# Деплой

Обновлено: 09.07.2026

## Миграции publication integrity от 12.07.2026

Миграции выполняются только в порядке timestamp:

1. `2026_07_12_174216_add_publication_integrity_columns_to_catalog_domain` добавляет nullable-поля без блокирующих unique-изменений.
2. `2026_07_12_174218_backfill_catalog_domain_publication_integrity` заполняет status/audience/kind/sort order существующих строк без удаления данных.
3. `2026_07_12_174219_enforce_catalog_domain_publication_integrity` проверяет NULL и дубли, затем добавляет `NOT NULL`, lookup/order indexes и special-aware unique keys.

Перед production-запуском дождитесь завершения активного import batch и сделайте резервную копию SQLite-файла. Затем разверните код и выполните обычный `php artisan migrate --force`. Constraint-миграция прервётся с явной ошибкой, если обнаружит конфликт; автоматически объединять или удалять строки она не будет. Перестройка четырёх таблиц на текущей SQLite-базе в проверке копии заняла около минуты, поэтому нужен отдельный maintenance window.

Rollback третьей миграции остановится, если special и regular записи уже используют одинаковый номер: старый unique key не способен представить такие данные. Сначала экспортируйте или безопасно перенумеруйте conflicts, затем повторите rollback.

## Пользовательское состояние карточки от 12.07.2026

Additive migration `2026_07_12_235500_create_catalog_user_state_tables` создаёт только новые таблицы `catalog_title_user_states` и `episode_view_progress`. Backfill не требуется: отсутствие строки означает пустой watchlist/rating/progress. Foreign keys удаляют приватное состояние вместе с user/title/episode, а unique keys запрещают дубли одного user/title и user/episode.

Порядок rollout: штатно дождаться завершения текущих database writes, сделать backup SQLite, развернуть код, выполнить `php artisan migrate --force`, затем проверить гостевую и authenticated карточку. Код не требует остановки импортера из-за изменения catalog tables, но backup и короткое согласованное окно исключают конкуренцию schema lock SQLite. Rollback сначала удаляет `episode_view_progress`, затем `catalog_title_user_states`; catalog data при этом не меняется.

Миграции `2026_07_12_235600_add_persistent_playback_fields_to_episode_view_progress_table` и `2026_07_12_235601_backfill_episode_view_progress_first_started_at` выполняются строго после создания user-state таблиц. Первая additive добавляет nullable source/percent/start/session поля и sequence с default 0, сохраняя unique `(user_id, episode_id)` и существующие индексы; вторая отдельно backfill-ит `first_started_at` из `created_at`/`last_watched_at`. Rollout: backup SQLite → deploy code → `php artisan migrate --force`. Rollback выполняется в обратном порядке; backfill timestamp намеренно не восстанавливается, после чего schema rollback удаляет новые поля, не меняя прежнюю позицию.

Progress policy настраивается через `PLAYBACK_PROGRESS_SESSION_TTL_SECONDS`, `PLAYBACK_PROGRESS_MAX_DURATION_SECONDS`, `PLAYBACK_PROGRESS_POSITION_TOLERANCE_SECONDS`, `PLAYBACK_PROGRESS_COMPLETION_PERCENT` и `PLAYBACK_PROGRESS_COMPLETION_REMAINING_SECONDS`. Менять completion thresholds нужно согласованно на всех web workers после `config:cache`.

## Окружение

Production-значения должны задаваться сервером, process manager или зашифрованным environment-файлом. Нельзя коммитить `.env` и настоящие секреты.

Обязательные production-ключи:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_KEY` с сгенерированным Laravel key
- `APP_URL` с публичным HTTPS URL
- `DB_CONNECTION` и `DB_DATABASE` для SQLite или соответствующие host/database/user/password ключи для другого драйвера
- `CACHE_STORE=database`
- `CACHE_LIMITER_STORE=file` — отдельное хранилище счетчиков throttle, чтобы публичные rate limiters не писали каждый запрос в SQLite cache table
- `QUEUE_CONNECTION=database`
- `SESSION_DRIVER=database`
- `FILESYSTEM_DISK=local`
- `LOCAL_FILESYSTEM_SERVE=false`
- `UPLOADS_DISK=uploads`
- `UPLOADS_MAX_IMAGE_KILOBYTES=2048`
- `NOTIFICATIONS_MAIL_QUEUE=default`
- `LOG_CHANNEL`, `LOG_STACK` и `LOG_LEVEL`

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
- `SEASONVAR_MEDIA_CHECK_*` — включение, timeout, retries, максимальный ответ, размер пачки и возраст повторной проверки внешних media URL.
- `PLAYBACK_SIGNED_URL_TTL_SECONDS`, `PLAYBACK_ALLOWED_HOSTS`, `PLAYBACK_ENFORCE_PUBLIC_DNS` — срок внутренней playback-ссылки и HTTPS/DNS allowlist provider sources. После изменения выполнить `php artisan config:cache`; в allowlist добавляются только официальные media-домены лицензированных провайдеров.
- `SEASONVAR_MEDIA_METADATA_CHUNK_SIZE` и `SEASONVAR_MEDIA_SOURCE_KEY_CHUNK_SIZE` — размеры сервисных дозаполнений старых media rows.
- `GOOGLE_APPLICATION_CREDENTIALS`, `GOOGLE_CLOUD_PROJECT`, `GOOGLE_PROJECT_ID` — runtime credential/project значения для Google API или локальных MCP, если они включены.
- `GOOGLE_SEARCH_CONSOLE_*` — read-only настройки Search Console.
- `GOOGLE_ANALYTICS_*` — read-only настройки GA4 reporting.
- `UPLOADS_DISK` — приватный disk для будущих user-upload файлов; по умолчанию `uploads`.
- `UPLOADS_MAX_IMAGE_KILOBYTES` — максимальный размер изображения для reusable upload-валидации.
- `NOTIFICATIONS_MAIL_QUEUE` — queue для email notification jobs.
- `SEASONVAR_IMPORT_FAILURE_MAIL_TO` и `SEASONVAR_IMPORT_FAILURE_MAIL_TO_NAME` — optional получатель письма об ошибке queued import; пустое значение отключает отправку.
- `SEASONVAR_QUEUE_CONNECTION=redis`, `SEASONVAR_QUEUE_NAME=seasonvar-import` и `SEASONVAR_QUEUE_LOCK_STORE=redis` — отдельная очередь и locks параллельного импортера.
- `SEASONVAR_QUEUE_CLAIM_SECONDS=86400`, `SEASONVAR_QUEUE_WORKER_TIMEOUT=900` и `SEASONVAR_IMPORT_REFRESH_AFTER_HOURS=24` — lease, timeout и период повторной проверки источника.
- `SEASONVAR_QUEUE_BUSY_THRESHOLD=5000` и `SEASONVAR_QUEUE_BUSY_LOG_SECONDS=3600` — порог backlog и минимальный интервал повторного warning в журнале.

В коде приложения эти значения читаются через `config('seasonvar.*')`, `config('queue.*')`, `config('database.*')` и другие config-файлы, а не через прямой `env()`.

## Десять workers импорта Seasonvar

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
  nohup /usr/bin/php artisan queue:work redis --queue=seasonvar-import --sleep=1 --tries=0 --timeout=900 --memory=256 --max-time=3600 \
    >> "storage/logs/seasonvar-worker-${worker}.log" 2>&1 &
done
```

`nohup` подходит для немедленного ручного запуска. Для постоянной работы после перезагрузки установите версионируемый systemd template:

```bash
sudo cp deploy/systemd/seasonvar-import-worker@.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now seasonvar-import-worker@{1..10}.service
systemctl --no-pager --type=service 'seasonvar-import-worker@*'
```

Управление и диагностика:

```bash
sudo systemctl stop 'seasonvar-import-worker@*.service'
sudo systemctl restart 'seasonvar-import-worker@*.service'
journalctl -u 'seasonvar-import-worker@*' -f
php artisan seasonvar:import --status
php artisan queue:failed
php artisan queue:retry all
```

Добавьте в crontab пользователя `www` dispatcher с десятью запусками в сутки и read-only монитор очереди каждые пять минут:

```cron
0 0,2,5,7,10,12,14,17,19,22 * * * cd /www/wwwroot/seasonvar.miniserver.fun && /usr/bin/php artisan seasonvar:import --queued >> storage/logs/seasonvar-cron.log 2>&1
*/5 * * * * cd /www/wwwroot/seasonvar.miniserver.fun && /usr/bin/php artisan queue:monitor redis:seasonvar-import --max=5000 >> storage/logs/seasonvar-queue-monitor.log 2>&1
```

Повторный cron не дублирует живые jobs: coordinator lock сериализует постановку, page lease защищает конкретный URL, а Redis title lock сериализует сезоны одного сериала. Каждая просроченная страница запрашивается заново и сравнивается по `content_hash`; видео не скачивается.

Не запускайте полный PHPUnit suite одновременно с живым импортом: sitemap tests очищают `storage/app/seasonvar/sitemaps`. На время полного тестирования штатно остановите десять worker units, после тестов снова запустите их через systemd.

## Правила конфигурации

- Код приложения читает значения окружения через `config()`.
- Прямые вызовы `env()` допустимы только в `config/*.php`; это обязательно для `php artisan config:cache`.
- `.env.example` содержит только безопасные значения по умолчанию и пустые placeholders без секретов.
- Для production SQLite файл базы, `storage` и `bootstrap/cache` должны быть доступны PHP-процессу на запись.

## Проверки деплоя

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
