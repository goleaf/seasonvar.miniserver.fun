# CI

Обновлено: 13.07.2026

## Workflow

GitHub Actions workflow находится в `.github/workflows/ci.yml` и запускается для `push`, `pull_request` в `main`, а также вручную через `workflow_dispatch`.

## Backend

Backend job использует PHP 8.5 и выполняет:

```bash
composer install --no-interaction --prefer-dist --no-progress
composer validate --strict
composer audit
./vendor/bin/pint --test --format=github
find app bootstrap config database routes tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
composer analyse
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan test
```

Тесты используют SQLite в памяти через `phpunit.xml`. Backend job поднимает один Redis 7 и один Memcached 1.6 service, устанавливает PhpRedis/Memcached extensions и задаёт run-specific prefixes/Redis DBs. Обычные тесты остаются на array cache; `RUN_CACHE_INFRASTRUCTURE_TESTS=true` включает exact-key integration tests реальных Redis cache/tags/locks/workload isolation, Memcached read/write и контролируемых outage fallbacks. Shared store никогда не flush-ится.

## Frontend

Frontend job использует Node 26 и выполняет:

```bash
npm ci
npm audit --audit-level=high
npm run build
```

`NPM_CONFIG_REGISTRY` явно задан как `https://registry.npmjs.org/`, чтобы security audit работал через официальный npm registry.

## Browser

Browser job после backend/frontend gates устанавливает managed Chromium, создаёт отдельную временную SQLite-базу `output/playwright/browser.sqlite` и запускает `npm run test:browser`. Playwright проверяет mobile `390×844` и desktop `1440×1200`: URL state каталога, открытие/возврат focus mobile-фильтров, title/player shell, отсутствие horizontal overflow и failed local assets. Внешние media requests блокируются. Axe допускает запуск только при отсутствии critical/serious WCAG 2 A/AA violations. Trace, screenshot, video и HTML-report сохраняются в ignored `output/playwright/` и загружаются как CI artifact только для диагностики.

## Caching

- Composer кеширует только download-cache Composer, ключ зависит от `composer.lock`.
- npm кешируется через `actions/setup-node`, ключ зависит от `package-lock.json`.
- `vendor` и `node_modules` не кешируются и не коммитятся.
- Redis/Memcached CI services являются application test infrastructure, а не GitHub dependency download cache. Их health checks должны пройти до PHPUnit.

## Static Analysis

`composer analyse` запускает Larastan/PHPStan level 6 без baseline и `ignoreErrors`. Начальная область намеренно ограничена `app/DTOs`, `app/Enums`, operational diagnostics, `AdminAuditRecorder`, `CheckDeploymentReadiness` и `AdminAuditEvent`: это low-noise gate для security/operations boundaries, а не заявление о полном анализе всего legacy application. CI также сохраняет отдельные проверки синтаксиса через `php -l` и форматирования через Pint.
