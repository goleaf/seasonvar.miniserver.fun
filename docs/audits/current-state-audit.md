# Аудит текущего состояния

Проверено: 13.07.2026. Корень приложения: `/www/wwwroot/seasonvar.miniserver.fun`; признаки Laravel root (`artisan`, `composer.json`, `app`, `bootstrap`, `config`, `routes`) находятся в одном приложении.

## Стек и эксплуатационная конфигурация

| Область | Подтверждённое состояние | Доказательство |
| --- | --- | --- |
| Runtime | PHP 8.5.8, Laravel 13.19.0 | `php -v`, `php artisan --version`, `composer show laravel/framework` |
| Интерактивный UI | Livewire 4.3.3, class-based components; Volt отсутствует | `composer show livewire/livewire`, `app/Livewire`, отсутствие `livewire/volt` в lock-файле |
| Frontend | Tailwind CSS 4.3.2, Vite 8.1.4, Node 26.4.0 | `npm ls --depth=0`, `node --version`, `vite.config.js`, `resources/css/app.css` |
| Flux | Не установлен; license/config отсутствуют | `composer show --direct`, `package.json`, поиск `flux` |
| Tests | PHPUnit 12.5.31; Pest отсутствует | `./vendor/bin/phpunit --version`, `composer.json`, `tests/TestCase.php` |
| Database | SQLite; тесты используют SQLite in-memory | `config/database.php`, `phpunit.xml`, `php artisan about` |
| Cache | default `redis-domain`; отдельные Redis connections для locks/limiter, Memcached tier для disposable snapshots | `config/cache.php`, `config/database.php`, `docs/caching.md` |
| Session | Redis connection `sessions`; non-Redis test drivers больше не получают Redis connection | `config/session.php`, `CacheArchitectureTest` |
| Queue | Redis; отдельные `seasonvar-import`, `seasonvar-title-refresh`, `cache-warm` | `config/queue.php`, `docs/queues.md`, deploy systemd units |
| Storage | local/private/public disks; public symlink на проверенном runtime отсутствует | `config/filesystems.php`, `php artisan about` |
| Player | локальные Plyr, HLS.js и FontAwesome assets | `package.json`, `resources/js/player.js`, Vite manifest/build |
| Media delivery | приложение хранит только внешние HTTPS URL; короткоживущий viewer-bound signed route повторно авторизует и возвращает redirect, байты через PHP не проксируются | `CatalogPlaybackSourceResolver`, `PlaybackSourceController`, `PlaybackSourceUrlGuard`, playback feature tests |
| Search | SQLite FTS5 с bounded fallback и нормализованными Livewire filters | search services, FTS migrations, `docs/catalog-search.md`, query-budget tests |

## Домен и границы

Реально существуют `CatalogTitle`, regular/special `Season` и `Episode`, `LicensedMedia`, переводы, субтитры, актёры, режиссёры, жанры, страны, рейтинги, постеры, связи, рекомендации, отзывы, watchlist, пользовательская оценка, progress/history, importer runs/pages/events и административное редактирование. Явные unique/check/FK constraints защищают provider identity, порядок сезонов/серий, pivots, media keys и user-state upserts.

Не существуют отдельные сущности rights/territories, subscription/plan, profiles/PIN, normalized audio-language preferences, DRM, upload/transcode pipeline и локальное хранение видео. Код не симулирует эти продуктовые возможности. Возраст, publication status, audience и availability windows применяются общей server-side entitlement boundary.

## Качество реализации

- Контроллеры делегируют query/build/write логику сервисам; Blade не выполняет запросы, не содержит `@php`, Volt или raw business logic.
- Восемь class-based Livewire компонентов и один Form object используют typed/locked state, повторную авторизацию ID, pagination/loading/key patterns; внешний media URL не сериализуется в public state.
- Публичный JSON API read-only и использует Resources. Admin/import endpoints защищены gate/policy; UI visibility не используется как единственная авторизация.
- Import/crawler HTTP использует URL allowlists, public-DNS guards, timeouts/retries, bounded bodies и `Http::fake()`/`preventStrayRequests()` в тестах.
- Listing/player/sitemap/cache тесты фиксируют query и payload budgets. Shared cache не хранит user state, signed URLs или Eloquent graphs.

## Исходный тестовый статус и найденные ошибки

Первый полный запуск дал 560 tests: 546 passed, 3 failed, 11 skipped, 3806 assertions. Два теста жёстко ожидали development filename `livewire.js`, хотя production выдаёт официальный `livewire.min.js`; cache contract ожидал `session.connection=null` при array driver, а config сохранял `sessions`. Исправления покрыты 24 focused tests / 214 assertions. Других application-boot ошибок baseline не показал.

## Риски

- **Security:** до аудита отсутствовал CSP. Добавлен `Content-Security-Policy-Report-Only` только для HTML; переход к enforcement требует наблюдения реального отчёта и сужения `https:` до подтверждённых origins.
- **Operations:** в начале аудита live SQLite показывал pending cleanup/availability migrations; deployment workflow применил их и последующие search/import migrations. После этого параллельная relation-identity задача добавила `2026_07_13_171455_create_catalog_relation_source_identities_table`, которая на финальном read-only status остаётся Pending. Quick/FK checks зелёные; migration rehearsal выполнялся на временной SQLite. Наличие production backup напрямую не проверялось.
- **Availability:** финальный health-check показывает DB/Redis/Memcached и queue workers `ok`; cache-warm state остаётся `unknown`. Это операционный сигнал, не ошибка приложения.
- **Performance:** SQLite остаётся single-writer boundary; рост import concurrency должен измеряться, а не увеличиваться по числу source pages.
- **Dependencies:** `composer outdated --direct` показал только PHPUnit 13 major; `npm outdated` — major `concurrently` и patch `laravel-vite-plugin`. Обновления без необходимости не выполнялись. Composer/npm audits не нашли advisories.
- **Documentation:** не было датированных environment/security/performance/MCP audit records; они добавлены в `docs/audits`, `docs/research` и `docs/tooling` без дублирования topic-owner contracts.

Детальные решения и статусы находятся в `docs/plans/laravel-video-portal-modernization.md`.
