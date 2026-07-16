# API

Обновлено: 16.07.2026

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
- `GET /api/v1/titles` возвращает пагинированные `TitleCardResource`. Параметр `q` ищет только по основному, оригинальному и альтернативным названиям; описание и relations в него не входят. Поддерживаются `q`, `page`, `per_page`, `sort`, `letter`, `year`, `year_from`, `year_to`, `genre`, `country`, `actor`, `director`, `age_rating`, `translation`, `status`, `network`, `studio`, `tag`, `exclude_country`, `exclude_genre`, `seasons_min`, `seasons_max`, `episodes_min`, `episodes_max`, `rating_source`, `rating_min`, `votes_min`, `video`, `subtitles`, `quality`, `publication_type`, `updated`.
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
- Ошибка возвращает `code`, локализованное `ru|en` `message`, тот же `request_id` и необязательный объект `errors` для validation. `SetApiLocale` принимает только поддерживаемый `Accept-Language`, иначе использует безопасный fallback; заголовок `X-Request-ID` совпадает с полем ответа.
- Стабильные foundation codes: `validation_failed`, `unauthenticated`, `forbidden`, `not_found`, `rate_limited`, `server_error`. Offline-sync дополнительно использует `sync_cursor_expired`/410 и `sync_unavailable`/503. Ответ `server_error` не содержит exception message или stack trace.
- API errors всегда получают `private, no-store`; неизвестный `/api/*` обрабатывает named API fallback, а неизвестный web URL продолжает редирект на главную.
- Для `/api/*` guest redirect отключён независимо от заголовка `Accept`: защищённый endpoint всегда отвечает JSON `unauthenticated`/401 и никогда не пытается построить web route `login`.

## HTTP cache

- Anonymous `200` GET/HEAD получает public `Cache-Control`, `ETag`, `Last-Modified`, `Vary: Accept, Accept-Encoding`, SWR/stale-if-error и поддерживает `304`.
- Наличие `Authorization` закрывает shared cache до разрешения пользователя: ответ становится `private, no-store`, а `ETag` и `Last-Modified` удаляются даже для недействительного Bearer token.
- Ответ с user, cookie, error или unsafe method также не становится shared-cacheable; API Resource/database остаётся корректным cold path.

## Mobile token foundation

- Laravel Sanctum является единственной token boundary мобильного API. Personal access tokens хранятся только в hashed виде, имеют abilities `mobile:read`/`mobile:write` и максимум 90 дней действия.
- Global expiration задаётся `SANCTUM_TOKEN_EXPIRATION_MINUTES=129600`; просроченные строки ежедневно удаляет scheduled `sanctum:prune-expired --hours=24`.
- Admin/import abilities, raw token hashes и plaintext token после момента выдачи через API не возвращаются.

## Authentication и аккаунт v1

- `POST /api/v1/auth/register` доступен только при `AUTH_REGISTRATION_ENABLED=true`, нормализует имя/email/device name, применяет общий `Password::defaults()`, создаёт обычный unverified аккаунт и один device token. `POST /api/v1/auth/login` использует единое сообщение и dummy hash work для неизвестного email, а после успешной проверки rehash-ит пароль по текущей Laravel config; endpoint не перечисляет пользователей.
- `GET /api/v1/auth/email/verify/{id}/{hash}` продолжает принимать временную signed URL для API-клиента. Текущее project-owned verification notification открывает signed web route, чтобы один и тот же адрес можно было подтвердить в браузере; `POST /api/v1/auth/email/verification-notification` доступен с Bearer token и повторно ставит письмо в очередь, если email ещё не подтверждён.
- `POST /api/v1/auth/forgot-password` имеет byte-identical success body для существующего и отсутствующего email. `POST /api/v1/auth/reset-password` проверяет reset token, меняет пароль и отзывает все mobile tokens аккаунта.
- `GET /api/v1/auth/devices` возвращает только `id`, device name, last-use/expiry timestamps и признак текущего устройства. Hash и abilities не сериализуются. `DELETE /api/v1/auth/devices/{token}` разрешает только owner-scoped ID и маскирует чужой ID как `not_found`.
- `POST /api/v1/auth/token/refresh` внутри транзакции повторно owner-resolves и блокирует persisted token, отзывает его и только затем создаёт один новый 90-дневный plaintext token с тем же device name/abilities. Одновременный или stale replay уже потреблённого token получает generic `unauthenticated`/401 и не создаёт второй replacement. `POST /api/v1/auth/logout` отзывает только текущий token, а `POST /api/v1/auth/logout-all` — все tokens пользователя.
- `GET /api/v1/me` возвращает `UserResource`; `PATCH /api/v1/me` меняет только имя/email, а реальная смена email дополнительно требует `current_password`. Она сбрасывает `email_verified_at`, удаляет reset rows обоих адресов и отправляет новое подтверждение. `PATCH /api/v1/me/password` требует текущий пароль, удаляет reset token и при Bearer-аутентификации отзывает все device tokens, кроме текущего; допустимый first-party session-вызов сохраняет текущую browser session и отзывает все mobile tokens. `DELETE /api/v1/me` требует пароль и удаляет аккаунт, tokens, reset tokens, database sessions, watchlist/rating и episode progress через общий lifecycle.
- `/me` намеренно доступен unverified пользователю: иначе он не смог бы исправить email или повторить verification. Будущие write/playback endpoints должны отдельно объявлять, требуется ли verified email; token сам по себе не создаёт такую границу.
- Read endpoints требуют `mobile:read`, изменения и отзыв tokens — дополнительно `mobile:write`. Credential endpoints имеют отдельные named budgets: register/login — 5 в минуту, resend verification — 3 в минуту, forgot/reset — 3 за 10 минут, refresh — 20 в минуту на текущий token. Limiter dimensions содержат HMAC email/network/token scopes вместо raw sensitive values; публичный каталог не получает эти limits.
- Authentication events записываются secret-free stable codes с HMAC fingerprints. Password/hash, raw email/IP, Bearer/reset/verification token, device plaintext и request body не попадают в audit. Social providers/linking/merge, magic links и MFA отсутствуют в v1 и discovery, а matching email не создаёт identity relation.
- Project-owned verification/reset notifications ставятся в очередь и ведут на web routes; mobile client открывает эту ссылку в браузере. API verification endpoint сохраняется как совместимый программный contract, а присланный reset token также принимается API reset endpoint. В production mail transport, `QUEUE_CONNECTION` и worker настраиваются вне Git; секреты и значения `.env` в документацию/репозиторий не переносятся.

## Личное состояние каталога v1

- `GET /api/v1/me/titles/{catalogTitle}/state` возвращает `in_watchlist`, личную оценку, отдельный aggregate пользовательских оценок, диапазон 1–10 и безопасный primary action. Provider ratings остаются отдельными данными карточки.
- `PUT`/`DELETE /api/v1/me/watchlist/{catalogTitle}` задают желаемое состояние `true`/`false`, а `PUT`/`DELETE /api/v1/me/ratings/{catalogTitle}` — целую оценку 1–10 или `null`. Повтор одного запроса идемпотентен. Favorites не является второй сущностью: это тот же watchlist.
- `GET /api/v1/me/watchlist` и `GET /api/v1/me/ratings` возвращают стандартную пагинацию `data`, `links`, `meta`, только строки текущего пользователя и только тайтлы, доступные ему сейчас. Оба принимают `q` до 160 символов, `type`, `year`, `direction=asc|desc`, `page` и `per_page` 1–50. `sort` поддерживает `updated|title|year`, а для ratings дополнительно `rating`; по умолчанию используется `updated desc`.
- `GET /api/v1/me/library/summary` возвращает точные `watchlist_count`, `ratings_count`, bounded `continue_watching_count`, `history_count`, nullable `last_watched_at` и canonical links четырёх разделов. Сводка не принимает profile/user ID, требует `mobile:read` и всегда отвечает `private, no-store` без ETag.
- `GET /api/v1/me/continue-watching` принимает `limit` 1–24 и возвращает одно действие `continue` или `next` на тайтл с позицией и процентом. `GET /api/v1/me/history` принимает `page` и `per_page` 1–48; старый скрытый или удалённый релиз остаётся в истории с `is_accessible=false`, но не становится доступным для воспроизведения.
- `DELETE /api/v1/me/history/{episodeViewProgress}` удаляет только owner-scoped запись и маскирует чужой numeric ID как `not_found`; `DELETE /api/v1/me/history` очищает только фактическую playback activity текущего пользователя.
- Все private reads требуют Bearer token с `mobile:read`. Mutations дополнительно требуют `mobile:write` и подтверждённый email; unverified пользователь продолжает читать своё состояние, но получает `email_not_verified`/403 при изменении. Все ответы private/no-store и не содержат ETag.

## Offline-синхронизация v1

- `GET /api/v1/sync/manifest` — публичная точка bootstrap. Она возвращает `sync_version=1`, непрозрачный catalog cursor, окна/лимиты и canonical links. Клиент сначала сохраняет cursor, затем постранично загружает текущие Resources каталога и после этого догоняет `GET /api/v1/sync/changes` от сохранённого cursor. Такой порядок закрывает изменения, случившиеся во время полной загрузки.
- `GET /api/v1/sync/changes` принимает optional `cursor` и `limit` 1–200. Без cursor возвращается пустой `data` и текущий checkpoint; с cursor — упорядоченные `title` invalidations `upsert|delete`. Journal не копирует graph тайтла: актуальные данные клиент получает через `links.self`, а tombstone удаления имеет `self=null`.
- `GET /api/v1/me/sync` требует `mobile:read` и использует тот же pull contract только для текущего владельца. Initial state по-прежнему загружается из watchlist, ratings, Continue Watching и history; последующие entries имеют типы `title_state`, `progress` и `history` и не содержат `user_id`, email или чужих progress IDs.
- Cursor является подписанным opaque transport value, привязанным к `catalog` или к конкретному user; внутри него также защищено время выдачи. Его нельзя разбирать или редактировать на клиенте. Подмена, неверный scope/owner или форма дают `validation_failed`/422. Cursor старше 30-дневного окна отвечает `sync_cursor_expired`/410 даже после полной очистки соответствующего journal, включая initial cursor с checkpoint `0`; успешный pull выдаёт новый cursor с обновлённым временем. Клиент после 410 повторяет полный bootstrap через manifest.
- `POST /api/v1/me/sync` требует `mobile:read`, `mobile:write` и verified email. Один запрос содержит 1–50 exact-shape операций в последовательном JSON-массиве: `watchlist.set`, `rating.set`, `progress.set`, `history.delete`, `history.clear`. Неизвестные поля верхнего уровня и операций, неизвестные типы, повтор UUID внутри batch, числовые заменители boolean и значения вне диапазонов отклоняются до доменной записи.
- Каждая операция имеет UUID `mutation_id` и выполняется независимо через существующие state/progress/history services. Ответ сохраняет исходный порядок и возвращает `applied`, `duplicate`, `conflict`, `rejected` или `not_found`; ожидаемый отказ одной операции не откатывает соседние. `watchlist.set` и `rating.set` передают `expected_version`; несовпадение с `versions.watchlist|rating` возвращает текущий state и `conflict`, не затирая более новое устройство.
- Повтор того же UUID и canonical payload возвращает безопасную сохранённую квитанцию как `duplicate` без повторной записи. Тот же UUID с другим payload возвращает `conflict`/`mutation_id_reused`. Квитанции хранятся 90 дней, но не сохраняют request body, playback session, Bearer token, raw media/source URL или importer state.
- Journal changes хранится 30 дней, mutation receipts — 90 дней. Ежедневная `api:sync-prune` удаляет просроченные строки пачками не более 500 и защищена `withoutOverlapping`/`onOneServer`. Если additive schema ещё не применена, sync endpoints отвечают очищенным `sync_unavailable`/503, publishers и prune безопасно пропускают работу, а остальной `/api/v1` продолжает работать.
- Offline-sync означает offline-каталог и очередь пользовательского состояния. Скачивание видео, постоянные media URL и offline playback не входят в API: воспроизведение всегда требует текущую короткоживущую session/grant и повторную server-side проверку доступа.

## Playback и progress v1

- `POST /api/v1/titles/{titleSlug}/playback-sessions` принимает необязательные `episode_id`, `media_id`, `variant`, `audio_language`, `quality` и `format`. Гость может создать сессию только для public audience; валидный Bearer token должен иметь `mobile:read`, а недействительный token не откатывается к guest. Ответ `201` содержит безопасный профиль media, тот же origin `playback_url`, срок действия, навигацию серии и, только для verified пользователя, `progress_session_token`.
- `playback_url` является короткоживущим signed URL `GET /api/v1/playback/{licensedMedia}` с opaque encrypted grant. Mobile-клиент считает URL непрозрачным и не передаёт Bearer token в query string. Endpoint повторно проверяет grant, существование привязанного user, media и всю publication hierarchy, затем использует общий playback resolver; provider URL появляется только как разрешённый redirect и никогда не сериализуется в JSON.
- `PUT /api/v1/titles/{titleSlug}/episodes/{episode}/progress` требует Bearer token с `mobile:write` и verified email. Тело содержит выданный `playback_session_token`, монотонный `event_sequence`, `position_seconds`, сообщённую длительность и `ended`; каноническая длительность берётся с media на сервере. Устаревший, повторный, чужой или подменённый event отвечает `invalid_playback_progress`/422 и не изменяет позицию.
- Playback responses всегда `private, no-store`; delivery дополнительно устанавливает `Referrer-Policy: no-referrer` и `X-Content-Type-Options: nosniff`. Domain-отказы сохраняют стабильное соответствие: authentication 401, plan 402, profile 403, not found 404, expired 410, future publication 425, region 451 и temporary source failure 503.

## Формат ответов

- Eloquent-модели не возвращаются напрямую. JSON готовят Laravel API Resources в `app/Http/Resources`.
- Коллекции используют стандартную обертку Laravel: `data`, `links`, `meta`.
- Карточка тайтла отдается через v1 `CatalogTitleResource`; справочники, media profiles, сезоны, серии, рекомендации, отзывы и подсказки отдаются через отдельные ресурсы.
- Ресурсы используют `whenLoaded()` и `whenCounted()`. Контроллеры и query-сервисы решают, какие связи и счетчики загружать, чтобы не создавать N+1 внутри сериализации.

## Public collections v1

- `GET /api/v1/collections` принимает validated `q`, `sort=featured|recent|title`, `per_page=12|18|24|36` и обычный `page`. Возвращаются только approved public non-deleted collections с public UUID/slug, original-or-editorial display text, visible item count, safe owner public UUID/name, cover web URL и canonical web/API links.
- `GET /api/v1/collections/{collectionSlug}` возвращает paginated visible serial items (`page`, `per_page` 6–48) и отдельный safe `collection` object. Private, unlisted, pending, rejected, hidden, archived и deleted collections отвечают `not_found`; API не является способом чтения intended unlisted links.
- `GET /api/v1/titles/{titleSlug}/collections` возвращает bounded public approved collections, содержащие guest-visible title. Membership current user, owner controls, unavailable items и private item counts не сериализуются.
- Collection Resources не отдают numeric collection/user IDs, `owner_id`, storage disk/path, report/moderation notes, membership attribution, content/cache versions или source/media URLs. Pagination query-object общий с web, но API явно использует conventional `page`, а не Livewire key `collectionsPage`.
- `resources/api/openapi.json` содержит `PublicCollection`, `PublicCollectionItem`, paginated list/items schemas и route response references. Discovery `/api` публикует collection links через существующий manifest.

Collection endpoints находятся в отдельном `public.cache:collection_api` profile: Authorization/cookies по общему API policy делают ответ private/no-store, а guest shared TTL/stale равны нулю. Это сохраняет HTTP validators/revalidation без риска stale public response после visibility/moderation change.

## Обсуждения и API

Task 12 не добавляет comment endpoints в legacy или `/api/v1`: текущий product scope — web/Livewire, а существующий API/OpenAPI contract остаётся совместимым. Web direct-comment URL является redirect на canonical target, не JSON resource и не отдельная индексируемая сущность.

Будущий mobile discussion API обязан переиспользовать `CommentTargetResolver`, `CommentPolicy`, canonical actions/query и explicit API Resources. Нельзя возвращать Eloquent graph, raw target URLs, body hash/submission key, moderator note/reporter, block/mute/restriction/notification state или author-only pending через public endpoint. Public DTO и viewer overlay должны оставаться разделены, а spoiler body — отсутствовать до явного authorized reveal.

## Отзывы пользователей и API

Existing `GET /api/v1/titles/{titleSlug}/reviews` and route name remain backward-compatible read-only provider-review feed. `CatalogReviewQuery` explicitly filters `origin=provider,status=published,deleted_at IS NULL,merged_into_id IS NULL` when the additive schema is ready; before migration `ReviewSchema` preserves the legacy query. Response fields/pagination do not expose source page, hashes, user/account IDs, votes, reports, moderation, rating state or viewer overlays.

Community review create/edit/delete/vote/report/moderation/history are web Livewire only and intentionally absent from mobile API/OpenAPI. This avoids silently broadening token abilities, caching or privacy semantics. A future mobile contract must reuse existing review actions/policy/DTO, declare private/no-store responses and never mix imported provider ratings with portal review score.

## Теги v1

- `GET /api/v1/tags?q=` возвращает максимум 25 eligible canonical tags: при query от двух символов — canonical/translation/approved-alias search с de-duplication и bounded synonym expansion, без query — popular by distinct visible serial count. Private/internal/unapproved/archive/empty rows исключены.
- `GET /api/v1/tags/{tagSlug}` разрешает case/current/history/approved alias/merge identity, 301-redirect-ит на current canonical slug и возвращает opaque `public_id`, optional code, stable type, localized name/plain-text description, visible `serial_count`, approved aliases, public links и bounded related list. Source URL, mapping details, internal IDs/status и private counts отсутствуют.
- `GET /api/v1/me/tags` owner-only ищет собственные active personal tags. Verified `POST /me/tags`, `PATCH|DELETE /me/tags/{uuid}` и `POST /me/tags/{uuid}/restore` создают, optimistic-edit-ят, soft-delete-ят и восстанавливают private tag. Owner ID/type/visibility/moderation не принимаются; label остаётся на исходном языке.
- `GET|PUT /api/v1/me/titles/{titleSlug}/tags` читает или transactionally reconciles ordered UUID set; `DELETE .../tags/{uuid}` idempotently снимает одну assignment. Все UUID повторно owner-authorize-ятся, batch bounded, недоступный title/tag отвечает безопасно и unrelated watchlist/rating/progress/history/collection state не меняется.
- Private tag endpoints требуют `auth:sanctum` + `mobile:read`; mutations также `mobile:write`, verified email и standard CSRF/token boundary. Каждый ответ имеет `Cache-Control: private, no-store`; owner IDs/private labels/counts не попадают в public resources, discovery, ETag или shared cache.
- Контракт описан в существующем `/api/openapi.json`; новый API version или competing tag envelope не вводится. Public error envelope и localized validation сохраняют текущий API contract без SQL/model/provider detail.

## Публичные поля

API отдает только публичные данные каталога: slug, название, тип, год, описание, постер, дату индексации, счетчики, справочники, сезоны, серии, безопасные media profiles, рекомендации, тексты отзывов и approved public collection summaries/items.

Через API нельзя раскрывать:

- `source_url`, `source_url_hash`, `content_hash`, `external_id`;
- исходные страницы, HTML-снимки и внутреннее состояние импортера;
- `licensed_media.path`, `playback_url`, `source_url`, ключи медиа и HTTP-статусы проверки;
- recommendation score/breakdown/signals/algorithm version и review source page/body hash;
- comment body hash/submission key, moderation notes, reporter identity, restrictions, blocks/mutes, notification payload/state и author-only pending comments;
- пароли, токены, stack traces, секреты и приватные диагностические поля.

## Профили пользователей и API

Task 14 adds web/Livewire public profiles only. Existing v1 user/account/library/comment/review/collection resources and routes are unchanged; no Eloquent `UserProfile`, email, privacy matrix, raw media path, report/moderation state, block/mute overlay or detailed watch progress is exposed through a new JSON endpoint. A future mobile profile API must reuse `PublicUserProfileData`, policy and canonical queries through API Resources rather than serialize models directly.

## Recommendation API compatibility

`GET /api/v1/titles/{titleSlug}/recommendations` и route name `api.v1.titles.recommendations` сохранены. Controller использует canonical `CatalogRecommendationService::forTitle()`, но response shape остаётся совместимым: public recommended title resource, rank и localized public reasons. Explicit related web rows не добавляют новый private API contract и не меняют существующий endpoint без версии.

Endpoint всегда public/content-contextual: authorization cookie не включает watch history, progress, watchlist, status, collection, personal tag, feedback/blacklist или private explanation и поэтому не загрязняет public API cache. Internal score breakdown, source/algorithm/provider signal, raw media URL и relation administration fields не выдаются. Additive owner-only user-state/library resources включают stable `recommendation_feedback`, feedback version/time, `watch_status` и version; они остаются authenticated `private, no-store` и описаны в `resources/api/openapi.json`.

## Video size и download API boundary

Текущий public/mobile v1 contract не отвечает за authenticated web attachment, поэтому его response shape не меняется. `file_size_bytes`, formatted size, download capability и download URL не добавляются в public title/media resources; raw `path`, `playback_url`, `source_url`, size-check error/status и provider headers по-прежнему закрыты. Offline-sync/download video через mobile API остаётся unsupported.

On-demand direct-file download существует только как session-authenticated web route `titles.media.download`. Клиент передаёт исключительно scoped title slug и media ID; remote URL, extension и filename не являются request/API fields. Endpoint повторно authorizes текущие persisted relationships и возвращает private attachment stream, а не JSON или постоянный media URL. Если позднее мобильный API получит download capability, это потребует отдельной versioned authenticated Resource/short-lived grant contract, а не раскрытия текущего upstream URL.

## Technical issues API boundary

Task 20 не добавляет public/mobile JSON ticket API: private Livewire/session routes являются единственным текущим intake/tracking workflow. Ни один public Resource/OpenAPI/offline-sync response не содержит ticket, requester, diagnostics, attachment, staff note, source-health action или notification preference. Будущий mobile support потребует отдельный versioned authenticated owner Resource и attachment grant contract; текущий UUID/`ISS-…` сам по себе не является API authorization. Полный домен: [`technical-issues.md`](technical-issues.md).
