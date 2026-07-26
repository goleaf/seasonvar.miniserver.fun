# Design: честная устанавливаемая PWA и privacy-safe Web Push

Дата: 26.07.2026.

Статус: `approved_by_explicit_user_instruction`.

## Цель и baseline

Seasonvar получает настоящую installable PWA: manifest, local icons, один
service worker, offline shell, сохранённую библиотеку и публичную справку,
bounded cache постеров/metadata, очередь безопасных owner actions и Web Push.
Проект уже имеет responsive Livewire shell, private/no-store middleware,
идемпотентный API sync, SSRF-защищённый poster proxy, database notifications и
queue. Web manifest, service worker, IndexedDB boundary, subscription storage
и VAPID delivery отсутствуют.

## Рассмотренные варианты

1. Cache-first для всех страниц отклонён: authenticated HTML и private JSON
   содержат CSRF/session/account data, а cached authorization быстро
   устаревает.
2. Только статическая offline-страница отклонена как неполное выполнение:
   пользователь не увидит сохранённую библиотеку, справку и очередь действий.
3. Сторонний Web Push package отклонён: новый production dependency не нужен
   для payloadless VAPID и потребовал бы отдельного согласования.
4. Выбран hybrid:
   - Cache Storage только для shell/build/icons и account-scoped proxy posters;
   - IndexedDB для минимальных snapshots и mutation queue;
   - canonical server sync для повторного применения действий;
   - payloadless VAPID Web Push, который лишь будит worker и открывает private
     inbox после обычной server authentication.

## Потоки данных

### Install и offline navigation

`layouts.app` объявляет manifest и PWA endpoints. `pwa.js` регистрирует
`/service-worker.js` только в secure context. Server-generated worker получает
точный build asset list. При network exception navigation возвращает только
`/offline`; обычные HTTP errors не заменяются.

### Личная библиотека

Authenticated `GET /pwa/library-snapshot` возвращает bounded owner-only
metadata для visible title state: slug, title, type/year, proxy poster,
watchlist/rating/status и watchlist/rating versions. Response остаётся
`private, no-store`. Page JavaScript записывает проверенный payload в IndexedDB
под HMAC account scope. HTTP response не помещается в Cache Storage.

### Справка

Public `GET /pwa/help-snapshot` возвращает только опубликованные `everyone`
articles и sanitized plain text. Snapshot сохраняется в IndexedDB и доступен
offline без staff/Premium/authenticated content.

### Постеры

Authenticated same-origin proxy повторно использует existing HTTPS/DNS/content
type/size guard и дополнительно проверяет visibility title для текущего user.
Page prefetch ограничивает количество карточек и Cache Storage entries; cache
name включает opaque account scope и очищается при logout/switch.

### Safe offline mutations

Offline shell разрешает только `watchlist.set` и `rating.set`. Browser создаёт
UUID, сохраняет expected version и bounded payload. При `online`, следующем
открытии или Background Sync message client отправляет batch в
`POST /pwa/actions`. Form Request запрещает лишние поля/types; server вызывает
existing `ApiSyncMutationService`. Applied/duplicate удаляются, conflict,
rejected и not-found остаются видимыми.

### Push

Явная кнопка в настройках вызывает Notification permission и
`PushManager.subscribe({userVisibleOnly: true, applicationServerKey})`.
Authenticated endpoint хранит endpoint encrypted и отдельно hash, locale,
opaque installation hash и delivery health. `NotificationSent` для database
channel после commit ставит fan-out job. Job подписывает empty POST VAPID
ES256, отправляет только на configured public browser-push hosts и не включает
private payload. Worker показывает локализованный generic text и ведёт только
на `/notifications`.

## Security и privacy

- Ни session/CSRF/Bearer/VAPID private key, ни media/signed/provider URL не
  записываются client-side.
- SW denylist проверяет method, origin, path, destination и authorization
  header до cache lookup/write.
- Push endpoint — encrypted capability, не логируется.
- VAPID audience — exact endpoint origin, expiry не более 24 часов, signature
  ES256/P-256.
- Current browser installation mapping удаляется при logout; account deletion
  покрывает FK cascade.
- Offline UI не выдаёт local state за server truth и всегда показывает
  timestamp/queue/conflict state.

## Performance и rollback

- Snapshot: не более 300 library titles и 60 public help articles.
- Poster cache: не более 80 entries, prefetch не более 40 за refresh.
- Mutation queue: не более 100 items, batch не более 50, retention 30 дней.
- Push fan-out chunk: 100, HTTP timeout 8 секунд, connect timeout 3 секунды,
  bounded retries только для connection errors, `429` и `5xx`.
- Новые индексы: unique endpoint hash, `(user_id, disabled_at, id)` и
  `(user_id, installation_hash)`.
- Rollback: disable `PWA_ENABLED`/`PWA_PUSH_ENABLED`, deploy previous worker
  version, retain additive table, then revert code. `down()` drops only the new
  subscription table if an explicit database rollback is required.

## Protected contracts

- Existing public routes, localized routes, API shapes and mobile sync remain
  unchanged.
- Private page/API `no-store`, policies/gates, title visibility, database
  notification content and player access remain server-owned.
- No offline HLS/progressive playback, media download, token/grant cache or
  fake push success.
- No new production dependency, queue infrastructure or cache backend.
