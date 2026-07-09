# Безопасность

Обновлено: 09.07.2026

## Правила

- `.env`, ключи, токены, cookies, приватные логи и локальные базы не коммитятся; публично отслеживается только `.env.example` без значений секретов.
- Код приложения читает переменные окружения через `config()`. Прямые `env()` допустимы только в `config/*.php`.
- Публичные страницы каталога остаются read-only и проходят строгую валидацию query-параметров через Form Request-классы.
- Служебная страница `/stats` доступна только аутентифицированным пользователям через gate `viewCatalogStats` и дополнительно ограничена rate limiter `catalog-stats`.
- Внешние URL Seasonvar нормализуются и допускаются только для `seasonvar.ru`; внешние playlist URL не могут указывать на localhost, `.local`, private или reserved IP.
- Локальные temporary storage URLs отключены по умолчанию через `LOCAL_FILESYSTEM_SERVE=false`; включать их можно только для явной функции загрузки/выдачи файлов с отдельной авторизацией.
- Blade-шаблоны не содержат `@php`/`@endphp`; вывод экранируется через `{{ }}`, кроме JSON-LD с `JSON_HEX_*` флагами.

## HTTP

- Web-ответы добавляют защитные заголовки: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` и `X-Permitted-Cross-Domain-Policies`.
- Строгий CSP пока не включен: страницы карточек используют внешние постеры и внешние media URLs, поэтому CSP нужно проектировать отдельно с учетом этих источников.
- Laravel web middleware сохраняет стандартные encrypted cookies и `PreventRequestForgery` для небезопасных HTTP-методов.

## Проверки

- `SecurityHardeningTest` проверяет security headers, rate limit `/stats`, отключенные storage routes и блокировку private/local playlist hosts.
- `composer audit` и `npm audit --audit-level=high` используются для проверки известных уязвимостей зависимостей.
