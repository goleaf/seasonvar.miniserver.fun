# CI

Обновлено: 20.07.2026

## Workflow

GitHub Actions workflow находится в `.github/workflows/ci.yml` и запускается для `push`, `pull_request` в `main`, а также вручную через `workflow_dispatch`.

Все три задания используют явный GA-образ `ubuntu-24.04`, чтобы постепенная миграция `ubuntu-latest` не меняла OS между запусками. Существующие major-версии `actions/checkout v6`, `actions/cache v5`, `actions/setup-node v6`, `actions/upload-artifact v7` и `shivammathur/setup-php v2` не обновлены: workflow закрепляет проверенные release commits полными SHA, а комментарий рядом с SHA сохраняет читаемую major-версию. Checkout выполняется с `persist-credentials: false`, потому что CI имеет только `contents: read` и не отправляет Git-изменения.

Полный SHA защищает от перемещения tag, но не превращает GitHub, npm или Composer registry в безотказные сервисы. Внешний outage и новая реальная ошибка должны честно завершать job ненулевым кодом; `continue-on-error`, отключение audits/tests и фиктивный success не используются.

## Политика репозитория GitHub

Проверенная 20.07.2026 repository-level политика дополняет workflow:

- GitHub Actions разрешены в режиме `selected`; remote setting `sha_pinning_required=true` отклоняет action без полного commit SHA. Разрешены GitHub-owned actions и единственный внешний ref `shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240`; произвольные и просто verified actions не разрешены.
- Default `GITHUB_TOKEN` имеет read-only permissions и не может одобрять pull request. Сам workflow дополнительно объявляет только `contents: read`, а checkout не сохраняет credentials.
- Активный repository ruleset `Protect main history` (`19185964`) для `refs/heads/main` запрещает удаление ветки и non-fast-forward update. Обычный fast-forward push в единственную рабочую ветку `main` сохранён; обязательный pull request и server-side required status checks не заявляются, потому что они противоречат каноническому direct-to-`main` workflow проекта.
- GitHub secret scanning и push protection уже включены. Passive Dependabot vulnerability alerts включены отдельно; read-back на дату проверки показал `0` открытых alerts. Автоматические Dependabot security updates намеренно не включены: они создают отдельные pull-request branches, запрещённые текущим Git workflow.
- Ручной run [№223](https://github.com/goleaf/seasonvar.miniserver.fun/actions/runs/29712616978) после применения этой политики завершил `Backend`, `Frontend` и `Browser` со статусом `success` на exact SHA `c19504e3183f011ebb14aaf15cf24b330c95bd92`.

Эти настройки уменьшают configuration drift, supply-chain и history-rewrite risks, но не обещают отсутствие всех будущих ошибок. Реальная регрессия, новый advisory или недоступность GitHub/registry должны оставаться видимым отказом. Direct-to-`main` правило также означает, что последний server-side run происходит после push; локальный `pre-push` остаётся обязательным предварительным gate.

## Единый исполняемый сценарий

`scripts/ci-check.sh` является единственным владельцем порядка и аргументов проверок. Доступны профили `docs`, `backend`, `frontend`, `browser`, `pre-push` и `full`; `composer ci:check` запускает `full`. Профиль `docs` до Laravel boot задаёт SQLite `:memory:` и только читает состояние через `project:docs-refresh --check`. Публичная база управляемых ссылок фиксируется через `PROJECT_DOCS_PUBLIC_BASE_URL=https://seasonvar.miniserver.fun` независимо от временного `APP_URL`, поэтому локальный сервер браузерных тестов и GitHub не переписывают sitemap-ссылки на `localhost`. GitHub Actions сохраняет отдельные jobs и отвечает за установку toolchain и dependencies, после чего вызывает соответствующий профиль.

Все профили также задают process-scoped maintenance driver `cache` с временным store `array`. Поэтому локальный файл `storage/framework/down`, принадлежащий реальному обслуживанию или параллельному deployment-процессу, не превращает тестовые запросы в ложные `503`; сам production marker не читается, не изменяется и не снимается. Это окружение ограничено дочерним процессом quality gate и не является способом вывода сайта из режима обслуживания.

`pre-commit` запускает тот же профиль `docs` после проверок чистоты дерева и до политик README/CHANGELOG. Поэтому stale managed blocks блокируются до создания commit. Исправление выполняется явно командой `php artisan project:docs-refresh`, после review изменённых managed документов проверка повторяется; hook ничего не исправляет и не добавляет в индекс автоматически.

Laravel config, routes, events, packages, services и compiled views проверяются только через process-scoped ignored `output/ci/<run-id>` с отдельными `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE`, `APP_EVENTS_CACHE`, `APP_PACKAGES_CACHE`, `APP_SERVICES_CACHE` и `VIEW_COMPILED_PATH`. `pint.json` исключает весь generated-каталог `output` из форматирования. Очистка выполняется перед началом проверок, повторно перед Pint и через exit-safe trap после backend, browser и cache-validation, поэтому generated artifacts удаляются даже после промежуточной ошибки. Параллельные локальные gate-процессы по умолчанию не разделяют Laravel manifests; воспроизводимый идентификатор можно передать через `SEASONVAR_CI_RUN_ID`, а полный путь — через `SEASONVAR_CI_OUTPUT_ROOT`. Store-wide `cache:clear` не выполняется.

PHP syntax lint проверяет исходники в `app`, `bootstrap`, `config`, `database`, `routes` и `tests`, но явно исключает только generated-каталог `bootstrap/cache`. Его manifests создаются Composer/Laravel и могут атомарно заменяться другим process-scoped cache check; они не являются исходным кодом, а их содержимое повторно проверяется фактическими командами `config:cache`, `route:cache` и `view:cache`.

## Backend

Backend job использует PHP 8.5, устанавливает Composer dependencies и вызывает `bash scripts/ci-check.sh backend`. Профиль последовательно выполняет strict Composer validation, dependency audit, Pint в check-only режиме, обязательный zero-diff `composer rector:check`, PHP syntax lint, bounded Larastan, проверку документации, сборку изолированных Laravel caches и полный PHPUnit suite. Rector запускается только в dry-run после Pint и до syntax/Larastan; workflow не дублирует прямую команду и никогда не применяет изменения. Расширение `gd` устанавливается явно в backend и browser jobs, потому что тесты обработки обложек и подготовка растровых fixtures требуют PNG/WebP и не должны зависеть от случайного состава runner image.

Тесты используют SQLite в памяти через `phpunit.xml`. Backend job поднимает один Redis 7 и один Memcached 1.6 service, устанавливает PhpRedis/Memcached extensions и задаёт run-specific prefixes/Redis DBs. Обычные тесты остаются на array cache; `RUN_CACHE_INFRASTRUCTURE_TESTS=true` включает exact-key integration tests реальных Redis cache/tags/locks/workload isolation, Memcached read/write и контролируемых outage fallbacks. Публичный readiness regression при этом проверяет только безопасные `status`, `ready`, `checked_at`; подробные component metrics остаются CLI-only. Тест синхронизации внешних подборок использует файловую группу временного `Storage::fake`, а не production default `UPLOADS_RUNTIME_GROUP`, сохраняя настоящую проверку private permissions без зависимости от имени группы GitHub runner. Shared store никогда не flush-ится.

## Frontend

Frontend job использует Node 26, выполняет `npm ci` и вызывает `bash scripts/ci-check.sh frontend`. Профиль запускает high-severity npm audit и production Vite build.

`NPM_CONFIG_REGISTRY` явно задан как `https://registry.npmjs.org/`, чтобы security audit работал через официальный npm registry.

## Browser

Browser job после backend/frontend gates вызывает `bash scripts/ci-check.sh browser`: профиль собирает frontend, устанавливает managed Chromium, создаёт process-scoped временную SQLite-базу `output/playwright/<run-id>/browser.sqlite` с database sessions и запускает Playwright suite на process-scoped локальном порту. `APP_URL` всегда собирается из того же порта, а все пять cache-architecture stores принудительно используют process-local `array`, поэтому browser-проверка не получает HTML, assets или подсказки с origin соседнего запуска. Playwright проверяет mobile `390×844`, tablet `768×1024` и desktop `1440×1200`; сценарий шапки дополнительно меняет ширину на 375, 768, 1280 и 1920 пикселей. Матрица покрывает URL state каталога, автодополнение и клавиатуру, двухполосную геометрию шапки, title/player shell, Livewire login/profile/library/logout, verified progress/Continue Watching, отсутствие horizontal overflow и failed local assets. Внешние media requests блокируются. Axe допускает запуск только при отсутствии critical/serious WCAG 2 A/AA violations. Trace, screenshot, video и HTML-report сохраняются в отдельном runtime namespace внутри ignored `output/playwright/` и загружаются как CI artifact только для диагностики; явные `BROWSER_TEST_DATABASE`, `PLAYWRIGHT_RUNTIME_NAME` и `PLAYWRIGHT_PORT` сохраняют приоритет над безопасными defaults.

Сценарии, которым нужен управляемый сетевой delay, сопоставляют оба официальных варианта Livewire update URL: обычный `/livewire/update` и CSP-safe `/livewire-<hash>/update`. Это сохраняет наблюдаемое промежуточное loading-состояние и не превращает проверку `aria-busy` в зависимость от случайной скорости локального ответа. Runtime пагинации дополнительно связывает завершение Livewire message с `component.id`, поэтому параллельная загрузка другого island не снимает spinner ожидающего paginator.

Общий лимит browser-сценария составляет 60 секунд, ожидания отдельного состояния — 15 секунд: это покрывает холодную сборку и однопоточный PHP-сервер, но не заменяет точные assertions. Player-сценарии перед teardown отправляют `pagehide` и снимают route handlers, чтобы завершающийся media range не оставался за границей теста.

## Caching

- Composer кеширует только download-cache Composer, ключ зависит от `composer.lock`.
- Backend job кеширует только производный `output/rector/required`; ключ зависит от OS, PHP 8.5, `composer.lock` и `rector.php`. Maximum-профиль в CI не запускается и его кеш не загружается.
- npm кешируется через `actions/setup-node`, ключ зависит от `package-lock.json`.
- `vendor` и `node_modules` не кешируются и не коммитятся.
- Redis/Memcached CI services являются application test infrastructure, а не GitHub dependency download cache. Их health checks должны пройти до PHPUnit.

## Static Analysis

`composer analyse` запускает Larastan/PHPStan level 6 без baseline и `ignoreErrors`. Начальная область намеренно ограничена `app/DTOs`, `app/Enums`, operational diagnostics, `AdminAuditRecorder`, `CheckDeploymentReadiness`, `AuditFailedSeasonvarJobs` и `AdminAuditEvent`: это low-noise gate для security/operations boundaries, а не заявление о полном анализе всего legacy application. CI также сохраняет отдельные проверки синтаксиса через `php -l` и форматирования через Pint.

Rector 2 анализирует `app`, `bootstrap`, `config`, `database`, `routes`, `tests` и корневые PHP-файлы с версией PHP из `composer.json`, Laravel rules из установленного framework и PHPUnit rules из Composer. `rector.php` обязан завершаться с нулевым diff; первый широкий аудит оставил массовые modernizations явным rule-level списком только для `rector-max.php`, без path baseline. `composer rector:max` включает все выбранные стабильные prepared sets и предназначен для локальной классификации: exit `2` из-за предлагаемых изменений ожидаем, internal/config/parser errors — нет.
