# Архитектура надёжной очереди Seasonvar

Дата: 12.07.2026

## Цель

Применить полезные правила из статей о Laravel architecture и queue worker lifecycle к текущей боли проекта: очередь не должна считать временную ошибку источника успешно завершённой job, а код обработки ошибок, статуса очереди и queue bootstrap должен иметь отдельные причины для изменения.

## Выбранный подход

Используется инкрементальная архитектура. Существующие границы `app/Services/Seasonvar`, `app/Services/Media` и `app/Services/Crawler` сохраняются. Новые `Actions`, `DTOs`, `Enums`, `Exceptions` и `Providers` появляются только там, где они получают реальную ответственность и тестируемый контракт.

Полный перенос контроллеров, моделей и 54 service-классов не выполняется. Не добавляются repository pattern, Laravel Horizon, job batches, пустые Observers/Policies и production dependencies.

## Компоненты

### Тип ошибки

`SeasonvarImportFailureType` содержит `transient` и `permanent`. Временными считаются connection timeout, HTTP 408/425/429/5xx и исчерпанная блокировка SQLite. HTTP 404 и ошибки содержимого страницы являются постоянными для текущей job.

`SeasonvarSourceRequestException` хранит HTTP status и предоставляет структурированный log context. Класс не принимает URL извне без предварительной проверки существующим `SeasonvarUrl`.

### Атомарная запись failure

`RecordSeasonvarPageFailure` является action с одной причиной для изменения: перевод `SourcePage` в ошибочное состояние. Action увеличивает `failure_count` один раз, вычисляет `retry_after_at`, сохраняет `last_import_run_id` и возвращает тип ошибки.

HTTP 404 получает `import_status=gone` и повторную плановую проверку через семь дней. Остальные ошибки получают экспоненциальную паузу от 15 минут до 24 часов.

### Поведение job

Синхронный импорт продолжает обрабатывать следующую страницу после любой ошибки. Queued-импорт повторно выбрасывает только transient exception, поэтому Laravel применяет существующие `backoff()` и `retryUntil()`.

На transient exception claim сохраняется. На успешном или permanent результате claim освобождается. После исчерпания queue retry window метод `failed()` освобождает только принадлежащий job claim и увеличивает счётчик run один раз.

Page jobs не получают `ShouldBeUnique`: database claim остаётся источником истины, а Redis group lock защищает общий тайтл от одновременной записи.

### Queue lifecycle

`SeasonvarQueueServiceProvider` регистрирует queue hooks отдельно от HTTP/view bootstrap:

- `Queue::looping()` откатывает транзакции, оставленные предыдущей job;
- `Queue::exceptionOccurred()` и `Queue::failing()` передают структурированный контекст в `SeasonvarQueueMonitor`;
- `QueueBusy` логируется с Redis throttle, чтобы не создавать запись каждую минуту.

Dispatch page jobs и finalizer использует `afterCommit()`.

### Статус очереди

`SeasonvarQueueStatusData` является readonly DTO. Он содержит connection, queue, pending/delayed/reserved counts, время старейшей pending job, количество живых claims и последний queued run.

`php artisan seasonvar:import --status` печатает DTO и ничего не ставит в очередь. Это сохраняет `seasonvar:import` единственной публичной командой Seasonvar.

### Защитные настройки

В non-production используется `Model::shouldBeStrict(true)`. В production вызывается `DB::prohibitDestructiveCommands(true)`. Новые PHP-файлы используют `declare(strict_types=1)`.

Systemd worker получает явный `--memory=256`, при этом сохраняются `--timeout=900`, `--max-time=3600` и `--max-jobs=1000`. Redis `retry_after=1200` остаётся больше worker timeout.

## Тестирование

- Unit tests проверяют классификацию HTTP, connection и SQLite lock exceptions.
- Feature tests проверяют единственное увеличение `failure_count`, статус `gone`, transient rethrow и permanent result.
- Queue tests проверяют сохранение/release claim, run counters и `afterCommit`.
- Command test проверяет `--status` без dispatch.
- Provider/guard tests проверяют Eloquent strictness и transaction cleanup.
- После focused tests запускаются Pint и полный `php artisan test` при остановленных workers.

## Production rollout

Во время реализации workers остаются остановленными, Redis backlog и claims сохраняются. После проверки обновляется установленный systemd template и добавляется queue monitor cron. Workers не запускаются автоматически; запуск остаётся отдельным осознанным действием оператора.
