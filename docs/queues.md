# Очереди и jobs

Обновлено: 09.07.2026

## Конфигурация

- Основное подключение очереди по умолчанию: `QUEUE_CONNECTION=database`.
- Таблицы `jobs`, `job_batches` и `failed_jobs` создаются миграцией `0001_01_01_000002_create_jobs_table.php`.
- Для database, Redis и Beanstalkd `retry_after` по умолчанию равен 1200 секундам. Это больше тайм-аута долгого job импорта и защищает от повторного запуска той же работы, пока worker еще ее выполняет.
- `composer dev` запускает локальный `queue:listen` с `--timeout=900 --tries=3`, чтобы jobs не выполнялись бесконечно и повторялись по той же политике, что production worker.
- Для отдельного worker использовать: `php artisan queue:work database --timeout=900 --tries=3`.

## Jobs

- `App\Jobs\RunSeasonvarImport` — внутренний queued wrapper для тяжелого импорта Seasonvar. Публичной командой остается `php artisan seasonvar:import`.
- Job принимает только scalar-параметры: URL-аргумент, `force` и `discover`. Модели или большие структуры данных в очередь не передаются.
- Job не поддерживает `forever`-режим: queued import всегда выполняет ограниченный запуск, чтобы worker не занимался бесконечным циклом.
- Job реализует `ShouldBeUnique`, использует уникальный ключ `seasonvar-import` и дополнительно берет тот же cache lock, что CLI-команда. Если импорт уже идет, job освобождает себя обратно в очередь через 300 секунд.
- Политика выполнения job: `tries=3`, `timeout=900`, backoff `[60, 300, 900]`, `uniqueFor=3600`.
- Ошибки job логируются через `failed()` с URL-аргументом и флагами запуска; сама pipeline уже помечает созданный `SeasonvarImportRun` как failed.
