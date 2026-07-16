# Максимальная контролируемая интеграция Rector

Дата: 2026-07-16

## Цель

Подключить Rector как обязательный инструмент автоматического анализа PHP-кода Seasonvar и одновременно дать разработчику максимально широкий профиль для поиска возможных улучшений. Интеграция должна усиливать текущие Pint, Larastan, PHPUnit и единый CI-сценарий, а не заменять их и не превращать одну задачу в неревьюируемую массовую перезапись приложения.

Слово «максимально» означает:

- анализ всего project-owned PHP-кода, включая приложение, bootstrap/config, migrations/factories/seeders, routes, tests и корневые PHP-файлы;
- PHP upgrade rules в пределах минимальной версии из `composer.json`;
- version-aware правила Laravel 13 и PHPUnit из установленных Composer packages;
- обязательный безопасный профиль в локальных командах и CI;
- отдельный максимально широкий dry-run профиль со всеми совместимыми built-in quality/type/dead-code/style/naming/privatization presets;
- отсутствие baseline, скрывающего замечания, и отсутствие автоматического изменения файлов внутри CI.

Пока `composer.json` разрешает PHP `^8.3`, Rector не должен генерировать синтаксис только для PHP 8.4/8.5, даже если рабочий сервер использует PHP 8.5. Повышение language floor является отдельным изменением production-контракта.

## Рассмотренные подходы

### 1. Один агрессивный профиль со всеми presets в CI

Такой профиль максимален по числу правил, но первое применение способно затронуть большую часть PHP-файлов. Изменения типов, visibility, naming и dead-code требуют разного уровня проверки, создают слишком большой diff и затрудняют поиск поведенческой регрессии. Подход не выбран.

### 2. Только базовый `rector/rector --dry-run`

Простая установка без Laravel rules, Composer scripts, CI и contract tests почти не меняет рабочий процесс и легко перестаёт запускаться. Подход недостаточен для запроса.

### 3. Обязательный профиль плюс максимальный аудит

Это выбранный подход. `rector.php` становится строгим и всегда зелёным quality gate. `rector-max.php` показывает полный потенциальный объём модернизации, но остаётся dry-run инструментом: его вывод разбирается по правилам и применяется небольшими проверяемыми партиями. Так проект получает максимальную видимость Rector без скрытой массовой смены поведения.

## Зависимости

Добавляются только development dependencies:

- `rector/rector` актуальной совместимой стабильной серии 2.x;
- `driftingly/rector-laravel` актуальной совместимой стабильной серии 2.x.

Production `require`, runtime service providers, HTTP lifecycle и deployment artifact не получают новых runtime-зависимостей. Точные версии фиксирует `composer.lock`; после установки выполняются `composer validate --strict` и `composer audit`.

## Обязательный профиль

Корневой `rector.php`:

- обрабатывает `app`, `bootstrap`, `config`, `database`, `routes`, `tests` и project-owned root PHP files;
- не обрабатывает `vendor`, `storage`, `bootstrap/cache`, `output`, Blade templates, generated cache и внешние fixtures;
- получает PHP target из `composer.json` и включает совместимые PHP upgrade sets;
- регистрирует Laravel set provider и выбирает Laravel rules по установленной версии framework;
- включает Composer-based PHPUnit rules;
- включает только правила, чей полный diff применён, просмотрен и прошёл Pint, Larastan и PHPUnit;
- использует bounded parallelism и файловый cache внутри ignored `output/rector`;
- не содержит blanket path exclusions или baseline; точечный skip допустим только с объяснением и contract test на актуальность.

Начальный обязательный набор формируется по dry-run: сначала PHP/Laravel/PHPUnit upgrade sets и самые безопасные уровни dead-code/code-quality/coding-style/type coverage. Если правило создаёт сомнительный framework magic rewrite или меняет публичный контракт, оно остаётся только в максимальном профиле до отдельной проверки.

## Максимальный профиль

`rector-max.php` использует тот же path и version scope, но включает все доступные в установленной версии совместимые подготовленные наборы:

- dead code;
- code quality;
- coding style;
- naming;
- privatization;
- type declarations и type-coverage docblocks;
- Rector preset и другие стабильные non-experimental built-in sets, если они применимы к текущей версии;
- Laravel 13 и PHPUnit Composer-based rules;
- усиленный вывод типов с трактовкой классов как final только для анализа, без требования массово объявлять классы final.

Этот профиль никогда не запускается в режиме записи из CI, Git hook или `composer ci:check`. Стандартная команда выполняет `--dry-run`; запись возможна только явной локальной командой для выбранных путей/правил с последующим review. Экспериментальные правила не включаются автоматически только ради количества.

## Команды

Composer предоставляет единый интерфейс:

```bash
composer rector:check
composer rector:fix
composer rector:max
```

- `rector:check` запускает обязательный профиль с `--dry-run`, nonzero exit при предлагаемом изменении или ошибке;
- `rector:fix` применяет только обязательный профиль и затем требует Pint;
- `rector:max` выполняет полный dry-run без записи.

Прямой `vendor/bin/rector process` остаётся доступен для scoped диагностики, но документация использует Composer scripts как стабильный командный контракт.

## CI и кеш

`scripts/ci-check.sh` остаётся единственным владельцем порядка проверок. Backend profile запускает `composer rector:check` после проверки Composer/Pint и до Larastan/PHPUnit. GitHub Actions не дублирует команду, а только устанавливает dependencies и при возможности восстанавливает безопасный Rector file cache по ключу, зависящему от OS, PHP, `composer.lock` и Rector config.

CI никогда не вызывает `rector:fix` и не коммитит сгенерированный diff. Cache хранит только производные AST/metadata, находится в ignored output и может быть удалён без влияния на приложение.

## Обработка замечаний

Первый обязательный dry-run классифицируется так:

1. безопасные механические изменения применяются;
2. результат форматируется Pint;
3. запускаются Larastan и релевантные PHPUnit tests;
4. framework-magic, public API, Eloquent relation, migration и test-double изменения просматриваются вручную;
5. сомнительное правило либо получает узкий документированный skip, либо остаётся только в `rector-max.php`;
6. обязательный профиль доводится до нулевого diff.

Максимальный отчёт не обязан быть зелёным по changed files: его назначение — показать следующие партии улучшений. Он обязан завершаться без internal errors, invalid configuration и необработанных файлов.

## Тестирование

Отдельный PHPUnit contract test проверяет:

- наличие обоих config files и development dependencies;
- Composer scripts и запрет write-mode в `rector:check`/`rector:max`;
- единственный вызов обязательной проверки из backend CI profile;
- полный набор project paths и исключение generated/runtime directories;
- Laravel set provider, PHP и PHPUnit integration;
- отсутствие Rector write-mode в GitHub workflow и hooks.

Acceptance sequence:

```bash
composer validate --strict
composer audit
composer rector:check
composer rector:max
./vendor/bin/pint --test --format=agent
composer analyse
php artisan test --filter=Rector
php artisan test
php artisan project:docs-refresh --check
```

Если полный PHPUnit одновременно ломается в параллельно изменяемом несвязанном участке, focused Rector contracts, обязательный dry-run, Pint и Larastan всё равно должны быть зелёными, а внешний blocker фиксируется отдельно без ослабления Rector rules.

## Документация

Изменение обновляет:

- `README.md` — команды и состояние quality gate;
- `docs/development.md` — локальный workflow и порядок применения;
- `docs/ci.md` — обязательный dry-run и cache boundary;
- `CHANGELOG.md` — зависимости, профили и фактический результат первого прохода.

Управляемые `project-docs` блоки вручную не редактируются.

## Критерии готовности

- обе dev dependencies зафиксированы без production dependency changes;
- обязательный профиль анализирует весь project-owned PHP scope и даёт нулевой diff;
- максимальный профиль запускает полный стабильный набор и завершается без internal errors;
- CI и Composer используют один обязательный dry-run;
- application changes первого прохода проходят Pint, Larastan и PHPUnit;
- README и тематические документы описывают точные команды;
- никакой baseline, скрытый write-mode или широкое необъяснённое исключение не добавлены.
