# PHPUnit `Storage::fake()` — изоляция concurrent процессов

Дата: 20.07.2026

## Причина

Laravel 13 `Storage::fake('uploads')` очищает `storage/framework/testing/disks/uploads`. Суффикс `_test_{token}` добавляется только при truthy `ParallelTesting::token()`. Два обычных `php artisan test` процесса в одном checkout поэтому могут удалить fake-файлы друг друга даже без `--parallel`; полный suite дважды потерял demo collection cover, тогда как тот же класс четыре последовательных раза проходил изолированно.

## Решение

- В общем `Tests\TestCase::setUp()` проверять существующий `ParallelTesting::token()` после boot приложения.
- Только при отсутствии token устанавливать resolver на текущий PID; реальный Paratest token не переопределять.
- Оставить disk alias `uploads`, application config и все test calls `Storage::fake('uploads')` без изменений.
- Не вводить production config, dependency, environment variable, global temp cleanup или случайный UUID на каждый test: внутри одного процесса последовательная очистка должна сохраниться.

## Совместимость и rollback

Изменение действует только в PHPUnit base class. Database, cache, sessions, queues, production storage, uploads, routes, Livewire, browser behavior и persistent data не меняются. Rollback удаляет process-token guard и regression test, но снова разрешает collision между concurrent serial runners одного checkout.
