# Деплой

Обновлено: 09.07.2026

## Миграции publication integrity от 12.07.2026

Миграции выполняются только в порядке timestamp:

1. `2026_07_12_174216_add_publication_integrity_columns_to_catalog_domain` добавляет nullable-поля без блокирующих unique-изменений.
2. `2026_07_12_174218_backfill_catalog_domain_publication_integrity` заполняет status/audience/kind/sort order существующих строк без удаления данных.
3. `2026_07_12_174219_enforce_catalog_domain_publication_integrity` проверяет NULL и дубли, затем добавляет `NOT NULL`, lookup/order indexes и special-aware unique keys.

Перед production-запуском дождитесь завершения активного import batch и сделайте резервную копию SQLite-файла. Затем разверните код и выполните обычный `php artisan migrate --force`. Constraint-миграция прервётся с явной ошибкой, если обнаружит конфликт; автоматически объединять или удалять строки она не будет. Перестройка четырёх таблиц на текущей SQLite-базе в проверке копии заняла около минуты, поэтому нужен отдельный maintenance window.

Rollback третьей миграции остановится, если special и regular записи уже используют одинаковый номер: старый unique key не способен представить такие данные. Сначала экспортируйте или безопасно перенумеруйте conflicts, затем повторите rollback.

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
- `SEASONVAR_MEDIA_CHECK_*` — включение, timeout, retries, размер пачки и возраст повторной проверки внешних media URL.
- `SEASONVAR_MEDIA_METADATA_CHUNK_SIZE` и `SEASONVAR_MEDIA_SOURCE_KEY_CHUNK_SIZE` — размеры сервисных дозаполнений старых media rows.
- `GOOGLE_APPLICATION_CREDENTIALS`, `GOOGLE_CLOUD_PROJECT`, `GOOGLE_PROJECT_ID` — runtime credential/project значения для Google API или локальных MCP, если они включены.
- `GOOGLE_SEARCH_CONSOLE_*` — read-only настройки Search Console.
- `GOOGLE_ANALYTICS_*` — read-only настройки GA4 reporting.
- `UPLOADS_DISK` — приватный disk для будущих user-upload файлов; по умолчанию `uploads`.
- `UPLOADS_MAX_IMAGE_KILOBYTES` — максимальный размер изображения для reusable upload-валидации.
- `NOTIFICATIONS_MAIL_QUEUE` — queue для email notification jobs.
- `SEASONVAR_IMPORT_FAILURE_MAIL_TO` и `SEASONVAR_IMPORT_FAILURE_MAIL_TO_NAME` — optional получатель письма об ошибке queued import; пустое значение отключает отправку.

В коде приложения эти значения читаются через `config('seasonvar.*')`, `config('queue.*')`, `config('database.*')` и другие config-файлы, а не через прямой `env()`.

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
