# Канонические требования PWA, offline-режима и Web Push

Обновлено: 26.07.2026.

Этот документ — единственный постоянный владелец product-контракта
устанавливаемого web-приложения, offline-копий, очереди безопасных действий и
browser Web Push. Общие security, cache, authorization, multilingual и
production-правила продолжают принадлежать соответствующим canonical
документам и не дублируются здесь.

## Честная граница возможностей

- Портал предоставляет действительный manifest, иконки и зарегистрированный
  service worker только в secure context и в браузере с поддержкой этих API.
- Offline-режим даёт публичную оболочку, сохранённую копию личной библиотеки,
  публичную справку, разрешённые постеры и metadata. Интерфейс всегда явно
  показывает, что это сохранённая копия и когда она была обновлена.
- Offline-просмотр HLS, progressive video, защищённого media, signed playback и
  внешнего источника не реализуется и не обещается.
- Недоступная browser capability не маскируется активной кнопкой или
  фиктивным успешным состоянием.
- Push включается только после явного действия пользователя. Автоматический
  permission prompt запрещён.

## Service worker и Cache Storage

- Единственный canonical worker доступен как `/service-worker.js`, имеет scope
  `/`, стабильный deployment contract и версионированные cache names.
- Worker precache-ит только offline shell, manifest, локальные иконки и
  build-assets, явно перечисленные server-side.
- Navigation fallback используется только при настоящей сетевой ошибке и
  ведёт на публичную `noindex` offline-оболочку. HTTP `4xx/5xx` не
  подменяются «успешной» offline-страницей.
- Cache Storage никогда не принимает authenticated HTML, private JSON,
  CSRF/session state, profile/history/recommendations/calendar/notification
  payload, Premium/authorization state, signed URL, grants, tokens, external
  provider URL, HLS manifest/segment, progressive video, audio или download.
- Разрешённые постеры кешируются отдельно, ограниченно и только через
  same-origin proxy с SSRF/content-type/size проверками.
- Worker не применяет новый authorization state и не принимает решения о
  доступе. Server остаётся единственным owner authorization.
- Обновление worker не должно принудительно перезагружать активный player.
  Новая версия активируется безопасно при следующей навигации или после
  явного действия вне воспроизведения.

## Локальная offline-копия

- Личная offline-копия хранится в IndexedDB, а не в общем HTTP/cache layer.
- Копия привязана к opaque account scope, не содержащему email, numeric user
  ID, token или другое публично обратимое значение.
- В offline snapshot допустимы только минимальные карточные поля, owner state
  и optimistic-concurrency versions, необходимые для requested UI.
- Точный playback progress, история источников, provider URL, entitlement,
  moderation state, private notification content и security data не
  сохраняются.
- При logout или смене account scope личная IndexedDB и account-scoped poster
  cache удаляются до показа данных другому пользователю.
- Публичная offline-справка включает только опубликованные статьи audience
  `everyone`; authenticated, Premium и staff content не переносится в
  публичную offline-копию.

## Очередь безопасных действий

- Offline-очередь ограничена allowlist операций, безопасных для повторной
  отправки. Текущий allowlist: `watchlist.set` и `rating.set`.
- Каждая операция имеет UUID mutation ID, expected resource version,
  ограниченный размер и срок хранения.
- Клиентская очередь не считается применённым server state. После
  восстановления сети сервер заново проверяет session, CSRF, active-account
  boundary, input, title visibility, ownership и optimistic version.
- Server reuse-ит canonical sync mutation service и receipt/idempotency
  contract; отдельная offline-бизнес-логика запрещена.
- При неподдерживаемом Background Sync очередь отправляется при `online` и
  следующем открытии портала. Отсутствие фоновой отправки не маскируется
  потерей операции или ложным успехом.
- Conflict/rejected/not-found остаются видимыми пользователю и не удаляются из
  очереди как «успешные».

## Web Push

- Push subscription endpoint рассматривается как secret capability URL: он не
  логируется, не экспортируется, не выводится в UI и хранится encrypted at
  rest; для lookup используется отдельный hash.
- Регистрация и отзыв subscription являются authenticated, CSRF-protected,
  owner-scoped и rate-limited write operations.
- Push endpoint проходит HTTPS/host/port allowlist и SSRF-проверку.
- Production VAPID private key хранится только в environment/secret storage.
  В repository допускаются только названия переменных и безопасные примеры.
- Провайдеру отправляется пустой payload. Заголовок/текст/URL уведомления
  создаёт локальный service worker из статичных переводов; private inbox
  content не проходит через push provider.
- Notification click открывает только same-origin canonical inbox route.
- HTTP `404/410` от push provider отключает subscription; transient
  `429/5xx` использует ограниченный queue retry. Endpoint и VAPID secrets
  никогда не попадают в exception context или application log.
- Logout удаляет server mapping текущей browser installation; account deletion
  удаляет все subscriptions через foreign-key cascade.

## Performance, data и operations

- Offline library snapshot и help snapshot имеют явные upper bounds; poster
  prefetch и cache eviction также ограничены.
- Push fan-out обрабатывается queue job и читает subscriptions порциями; он не
  выполняется синхронно в исходном HTTP/import request.
- Миграции additive, обратимы и не требуют production data backfill.
- Deploy обязан выполнить additive migration, production asset build,
  cache/config refresh по существующему runbook, worker update-check и
  offline/push smoke tests.
- Rollback не удаляет пользовательские subscriptions до подтверждённого
  завершения rollback; старый worker не должен получать несовместимый cache
  contract.
- Production readiness не заявляется без HTTPS, configured VAPID keys,
  работающего queue worker и фактической проверки browser subscription.

## Verification

- Feature tests проверяют manifest/worker/shell headers, private cache
  boundaries, snapshot authorization/audience/bounds, mutation
  validation/idempotency/conflicts, subscription ownership/encryption/revoke,
  VAPID headers и permanent/transient provider failures.
- Frontend contract tests проверяют strict media denylist, account-scope
  cleanup, bounded caches/queue, permission только по user gesture и отсутствие
  `innerHTML`/token persistence.
- Browser acceptance проверяет installability, offline shell, saved library,
  public help, queued safe action, online flush, push settings states,
  mobile layout, accessibility и отсутствие console/network errors.
- Отдельно проверяется, что HLS, video, signed playback, downloads и protected
  responses никогда не появляются в Cache Storage или offline UI.
