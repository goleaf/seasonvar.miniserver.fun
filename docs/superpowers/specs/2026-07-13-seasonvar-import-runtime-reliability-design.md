# Надёжность непрерывного импорта Seasonvar

**Дата:** 13.07.2026

## Цель

Устранить два источника бессмысленных повторов queue jobs и добавить версионируемый reboot-safe способ постоянно обновлять XML sitemap и каталог одним последовательным процессом.

## Устаревшие задания обновления тайтла

`RefreshSeasonvarCatalogTitle` принимает только `catalog_title_id`. Между постановкой и выполнением тайтл может быть удалён. Это нормальная гонка жизненного цикла, а не ошибка импортера.

Если строки больше нет, job:

1. удаляет `CatalogTitleRefreshStateStore` для этого ID;
2. не создаёт import run или title group;
3. успешно завершается без exception, retry и error log.

Недопустимый URL существующего тайтла остаётся permanent failure и продолжает проходить через текущую валидацию.

## Retry deadline и claim lease

`PrepareSeasonvarImportTitlePage` владеет или ожидает page claim, а `FinalizeSeasonvarImportTitleGroup` ждёт завершения всех preparation jobs. Поэтому их абсолютный retry deadline не может быть короче активного lease страницы.

Оба job используют окно:

```text
max(300, seasonvar.queue.retry_window_seconds, seasonvar.queue.claim_seconds)
```

Для preparation job это также `uniqueFor`. Для finalizer unique lock живёт на 300 секунд дольше retry window, как и раньше. Более длинная явно настроенная retry policy продолжает иметь приоритет над claim duration.

## Один постоянный процесс

Новый `deploy/systemd/seasonvar-import-forever.service` запускает единственную публичную команду:

```text
/usr/bin/php -d memory_limit=256M artisan seasonvar:import --forever
```

Unit использует `User=www`, каталог проекта, `Restart=always`, корректный `SIGTERM` и включается через `multi-user.target`. Это сохраняет один процесс после reboot и не создаёт queue workers.

Последовательный профиль взаимоисключающий с queued production-профилем: перед его включением оператор отключает import/title-refresh worker templates и cron `seasonvar:import --queued`. Версионируемый unit добавляется в репозиторий, но текущий transient importer и `/etc/systemd/system` в рамках изменения не мутируются.

## Проверки

- Feature test подтверждает no-op и очистку state для удалённого тайтла.
- Feature tests проверяют claim-bound и retry-bound deadlines preparation/finalizer jobs.
- Unit test проверяет точную команду и single-service contract systemd unit.
- Focused PHPUnit, Pint, полный PHPUnit и документационные проверки подтверждают отсутствие регрессий.
