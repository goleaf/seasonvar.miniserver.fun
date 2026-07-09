# Аудит Laravel 13

Дата аудита: 09.07.2026

## Область проверки

Проверены структура Laravel-приложения, Composer и npm-пакеты, маршруты, контроллеры, модели, миграции, сервисы, Blade-шаблоны, тесты, конфигурация, очереди, события, политики, MCP-настройки и CI-файлы.

Подтвержденное окружение:

- PHP 8.5, Laravel 13.19.0, Laravel Boost 2.4.12, Laravel MCP 0.8.2.
- Основная база данных SQLite; тесты используют SQLite в памяти.
- PHPUnit 12.5; Pest не установлен.
- Tailwind CSS 4.3 с Vite 8, локальными FontAwesome, Plyr и HLS-ресурсами.
- В проекте есть read-only JSON API в `routes/api.php` для опубликованных карточек каталога и Laravel API Resources для форматирования ответов. Политик приложения, событий, слушателей, писем и уведомлений сейчас нет. Публичные query-параметры каталога и API пагинация проверяются Form Request-классами, а служебная статистика доступна как read-only Livewire-страница под rate limit.
- GitHub Actions workflow находится в `.github/workflows/ci.yml` и проверяет Composer, Pint, Laravel tests, PHP syntax lint, npm audit/build и dependency audits.

## MCP

Laravel Boost остается единственным активным проектным MCP-сервером, который хранится в Git. В проекте есть `boost.json` с включенным MCP и `.codex/config.toml` для запуска:

```toml
[mcp_servers.laravel-boost]
command = "php"
args = ["artisan", "boost:mcp", "--env=local"]
```

Файл конфигурации разрешен в `.gitignore`, чтобы проектная настройка Laravel Boost MCP попадала в Git без отслеживания остальных файлов `.codex`.

Дополнительные MCP и app connectors для GitHub, Google, Cloudflare, Notion, Sentry, Figma и других внешних сервисов должны подключаться через user/global config или авторизованный connector. Проектные правила и шаблоны находятся в `docs/integrations/mcp-catalog.md`, `docs/integrations/google.md` и `.codex/mcp.example.toml`.

## Исправлено

- Безопасные расчеты Blade-компонентов перенесены из `resources/views/components/title-card.blade.php`, `resources/views/components/title-list-row.blade.php` и `resources/views/components/ui/taxonomy-chip.blade.php` в классы компонентов `app/View/Components`.
- В не-production окружении включены проверки Eloquent на ленивую загрузку связей и тихо отброшенные атрибуты через `AppServiceProvider`.
- Общие метаданные стартового шаблона Laravel в `composer.json` заменены на метаданные проекта.
- Добавлен этот audit-документ и разрешено отслеживание существующей настройки Laravel Boost MCP.
- Посадочные страницы sitemap больше не выполняют `exists()` для каждой пары справочник/год; реальные пары считаются grouped join-запросами по pivot-таблицам и покрыты query-count regression test.
- Валидация публичных query-параметров вынесена в `CatalogTitlesRequest` и `CatalogShowRequest`; slug-фильтры используют reusable Rule, а типы фильтров перечислены enum.
- Служебная страница `/stats` открыта для гостевого read-only доступа, остается под rate limiter и не выводит raw source URLs, приватные media URLs, stack traces или внутренние имена маршрутов.
- Все Blade-шаблоны очищены от `@php`/`@endphp`; layout SEO готовит `AppLayoutData`, состояние страниц готовят ViewModel-классы, а `CatalogController` делегирует тяжелую работу page-builder и responder сервисам.

## Проверка

- `php artisan project:docs-refresh --check` прошел.
- `php artisan route:list` прошел.
- `php artisan list --raw` прошел.
- `rg -n "@php|@endphp" resources/views -g '*.blade.php'` не нашел inline PHP.
- `rg -n "env\(" app bootstrap config routes tests resources -g '*.php' -g '*.blade.php'` подтвердил `env()` только в config-файлах.

## Критично

Критичных проблем с прямым влиянием на production не найдено.

## Высокий приоритет

Высоких текущих проблем после рефакторинга Blade и контроллеров не найдено. Перед любыми будущими write/admin/moderation/import-control эндпоинтами все еще нужно добавлять отдельную авторизацию.

## Средний приоритет

- [app/Services/Seasonvar/SeasonvarTitleMerger.php](/www/wwwroot/seasonvar.miniserver.fun/app/Services/Seasonvar/SeasonvarTitleMerger.php:82) загружает все тайтлы в память перед группировкой дублей. Для большого каталога лучше перейти на группировку кандидатных дублей в базе или chunk-обработку.
- Поиск в `CatalogTitleQuery::applySearchFilter()` использует несколько `LIKE` с ведущим `%` и подзапросы по связям. Для маленькой базы это нормально, но при росте каталога стоит рассмотреть SQLite FTS или отдельную поисковую таблицу.
- Снимки страниц источника хранят raw HTML. Они должны оставаться непубличными; нужна политика retention/cleanup и запрет на вывод таких данных через будущие API или диагностику.
- JSON-LD выводится через `{!! !!}` в [resources/views/layouts/app.blade.php](/www/wwwroot/seasonvar.miniserver.fun/resources/views/layouts/app.blade.php:1667). Сейчас используются JSON_HEX-флаги, это правильная защита, но после рефакторинга SEO нужны regression tests на escaping.

## Низкий приоритет

- CI workflow добавлен. При расширении набора инструментов стоит подключить PHPStan/Larastan или Rector отдельным шагом.
- В `composer.json` нет команд статического анализа или Rector. PHPStan/Larastan или Rector стоит добавлять только когда будет готовность поддерживать базовый уровень проверок.
- API пока ограничен read-only карточками тайтлов. Перед write/admin/moderation/import-control JSON endpoints нужно добавить отдельную авторизацию, Form Requests и явные ресурсы ответа.

## Можно улучшить позже

- Добавить точечные тесты рендера Blade-компонентов, escaping JSON-LD, неправильных комбинаций фильтров каталога и количества запросов при сборке посадочных страниц карты сайта.
- Ввести небольшие immutable view-data objects для фильтров каталога, состояния плеера тайтла, строк сезонов и вариантов медиа.
- Добавить метрики retention и cleanup-команды для `source_page_snapshots` и логов импортов.
- Рассматривать Laravel scheduled tasks только после того, как operational-команды будут явно idempotent и защищены lock-механизмом.
