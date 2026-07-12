# Тестирование

Обновлено: 12.07.2026

## Стек

- Тесты пишутся как PHPUnit-классы в `tests/Feature` и `tests/Unit`; Pest в проекте не установлен.
- PHPUnit использует SQLite в памяти через `phpunit.xml`.
- Для тестов, которые пишут в базу, использовать `RefreshDatabase`.
- Базовый `Tests\TestCase` вызывает `withoutVite()`, поэтому feature-тесты не зависят от собранного Vite manifest.
- Для данных каталога использовать существующие фабрики: `CatalogTitle`, `Season`, `Episode`, `LicensedMedia`, `Source`, `SourcePage`, `User`.
- Production-данные не сидируются; seeders не являются частью обычного тестового сценария.

## Паттерны

- Feature-тесты должны покрывать маршруты, Form Request-валидацию, авторизацию, sitemap/robots, importer/media behavior и database side effects.
- Read-only страницы проверять через HTTP helpers: `get()`, `getJson()`, `assertOk()`, `assertRedirect()`, `assertInvalid()` и `assertSessionHasErrors()`.
- Для importer, crawler, playlist и media-check тестов блокировать реальные сетевые вызовы через `Http::preventStrayRequests()` и задавать `Http::fake()` для ожидаемых URL.
- Если тестируемая логика окажется внутри Blade, сначала перенести ее в request, service, view-model, component class, accessor или enum, а затем тестировать PHP-код.
- JSON API покрывается feature-тестами через `getJson()` и fluent JSON assertions; тесты ресурсов должны проверять отсутствие приватных source/media/importer полей.
- Operational notifications тестируются через `Notification::fake()` и direct content tests на `toMail()`; реальные письма в тестах не отправляются.
- `CatalogSearchPageTest` фиксирует hard-year, short-token, AND-person, unpublished, true-zero и insufficient состояния поиска.
- `CatalogVisualSystemTest` фиксирует shell, порядок страниц, один title tab-stop, non-cropping poster и русскую светлую pagination.
- `CatalogPageTest` фиксирует reverse-pivot facet aggregation, единый запрет public binding неопубликованных тайтлов, отсутствие HTTP-запроса для их poster proxy и поддержку image-ответов без `Content-Length`.

## Команды

```bash
php artisan test --filter=SpecificTestName
php artisan test
./vendor/bin/phpunit
./vendor/bin/pint path/to/ChangedFile.php --format agent
composer test
php artisan project:docs-refresh --check
```

`npm run build` нужен только при изменениях Vite, JS/CSS, Blade-разметки с asset assumptions или frontend assets. `vendor/bin/pest`, `npm run lint`, PHPStan и Rector сейчас не установлены.
