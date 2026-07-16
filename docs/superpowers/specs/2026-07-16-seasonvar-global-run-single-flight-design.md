# Seasonvar global run single-flight — design

Дата: 16.07.2026

## Проблема

Production read-only audit обнаружил два одновременно живых sitemap-run: queued run и sync run, оба с актуальным heartbeat. Текущий `SeasonvarGlobalImportRunCoordinator` сериализует только создание queued run и фильтрует active state по `execution_mode=queue`. Синхронная команда использует отдельный долгоживущий Redis-lock `seasonvar-import`, после чего `SeasonvarImportPipeline` самостоятельно создаёт sync run. Поэтому queued и sync start decisions не атомарны относительно друг друга.

## Решение

`SeasonvarGlobalImportRunCoordinator` остаётся единственной lifecycle start boundary для полного sitemap import и использует существующий короткий distributed start-lock. Под этим lock он ищет active `queued|running` sitemap-run независимо от execution mode.

- Queued start сохраняет текущий контракт: либо возвращает существующий global run, либо создаёт один queued run.
- Sync start получает отдельный typed method coordinator, который либо возвращает существующий global run, либо заранее создаёт sync run со status `running`, process metadata, timestamps и heartbeat.
- `SeasonvarImportPipeline` принимает только уже зарезервированный совместимый sync run либо, для legacy/targeted callers, создаёт run как раньше.
- CLI и legacy `RunSeasonvarImport` используют sync reservation только для полного sitemap import. Импорт конкретного URL остаётся независимым и не блокируется global lifecycle.
- Inventory-only и status paths не входят в catalog global-run ownership.

Короткий start-lock удерживается только во время active check и вставки run. Он не удерживается на протяжении внешнего HTTP, queue processing или полного import cycle.

## Инварианты

- Одновременно создаётся не больше одного active sitemap-run среди `sync` и `queue`.
- Active sync run запрещает новый queued/admin global run; active queued run запрещает новый full sync run.
- Повторный start успешен идемпотентно и возвращает существующий run без dispatch и без создания audit-дубликата.
- URL-targeted refresh не становится частью global single-flight.
- Terminal и non-sitemap runs не блокируют новый global run.
- Existing run data, claims, jobs, route/command identity и `php artisan seasonvar:import` сохраняются.

## Ошибки и восстановление

Если создание reservation не удалось, import не начинается. Если процесс завершился после sync reservation, существующий process-inspector/recovery flow видит process metadata и закрывает неподтверждённый sync run по текущим правилам. Raw process command, URL и exception text не добавляются в новые пользовательские сообщения.

Текущие два production run не изменяются автоматически: fix предотвращает новые пересечения, а disposition существующих run остаётся отдельным операторским решением после safe boundary.

## Проверка

- RED/GREEN: active queued run блокирует full sync CLI до вызова pipeline.
- RED/GREEN: active sync run переиспользуется queued/admin coordinator без dispatch.
- RED/GREEN: sync reservation создаёт ровно один correctly-shaped run.
- Regression: targeted URL import не блокируется active global run.
- Focused importer tests, Pint, changed-scope Larastan, broader importer regression and full suite before completion.

## Rollback

Revert coordinator reservation and pipeline optional reserved-run integration. Миграций и автоматических production state mutations нет; data rollback не требуется.
