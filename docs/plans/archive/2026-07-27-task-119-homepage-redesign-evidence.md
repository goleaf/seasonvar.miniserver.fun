# Task 119 — evidence полного редизайна главной

Дата: 27.07.2026.

## Результат

Главная `/`, `/ru` и `/en` получила утверждённую светлую цветную
композицию без изменения guest/auth data projection. Постер и основная
площадь `home`, `spotlight`, `trend`, latest-media и Continue Watching
карточек открывают сериал через одну whole-card title link. Отдельные CTA и
taxonomy links остаются самостоятельными foreground controls, а Continue
Watching сохраняет exact episode URL и `#player`.

Секции используют четыре системные поверхности: `amber` для тренда и
рекомендаций, `sky` для обновлений и подборок, `emerald` для просмотра и
личной точки возврата, `slate` для нейтральных данных и фасетов. Порядок
Task 94, все genres/countries/years Task 111, RU/EN, routes, API, ranking,
permissions, schema и persistent data сохранены.

## TDD и направленные проверки

- RED cards: `19` tests, `16` passed, `3` ожидаемо упали из-за отсутствия
  `data-home-title-link`.
- GREEN cards: `19` tests / `175` assertions.
- RED composition: `23` tests, `20` passed, `3` ожидаемо упали из-за
  отсутствия homepage/surface contract.
- GREEN composition: `23` tests / `172` assertions.
- RED cache: `5` tests, `4` passed; homepage contract оставался `2`.
- GREEN cache: `5` tests / `37` assertions; contract равен `3`.
- Финальный focused набор: `43` tests / `339` assertions.
- Projection/performance/facet/visual набор: `59` tests / `485` assertions.
- `CatalogPageTest`: `85` tests / `865` assertions.
- `--filter=CatalogHome`: `31` tests / `242` assertions.
- Focused PHPStan: `0` errors.
- Focused Rector: `0` changed files, `0` errors.
- `./vendor/bin/pint --dirty --format agent`: успешно.
- `npm run build`: Vite `8.1.4`, `28` modules, `31` player sources,
  `19` assets, release ready.
- Полный `scripts/ci-check.sh pre-push`: `2 305` PHPUnit tests,
  `2 294` passed, `11` skipped, `208 535` assertions; Composer audit и npm
  audit (`0` vulnerabilities), Pint, Rector, PHP syntax, PHPStan, docs,
  Laravel config/routes/views cache и Vite/player release прошли.

Первый запуск полного gate один раз получил внешний `HTTP/2 502` от
Packagist security-advisories. Отдельный повтор `composer audit` и полный
повторный gate завершились успешно; проектный обход или подавление audit не
добавлялись.

## Браузерная проверка

Новый `tests/browser/homepage-redesign.spec.js` проверяет:

- видимость всех `amber|sky|emerald|slate` surfaces;
- section actions высотой не меньше `44 px`;
- отсутствие горизонтального переполнения;
- реальный hit target в видимой координате постера, включая landscape между
  sticky header и fixed mobile navigation;
- переход гостя по фактическому `href`;
- exact Continue Watching destination с episode и `#player`;
- локализованную `/en` presentation;
- отсутствие ошибок console/page/HTTP на самой главной.

Основная матрица desktop/mobile/tablet прошла `9/9`. Расширенная матрица
desktop, mobile, tablet, narrow phone, phone landscape, tablet landscape и
TV-like Chromium прошла `21/21`. Desktop/mobile/auth screenshots просмотрены
визуально: секции различимы, карточки и действия читаемы, обязательный
контент не удалён.

## Compatibility и operations

- `home`, `localized.home`, `CatalogHomePage`, `CatalogHomePageBuilder`,
  `/api/v1/home`, recommendation/visibility/ownership contracts не менялись.
- Homepage guest full-response dimension повышена с `response_contract=2`
  до `3`, поэтому старый HTML недостижим без wildcard scan или broad flush.
- Authenticated/Livewire/private responses по-прежнему обходят shared cache.
- Dependencies, migrations, database writes, translations, permissions,
  importer, player grants и business JavaScript не менялись.
- Rollout: согласованная публикация application/Blade и собранных Vite
  assets с существующим cache/view/config workflow.
- Rollback: revert Task 119 presentation/tests/docs, Vite rebuild и возврат
  response contract; database restore и broad cache flush не нужны.
- Посторонние `composer.lock` и `storage/debugbar/.gitignore` исключены из
  task scope.

## Delivery

Полный pre-push gate завершён успешно. Exact staged diff approval, commit в
`main` и push attempt фиксируются после завершения этих шагов. Ранее
настроенный HTTPS remote не имел доступного credential helper; возможный
внешний отказ должен быть отмечен как `unresolved`.
