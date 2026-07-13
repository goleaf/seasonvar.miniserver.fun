# Очереди и jobs

Обновлено: 13.07.2026

## Конфигурация

- Основное подключение очереди по умолчанию: `QUEUE_CONNECTION=database`.
- Таблицы `jobs`, `job_batches` и `failed_jobs` создаются миграцией `0001_01_01_000002_create_jobs_table.php`.
- Для database, Redis и Beanstalkd `retry_after` по умолчанию равен 1200 секундам. Это больше тайм-аута долгого job импорта и защищает от повторного запуска той же работы, пока worker еще ее выполняет.
- `composer dev` запускает локальный `queue:listen` с `--timeout=900 --tries=3`, чтобы jobs не выполнялись бесконечно и повторялись по той же политике, что production worker.
- Для отдельного worker использовать: `php artisan queue:work database --timeout=900 --tries=3`.

## Jobs

- `App\Jobs\RunSeasonvarImport` — внутренний queued wrapper для тяжелого импорта Seasonvar. Публичной командой остается `php artisan seasonvar:import`.
- `StartSeasonvarQueuedImport` — короткий coordinator для `/admin/imports`: payload содержит только `seasonvar_import_runs.id`, затем job вызывает тот же `SeasonvarQueuedImportDispatcher`, что CLI `--queued`.
- Coordinator ограничен 3 attempts, timeout 900 секунд и backoff 60/300/900; `ShouldBeUnique` держит lock на ID run. Page/finalizer jobs ограничены absolute retry window, согласованным с claim lease.
- `SeasonvarImportFailureClassifier` разделяет temporary и permanent failures. Первые повторяют coordinator, вторые сразу фиксируют `failed`; queue logs и persisted error fields проходят `SeasonvarImportErrorSanitizer`.
- Queued finalizer выбирает `licensed_media` только по наступившему `next_check_at`, в стабильном `id`-порядке проверяет не более `SEASONVAR_MEDIA_CHECK_MAX_PER_CYCLE` строк и применяет health state вне catalog transaction. Default cap `20` совместим с worker timeout при трёх 10-секундных попытках; `SEASONVAR_MEDIA_CHECK_CHUNK_SIZE` ограничивает только размер чтения. После batch finalizer пересобирает рекомендации и stats snapshot; permanent source error не делает сам finalizer job failed.
- Перед внешним выбором и catalog-wide derived work finalizer выполняет bounded local metadata-backfill из сохранённых snapshots. Page/title limits не дают накопившемуся version backlog вытеснить остальные стадии одного 900-секундного job.
- Recommendation rebuild ограничивает предварительный candidate pool через `SEASONVAR_RECOMMENDATION_CANDIDATE_LIMIT` и `SEASONVAR_RECOMMENDATION_CANDIDATE_SCAN_PER_FEATURE`; широкие слабые признаки учитываются только после отбора. Это удерживает полный rebuild внутри worker memory/timeout boundary.
- Unique key finalizer остаётся привязан к run ID. Дополнительный Redis lock `seasonvar-import-finalizer` сериализует catalog-wide finalization между разными runs, имеет TTL `job timeout + 300`, освобождается в `finally` и заставляет конкурирующий job обновить heartbeat и release на `SEASONVAR_QUEUE_FINALIZER_DELAY_SECONDS`. Run state и claims проверяются повторно уже под lock.
- Run states: `queued -> running -> completed|partial|failed`; admin cancel даёт `cancelled`. Heartbeat обновляется coordinator/page/finalizer шагами, а admin service может закрыть `running` run с просроченным heartbeat, если живых claims больше нет. Порог — `SEASONVAR_QUEUE_STALE_AFTER_MINUTES`.
- Retry из admin UI разрешён только для `partial/failed` и создаёт новую audit-строку. Для старых Laravel failed jobs остаётся `php artisan queue:failed`/`queue:retry <id>`; перед retry нужно сверить run state и claims.
- Job принимает только scalar-параметры: URL-аргумент, `force` и `discover`. Модели или большие структуры данных в очередь не передаются.
- Job не поддерживает `forever`-режим: queued import всегда выполняет ограниченный запуск, чтобы worker не занимался бесконечным циклом.
- Job реализует `ShouldBeUnique`, использует уникальный ключ `seasonvar-import` и дополнительно берет тот же cache lock, что CLI-команда. Если импорт уже идет, job освобождает себя обратно в очередь через 300 секунд.
- Политика выполнения job: `tries=3`, `timeout=900`, backoff `[60, 300, 900]`, `uniqueFor=3600`.
- Ошибки job логируются через `failed()` с URL-аргументом и флагами запуска; сама pipeline уже помечает созданный `SeasonvarImportRun` как failed.
- Если задан `SEASONVAR_IMPORT_FAILURE_MAIL_TO`, `RunSeasonvarImport::failed()` отправляет queued on-demand notification `SeasonvarImportFailed` на email получателя. Notification использует queue из `NOTIFICATIONS_MAIL_QUEUE`.
