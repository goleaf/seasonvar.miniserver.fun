# Авторизация

Обновлено: 09.07.2026

## Правила

- Публичные страницы каталога, карточек, sitemap, RSS, OpenSearch и `llms.txt` доступны гостям.
- Операционные и диагностические write/import-control endpoints не должны оставаться публичными.
- `/stats` доступен гостям как read-only Livewire-страница состояния каталога; чувствительные raw URLs, stack traces и внутренние технические имена не выводятся.
- Для будущих write/admin/moderation/import-control endpoints нужно добавлять отдельные gates или policies до публикации маршрута.
- Blade-шаблоны не принимают решений авторизации. Допустимы только простые display checks: `@can`, `@cannot`, `@auth`, `@guest`.

## Реализация

- Route `/stats` применяет только rate limiter `catalog-stats`, поэтому страница доступна без авторизации.
- Livewire update route для stats-polling также проходит через `throttle:catalog-stats`; авторизация не добавляется, потому что компонент только читает уже очищенный snapshot.
- Тесты `AuthorizationTest` покрывают гостевой доступ к основным страницам каталога и странице статистики.
