# Безопасность

Обновлено: 09.07.2026

## Правила

- `.env`, ключи, токены, cookies, приватные логи и локальные базы не коммитятся; публично отслеживается только `.env.example` без значений секретов.
- OAuth client secrets, Google ADC/service-account JSON, refresh tokens, MCP bearer tokens и приватные пути к credential-файлам не хранятся в репозитории.
- Код приложения читает переменные окружения через `config()`. Прямые `env()` допустимы только в `config/*.php`.
- Публичные страницы каталога остаются read-only и проходят строгую валидацию query-параметров через Form Request-классы.
- Все текущие публичные маршруты с implicit binding `CatalogTitle`, включая карточку `/titles/{slug}`, API show и proxy постера статистики, доступны только при `is_published=true`; неопубликованный тайтл возвращает `404` до controller/responder и не инициирует внешний HTTP-запрос.
- Служебная страница `/stats` доступна гостям как read-only Livewire-сводка, дополнительно ограничена rate limiter `catalog-stats` и не выводит raw source URLs, приватные media URLs, stack traces или внутренние имена маршрутов.
- Livewire-компонент `/stats` получает данные через очищенный snapshot: полный массив статистики не хранится в публичных свойствах компонента и не должен попадать в `wire:snapshot`.
- Прокси `stats.poster` всегда делает запросы только к HTTPS-URL с валидацией `poster_url`, не следует редиректам (`withoutRedirecting()`), отбрасывает слишком большие изображения и проверяет `Content-Type` как `image/*`. Непустой `Content-Length` проверяется до чтения тела, а фактический размер тела проверяется всегда; корректные chunked-ответы без `Content-Length` допускаются.
- `CatalogStatsPosterUrlGuard` используется и при сборке `/stats`, и в proxy responder: неразрешимые hostnames, localhost, `.local`, private и reserved IP не попадают в `poster_src` и не создают браузерные 404-запросы.
- Внешние URL Seasonvar нормализуются и допускаются только для `seasonvar.ru`; внешние playlist URL не могут указывать на localhost, `.local`, private или reserved IP.
- Внешние MCP и Google Workspace данные считаются недоверенным контентом; write tools и широкие scopes включаются только под конкретную задачу и с user-level authorization.
- Локальные temporary storage URLs отключены по умолчанию через `LOCAL_FILESYSTEM_SERVE=false`; включать их можно только для явной функции загрузки/выдачи файлов с отдельной авторизацией.
- Пользовательские uploads по умолчанию сохраняются на приватный disk `uploads`; нельзя доверять клиентским именам файлов, делать upload-файлы публичными без отдельной авторизации или отдавать private paths наружу.
- Operational notifications не должны включать stack traces, raw HTML source snapshots, private media URLs, секреты или credential paths.
- Blade-шаблоны не содержат `@php`/`@endphp`; вывод экранируется через `{{ }}`, кроме JSON-LD с `JSON_HEX_*` флагами.

## HTTP

- Web-ответы добавляют защитные заголовки: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` и `X-Permitted-Cross-Domain-Policies`.
- Строгий CSP пока не включен: страницы карточек используют внешние постеры и внешние media URLs, поэтому CSP нужно проектировать отдельно с учетом этих источников.
- Laravel web middleware сохраняет стандартные encrypted cookies и `PreventRequestForgery` для небезопасных HTTP-методов.

## Проверки

- `SecurityHardeningTest` проверяет security headers, rate limit `/stats`, отключенные storage routes и блокировку private/local playlist hosts.
- `PrivateImageUploadRulesTest` и `PrivateUploadStorageTest` проверяют upload-валидацию, private visibility, generated filenames и cleanup через fake storage.
- `RunSeasonvarImportJobTest` и `SeasonvarImportFailedNotificationTest` проверяют dispatch/content operational notification без отправки реальных писем.
- `CatalogPageTest` и `CatalogStatsSnapshotSanitizerTest` проверяют, что stats HTML/Livewire-рендер не раскрывает исходные source/media URL, stack traces и приватный event context.
- `composer audit` и `npm audit --audit-level=high` используются для проверки известных уязвимостей зависимостей.
