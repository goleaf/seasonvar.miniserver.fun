# Admin-only catalog corrections

## Цель

Полностью убрать публичное действие «Исправить данные» и сделать типы
`metadata_correction` и `episode_list_correction` закрытым административным
workflow. Ограничение действует на UI, Livewire state, application action,
policy, query, route binding, SEO, sitemap, уведомления, help-center и
исторические строки, даже если у них сохранено `is_public=true`.

## Границы решения

- `ContentRequestType` — единый источник классификации административных и
  публично доступных типов.
- `ContentRequestPolicy` остаётся канонической server-side boundary.
  Авторизация создания получает выбранный тип как context.
- `ContentRequest::scopePubliclyVisible()` исключает административные типы
  независимо от mutable-флага `is_public`.
- Public title/player builders не строят correction URL; Blade controls и
  выделенный link-builder/component удаляются как dead code.
- Административная correction-заявка private с момента создания и не создаёт
  requester vote/follow.
- Исторические correction-заявки недоступны обычному requester и не
  участвуют в public feeds, search, sitemap, SEO или engagement.
- Help-center не предлагает административные типы как публичный escalation;
  сохранённый устаревший type обрабатывается fail-closed.
- Существующая moderation queue, target resolver, optimistic version,
  status history и catalog editor/import boundary сохраняются.

## Потоки авторизации

1. Обычный verified user видит в форме только
   `ContentRequestType::publicCases()`.
2. Direct query с административным типом отклоняется `403` до разрешения
   correction target.
3. Forged Livewire state и прямой вызов `CreateContentRequest` повторно
   авторизуются policy с type context.
4. Администратор с `manage-content-requests` может открыть защищённую форму
   и создать private correction-заявку.
5. Все read/engagement policy методы fail-closed для administrative-only
   типа, кроме moderation boundary.

## Данные и совместимость

Новая колонка или индекс не нужны. Существующие enum values, таблицы,
relations, status history и admin routes сохраняются. Additive reversible
data migration создаёт новую revision только для неизменённой базовой версии
двух help-статей; runtime allowlist остаётся окончательной защитой, если
редакция статьи уже была изменена вручную.

Rollback выполняется revert commit и rollback data migration. Rollback
возвращает прежние help revisions и код, но не меняет уже созданные
административные correction-заявки на public автоматически.

## Cache и deployment

Response-contract dimensions title/request HTML меняются, чтобы старые
страницы с public control не пережили deploy. После кода и migration
выполняется только документированная targeted invalidation для title,
content-request, search, sitemap и help namespaces. Глобальный cache flush и
новая инфраструктура не требуются.

## Проверка

PHPUnit покрывает отсутствие UI, запрет direct/forged creation, разрешённое
административное создание, private persistence, historical visibility,
engagement, sitemap/search/help/demo/cache contracts. Playwright проверяет
desktop/mobile отсутствие controls и запрет direct URL. Дополнительно
выполняются Pint, связанные и полные тесты, migration round-trip, build,
route review, repository scan, SQL `EXPLAIN`, staged diff review и secret
scan.
