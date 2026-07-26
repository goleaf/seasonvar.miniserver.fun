# Умная обратная связь и управление профилем рекомендаций

Дата: 26.07.2026.

## Цель

Заменить прямое действие «Не интересует» на осмысленный приватный feedback:
пользователь выбирает причину, а recommendation boundary применяет только
серверно проверяемый и ограниченный сигнал. Одновременно персональная выдача
получает реальные настройки разнообразия, баланса новых/проверенных сериалов,
временное скрытие жанра и безопасный сброс выученного профиля.

Существующее объяснение «Почему это показано» сохраняется каноническим:
карточка показывает только broad truthful reason codes, но никогда не
раскрывает историю, source title IDs, negative feature keys, confidence,
score breakdown или чужие данные.

## Проверенный baseline

- Laravel 13.22, PHP 8.5.8, Livewire 4.3.3, Tailwind 4.3.2/Vite 8.1.4,
  SQLite; Redis настроен для cache/session/queue.
- `CatalogRecommendationService` — единственная orchestration boundary.
- `catalog_title_user_states` содержит одну canonical строку user/title и
  стабильные значения `more_like_this|not_interested|blacklisted`.
- `CatalogRecommendationPresenter` уже выводит broad explanation, а
  recommendation card visibly labels его «Почему это показано».
- Current UI записывает `not_interested` одним кликом и не спрашивает причину.
- Personalized v2 уже умеет bounded feature demotion, recency, confidence и
  exploration, но пользователь не может управлять diversity/freshness.
- В production-size SQLite 1 646 332 user/title rows у 102 пользователей;
  feedback нельзя превращать в unbounded event log.
- Personalized output и private preference state не используют shared cache.

## Рассмотренные варианты

### 1. Добавить строковое поле причины в `catalog_title_user_states`

Это минимальный DDL, но один polymorphic subject ID не может иметь FK сразу к
genre/country/actor. Свободная строка создаёт translated identity, IDOR и
невозможность безопасного merge/delete. Вариант отклонён.

### 2. Создать append-only event/impression/click analytics

Такой журнал позволил бы offline analytics, но добавил бы новую
high-cardinality privacy/retention/consent/administration архитектуру.
Проект намеренно не имеет recommendation analytics, а задача требует current
preferences, не telemetry. Вариант отклонён.

### 3. Current-state detail + owner preferences — выбран

`catalog_title_user_states.recommendation_feedback` остаётся canonical
решением по тайтлу. Одна дополнительная owner/title строка хранит текущую
причину и один типизированный subject через отдельные nullable FK columns.
Одна owner preference row хранит stable diversity/freshness codes и reset
cutoff. Временные жанры хранятся отдельными owner/genre rows с expiry.

Этот вариант:

- не создаёт второй blacklist/watchlist;
- не хранит произвольный user text или impression history;
- позволяет FK, cascade/null behavior, merge/export/delete;
- остаётся bounded и объяснимым;
- не требует package, внешнего API, queue, cache или scheduler.

## Стабильные причины

`CatalogRecommendationFeedbackReason` хранит только codes:

| Code | UI | Canonical feedback | Ranking effect |
| --- | --- | --- | --- |
| `watched_elsewhere` | Уже смотрел в другом месте | `not_interested` | exact title only |
| `dislike_genre` | Не нравится жанр | `not_interested` | selected owned genre feature |
| `dislike_country` | Не нравится страна | `not_interested` | selected owned country feature |
| `dislike_actor` | Не нравится актёр | `not_interested` | selected owned actor feature |
| `too_many_episodes` | Слишком много серий | `not_interested` | bounded long-series feature |
| `unfinished` | Не закончен | `not_interested` | canonical unfinished status feature when present |
| `too_old` | Слишком старый | `not_interested` | bounded old-title feature |
| `low_rating` | Плохой рейтинг | `not_interested` | bounded low-rating feature with vote floor |
| `wrong_mood` | Не хочу такой настрой | `not_interested` | bounded server-derived theme features |
| `not_this_title` | Не предлагать именно этот сериал | `blacklisted` | exact title only; existing notification suppression |
| `not_similar` | Не предлагать похожие | `not_interested` | bounded genre/tag/theme similarity features |

Genre/country/actor reason требует positive integer subject ID. Service
проверяет, что relation действительно принадлежит видимому тайтлу. Остальные
reasons запрещают subject. Client не задаёт feature key, weight, timestamp,
user ID, feedback mapping или relation identity.

Если canonical status metadata для `unfinished` отсутствует, система хранит
явную причину и exact exclusion, но не выдумывает признак по году или
описанию. Это честнее ложной классификации.

## Схема

### `catalog_recommendation_feedback_details`

- `id`;
- cascade FK `user_id`, `catalog_title_id`;
- stable `reason`;
- nullable `genre_id`, `country_id`, `actor_id` с `nullOnDelete`;
- timestamps;
- unique `(user_id, catalog_title_id)`;
- index `(user_id, updated_at, id)` для bounded profile read.

Ровно один subject column разрешён только для соответствующей причины.
Database row не является authority сама по себе: invariant повторно проверяет
write service.

### `catalog_recommendation_preferences`

- `user_id` одновременно primary key и cascade FK;
- stable `diversity=focused|balanced|varied`;
- stable `freshness=newer|balanced|proven`;
- nullable `profile_reset_at`;
- monotonic `version`;
- timestamps.

Отсутствующая строка означает balanced defaults. Переводы никогда не
сохраняются.

### `catalog_recommendation_hidden_genres`

- `id`;
- cascade FK `user_id`, `genre_id`;
- `hidden_until`;
- timestamps;
- unique `(user_id, genre_id)`;
- index `(user_id, hidden_until, id)`.

Expired rows не влияют на query и bounded очищаются при следующей owner
mutation. Scheduler не нужен для correctness.

Все migrations additive, reversible, без backfill/DML и совместимы с SQLite.
Rollback удаляет только новую capability; existing feedback остаётся.

## Backend boundaries

### Feedback

`CatalogRecommendationFeedbackService`:

1. требует authenticated verified owner через existing
   `CatalogTitlePolicy::interact`;
2. проверяет rolling schema до mutation;
3. нормализует enum/positive IDs;
4. проверяет subject membership;
5. в short retryable transaction вызывает existing
   `CatalogUserStateService` и upsert/delete detail;
6. сохраняет existing rate limit, sync version и undo semantics.

`more_like_this` удаляет stale negative detail. Undo очищает canonical feedback
и detail одной transaction. Никакой reason не попадает в public API,
structured data, shared cache или logs.

### Preferences

`CatalogRecommendationPreferenceService` владеет owner writes:

- `save()` сохраняет два allowlisted enum;
- `hideGenre()` upsert-ит ровно один genre на configured bounded срок;
- `restoreGenre()` удаляет только owner row;
- `reset()` под user row lock выставляет balanced defaults и
  `profile_reset_at=now()`, удаляет detail rows и temporary genre rows, затем
  очищает только owner session repeat suppression.

Reset не удаляет watch history, progress, ratings, watchlist, statuses,
collections, personal tags или exact hidden-title decisions. Старые
recommendation evidence с semantic activity до cutoff перестают участвовать
в profile/ranking. Новая реальная activity после reset снова формирует
профиль.

### Ranking

`CatalogRecommendationContext` получает server-only prepared preference DTO.

- Hidden genres применяются как indexed `whereIn` subquery внутри canonical
  visibility query, а не materialized full title-ID list.
- Legacy и v2 personalization фильтруют evidence по `profile_reset_at`.
- Explicit detailed reasons формируют bounded feature demotions; source и
  candidate feature extraction остаются server-side.
- Candidate feature extraction дополняется country/actor и только при
  реально активных reasons — bounded trait aggregates.
- `CatalogRecommendationTasteReranker` применяет preference effects одним
  bounded candidate batch; rows, уже demoted v2 scorer, не получают двойной
  штраф.
- `newer` даёт небольшой bounded bonus recent year/catalog additions;
  `proven` даёт bounded bonus зрелым тайтлам с устойчивым ranking evidence.
  Настройка не отменяет visibility/watchability/minimum relevance.
- `focused|balanced|varied` меняет только limits existing diversity service;
  score, candidate identity и public type contracts не меняются.

## UI и accessibility

Обе существующие Livewire surfaces используют один Blade component.

- «Больше похожего» остаётся прямым положительным действием.
- «Не интересует» раскрывает 11 причин; genre/country/actor показывает только
  prepared relation options данного тайтла.
- Каждая mutation — native button, минимум 44 px, exact loading target,
  disabled during request, keyboard-operable, escaped and translated.
- Empty subject option не показывает dead reason.
- Personalized discovery показывает owner-only panel:
  diversity select, freshness select, genre select, список временно скрытых
  жанров с датой, restore и reset confirmation.
- Сброс явно сообщает, что история/оценки/закладки и уже скрытые конкретные
  тайтлы сохраняются.
- Mobile — одна колонка, long labels wrap, horizontal overflow отсутствует.
- JS, modal package, inline CSS, `@php`, Blade queries и client authority не
  добавляются.

## Privacy, security и lifecycle

- CSRF обеспечивает Livewire POST transport; authorization повторяется
  server-side.
- IDOR закрывается current owner + visible title + relation membership.
- Mass assignment не принимает raw client array.
- All output escaped; dynamic action names allowlisted component class.
- Reason/profile/hidden genre data private, `no-store` по existing personal
  boundary и отсутствуют в shared cache/service worker/SEO.
- Account export включает stable reason/subject labels and preference codes.
- Account deletion удаляет все новые rows cascade.
- Title merge переносит newest matching detail к canonical title и
  deterministic winner следует уже выбранному canonical feedback/timestamp.
- Taxonomy deletion only nulls obsolete subject; generic exact feedback
  сохраняется.
- No secret, URL, personal prose, provider content or raw score is logged.

## Производительность и индексы

- Preference read: one PK lookup + one active hidden-genre lookup.
- Feedback details: bounded latest owner rows by
  `(user_id, updated_at, id)`.
- Temporary hide uses `(user_id, genre_id)` unique and active expiry index.
- Subject options reuse eager genres/countries and one bounded actor batch for
  all visible recommendation cards; N+1 запрещён.
- Feature and trait queries use candidate/source ID batches capped by existing
  recommendation limits; no full catalogue collection in PHP.
- No private cache is added: invalidation complexity outweighs two indexed
  owner reads.
- EXPLAIN должен подтвердить owner/expiry/detail and genre pivot plans before
  completion. Индексы сверх конкретных reads не добавляются.

## Error and rolling-deploy behavior

- Missing new schema returns existing localized unavailable error before
  partial write.
- Invalid enum/subject/membership returns one localized validation error
  without disclosing foreign IDs.
- Rate limit remains distinguishable.
- Unexpected exceptions are reported without private payload and existing UI
  shows generic error.
- Query defaults remain balanced when preference tables are unavailable;
  mutation controls fail closed until migration completes.

## Backward compatibility

Не меняются:

- routes and route names;
- discovery URL filters, pagination and public API response fields;
- `CatalogRecommendationService` public discovery/title contracts;
- existing feedback enum codes and user/title state;
- hidden library/notification suppression;
- cache keys/versions/TTL;
- importer/build/shadow activation;
- title card public explanation reason codes;
- guest output.

Existing API/mobile calls can still write canonical feedback without a detail
reason through `CatalogUserStateService`; reason-aware web UI uses the new
service. Это сохраняет старый contract, но новый web control больше не делает
negative write без причины.

## Rollout и rollback

Порядок production:

1. database backup assessment;
2. additive migrations;
3. application code/translations/assets;
4. config/route/view cache refresh and PHP-FPM reload;
5. focused authenticated smoke;
6. monitor validation/error/query time.

Rollback code сначала отключает UI/read effects, затем migrations могут
удалить только новые rows/tables. Existing feedback остаётся usable.
Migration rollback удаляет подробные причины/preferences, поэтому перед
rollback после реального использования нужен private export/acceptance
оператора; иначе forward-fix предпочтительнее.

## Acceptance

- Все 11 причин доступны и negative write невозможен без причины в web UI.
- Genre/country/actor subject принадлежит exact title.
- Existing `more_like_this`, blacklist, undo and hidden library remain green.
- Detailed signals меняют both legacy and v2 ranking without double demotion.
- Reset cutoff ignores old evidence but preserves library/history/exact hides.
- Temporary genre is excluded until expiry and automatically stops affecting
  output after expiry.
- Diversity and freshness settings produce deterministic different ordering.
- «Почему это показано» remains present for every explained card.
- Empty/guest/unverified/invisible/invalid/rate-limited states are safe.
- Query counts are bounded; EXPLAIN uses justified indexes.
- RU/EN parity, mobile/desktop accessibility, Vite build and browser console
  checks pass.
- Focused/full PHPUnit, Pint, PHPStan, docs checks and security scans pass or
  exact unrelated blocker is recorded.
- README, canonical owners, data/security/performance/frontend docs and
  CHANGELOG are current.
- Only exact task scope is committed in `main`; configured push result is
  reported literally.
