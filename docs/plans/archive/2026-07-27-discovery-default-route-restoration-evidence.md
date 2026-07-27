# Task 116 — evidence восстановления `/discover/`

Дата: 27.07.2026.

## Причина

- production и feature test подтвердили `404` для `/discover/`;
- `git show 16d90668^:routes/web.php` подтвердил прежние
  `discover.default` и `localized.discover.default`;
- текущие `/discover/{type}` уже используют один `CatalogDiscoveryPage` и
  один `CatalogCollectionExplorer`;
- Task 109/110 уже восстановили полный справочник из пяти root и
  31 child во всех девяти modes.

Следовательно, data backfill, ослабление public quality и повторная
реализация дерева не требовались.

## Реализация

- `/discover` и trailing-slash request получают `302` на
  `/discover/popular#collections`;
- `/{locale}/discover` получает `302` на locale-aware
  `/{locale}/discover/popular#collections`;
- возвращены стабильные route names `discover.default` и
  `localized.discover.default`;
- удалённые legacy aliases, component/query/data/cache/permission contracts
  не изменены.

## Verification

- RED: `test_default_discovery_routes_redirect_to_popular_collections`
  получил ожидаемый `404` вместо `302`;
- GREEN: тот же тест — `1` test, `12` assertions;
- focused hierarchy/layout/query suite — `30` tests, `340` assertions;
- расширенная discovery/route/cache safety matrix — `58` tests,
  `494` assertions;
- task-scoped `Pint` — pass;
- изолированный `APP_ROUTES_CACHE` — `Routes cached successfully`;
- `route:list --path=discover` показывает четыре маршрута:
  default/index и localized default/index;
- `npm run build` — `28 modules`; player release ready, `31` sources,
  `19` assets;
- отдельный Playwright desktop/mobile — `2 passed`; конечный URL
  `popular#collections`, root count `5`, child count `31`, overflow и
  browser errors отсутствуют;
- production `curl` — `/discover/` отвечает `302` с
  `Location: /discover/popular#collections`, `/en/discover` сохраняет
  locale; SSR `/discover/popular` содержит category-tree marker,
  «Темы и жанры» и «Мини-сериалы».

## Ограничения и постороннее evidence

- полный прежний `discovery-collections.spec.js` дошёл до финальной общей
  проверки и зафиксировал только существующий посторонний
  `404 /pwa/posters/browser-smoke` на desktop/mobile; новый изолированный
  route/hierarchy сценарий прошёл без ошибок;
- public response продолжает выдавать cookie Laravel Debugbar из текущей
  runtime-конфигурации. Task 116 не меняет `.env` или server config;
  production debug configuration остаётся отдельным `unresolved` риском;
- Task 113 зафиксирован отдельным commit `a7322a91`; user-authorized Tasks
  114–116 подготовлены одним exact reviewed snapshot. Непроверенное
  обновление `composer.lock` не включено и сохраняет push как `unresolved`
  до отдельного maintenance review или штатного handoff.

## Rollback

Удалить два default entry routes и вернуть прежний `404` test contract.
Migration, data restore, cache flush, asset или dependency rollback не
нужны.
