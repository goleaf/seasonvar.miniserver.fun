# Приватные подписки календаря релизов — design

Дата: 26.07.2026.

Статус: approved by explicit user authorization.

## Цель

Дать вошедшему пользователю несколько независимо отзываемых read-only
iCalendar-подписок на существующее каноническое расписание:

- весь личный календарь;
- одну собственную подборку;
- новые серии;
- премьеры сезонов;
- один сериал;
- конкретный перевод;
- субтитры конкретного языка;
- перевод или субтитры с дополнительным ограничением одним сериалом.

Управление живёт в существующем `/calendar/mine`. Публичные календарные
маршруты, JSON API, notification categories и импорт не меняются.

## Рассмотренные подходы

### Один token пользователя и query-параметры

URL вида `/calendar/feed/{token}.ics?scope=...` проще по schema, но один отзыв
ломает все подписки, изменяемые параметры превращают сохранённый feed в
другой scope, а независимые Google/Apple subscriptions нельзя безопасно
управлять. Подход отклонён.

### Несколько строк с plaintext token

Независимое удаление и scope работают, но чтение database snapshot немедленно
раскрывает все capability URL. Подход отклонён.

### Несколько типизированных feed'ов с hash + encrypted secret

Каждый feed получает `public_id`, собственный scope, `token_hash` для
indexed lookup и encrypted secret для повторного показа владельцу.
Регенерация атомарно заменяет оба значения. Это выбранный подход: он
поддерживает независимый lifecycle, минимизирует последствия database read и
остаётся без новой dependency/infrastructure.

## Data model

Новая additive таблица `release_calendar_feeds`:

- `id`;
- unique UUID `public_id` для owner management;
- `user_id` с cascade delete;
- nullable `catalog_collection_id` и `catalog_title_id` с cascade delete;
- allowlisted `scope`;
- unique SHA-256 `token_hash`;
- encrypted text `token_secret`;
- nullable normalized `language_code` и `translation_name`;
- stable `locale`;
- monotonic `version`, `token_rotated_at`, timestamps.

Индексы:

- unique `token_hash` для capability lookup;
- `(user_id, created_at, id)` для owner list;
- FK indexes для collection/title lifecycle;
- `(is_public, starts_at, id)` и
  `(is_public, date_value, date_end, id)` на schedule для нового status-free
  exact-date feed window.

Старые migrations не изменяются. `down()` удаляет два schedule indexes и
feed table. Rollback отзывает только новую возможность.

## Scope invariants

| Scope | Target/filter contract |
| --- | --- |
| `all` | Personal eligibility, без target/track |
| `collection` | Обязательная собственная активная collection |
| `new_episodes` | Personal eligibility + `episode_release` |
| `season_premieres` | Personal eligibility + `season_premiere` |
| `title` | Обязательный доступный title |
| `translation` | Personal eligibility или optional title + обязательный normalized translation name + optional language |
| `subtitles` | Personal eligibility или optional title + обязательный normalized language |

Лишние поля запрещаются, а не игнорируются. Пустые строки становятся `null`.
Title/collection повторно разрешаются server-side. На пользователя действует
hard feed limit и owner mutation limiter.

## Backend flow

`ReleaseCalendarFeedManager` — отдельный child Livewire component, чтобы не
увеличивать page calendar component. Он валидирует form state, разрешает
target, вызывает один lifecycle service и передаёт Blade только готовые
presentation arrays.

`ReleaseCalendarFeedService` выполняет create/regenerate/delete в transaction.
Create блокирует user row для корректного hard limit. Regenerate блокирует
owner feed row, заменяет hash/secret/version и немедленно отзывает старый URL.
Policy разрешает management только владельцу.

Stateless route вызывает тонкий `ReleaseCalendarFeedResponder`. Responder
hashes route token, загружает ровно один feed, проверяет schema/account/target,
получает bounded entries и возвращает RFC 5545 content с private headers.
Invalid и inaccessible состояния одинаково дают `404`.

`ReleaseCalendarFeedQuery` использует существующий
`ReleaseScheduleVisibility`. Personal scopes переиспользуют вынесенный
personal eligibility query, а collection/title/track filters добавляются
условно. Projection ограничена нужными ICS полями и title relation; limit и
time window задаются config.

## RFC 5545 representation

- UTF-8, CRLF, 75-octet folding и text escaping;
- stable event `UID` из schedule UUID и configured application host;
- `DTSTAMP`/`LAST-MODIFIED` UTC, `SEQUENCE` из revision;
- exact datetime — UTC `DTSTART`;
- exact date — all-day `DTSTART` и exclusive next-day `DTEND`;
- date range — all-day start и exclusive day after range end;
- partial month/quarter/year и unknown пропускаются без fake first day;
- cancelled events получают `STATUS:CANCELLED`, остальные подходящий
  confirmed/tentative status;
- `TRANSP:TRANSPARENT`, безопасный canonical title URL, без provider/media
  URL, correction private note или personal state.

## UI и integrations

Management panel отображается только в personal view:

- selector scope;
- owner collection selector;
- bounded title search/select;
- language/translation fields только для подходящих scopes;
- create, delete и regenerate с loading/disabled/confirmation;
- для каждого feed: Google, Apple, copy HTTPS URL.

Apple link использует `webcal://`. Google официально требует desktop
«Добавить календарь → По URL» и не документирует стабильный arbitrary-feed
prefill: link копирует URL и открывает canonical add-by-URL settings.
Clipboard module валидирует same-origin HTTPS feed URL, не использует
`innerHTML`, storage, dynamic code или external script.

## Security/privacy

- Route token имеет не менее 256 бит CSPRNG entropy и regex length bound.
- Plain secret не логируется и не входит в rate-limit/cache key.
- Model скрывает hash/secret; account export исключает оба.
- Feed response stateless, private/no-store/noindex/noarchive/nosniff/
  no-referrer.
- Token lookup и management не раскрывают IDOR; invalid/blocked/deleted
  состояния fail closed.
- No shared response/data cache, analytics, service worker, queue или Redis
  dependency.
- Google получает URL только через явное пользовательское clipboard action;
  приложение не отправляет private URL Google server-side.

## Compatibility и cross-feature impact

- Existing route names/query strings/API payloads сохраняются.
- User delete каскадно отзывает feeds.
- Collection/title hard delete отзывает targeted feeds; soft delete fail
  closed.
- Title merge переносит targeted/track feeds на canonical title.
- Account export содержит только non-secret feed metadata.
- Notifications/importer/admin/editor/public cache/SEO/sitemap не получают
  новый contract.
- RU/EN UI и feed locale используют существующие translations.

## Errors и graceful degradation

- DDL ещё не применён: calendar pages работают, feed panel сообщает
  unavailable, route возвращает `404`.
- Invalid form: localized field errors без partial write.
- Feed limit/rate limit: localized error, existing rows не меняются.
- Decryption failure: exception report без secret и unavailable owner action;
  public route fail closed.
- Deleted/inaccessible target или blocked account: `404`.
- Empty valid feed: корректный пустой `VCALENDAR`.

## Verification

Сначала RED tests, затем minimum GREEN:

- migration/model/encryption/hash;
- all scopes и combinations;
- authorization/IDOR/rate limits/hard limits;
- exact/date/range/partial/cancelled ICS;
- headers, invalid/revoked/blocked/deleted tokens;
- regeneration/delete/account export/account delete/title merge;
- Livewire UI links/states and clipboard module;
- query count/EXPLAIN and index audit;
- Pint, focused/full tests, Larastan scope, route/config/view cache, docs,
  Vite build and Playwright desktop/mobile.

## Production/rollback

Deployment: backup, additive migration, code/assets, focused smoke, then
observe 404/429/error rate without logging route tokens. No backfill or secret
creation runs during migration/GET.

Rollback: disable management/route via schema/config guard or deploy previous
code, then rollback migration only if revoking every issued feed is accepted.
Restoring from backup restores old secrets and must be treated as capability
re-activation.
