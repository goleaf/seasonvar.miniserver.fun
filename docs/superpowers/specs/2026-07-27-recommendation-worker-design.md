# Полный пересчёт рекомендаций в worker — дизайн

Дата: 27.07.2026
Статус: утверждён пользователем (`dавай`)

## Цель

Убрать catalog-wide v6 rebuild из длительного synchronous import path. После
полного импорта пересчёт для всех опубликованных тайтлов должен выполняться
уже существующим Redis worker на очереди `seasonvar-import`, а активный shadow
build должен оставаться доступным до успешной активации нового.

## Решение

- Добавить уникальный `RebuildCatalogRecommendations` job. Он ждёт завершения
  активного импорта, затем вызывает существующий
  `CatalogTitleRecommendationBuilder::rebuildDirty(..., allowFullRebuild: true)`.
- Job использует существующие Redis connection/queue/lock store, ограниченный
  timeout ниже `retry_after`, абсолютное окно повторов и
  `WithoutOverlapping`. Память процесса задаётся уже установленным worker
  profile (`256M` PHP limit/`192M` Laravel limit), без изменения server config.
- Synchronous public `seasonvar:import` передаёт pipeline новый явный флаг
  handoff и записывает только bounded queued result; прямые вызовы pipeline в
  старых тестах и maintenance tooling сохраняют синхронный контракт по
  умолчанию.
- Queued finalizer после deferred recommendation stage ставит тот же полный
  job. Scoped collection rebuild остаётся отдельным bounded job.
- После успешной активации job запускает существующий `WarmCatalogCaches`.
  При ошибке shadow build активные строки и dirty signals не удаляются.

## Границы

Персональные рекомендации не материализуются для каждого пользователя:
существующее ранжирование по user state продолжает читать активные title-level
строки. Маршруты, schema, permissions, translations и external provider
contracts не меняются. 502 во время старого synchronous cron устраняется после
перевода cron на `php artisan seasonvar:import --queued`; внешний aaPanel cron
не редактируется этим application commit.

## Rollback и rollout

Откат — остановить dispatch нового job и временно передать pipeline
`queueRecommendations: false`; активный shadow build не требует data restore.
Перед rollout workers должны быть перезапущены штатным способом, а queue
`retry_after` должен оставаться больше job timeout. Если aaPanel cron всё ещё
запускает старую synchronous команду, это фиксируется как unresolved
operational step, а не маскируется успешным application deploy.
