# API

Обновлено: 13.07.2026

## Маршруты

- `GET /api/titles` возвращает опубликованные карточки каталога с пагинацией.
- `GET /api/titles/{slug}` возвращает одну опубликованную карточку по slug.
- API-маршруты подключены через `routes/api.php`, получают стандартный префикс `/api` и ограничены rate limiter `catalog-api` до 60 запросов в минуту на IP.
- `GET /api/titles` принимает `page` и `per_page`; `per_page` ограничен диапазоном 1-50 и по умолчанию равен 15.
- `/api/*` ошибки должны оставаться JSON-friendly благодаря `shouldRenderJsonWhen()` в `bootstrap/app.php`.
- Anonymous `200` GET/HEAD получает public `Cache-Control`, `ETag`, `Last-Modified`, `Vary: Accept, Accept-Encoding`, SWR/stale-if-error и поддерживает `304`. Ответ с user, cookie, error или unsafe method не становится shared-cacheable; API Resource/database остаётся корректным cold path.

## Формат ответов

- Eloquent-модели не возвращаются напрямую. JSON готовят Laravel API Resources в `app/Http/Resources`.
- Коллекции используют стандартную обертку Laravel: `data`, `links`, `meta`.
- Карточка тайтла отдается через `CatalogTitleResource`; справочники, сезоны и серии отдаются через отдельные ресурсы.
- Ресурсы используют `whenLoaded()` и `whenCounted()`. Контроллеры и query-сервисы решают, какие связи и счетчики загружать, чтобы не создавать N+1 внутри сериализации.

## Публичные поля

API отдает только публичные данные каталога: slug, название, тип, год, описание, постер, дату индексации, счетчики, справочники, сезоны и серии.

Через API нельзя раскрывать:

- `source_url`, `source_url_hash`, `content_hash`, `external_id`;
- исходные страницы, HTML-снимки и внутреннее состояние импортера;
- `licensed_media.path`, `playback_url`, `source_url`, ключи медиа и HTTP-статусы проверки;
- пароли, токены, stack traces, секреты и приватные диагностические поля.
