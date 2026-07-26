# Task 106 — evidence восстановления импорта Seasonvar

## Результат

Visitor-triggered обновление карточки восстановлено. До recovery четыре
`seasonvar-title-refresh` jobs ожидали без workers, а семь durable groups
оставались в `finalizing`; после запуска штатного пула все относящиеся к
проверке targeted runs получили `completed`. Итог групп: 30 expected, 30
prepared, 30 applied, 0 failed pages. Обе очереди завершили проверку с
нулевыми pending, delayed и reserved.

Redis state восьми затронутых `CatalogTitle` подтверждён как `completed`.
Каждая их публичная страница вернула HTTP `200`; контрольная серверная разметка
не содержит активное «Обновляем данные». Главная вернула HTTP `200`, readiness
— `status=ok` и `ready=true`.

## Исправление и production recovery

- `ImportSeasonvar` проверяет все `sitemap/sync/running` rows через
  `SeasonvarImportProcessInspector` до нового sync или queued lifecycle даже
  после исчезновения прежнего cache lock.
- RED реконструирован удалением двух новых call sites: три точных regression
  test ожидаемо упали. После восстановления реализации GREEN: 3 теста,
  17 assertions.
- PHPUnit logging изолирован существующим `null` channel; focused contract:
  1 тест, 1 assertion.
- Проверенная согласованная SQLite backup прошла SHA-256, `quick_check=ok` и
  нулевой `foreign_key_check`; private directory приведён к `0700`, файлы —
  к `0600`.
- Три заранее определённые additive migrations имеют состояние `Ran`.
- Подтверждённый live sync process завершён штатным `SIGTERM`; pipeline сам
  записал `cancelled`, `finished_at` и `cancel_requested_at`.
- Дублирующая aaPanel sync task отключена, её сохранённый body/script использует
  `www` как fail-safe; root cron entry отсутствует. Восстановлен ровно один
  canonical `www` cron `seasonvar:import --queued`.
- Длительный `schedule:work`, найденный на host, проверен по cwd и systemd
  cgroup: он принадлежит другому checkout и не является вторым scheduler
  Seasonvar. Для этого проекта scheduler owner — минутный `schedule:run` cron
  пользователя `www`.
- Активны четыре import, восемь title-refresh и один cache-warm systemd unit;
  все соответствующие PHP worker processes принадлежат `www`.
- Failed jobs не увеличились. Queue clear, cache clear, массовые retry/forget,
  ручная mutation import ledger и раскрытие provider URL не выполнялись.

## Verification

- Focused importer/Livewire/finalizer suite: 88 тестов, 591 assertion, GREEN.
- Seasonvar-wide suite: 302 из 304 тестов прошли, 1967 assertions; два
  независимых baseline blockers сохранены без изменений:
  `SeasonvarTitleMergeTest` проходит через прерванный чужой
  `CatalogTitlePageBuilder`, а `SeasonvarImportDispatchBatcherTest` ссылается
  на отсутствующий в текущем `HEAD` application class.
- `Pint` для `app/Console/Commands/ImportSeasonvar.php`: GREEN.
- Production HTTP: восемь затронутых title routes — `200`, home — `200`,
  readiness — `ok`.

## Unresolved

Первый немедленный global queued dispatcher совпал со стартовым всплеском
SQLite writers. Он создал run, но не получил claims/staging и не смог записать
terminal failure из-за повторного `database is locked`. Строка оставлена
канонической `SEASONVAR_QUEUE_STALE_AFTER_MINUTES` recovery; вручную её статус
и heartbeat не менялись. Это не удерживает targeted title refresh queue, уже
проверенную в terminal `completed`, но следующий полный global cycle остаётся
неподтверждённым до штатного stale recovery.

Обычный push может быть заблокирован требованием clean worktree, поскольку
прерванная Task 107 оставила чужие незакоммиченные paths. Они не входят в
Task 106 implementation и не изменялись этой задачей.
