# Безопасные Git hooks для миграций базы данных

Дата: 13.07.2026

## Цель

Автоматически обнаруживать завершённые изменения миграций после локального commit и после merge/pull, выполнять безопасный preflight текущей SQLite-базы и запускать только обычную команду `php artisan migrate`. Автоматизация не должна создавать синтетический каталог, останавливать или портить текущий импорт и не должна обходить production confirmation Laravel.

## Непереговорные ограничения

- Никогда не выполнять `php artisan migrate --force`.
- Никогда не выполнять `migrate:fresh`, `migrate:refresh`, `db:wipe`, destructive SQL или автоматический rollback.
- Никогда не запускать seeders, model factories или команды, создающие вымышленные production-данные.
- Не очищать, не заменять и не пересоздавать существующую SQLite-базу.
- Не запускать миграцию при активных queued/synchronous imports, pending/delayed/reserved jobs или live page claims.
- Не менять `.env` и не раскрывать database path, credentials или содержимое backup в выводе hook.
- Недостающие реальные сезоны и серии восполняются только существующим `php artisan seasonvar:import` и текущими source pages/snapshots/URL в рамках отдельного importer workflow. DB hook импорт не запускает.
- Новые тесты hook не используют model factories. Существующая полная PHPUnit suite продолжает работать только со своей изолированной in-memory SQLite и не меняет live database.

## Выбранная архитектура

### Git triggers

- Существующий `.githooks/post-commit` вызывает общий database hook перед docs refresh.
- Новый `.githooks/post-merge` вызывает тот же общий hook после merge или pull.
- `composer hooks:install` остаётся единственным installer и продолжает задавать `core.hooksPath=.githooks`.
- `SEASONVAR_SKIP_DB_MIGRATE_HOOK=1` временно отключает только DB automation для диагностического commit/merge; Git guard и docs hook продолжают работать.

### Обнаружение релевантных изменений

Общий script проверяет commit range текущего trigger и реагирует на:

- `database/migrations/**`;
- `database/schema/**`;
- `config/database.php`.

Другие PHP, model или query changes не требуют автоматического `migrate`. Если предыдущая попытка была отложена, локальный marker внутри `.git/` заставляет каждый следующий post-commit/post-merge повторить preflight даже без нового database path.

### Laravel preflight command

Read-only preflight использует установленный Laravel runtime и существующий `SeasonvarQueueStatus` вместо разбора человекочитаемого CLI output. Он:

1. получает список pending migrations через Laravel migrator;
2. завершает hook успешно без backup, если pending migrations нет;
3. fail-closed при недоступности DB, Redis queue status или importer state;
4. блокирует migrate, если любой writer/import signal активен;
5. принимает только текущий SQLite connection с физическим database file;
6. выполняет `PRAGMA quick_check`;
7. создаёт согласованный timestamped SQLite backup через SQLite `VACUUM INTO` в игнорируемом `storage/app/private/database-backups/`;
8. возвращает машинно-различимый status для shell script без вывода секретных путей.

Preflight не меняет migration repository и не запускает миграции. При blocked/error состоянии retry marker сохраняется.

### Запуск миграции и postflight

Только после успешного preflight общий script запускает ровно:

```bash
php artisan migrate
```

Флаги `--force`, `--graceful` и автоматический ответ на production prompt запрещены. Если Laravel требует production confirmation, оператор отвечает в текущем terminal; без интерактивного подтверждения команда завершается без изменения schema, а marker остаётся для следующей попытки.

После успешного migrate script выполняет:

1. Laravel postflight, подтверждающий отсутствие pending migrations;
2. повторный `PRAGMA quick_check`;
3. `php artisan queue:restart`, чтобы workers перечитали новый код/schema после текущей job;
4. `php artisan app:health --json` без публикации полного JSON в hook output;
5. удаление retry marker только после полного успеха.

`config:cache`, seeders и catalog import не входят в hook: config cache остаётся частью явного deployment workflow, а import нельзя смешивать со schema migration.

### Concurrency и ошибки

- Общий script использует атомарный lock directory внутри `.git/`, поэтому параллельные hooks не мигрируют одну базу одновременно.
- Active importer не останавливается автоматически. Hook пишет краткое сообщение, сохраняет marker и завершается без schema changes.
- Ошибка backup, quick check, migrate, queue restart или health check сохраняет backup и marker, возвращает ненулевой exit code и не выполняет rollback.
- Post-commit уже не может отменить созданный commit; сообщение явно говорит, что database rollout отложен. Post-merge также не переписывает Git history.
- Backup не удаляется автоматически. Retention остаётся ручной операционной политикой, чтобы hook не удалял единственную recovery copy.

## Поток данных

1. Git передаёт trigger context общему script.
2. Script вычисляет changed paths или обнаруживает retry marker.
3. Laravel preflight возвращает `skip`, `blocked`, `ready` или `error`.
4. При `ready` script запускает обычный `php artisan migrate`.
5. Laravel postflight и health commands подтверждают schema/runtime.
6. Marker удаляется только после полного успеха.

## Проверки

Новые PHPUnit tests без model factories покрывают:

- no-op без pending migrations;
- blocked state при любом active import/queue/claim signal;
- fail-closed при недоступном queue status;
- SQLite quick check и создание backup без изменения исходных данных;
- отказ для in-memory или non-SQLite connection;
- postflight с pending migration и успешный postflight;
- shell path detection, retry marker, lock и отсутствие запрещённых строк `--force`, `migrate:fresh`, seed/factory commands;
- интеграцию post-commit/post-merge с существующим docs hook.

Дополнительная verification:

- `bash -n` для всех hooks/scripts;
- focused PHPUnit tests;
- Pint для нового PHP;
- полный `php artisan test`;
- `composer hooks:install` и проверка `core.hooksPath`;
- ручной запуск общего script с forced check: на текущем active run он обязан отложить pending migration без изменения live schema;
- повторный read-only `php artisan migrate:status`, `seasonvar:import --status` и `app:health --json`.

## Rollback реализации

Rollback feature удаляет новый post-merge hook, вызов DB script из post-commit, общий script, preflight/postflight command/service и соответствующие tests/docs. Уже применённые migrations автоматически не откатываются. Созданные backup-файлы сохраняются до отдельного осознанного удаления оператором.
