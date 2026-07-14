# Мобильный API v1 для Seasonvar

Дата: 14.07.2026

## Цель

Создать полный стабильный JSON API для нативного мобильного приложения поверх существующего Laravel-каталога: публичная главная, поиск, фильтры, справочники, карточки, сезоны, серии, рекомендации и отзывы; регистрация и token-auth; пользовательский список просмотра, оценки, Continue Watching, история и прогресс; безопасная выдача playback без раскрытия внутренних source/importer данных.

API должен переиспользовать действующие catalog, entitlement, playback и user-state boundaries, а не создавать параллельную бизнес-логику. Существующий web-портал и текущие API-клиенты сохраняют совместимость.

## Подтверждённые продуктовые решения

- Мобильный API включает публичные и пользовательские функции.
- Административное управление каталогом и импортом не открывается через mobile API.
- Разрешено добавить официальную production dependency `laravel/sanctum`.
- Регистрация открыта и принимает имя, email и пароль.
- Новый контракт версионируется под `/api/v1`; `GET /api` служит discovery-манифестом.
- Текущие `/api/titles`, `/api/titles/{slug}` и `/api/catalog/people` сохраняются без breaking changes.
- Подтверждение email обязательно перед записью списка просмотра, оценок, истории и прогресса.
- Видимый пользователю текст остаётся русским; машинные codes и enum values остаются стабильными английскими идентификаторами.

## Рассмотренные варианты

### Неверсионированное расширение `/api/*`

Самый короткий URL и минимальная начальная структура, но breaking changes будущего mobile-контракта нельзя безопасно отделить от уже работающих клиентов. Вариант отклонён.

### Перенос текущего API в `/api/v1/*`

Даёт чистое дерево маршрутов, но ломает существующие `/api/titles` и `/api/catalog/people`. Вариант отклонён.

### Discovery `/api` + новый `/api/v1/*` + legacy compatibility

Выбранный вариант. Он даёт мобильному клиенту стабильную версию, позволяет добавить `/api/v2` при реальном breaking change и не меняет текущий публичный контракт.

Для mobile auth выбран Sanctum personal access token, а не cookie session и не OAuth/Passport. Это соответствует first-party mobile client, поддерживает Bearer-токены, device names, abilities, expiration и отзыв без введения OAuth client lifecycle.

## Архитектурные границы

- `routes/api.php` остаётся единой точкой API-маршрутов. Новый v1 группируется через `Route::prefix('v1')->name('api.v1.')`.
- Контроллеры выполняют только request orchestration, authorization и выбор Resource/response.
- Любой нетривиальный input проходит через отдельный Form Request с русскими сообщениями.
- Eloquent-модели и коллекции не возвращаются напрямую; публичная форма принадлежит API Resources.
- Query/service boundaries используют существующие `CatalogTitleQuery`, `CatalogTitlesCriteria`, `CatalogFacetQuery`, `CatalogDirectoryQuery`, `CatalogTitlePageBuilder`, `CatalogTitlePlaybackQuery`, `CatalogPlaybackSourceResolver`, `CatalogPrimaryActionResolver`, `CatalogUserStateService`, `CatalogViewingActivityQuery` и policies.
- Общая filter parsing/query logic извлекается или переиспользуется так, чтобы Livewire и API применяли одинаковую нормализацию, allowlists и visibility scopes.
- API не выполняет queries из Resources и не возвращает lazy-loaded relations.
- Новые multi-table записи выполняются транзакционно; token rotation и account deletion атомарны.
- Добавляются только additive migrations: Sanctum personal access tokens и, если потребуется для mobile email flow, минимальные additive данные, не дублирующие стандартный password reset broker.

## Версия и discovery

### `GET /api`

Возвращает:

- название сервиса;
- текущую стабильную версию `v1`;
- base URL `/api/v1`;
- ссылку `/api/openapi.json`;
- список верхнеуровневых возможностей;
- минимально поддерживаемую версию mobile-контракта.

Discovery является небольшим cacheable JSON-ответом и не раскрывает framework version, database driver, importer/queue state или environment.

### `GET /api/openapi.json`

Возвращает project-owned OpenAPI 3.x document со схемами, query parameters, Bearer security scheme, response examples и стабильными error codes. Спецификация хранится в репозитории, проверяется тестом на валидный JSON и соответствие ключевым route names. Новая runtime documentation dependency не требуется.

### `GET /api/v1/health`

Возвращает только `status`, `server_time` и `api_version`. Database, cache, queue, importer и filesystem diagnostics не включаются.

### `GET /api/v1/config`

Возвращает presentation-safe configuration:

- locale и timezone contract;
- диапазон пользовательской оценки;
- поддерживаемые playback qualities/formats;
- максимальный `per_page`;
- рекомендуемый progress heartbeat interval;
- срок действия playback URL/session в безопасной клиентской форме.

## Публичный каталог

### Главная

`GET /api/v1/home` возвращает bounded sections:

- latest titles;
- featured titles;
- titles with available video;
- latest released media/episodes без source URL;
- популярные genres/countries;
- year buckets;
- публичные catalog counts.

Выдача переиспользует snapshot/cache и visibility rules `CatalogHomePageBuilder`. Внутренние метрики качества данных, импорта и инфраструктуры исключены.

### Схема фильтров

`GET /api/v1/catalog/filters` возвращает описание controls, достаточное для построения мобильного filter UI:

- filter keys и тип значений;
- sort values и русские labels;
- rating sources;
- video/subtitle/publication/updated options;
- quality values;
- bounds для года, сезонов, серий, рейтинга и голосов;
- отдельные `alphabet.cyrillic`, `alphabet.latin` и `alphabet.other`.

`latin` содержит отдельные `A`–`Z`. Legacy `letter=latin` продолжает означать всю латиницу, но новый mobile UI использует отдельные буквы. `#` остаётся отдельным фильтром не-буквенных названий.

### Справочники

- `GET /api/v1/catalog/directories`
- `GET /api/v1/catalog/directories/{directory}`

Поддерживаемые directory keys: `genres`, `countries`, `actors`, `directors`, `age-ratings`, `translations`, `statuses`, `networks`, `studios`, `tags`, `years`.

Detail endpoint принимает allowlisted `q`, `letter`, `sort`, `decade`, `page`, `per_page`. Алфавит возвращается двумя отдельными группами кириллицы и латиницы только для directory, где он поддержан. Items содержат публичные id/name/slug/count и не содержат provider/source identity.

### Поиск и список тайтлов

`GET /api/v1/titles` принимает:

- `q`, `page`, `per_page`, `sort`, `letter`;
- `year[]`, `year_from`, `year_to`;
- `genre[]`, `country[]`, `actor[]`, `director[]`, `age_rating[]`, `translation[]`, `status[]`, `network[]`, `studio[]`, `tag[]`;
- `exclude_country[]`, `exclude_genre[]`;
- `seasons_min`, `seasons_max`, `episodes_min`, `episodes_max`;
- `rating_source`, `rating_min`, `votes_min`;
- `video`, `subtitles[]`, `quality[]`, `publication_type[]`, `updated`.

Laravel array syntax с индексами и без них поддерживается одинаково: `country[]=turciia` и `country[0]=turciia`. Максимум одного filter group остаётся ограниченным существующими validation rules. `per_page` для v1 допускает 1–50; web-specific `view` не входит в API-критерии.

Ответ содержит title card Resources и стандартные `links/meta`. Search metadata может включать нормализованный query и безопасную suggestion, но не search-index internals, BM25 score или debug state.

`GET /api/v1/search/suggestions?q=...` возвращает bounded title suggestions и people options только после существующей минимальной длины/нормализации.

### Карточка и дочерние данные

- `GET /api/v1/titles/{catalogTitle:slug}`
- `GET /api/v1/titles/{catalogTitle:slug}/seasons`
- `GET /api/v1/titles/{catalogTitle:slug}/seasons/{season}/episodes`
- `GET /api/v1/titles/{catalogTitle:slug}/recommendations`
- `GET /api/v1/titles/{catalogTitle:slug}/reviews`

Title detail включает public identity, aliases, descriptions, poster, type/year, provider ratings, aggregate user rating, taxonomies, counts, primary action and safe links. При валидном optional Bearer token добавляется состояние текущего пользователя; guest response не меняет public visibility.

Seasons/episodes возвращают только дочерние releases, принадлежащие выбранному тайтлу и доступные текущему audience. Safe media profiles содержат id, variant, translation, quality, format и availability, но не `path`, `playback_url`, source URL, storage disk, health internals или keys.

Recommendations возвращают карточки, rank и русские reason labels, но не algorithm weights, score breakdown, signals или importer state.

Reviews являются read-only imported content. Resource возвращает id, author, body и published date; `source_page_id`, hashes и source metadata исключены. Endpoint пагинируется.

## Авторизация и жизненный цикл аккаунта

### Sanctum

`laravel/sanctum` устанавливается как согласованная production dependency. `User` использует `HasApiTokens`; protected routes используют `auth:sanctum`. Mobile передаёт `Authorization: Bearer <token>` и `Accept: application/json`.

Plain token возвращается только при регистрации, входе и rotation. В базе хранится Sanctum hash. Token name формируется из валидированного client `device_name`; abilities ограничиваются mobile read/write, но policies остаются обязательной resource authorization boundary.

Срок token — 90 дней. `POST /api/v1/auth/token/refresh` в транзакции создаёт новый token и отзывает текущий только после успешного создания. Expired rows ежедневно удаляются стандартной scheduled prune command.

### Guest auth endpoints

- `POST /api/v1/auth/register`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/forgot-password`
- `POST /api/v1/auth/reset-password`
- `GET /api/v1/auth/email/verify/{id}/{hash}` с signed middleware

Registration принимает `name`, `email`, `password`, `password_confirmation`, `device_name`. Email нормализуется, уникален case-insensitively в границах фактической database behavior, пароль проходит Laravel password rule. Ответ создаёт token, возвращает UserResource и отправляет verification notification.

Login использует одинаковую validation error форму для неизвестного email и неверного пароля. Authentication events сохраняются; password/token не логируются.

Forgot-password всегда возвращает одинаковый публичный success response, чтобы не раскрывать наличие account. Reset использует стандартный password broker и после успеха отзывает существующие mobile tokens.

Signed verification link подтверждает email и возвращает безопасный JSON/browser completion response, пригодный для universal/app link integration. Конкретная mobile deep-link схема не hardcode-ится до появления mobile repository.

### Authenticated endpoints

- `POST /api/v1/auth/token/refresh`
- `POST /api/v1/auth/logout`
- `POST /api/v1/auth/logout-all`
- `GET /api/v1/auth/devices`
- `DELETE /api/v1/auth/devices/{token}`
- `POST /api/v1/auth/email/verification-notification`
- `GET /api/v1/me`
- `PATCH /api/v1/me`
- `PATCH /api/v1/me/password`
- `DELETE /api/v1/me`

Devices возвращают token id, recognizable name, last used and expiry timestamps; token hash/secret never appears. User может отозвать только свои tokens. Logout current удаляет current access token, logout-all удаляет все.

Изменение email очищает `email_verified_at`, требует повторного подтверждения и не меняет owner identity. Смена пароля требует current password и отзывает остальные tokens. Удаление аккаунта требует current password, выполняется транзакционно, отзывает tokens и использует действующие FK/cascade rules для private user state.

Неподтверждённый user может читать `/me` и уже принадлежащее ему private state, управлять текущей авторизацией и повторно запросить письмо. Все state mutations и запись progress дополнительно используют `verified` boundary и стабильную ошибку `email_not_verified`; private reads требуют аутентификацию, но не повторное подтверждение после смены email.

## Пользовательское состояние

- `GET /api/v1/me/watchlist`
- `PUT /api/v1/me/watchlist/{catalogTitle:slug}`
- `DELETE /api/v1/me/watchlist/{catalogTitle:slug}`
- `GET /api/v1/me/ratings`
- `PUT /api/v1/me/ratings/{catalogTitle:slug}`
- `DELETE /api/v1/me/ratings/{catalogTitle:slug}`
- `GET /api/v1/me/titles/{catalogTitle:slug}/state`
- `GET /api/v1/me/continue-watching`
- `GET /api/v1/me/history`
- `DELETE /api/v1/me/history/{episodeViewProgress}`
- `DELETE /api/v1/me/history`

Watchlist PUT/DELETE задаёт желаемое состояние и остаётся idempotent. Отдельной favorites table не вводится: действующий product contract считает watchlist/избранное одним состоянием.

Rating PUT принимает один integer из configured range; DELETE очищает значение. Provider ratings не смешиваются с user rating aggregate.

State detail возвращает `in_watchlist`, personal rating, aggregate count/average и primary action `start`, `continue`, `next`, `replay`, `title-media` или `unavailable` с безопасными child ids.

Continue Watching возвращает не более 24 items и использует существующий canonical progress/next-episode query. History имеет стабильную пагинацию, сообщает accessibility текущего release и позволяет удалять только собственную строку. Clear history затрагивает только текущего user.

## Mobile playback

### Создание playback session

`POST /api/v1/titles/{catalogTitle:slug}/playback-sessions` принимает:

- optional `episode_id` и `media_id`;
- optional `variant`, `audio_language`, `quality`, `format` preferences.

Сервис повторно проверяет title, season, episode, media ownership, publication, audience, availability window, source health и URL allowlist. Requested ids не могут пересечь title boundary.

Success response содержит:

- playback availability status/message;
- selected title/season/episode/media public profile;
- same-origin short-lived signed playback URL;
- MIME/format/quality/variant;
- expiry timestamp;
- previous/next episode navigation;
- для verified user — encrypted progress session token и heartbeat metadata.

JSON никогда не содержит raw external provider URL или local storage path. Временный same-origin URL несёт только signed/opaque grant, повторно проверяет media и entitlement и затем выполняет private no-store redirect/stream. Для authenticated-only release grant связывается с user identity; bearer token не помещается в query string.

Guest может создать session только для public content. Authentication-required возвращает `401` и code `authentication_required`; прочие существующие playback states сохраняют стабильные enum codes и подходящие HTTP statuses.

### Прогресс

`PUT /api/v1/titles/{catalogTitle:slug}/episodes/{episode}/progress` принимает:

- `playback_session_token`;
- `event_sequence` >= 1;
- `position_seconds` >= 0;
- `reported_duration_seconds` >= 0;
- `ended` boolean.

Endpoint доступен только verified user. Existing `CatalogUserStateService::recordProgress()` остаётся owner canonical behavior: session привязан к user/title/episode/media и TTL, server media duration является trusted, stale/out-of-order события не перезаписывают новое состояние, completion не снимается replay-событием. Response возвращает canonical progress Resource, а не молчаливое предположение client.

## Формат ответов

### Успех

Single resource:

```json
{
  "data": {}
}
```

Paginated collection:

```json
{
  "data": [],
  "links": {},
  "meta": {}
}
```

Mutation может вернуть `200` с canonical Resource или `204` для успешного удаления без полезного body. Created registration/playback session использует `201`.

### Ошибка

```json
{
  "code": "validation_failed",
  "message": "Переданные данные некорректны.",
  "errors": {
    "email": ["Укажите корректный email."]
  },
  "request_id": "01..."
}
```

`errors` присутствует только при field errors. Известные codes включают `validation_failed`, `unauthenticated`, `email_not_verified`, `forbidden`, `not_found`, `rate_limited`, playback availability values и `server_error`. Stack trace, SQL, local path и exception message не возвращаются.

Request ID принимается только из безопасного allowlisted incoming format или генерируется сервером, добавляется в response header/body и logging context. Secret-bearing request fields redacted.

Dates возвращаются в ISO 8601 с timezone/UTC contract. Nullable fields остаются явными там, где `null` имеет доменный смысл. IDs являются integers, slugs — public strings; clients не должны строить ownership из numeric ids.

## Cache и HTTP semantics

- Anonymous successful safe GET использует действующий `public.cache:api`, ETag, Last-Modified, SWR и `304`.
- Любой запрос с resolved user, cookie, Authorization header, write method, error или Set-Cookie получает `private, no-store` и не сохраняет public validators.
- Auth, `/me`, playback session, playback delivery и progress всегда private/no-store.
- Public cache Vary продолжает включать `Accept` и `Accept-Encoding`; Authorization responses никогда не разделяются shared cache.
- Pagination links сохраняют все нормализованные query parameters.
- Mobile API не получает browser scroll behavior.

## Security и privacy

- Auth endpoints получают отдельные named rate limiters для register, login, forgot password, reset, resend verification и token refresh. Это не возвращает удалённый общий throttle для публичного каталога.
- Form Requests ограничивают длины strings, selection counts, page sizes, numeric ranges, enum values и array shapes.
- Protected resources используют policies/gates и owner-scoped queries; route binding сам по себе не считается authorization.
- Sanctum abilities дополняют, но не заменяют policies.
- Email enumeration исключается из login/forgot flows настолько, насколько совместимо с validation UX.
- Passwords, Bearer tokens, reset/verification tokens, playback/progress grants, raw media URLs и importer data редактируются из logs/errors.
- API не вводит billing, подписки, household/child profiles, PIN, territory или concurrent stream simulation: таких product models сейчас нет.
- CORS не открывается wildcard credentials. Native mobile не зависит от browser CORS; будущий web client получит отдельную allowlist-конфигурацию.
- Account deletion и token revocation проверяются на cross-user IDOR.

## Производительность

- List Resources получают только выбранные columns, eager-loaded public relations и `withCount()` aggregates.
- Title detail, filters, directories, recommendations, watchlist and history имеют query-budget tests на representative fixtures.
- Reviews и все потенциально растущие user collections пагинируются.
- Home sections bounded и используют текущий snapshot/cache.
- Filters/facets не выполняют отдельный полный count на каждый item вне существующей grouped query boundary.
- No database queries are allowed from API Resources.

## Совместимость и версионирование

- Legacy routes и response shapes не изменяются.
- `/api/v1` считается отдельным стабильным contract.
- В v1 допускаются только additive optional fields/endpoints и расширение enum только там, где client обязан обрабатывать unknown value.
- Rename, removal, meaning change, nullable/type change или pagination/error envelope change требует `/api/v2`.
- Historical title slug behavior и public visibility remain shared with web route binding/query boundaries.

## Тестовая стратегия

TDD начинается с failing feature tests и узких unit tests. Обязательные группы:

1. Discovery, OpenAPI, config и safe health response.
2. Home, full title filter parity, both Laravel array syntaxes, separate Cyrillic/Latin alphabets, suggestions, directories, title details, seasons/episodes, recommendations and reviews.
3. Legacy API compatibility and public cache/conditional requests.
4. Registration/login validation, hashed token storage, Russian errors, device names, expiry, rotation, current/all/specific revoke.
5. Verification email, resend throttle, unverified denial, signed verification and password reset without enumeration.
6. `/me` read/update/password/delete, token revocation and database side effects.
7. Watchlist/rating desired state, range validation, aggregates, multi-user isolation and hidden title denial.
8. Continue Watching/history pagination, deletion/clear policies and inaccessible historical releases.
9. Playback guest/authenticated audience, exact child ownership, preferences, safe same-origin grant, expiry/tamper/cross-user denial and absence of raw source values.
10. Progress first event, duplicate/out-of-order events, replay, completion, expired/tampered session, trusted duration and cross-user isolation.
11. JSON fallback/error envelope/request ID, 404/401/403/422/429/5xx sanitization.
12. Query counts/N+1, `private, no-store` personalization and absence of sensitive fields across serialized responses.

External email/HTTP behavior uses Laravel fakes and stray requests remain blocked in relevant tests. После focused suites выполняются `./vendor/bin/pint --dirty --format agent`, полный `php artisan test`/`./vendor/bin/phpunit` и `npm run build` только если затронуты frontend assets или Blade asset assumptions.

## Документация

- `docs/api.md` становится владельцем стабильного route/response/versioning contract.
- `docs/architecture.md` описывает API controllers/query/service/resource boundaries.
- `docs/authorization.md` описывает Sanctum, verified boundary и mobile playback grant.
- `docs/security.md` фиксирует rate limiting, secret redaction и IDOR boundaries.
- `docs/DATA_RELATIONS.md` фиксирует personal access token/account lifecycle только там, где меняются реальные связи.
- `docs/testing.md`, `README.md` и `CHANGELOG.md` обновляются синхронно с поведением.
- OpenAPI JSON входит в project-owned documentation и тестируется.
- Managed `project-docs` blocks вручную не редактируются.

## Этапы реализации

1. API foundation: Sanctum install/migration, version/discovery, common error envelope, request ID, Resources and OpenAPI skeleton.
2. Public catalog: home/config/filters/directories/search/title children/recommendations/reviews with cache and compatibility tests.
3. Auth/account: registration, verification, login, reset, device/token lifecycle and profile mutations.
4. User state: watchlist, ratings, state detail, Continue Watching and history.
5. Playback/progress: short-lived opaque grants, session creation, secure delivery and canonical progress endpoint.
6. Full privacy/performance/compatibility verification, documentation and production-safe migration rollout.

Каждый этап оформляется небольшими причинно связанными commit-ами на существующей `main`. Реализация не считается завершённой, пока весь v1 contract, документация и полный test suite не зелёные.

## Не входит в v1

- admin/import/catalog mutation API;
- source snapshots, importer runs/events, queue controls и diagnostics;
- raw playback/source URLs, storage paths или downloadable video files;
- user-written reviews/comments/moderation, потому что текущие reviews импортируются и read-only;
- billing, subscriptions, purchases, territories, household/child profiles, PIN и concurrent-stream enforcement без соответствующих product models;
- social login, OAuth third-party clients и push notifications;
- mobile UI/repository, universal-link provisioning и app-store configuration;
- изменение web UI, URL или pagination behavior;
- отдельный favorites model, пока product contract считает его watchlist.
