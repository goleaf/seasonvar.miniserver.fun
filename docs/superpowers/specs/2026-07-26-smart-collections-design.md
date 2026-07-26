# Умные личные подборки

Дата: 26.07.2026.

Статус: implemented_and_verified.

## Проблема

`CatalogCollection` сейчас хранит только вручную выбранные
`catalog_collection_items`. Пользователь не может сохранить правило вроде
«Южная Корея + криминал + IMDb от 8 + завершён + не просмотрен + есть
субтитры + серия не длиннее 60 минут» и получать автоматически меняющийся
результат. Аудит честно обозначает smart rules как unsupported boundary.

## Цели

- добавить owner-only smart mode в существующий `CatalogCollection`;
- хранить нормализованный versioned rule set, а не materialized title IDs;
- вычислять результат из актуального каталога, доступности видео и личного
  состояния владельца;
- поддержать сочетание всех правил, сортировку, фильтры detail-страницы,
  пагинацию и reset;
- дать шесть готовых шаблонов из пользовательского запроса;
- сохранить manual/public collection contracts без изменения URL;
- разрешить использовать smart collection в уже существующей приватной
  ICS-подписке владельца;
- не добавлять queue, scheduler, Redis, package или внешнего provider.

## Рассмотренные варианты

### 1. Materialized membership

Правила периодически пересобирают `catalog_collection_items`.

Преимущество — простые существующие read paths. Недостатки — stale result,
необходимость покрыть importer, media health, user state, progress, calendar
и merge events; queue/scheduler становятся correctness dependency, появляются
гонки и дорогой fan-out. Вариант отклонён.

### 2. Hybrid snapshot с read-through refresh

Состав хранится как snapshot и иногда пересчитывается в запросе.

Он уменьшает часть read cost, но сохраняет две истины, сложную invalidation
matrix и непредсказуемый latency первого запроса. Вариант отклонён.

### 3. Dynamic read-time rules

Подборка хранит только правила, а canonical query строит bounded SQL по
актуальным данным. Это выбранный вариант: состав не устаревает, не требует
background infrastructure, а существующая пагинация ограничивает hydration.

## Доменный контракт

В `catalog_collections` additive migration добавляет:

- `mode`: stable `manual|smart`, default `manual`;
- `smart_rules`: nullable JSON;
- `smart_rules_version`: unsigned integer, default `1`.

`manual` сохраняет прежнюю семантику и item rows. `smart` разрешён только для
`type=user`, `visibility=private`, существующего owner и non-empty
нормализованного rule set. Категория, публикация, feature/moderation queue,
public API, public profile, discovery, sitemap, related collections и
recommendation membership для smart mode не применяются.

Публичный route contract не меняется: владелец открывает private detail через
существующий `/collections/{slug}` и existing policy. Browser не задаёт
`owner_id`, mode после создания, raw SQL, table/column/operator либо arbitrary
JSON key.

Manual item mutation на smart collection отклоняется server-side. Смена
manual ↔ smart для существующей записи не предоставляется: это сохраняет
понятную identity и не оставляет скрытый competing membership.

## Versioned rule set

Версия `1` поддерживает только allowlisted поля:

- `country_slug`, `genre_slug`, `actor_slug`;
- `imdb_min`;
- `year_from`, `year_to`;
- `completion`: `completed|ongoing`;
- `episodes_max`;
- `max_episode_minutes`;
- `in_library`;
- `unwatched`;
- `has_subtitles`;
- `has_new_episodes`;
- `watch_status`;
- `watch_status_older_days`;
- `video_available`.

Пустые строки нормализуются в `null`; boolean — в реальные boolean; числа
имеют bounded диапазоны. `year_from <= year_to`,
`watch_status_older_days` допустим только вместе с `watch_status`, а smart
collection требует хотя бы одно активное правило. Неизвестные keys и версии
не исполняются: query fail-closed возвращает пустой result.

`completion=completed` означает: у тайтла есть regular season с известным
положительным `episodes_total`, и все доступные regular seasons имеют
`episodes_released >= episodes_total`. `ongoing` означает наличие доступного
regular season с неизвестным total либо released меньше total. Это
детерминированная проекция фактических season counters, а не анализ
человеческого текста.

`unwatched` исключает любой тайтл с `episode_view_progress` владельца.
`in_library` требует watchlist или watch status. `has_new_episodes`
переиспользует `CatalogPersonalUpdateQuery`. `video_available` требует
доступный published playable source без known failure. Субтитры и duration
используют тот же media availability boundary. Ограничение duration требует
хотя бы одно известное значение и исключает тайтл, если любая доступная
серия превышает лимит.

## Готовые шаблоны

Stable preset codes не являются persisted rules identity и переводятся только
в presentation:

1. `new_korean_thrillers` — Южная Корея, триллер, IMDb от 8, последние два
   года;
2. `short_completed_comedies` — комедия, завершён, до восьми серий, серия до
   60 минут;
3. `library_new_episodes` — личная библиотека с непросмотренными обновлениями;
4. `unwatched_favorite_actor` — непросмотренные, затем владелец выбирает
   актёра;
5. `dropped_three_months` — статус `dropped` старше 90 дней;
6. `library_available_video` — библиотека, где есть доступное видео.

Применение шаблона только заполняет draft. Сохранение выполняется отдельным
действием с обычной validation/version/policy boundary.

## Query architecture

`CatalogSmartCollectionQuery` получает canonical visible title query и
добавляет только parameter-bound Eloquent subqueries. Taxonomy slugs
разрешаются indexed slug lookup, pivots используют существующие unique/reverse
indexes. IMDb использует existing provider/rating index. User state,
progress, release entries, seasons и media используют существующие
owner/title или title/status prefixes.

Результат:

- выбирает только card columns/relations/counts;
- сортируется deterministic secondary title ID;
- пагинируется максимум по 48, UI использует 24;
- hydrates personal card state одной grouped boundary;
- сохраняет дополнительные detail filters `q/genre/country/status/year/sort`;
- не кешируется shared/private cache и не вызывает provider.

Для dashboard count не вычисляется на каждую smart card: карточка показывает
«состав обновляется автоматически». На detail authoritative total берётся из
текущего paginator. Это устраняет N+1 разных rule queries.

## Запись, concurrency и cache

Создание остаётся в `CatalogCollectionService`: под owner lock проверяются
лимит, policy, mode/type/visibility/rules invariant и создаётся одна запись.
Rule edit переиспользует `CatalogCollectionService::update()` под collection
lock: boundary повторно проверяет policy/mode и optimistic
`content_version`, затем одной транзакцией заменяет metadata и normalized
JSON и увеличивает `content_version`. Отдельный дублирующий rules-service
после code review удалён.

После commit используется существующий `CatalogCollectionCacheInvalidator`.
Dynamic result не получает отдельный cache, поэтому importer, progress,
watch-status и media-health changes не требуют fan-out invalidation.

## ICS, account lifecycle и compatibility

Existing collection ICS feed принимает owner-owned smart collection. Feed
query использует те же dynamic title IDs и после каждой загрузки видит
актуальный результат; token, scope, limit и private no-store contract не
меняются.

Account export включает mode, rules version и нормализованные rules, но не
materializes текущий состав. Account deletion/merge сохраняют прежний
collection lifecycle; rules содержат только slugs/scalars и не требуют title
ID reconciliation.

Manual collections, item positions, collection membership selector, public
API/resource shape, public URLs, recommendation signals, calendar token
format, imports and editorial sync remain backward compatible.

## UX и доступность

Dashboard предлагает manual/smart mode и preset для smart creation. Smart
mode явно сообщает, что подборка private и обновляется автоматически.
Editor показывает:

- готовые шаблоны;
- country/genre/actor selectors;
- bounded numeric/year fields;
- completion/watch status selectors;
- library/unwatched/subtitle/update/video checkboxes;
- active-rule summary, reset, loading/error/success states.

Actor lookup bounded до 10 результатов, начинается после двух символов и
сохраняет stable slug. Все подписи RU/EN, controls имеют label/error binding,
touch target не меньше 44px, grid складывается на mobile. Blade не выполняет
query/service/config calls и не содержит inline business JavaScript.

На detail smart badge и описание правил объясняют автоматический состав.
Remove/reorder/unavailable/manual-membership controls отсутствуют.

## Security и privacy

- smart collections always private and owner-authorized;
- arbitrary/malformed JSON fails closed;
- only allowlisted enum/slug/number/boolean values reach query builder;
- no raw SQL interpolation, dynamic columns/operators or external URL;
- escaped Blade output, Livewire CSRF and locked stable identities remain;
- private rules/results are absent from public API/search/sitemap/cache;
- no secret, provider URL or personal viewing details are logged.

## Migration, rollout и rollback

Classification: `safe_additive`.

Migration adds nullable/default columns without backfill; all existing rows
read as `manual`. `down()` drops only these columns. Deployment order:
backup assessment → compatible code → migration → focused smoke. No queue
restart, data backfill, cache flush or asset provider is required.

Rollback before smart rows is ordinary migration rollback. After smart rows,
code rollback must first convert/delete those user-owned records or keep the
columns; blindly dropping rules would lose user configuration. Preferred
production recovery is forward-fix.

## Verification

- migration/model casts/defaults/rollback;
- rule normalization, impossible combinations and unknown version fail-close;
- every rule and multi-rule combination;
- zero/min/max boundaries, sort and pagination;
- owner vs guest/other user/IDOR;
- smart create/edit/preset/reset and stale version;
- manual item mutation denial and manual regression;
- dynamic catalog/user/media changes without collection rewrite;
- smart collection ICS;
- account export;
- public API/search/profile/sitemap exclusion;
- SQL count/query budget and SQLite `EXPLAIN QUERY PLAN`;
- RU/EN parity, Pint, PHPStan, full tests, Vite and desktop/mobile Playwright.
