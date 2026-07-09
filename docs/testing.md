# Тестирование

Обновлено: 09.07.2026

## Стек

- Тесты пишутся как PHPUnit-классы в `tests/Feature` и `tests/Unit`; Pest в проекте не установлен.
- PHPUnit использует SQLite в памяти через `phpunit.xml`.
- Для тестов, которые пишут в базу, использовать `RefreshDatabase`.
- Для данных каталога использовать существующие фабрики: `CatalogTitle`, `Season`, `Episode`, `LicensedMedia`, `Source`, `SourcePage`, `User`.
- Production-данные не сидируются; seeders не являются частью обычного тестового сценария.

## Паттерны

- Feature-тесты должны покрывать маршруты, Form Request-валидацию, авторизацию, sitemap/robots, importer/media behavior и database side effects.
- Read-only страницы проверять через HTTP helpers: `get()`, `getJson()`, `assertOk()`, `assertRedirect()`, `assertInvalid()` и `assertSessionHasErrors()`.
- Для importer, crawler, playlist и media-check тестов блокировать реальные сетевые вызовы через `Http::preventStrayRequests()` и задавать `Http::fake()` для ожидаемых URL.
- Если тестируемая логика окажется внутри Blade, сначала перенести ее в request, service, view-model, component class, accessor или enum, а затем тестировать PHP-код.
- В проекте сейчас нет app-specific jobs, events, notifications или JSON API resources; добавлять их тесты нужно вместе с соответствующей функциональностью.

## Команды

```bash
php artisan test --filter=SpecificTestName
php artisan test
./vendor/bin/phpunit
./vendor/bin/pint path/to/ChangedFile.php --format agent
composer test
```

`npm run build` нужен только при изменениях Vite, JS/CSS, Blade-разметки с asset assumptions или frontend assets. `vendor/bin/pest`, `npm run lint`, PHPStan и Rector сейчас не установлены.
