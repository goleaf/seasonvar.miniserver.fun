# Compact snapshot метаданных справочников: дизайн

Дата: 26.07.2026.

Статус: approved — пользователь многократно поручил самостоятельно выбирать
измеренные улучшения, обновлять безлимитный план и продолжать реализацию.

## Цель

Ускорить публичные summary и алфавит одиннадцати каталогов-справочников,
сохранив точные значения, текущую видимость тайтлов, locale-aware названия
тегов, web/API shape, маршруты, сортировку, фильтры и importer write paths.

## Подтверждённый root cause

После bounded name-order оптимизации Task 82 страница справочника получает
первые 36–48 значений быстро, но до неё независимо выполняет два глобальных
запроса:

1. `summary()` считает два `COUNT(DISTINCT)` по всем видимым pivot-связям;
2. `letters()` применяет label expression к каждой видимой pivot-связи и
   только затем группирует инициалы.

На production-scale SQLite с активным importer actor-directory содержит
111 714 видимых значений и 28 199 связанных тайтлов. Diagnostic medians:

- summary — `233,58 ms`;
- alphabet — `729,88 ms`;
- cold pair — около `963 ms`.

У tags current alphabet занял `431,89 ms`. Предварительная дедупликация
видимых `tag_id` до вычисления locale label дала `255,26 ms` с тем же
набором initial rows. У actors аналогичная форма дала `335,62 ms`.
Измерения под конкурентной нагрузкой являются локальной диагностикой, а не
p95/SLA.

`EXPLAIN QUERY PLAN` подтверждает, что текущий alphabet вычисляет label и
temporary `GROUP BY` после прохода по каждой pivot-связи. Двусторонние
covering pivot-индексы и title primary key уже существуют.

## Рассмотренные подходы

### 1. Новые expression indexes или поле initial

Отклонено. Для кириллицы и locale-aware tag labels перенос initial в schema
потребует migration/backfill, importer/admin maintenance, reindex/rollback и
разных DB-specific правил Unicode. Цена write amplification не оправдана.

### 2. Отдельная materialized directory table

Отклонено. Новый read model потребует authoritative hooks для каждой
title/taxonomy/translation/pivot mutation, reconciliation, deployment
sequence, backup и data rollback. Для compact публичных aggregates в проекте
уже существует versioned `CatalogFacetSnapshotCache`.

### 3. Только переписать summary на split queries

Отклонено. Отдельный grouped values count плюс title `EXISTS` дал около
`310,80 ms` у actors против `233,58 ms` текущего single statement. Текущий
summary остаётся cold rebuild owner.

### 4. Дедупликация alphabet плюс существующий facet snapshot — выбран

Cold alphabet сначала формирует подзапрос уникальных visible taxonomy IDs,
затем один раз вычисляет label/initial для каждого ID. Summary и alphabet
хранятся отдельными compact scalar/list snapshots через существующий
`CatalogFacetSnapshotCache`.

Такой вариант:

- ускоряет доказанный cold alphabet root cause;
- делает повторные SSR/API/warm reads cache-backed;
- переиспользует существующие TTL, stale, lock, telemetry и after-commit
  version invalidation;
- не добавляет service, migration, index, dependency или DML.

## Архитектура

`CatalogDirectoryQuery` остаётся единственным query owner.

Public flow:

`CatalogDirectoryPageBuilder / CatalogDirectoryController`
→ `CatalogDirectoryQuery`
→ `CatalogFacetSnapshotCache`
→ compact summary или alphabet rebuild
→ существующий SQL visibility boundary.

### Summary snapshot

- Resource: `directory-summary-v1`.
- Dimensions: stable directory key.
- Payload: одна строка только с integer `values` и `titles`.
- Rebuild: прежний exact SQL без semantic rewrite.

### Alphabet snapshot

- Resource: `directory-alphabet-v1`.
- Dimensions: stable directory key и effective label locale только там, где
  label зависит от locale.
- Payload: список строк только с normalized letter.
- Rebuild:
  1. выбрать visible related IDs из canonical pivot;
  2. для tags применить прежний `publiclyEligible()` scope;
  3. сгруппировать IDs до join taxonomy table;
  4. вычислить прежний localized/fallback/canonical label;
  5. нормализовать буквы и символы прежним PHP boundary.

`CatalogFacetSnapshotCache` уже использует `CatalogFacets` version,
`300 s` fresh, `1 800 s` stale, `120 s` hot, distributed rebuild lock и
database fallback при cache failure. `CatalogCacheInvalidator::catalogChanged`
и `TagCacheInvalidator::publicChanged` уже повышают этот version after
commit. Store-wide flush не добавляется.

## Exact compatibility

- `CatalogEntitlementService` через `CatalogTitleQuery::visibleTo(null)`
  остаётся единственным title visibility owner.
- Summary продолжает считать distinct related ID и distinct title ID.
- Alphabet поддерживается только actors, directors и tags.
- Пустые/null taxonomy names по-прежнему не создают букву.
- Tag eligibility, active locale, fallback locale и canonical name имеют
  прежний приоритет.
- `#`, кириллица, латиница, uppercase normalization и sort остаются в
  `CatalogDirectoryQuery`/`CatalogAlphabet`.
- Повторяющиеся pivot links никогда не меняют summary или alphabet.
- Web и API по-прежнему получают `summary`, `alphabet`, `decades`, pagination
  и Resources прежней формы.

Если active locale совпадает с fallback locale, label SQL выполняет один
translation lookup вместо двух идентичных scalar subqueries. Это не меняет
приоритет или результат.

## Cross-feature impact

- Web directory routes, full-page Livewire, query parameters и SEO не
  меняются.
- `/api/v1/catalog/directories/{directory}`, Resource/OpenAPI shape и
  validators не меняются.
- Public page cache продолжает быть внешним HTML envelope; новый snapshot
  хранит только compact metadata, не HTML.
- Cache warming получает те же query methods и автоматически наполняет
  snapshot без нового queue/scheduler path.
- Search, title filters, detail routes, sitemap, recommendations,
  authentication, authorization, Premium, payments, advertisements,
  regional/legal access, notifications и personal state не меняются.
- Importer/admin/tag writes не получают новый invalidation owner и используют
  существующую after-commit boundary.
- Translations, UI markup, JavaScript, CSS и responsive behavior не меняются.

## Production, data safety и rollback

Изменение выполняет только `SELECT` и recomputable cache writes. Database
schema/data, packages, environment, routes, queues и scheduler не меняются.
Backup для code/cache-only activation не требуется; обычный deployment
backup и readiness contract сохраняется.

При недоступном Redis/Memcached `TieredCache` выполняет authoritative DB
rebuild и не превращает cache failure в ошибку каталога. Stale payload
ограничен существующим catalog-facets policy.

Rollback — revert task commit и graceful runtime reload. Новые versioned
cache keys становятся недоступными естественно; restore, reindex, backfill,
reconciliation и cache clear не нужны.

## Тестирование

1. RED фиксирует, что alphabet query сначала группирует visible taxonomy IDs
   и только затем join-ит label table.
2. Fixture проверяет exact summary/alphabet для visible, draft, future,
   expired, deleted, duplicate-related и symbol/cyrillic/latin values.
3. Cache regression доказывает повторное чтение без DB rebuild и обновление
   после `CatalogFacets` version bump.
4. Tag regression проверяет eligibility, locale/fallback и отсутствие
   duplicate fallback lookup при одинаковых locales.
5. Existing page/API/cache-warming/SEO tests подтверждают public contracts.
6. Production-scale profile сравнивает cold summary/alphabet, hot snapshot,
   payload shape и `EXPLAIN QUERY PLAN`.

## Вне scope

- изменение summary SQL;
- новые индексы, columns или materialized tables;
- изменение cache TTL/store/version owners;
- approximate counts;
- изменение алфавитных групп или отображения;
- изменение directory routes/API/SEO;
- любой foreign shared-worktree scope.
