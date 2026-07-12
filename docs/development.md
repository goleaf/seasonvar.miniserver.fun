# Разработка

Обновлено: 12.07.2026

## Локальная установка

Требования для разработки:

- PHP 8.5 с `pdo_sqlite`, `sqlite3`, `mbstring`, `dom` и `fileinfo`.
- Composer 2.
- Node 26 и npm 11.
- SQLite для локальной базы и тестов.

Базовая ручная установка:

```bash
composer install
cp .env.example .env
php artisan key:generate
mkdir -p database
touch database/database.sqlite
php artisan migrate
npm install
npm run build
```

Если `.env` уже существует, не перезаписывайте его без причины. Для локального SQLite можно оставить `DB_DATABASE` пустым: Laravel использует `database/database.sqlite`.

`composer setup` доступен как укрупненный wrapper для установки зависимостей, генерации ключа, миграций и frontend build. Для полностью чистого SQLite checkout ручной вариант выше явно создает файл базы перед миграциями.

## Локальный запуск

```bash
composer dev
```

`composer dev` запускает Laravel server, `queue:listen --tries=3 --timeout=900`, Pail logs и Vite. Если нужен только фронтенд, используйте `npm run dev`.

## Git workflow

- Рабочая ветка проекта — только существующая `main`.
- Не создавать feature branches, временные ветки, worktree-ветки, PR-ветки или дополнительные `main`-подобные ветки без прямого нового указания пользователя.
- Если локальный checkout оказался на другой ветке, сначала безопасно перенесите незакоммиченные изменения на `main`, затем продолжайте работу только в `main`.
- Перед commit или push выполните `git status --short --branch` и проверьте, что текущая ветка — `main`.
- Не коммитьте и не отправляйте изменения из веток, отличных от `main`.

## Команды проекта

- `php artisan seasonvar:import` — единственная публичная команда импорта Seasonvar.
- `php artisan seasonvar:import --forever --sleep=60` — непрерывный локальный цикл импорта.
- `php artisan seasonvar:import "https://seasonvar.ru/..." --force` — принудительное обновление одной страницы.
- `php artisan integrations:doctor` — read-only диагностика MCP, Google, CLI tools и проектных skills без вывода секретов.
- `php artisan google:search-console:summary` — read-only сводка Search Console, если Google credentials настроены вне Git.
- `php artisan google:analytics:summary` — read-only сводка GA4, если Google credentials настроены вне Git.
- `php artisan project:docs-refresh` — обновляет управляемые блоки документации.
- `php artisan project:docs-refresh --check` — проверяет документацию без записи изменений.

## Проверки

```bash
composer test
php artisan test --compact
./vendor/bin/pint --dirty --format agent
npm run build
php artisan project:docs-refresh --check
```

Pest, PHPStan, Larastan, Rector и `npm run lint` сейчас не установлены.
