# Laravel Debugbar по `APP_DEBUG`: дизайн

Обновлено: 19.07.2026

## Цель

Добавить официальный Laravel Debugbar как инструмент разработки и сделать `APP_DEBUG` единственным проектным переключателем его отображения: при `APP_DEBUG=true` панель доступна только в доверенной non-production/non-testing среде, при `APP_DEBUG=false` она не регистрирует HTTP routes/listeners и не внедряется в HTML. Production остаётся fail-closed даже при ошибочно включённом `APP_DEBUG`.

## Рассмотренные варианты

1. **`fruitcake/laravel-debugbar` как `require-dev` плюс минимальный project config — выбран.** Актуальный пакет поддерживает PHP `^8.2`, Laravel `11|12|13` и Livewire 4. `config/debugbar.php` явно связывает `enabled` с `APP_DEBUG` и запрещает `force_allow_enable`; встроенная package-boundary дополнительно исключает `production` и `testing`.
2. **Только package defaults без project config.** Меньше tracked-кода, но `DEBUGBAR_ENABLED` остаётся отдельным override и ослабляет требование об одном переключателе.
3. **Собственный provider/middleware.** Даёт полный контроль, но дублирует package lifecycle, увеличивает риск ранней регистрации collectors/routes и не даёт ценности для текущей задачи.

## Архитектура и конфигурация

- Composer dependency: `fruitcake/laravel-debugbar:^4.4` только в `require-dev`; прежнее имя `barryvdh/laravel-debugbar` не добавляется.
- Auto-discovery регистрирует `Fruitcake\LaravelDebugbar\ServiceProvider`; ручной provider/facade не нужен.
- `config/debugbar.php` содержит только project-owned overrides:
  - `enabled => (bool) env('APP_DEBUG', false)`;
  - `force_allow_enable => false`.
- Package defaults для collectors, storage, routes и assets продолжают поступать через `mergeConfigFrom`; полный vendor config не копируется, чтобы не создавать stale configuration fork.
- `.env` не читается и не изменяется. `.env.example` уже содержит безопасный `APP_DEBUG=false`; `DEBUGBAR_ENABLED` и `DEBUGBAR_FORCE_ALLOW_ENABLE` не добавляются.

## Runtime flow и безопасность

`APP_DEBUG` загружается Laravel в `config('app.debug')` и тем же environment value задаёт `config('debugbar.enabled')`. Package `LaravelDebugbar::canBeEnabled()` разрешает boot только при debug mode и environment вне `testing|production`; затем `isEnabled()` включает collectors и HTML injection.

Production deployment устанавливает Composer lock через `--no-dev`, поэтому package отсутствует в runtime artifact. Даже если dev packages случайно присутствуют, `APP_DEBUG=false` и запрет `force_allow_enable` не позволяют зарегистрировать Debugbar routes/listeners. Debugbar не добавляет migrations, persistent domain data, queue/scheduler work, public API или frontend build entrypoint.

Debugbar может раскрывать SQL bindings, request/session/context и замедлять ответы, поэтому production override, публичный open storage и runtime enable middleware намеренно не проектируются.

## Ошибки, rollout и rollback

- Ошибка Composer resolution блокирует изменение до lock update; unrelated dependency upgrades не принимаются.
- Config cache пересобирается обычным deployment workflow; store-wide cache flush не нужен.
- Rollout: reviewed `composer.lock`, development install, focused tests, isolated config/route checks, production `--no-dev` dry-run и обычная project verification.
- Rollback: удалить `fruitcake/laravel-debugbar` из `require-dev`/lock и `config/debugbar.php`, затем пересобрать autoload/config cache. Database, assets, sessions, queues и user data не затрагиваются.

## Проверка

- TDD regression проверяет связь `debugbar.enabled === app.debug`, `force_allow_enable=false`, разрешение local debug и запрет local non-debug/production/testing.
- Изолированный boot подтверждает Debugbar routes при `APP_ENV=local APP_DEBUG=true` и их отсутствие при `APP_DEBUG=false` и `APP_ENV=production`.
- Development HTTP smoke подтверждает injection в HTML при local debug и отсутствие injection при local non-debug без изменения `.env`.
- Выполняются focused/full PHPUnit, Pint при PHP-правках, Composer validate/audit/platform checks, docs link check и repository-wide scan прежних package names/override/config/provider/routes.

## Cross-feature impact

Authentication, authorization, translations, search, SEO, sitemap, notifications, administration, imports, premium, payments, advertisements, regional/legal access, mobile API, service worker и persistent data не меняются. Общая HTML response pipeline затрагивается только в доверенной local debug-среде; production/public contracts, cache identities и Vite assets сохраняются.
