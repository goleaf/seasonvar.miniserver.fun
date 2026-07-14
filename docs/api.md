# API

Обновлено: 14.07.2026

## Версии и discovery

- `GET /api` возвращает небольшой discovery-манифест: текущую и минимально поддерживаемую версию `v1`, base URL, OpenAPI URL и список верхнеуровневых возможностей.
- Новый стабильный mobile contract размещается под `/api/v1`. Breaking rename, removal, изменение смысла или формы pagination/error response требует новой версии; additive поля остаются допустимы в `v1`.
- `GET /api/openapi.json` отдаёт project-owned OpenAPI 3.1 document из `resources/api/openapi.json`. Runtime documentation package не используется.
- `GET /api/v1/config` возвращает только locale/timezone, публичные границы pagination/rating, поддерживаемые playback formats/qualities и bounded client TTL/heartbeat values.
- `GET /api/v1/health` возвращает только `status`, UTC `server_time` и `api_version` с `private, no-store`; database, cache, queue, importer, filesystem и версии framework не раскрываются.

## Legacy-маршруты

- `GET /api/titles` возвращает опубликованные карточки каталога с пагинацией.
- `GET /api/titles/{slug}` возвращает одну опубликованную карточку по slug.
- `GET /api/catalog/people` возвращает bounded actor/director options для публичного поиска.
- API-маршруты подключены через `routes/api.php`, получают стандартный префикс `/api` и не используют локальный request budget.
- `GET /api/titles` принимает `page` и `per_page`; `per_page` ограничен диапазоном 1-50 и по умолчанию равен 15.
- Legacy route names и формы ответов сохраняются; v1 не переименовывает и не подменяет существующие endpoints.

## Ошибки и request ID

- Каждый API request проходит `AssignApiRequestId`. Разрешённый входящий `X-Request-ID` имеет 8-128 символов из безопасного allowlist; иначе сервер создаёт ULID.
- Ошибка возвращает `code`, русское `message`, тот же `request_id` и необязательный объект `errors` для validation. Заголовок `X-Request-ID` совпадает с полем ответа.
- Стабильные foundation codes: `validation_failed`, `unauthenticated`, `forbidden`, `not_found`, `rate_limited`, `server_error`. Ответ `server_error` не содержит exception message или stack trace.
- API errors всегда получают `private, no-store`; неизвестный `/api/*` обрабатывает named API fallback, а неизвестный web URL продолжает редирект на главную.

## HTTP cache

- Anonymous `200` GET/HEAD получает public `Cache-Control`, `ETag`, `Last-Modified`, `Vary: Accept, Accept-Encoding`, SWR/stale-if-error и поддерживает `304`.
- Наличие `Authorization` закрывает shared cache до разрешения пользователя: ответ становится `private, no-store`, а `ETag` и `Last-Modified` удаляются даже для недействительного Bearer token.
- Ответ с user, cookie, error или unsafe method также не становится shared-cacheable; API Resource/database остаётся корректным cold path.

## Mobile token foundation

- Laravel Sanctum является единственной token boundary мобильного API. Personal access tokens хранятся только в hashed виде, имеют abilities `mobile:read`/`mobile:write` и максимум 90 дней действия.
- Global expiration задаётся `SANCTUM_TOKEN_EXPIRATION_MINUTES=129600`; просроченные строки ежедневно удаляет scheduled `sanctum:prune-expired --hours=24`.
- Admin/import abilities, raw token hashes и plaintext token после момента выдачи через API не возвращаются.

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
