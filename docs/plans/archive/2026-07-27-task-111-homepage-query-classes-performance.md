# Task 111 — homepage, discussions и query boundaries

## Scope

Task 111 исправляет гостевую загрузку обсуждений, выводит полный набор жанров,
стран и допустимых лет на главной, сокращает запросы homepage и закрепляет
Query Class pattern, request-scoped memoization, безопасные callback-функции
коллекций и составную валидацию внешних идентификаторов.

## Подтверждённое evidence

- Причина гостевой ошибки воспроизведена как `MissingAttributeException` для
  отсутствующего `viewer_private_replies_count`; guest-проекция теперь всегда
  возвращает нулевой alias.
- Homepage relation facets объединены в один bounded union query; web получает
  все строки, а API сохраняет limits 18 жанров и 12 лет.
- Добавлены три `final readonly` Query Class с единственным публичным
  `handle()`, существующие cache boundaries и публичные маршруты сохранены.
- Для personal updates добавлен обратимый индекс
  `release_schedule_title_released_idx`, соответствующий коррелированному
  фильтру `(catalog_title_id, status, released_at, id)`.
- `CatalogTasteOnboardingSchema` зарегистрирован через `scopedIf`; lifecycle
  проверяется после сброса scoped instances.
- Side-effect callback-функции `Collection::each()` стали явными `void`
  closures; AST regression запрещает повторное появление arrow callbacks.
- Внешние идентификаторы валидируются по составной нормализованной паре
  `(provider, identifier)` без запрета одинакового identifier у разных
  providers.
- Project skills проверены локальным validator; конфликтующие внешние
  инструкции и небезопасный package `checkpoint` не установлены.
- Финальный large-project blueprint сопоставлен с текущим кодом: services,
  view models, API Resources, config owners и Blade components уже покрывают
  полезные границы. Interfaces/repositories для каждого класса, global view
  composer, wholesale domain rewrite и неподтверждённый zero-downtime claim
  отклонены как лишние или недоказанные.
- Полный suite обнаружил upstream regression Laravel 13.22.0 в
  `logoutOtherDevices()` без remember-cookie. Exact version/message/stack
  shim не меняет `vendor`, восстанавливает пропущенный
  `OtherDeviceLogout`, сохраняет текущую сессию и остаётся неактивным после
  следующего framework patch.
- Broad regression repair сохранил validated player query-state между
  full-page и nested Livewire owners, восстановил подготовленные directory/tag
  секции, синхронизировал отображаемый rating provider Top‑100 с ранжированием
  и изолировал hook subprocess от lease текущего test runner.

## Verification status

- Focused PHP tests: `64` tests, `402` assertions — пройдены до broad run.
- Связанный catalog/player quality набор: `35` tests, `255` assertions —
  пройден после обновления текущих SSR contracts.
- Финальная группа обнаруженных broad regressions: `13` tests,
  `150` assertions — пройдена.
- Playwright homepage matrix: guest/authenticated × desktop/mobile/tablet —
  `6/6`.
- Playwright player lifecycle matrix: Desktop Chromium и Desktop Firefox —
  `16` passed, `6` skipped по project-specific матрице из `22` тестов.
- `composer validate`, `composer audit`, `npm audit`, Vite build, Pint,
  Larastan и Rector dry-run — пройдены.
- Full backend gate: `2286` tests, `2275` passed, `11` skipped,
  `208315` assertions — пройден. Все `23` project skills повторно прошли
  `quick_validate.py`.
- Финальный player build после добавления `CatalogTitleDetail` в release
  identity вернул `ready: true`, `30` исходников и `19` assets.
- Commit и push не считаются выполненными до exact staged review и clean
  pre-push.

## Production и rollback

- Миграция additive и удаляет только новый индекс в `down()`.
- Cache keys versioned; rollback возвращает прежние query/cache classes без
  изменения public routes или JSON shape.
- Реальные production backup/restore и timing создания индекса остаются
  operational verification на целевом сервере перед миграцией.
