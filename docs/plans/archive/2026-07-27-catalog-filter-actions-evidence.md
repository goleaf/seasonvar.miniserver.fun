# Task 118 — evidence панели действий фильтров каталога

Дата: 27.07.2026.

## Результат

- Действия «Показать … сериалов», «Отменить изменения» и «Сбросить фильтры»
  в единой форме `/titles` расположены вертикально и занимают полную ширину
  панели на всех проверенных размерах экрана.
- Длинная динамическая подпись результата переносится внутри основной кнопки;
  каждое действие имеет минимум `44 px` и заметный `focus-visible`.
- `applyFilters`, `resetAll`, GET fallback, маршруты, query parameters,
  переводы и маркеры `data-catalog-filter-submit-label` /
  `data-catalog-filter-cancel` сохранены.

## TDD и focused verification

- RED `PHPUnit`:
  `php artisan test tests/Feature/CatalogVisualSystemTest.php
  --filter=test_catalog_filter_actions_are_a_vertical_full_width_stack` —
  ожидаемое падение нового контракта: marker отсутствовал, `1` тест,
  `2` утверждения до остановки.
- RED `Playwright`: desktop-проверка ожидала
  `data-catalog-filter-actions` и ожидаемо завершилась ошибкой до изменения
  Blade.
- GREEN focused `PHPUnit`: тот же тест — `1` тест, `10` утверждений.
- Соседние наборы:
  `CatalogVisualSystemTest` — `39` тестов / `349` утверждений,
  `CatalogTitlesUxRedesignTest` — `7` / `54`,
  `CatalogPageTest` — `85` / `865`; суммарно `131` тест /
  `1 268` утверждений.
- `PLAYWRIGHT_DEVICE_MATRIX=extended` для нового сценария —
  `3/3`: desktop, mobile и узкий телефон `320×568`; проверены вертикальная
  геометрия, полная ширина, `44 px`, клавиатурный фокус и отсутствие
  горизонтального переполнения.

## Общая verification

- `./vendor/bin/pint --dirty --format agent` — успешно.
- `php artisan project:docs-refresh --check`,
  `bash scripts/ci-check.sh docs` и `git diff --check` — успешно.
- `bash scripts/ci-check.sh frontend` — успешно: `0` уязвимостей, Vite build
  и `player:release-check` завершились без ошибок.
- Первый pre-push-запуск выявил единичное нестабильное падение несвязанного
  `WebPushSubscriptionTest`: `503` вместо `201`; `2 290` тестов прошли,
  `11` были пропущены. Проблемный тест отдельно прошёл `1/1`, весь его файл —
  `4/4`.
- Повторный полный `bash scripts/ci-check.sh pre-push` на том же коммите
  завершился успешно: `2 302` теста, `2 291` успешный,
  `11` пропущено, `208 472` утверждения; Pint, Rector, PHPStan, синтаксис,
  Laravel cache/view gates, audit, Vite build и player release-check зелёные.

## Совместимость и эксплуатация

- Routes, API, migrations, данные, queries, cache keys и permissions:
  `already_compliant`, изменения отсутствуют.
- RU/EN translation keys и locale hydration: `already_compliant`, новые
  строки не добавлялись.
- Authentication, privacy, notifications, Premium, payments, region/legal,
  importer, player и PWA/service worker: `not_applicable` либо не затронуты
  presentation-only изменением.
- Production release unit — Blade-код и собранные Vite assets; data backup,
  migration и cache flush не требуются.
- Rollback — revert Task 118 code/docs commits и повторный Vite build без
  восстановления данных.

## Delivery

- Implementation commit: `d6286c7c`.
- Повторный pre-push: `completed`.
- Push: `unresolved`. После implementation-коммита
  `composer git:doctor -- --remote` подтвердил чистый `main` и `ahead=14`,
  но не нашёл credential helper; фактический `git push origin main`
  завершился ошибкой
  `could not read Username for 'https://github.com'`. После evidence-коммита
  проверка повторно подтвердила чистый `main`, уже `ahead=15`, а вторая
  фактическая попытка push завершилась той же ошибкой и кодом `128`.
- Посторонние изменения `composer.lock` и
  `storage/debugbar/.gitignore` не включались в индекс. Перед каждым
  clean-snapshot gate они сохранялись точным binary patch и восстанавливались
  с тем же SHA-256
  `0ad8cc0c1217fefdf431fe60bd42260b1135923b2f164c78da6432f2a8167e2e`.
