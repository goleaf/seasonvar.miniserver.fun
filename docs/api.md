# API

Обновлено: 14.07.2026

## Версии и discovery

- `GET /api` возвращает небольшой discovery-манифест: текущую и минимально поддерживаемую версию `v1`, base URL, OpenAPI URL и список верхнеуровневых возможностей.
- Новый стабильный mobile contract размещается под `/api/v1`. Breaking rename, removal, изменение смысла или формы pagination/error response требует новой версии; additive поля остаются допустимы в `v1`.
- `GET /api/openapi.json` отдаёт project-owned OpenAPI 3.1 document из `resources/api/openapi.json`. Runtime documentation package не используется.
- `GET /api/v1/config` возвращает только locale/timezone, публичные границы pagination/rating, поддерживаемые playback formats/qualities и bounded client TTL/heartbeat values.
- `GET /api/v1/health` возвращает только `status`, UTC `server_time` и `api_version` с `private, no-store`; database, cache, queue, importer, filesystem и версии framework не раскрываются.

## Публичный каталог v1

- `GET /api/v1/home` возвращает bounded главные подборки, публичные счетчики, годы, жанры и страны. В latest releases присутствуют только безопасные metadata серии/media без URL воспроизведения.
- `GET /api/v1/catalog/filters` описывает controls, сортировки, типы публикаций, rating/video/subtitle/updated options, качества и числовые границы. `alphabet.cyrillic`, `alphabet.latin` (`A`–`Z`) и `alphabet.other` (`#`) всегда разделены.
- `GET /api/v1/catalog/directories` перечисляет `genres`, `countries`, `actors`, `directors`, `age-ratings`, `translations`, `statuses`, `networks`, `studios`, `tags`, `years`.
- `GET /api/v1/catalog/directories/{directory}` принимает `q`, одну `letter` или `#`, `sort=name_asc|count_desc`, `decade`, `page`, `per_page`. Ответ содержит стандартную пагинацию, summary, доступные отдельные группы алфавита и decades для years.
- `GET /api/v1/titles` возвращает пагинированные `TitleCardResource`. Поддерживаются `q`, `page`, `per_page`, `sort`, `letter`, `year`, `year_from`, `year_to`, `genre`, `country`, `actor`, `director`, `age_rating`, `translation`, `status`, `network`, `studio`, `tag`, `exclude_country`, `exclude_genre`, `seasons_min`, `seasons_max`, `episodes_min`, `episodes_max`, `rating_source`, `rating_min`, `votes_min`, `video`, `subtitles`, `quality`, `publication_type`, `updated`.
- Массивы принимаются с индексами, без индексов или как повторяемые значения. Например, `country[]=turciia`, `country[0]=turciia` и `country=turciia` проходят одну нормализацию. В группе действует OR, между группами — AND; одно значение нельзя одновременно включить и исключить.

## Карточка и связанные данные v1

- `GET /api/v1/titles/{titleSlug}` возвращает public identity, aliases, provider ratings, aggregate viewer rating, taxonomies, counts, безопасный primary action и ссылки. При валидном optional Bearer token добавляется только состояние текущего пользователя.
- `GET /api/v1/titles/{titleSlug}/seasons` и `GET /api/v1/titles/{titleSlug}/seasons/{season}/episodes` возвращают только releases, принадлежащие выбранному тайтлу и доступные текущему audience. Чужой season ID отвечает `not_found`.
- Media profile содержит только `id`, translation, variant, variant key, quality, format и duration. Выдача реального playback URL относится к отдельному защищённому playback contract, а не к публичному каталогу.
- `GET /api/v1/search/suggestions?q=...` требует 2–80 нормализованных символов и возвращает максимум по пять тайтлов, актёров и режиссёров с типом, label, slug и публичным count.
- `GET /api/v1/titles/{titleSlug}/recommendations` возвращает rank, русские reason labels и безопасную карточку. Numeric score, breakdown, signals и algorithm version исключены.
- `GET /api/v1/titles/{titleSlug}/reviews` возвращает read-only импортированные `id`, author, body, published date со стандартной пагинацией 1–50. Source page, body hash и provider identity исключены.

Все public v1 GET поддерживают guest-доступ. Валидный Bearer token с ability `mobile:read` расширяет только audience/personalization; недействительный token отвечает `unauthenticated`, а token без read ability — `forbidden`.

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
- Карточка тайтла отдается через v1 `CatalogTitleResource`; справочники, media profiles, сезоны, серии, рекомендации, отзывы и подсказки отдаются через отдельные ресурсы.
- Ресурсы используют `whenLoaded()` и `whenCounted()`. Контроллеры и query-сервисы решают, какие связи и счетчики загружать, чтобы не создавать N+1 внутри сериализации.

## Публичные поля

API отдает только публичные данные каталога: slug, название, тип, год, описание, постер, дату индексации, счетчики, справочники, сезоны, серии, безопасные media profiles, рекомендации и тексты отзывов.

Через API нельзя раскрывать:

- `source_url`, `source_url_hash`, `content_hash`, `external_id`;
- исходные страницы, HTML-снимки и внутреннее состояние импортера;
- `licensed_media.path`, `playback_url`, `source_url`, ключи медиа и HTTP-статусы проверки;
- recommendation score/breakdown/signals/algorithm version и review source page/body hash;
- пароли, токены, stack traces, секреты и приватные диагностические поля.
