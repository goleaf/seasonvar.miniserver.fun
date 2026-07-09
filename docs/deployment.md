# Деплой

Обновлено: 09.07.2026

## Окружение

Production-значения должны задаваться сервером, process manager или зашифрованным environment-файлом. Нельзя коммитить `.env` и настоящие секреты.

Обязательные production-ключи:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_KEY` с сгенерированным Laravel key
- `APP_URL` с публичным HTTPS URL
- `DB_CONNECTION` и `DB_DATABASE` для SQLite или соответствующие host/database/user/password ключи для другого драйвера
- `CACHE_STORE=database`
- `QUEUE_CONNECTION=database`
- `SESSION_DRIVER=database`
- `FILESYSTEM_DISK=local`
- `LOCAL_FILESYSTEM_SERVE=false`
- `LOG_CHANNEL`, `LOG_STACK` и `LOG_LEVEL`

Проектные ключи Seasonvar описаны в `.env.example`. Значения по умолчанию консервативные: `SEASONVAR_BASE_URL=https://seasonvar.ru`, `SEASONVAR_IMPORT_CHUNK_SIZE=100`, `SEASONVAR_CRAWL_DELAY=3`, а проверки медиа включены с ограниченными тайм-аутами.

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
