# Инструкции проекта

## Обзор проекта

Это Laravel 13 каталог Seasonvar: сериалы, передачи, аниме, документальные страницы, сезоны, серии, связи каталога, отзывы, страницы карты сайта и внешние видео-ссылки.

Текущий стек и соглашения:

- PHP 8.5, Laravel 13.19, Laravel Boost 2.4, Laravel Pint 1.29, PHPUnit 12.5.
- Tailwind CSS 4.3 с Vite 8; локальные FontAwesome, Plyr и HLS-ресурсы установлены через npm.
- SQLite используется локально и в тестах; PHPUnit использует SQLite в памяти.
- Web-маршруты находятся в `routes/web.php`; read-only JSON API находится в `routes/api.php`.
- Сейчас нет project-specific policies, events, listeners, notifications или mailables. Form Requests, API Resources и jobs используются для существующих read-only API, публичных фильтров каталога и очередного импорта.
- Основная публичная команда импорта: `php artisan seasonvar:import`.
- Документация проекта находится в `README.md` и `docs/*.md`; она должна быть конкретной для проекта, а не текстом стартового шаблона Laravel.

Перед изменением кода нужно смотреть соседние файлы и существующие паттерны. Предпочитать структуру, которая уже используется в `app/Http/Controllers`, `app/Models`, `app/Services`, `app/Console/Commands`, `resources/views`, `database/migrations` и `tests`.

## Соглашения Laravel

- Использовать API Laravel 13 и проверять поведение, зависящее от версии, через документацию Laravel Boost перед изменением Laravel-кода.
- Следовать соглашениям фреймворка для имен, названий маршрутов, моделей, связей, приведений типов, фабрик и тестов.
- Сохранять route model binding там, где он уже используется, например `CatalogTitle` по `slug`.
- Видимый текст интерфейса должен быть на русском языке.
- Не добавлять рекламный текст, маркетинговые описания, заглушки и фальшивый публичный контент.

## Архитектура

- Контроллеры должны оставаться тонкими: разбор запроса, передача валидации, передача авторизации, orchestration сервисов/запросов и выбор ответа.
- Бизнес-логику размещать в сервисах, actions, jobs или доменных классах, когда это уместно. Сначала использовать текущие сервисы, а не вводить новый паттерн.
- Соблюдать текущие границы сервисов в `app/Services/Seasonvar`, `app/Services/Media` и `app/Services/Crawler`.
- Выносить parsing, import, media, sitemap, crawling и тяжелую сборку запросов из контроллеров, когда они растут.
- `php artisan seasonvar:import` должен оставаться единственной публичной командой импорта Seasonvar.
- Импортер должен хранить сезоны и серии внутри одного `CatalogTitle`; нельзя создавать отдельные тайтлы каталога для отдельных сезонов.
- Видео-файлы никогда не скачиваются в это приложение. Нужно хранить внешние URL, качество, формат, перевод и состояние доступности.

## База данных и миграции

- Использовать Eloquent-связи и уже существующие явные pivot-таблицы для метаданных каталога.
- Не вводить полиморфные связи для метаданных каталога, если пользователь прямо не просит перепроектирование.
- Добавлять индексы для новых паттернов запросов: фильтры, joins, сортированные списки, очереди и большие импорты.
- Использовать транзакции для multi-table записей каталога.
- Для импортера использовать bulk operations: `upsert`, `chunkById` и grouped queries.
- Миграции должны быть additive и обратимыми, где это практически возможно. Не редактировать старые миграции после возможного запуска, если репозиторий явно не считает их unreleased.
- Не использовать seeders для production-данных каталога.

## Валидация и Form Request

- Нормализовать и валидировать весь ввод запроса перед применением фильтров, поиска, диапазонов года, route parameters или write operations.
- Текущие маршруты каталога в основном используют read-only валидацию в контроллере. При добавлении write endpoints или нетривиальной валидации создавать Form Request классы в `app/Http/Requests`.
- Для write operations размещать authorization в Form Request `authorize()` или policies.
- Для mass assignment использовать `$request->validated()`, а не `$request->all()`.
- Сообщения валидации и ошибки для пользователя должны быть на русском языке, если они показываются в интерфейсе.

## Авторизация

- Сейчас в кодовой базе нет project-specific policies. Перед authenticated write, admin, import-control или moderation endpoints нужно добавить policies или gates.
- Нельзя считать скрытые UI-кнопки авторизацией.
- Поведение `/api/*` должно оставаться удобным для JSON, как настроено в `bootstrap/app.php`.

## API Resources

- API-маршруты находятся в `routes/api.php`.
- При возврате Eloquent-моделей или коллекций из API использовать Laravel API Resources в `app/Http/Resources`.
- Для ответов с пагинацией включать метаданные пагинации и держать форму ответа явной.
- Не раскрывать через API внутреннее состояние импортера, source HTML snapshots, raw remote URLs, secrets или stack traces.

## Тесты

- Использовать PHPUnit-классы в `tests/Feature` и `tests/Unit`; Pest не установлен.
- Сначала запускать focused tests рядом с измененным поведением, потом широкий набор.
- Использовать фабрики и Laravel HTTP/console helpers для тестов.
- Использовать `RefreshDatabase`, как это делают текущие тесты, если нет явной причины менять базовый паттерн.
- Для importer, crawler, playlist и media-check tests подменять внешние HTTP-запросы через `Http::fake()` и `Http::preventStrayRequests()`.
- Тесты должны покрывать измененное поведение: routes, commands, parsing, sitemap/robots, media import и database behavior.

## Стиль кода

- После PHP-правок запускать Pint: `./vendor/bin/pint --dirty --format agent`.
- Использовать типизированные сигнатуры методов, return types и PHPDoc-generics связей, как в существующих моделях.
- Использовать Laravel helpers и collections там, где это делает код понятнее.
- Комментарии должны быть редкими и полезными; лучше понятные имена, чем поясняющие комментарии.
- Не выполнять database queries из Blade views.
- Использовать существующие Blade components из `resources/views/components` перед добавлением duplicate markup.
- Для изменений интерфейса следовать `docs/UI_STANDARDS.md`: светлая тема, русский текст интерфейса, локальные icons/assets и читаемые мобильные макеты.

## Git workflow

- Работать только в существующей ветке `main`. Не создавать feature branches, временные ветки, worktree-ветки, PR-ветки или дополнительные `main`-подобные ветки без прямого нового указания пользователя.
- Если рабочая директория открыта не на `main`, сначала сохранить текущие незакоммиченные изменения безопасным способом, переключиться на существующую `main` и продолжать работу только там.
- Не коммитить изменения в ветках, отличных от `main`. Не открывать pull request из отдельной ветки, если пользователь прямо не отменил это правило.
- Перед любым commit/push проверять `git status --short --branch` и убеждаться, что текущая ветка — `main`.
- Рабочее дерево нельзя оставлять грязным после завершения задачи: все разрешенные изменения должны быть закоммичены, а посторонние незакоммиченные изменения должны быть явно отмечены как блокер.
- Версионируемые хуки `.githooks/pre-commit` и `.githooks/pre-push` обязательны через `core.hooksPath=.githooks`: они блокируют commit/push вне `main`, частичные commit с unstaged/untracked файлами и push с dirty tree.

## Безопасность

- Спрашивать перед добавлением production dependencies.
- Не редактировать `.env` напрямую без явного запроса пользователя. При необходимости обновлять `.env.example` или config files.
- Не коммитить секреты, tokens, cookies, source credentials, raw private logs или скачанные видео-файлы.
- Читать environment values через config files; код приложения должен использовать `config()`, а не прямые `env()` вне config.
- Валидировать и нормализовать все external URLs. Source URLs Seasonvar должны оставаться внутри `https://seasonvar.ru/`.
- Экранировать Blade output через `{{ }}`, если content не trusted и намеренно sanitized.
- Для внешних запросов использовать timeouts/retries Laravel HTTP client; не создавать неограниченные remote calls.

## Производительность

- Избегать N+1 queries; заранее загружать связи, которые используются Blade или serialization.
- Не выполнять database queries в Blade views.
- Для счетчиков использовать `withCount()` или aggregate queries.
- Для больших наборов данных использовать `chunkById`, cursors, lazy collections и bulk writes.
- Sitemap и feed responses должны оставаться streamed, когда они могут расти вместе с каталогом.
- Не разбирать неизмененные source pages, если hashes/status позволяют безопасный пропуск.
- Размеры пакетов импортера и crawl delays должны быть консервативными, чтобы не перегружать локальный и внешний сервер.

## Проверки перед завершением

Запускать самые узкие подходящие команды для изменения:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=SpecificTestName
php artisan test
./vendor/bin/phpunit
npm run build
```

Примечания:

- Pint запускать после PHP-правок.
- Сначала запускать узкий фильтр тестов, если есть релевантный тест; полный набор запускать для широких или рискованных изменений.
- `./vendor/bin/phpunit` доступен, потому что PHPUnit установлен; обычно предпочтительнее `php artisan test`.
- `npm run build` запускать при изменениях frontend assets, Blade-разметки с asset assumptions, Vite config, Tailwind classes или JS/CSS files.
- Не запускать несуществующие команды: Pest и `npm run lint` в проекте не установлены.

## Запрещенные действия

- Не запускать destructive commands вроде `migrate:fresh`, `db:wipe`, `queue:clear`, `cache:clear` или широкие команды удаления данных на production-like окружениях без явного подтверждения.
- Не запускать `git reset --hard`, destructive checkout и не удалять пользовательские изменения без явного запроса.
- Не заменять проектную документацию общим текстом стартового шаблона Laravel.

<!-- project-docs:start -->
## Автоматизация документации

- Команда `php artisan project:docs-refresh` поддерживает управляемые блоки документации в актуальном состоянии.
- Git-хук должен работать через `core.hooksPath=.githooks`, не должен коммитить посторонние изменения вне управляемых файлов документации и должен отправлять текущую ветку в Git только при `SEASONVAR_DOCS_AUTO_PUSH=1`.
- Карта сайта и `robots.txt` считаются частью технической документации проекта и должны отражаться в `README.md`, `docs/CODE_STANDARDS.md`, `docs/DATA_RELATIONS.md` и журнале обслуживания.
<!-- project-docs:end -->
