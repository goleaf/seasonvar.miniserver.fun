# Рейтинг качества и очистка подборок — дизайн

Дата: 26.07.2026.

Статус: `approved_for_implementation`.

## Подтверждённый baseline

Read-only аудит текущей SQLite подтвердил:

- 1 403 подборки, все без категории;
- 501 legacy-запись имеет `public + approved + published`, но уже не проходит
  canonical public scope из-за отсутствующей категории и/или размера;
- 1 349 подборок содержат более 500 тайтлов, максимум — 4 252;
- 39 подборок пусты, только 15 имеют от 1 до 500 элементов;
- есть exact duplicate composition groups и повторяющиеся шаблонные названия;
- likes, follows и collaborators не имеют таблиц, сервисов или public
  contracts;
- реальные сигналы watchlist, reports, completion и повторного просмотра
  уже хранятся в `catalog_title_user_states`,
  `catalog_collection_reports` и `episode_view_progress`.

Существующие category/public eligibility, editorial readiness и exact
demo/HDRezka quarantine сохраняются. Широкое удаление по имени, описанию,
размеру или fuzzy similarity запрещено.

## Цель

Каждая manual collection получает объяснимую, повторяемую оценку от 0 до
100 и evidence:

- качество названия, описания и категории;
- размер, доступность и связность состава;
- exact duplicate composition;
- повторяющийся или похожий шаблонный текст;
- средний тематический match и покрытие причин добавления;
- open/reviewed/upheld reports;
- агрегированные watchlist, completion и return signals;
- актуальность оценки относительно `content_version`;
- редакционную верификацию точной версии.

Public publication и recommendations не доверяют stale или низкой оценке.
Legacy nullable score нужен только для безопасного rolling rollout; после
первой оценки current version обязана иметь score не ниже configured minimum.
Новая moderation/publication всегда требует current score.

## Выбранная data model

Additive migration:

### `catalog_collections`

- nullable `quality_score` (`0..100`);
- nullable `quality_content_version`;
- nullable `quality_evaluated_at`;
- nullable SHA-256 `content_signature`;
- nullable SHA-256 `normalized_text_hash`;
- nullable JSON `quality_details` только с bounded counters/codes;
- nullable `editorially_verified_at`;
- nullable `editorially_verified_by_id`;
- nullable `editorially_verified_content_version`.

Score и verification не увеличивают `content_version`: они подтверждают
конкретную уже существующую версию. Любая content mutation делает evidence
stale сравнением version, даже если отдельный write boundary не успел
обнулить derived columns.

### `catalog_collection_items`

- nullable `theme_match_percent` (`0..100`);
- nullable stable `inclusion_reason_code`;
- nullable `quality_content_version`.

Public UI переводит stable reason code. Free-form автоматически
сгенерированный рекламный текст в DB не хранится.

### `catalog_collection_quality_issues`

Хранит collection FK, nullable related collection FK, stable code/severity,
unique fingerprint, status `open|resolved`, bounded JSON evidence и
timestamps. Fuzzy issue является сигналом модерации, а не destructive
identity decision.

### `catalog_collection_quality_runs`

Хранит status, bounded counters, started/completed timestamps и безопасное
error summary. Run не хранит пользовательские тексты, emails, source URLs,
title IDs или exception traces.

## Оценка

`CatalogCollectionQualityEvaluator` возвращает score, components, metrics,
item matches и issue candidates. Итог:

- metadata clarity — 25;
- bounded/watchable structure — 25;
- thematic coherence и reasons coverage — 30;
- audience/editorial trust — 20;
- exact non-canonical duplicate penalty — 35;
- strong similar/template penalty — до 20.

Score всегда clamp-ится в `0..100`. Config хранит minimum public score,
batch size, stale interval и similarity thresholds. Нулевые engagement
signals не считаются ошибкой и не создают fake popularity.

### Реальные engagement signals

- «сохранения» — distinct user/title rows с `in_watchlist=true` среди
  элементов подборки;
- «досмотры» — distinct user/title с completed watch status или завершённой
  серией;
- «возвраты» — distinct user/title, где пользователь начал минимум две
  разные серии;
- «жалобы» — collection reports кроме `dismissed`.

Это агрегаты качества состава, а не доказательство причинной атрибуции
перехода из подборки. User IDs и индивидуальное поведение не выводятся.

## Theme match и причина

`CatalogCollectionThemeMatcher` переиспользует
`CatalogCollectionCategorySuggestionRules`. Для активной category rule
сопоставляются genre, country, network/studio, type и текстовые признаки.
Результат хранит percentage и один stable reason code:

- `category_genre`;
- `category_country`;
- `category_platform`;
- `category_type`;
- `title_theme`;
- `source_rule`;
- `editorial_choice`;
- `manual_choice`;
- `smart_rule`.

Source-managed manual collection получает badge «Динамическая подборка» и
`source_rule`. Smart owner-only collection уже является динамической и
получает `smart_rule`, но остаётся private и не участвует в public score,
duplicates или recommendations. Процент `100` для smart result означает
точное прохождение сохранённых server-owned rules, а не редакционную оценку.

## Duplicate и similarity

Composition signature — SHA-256 отсортированных numeric title IDs.
Canonical exact duplicate выбирается детерминированно как более ранний
persisted ID. Редакционная верификация не меняет identity дубля и остаётся
подтверждением точной версии выбранной записи.

Non-canonical row получает issue и penalty. Exact duplicate не hard-delete-ится.

Text normalization использует Unicode lowercase, NFKC, удаление punctuation,
whitespace squish и bounded meaningful token set. Exact normalized hashes
находятся напрямую. Fuzzy Jaccard сравнение выполняется только внутри
bounded candidate buckets с общей meaningful token и совместимой длиной;
полный O(n²) request-time scan запрещён. Similar text создаёт issue, но не
меняет identity и не удаляет запись.

## Автоматическое обновление

`catalog-collections:quality-refresh`:

- default обрабатывает bounded stale/dirty batch по ID;
- `--all` разрешает полный operator run, всё равно chunked;
- `--dry-run` не пишет;
- run/assessment/item/issue updates транзакционны на collection;
- issue fingerprints upsert-ятся, исчезнувшие issues resolve-ятся;
- changed public eligibility инвалидирует существующие collection,
  homepage, sitemap, title-detail, recommendation и API domains.

Schedule запускает bounded refresh каждые десять минут с
`withoutOverlapping()`/`onOneServer()`. Внешние HTTP calls отсутствуют.
Exact demo/source visibility quarantine остаётся отдельной
dry-run-first operator boundary с backup/writer assertions.

## Public, recommendation и moderation

- категория остаётся обязательной;
- structural cap `500` остаётся hard gate;
- current assessed version требует `quality_score >= minimum`;
- stale assessed version fail-closed;
- legacy never-assessed row допускается только rolling compatibility path,
  но не может получить новую public approval/feature/verification без current
  assessment;
- recommendation и featured boundaries требуют current score без legacy
  fallback;
- thousand-item list не получает recommendation signal независимо от score;
- collection with precise private smart rule не становится public.

`CatalogCollectionModerationService` перед public approval синхронно
оценивает locked current row и отказывает ниже threshold. Редакционная
verification доступна только `collections.moderate`, только current
approved/public/editorial collection с достаточным score и пишет audit.
Content mutation автоматически делает badge недействительным через version.

## Admin и public UX

В существующем `/admin/catalog?section=collections` добавляется quality
filter/queue:

- низкий score;
- exact duplicates;
- похожий/шаблонный текст;
- низкий theme match;
- oversized/empty/missing category;
- reports;
- stale/unassessed.

Карточка показывает score, current/stale, components, privacy-safe aggregate
signals, issues, duplicate relation и verification control. Никакого второго
admin route или controller нет.

Public collection card/detail показывают:

- score только когда current;
- «Проверено редакцией» только при exact version match;
- «Динамическая подборка» для smart/source-managed;
- у каждого item percentage и короткую локализованную причину.

Loading/empty/error states остаются в существующем Livewire flow. RU/EN keys
добавляются с одинаковой структурой.

## Security, performance и operations

- browser не передаёт score, signature, metrics, canonical duplicate,
  verification version или issue status;
- all writes повторно resolve/authorize/lock server records;
- SQL использует bindings/query builder; raw user input отсутствует;
- details JSON allowlisted и bounded;
- public/API/cache не содержат user IDs или individual engagement;
- migration не выполняет heavy backfill;
- batch читает item rows одним ordered stream и aggregates grouped queries;
- theme hydration выполняется только для collections не больше public cap;
- indexes добавляются только для dirty queue, signature lookup и admin issue
  queue; отдельный public-quality index не добавляется, поскольку
  `EXPLAIN QUERY PLAN` выбирает существующий
  `catalog_collections_public_idx`;
- production rollout: backup → additive migration → code → scheduler/manual
  canary → bounded refresh → public/admin/browser checks;
- rollback code сохраняет additive columns/tables; destructive `down` после
  накопления evidence не рекомендуется, но migration технически обратима.

## Acceptance

- current 501 noisy public rows остаются скрыты;
- exact demo quarantine не расширяет destructive match;
- exact composition и similar text issues детектируются;
- category и current minimum score обязательны для новой публикации;
- >500 items не рекомендуются и не публикуются;
- dynamic/editorial badges truthful и version-aware;
- item percentage/reason отображаются без N+1;
- score учитывает реальные aggregate saves/reports/completions/returns;
- likes/follows/collaborators не появляются;
- filters, sort, pagination, API, sitemap, profiles, title relations,
  recommendations и caches сохраняют contracts;
- focused/broad tests, Pint, PHPStan, Vite, migrations, EXPLAIN и browser QA
  проходят либо blocker записывается точно.
