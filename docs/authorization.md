# Авторизация

Обновлено: 13.07.2026

## Правила

- Публичные страницы каталога, карточек, sitemap, RSS, OpenSearch и `llms.txt` доступны гостям.
- Операционные и диагностические write/import-control endpoints не должны оставаться публичными.
- `/stats` доступен гостям как read-only Livewire-страница состояния каталога; чувствительные raw URLs, stack traces и внутренние технические имена не выводятся.
- Для будущих write/admin/moderation/import-control endpoints нужно добавлять отдельные gates или policies до публикации маршрута.
- Blade-шаблоны не принимают решений авторизации. Допустимы только простые display checks: `@can`, `@cannot`, `@auth`, `@guest`.
- Watchlist, rating и progress карточки доступны только authenticated user и проходят `CatalogTitlePolicy::interact`; policy повторно проверяет `CatalogTitle::availableTo($user)` независимо от видимости controls.
- Playable source не является публичным полем модели. Livewire получает только `PlaybackSourceData` с короткоживущим signed URL; `/playback/{licensedMedia}` сверяет подпись, viewer с текущей сессией и всю publication hierarchy повторно, поэтому прямой URL не обходит снятие с публикации или смену audience.

## Реализация

- Route `/stats` применяет только rate limiter `catalog-stats`, поэтому страница доступна без авторизации.
- Livewire update route для stats-polling также проходит через `throttle:catalog-stats`; авторизация не добавляется, потому что компонент только читает уже очищенный snapshot.
- Тесты `AuthorizationTest` покрывают гостевой доступ к основным страницам каталога и странице статистики.
- Implicit binding `CatalogTitle` скрывает authenticated-audience карточку от гостя; Livewire дополнительно держит `catalogTitleId` locked и разрешает episode/media IDs только внутри доступной и playable иерархии выбранного тайтла.
- Текущая схема поддерживает audience `public/authenticated`. Отдельных profile age, territory, subscription/entitlement и concurrent-stream сущностей пока нет; `PlaybackAvailability` уже задает однозначные user-facing состояния для этих отказов, но resolver не имитирует отсутствующие правила. Их нужно подключать в эту же server-side boundary одновременно с появлением реальной лицензионной модели.
