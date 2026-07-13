# Обновление Laravel 13

Обновлено: 13.07.2026

## Состояние Laravel 13

Приложение уже обновлено до Laravel 13 и проверено по официальному руководству обновления Laravel через документацию Laravel Boost.

Проверенные локальные версии:

- PHP 8.5.8
- Laravel Framework 13.19.0
- Livewire 4.3.3
- Laravel Boost 2.4.12
- Laravel MCP 0.8.2
- Laravel Pint 1.29.3
- PHPUnit 12.5.31
- Tailwind CSS 4.3.2

13.07.2026 повторно проверены официальные Laravel 13 cache/session/queue/deployment и Livewire 4 Forms/Locked/URL/Computed/Lazy/Defer/Isolate/Islands/Async/Renderless/navigation материалы. `composer outdated --direct` не показал совместимых patch/minor обновлений Laravel или Livewire; доступный PHPUnit 13 является unrelated major и намеренно не устанавливался. Composer/frontend lockfiles поэтому не менялись.

## Требования зависимостей

Руководство Laravel 13 требует эти ограничения пакетов, и они уже указаны в проекте:

- `laravel/framework`: `^13.8`
- `laravel/boost`: `^2.4`
- `laravel/tinker`: `^3.0`
- `phpunit/phpunit`: `^12.5`

Pest в проекте не установлен, поэтому требование Laravel 13 для Pest не применяется. PHPUnit 13 уже доступен выше по цепочке, но проект остается на PHPUnit 12, потому что это целевая версия из руководства обновления Laravel 13 и текущий набор тестов настроен под PHPUnit 12.

## Проверка совместимости

Проверены зоны изменений Laravel 13, которые относятся к этому приложению:

- Нет прямых ссылок приложения на устаревшие alias middleware `VerifyCsrfToken` или `ValidateCsrfToken`.
- В конфигурации cache уже есть `serializable_classes => false`.
- Префиксы cache, Redis и session явно заданы в конфигурационных файлах приложения.
- Не найдены собственные реализации queue driver, cache store, dispatcher, response factory, notification или `MustVerifyEmail`.
- Не найдены прямые ссылки на старые имена Bootstrap pagination views.
- Domain routes не зарегистрированы, поэтому приоритет domain routes в Laravel 13 не влияет на текущие маршруты.
- Не найдены собственные методы `boot()` моделей, которые создают модели во время boot модели.

## MCP

Для обслуживания Laravel в этом проекте нужен Laravel Boost. Конфигурация проекта:

```toml
[mcp_servers.laravel-boost]
command = "php"
args = ["artisan", "boost:mcp", "--env=local"]
```

Флаг `--env=local` нужен, потому что Laravel Boost регистрирует свою MCP-команду только в local/debug окружении.

## Команды

Для будущего обслуживания Laravel 13 использовать:

```bash
composer update laravel/framework laravel/boost laravel/tinker laravel/pint phpunit/phpunit --with-all-dependencies
composer validate --strict
composer audit
./vendor/bin/pint --dirty --format agent
php artisan test
npm run build
```

Точечное обновление Composer от 09.07.2026 изменило только `league/mime-type-detection` с `1.16.0` до `1.17.0`.

## Результаты проверки

Проверка Laravel 13 завершена так:

- `composer validate --strict` прошел.
- `composer audit` не нашел advisories безопасности.
- `./vendor/bin/pint --dirty --format agent` прошел.
- `php artisan test` прошел: 37 тестов, 151 assertion.
- `npm run build` прошел. Vite по-прежнему показывает существующее предупреждение о большом JavaScript chunk.

В проекте не установлены:

- Pest
- PHPStan
- Rector
- `npm run lint`
