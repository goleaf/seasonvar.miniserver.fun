# Design: предпочтения переводов и субтитров

Дата: 26.07.2026.

Статус: `approved_by_explicit_execution_instruction`.

## Цель

Расширить существующий единый профиль воспроизведения так, чтобы
зарегистрированный пользователь мог выбрать любимую и запасную озвучку,
предпочесть оригинал с субтитрами, выбрать язык субтитров, скрыть конкретные
варианты и включить уведомление о появлении любимого перевода. Плеер,
рекомендации и карточки должны использовать эти сигналы без второго player
lifecycle, без fake subtitle tracks и без раскрытия private source metadata.

## Фактический baseline

- PHP `8.5`, Laravel `13.22.0`, Livewire `4.3.3`, SQLite, Tailwind `4.3.2`.
- `user_account_settings.preferred_variant` уже хранит stable `variant_key`.
- `AccountSettingsService` является policy/transaction boundary настроек.
- `CatalogPlaybackSourceResolver` является единственным source resolver.
- `LicensedMedia` хранит `variant_type`, `variant_key`, `translation_name` и
  `has_subtitles`, но не подтверждённый язык субтитров.
- `CatalogUserCardStateLoader` пакетно добавляет private owner state карточкам.
- `LicensedMediaReleaseScheduleObserver` уже запускает release side effects
  после commit.
- Database notifications используют deterministic UUID и safe payload.
- Рабочая ветка `main` содержит большой чужой staged/unstaged/untracked scope;
  delivery этой задачи обязана быть path/hunk-limited.

## Рассмотренные варианты

### A. Расширить существующий account/player contract — выбран

`preferred_variant` сохраняется как любимая озвучка. Новые scalar preferences
добавляются additive columns, исключения — отдельной user-owned таблицей.
Player DTO/resolver, importer metadata, card overlay и notification observer
расширяются в текущих границах.

Преимущества: обратная совместимость, server-side validation, нормальная
индексация, атомарный reset/export/delete, отсутствие duplicate player state.

### B. Хранить весь профиль в JSON — отклонён

JSON уменьшил бы число колонок, но ухудшил бы constraint/validation,
notification lookup, частичное обновление, миграцию данных и SQL evidence.
Для маленького стабильного набора глобальных preference fields это не даёт
практической пользы.

### C. Связать preferences с catalog `translations` — отклонён

Catalog taxonomy переводов не является identity конкретного playback variant:
player и importer уже используют `variant_key`, который различает studio,
original и subtitles. FK на taxonomy создал бы ложную точность и сломал бы
existing selection contract.

## Data model

Additive migration расширяет `user_account_settings`:

- `fallback_variant` nullable string(160);
- `preferred_playback_mode` nullable string(32), stable values
  `automatic|dubbed|original_subtitles`;
- `preferred_subtitle_language` nullable string(16);
- `notify_preferred_translation` nullable boolean, default `false`.

Новая `user_hidden_playback_variants`:

- bigint primary key;
- `user_id` FK cascade;
- `variant_key` string(160);
- timestamps;
- unique `(user_id, variant_key)`.

`licensed_media.subtitle_language` — nullable string(16). Индекс добавляется
только если проверяемый card/recommendation query plan не покрывается
существующими индексами; blind index запрещён.

Rolling deployment использует расширенный `AccountSettingsSchema`: до полной
миграции новые fields возвращают safe defaults, а write возвращает понятную
503 вместо SQL exception. `down()` сначала удаляет owned table, затем additive
columns. Production backfill не выполняется; старые rows эквивалентны
`automatic`, empty hidden set и notification off.

## Validation и atomic write

Livewire выполняет UX validation, но `AccountSettingsService` повторяет
server-side allowlists:

- favorite/fallback/hidden keys разрешаются только из bounded actual options
  плюс уже сохранённые unavailable values;
- favorite и fallback различны;
- favorite/fallback не входят в hidden set;
- hidden set unique, максимум 50 values;
- mode принадлежит enum;
- subtitle language принадлежит configured allowlist;
- notification — boolean.

Account setting row блокируется вместе с user row. Scalar changes и
`user_hidden_playback_variants` replacement выполняются в одной retryable
transaction. `settings_version` увеличивается ровно при material change.
Reset атомарно возвращает defaults и очищает hidden set, не меняя progress,
history или library.

## Playback selection

`PlaybackPreferencesData` расширяется favorite/fallback/mode/subtitle language
и hidden keys с default values, поэтому существующие callers совместимы.

Порядок:

1. server-validated explicit media/variant selection;
2. любимый `variant_key`;
3. запасной `variant_key`;
4. mode match (`voiceover` либо `original|subtitles` с `has_subtitles`);
5. exact `subtitle_language`;
6. preferred quality/format;
7. provider priority и source health;
8. deterministic media id tie-break.

Hidden keys не попадают в candidate query или menu options. Если explicit ID
оказался hidden после сохранения профиля, resolver не выдаёт его и применяет
обычный safe fallback. Source URLs, grants и hidden set не сериализуются в
публичные responses.

## Import и subtitle language

`ExternalMediaMetadata` распознаёт только явные allowlisted language markers в
title/source metadata. Результат nullable. Он записывается существующими
Seasonvar/external playlist import paths и safe backfill pipeline вместе с
variant metadata. Unknown остаётся `null`; locale интерфейса и страна тайтла
не являются fallback.

## Cards и recommendations

`CatalogUserCardStateLoader` получает account preferences один раз и выполняет
не более одного дополнительного grouped media query для title IDs страницы.
Каждый title получает transient code:

- `preferred` — playable favorite available;
- `alternative` — favorite unavailable, но playable non-hidden voiceover есть;
- `null` — favorite unset или нет релевантного media.

`TitleCard` переводит code в RU/EN presentation; Blade не делает query.
Personal state не входит в public/shared cache и API resource.

Personalized recommendation reranker одним grouped availability read
учитывает favorite, fallback, mode и subtitle language; hidden variants не
получают boost. Existing score shape и public modes не меняются.

## Notification

При committed publication playable `LicensedMedia` observer вызывает focused
service. Получатели выбираются по:

- exact `preferred_variant = media.variant_key`;
- `notify_preferred_translation = true`;
- owner account active;
- published/publicly accessible title/media.

Deterministic UUID включает user, title и variant; повторный import или новая
серия того же title/variant не создаёт notification spam. Payload:
`catalog_title_id`, `catalog_title_slug`, `variant_key`, safe display label.
Provider/source URL, media ID, user ID, raw importer text и playback history
не сохраняются. Delivery database-only, best-effort и после commit.

## UI

Существующая full-page `AccountSettingsPage`, section `playback`:

- select любимой озвучки;
- select запасной озвучки;
- radio/select режима воспроизведения;
- select языка субтитров;
- bounded checkbox list «не показывать»;
- checkbox уведомления.

Controls имеют label, hint, error, loading/disabled state, 44px targets,
mobile single-column layout и RU/EN key parity. Новых routes, frontend
frameworks, inline JS/CSS и dependencies нет.

## Cross-feature compatibility

- authentication/policy/CSRF: existing account settings gate;
- account export: новые scalar fields и hidden keys включаются;
- account deletion: user-owned rows удаляются cascade;
- onboarding: `dubbed|subtitles` обновляет new mode без стирания explicit
  favorite/fallback;
- player/mobile: DTO defaults сохраняют public contracts; authenticated web
  применяет account state server-side;
- recommendations/cards: private overlay только authenticated;
- cache/SEO/API/sitemap: shared/public contracts unchanged;
- release calendar: subtitle language дополняет metadata, существующие event
  types и subscription preferences unchanged;
- importer: одна публичная команда, no remote video download;
- privacy: no source URL/track body/user history in settings or notification;
- Premium/payments/ads/regional/legal: selection продолжает existing
  `availableTo()`/authorization rules, bypass не появляется.

## Error handling

- missing schema: safe defaults for read, localized unavailable response for
  writes;
- invalid/mutually exclusive selection: field validation error;
- unavailable saved option: retained and marked unavailable;
- no matching media: existing fallback/error state;
- notification failure: reported after commit, publication не откатывается;
- importer cannot identify language: nullable metadata, no guess.

## Verification

- migration rollback/forward и foreign key integrity на SQLite;
- service tests for validation, atomic replace, version/no-op/reset/export;
- resolver tests for precedence, hidden variant, subtitle language and
  unavailable fallback;
- importer metadata unit/integration tests;
- card loader query-count and presentation tests;
- notification idempotency/safe payload tests;
- Livewire save/error/reset/auth tests;
- onboarding/recommendation regressions;
- Pint, focused tests, full suite, docs checks, translation parity, Vite build;
- repository-wide legacy/debug/secret and scoped diff audit.

## Rollback

Revert application/docs commit, then run migration `down()` while the prior
code still tolerates additive columns/table. No backfill or destructive data
rewrite exists. Notification delivery is additive and idempotent; already
delivered safe database rows may be deleted only by ordinary retention or
account deletion, not by rollback migration.
