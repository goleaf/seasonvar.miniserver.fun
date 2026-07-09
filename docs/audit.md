# Аудит Laravel 13

Дата аудита: 09.07.2026

## Область проверки

Проверены структура Laravel-приложения, Composer и npm-пакеты, маршруты, контроллеры, модели, миграции, сервисы, Blade-шаблоны, тесты, конфигурация, очереди, события, политики, MCP-настройки и CI-файлы.

Подтвержденное окружение:

- PHP 8.5, Laravel 13.19.0, Laravel Boost 2.4.12, Laravel MCP 0.8.2.
- Основная база данных SQLite; тесты используют SQLite в памяти.
- PHPUnit 12.5; Pest не установлен.
- Tailwind CSS 4.3 с Vite 8, локальными FontAwesome, Plyr и HLS-ресурсами.
- В проекте сейчас нет `routes/api.php`, политик приложения, задач очереди, событий, слушателей, писем, уведомлений, Form Request-классов и API Resources.
- CI-файлы не найдены.

## MCP

Laravel Boost сейчас единственный полезный MCP-сервер для этого проекта. В проекте есть `boost.json` с включенным MCP и `.codex/config.toml` для запуска:

```toml
[mcp_servers.laravel-boost]
command = "php"
args = ["artisan", "boost:mcp"]
```

Файл конфигурации разрешен в `.gitignore`, чтобы проектная настройка Laravel Boost MCP попадала в Git без отслеживания остальных файлов `.codex`.

## Исправлено

- Безопасные расчеты Blade-компонентов перенесены из `resources/views/components/title-card.blade.php`, `resources/views/components/title-list-row.blade.php` и `resources/views/components/ui/taxonomy-chip.blade.php` в классы компонентов `app/View/Components`.
- В не-production окружении включены проверки Eloquent на ленивую загрузку связей и тихо отброшенные атрибуты через `AppServiceProvider`.
- Общие metadata Laravel skeleton в `composer.json` заменены на metadata проекта.
- Добавлен этот audit-документ и разрешено отслеживание существующей настройки Laravel Boost MCP.

## Проверка

- `./vendor/bin/pint --dirty --format agent` прошел.
- `php artisan test --filter=CatalogPageTest` прошел: 5 tests, 17 assertions.
- `php artisan test` прошел: 34 tests, 143 assertions.
- `composer validate --strict` прошел.
- `composer audit` не нашел advisories.
- `npm run build` прошел. Vite вывел существующее предупреждение о крупном frontend chunk.
- `php artisan route:list` прошел. Опция `--compact` недоступна в этой установке Laravel, поэтому использован обычный route list.

## Критично

Критичных проблем с прямым влиянием на production не найдено.

## Высокий приоритет

- [resources/views/layouts/app.blade.php](/www/wwwroot/seasonvar.miniserver.fun/resources/views/layouts/app.blade.php:1) содержит слишком большой `@php`-блок: там собираются SEO-метаданные, URL поиска, JSON-LD, навигационные блоки и производное состояние страницы. Это нужно вынести в отдельный SEO-сервис или view model перед дальнейшим расширением SEO.
- [app/Http/Controllers/CatalogController.php](/www/wwwroot/seasonvar.miniserver.fun/app/Http/Controllers/CatalogController.php:1) остается слишком большим и смешивает нормализацию запроса, query building, SEO/JSON-LD, sitemap-делегирование, рекомендации и состояние страницы. Его нужно постепенно делить на query objects, SEO builders и меньшие контроллеры.
- [resources/views/catalog/titles.blade.php](/www/wwwroot/seasonvar.miniserver.fun/resources/views/catalog/titles.blade.php:3) и [resources/views/catalog/show.blade.php](/www/wwwroot/seasonvar.miniserver.fun/resources/views/catalog/show.blade.php:3) все еще собирают URL фильтров, подписи таксономий, данные плеера, бейджи сезонов и состояние вариантов медиа внутри Blade. Перед новым UI-поведением это лучше перенести в подготовленные DTO, классы компонентов или view models.
- В приложении нет policies или gates. Для текущей публичной read-only поверхности это допустимо, но перед любыми write/admin/moderation/import-control эндпоинтами нужно добавить авторизацию.

## Средний приоритет

- [app/Services/Catalog/CatalogSitemapResponder.php](/www/wwwroot/seasonvar.miniserver.fun/app/Services/Catalog/CatalogSitemapResponder.php:122) может выполнять много `exists()`-проверок при сборке посадочных страниц карты сайта. Перед ростом каталога лучше заменить это grouped counts или заранее собранными парами taxonomy/year.
- [app/Services/Seasonvar/SeasonvarTitleMerger.php](/www/wwwroot/seasonvar.miniserver.fun/app/Services/Seasonvar/SeasonvarTitleMerger.php:82) загружает все тайтлы в память перед группировкой дублей. Для большого каталога лучше перейти на группировку кандидатных дублей в базе или chunk-обработку.
- Поиск в `CatalogController::applySearchFilter()` использует несколько `LIKE` с ведущим `%` и повторные `orWhereHas()` по связям. Для маленькой базы это нормально, но при росте каталога стоит рассмотреть SQLite FTS или отдельную поисковую таблицу.
- Снимки страниц источника хранят raw HTML. Они должны оставаться непубличными; нужна политика retention/cleanup и запрет на вывод таких данных через будущие API или диагностику.
- Валидация запроса сейчас встроена в `CatalogController`. Текущие read-only маршруты нормализуют scalar input, но фильтры каталога будет проще тестировать и переиспользовать через отдельные filter DTO или Form Request-классы, если появятся write/API endpoints.
- JSON-LD выводится через `{!! !!}` в [resources/views/layouts/app.blade.php](/www/wwwroot/seasonvar.miniserver.fun/resources/views/layouts/app.blade.php:1667). Сейчас используются JSON_HEX-флаги, это правильная защита, но после рефакторинга SEO нужны regression tests на escaping.

## Низкий приоритет

- CI workflow отсутствует. Минимальный набор: `composer validate`, Pint, `php artisan test`, `npm run build`.
- В `composer.json` нет static analysis или Rector scripts. PHPStan/Larastan или Rector стоит добавлять только когда будет готовность поддерживать baseline.
- [resources/views/catalog/index.blade.php](/www/wwwroot/seasonvar.miniserver.fun/resources/views/catalog/index.blade.php:3) все еще группирует последние тайтлы внутри Blade. При следующей чистке главной каталога это стоит перенести в контроллер или небольшой view model.
- Все публичные маршруты находятся в `routes/web.php`; для текущего приложения это нормально, но перед JSON endpoints нужно добавить `routes/api.php` и явные API Resources.

## Можно улучшить позже

- Добавить точечные тесты рендера Blade-компонентов, escaping JSON-LD, неправильных комбинаций фильтров каталога и количества запросов при сборке посадочных страниц карты сайта.
- Ввести небольшие immutable view-data objects для фильтров каталога, состояния плеера тайтла, строк сезонов и вариантов медиа.
- Добавить метрики retention и cleanup-команды для `source_page_snapshots` и логов импортов.
- Рассматривать Laravel scheduled tasks только после того, как operational-команды будут явно idempotent и защищены lock-механизмом.
