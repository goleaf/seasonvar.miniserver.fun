# Recommendation v3 и единый список на странице сериала

## Цель

Заменить рекомендационный алгоритм `v2`, в котором широкий жанр и общая заполненность кандидата могут перевесить реальное сходство, на объяснимый content-aware алгоритм `v3`. На странице тайтла блок «Советуем посмотреть» должен стать одним вертикальным списком без grid-вариантов и вложенных карточек.

Пользователь делегировал выбор реализации формулировкой «сделай всё по своей рекомендации». Выбран вариант без внешнего inference API и без новой production dependency: каталог уже содержит достаточно описаний и нормализованных связей для детерминированного offline ranking.

## Проверенный baseline

- В публичном каталоге 34 179 тайтлов и у каждого есть описание.
- `catalog_title_recommendations` содержит 410 131 строку `v2`; coverage формально равен 100%, поэтому проблема состоит в relevance, а не в пустых блоках.
- У `Именно так/Aynen Aynen` есть один широкий жанр `комедия`, страна `Турция`, два актёра, режиссёр, возраст `18+`, рейтинги и описание отношений друзей, но нет тегов, эпизодов или доступного видео.
- Текущий `sourceSignalScore()` добавляет кандидату баллы за его собственные положительные сигналы. Из-за этого общие `rating` и `page_quality` повышают сходство даже без общего содержательного признака.
- Текущая выдача включает тематически далёкие «Конь БоДжек», «Бессмертные» и анимационные сериалы. Более подходящие записи каталога — турецкие relationship/romantic-comedy тайтлы «Повсюду ты», «Любовь напрокат», «Ранняя пташка», «Постучись в мою дверь» и «Первый и последний (2021)».
- Desktop-разметка смешивает одну крупную grid-card, четыре compact-card во вложенной рамке и ещё одну grid-секцию. Полноразмерный снимок показывает, что постеры доминируют над объяснениями и создают длинные пустые вертикальные зоны. На mobile topology меняется ещё раз.
- Механический layout scan не нашёл запрещённых произвольных spacing/z-index значений. Проблема структурная: одинаковые карточки и разные topology внутри одного блока.

## Интернет-исследование

- [IMDb](https://www.imdb.com/title/tt10841904/) классифицирует `Aynen Aynen` как турецкую comedy/romance 2019–2021 с короткими эпизодами около 10 минут.
- [TV+](https://tvplus.com.tr/blog/kategori/dizi/aska-dair-en-iyi-8-romantik-dizi) описывает сериал как лёгкую романтическую комедию о повседневной динамике пары, с короткими эпизодами, быстрым темпом и современным юмором.
- Исследование [Content Recommendation System Application with TF-IDF](https://dergipark.org.tr/en/pub/ajit-e/article/1012354) подтверждает применимость текстовых признаков к фильмам и сериалам. В проекте используется bounded тематический профиль с inverse-frequency весами вместо полного словаря: это сохраняет объяснимость и существующий memory budget.
- Оригинальная работа [The Use of MMR](https://www.cs.cmu.edu/~jgc/publication/The_Use_MMR_Diversity_Based_LTMIR_1998.pdf) задаёт принцип relevance + novelty. `v3` применяет небольшой greedy redundancy penalty только после вычисления relevance, чтобы разнообразие не поднимало слабый результат выше сильного.

## Рассмотренные варианты

### 1. Только перенастроить фиксированные веса `v2`

Самый маленький diff, но один широкий жанр по-прежнему остаётся главным источником кандидатов. Вариант не различает романтическую, абсурдистскую, анимационную и бытовую комедию и поэтому отклонён.

### 2. Content-aware `v3` на локальных данных — выбран

Нормализованные relations дополняются ограниченным тематическим профилем из русского title/description. Редкие признаки получают больший вес, общие — меньший. Сходство, качество и diversity остаются отдельными buckets. Решение детерминировано, объяснимо, не требует сети во время rebuild и укладывается в текущий importer boundary.

### 3. Внешние embeddings/LLM или сторонний movie API

Может дать более богатую семантику, но добавляет production dependency, стоимость, rate limits, лицензионные условия и недетерминированность массового rebuild. Без отдельного запроса на такую интеграцию вариант отклонён.

## Архитектура

### Тематический профиль

Новый `CatalogRecommendationThemeExtractor` принимает готовые plain-text `title`, `original_title` и `description` и возвращает bounded map theme key → Russian label. Первая версия содержит только проверяемые доменные темы с Unicode-aware patterns:

- `romance`, `relationships`, `friendship`, `family`, `youth`;
- `workplace`, `school`, `medical`, `legal`, `crime`, `mystery`;
- `fantasy`, `supernatural`, `science_fiction`, `historical`, `military`;
- `adventure`, `sports`, `music`, `show_business`, `everyday_life`.

Extractor не выполняет запросов и не хранит provider prose. Он ограничивает число тем на тайтл, нормализует `е/ё`, регистр и пробелы, а unit tests фиксируют positive и negative examples. Для `Именно так` ожидаются как минимум `romance`, `relationships` и `friendship`.

### Candidate generation

`CatalogTitleRecommendationBuilder` сохраняет текущий packed inverted index для relations и добавляет bounded packed maps для:

- отдельной темы;
- `theme + country`;
- `theme + genre`.

Composite keys нужны, чтобы popular theme `romance` сначала привёл к турецкой романтической комедии, а не к случайной мелодраме из всего каталога. Все candidate scans остаются детерминированно ограниченными конфигурацией; current title никогда не входит в свой pool.

### Relevance scoring

Алгоритм хранит три существующих buckets, но меняет их смысл:

- `metadata_score`: общие relations, тип, близость года и темы описания;
- `source_score`: только реально общие provider recommendation signals из allowlist. Собственная заполненность кандидата, `rating`, `page_quality` и дубли taxonomy не являются сходством;
- `quality_score`: обязательное опубликованное видео, затем небольшой bounded bonus за рейтинг, отзывы и количество доступных media.

Relation/theme contribution умножается на bounded inverse-frequency factor. Редкий общий актёр, режиссёр или конкретная тема значит больше, чем `комедия`, `драмы` или `Турция`. Quality не может самостоятельно провести кандидата через minimum relevance gate.

Сохранённые `reasons` включают contribution каждого признака. Theme-reasons хранят стабильные keys, а модель переводит их в короткие русские badges: `Романтика`, `Отношения`, `Дружба`, затем `Актёры`, `Режиссёр`, `Страна`, `Год`, `Видео` по убыванию вклада.

### Diversity reranking

После relevance sort greedy re-ranker выбирает первый самый сильный результат, затем вычитает небольшой penalty за Jaccard-overlap dominant themes/relations с уже выбранными строками. Penalty ограничен и не может компенсировать большой relevance gap. В БД остаётся исходный `score`; `rank` отражает итоговый порядок. Версия алгоритма — `v3`.

### Import lifecycle

Публичная команда не меняется: `php artisan seasonvar:import`. Full и queued-finalizer cycles продолжают запускать один массовый recommendation rebuild после catalog/media maintenance. Rebuild остаётся локальным и не вызывает интернет. Summary сохраняет coverage, stored count, version и duration, как сейчас.

## UI

`CatalogTitlePageBuilder` возвращает одну коллекцию presentation-safe list items. Precomputed rows и fallback нормализуются в одной PHP boundary; Blade не объединяет коллекции и не выполняет запросы.

Блок «Советуем посмотреть» рендерит один `<ol>`:

- одна строка на рекомендацию, порядок DOM равен `rank`;
- широкий poster frame слева: около 112 px на телефоне и 176–192 px начиная с `sm`;
- landscape frame заполняется по ширине через `object-cover`, без 2% overscan; допустима обрезка только по высоте;
- справа: номер, название, original title, год/type, до четырёх причин и полное читаемое описание без CSS-обрезания;
- строки разделяются обычным `divide-y`, без отдельных card borders, shadow и nested panels;
- на узком телефоне сохраняется двухколоночная строка, поэтому список не превращается обратно в grid;
- весь row имеет один основной stretched link; taxonomy links в recommendation row не выводятся, чтобы не создавать конкурирующие tab stops.

Новый `recommendation` poster/title layout используется только в этом блоке. Глобальные catalog grid cards не меняются: это предотвращает незапрошенную регрессию `/titles`, главной и истории просмотра.

Если precomputed rows отсутствуют, genre/year fallback объединяется, дедуплицируется и выводится тем же `<ol>`. Если нет и fallback, остаётся одно полезное русское empty state без второго пустого panel.

## Ошибки и границы

- Candidate без опубликованного и видимого media отбрасывается до ranking.
- Missing description даёт пустой theme profile, но metadata scoring продолжает работать.
- Неизвестный theme key не попадает в публичный badge.
- Missing recommendation table не ломает title page; fallback остаётся доступным.
- Builder начинает все title/candidate queries с существующей public visibility boundary.
- Новых HTTP-запросов, пользовательского profiling и скачивания видео нет.

## Проверка

TDD фиксирует:

1. extractor themes и negative matches;
2. generic candidate quality больше не считается source similarity;
3. broad genre без theme/strong relation не проходит gate;
4. турецкая relationship comedy ранжируется выше тематически далёкого сериала с общим актёром;
5. candidate без published media не сохраняется;
6. MMR сохраняет лучший первый результат и только мягко разводит последующие;
7. `v3`, score breakdown, ranks, reasons и max-per-title сохраняются корректно;
8. title page выводит один ordered list без recommendation grid classes и в rank order;
9. fallback использует тот же list layout и не дублирует тайтлы;
10. publication boundary сохраняется.

После реализации выполняются focused PHPUnit tests, Pint, полный `php artisan test`, `npm run build`, production rebuild, SQL-метрики и Playwright desktop/tablet/mobile. Browser QA проверяет отсутствие horizontal overflow, console/page errors, current-title duplicates, пустого блока и локальных asset failures.

## Task 18: каноническая recommendation/discovery architecture

Статус на 16.07.2026: реализовано. Этот раздел заменяет прежнее предположение, что рекомендации ограничены одним similarity-list. Builder остаётся единственным offline-производителем рассчитанного сходства, а `CatalogRecommendationService` стал единственной orchestration boundary для title page, homepage, discovery, library и legacy JSON API. В проекте нет AI, ML, collaborative filtering, embeddings или внешнего inference; интерфейс этого не заявляет.

### Стабильные типы и публичность

| Code | Реальный источник ранжирования | Anonymous/cold start | Cache и SEO |
| --- | --- | --- | --- |
| `personalized` | bounded meaningful progress, watchlist, watch status, rating, own collections и own personal-tag assignments через stored similarity candidates | гость и пользователь без сигналов получают честный `editorial → trending → popular` fallback с фактическим display type | shared cache запрещён; `noindex` |
| `similar` | stored content similarity v5/v4; fallback по общему genre только при отсутствии rows | доступен в контексте title | не отдельная индексируемая page |
| `related` | явные sequel/prequel/spin-off/remake/universe/companion/editorial/provider relations | доступен в контексте title | не отдельная индексируемая page |
| `editorial` | approved public featured editorial collections и их ручной item position | одинаковая публичная подборка после visibility | scalar public IDs; `noindex`, потому что отдельной стабильной section landing пока нет |
| `trending` | distinct authenticated meaningful viewers, новые watchlist entries, published reviews/comments за `day|week|month` | публичный; пустая активность не подменяется lifetime views внутри type | locale/type/period/filter/version; индексируется только непустой default state |
| `popular` | lifetime meaningful viewers, watchlists, published reviews и tiered votes одного provider | публичный | public cache; indexable default state |
| `top_rated` | один источник `portal|kinopoisk|imdb`, rating desc, votes desc, ID tie-break | публичный | public cache; indexable default state |
| `recently_added` | `catalog_titles.indexed_at`, ближайший существующий marker публикации каталога, после canonical visibility | публичный | public cache; indexable default state |
| `recently_updated` | max published media date или released episode date; `catalog_titles.updated_at` не используется | публичный | public cache; indexable default state |
| `upcoming` | future episode `released_at` или announced future title year; watched/completed title получает вторичный private boost | публичный; дата не означает playable media | `noindex`, watch button не обещается |
| `random` | deterministic seeded probes по indexed eligible ID range с wrap-around | публичный | shared cache bypass; `noindex`, result ID отсутствует в canonical URL |

У каждого типа есть переводимые title/description/accessibility label в `lang/{ru,en}/recommendations.php`. В БД, URL, cache dimensions, API и action payload используются только codes. `CatalogRecommendationSource` и `CatalogRecommendationReason` также являются enum codes; raw codes никогда не выводятся пользователю.

### Контекст, visibility и filters

`CatalogRecommendationContext` живёт только на сервере и содержит current user object, locale, type, current title, explicit exclusions, allowlisted filters, period, rating source, bounded page/per-page и opaque random/refresh seed. Никакие user IDs, progress rows, collection names, personal tag names, blacklist IDs или candidate sets не сериализуются в URL/Livewire properties.

Все pools проходят `CatalogRecommendationVisibilityService`, который переиспользует `CatalogTitleQuery::visibleTo()`. Это исключает unpublished, hidden, soft-deleted и audience/window-inaccessible titles. Watchable sections дополнительно требуют published, currently available, nonfailed media с реальной playback location. Выделенных region, licensing или premium-entitlement models в проекте нет; поэтому система не имитирует их и автоматически унаследует такие ограничения, когда они появятся внутри canonical visibility/media scopes.

Discovery и random используют task 04 taxonomy identity и facets: `genre`, `country`, `tag`, `actor`, `director`, `translation`, `studio`, year range, quality, subtitle availability, rating и votes. Slugs, numeric ranges, provider и limits нормализуются server-side. Tag query применяет `publiclyEligible()`, поэтому private/hidden/archived/moderation-pending tags не участвуют. Отдельных audio-language, subtitle-language, creator/writer/showrunner и translation-studio/billing-order entities нет; их не подменяют строками интерфейса или фиктивными relations. Existing `translation` taxonomy и media subtitle/quality flags остаются единственными достоверными availability signals.

### Personal ranking, exclusions и explanations

Candidate generation читает не более 120 recent meaningful source titles. Значимым считается completion, минимум 180 секунд или 10% прогресса; page open/нулевая позиция не является интересом. На source title накладывается один strongest verified signal: completion/history, watchlist, `planned|watching`, high portal rating, own collection membership или own personal-tag assignment. Затем читаются только первые 24 stored similar rows на source; полный каталог в PHP не сканируется.

Hard exclusions централизованы: current title, explicit page exclusions, `not_interested`, `blacklisted`, recently shown IDs при refresh, недоступные записи и source titles. General personalized discovery также исключает/demotes watching, completed и dropped. `planned`/watchlist служат положительными source signals, но сам сохранённый title не выдаётся как новый. Completed/dropped не удаляются из каталога и остаются доступны по прямой ссылке, в explicit similar/related и upcoming/new-release context. Upcoming использует watched/completed title только как boost, поэтому новая серия может вернуть релевантный завершённый сериал без бесконечного повтора в «Для вас».

`not_interested` и `blacklisted` — два значения существующей one-row-per-user/title state, а не второй preference table. Mutation authenticated, policy-authorized, rate-limited, idempotent, CSRF-protected и не использует GET. Undo очищает только feedback; history, progress, collection membership, watchlist и direct access сохраняются. Library section `hidden-recommendations` позволяет восстановить скрытое состояние. Другая учётная запись это состояние не видит.

Explanation выбирается только из реально победившего signal/source. Формулировки широкие: история, watchlist, status, collection membership, own tags, rating, metadata similarity, public activity, editorial choice, release/update или random. Exact episode, progress, time, device, personal tag/collection name и internal weight не выводятся. Stored similarity reasons локализуются presenter-ом. В UI отсутствуют fake match percentages, «идеально для вас» и AI labels.

### Similarity и explicit relations

Builder algorithm `v5` сохраняет backward-compatible rows в `catalog_title_recommendations`; существующие v4 rows читаются до следующего full import rebuild. Candidate maps bounded и packed. Exact scoring разделён на metadata/source/quality:

- genre 180, tag 220, director 280, actor 130, network/studio 200, translation 45, status 35, country 80, age 20; contribution умножается на bounded rarity;
- actor учитывает максимум два shared actors и не проходит strong gate в одиночку; минимум два shared actors либо tag/director/network/studio/theme/provider relation дают strong relevance;
- description themes и trusted identical provider relation signals добавляют relevance; candidate-only quality не является сходством;
- year/type дают малый secondary bonus; published media/rating/review bonus добавляется только после strong gate и minimum relevance;
- deterministic MMR/storage rank остаётся совместимым, а score никогда не показывается как процент.

Явные связи хранятся отдельно в `catalog_title_relations`, потому что rebuild владеет calculated rows. Directional inverse: sequel↔prequel и spin_off↔spin_off_from; remake/universe/companion/manual-similar/provider-related симметричны. Service запрещает self/deleted pair и bounded sequel/prequel cycle, создаёт inverse idempotently и сохраняет source provenance. Locked editorial relation не перезаписывается provider import. Title page показывает explicit related перед computed similar и исключает current/duplicate IDs из обоих блоков.

При Seasonvar merge outgoing/incoming explicit relations переносятся на canonical title до force-delete. Совпадения объединяют minimum priority, active/locked flags и provider provenance; self-relations удаляются. FK cascade удаляет relations только при реальном hard delete. Soft-hidden/deleted targets остаются исторически сохранёнными, но canonical visibility исключает их из результата. Rebuild и title merge bump-ят recommendation cache version после успешного commit.

### Public ranking formulas

Canonical popularity score:

```text
watchlist_count × 35
+ distinct_meaningful_viewers × 45
+ published_review_count × 8
+ provider_vote_tier (0/20/40/60/80)
```

Provider vote tiers are `<100`, `100`, `1k`, `10k`, `100k`. Raw page views, model `updated_at`, mixed rating sources and anonymous refresh count are absent. Distinct authenticated user aggregation limits refresh-loop influence without fingerprinting. No claim of perfect fraud protection is made.

Trending sums only recent semantic events within 1/7/30 days: distinct meaningful viewers ×40, newly changed watchlist rows ×25, published reviews ×8 and published title comments ×4. It is deliberately different from lifetime popularity. Top-rated minimum votes are 5 for sparse portal ratings and 1,000 separately for Kinopoisk/IMDb; one perfect vote cannot win a provider list.

### Diversity, novelty, repeat suppression and random

Ranking first preserves relevance, then `CatalogRecommendationDiversityService` processes a bounded pool. It caps an explicit franchise component at 2, the dominant common genre at 5 and dominant actor at 4; deferred items fill a small catalogue so diversity never turns into an empty/irrelevant list. No billing/franchise model is fabricated: franchise grouping uses only explicit sequel/prequel/spin-off/remake/universe relations.

Recently displayed IDs are stored only in the current server session, separately for guest and authenticated user, at most 96 IDs for seven days. Refresh remembers the visible batch, changes an opaque seed and demotes/excludes immediate repeats; if the eligible catalogue is too small, a fallback relaxes only repeat suppression. It never permanently blocks a title and is removed with session/account lifecycle. No impression analytics table or write per card was added.

Random selection calculates eligible min/max IDs, performs at most 12 indexed probes of 8 rows, wraps when a gap is reached and retains all visibility/filter/feedback/current-title exclusions. It does not execute `ORDER BY RANDOM()` and remains SQLite-compatible. Random is accurately labelled random, never personalized/serendipitous.

### Cache, performance and failure behavior

Shared cache stores only scalar public candidate arrays (`id`, internal score, source/reason codes), never Eloquent models or user state. Dimensions are type, locale, public audience, period, rating source, current/exclusion hash when required, normalized filter hash and `task18-v5` ranking version. Page/per-page are applied after the shared candidate pool and are intentionally absent from the key. Authenticated, personalized and random requests bypass shared results; hashed exclusion dimensions cannot reveal raw private IDs.

Public metadata/rating/comment/review/editorial/rebuild/merge changes bump the existing Recommendations version. Meaningful progress crosses the invalidation boundary only on the first 180s/10% threshold or completion, not every heartbeat. Watchlist/rating changes invalidate only through their existing after-commit services. No application-wide cache flush, mandatory queue, cron, vector DB or external search/inference dependency was introduced; cache failure falls back to bounded queries.

Candidate pools are capped at 180, page size at 48 and URL page at 500. Card hydration eager-loads only card taxonomies/current state and uses grouped count queries for episodes/media/reviews. Diversity uses two pivot queries plus an optional explicit-relation query, not a query per card. Public facet options are one UNION-based batch query. Live SQLite cold observations on 32.9k titles are documented in `performance.md`; they are diagnostics, not p95/SLA.

### Post-rollout cold-path hardening

Follow-up profiling on 16.07.2026 separated candidate generation from card/facet hydration. On the current SQLite catalogue, uncached candidate generation measured 5.945 s for trending, 3.629 s for popular, 2.787 s for top-rated, 1.143 s for recently-added and 9.623 s for recently-updated. The last query aggregated 873,565 published media rows across 32,239 titles before applying the bounded result limit. These are single diagnostics, not an SLA.

Three approaches were evaluated. A materialized content-update summary would make reads cheapest but would introduce a new write/rebuild owner across every importer/admin path. An index-only change would retain a full historical aggregate on every cold miss. The selected design keeps publication timestamps authoritative and reads a bounded latest-event window per real source, merges it deterministically by event time/source row ID, deduplicates canonical title IDs, then applies the existing visibility/watchability boundary. The window is derived from the 180-candidate cap and hard-limited in configuration; it never scans or scores the complete catalogue in PHP. If a highly concentrated source produces fewer unique eligible titles, the section returns the truthful bounded set rather than falling back to technical `updated_at`.

The media stream reuses the existing `(status, published_at, id)` feed index. Episode events receive one additive SQLite-compatible index matching publication/deletion/release ordering; no summary table, trigger or backfill is introduced. On current data, the first 10,000 media events contain 252 unique titles and the bounded prototype returned all 180 eligible candidates in 1.697 s with 34 MiB peak memory. A 20,000-event diagnostic completed in 1.911 s with 46 MiB peak memory. The implemented 11,520-event-per-source default returned 180 ordered unique eligible IDs in 1.748 s at 42 MiB peak memory, compared with the isolated 9.623 s pre-change candidate query; supplied exclusions and genre filters remained authoritative. These are one-off local diagnostics, not p95/SLA.

The existing optional `WarmCatalogCaches`/`PublicPageCacheWarmer` path gains the five stable indexable discovery URLs. This warms both sanitized public HTML and the existing scalar recommendation/facet snapshots after normal scheduled/catalog invalidation work. Personalized, authenticated, filtered, random, editorial and upcoming states remain outside proactive warming. Queue availability is not required for correctness: a missing worker/cache still falls back to the same bounded authoritative request-time queries, and no second queue, cache domain or scheduler is added.

### Post-rollout top-rated source-first hardening

Follow-up profiling on 16.07.2026 isolated `top_rated` candidate generation. The existing query evaluated rating and vote correlated subqueries from every visible/watchable title before ordering. Single local cold observations were 1.412 s for the empty portal result, 1.807 s for Kinopoisk and 1.721 s for IMDb. The same visibility query joined to its authoritative rating source produced identical ordered IDs in 0.001 s, 1.151 s and 1.146 s respectively. A Kinopoisk genre-filtered set and an explicit exclusion set also matched all 180 current IDs. These values are diagnostics, not p95/SLA.

Three approaches were evaluated. A stored top-rated summary would make reads cheapest but add a new aggregate and synchronization owner to rating import, user rating, title merge and administration paths. Cache-only warming would leave the authoritative cold fallback expensive. The selected source-first query keeps the current tables and exact ranking semantics: portal ratings are grouped once by title with `AVG(rating)`, `COUNT(rating)` and the configured minimum-vote `HAVING`; provider ratings join the existing unique `(catalog_title_id,provider)` row and require the provider-specific minimum vote count. The canonical visibility/watchability builder remains the outer title boundary and therefore retains every filter, current-title, region/audience and exclusion rule.

Ordering remains `rating DESC`, `votes DESC`, `catalog_titles.id DESC`; portal, Kinopoisk and IMDb never mix. The result still emits the existing `rating` source and `top_rated` reason with rank-derived internal scores. No cache identity, route, translation, presenter, SEO, API, migration, queue or background summary changes. If a source has fewer than its configured minimum-vote candidates, the truthful bounded result may be empty exactly as before.

Post-implementation read-only repeats preserved all five pre-change result hashes. Portal completed in 57 ms, Kinopoisk in 1.349 s, IMDb in 954 ms, the genre-filtered Kinopoisk set in 1.143 s and the top-ten-exclusion set in 1.120 s with zero overlap. Provider plans selected `catalog_ratings_provider_score_votes_title_idx` as covering; portal used a grouped co-routine followed by primary-key visibility lookup. These single local observations vary with OS cache and database load and are not an SLA.

### Post-rollout release-availability query hardening

The next cold-path audit found a shared cost below every watchable recommendation type. `LicensedMedia::forAvailableReleases()` expressed the season and episode boundaries as two `IN (subquery)` lists. On the current SQLite catalogue each candidate query therefore materialized eligible IDs from 48,467 seasons and 724,418 episodes even though every inspected media row already carries `season_id` and `episode_id`. `EXPLAIN QUERY PLAN` confirmed two `LIST SUBQUERY` branches; the episode branch also rebuilt the season list. This is canonical access logic rather than recommendation ranking, so the fix remains in the existing media scope and every consumer keeps one entitlement boundary.

Three approaches were evaluated. An additional media composite index cannot remove the global child-ID list construction and would add write cost to an already heavily indexed 873,435-row table. A materialized watchable-title summary would make reads cheap but create a second availability truth and synchronization ownership across import, publication, health, deletion, audience and availability-window mutations. The selected design keeps the same authoritative child scopes and replaces membership lists with correlated `EXISTS` checks keyed by the referenced primary IDs. SQLite then probes `seasons` and `episodes` by `INTEGER PRIMARY KEY` only for a media row that survived the existing title-keyed publication index.

The logical contract is unchanged: `NULL season_id` remains allowed; otherwise that exact season must satisfy `availableTo($user)`. `NULL episode_id` remains allowed; otherwise that exact episode and its own exact season must both satisfy `availableTo($user)`. Soft deletion, publication status, public/authenticated audience and availability windows continue to come exclusively from `CatalogEntitlementService`; media publication, health and playback-location constraints are unchanged. The design intentionally preserves the independent media-season and episode-season checks so malformed or mismatched legacy references cannot bypass either boundary.

Read-only prototypes preserved the complete ordered public eligible-ID hashes for unfiltered watchability (32,230 titles), `1080p` (7,688) and subtitle availability (17,195). The bounded recently-added probe preserved its 180-ID hash while alternating from 1.362 s/1.270 s with materialized lists to 18 ms/9.5 ms with primary-key correlation. Full-catalogue probes also improved, but remain bounded by reading all matching titles and are not request-path targets. These are local diagnostics, not an SLA. No migration, cache-key/version, route, ranking, explanation, DTO, API, UI, queue or background owner is introduced.

Implementation retained those three complete hashes and matched same-snapshot legacy/new hashes for base, 1080p, subtitles and an authenticated 180-row query. The member query expanded only stable audience codes and contained no user-ID binding. The final SQLite plan used `licensed_media_publication_lookup_idx` followed by three integer-primary-key probes and contained no `LIST SUBQUERY`. Managed Chromium verified the default and filtered discovery pages at desktop/mobile widths with 24 rows, correct index/noindex canonicals, no horizontal overflow, console/page error or local request failure. The browser process used an isolated in-memory maintenance store because the shared production-like environment was already in maintenance mode; that global state was not changed.

### Post-rollout semantic activity provenance and availability hardening

A final acceptance reread found that `trending` treated a current watchlist row's shared `catalog_title_user_states.updated_at` as the watchlist event date. That timestamp also changes when rating, watch status or recommendation feedback changes, so an unrelated private mutation could make an old watchlist entry look new. The same shared timestamp was used to choose the bounded personalized watchlist/rating/status windows. This is deterministic but not truthful event provenance.

Three approaches were evaluated. Keeping `updated_at` preserves the smallest schema but violates the semantic trending contract. A separate append-only activity ledger would provide richer analytics, but it would add a new privacy/retention/abuse domain and a second mutation owner even though recommendation analytics are intentionally absent. The selected design extends the existing one-row-per-user/title state with nullable `watchlist_updated_at`, `rating_updated_at` and `watch_status_updated_at`. Legacy values remain intact but are deliberately not backfilled because a shared technical timestamp cannot prove which signal changed. Only a real canonical web/mobile/sync mutation establishes the matching semantic timestamp. Title merge keeps the newest trustworthy semantic timestamp; technical `updated_at` may break an equal-version legacy conflict but is never persisted as invented semantic provenance. No timestamp or user identity enters public output, URL, explanation or shared cache.

The public trending watchlist branch is present only when `watchlist_updated_at` exists and reads that column; a rolling deployment without the new column simply omits that contribution instead of falling back to technical `updated_at`. Personalized signal windows use the matching semantic column and fall back to stable row identity when the additive schema is not yet available. The migration replaces the two Task 18 indexes that encoded technical `updated_at` with exact semantic activity indexes and restores the legacy index definitions on rollback.

Title merge also reconciles the complete one-row state rather than only watchlist/rating: `blacklisted` wins over `not_interested`, and `dropped` wins over `completed`, `watching` and `planned`. Versions remain monotonic. This conservative precedence prevents a duplicate-title merge from silently re-exposing content the user explicitly hid or dropped.

Two adjacent availability boundaries are hardened without changing result identity. Upcoming builds bounded pools from the earliest canonically available future episodes and visible future-year titles, then applies the shared outer visibility/order query; draft/deleted episodes or seasons cannot affect public order, and an empty calendar no longer evaluates a correlated future-release scan across the full catalogue. Personalized quality/variant/subtitle boosts additionally require a real playback location; metadata-only media can no longer improve a candidate's convenience score. Both changes reuse the canonical entitlement scopes and introduce no new cache, route, DTO, API, UI or background owner.

### Routes, integrations, UI and localization

Canonical routes are `/discover/{type}` and `/{locale}/discover/{type}`. `/discover` redirects to public popular; legacy `/recommendations` and localized equivalent 301 to the same canonical type. User IDs and private state are absent. Browser history preserves allowlisted filters, rating source, period and page. The existing API route/name and response shape remain `GET /api/v1/titles/{titleSlug}/recommendations`; it is intentionally public/contextual and never consumes authenticated signals.

Homepage renders one light recommendation row: authenticated real personal result or honest public fallback, anonymous trending/popular. Title detail orders related then similar and offers working feedback/undo. Search no-result links to explicitly labelled popular discovery rather than pretending those cards matched the query. Library exposes personal discovery and hidden-feedback restore. Future episode/title dates power upcoming discovery; there is no competing calendar model. Existing cards, light theme, Russian/English translation architecture and local assets are reused.

The Livewire component keeps only stable scalar URL state and locked type/seed/undo ID. It does not serialize models/history. Every loading, empty, anonymous, cold-start, error and feedback state is localized and announced. Results use a responsive grid/list, meaningful headings, 44px controls, visible focus, touch scroll for type navigation and no autoplay/carousel/focus trap/inline CSS/inline business JS. No new JavaScript module was necessary.

### Authorization, privacy, analytics and lifecycle

Public discovery is readable without login. Feedback/watch status uses current authenticated user and `CatalogTitlePolicy::interact`; editorial relation create/remove/search requires `manage-catalog`. Client-provided IDs, type, relation type, priority, feedback, filter, provider and limit are enum/allowlist/bounds checked again in services. Editorial text is existing escaped collection metadata; no raw HTML or score mutation reaches mass assignment.

No recommendation impression/click/conversion analytics existed, so none was invented. Trending uses already persisted semantic portal activity only. Account export includes explicit feedback/watch status; account deletion cascades user-title state, progress/session repeat suppression and private tags/collections through existing lifecycle, while public calculated/editorial relations remain. Public HTML/structured data never contains another user's activity, private explanations or access state.

### SEO and known limitations

Only non-empty anonymous default `trending`, `popular`, `top_rated`, `recently_added` and `recently_updated` pages are indexable and eligible for sitemap. They receive canonical, localized metadata, real `hreflang`, breadcrumbs and a bounded public ItemList. Personalized, authenticated, upcoming, editorial, random, filters and transient refresh state are `noindex` and excluded. Scores are not Schema.org ratings. Default filters are omitted from canonical; random candidate identity is not a URL.

Verified limitations are explicit: no per-region/licence/premium model, favorite-genre profile, creator/writer/showrunner relation, billing order, canonical franchise/merged-ID relation, dedicated release-calendar record, distinct audio/subtitle language entity, recommendation analytics, followed editorial collection or localized editorial market schedule exists. The system degrades through canonical visibility/public fallback and does not claim these capabilities. Adding one later means extending the existing context/visibility/source enum, not creating a second recommender.

### Manual acceptance

- Inspect stable enum codes, schema/indexes, relation inverse/merge behavior, public/private query boundaries and scalar cache payloads.
- Confirm public types produce distinct deterministic IDs; empty editorial/upcoming remain truthful empty states; random changes with seed and respects filters.
- Confirm feedback/undo and hidden-library restore only affect current user; private IDs/names/progress are absent from URL, HTML metadata and shared cache.
- Confirm current title/hidden/unpublished/deleted/nonwatchable entries are absent where promised; related precedes similar; v4 rows remain readable until v5 rebuild.
- Confirm route/localized/legacy resolution, API shape, noindex/canonical/hreflang/sitemap policy, `ru`/`en` key parity, keyboard focus, mobile overflow and Vite assets.
- Verification for Task 18 intentionally uses lint, Pint, schema/route/query inspection, build and browser smoke only; no automated tests are created or run per the task instruction.
