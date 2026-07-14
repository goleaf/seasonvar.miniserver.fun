# Авторизация

Обновлено: 14.07.2026

## Правила

- Публичные страницы каталога, карточек, sitemap, RSS, OpenSearch и `llms.txt` доступны гостям.
- Операционные и диагностические write/import-control endpoints не должны оставаться публичными.
- `/stats` доступен гостям как read-only Livewire-страница состояния каталога; чувствительные raw URLs, stack traces и внутренние технические имена не выводятся.
- `/admin/imports` защищён route middleware и gate `manage-seasonvar-imports`; allowlist берётся из `SEASONVAR_IMPORT_ADMIN_EMAILS`. Каждый Livewire action повторно применяет gate и не принимает user ID от browser.
- `/admin/catalog` защищён route middleware и gate `manage-catalog` из того же allowlist. Livewire повторяет gate при mount/render, а `CatalogAdministrationService` применяет `CatalogTitlePolicy` до каждой записи; episode/media IDs всегда разрешаются внутри выбранной title hierarchy.
- В проекте пока нет role/permission и admin-login пакета, поэтому email allowlist — узкая операционная граница, а не новая RBAC-система. Пустой allowlist закрывает доступ всем.
- Blade-шаблоны не принимают решений авторизации. Допустимы только простые display checks: `@can`, `@cannot`, `@auth`, `@guest`.
- Список просмотра, user rating и progress карточки доступны только authenticated user и проходят `CatalogTitlePolicy::interact` внутри write-сервиса; policy повторно применяет SQL-ограничение `CatalogEntitlementService` независимо от видимости controls. Идентификатор владельца берётся только из authenticated session. Скрытый, неопубликованный или недоступный тайтл нельзя добавить в список или оценить.
- `/watching` доступен только authenticated user. `CatalogViewingActivityQuery` начинает обе выборки с `whereBelongsTo($user)`, а `EpisodeViewProgressPolicy` отдельно защищает удаление одной записи и полную очистку; web отклоняет чужую запись как 403. Mobile API сначала разрешает progress внутри relation владельца, поэтому чужой numeric ID маскируется как 404 и не изменяется.
- Playable source не является публичным полем модели. Livewire получает только `PlaybackSourceData` с короткоживущим signed URL; `/playback/{licensedMedia}` сверяет подпись, viewer с текущей сессией и повторно получает entitlement decision для всей publication hierarchy, поэтому прямой URL не обходит снятие с публикации или смену audience.
- Admin source editor также не возвращает сохранённый playback URL в HTML/Livewire snapshot. Новый URL проходит HTTPS/provider allowlist, а существующий может только сменить разрешённые метаданные или reversible publication state.
- Laravel Sanctum является единственной mobile token boundary. `User` хранит только hashed personal access token, каждый token имеет ограниченные `mobile:read`/`mobile:write` abilities и expiry не более 90 дней; plaintext допустим только в issuance/rotation response и не восстанавливается из базы.
- Mobile token не получает admin/import abilities и не заменяет существующие gates/policies. Наличие Bearer token не даёт автоматического доступа к authenticated-audience или write операциям: route ability, verification и domain policy проверяются отдельно на соответствующей границе.
- Mobile self-service не принимает user ID: `/api/v1/me` всегда получает owner из `auth:sanctum`, read требует `mobile:read`, а profile/password/delete и device revocation — также `mobile:write`. Device ID разрешается только через relation текущего пользователя, поэтому чужой ID возвращает 404 и не раскрывает существование token.
- Mobile playback session доступна гостю только для public audience. Если передан Bearer token, optional Sanctum boundary требует валидный token с `mobile:read` и не разрешает fallback к guest; authenticated audience по-прежнему решает `CatalogEntitlementService`, а не сам token. Progress разрешён только verified пользователю с `mobile:write`.
- Публичные `GET /api/v1/sync/manifest` и `/sync/changes` содержат только catalog invalidations. `GET /api/v1/me/sync` требует `mobile:read`, привязывает подписанный cursor к authenticated owner и никогда не принимает user/profile ID. `POST /api/v1/me/sync` дополнительно требует `mobile:write` и verified email; каждая операция снова проходит visibility, policy, hierarchy ownership и playback-session проверки существующих доменных сервисов.
- Offline optimistic version не является разрешением доступа и не доверяется как глобальный sequence. Watchlist/rating mutation сравнивает owner-scoped version под row lock; чужой или устаревший идентификатор не меняет состояние. `history.delete` начинает lookup с relation текущего пользователя, а progress повторно связывает title/episode/media с authenticated owner.
- Signed mobile delivery URL авторизует только один media grant на короткое время и не является reusable login credential. Grant привязан к nullable user ID и media ID; endpoint повторно проверяет существование user и полный entitlement непосредственно перед provider redirect, поэтому удаление аккаунта, снятие релиза с публикации или отзыв доступа действует на уже выданный URL.
- Email verification не ставится route middleware на `/api/v1/me`: unverified пользователь сохраняет доступ к чтению своего профиля, исправлению email, повторной отправке письма, выходу и удалению аккаунта. Private catalog state также остаётся читаемым после смены email. `verified.api` явно защищает watchlist/rating и history mutations стабильной ошибкой `email_not_verified`; token и скрытая UI-кнопка эту границу не заменяют.
- Password reset отзывает все tokens; обычная смена пароля сохраняет только текущий token; rotation удаляет прежний token после успешного создания замены. Logout current/all и owner-scoped device delete имеют разный, зафиксированный тестами охват.

## Реализация

- Route `/stats` доступен без авторизации, потому что отдаёт только очищенную read-only сводку.
- Livewire update route для stats-polling использует стандартный `web` middleware stack; отдельная авторизация не добавляется, потому что компонент только читает уже очищенный snapshot.
- Тесты `AuthorizationTest` покрывают гостевой доступ к основным страницам каталога и странице статистики.
- `AuthenticationTest`, `EmailVerificationAndPasswordResetTest`, `DeviceTokenManagementTest` и `AccountManagementTest` покрывают Bearer abilities, unverified/verified доступ, token lifecycle, cross-user device ID и self-service side effects. `UserTitleStateTest`, `UserLibraryTest` и `ViewingActivityTest` отдельно фиксируют owner isolation, скрытые тайтлы, verified mutations, private cache и чужой progress ID.
- Implicit binding `CatalogTitle` скрывает authenticated-audience карточку от гостя; Livewire дополнительно держит `catalogTitleId` locked и разрешает episode/media IDs только внутри доступной и playable иерархии выбранного тайтла.
- Текущая схема поддерживает audience `public/authenticated`; текущий `User` является единственным активным профилем, поэтому все private actions используют его напрямую и не принимают profile ID. Отдельных profile ownership/age/PIN, role/admin preview, territory, subscription/purchase/trial и concurrent-stream сущностей пока нет. `CatalogEntitlementDecision` задаёт однозначные user-facing состояния для будущих отказов, но сервис не имитирует отсутствующие правила. Их нужно подключать к нему одновременно с появлением реальной доменной модели; PIN в таком расширении должен храниться только как hash.

## Осознанные границы продукта

Household/детские профили, PIN, billing, подписки/покупки/trial, territory и concurrent-stream enforcement отсутствуют как продуктовые возможности, а не являются скрытым implementation backlog. Их нельзя «включить» новым config flag: каждое расширение требует отдельного владельца продукта, политики хранения/legal review, additive schema, ownership constraints, backfill и тестов authorization boundary.
