# Разработка

Обновлено: 15.07.2026

## Локальная установка

Требования для разработки:

- PHP 8.5 с `pdo_sqlite`, `sqlite3`, `mbstring`, `dom`, `fileinfo`, `redis` и `memcached`.
- Composer 2.
- Node 26 и npm 12.
- SQLite для локальной базы и тестов.
- Redis и Memcached для production-like integration tests, health и cache benchmarks; обычный PHPUnit остаётся воспроизводимым с array store.

Базовая ручная установка:

```bash
composer install
composer hooks:install
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

- Единственная рабочая ветка проекта — существующая `main`.
- Не создавать feature branches, временные ветки, worktree-ветки, PR-ветки или дополнительные `main`-подобные ветки без прямого нового указания пользователя.
- Если локальный checkout оказался на другой ветке, сначала безопасно перенесите незакоммиченные изменения на `main`, затем продолжайте работу только в `main`.
- Перед commit или push выполните `git status --short --branch` и проверьте, что текущая ветка — `main`.
- Не коммитьте и не отправляйте изменения из веток, отличных от `main`.
- Не оставляйте рабочее дерево грязным после задачи: разрешенные изменения должны быть закоммичены, а чужие/посторонние незакоммиченные изменения нужно явно отметить как блокер.
- Установить версионируемые hooks: `composer hooks:install`. Команда локально задаёт `core.hooksPath=.githooks`; `composer setup` выполняет её автоматически.
- `pre-commit` блокирует commit вне `main`, unresolved conflicts, staged временные/debug-файлы, staged `.env`/credential paths, unstaged tracked changes и untracked files.
- `pre-push` повторно проверяет `main`, unresolved conflicts и уже tracked временные/credential paths, затем требует clean working tree.
- Проверки только читают Git state и печатают причину отказа. Они не добавляют файлы, не исправляют код, не удаляют изменения и не зависят от персональных абсолютных путей. `.env.example` явно разрешён; реальные `.env`, private keys и credential JSON должны храниться вне Git.
- Проверка secrets намеренно лёгкая и основана на очевидных именах путей; она не заменяет review staged diff или полноценный secret scanner CI для произвольно названных файлов.
- `post-commit` запускает только управляемое обновление Markdown через `project:docs-refresh`; исходный PHP/Blade/JS код hook не редактирует, auto-push выключен без явного `SEASONVAR_DOCS_AUTO_PUSH=1`.

Проверить установку без изменения файлов:

```bash
git config --local --get core.hooksPath
bash -n .githooks/pre-commit .githooks/pre-push .githooks/post-commit .githooks/lib/git-guard.sh
```

## Команды проекта

- `php artisan seasonvar:import` — единственная публичная команда импорта Seasonvar.
- `php artisan seasonvar:import --forever --sleep=60` — непрерывный локальный цикл импорта.
- `php artisan seasonvar:import "https://seasonvar.ru/..." --force` — принудительное обновление одной страницы.
- `php artisan integrations:doctor` — read-only диагностика MCP, Google, CLI tools и проектных skills без вывода секретов.
- `php artisan app:health` — operational health по DB, Redis workloads, Memcached, workers и прогреву; `degraded`/`failed` возвращают ненулевой exit, даже если HTTP traffic readiness ещё true.
- `php artisan app:failed-job-audit` — bounded read-only сопоставление historical finalizer rows с текущими run/group/claim states; `--json` даёт safe evidence, `--samples=0..10` ограничивает ID-only примеры на состояние. Команда не retry-ит, не забывает, не очищает и не dispatch-ит jobs.
- `php artisan cache:warm-catalog` — синхронный bounded warm; `--queue` ставит unique job в `cache-warm-v2`, а `--refresh` планово пересобирает текущие warmable keys под lock, не удаляя читаемый snapshot до успеха. Историческая `cache-warm` не используется новым worker и не очищается автоматически.
- `php artisan cache:metrics` — low-cardinality hit/miss/rebuild/failure snapshot без raw keys.
- `php artisan api:sync-prune` — bounded очистка transport-журнала offline-sync: changes старше 30 дней и idempotency receipts старше 90 дней; до additive migration завершается безопасным no-op.
- `php artisan google:search-console:summary` — read-only сводка Search Console, если Google credentials настроены вне Git.
- `php artisan google:analytics:summary` — read-only сводка GA4, если Google credentials настроены вне Git.
- `php artisan project:docs-refresh` — обновляет управляемые блоки документации.
- `php artisan project:docs-refresh --check` — проверяет документацию без записи изменений, включая repository-relative Markdown links и migration inventory.
- `composer analyse` — запускает bounded Larastan/PHPStan по DTO, enums и критичным operational/admin boundaries без baseline и ignored errors.

## Проверки

```bash
composer test
composer analyse
php artisan test --compact
./vendor/bin/pint --dirty --format agent
npm run build
php artisan project:docs-refresh --check
```

Pest, Rector и `npm run lint` сейчас не установлены. Larastan/PHPStan применяется как ограниченный high-value gate; расширять paths нужно постепенно и только с нулём ignored errors.
