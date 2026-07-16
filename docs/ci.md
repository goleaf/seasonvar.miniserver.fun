# CI

Обновлено: 16.07.2026

## Workflow

GitHub Actions workflow находится в `.github/workflows/ci.yml` и запускается для `push`, `pull_request` в `main`, а также вручную через `workflow_dispatch`.

Workflow закрепляет официальные major-версии `actions/checkout@v6`, `actions/cache@v5`, `actions/setup-node@v6` и `actions/upload-artifact@v7`. PHP устанавливается существующим `shivammathur/setup-php@v2`.

## Единый исполняемый сценарий

`scripts/ci-check.sh` является единственным владельцем порядка и аргументов проверок. Доступны профили `backend`, `frontend`, `browser`, `pre-push` и `full`; `composer ci:check` запускает `full`. GitHub Actions сохраняет отдельные jobs и отвечает за установку toolchain и dependencies, после чего вызывает соответствующий профиль.

Laravel config, routes, events, packages, services и compiled views проверяются только через ignored `output/ci` с отдельными `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE`, `APP_EVENTS_CACHE`, `APP_PACKAGES_CACHE`, `APP_SERVICES_CACHE` и `VIEW_COMPILED_PATH`. Exit-safe cleanup удаляет generated artifacts даже после промежуточной ошибки и не выполняет store-wide `cache:clear`.

## Backend

Backend job использует PHP 8.5, устанавливает Composer dependencies и вызывает `bash scripts/ci-check.sh backend`. Профиль последовательно выполняет strict Composer validation, dependency audit, Pint в check-only режиме, PHP syntax lint, bounded Larastan, проверку документации, сборку изолированных Laravel caches и полный PHPUnit suite.

Тесты используют SQLite в памяти через `phpunit.xml`. Backend job поднимает один Redis 7 и один Memcached 1.6 service, устанавливает PhpRedis/Memcached extensions и задаёт run-specific prefixes/Redis DBs. Обычные тесты остаются на array cache; `RUN_CACHE_INFRASTRUCTURE_TESTS=true` включает exact-key integration tests реальных Redis cache/tags/locks/workload isolation, Memcached read/write и контролируемых outage fallbacks. Shared store никогда не flush-ится.

## Frontend

Frontend job использует Node 26, выполняет `npm ci` и вызывает `bash scripts/ci-check.sh frontend`. Профиль запускает high-severity npm audit и production Vite build.

`NPM_CONFIG_REGISTRY` явно задан как `https://registry.npmjs.org/`, чтобы security audit работал через официальный npm registry.

## Browser

Browser job после backend/frontend gates вызывает `bash scripts/ci-check.sh browser`: профиль собирает frontend, устанавливает managed Chromium, создаёт отдельную временную SQLite-базу `output/playwright/browser.sqlite` с database sessions и запускает Playwright suite. Playwright проверяет mobile `390×844`, tablet `768×1024` и desktop `1440×1200`: URL state каталога, открытие/возврат focus mobile-фильтров, title/player shell, Livewire login/profile/library/logout, verified progress/Continue Watching, отсутствие horizontal overflow и failed local assets. Внешние media requests блокируются. Axe допускает запуск только при отсутствии critical/serious WCAG 2 A/AA violations. Trace, screenshot, video и HTML-report сохраняются в ignored `output/playwright/` и загружаются как CI artifact только для диагностики; локальные параллельные запуски могут задать отдельные `PLAYWRIGHT_RUNTIME_NAME` и `PLAYWRIGHT_PORT`.

## Caching

- Composer кеширует только download-cache Composer, ключ зависит от `composer.lock`.
- npm кешируется через `actions/setup-node`, ключ зависит от `package-lock.json`.
- `vendor` и `node_modules` не кешируются и не коммитятся.
- Redis/Memcached CI services являются application test infrastructure, а не GitHub dependency download cache. Их health checks должны пройти до PHPUnit.

## Static Analysis

`composer analyse` запускает Larastan/PHPStan level 6 без baseline и `ignoreErrors`. Начальная область намеренно ограничена `app/DTOs`, `app/Enums`, operational diagnostics, `AdminAuditRecorder`, `CheckDeploymentReadiness`, `AuditFailedSeasonvarJobs` и `AdminAuditEvent`: это low-noise gate для security/operations boundaries, а не заявление о полном анализе всего legacy application. CI также сохраняет отдельные проверки синтаксиса через `php -l` и форматирования через Pint.
