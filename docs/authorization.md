# Авторизация

Обновлено: 16.07.2026

## Правила

- Публичные страницы каталога, карточек, sitemap, RSS, OpenSearch и `llms.txt` доступны гостям.
- Операционные и диагностические write/import-control endpoints не должны оставаться публичными.
- `/stats` доступен гостям как read-only Livewire-страница состояния каталога; чувствительные raw URLs, stack traces и внутренние технические имена не выводятся.
- `/admin/imports` защищён route middleware и gate `manage-seasonvar-imports`; allowlist берётся из `SEASONVAR_IMPORT_ADMIN_EMAILS`. Каждый Livewire action повторно применяет gate и не принимает user ID от browser.
- `/admin/catalog` защищён route middleware и gate `manage-catalog` из того же allowlist. Livewire повторяет gate при mount/render, а `CatalogAdministrationService` применяет `CatalogTitlePolicy` до каждой записи; episode/media IDs всегда разрешаются внутри выбранной title hierarchy.
- В проекте пока нет role/permission и admin-login пакета, поэтому email allowlist — узкая операционная граница, а не новая RBAC-система. Пустой allowlist закрывает доступ всем.
- Blade-шаблоны не принимают решений авторизации. Допустимы только простые display checks: `@can`, `@cannot`, `@auth`, `@guest`.
- Web registration/login/password recovery реализованы Livewire-компонентами на стандартной session boundary. Гостевые routes закрыты `guest`; `/email/verify`, `/confirm-password`, `/profile`, `/profile/security` и `/library/*` защищены одновременно `auth` и `auth.session`, поэтому сменившийся password hash завершает устаревшую browser session. Successful login регенерирует session ID, logout инвалидирует session и обновляет CSRF token.
- Signed `/email/verify/{id}/{hash}` подтверждает адрес владельца ссылки без автоматического входа: уже вошедший владелец переходит в библиотеку, остальные — на login. Страница `/email/verify` остаётся доступной unverified user для повторной отправки. Password reset и смена пароля используют общие account-сервисы с API: reset отзывает все mobile tokens, web-смена пароля отзывает API tokens и сохраняет текущую browser session.
- Профиль не принимает user ID и всегда изменяет только текущего `User`. Смена email сбрасывает `email_verified_at`, удаляет прежние password-reset rows и ставит новое письмо в очередь. Security actions требуют текущий пароль там, где меняют credentials, завершают только чужие browser sessions, owner-scoped отзывают mobile devices либо удаляют аккаунт со всеми принадлежащими ему приватными строками.
- Список просмотра, user rating, история и progress читаются authenticated user, но все mutations требуют verified email и проходят `CatalogTitlePolicy::interact` либо `EpisodeViewProgressPolicy`. Policy повторно применяет SQL-ограничение `CatalogEntitlementService` независимо от видимости controls. Идентификатор владельца берётся только из authenticated session. Скрытый, неопубликованный или недоступный тайтл нельзя добавить в список или оценить.
- `/library/*` доступен только authenticated user. Library/activity queries начинают выборки с текущего владельца; удаление web history сначала разрешает ID внутри relation пользователя и поэтому маскирует чужую запись как 404. Полная очистка затрагивает только фактическую activity текущего пользователя. `/watching` является совместимым redirect на `/library/continue-watching`; mobile API применяет ту же owner-scoped 404 границу.
- Playable source не является публичным полем модели. Livewire получает только `PlaybackSourceData` с короткоживущим signed URL; `/playback/{licensedMedia}` сверяет подпись, viewer с текущей сессией и повторно получает entitlement decision для всей publication hierarchy, поэтому прямой URL не обходит снятие с публикации или смену audience.
- Admin source editor также не возвращает сохранённый playback URL в HTML/Livewire snapshot. Новый URL проходит HTTPS/provider allowlist, а существующий может только сменить разрешённые метаданные или reversible publication state.
- Laravel Sanctum является единственной mobile token boundary. `User` хранит только hashed personal access token, каждый token имеет ограниченные `mobile:read`/`mobile:write` abilities и expiry не более 90 дней; plaintext допустим только в issuance/rotation response и не восстанавливается из базы.
- Mobile token не получает admin/import abilities и не заменяет существующие gates/policies. Наличие Bearer token не даёт автоматического доступа к authenticated-audience или write операциям: route ability, verification и domain policy проверяются отдельно на соответствующей границе.
- Mobile self-service не принимает user ID: `/api/v1/me` всегда получает owner из `auth:sanctum`, read требует `mobile:read`, а profile/password/delete и device revocation — также `mobile:write`. Device ID разрешается только через relation текущего пользователя, поэтому чужой ID возвращает 404 и не раскрывает существование token.
- Mobile playback session доступна гостю только для public audience. Если передан Bearer token, optional Sanctum boundary требует валидный token с `mobile:read` и не разрешает fallback к guest; authenticated audience по-прежнему решает `CatalogEntitlementService`, а не сам token. Progress разрешён только verified пользователю с `mobile:write`.
- Публичные `GET /api/v1/sync/manifest` и `/sync/changes` содержат только catalog invalidations. `GET /api/v1/me/sync` требует `mobile:read`, привязывает подписанный cursor к authenticated owner и никогда не принимает user/profile ID. `POST /api/v1/me/sync` дополнительно требует `mobile:write` и verified email; каждая операция снова проходит visibility, policy, hierarchy ownership и playback-session проверки существующих доменных сервисов.
- Offline optimistic version не является разрешением доступа и не доверяется как глобальный sequence. Watchlist/rating mutation сравнивает owner-scoped version под row lock; чужой или устаревший идентификатор не меняет состояние. `history.delete` начинает lookup с relation текущего пользователя, а progress повторно связывает title/episode/media с authenticated owner.
- Signed mobile delivery URL авторизует только один media grant на короткое время и не является reusable login credential. Grant привязан к nullable user ID и media ID; endpoint повторно проверяет существование user и полный entitlement непосредственно перед provider redirect, поэтому удаление аккаунта, снятие релиза с публикации или отзыв доступа действует на уже выданный URL.
- Email verification не ставится route middleware на web `/profile`/`/library/*` или API `/api/v1/me`: unverified пользователь сохраняет доступ к чтению своего профиля и библиотеки, исправлению email, повторной отправке письма, выходу и удалению аккаунта. `CatalogTitlePolicy::interact`, `EpisodeViewProgressPolicy` и `verified.api` явно защищают watchlist/rating/progress/history mutations; token и скрытая UI-кнопка эту границу не заменяют.
- Password reset отзывает все tokens; обычная смена пароля сохраняет только текущий token; rotation удаляет прежний token после успешного создания замены. Logout current/all и owner-scoped device delete имеют разный, зафиксированный тестами охват.

## Матрица аутентификации

| Операция | Guest | Authenticated owner | Дополнительная граница |
| --- | --- | --- | --- |
| Register/login/recovery/reset | да, если route включён | guest middleware исключает повторный вход | named layered limiter, generic failures, normalized email |
| Verification callback | владелец signed link без auto-login | только тот же user получает owner redirect | temporary signature, ID и email hash |
| Resend verification | нет | только unverified owner | throttle, locale-aware notification |
| Изменить name | нет | да | explicit allowlist и plain-text normalizer |
| Изменить email | нет | да | current password, uniqueness, reset-token deletion, verification reset, remember rotation |
| Изменить password | нет | да | current password, shared policy, remember rotation, other access revocation |
| Logout | нет | да | Livewire POST/CSRF, session invalidate, CSRF rotation |
| Revoke browser/mobile session | нет | только owner | current password в web UI; opaque session identity или owner token relation |
| Export/delete | нет | только owner | password confirmation; private/no-store; canonical lifecycle service |
| Social/link/unlink/merge/MFA/magic link | отсутствует | отсутствует | capability не существует и не симулируется control-ом |

`AUTH_REGISTRATION_ENABLED=false` удаляет create routes web/API при route build; login, recovery, verification и существующие accounts продолжают работать. Обычный unverified user может войти и управлять безопасными account actions, но verified domain policies по-прежнему запрещают catalog writes. Suspended/disabled/deleted status model в проекте отсутствует; hard-deleted row не может пройти Eloquent provider или Sanctum ownership resolution.

## Реализация

- Route `/stats` доступен без авторизации, потому что отдаёт только очищенную read-only сводку.
- Livewire update route для stats-polling использует стандартный `web` middleware stack; отдельная авторизация не добавляется, потому что компонент только читает уже очищенный snapshot.
- Тесты `AuthorizationTest` покрывают гостевой доступ к основным страницам каталога и странице статистики. Web auth/profile/security/library suites фиксируют guest redirects, session regeneration/invalidation, verification, non-enumerating recovery, current-password boundaries, owner isolation и verified mutations.
- `AuthenticationTest`, `EmailVerificationAndPasswordResetTest`, `DeviceTokenManagementTest` и `AccountManagementTest` покрывают Bearer abilities, unverified/verified доступ, token lifecycle, cross-user device ID и self-service side effects. `UserTitleStateTest`, `UserLibraryTest` и `ViewingActivityTest` отдельно фиксируют owner isolation, скрытые тайтлы, verified mutations, private cache и чужой progress ID.
- Implicit binding `CatalogTitle` скрывает authenticated-audience карточку от гостя; Livewire дополнительно держит `catalogTitleId` locked и разрешает episode/media IDs только внутри доступной и playable иерархии выбранного тайтла.
- Текущая схема поддерживает audience `public/authenticated`; текущий `User` является единственным активным профилем, поэтому все private actions используют его напрямую и не принимают profile ID. Отдельных profile ownership/age/PIN, role/admin preview, territory, subscription/purchase/trial и concurrent-stream сущностей пока нет. `CatalogEntitlementDecision` задаёт однозначные user-facing состояния для будущих отказов, но сервис не имитирует отсутствующие правила. Их нужно подключать к нему одновременно с появлением реальной доменной модели; PIN в таком расширении должен храниться только как hash.

## Осознанные границы продукта

Household/детские профили, PIN, billing, подписки/покупки/trial, territory и concurrent-stream enforcement отсутствуют как продуктовые возможности, а не являются скрытым implementation backlog. Их нельзя «включить» новым config flag: каждое расширение требует отдельного владельца продукта, политики хранения/legal review, additive schema, ownership constraints, backfill и тестов authorization boundary.

## Матрица доступа коллекций

`CatalogCollectionPolicy` — единственная authorization boundary коллекций. `view` разрешает approved public/unlisted или owner/admin и иначе отвечает `denyAsNotFound`; private name, count, owner и canonical slug не раскрываются. `create` требует authenticated verified user, `createEditorial`, moderation и feature используют `manage-catalog`; update/delete/restore/force-delete/item/reorder/cover повторно разрешают stable resolved record. System record изменяет только admin, а feature разрешена только approved public editorial collection.

Owner берётся из authenticated actor внутри service; client не передаёт `owner_id`, type/moderation/feature не mass-assignятся из public form. Title ID повторно проходит `CatalogTitlePolicy::interact`/visible query, collection UUID batch сравнивается с полным owner-scoped manageable set, item IDs проверяются внутри locked collection. Report разрешён verified non-owner только для directly public-viewable target и имеет отдельный limiter/deduplication key; moderator decision повторно проверяет gate уже после row lock, поэтому stale Livewire payload не обходит актуальную authority/state boundary.

Private и pending/rejected/hidden/deleted records не дают redirect/canonical metadata постороннему: slug history resolution всегда завершается policy до `301`. Covers повторяют ту же policy. Unlisted — direct-link visibility, не access token и не секретное хранилище; owner management всё равно policy-protected. Collaborators/ownership transfer/follows/collection likes отсутствуют, поэтому не симулируются UI-флагами.

## Матрица доступа обсуждений

| Действие | Guest | Authenticated unverified | Verified owner/user | Moderator |
| --- | --- | --- | --- | --- |
| Читать published на доступной цели | да | да | да с private overlays | да |
| Читать own pending/hidden/rejected/spam | нет | только собственный существующий | да | да |
| Создать comment/reply | нет | нет | да, если нет restriction/block conflict | да на тех же product rules |
| Edit/delete/restore | нет | только прежняя owner-policy при наличии verified state | own, 30 min edit / 7 day restore | только moderation actions, не подмена owner edit |
| React/report | нет | нет | verified non-owner, public non-deleted, no block | по policy; moderation отдельно |
| Block/mute | нет | authenticated actor, self запрещён | да | да как обычный private actor |
| Moderate/report resolve/restrict | нет | нет | нет | `manage-comments` |

`CommentPolicy` и focused actions остаются единственной mutation boundary. Verified requirement, active temporary/permanent restriction и block relation проверяются server-side даже при подделанном Livewire payload. Mute не является блоком: он скрывает presentation и suppress-ит notifications только для muter. Direct `/comments/{id}` сначала проверяет view/target; inaccessible public state отвечает 404, а moderator-only hidden/deleted context перенаправляется в защищённый `/admin/comments?comment={id}`.

`/profile/discussions`, `/notifications` и `/profile/export` защищены `auth` + `auth.session`; export дополнительно `password.confirm` и throttle. Они всегда используют текущего user и не принимают owner/profile ID. `/admin/comments` имеет route gate и повторный gate/policy в component/actions. Comment-only restriction не влияет на login, library, playback, rating, collection или review access.

## Матрица доступа отзывов

| Действие | Guest | Authenticated unverified | Verified owner/user | Moderator |
| --- | --- | --- | --- | --- |
| Читать public review | да на доступном title | да | да, включая own pending overlay | да |
| Создать/edit/restore | нет | нет | own title review, если review feature active и restriction отсутствует | не подменяет owner action |
| Удалить own review | нет | authenticated owner может удалить даже при feature disable/restriction | да | moderation remove отдельно |
| Helpful/report | нет | нет | verified non-owner, public review, no block/restriction | по обычной policy; moderation отдельно |
| Private history/preferences | нет | только текущий account | только текущий account | только собственное состояние |
| Moderate/report resolve/restrict | нет | нет | нет | `manage-reviews` |

`CatalogTitleReviewPolicy` и focused actions повторно проверяют actor, origin, ownership, target visibility, status/deletion/merge, email verification, active review-only restriction и shared directional block. Client не выбирает author, target class, moderation status, verified flag или rating owner. Delete intentionally remains available to the owner when review creation is globally disabled or the user becomes restricted, so privacy removal cannot be trapped by a feature flag.

`/profile/reviews` and review preference/history actions use only current authenticated user. `/admin/reviews` has route gate plus component/action policy. Public `/reviews/{review}` accepts only a positive stable ID, resolves alias, reauthorizes review and current title, then redirects; hidden/deleted/blocked state returns safe not-found and cannot be used as an IDOR oracle. Review restriction affects only review create/edit/restore/vote/report and never login, comments, playback, rating, watchlist, progress, collection or account deletion.

## Матрица доступа тегов

| Действие | Guest | Authenticated unverified | Verified owner/user | Catalog admin/editor |
| --- | --- | --- | --- | --- |
| Читать eligible global tag/page/API | да | да | да | да |
| Читать personal tag/assignment | нет | только собственное read API/UI | только собственное | не раскрывается вне отдельной account/privacy процедуры |
| Create/edit/delete/restore personal | нет | нет | только own stable UUID | не подменяет owner action |
| Assign/remove personal on title | нет | нет | только own active tag + interactable title | не превращает tag в global metadata |
| Create/edit/archive/restore global | нет | нет | нет | `manage-catalog` + `TagPolicy` |
| Translate/alias/synonym/provider moderation | нет | нет | нет | `manage-catalog` + current-record reauthorization |
| Assign/remove global или merge | нет | нет | нет | `manage-catalog`, eligible exact IDs, transaction/audit |

`TagPolicy::view` разрешает только public approved non-internal non-archived non-merged tag; URL resolver дополнительно требует visible title и до policy result не раскрывает canonical slug/count. `TagPolicy` mutations и `/admin/tags` route gate используют `manage-catalog`; Livewire `boot()` повторяет gate на каждом hydration, а service повторно authorizes конкретные source/target/tag/title records. Public requests не могут задавать type, visibility, moderation, code, alias target, provider mapping или arbitrary model class.

`UserTagPolicy` derives ownership from `user_tags.user_id`; non-owner view отвечает как not found. Create/update/restore/assign требуют verified email, owner передаётся в service только из current auth context. API/Livewire принимают UUID tags, но полный requested set сравнивается с owner-scoped active query; title повторно проходит `CatalogTitlePolicy::interact`/`visibleTo(user)`. Delete своей записи остаётся explicit non-GET action, repeat delete/remove idempotent; soft-deleted tag нельзя назначить.

Public user tags, unlisted tags, community global assignment, tag reporting и season/episode assignment отсутствуют как policy surface, потому что продукт их не поддерживает. Administrator не получает отдельную UI-выгрузку private personal labels/counts; account export/delete выполняются только существующим current-user account boundary.

## Матрица доступа профилей пользователей

| Действие | Guest | Active public viewer | Owner | `manage-catalog` |
| --- | --- | --- | --- | --- |
| Public active profile/explicit public section | Да | Да, кроме bilateral block | Да | Да |
| Private/hidden/suspended profile | Нет, safe 404 | Нет, safe 404 | Да | Да |
| Edit details/privacy/media/username | Нет | Нет | Да; username дополнительно требует password/rate limit | Нет через owner form |
| Block/mute | Нет | Только canonical Task 12 action, не self | Нет self-action | По тем же user rules |
| Report profile | Нет | Verified non-owner, public target | Нет | По reporter policy |
| View/resolve report and moderate profile/media/text | Нет | Нет | Нет | Да, с повторной authorization |

Section visibility никогда не отменяет whole-profile/moderation/block check. Unblock остаётся в существующем relationship account UI, поэтому blocked direct profile URL не становится side channel. Viewer overlay не входит в shared cache/SEO.

## Матрица доступа настроек аккаунта

| Действие | Гость | Авторизованный пользователь | Чужой account / администратор |
| --- | --- | --- | --- |
| Открыть `/settings/{section?}` | redirect на login | только own context через `view-account-settings` | user ID в URL отсутствует; impersonation не добавлена |
| Изменить appearance/playback/collection defaults | нет | `update-account-settings`, typed service allowlist | arbitrary owner/key не принимается |
| Изменить comment/review notifications | нет | canonical preference actions для current user | чужой `user_id` не принимается |
| Изменить profile/password/email | нет | существующие `ProfilePage`/`SecurityPage` rules | settings shell не обходит boundary |
| Просмотреть/отозвать browser session | нет | safe summaries; opaque HMAC token повторно разрешается в own sessions | raw session ID не поступает из UI |
| Экспортировать/удалить account | нет | current password и canonical export/delete service | другой account path отсутствует |

`AccountSettingsPolicy` регистрирует self-context gates; service повторяет authorization. Normal settings URL никогда не принимает user ID, role, premium state, provider, произвольную notification category/privacy section/column/JSON path. Email, password, provider и session-sensitive actions не объединяются с generic save и продолжают требовать собственную canonical authorization.

## Матрица авторизации заявок на материалы

| Действие | Guest | Verified requester/participant | `manage-content-requests` |
| --- | --- | --- | --- |
| Public directory/detail | только `is_public`; merged UUID redirect | то же плюс own hidden request | любой request для moderation |
| Create | нет | да; requester/status/priority назначаются server-side | по тем же create rules |
| Edit/withdraw/clarify | нет | только owner и разрешённый status; community-supported withdrawal anonymizes | clarification/moderation через отдельные actions |
| Vote/follow | нет | только public open request, desired state idempotent | без обхода terminal rule |
| Status/priority/reject/merge/complete/import | нет | нет | gate + повторная policy/action authorization |
| Private note/source/import run | нет | нет | только moderation context |

`ContentRequestPolicy` и action boundaries повторно разрешают persisted request/target, не доверяют ID/type/status/priority/provider/language/quality/merge/completion values из Livewire. Public binding возвращает 404 для hidden чужой заявки; email/internal user ID/voter/follower list никогда не являются route или DTO полем. Все mutations идут через CSRF-protected Livewire POST, GET только читает или выполняет canonical merged redirect.

## Матрица рекомендаций

| Действие | Guest | Authenticated user | `manage-catalog` |
| --- | --- | --- | --- |
| Public discovery / similar / related | canonical visibility | то же, private exclusions/rerank применяются server-side | без обхода visibility |
| Personal discovery | honest public fallback | только own implicit context, user ID в URL отсутствует | не может читать контекст другого user |
| Not interested / blacklist / undo / watch status | login redirect | `CatalogTitlePolicy::interact`, current owner row, rate limit | не может менять чужой state через admin role |
| Editorial relation create/remove/reorder fields | нет | нет | `manage-catalog`, enum type, eligible source/target, bounded priority |
| Imported relation | нет | нет | только trusted importer service; locked editorial остаётся |
| Internal score/source/cache version | public UI не принимает | public UI не принимает | diagnostics не раскрывает private signals |

Discovery type route ограничен implemented enum cases; similar/related доступны только title context. Public API никогда не повышает доступ из-за authenticated user. Premium/region authorization не имитируется: будущая policy должна войти в existing `CatalogTitleQuery::visibleTo()`/media scopes и автоматически примениться ко всем pools.

## Авторизация скачивания видео

| Проверка | Guest | Authenticated user |
| --- | --- | --- |
| `titles.media.download` route | `auth` не допускает request к controller | route продолжает scoped binding/policy |
| title/media relationship | отсутствуют bytes | numeric media должен принадлежать route-bound title; mismatch = 404 |
| title/season/episode/media release | отсутствуют bytes | каждый persisted release проходит `CatalogEntitlementService`: publication, audience, window, soft delete |
| media delivery | отсутствуют bytes | health playable, status published, direct allowlisted format, persisted URL и trusted upstream validation |

`LicensedMediaPolicy::download` обнаруживается model attribute и вызывается в endpoint; UI link не является permission. Policy не требует verified email: скачивание read/delivery доступно любому зарегистрированному authenticated user, а verification сохраняется только на тех write interactions, где она явно задана domain policy. Controller маскирует relationship/policy denial как 404, не принимает user/media URL/filename и делегирует всю сеть/Range/headers focused service. `StreamLicensedMediaDownload` повторяет eligibility и full public-DNS/host validation непосредственно перед соединением; cached authorization отсутствует.

## Матрица технических обращений

| Действие | Guest | Authenticated requester/participant | `manage-technical-issues` |
| --- | --- | --- | --- |
| Create / My Tickets | нет | create без email-verification barrier и owner-scoped directory | support queue вместо чужого My Tickets |
| Detail | нет | requester либо уже подтверждённый/following participant; данные viewer-scoped | да, с sanitized diagnostics/internal controls |
| Edit / withdraw / reply | нет | requester и только разрешённый status; participant не получает owner evidence | public reply/internal note через отдельные actions |
| Confirm / follow | нет | ручное действие verified-only; exact-duplicate create использует отдельный limiter/occurrence join; requester не self-confirms | те же domain constraints |
| Attachment | нет | собственный upload или requester-visible support attachment своего ticket | да, только после policy и ticket/attachment binding |
| Verify / reopen | нет | requester/confirmer в eligible state; reopen rate-limited | да, через тот же transition boundary |
| Status / classify / assign / merge / redact / source health | нет | нет | route gate плюс policy/action reauthorization |

`TechnicalIssuePolicy` и every action не доверяют UUID/number, requester/target/source/track/status/severity/priority/assignee/resolution/attachment/merge IDs из Livewire. Знание `ISS-…` или UUID не даёт доступ. Полный contract: [`technical-issues.md`](technical-issues.md).
