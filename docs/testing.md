# Тестирование

Обновлено: 13.07.2026

## Стек

- Тесты пишутся как PHPUnit-классы в `tests/Feature` и `tests/Unit`; Pest в проекте не установлен.
- PHPUnit использует SQLite в памяти через `phpunit.xml`, а обычный cache — `array`; локальных request/action limiter counters в приложении нет.
- `RUN_CACHE_INFRASTRUCTURE_TESTS=true` включает реальные Redis/Memcached tests: domain read/write, tags, critical version bump, distributed lock, queue workload, session isolation, connection isolation, readiness, Memcached outage и Redis-cache/version-registry outage. Они используют случайные exact keys/run prefixes и не выполняют full-store flush.
- Параллельный importer проверяется в `SeasonvarParallelImportTest`, `SeasonvarParallelTitleRefreshPersistenceTest`, `SeasonvarCatalogPagePreparationTest`, `SeasonvarCatalogPreparedApplyTest`, `SeasonvarImportTitleGroupDispatcherTest` и `SeasonvarImportTitleGroupFinalizerTest`: тесты фиксируют durable fan-out/fan-in state, отсутствие application-level page limit, динамическое discovery, network-free apply, local-only preservation, partial groups, lease recovery и ожидание групп глобальным finalizer. HTTP-поведение закрыто `Http::fake()` и `Http::preventStrayRequests()`.
- Фоновое обновление карточки покрывают `CatalogTitleBackgroundRefreshTest`, `RefreshSeasonvarCatalogTitleJobTest` и `CatalogTitleLiveRefreshTest`: atomic dispatch/freshness, пять независимых тайтлов, 15-минутное окно только после `completed`, sanitized failure payload, трёхсекундный visible polling, полный rerender и сохранение валидного player selection. `TitleBackgroundRefreshDocumentationTest` фиксирует отдельные очереди worker pools и отсутствие application-level cap.
- Для тестов, которые пишут в базу, использовать `RefreshDatabase`.
- Базовый `Tests\TestCase` вызывает `withoutVite()`, поэтому feature-тесты не зависят от собранного Vite manifest.
- Для данных каталога использовать существующие фабрики: `CatalogTitle`, `Season`, `Episode`, `LicensedMedia`, `Source`, `SourcePage`, `User`.
- Livewire-каталог проверяется через `Livewire::withQueryParams(...)->test(CatalogSeries::class)`: URL hydration, нормализация, server-side выдача, reset paginator и групповые/полные сбросы должны оставаться покрыты существующими feature-тестами.
- Production-данные не сидируются; seeders не являются частью обычного тестового сценария.
- Cache architecture tests фиксируют canonical key hashing/bounds, TTL+jitter, negative lookup, payload limit, version invalidation, stale fallback, bounded lock timeout, warming uniqueness/overlap/after-commit, private shared-cache bypass и public HTTP validators.
- Blade audit должен отвергать `@php`, `@endphp`, PHP tags, cache/database calls и Volt. Livewire security tests продолжают фиксировать URL state, locked tamper protection, Form Objects, renderless actions, visible-only polling и отсутствие больших public model collections; намеренно неиспользуемые Livewire features не симулируются искусственными компонентами.

## Паттерны

- Feature-тесты должны покрывать маршруты, Form Request-валидацию, авторизацию, sitemap/robots, importer/media behavior и database side effects.
- Read-only страницы проверять через HTTP helpers: `get()`, `getJson()`, `assertOk()`, `assertRedirect()`, `assertInvalid()` и `assertSessionHasErrors()`.
- Для importer, crawler, playlist и media-check тестов блокировать реальные сетевые вызовы через `Http::preventStrayRequests()` и задавать `Http::fake()` для ожидаемых URL.
- Если тестируемая логика окажется внутри Blade, сначала перенести ее в request, service, view-model, component class, accessor или enum, а затем тестировать PHP-код.
- JSON API покрывается feature-тестами через `getJson()` и fluent JSON assertions; тесты ресурсов должны проверять отсутствие приватных source/media/importer полей.
- Operational notifications тестируются через `Notification::fake()` и direct content tests на `toMail()`; реальные письма в тестах не отправляются.
- `CatalogSearchPageTest` фиксирует hard-year, short-token, AND-person, exact external ID, duplicate-free totals, unpublished, true-zero и insufficient состояния поиска.
- `CatalogSearchAcceptanceTest` фиксирует ранжирование полного поискового корпуса: top-1/top-3 для названий, original title, aliases и года, precision@10 для людей, минимум 95% для категорий/жанров/стран и готовый FTS-поиск имён через взаимозаменяемые `е/ё`.
- `CatalogVisualSystemTest` фиксирует shell, 650 ms search debounce, порядок страниц, один title tab-stop, non-cropping poster и русскую светлую pagination.
- `CatalogPageTest` фиксирует Livewire URL hydration, malformed и out-of-range page recovery, сохранение search/filter state при сортировке, reverse-pivot facet aggregation, единый запрет public binding неопубликованных тайтлов, отсутствие HTTP-запроса для их poster proxy и поддержку image-ответов без `Content-Length`.
- `CatalogPageTest` также фиксирует карточку тайтла: один active-season episode set, exact playable counts, guest/authenticated audience boundary, continue/next/replay action, idempotent desired-state список просмотра, configurable user-rating range, multi-user isolation, create/change/remove aggregates без provider-rating примеси и canonical progress. Progress contract покрывает first play, duplicate heartbeat, source-trusted duration, configurable completion, replay без снятия completion, stale/expired/tampered sessions, concurrent user isolation, revoked episode access и missing duration с `ended`.
- `SecurityHardeningTest` фиксирует типизированный `CatalogEntitlementDecision`, одинаковую видимость SQL scope и loaded release, guest/authenticated решения, future/expired/hidden отказы, отсутствие authenticated/admin bypass и повторную проверку direct signed playback URL. Child profile, expired subscription и concurrent stream сценарии не симулируются до появления соответствующих таблиц.
- `CatalogBladeComponentTest` проверяет shareable season/episode/media profile links, active markers, active-season anchor и сохранение playback variant между сериями без загрузки остальных сезонов.
- `CatalogAdvancedFilterTest` сравнивает paginator total с фактическими ID, проверяет OR-внутри/AND-между группами, own-group-excluded счетчики relation/year/publication/subtitle фасетов, bounded top-N после контекста, выбранные нулевые значения, постоянный query budget и свежесть после publish/unpublish, soft delete/restore, pivot/media и episode lifecycle изменений.

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
