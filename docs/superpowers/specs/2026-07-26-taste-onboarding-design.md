# Быстрый onboarding вкусов

Дата: 26.07.2026.

## Цель

После подтверждения email новый пользователь получает короткий приватный
onboarding, который создаёт полезный стартовый профиль рекомендаций до
накопления истории просмотра. Пользователь выбирает:

- от 5 до 10 знакомых и понравившихся сериалов;
- любимые жанры;
- интересующие страны;
- предпочитаемый язык интерфейса;
- озвучку, субтитры или нейтральный режим;
- законченные, продолжающиеся сериалы или нейтральный режим;
- короткие, длинные серии или нейтральный режим;
- конкретные тайтлы, которые не следует рекомендовать.

Onboarding остаётся повторно доступным и не блокирует остальной аккаунт.
Сохранение ведёт на существующую персональную выдачу
`/discover/personalized`.

## Проверенный baseline

- PHP 8.5.8, Laravel 13.22.0, Livewire 4.3.3, Tailwind CSS 4.3.2,
  Vite 8.1.4, PHPUnit 12.5.32 и SQLite.
- Регистрация создаёт `User`, account settings и профиль, затем ведёт на
  подтверждение email. Подписанная verification-ссылка уже сохраняет locale.
- `CatalogRecommendationService` является единственной orchestration boundary.
- `CatalogPersonalPreferenceProfileBuilder` и
  `CatalogPersonalizedRecommendationQuery` владеют v2 и legacy source
  signals; `CatalogRecommendationTasteReranker` владеет bounded taste
  adjustments; `CatalogRecommendationExclusionService` владеет exclusions.
- `catalog_recommendation_preferences` уже хранит owner-only diversity,
  freshness, reset cutoff и version. Shared/private cache для preference
  state не используется.
- Каталог имеет реальные genre/country/translation/subtitle signals.
  Локальная SQLite содержит 32 980 опубликованных тайтлов, 21 жанр,
  67 стран, 21 103 тайтла с translation relation и 17 193 опубликованных
  тайтла с subtitle availability.
- `licensed_media.duration_seconds` существует, но в текущем production-like
  snapshot все значения `NULL`; `catalog_statuses` не связан ни с одним
  тайтлом. Поэтому duration/status preferences нельзя изображать работающими
  на неизвестных данных: они применяются только к кандидатам с достоверным
  canonical signal, а остальные остаются neutral.
- Interface locale отделён постоянным multilingual contract от языка аудио и
  субтитров. Отдельной canonical audio/subtitle-language relation нет.

## Рассмотренные варианты

### 1. Сохранить всё JSON в `users` или account settings

Отклонено: JSON не даёт foreign keys для title/genre/country, усложняет
merge/delete/export, создаёт неиндексируемые проверки и смешивает interface,
playback и recommendation responsibilities.

### 2. Записать выбранные тайтлы как обычный `more_like_this`/`blacklisted`

Отклонено как единственное хранилище: нельзя отличить onboarding provenance
от последующего осознанного feedback, повторное редактирование могло бы
удалить более свежий feedback, а «не предлагать» неожиданно повлияло бы на
notification suppression.

### 3. Нормализованный onboarding state внутри существующего recommender —
выбран

Существующая owner preference row получает stable enum codes и completion
timestamp. Три additive таблицы хранят current-state selections:

- одна owner/title таблица с `liked|excluded`;
- одна owner/genre таблица;
- одна owner/country таблица.

Existing recommendation services читают этот state как дополнительный
bounded source/feature/exclusion input. Отдельный recommender, event log,
analytics, package, queue, cache или scheduler не создаётся.

## Стабильные значения и ограничения

- `playback_preference`: `any|dubbed|subtitles`;
- `completion_preference`: `any|completed|ongoing`;
- `episode_length_preference`: `any|short|long`;
- title choice kind: `liked|excluded`;
- liked titles: 5–10;
- excluded titles: 0–10;
- favorite genres: 1–8;
- favorite countries: 1–8;
- liked и excluded title IDs не пересекаются;
- все IDs уникальны, положительны и повторно разрешаются server-side;
- title IDs должны быть доступны текущему пользователю через canonical
  visibility query;
- locale берётся только из configured supported locales.

User-facing переводы не становятся database identity.

## Схема данных

Новая additive reversible migration:

1. Добавляет в `catalog_recommendation_preferences` nullable
   `onboarding_completed_at` и три non-null stable string preference с
   default `any`. Existing rows безопасно получают neutral defaults.
2. Создаёт `catalog_recommendation_onboarding_titles`:
   owner/title cascade FK, stable `kind`, timestamps, unique
   `(user_id,catalog_title_id)` и covering owner/kind/title index.
3. Создаёт `catalog_recommendation_preferred_genres`:
   owner/genre cascade FK, timestamps, unique `(user_id,genre_id)` и
   owner/genre lookup index через unique key.
4. Создаёт `catalog_recommendation_preferred_countries`:
   owner/country cascade FK, timestamps, unique `(user_id,country_id)`.

Новая schema additive, без backfill/DML, совместима с SQLite. `down()` сначала
удаляет owned tables, затем onboarding columns. После реального использования
предпочтителен forward-fix или private export перед rollback.

## Backend boundaries

### Read и options

`CatalogTasteOnboardingQuery`:

- возвращает defaults, если migration ещё не применена;
- читает одну owner preference row и три bounded owner lists;
- возвращает 21 жанр и 67 стран без Eloquent graph;
- использует existing search parser и `CatalogTitleSuggestionQuery` для
  bounded title search;
- загружает выбранные title cards одним visibility query;
- не передаёт model graph в публичный Livewire state.

### Write

`CatalogTasteOnboardingService`:

1. требует существующего verified owner и gate
   `update-account-settings`;
2. fail closed проверяет recommendation и account-settings schema;
3. повторяет все count/enum/disjoint/locale/ID invariants;
4. одним canonical visibility query разрешает title IDs;
5. одной grouped validation проверяет genre/country IDs;
6. в короткой retryable transaction блокирует user/preference rows;
7. синхронизирует current-state rows через bounded upsert/delete;
8. сохраняет stable preferences и `onboarding_completed_at`;
9. через existing `AccountSettingsService` сохраняет locale и
   `subtitles_enabled`, не перезаписывая остальные playback settings;
10. очищает только request-scoped preference memo и owner repeat suppression.

Livewire validation улучшает UX, но service не доверяет browser arrays.

### Registration и verification

Регистрация по-прежнему ведёт на обязательное подтверждение email. Только
первое успешное подтверждение matching authenticated owner ведёт на
localized onboarding route. Повторная verification уже подтверждённого
аккаунта и verification без active owner сохраняют прежнее безопасное
поведение.

Onboarding route — class-based full-page Livewire под
`auth`, `auth.session`, `verified`, `account.private`, `account.active`.
Гость идёт на login, unverified user — на verification notice.

## Recommendation integration

### Source titles

`liked` rows становятся bounded high-confidence signals в обоих существующих
personalization paths. Они используют truthful broad reason
`because_onboarding`; source IDs исключаются из результата, поэтому знакомый
тайтл не рекомендует сам себя.

### Favorite traits

Taste reranker получает candidate feature map одним bounded batch:

- `genre:{id}`;
- `country:{id}`;
- `availability:dubbed` при реальной translation relation;
- `availability:subtitles` при опубликованном доступном media flag;
- `status:completed|status:unfinished` только при canonical status relation;
- `duration:short|duration:long` только при реальной положительной
  `duration_seconds`.

Genre/country/mode/status/duration bonuses независимы и имеют per-family и
total caps. Они не отменяют relevance, visibility, watchability, legal,
audience, premium или repeat/exclusion boundaries.

### Exclusions

Оба вида onboarding title rows исключаются из recommendation candidates:
`liked` уже знакомы, `excluded` явно запрещены. Они не меняют watchlist,
progress, rating, watch status, normal feedback или notifications.

### Reset, merge, export

- Existing «сброс профиля вкусов» удаляет onboarding selections, neutralizes
  onboarding enums и очищает completion timestamp вместе с уже описанными
  learned details/temporary genres. Library/history/exact normal feedback
  сохраняются по прежнему contract.
- Title merge переносит onboarding row на canonical title; при unique
  конфликте `excluded` имеет precedence над `liked`.
- Account export включает stable preference codes и readable selected
  title/genre/country labels.
- Account hard delete, title delete и taxonomy delete удаляют owned rows FK
  cascade.

## UI и accessibility

Одна responsive full-page form использует existing light panels, buttons,
poster frame/icon components и Tailwind utilities:

- прогресс показывает фактическое число выбранных понравившихся тайтлов;
- два независимых bounded search controls для liked и excluded titles;
- native checkbox/radio controls с labels и fieldset legends;
- exact loading targets, disabled duplicate submit, status/error live regions;
- кнопки и интерактивные строки не меньше 44 px;
- one-column phone layout, bounded cards, wrapping labels, no horizontal
  overflow;
- «Настрою позже» ведёт в библиотеку и не блокирует аккаунт;
- повторная ссылка доступна из owner preference panel.

Blade получает только prepared arrays/DTO, не делает query, config, policy
или business computation. Нет inline CSS/JS, `@php`, raw HTML и новых
frontend dependencies.

## Security, privacy и cache

- State private; authenticated HTML и Livewire payload получают existing
  private/no-store boundary.
- CSRF обеспечивает Livewire transport; authorization и visibility
  повторяются в service.
- Locked selected title arrays меняются только server actions; browser не
  передаёт user ID, score, feature key или timestamp.
- All output escaped, search bounded, IDs не раскрывают foreign private state.
- Нет shared cache, service-worker state, SEO/structured data, telemetry,
  arbitrary prose, raw provider URL или personal log payload.
- Rate limiter ограничивает repeated saves, но не заменяет authorization.

## Производительность и SQL

- initial metadata: три bounded indexed owner reads + two small option lists;
- search: existing FTS/visibility boundary, hard limit 12;
- save: maximum 36 current-state rows, grouped validation and bulk writes;
- profile: one indexed liked-title read; no query per title;
- exclusions: one indexed owner title read;
- rerank: one bounded candidate feature extraction batch, no N+1;
- no new index is added without a corresponding owner/kind lookup.

EXPLAIN должен подтвердить unique/covering indexes для owner lists. Current
SQLite lack of duration/status data is documented, not hidden behind cache or
heuristic text parsing.

## Error, rollout и rollback

- Missing migration returns translated `503`/validation state before write.
- Invalid/tampered combination returns translated validation errors without
  internal IDs.
- Unexpected exceptions are reported once without private payload; UI retains
  current choices and shows a generic error.
- Rollout: verified backup/writer assessment → additive migration →
  application/translations/assets → config/route/view refresh → focused
  verified-user smoke. SQLite migration requires a short coordinated writer
  window.
- Rollback: code/UI first, then tables/columns only after accepting/exporting
  private onboarding data. No cache flush, search rebuild or catalog backfill.

## Protected contracts

Не меняются public discovery routes/query/pagination/API shape, title binding,
feedback codes, library state, cache keys/TTL, importer, recommendation build
schema, notification behavior, player source, account settings not owned by
onboarding, locale identifiers и existing guest output.

## Acceptance

- First verified matching owner is sent to localized onboarding.
- Guest/unverified users cannot read or mutate it.
- 5 and 10 liked titles save; 4, 11, duplicate, overlap, invisible and unknown
  IDs fail.
- Genre/country/locale/all three preference enums validate server-side.
- Edit replaces only onboarding-owned state.
- Liked sources remove cold confidence and drive existing similarity edges.
- Favorite feature, subtitles/dubbed, status and duration choices change
  deterministic order only when real metadata exists.
- Unknown duration/status is neutral.
- Excluded/liked source titles never appear as candidates.
- Reset, merge, account export/delete and migration rollback are covered.
- Query counts stay bounded and EXPLAIN uses justified indexes.
- RU/EN parity, mobile/desktop/tablet, focus/loading/error/empty states,
  Vite build and browser console checks pass.
- Focused/full PHPUnit, Pint, Larastan, Rector, docs and diff/security checks
  pass or exact external/unrelated blocker is reported.
- README, canonical owners and CHANGELOG are updated.
- Exact task scope is committed on `main`; configured non-force push result is
  reported literally.
