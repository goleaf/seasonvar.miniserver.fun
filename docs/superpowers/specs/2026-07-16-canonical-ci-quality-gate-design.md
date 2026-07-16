# Канонический CI quality gate

## Статус

Дизайн утверждён 16.07.2026. Документ описывает только унификацию локальных и GitHub Actions проверок. Он не меняет runtime-поведение портала, доменную логику или зависимости приложения.

## Контекст

Обязательные проверки проекта перечислены одновременно в GitHub Actions, локальной документации, Composer scripts и Git hooks. Такое дублирование позволяет командам и их параметрам расходиться. Уже подготовленный рабочий черновик правильно вводит единый `scripts/ci-check.sh`, но использует несуществующие major-версии `actions/checkout@v7`, `actions/cache@v6` и `actions/setup-node@v7`.

Нужен один версионируемый исполняемый контракт, который одинаково вызывается CI, разработчиком и `pre-push`, не расширяя текущую область функциональных изменений.

## Цели

- сделать `scripts/ci-check.sh` единственным источником порядка и состава quality checks;
- сохранить отдельные backend, frontend и browser jobs в GitHub Actions;
- предоставить явные профили для локального запуска и hooks;
- использовать существующие официально поддерживаемые major-версии GitHub Actions;
- сохранить текущие PHP, Node, SQLite, Redis, Memcached и Playwright окружения;
- обеспечить одинаковую семантику ошибок локально и в CI;
- документировать быстрые и полные способы проверки;
- не захватывать параллельные изменения грязного общего рабочего дерева.

## Не входит в задачу

- исправление прикладных тестов, доменных сервисов, Livewire-компонентов или Blade;
- обновление Composer/npm packages и изменение `composer.lock` или `package-lock.json`;
- изменение PHP, Laravel, Node, npm, Redis, Memcached или Playwright версий;
- добавление deployment, release, PR или feature-branch workflow;
- изменение production `.env`, секретов или инфраструктуры;
- ослабление уже существующих проверок ради зелёного результата.

## Рассмотренные варианты

### 1. Канонический скрипт с профилями — выбран

Workflow, Composer и hook делегируют исполнение одному Bash-скрипту. Jobs продолжают независимо устанавливать нужное окружение и зависимости, а скрипт определяет только проверки. Это устраняет расхождение команд, сохраняет читаемые CI jobs и позволяет запускать тот же контракт локально.

### 2. Исправить только версии GitHub Actions

Это минимальный patch, но он оставляет команды продублированными и не предотвращает следующий drift. Вариант отклонён как недостаточный.

### 3. Одновременно исправить все падающие тесты и обновить зависимости

Такой вариант смешивает infrastructure boundary с параллельной доменной разработкой, увеличивает риск конфликтов и делает rollback неоднозначным. Вариант отклонён; quality gate должен честно показать внешние сбои, а не поглощать их.

## Архитектура

### Канонический исполняемый контракт

`scripts/ci-check.sh` работает в strict Bash mode, определяет repository root через Git и принимает один allowlisted profile:

- `backend` — Composer validation/audit, Pint, PHP syntax, Larastan, документация, Laravel cache-build checks и PHPUnit;
- `frontend` — npm security audit и production Vite build;
- `browser` — production build, установка managed Chromium, подготовка изолированных fixtures и Playwright suite;
- `pre-push` — `backend`, затем `frontend`; browser suite остаётся полным отдельным gate из-за стоимости;
- `full` — `backend`, `frontend`, затем `browser`.

Без аргумента выбирается `full`. Неизвестный профиль завершается с кодом `2` и коротким сообщением на русском языке со списком допустимых профилей.

Скрипт задаёт только безопасные testing defaults, если соответствующие переменные не переданы окружением. Он не пишет `.env`, не печатает секреты и не переопределяет явно заданные CI values.

### Backend profile

Порядок проверок остаётся fail-fast:

1. `composer validate --strict`;
2. `composer audit`;
3. Pint в check-only режиме;
4. `php -l` для project PHP paths;
5. bounded `composer analyse`;
6. `php artisan project:docs-refresh --check --no-interaction`;
7. сборка config, route и view caches;
8. обязательная очистка созданных cache artifacts даже при ошибке промежуточной cache-команды;
9. полный PHPUnit suite.

Очистка относится только к Laravel-generated cache artifacts и не вызывает `cache:clear`, `db:wipe`, `migrate:fresh` или другие destructive/store-wide операции.

### Frontend profile

Профиль выполняет `npm audit --audit-level=high` через уже настроенный официальный registry, затем `npm run build`. Установка зависимостей остаётся ответственностью вызывающего workflow или разработчика.

### Browser profile

Профиль использует отдельную SQLite database в `output/playwright`, testing session/cache drivers и существующие Playwright scripts. Он не скачивает media-файлы и не выполняет внешние browser requests сверх уже разрешённого test harness.

### GitHub Actions workflow

`.github/workflows/ci.yml` сохраняет три jobs:

- backend устанавливает PHP/Composer dependencies и вызывает `backend`;
- frontend устанавливает Node/npm dependencies и вызывает `frontend`;
- browser зависит от первых двух jobs, устанавливает оба toolchain, вызывает `browser` и публикует diagnostic artifact независимо от результата.

Jobs сохраняют свои timeout, Redis/Memcached services, run-scoped cache prefixes, SQLite configuration и artifact retention. Команды проверок не дублируются в YAML.

Закрепляются существующие официальные major-версии:

- `actions/checkout@v6`;
- `actions/cache@v5`;
- `actions/setup-node@v6`;
- `actions/upload-artifact@v7`.

`shivammathur/setup-php@v2` сохраняется. Новые неподтверждённые majors не используются.

### Локальные точки входа

- `composer ci:check` запускает `bash scripts/ci-check.sh full`;
- `.githooks/pre-push` сначала выполняет существующий Git guard, затем `bash "$repo_root/scripts/ci-check.sh" pre-push`;
- разработчик может вызвать любой профиль напрямую без скрытой альтернативной последовательности команд.

Git guard остаётся владельцем branch, conflict, unsafe-path и clean-tree checks. Quality script не дублирует Git policy.

## Поток выполнения

```text
GitHub job / Composer / pre-push
              |
              v
      scripts/ci-check.sh
              |
       allowlisted profile
              |
       ordered fail-fast checks
              |
     original process exit code
```

Workflow устанавливает toolchain и зависимости, после чего передаёт управление профилю. Скрипт транслирует stdout/stderr каждой команды без буферизации и возвращает её ненулевой код. Это оставляет причину сбоя видимой и одинаковой во всех точках входа.

## Безопасность и обработка ошибок

- используется `set -euo pipefail`;
- произвольные имена команд или профилей не исполняются;
- testing `APP_KEY` существует только в process environment и не записывается в Git;
- audit failures не маскируются;
- browser artifacts не должны включать `.env`, cookies, credentials или raw private data;
- Laravel cache validation гарантирует cleanup через exit-safe boundary;
- script не выполняет store-wide cache flush и не меняет production data;
- workflow не получает новых permissions и не использует third-party actions вне уже существующих владельцев.

## Проверочный контракт

Изменение выполняется через существующий PHPUnit/TDD-подход:

1. сначала контрактный тест фиксирует правильные action majors, отсутствие несуществующих majors, делегирование workflow, Composer и hook;
2. focused test должен падать на текущем черновике;
3. implementation приводит контракт к зелёному состоянию;
4. `bash -n` проверяет syntax script и hooks;
5. `composer validate --strict` подтверждает Composer contract;
6. focused CI contract tests запускаются перед широким gate;
7. `composer ci:check` запускает полный канонический gate после локальных узких проверок.

Существующие `BrowserCiContractTest` и `StaticAnalysisContractTest` обновляются только если их утверждения действительно описывают перенесённый в скрипт контракт. Не связанные с quality gate test changes не включаются.

## Документация

После реализации обновляются только релевантные владельцы документации:

- `docs/ci.md` — jobs, profiles, action majors и точный gate contract;
- `docs/development.md` — локальные точки входа и поведение `pre-push`;
- `docs/testing.md` — focused/full verification и browser boundary;
- `CHANGELOG.md` — одна английская запись о CI унификации;
- `README.md` — только если проверка project documentation policy действительно требует обновления обзора.

Автоматически управляемые блоки не редактируются вручную.

## Ожидаемые файлы реализации

- `.github/workflows/ci.yml`;
- `.githooks/pre-push`;
- `scripts/ci-check.sh`;
- `composer.json` без изменения dependency constraints;
- `tests/Unit/CiQualityGateContractTest.php`;
- при необходимости существующие `tests/Unit/BrowserCiContractTest.php` и `tests/Unit/StaticAnalysisContractTest.php`;
- `docs/ci.md`, `docs/development.md`, `docs/testing.md`, `CHANGELOG.md`;
- `README.md` только по установленной project policy.

`composer.lock`, application code, migrations, routes и frontend application assets должны остаться вне этого commit scope.

## Совместимость и rollback

Изменение не затрагивает database schema, runtime routes, public API или пользовательские данные. Rollback состоит из возврата workflow, hook, Composer script, contract tests и документации к предыдущему состоянию и удаления `scripts/ci-check.sh`. Отдельный data rollback не требуется.

Работа выполняется только на существующей `main`. Из-за общего грязного рабочего дерева каждый commit формируется из явного allowlist через отдельный temporary index; чужие изменения не stash-ятся, не сбрасываются и не включаются.

## Критерии приёмки

- все три CI jobs вызывают один versioned script с отдельными профилями;
- workflow использует `checkout@v6`, `cache@v5`, `setup-node@v6`, `upload-artifact@v7`;
- `composer ci:check` запускает полный gate;
- `pre-push` сохраняет Git guard и запускает backend/frontend gate;
- неизвестный profile безопасно завершается кодом `2`;
- cache-build validation очищает только собственные generated artifacts даже при ошибке;
- backend, frontend и browser проверки не ослаблены;
- dependency lockfiles и runtime application code не изменены этим scope;
- документация описывает один и тот же фактический контракт;
- focused contract tests и полный доступный gate имеют сохранённые результаты выполнения;
- commit создан и отправлен из существующей `main` без чужих файлов.
