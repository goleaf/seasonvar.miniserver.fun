# Тестирование

Обновлено: 14.07.2026

## Стек

- Тесты пишутся как PHPUnit-классы в `tests/Feature` и `tests/Unit`; Pest в проекте не установлен.
- PHPUnit использует SQLite в памяти через `phpunit.xml`, обычный cache — `array`, а `APP_ROUTES_CACHE` указывает на отдельный отсутствующий testing artifact, поэтому production `bootstrap/cache/routes-v7.php` не скрывает новые маршруты. Общих public request/action limiter counters нет; mobile credential endpoints используют отдельные named limiters, которые очищаются между feature-тестами вместе с application state.
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
- Browser regression suite использует отдельный ignored `output/playwright/browser.sqlite`, локальный PHP server и Playwright Chromium на `390×844` и `1440×1200`. Внешние requests блокируются; axe gate отклоняет critical/serious WCAG 2 A/AA violations, а geometry checks фиксируют horizontal overflow и 44-pixel control contract.

## Паттерны

- Feature-тесты должны покрывать маршруты, Form Request-валидацию, авторизацию, sitemap/robots, importer/media behavior и database side effects.
- Read-only страницы проверять через HTTP helpers: `get()`, `getJson()`, `assertOk()`, `assertRedirect()`, `assertInvalid()` и `assertSessionHasErrors()`.
- Для importer, crawler, playlist и media-check тестов блокировать реальные сетевые вызовы через `Http::preventStrayRequests()` и задавать `Http::fake()` для ожидаемых URL.
- Если тестируемая логика окажется внутри Blade, сначала перенести ее в request, service, view-model, component class, accessor или enum, а затем тестировать PHP-код.
- JSON API покрывается feature-тестами через `getJson()` и fluent JSON assertions; тесты ресурсов должны проверять отсутствие приватных source/media/importer полей.
- Foundation API tests отдельно фиксируют Sanctum token hashing/expiry/prune schedule, `/api` discovery, safe config/health/OpenAPI, request ID/error envelope, legacy route compatibility и fail-closed cache semantics для любого `Authorization` header.
- Mobile auth tests фиксируют normalised register/login, strong password, queued verification/reset, non-enumerating recovery, 90-day token rotation, current/all/device revoke, ability boundaries, `/me`, email reverification, password token revocation и account-delete cascades. Privacy matrix проверяет guest, invalid token, unverified/verified и cross-user device ID; OpenAPI regression сравнивает каждый `/api/v1` route и HTTP method с document paths.
- `UserTitleStateTest`, `UserLibraryTest` и `ViewingActivityTest` покрывают desired-state PUT/DELETE, rating 1–10, watchlist/ratings pagination, Continue Watching `continue|next`, стандартную history pagination, inaccessible summaries и owner-scoped delete/clear. Матрица проверяет guest/invalid token, unverified read и verified write, отсутствие shared validators и sensitive fields; query-delta сравнивает 1 и 20 строк.
- `MobilePlaybackGrantTest`, `PlaybackSessionTest`, `PlaybackDeliveryTest` и `PlaybackProgressTest` фиксируют opaque grant round-trip/tampering/expiry, guest и authenticated audience, strict preferences/hierarchy ownership, signed delivery headers, отсутствие raw provider/path/source/storage в JSON и ошибках, deleted-user/revoked-entitlement recheck, verified write boundary, trusted media duration, duplicate/stale/concurrent event sequence, completion/replay и OpenAPI schema. Web playback/progress regressions запускаются рядом, чтобы mobile endpoints не меняли существующий Plyr/Livewire contract.
- Стресс-сценарий импорта 2600 серий запускается PHPUnit в отдельном процессе: отдельный test проходит под 128 MiB, а production importer/worker имеет документированный hard limit 256 MiB. Изоляция не повышает application memory limit и не меняет bulk-upsert contract.
- `tests/Feature/Api/V1` фиксирует полный public catalog contract: indexed/unindexed filter arrays, отдельные Cyrillic/Latin/symbol groups, directory pagination, title hierarchy ownership, audience visibility, safe media profiles, bounded suggestions, ranked recommendations и read-only reviews. Сквозной privacy test запрашивает каждый public v1 GET и проверяет source/media/importer/recommendation markers; query-delta tests сравнивают 1 и 20 элементов с допуском не более двух SQL-запросов.
- Operational notifications тестируются через `Notification::fake()` и direct content tests на `toMail()`; реальные письма в тестах не отправляются.
- `CatalogSearchPageTest` фиксирует hard-year, short-token, title/original/alias matches, исключение description/slug/external ID/relations, unpublished, true-zero и insufficient состояния поиска.
- `CatalogSearchAcceptanceTest` фиксирует top-1/top-3 для основных, оригинальных и альтернативных названий с годом, готовый FTS-поиск вариантов `е/ё` и негативные корпусы для имён людей, категорий, жанров, стран и описаний.
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
npm run test:browser:install
npm run test:browser
```

`npm run build` нужен только при изменениях Vite, JS/CSS, Blade-разметки с asset assumptions или frontend assets. Browser artifacts сохраняются только в ignored `output/playwright/`. `vendor/bin/pest`, `npm run lint`, PHPStan и Rector сейчас не установлены.

Датированный baseline, исправленные regressions, browser smoke и финальные exact counts записываются в `docs/audits/verification-report.md`. Production Livewire может выдавать как `livewire.js`, так и `livewire.min.js`; asset tests обязаны проверять официальный URL/id и singleton, а не жёстко фиксировать build mode.
