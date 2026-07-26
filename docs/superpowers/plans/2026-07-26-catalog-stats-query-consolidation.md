# Catalog Stats Query Consolidation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сократить exact cold rebuild `/stats` без изменения публичного
snapshot, visibility, cache, schema или write paths.

**Architecture:** Существующий `CatalogStatsPageBuilder` остаётся
единственным query owner. Private request-local profiles объединяют
повторные table/present/URL/public-media/index counts; SQLite-specific
metadata paths защищены driver guard и сохраняют portable fallback.

**Tech Stack:** PHP 8.5, Laravel 13.22, Eloquent/Query Builder, SQLite,
PHPUnit 12.5.

## Global Constraints

- Работать только в существующей ветке `main`, без branch/worktree.
- Не изменять schema, cache key/version/TTL, route, translation, Blade,
  Livewire, importer, queue, dependency, config или `.env`.
- Сохранить exact public media predicates
  `availableTo(null) → forAvailableReleases(null) → visible title IDs`.
- Не выполнять DML, cache clear/flush или production migration.
- Все изменения кода проходят TDD RED до implementation.
- Shared worktree содержит чужие изменения; commit формируется только
  точным alternate index.

---

### Task 1: RED для query-shape и exact values

**Files:**

- Create: `tests/Feature/CatalogStatsQueryConsolidationTest.php`
- Reference: `tests/Feature/CatalogPageTest.php`
- Reference: `app/Services/Catalog/CatalogStatsPageBuilder.php`

**Interfaces:**

- Consumes: `CatalogStatsPageBuilder::data(): array<string, mixed>`.
- Produces: regression contract для combined media counts, URL profiles,
  SQLite table/index inventory и duplicate missing-release counts.

- [x] **Step 1: Создать fixture с duplicate/relative/HTTP/HTTPS media URL**

Использовать `RefreshDatabase`, `CatalogTitle`, `Season`, `Episode` и
`LicensedMedia` factories. Создать две доступные серии и media rows:

```php
LicensedMedia::factory()->create([
    'catalog_title_id' => $title->id,
    'season_id' => $season->id,
    'episode_id' => $firstEpisode->id,
    'path' => 'https://media.example.com/shared.mp4',
    'playback_url' => 'https://media.example.com/shared.mp4',
    'source_url' => 'https://seasonvar.ru/source-one.html',
    'status' => 'published',
    'published_at' => now(),
]);
```

Вторая строка повторяет URL, третья использует relative path и отдельную
series identity. Assertions читают существующие
`internalLinkRows`/`externalUrlFieldRows`, поэтому production output shape
не меняется ради теста.

- [x] **Step 2: Записать SQL только вокруг `data()`**

```php
$queries = [];

DB::listen(function (QueryExecuted $query) use (&$queries): void {
    $queries[] = Str::squish($query->sql);
});

$data = app(CatalogStatsPageBuilder::class)->data();
```

- [x] **Step 3: Зафиксировать отсутствующие combined boundaries**

Проверить:

```php
$this->assertCount(1, array_filter(
    $queries,
    fn (string $sql): bool => str_contains($sql, 'as "video_count"')
        && str_contains($sql, 'as "episode_count"'),
));

$this->assertCount(1, array_filter(
    $queries,
    fn (string $sql): bool => str_contains($sql, 'as "path_distinct"')
        && str_contains($sql, 'as "playback_url_distinct"')
        && str_contains($sql, 'as "source_url_distinct"'),
));

$this->assertSame([], array_values(array_filter(
    $queries,
    fn (string $sql): bool => str_starts_with($sql, 'PRAGMA index_list(')
        || str_starts_with($sql, 'PRAGMA index_info('),
)));
```

Отдельно проверить отсутствие individual table-count SQL для
`migrations`, ровно один SQLite index-inventory statement и ровно один
count statement каждой формы «title/episode without published media».

- [x] **Step 4: Проверить exact presentation values**

Найти строки по stable label и сравнить integer `count` для public video и
episode links, а также `filled_display`, `unique_display`,
`absolute_display` и `empty_display` URL-строк. Форматированные значения
нормализовать через явные ожидаемые строки с пробелами тысячных разрядов.

- [x] **Step 5: Запустить RED**

Run:

```bash
php artisan test tests/Feature/CatalogStatsQueryConsolidationTest.php
```

Expected: semantic value assertions проходят, query-shape assertions падают
из-за двух отдельных media statements, per-index PRAGMA и отдельных URL
queries.

### Task 2: Объединить factual counters

**Files:**

- Modify: `app/Services/Catalog/CatalogStatsPageBuilder.php`
- Test: `tests/Feature/CatalogStatsQueryConsolidationTest.php`

**Interfaces:**

- Produces:
  - `private function publicMediaLinkCounts(): array{videos: int, episodes: int}`
  - `private function titlesWithoutPublishedMediaCount(): int`
  - `private function episodesWithoutPublishedMediaCount(): int`

- [x] **Step 1: Добавить request-local properties**

```php
/** @var array{videos: int, episodes: int}|null */
private ?array $publicMediaLinks = null;

private ?int $titlesWithoutPublishedMedia = null;

private ?int $episodesWithoutPublishedMedia = null;
```

- [x] **Step 2: Реализовать один public media aggregate**

Собрать прежний Eloquent scope, затем:

```php
$row = $query
    ->selectRaw(
        'COUNT(CASE WHEN licensed_media.playback_url LIKE ?'
        .' OR licensed_media.playback_url LIKE ?'
        .' OR licensed_media.path LIKE ?'
        .' OR licensed_media.path LIKE ? THEN 1 END) AS video_count',
        ['https://%', 'http://%', 'https://%', 'http://%'],
    )
    ->selectRaw(
        'COUNT(DISTINCT CASE WHEN licensed_media.episode_id IS NOT NULL'
        .' THEN licensed_media.episode_id END) AS episode_count',
    )
    ->toBase()
    ->first();
```

Оба прежних private methods возвращают соответствующее значение одного
memoized array.

- [x] **Step 3: Memoize одинаковые missing-release counts**

Перенести два существующих `whereDoesntHave()` count в названные private
methods и использовать их одновременно в summary/quality sections.
`statsIssueRows()` остаётся отдельной bounded row selection.

- [x] **Step 4: Запустить focused test**

Run:

```bash
php artisan test tests/Feature/CatalogStatsQueryConsolidationTest.php
```

Expected: public media и duplicate-count assertions GREEN; URL/metadata
assertions ещё RED.

### Task 3: Batch present и URL metrics

**Files:**

- Modify: `app/Services/Catalog/CatalogStatsPageBuilder.php`
- Test: `tests/Feature/CatalogStatsQueryConsolidationTest.php`

**Interfaces:**

- Produces:
  - `private function loadPresentCounts(string $table, string $requiredColumn): void`
  - `private function loadExternalUrlCounts(string $table): void`

- [x] **Step 1: Добавить static present-column map**

Карта содержит union всех существующих `presentCount()`/`missingCount()`
полей и URL fields. Все columns application-owned; unknown required column
добавляется только в текущий internal query.

- [x] **Step 2: Собрать conditional present aggregate**

Для каждого ещё не загруженного столбца grammar формирует identifier:

```php
'SUM(CASE WHEN '.$wrapped.' IS NOT NULL AND '.$wrapped
.' != ? THEN 1 ELSE 0 END) AS '.$grammar->wrap($alias)
```

Один row заполняет прежний `$presentCounts`; empty table даёт `0`.

- [x] **Step 3: Собрать три URL metric на столбец**

Один statement на table содержит:

```sql
COUNT(CASE WHEN column IS NOT NULL AND column != ? THEN 1 END)
COUNT(DISTINCT CASE WHEN column IS NOT NULL AND column != ? THEN column END)
COUNT(CASE WHEN column LIKE ? OR column LIKE ? THEN 1 END)
```

Результат заполняет существующие present/distinct/absolute caches.
`externalUrlFieldRows()` и его output shape остаются прежними.

- [x] **Step 4: Запустить focused test**

Run:

```bash
php artisan test tests/Feature/CatalogStatsQueryConsolidationTest.php
```

Expected: media/URL/value assertions GREEN; SQLite metadata assertions ещё
RED.

### Task 4: Объединить SQLite metadata inventory

**Files:**

- Modify: `app/Services/Catalog/CatalogStatsPageBuilder.php`
- Test: `tests/Feature/CatalogStatsQueryConsolidationTest.php`

**Interfaces:**

- Preserves:
  `Collection<int, array{table: string, name: string, unique: bool,
  origin: string, partial: bool, columns: string}>`.

- [x] **Step 1: Warm exact table counts одним SQLite statement**

`databaseTables()` вызывает `loadTableCounts($this->tableNames())`.
Driver-guarded SQL состоит из grammar-wrapped
`SELECT ? AS table_name, COUNT(*) ... UNION ALL ...`.
При exception/non-SQLite прежний `tableCount()` fallback выполняется
без скрытого значения.

- [x] **Step 2: Заменить per-index PRAGMA**

Один read-only statement:

```sql
SELECT table_name, index_name, is_unique, origin, partial,
       GROUP_CONCAT(column_name, CHAR(44) || CHAR(32)) AS columns
FROM (
    SELECT schema.name AS table_name,
           indexes.name AS index_name,
           indexes."unique" AS is_unique,
           indexes.origin,
           indexes.partial,
           columns.name AS column_name,
           columns.seqno AS column_order
    FROM sqlite_schema AS schema
    JOIN pragma_index_list(schema.name) AS indexes
    JOIN pragma_index_info(indexes.name) AS columns
    WHERE schema.type = ? AND schema.name NOT LIKE ?
    ORDER BY schema.name, indexes.name, columns.seqno
)
GROUP BY table_name, index_name, is_unique, origin, partial
ORDER BY table_name, index_name
```

Map aliases в прежние typed rows. Non-SQLite и Throwable сохраняют empty
collection.

- [x] **Step 3: Удалить больше не используемые helpers**

Удалить `sqliteIndexColumns()` только после repository search; сохранить
`sqliteLiteral()` лишь если остались реальные consumers. Не оставлять dead
compatibility code.

- [x] **Step 4: Запустить полный focused GREEN**

Run:

```bash
php artisan test tests/Feature/CatalogStatsQueryConsolidationTest.php
php artisan test --filter=CatalogStats
php artisan test tests/Feature/CatalogPageTest.php --filter=stats
```

Expected: all pass, нет per-table/per-index metadata loops.

### Task 5: Profile, parity и regression matrix

**Files:**

- Modify only if a demonstrated defect appears:
  `app/Services/Catalog/CatalogStatsPageBuilder.php`
- Test:
  `tests/Feature/CatalogStatsQueryConsolidationTest.php`

**Interfaces:**

- Preserves full `CatalogStatsSnapshotSanitizer::sanitize()` output.

- [x] **Step 1: Запустить before/after production-scale profile**

Direct builder profile записывает elapsed, query count, SQL total, top
shapes и canonical payload hash. Выполнить минимум три последовательных
samples без cache/DML.

- [x] **Step 2: Проверить exact parity**

Сравнить сохранённый baseline и новый snapshot по stable sections,
collections converted to arrays, integer values, route names и URL metric
rows. Time-dependent duration/current-run fields сравнивать по domain
meaning, а не по byte hash между разными моментами.

- [x] **Step 3: Форматирование и static gates**

```bash
./vendor/bin/pint --dirty --format agent
composer analyse
composer rector:check
git diff --check
```

- [x] **Step 4: Adjacent и full tests**

```bash
php artisan test --filter=CatalogPageTest
php artisan test --filter=CatalogStatsSnapshot
php artisan test --filter=CatalogCache
php artisan test
```

Если полный default process достигает известного `256M` ceiling, точный
failed test/file повторяется отдельно; outcome остаётся `unresolved`, а не
маскируется.

### Task 6: Documentation, compliance, commit и push

**Files:**

- Modify: `docs/performance.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `docs/maintenance/technical-debt.md` только если статус `TD-011`
  фактически меняется
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**

- Preserve managed `project-docs` blocks unless their source actually
  changed.

- [x] **Step 1: Записать measured result**

Указать before/after query count, elapsed/SQL samples, exact parity,
remaining expensive shapes и отсутствие schema/cache/route changes. Не
объявлять локальное наблюдение SLA/p95.

- [x] **Step 2: Перечитать requirements и выполнить legacy scan**

Проверить duplicate stats builders, old per-index PRAGMA helpers, stale
cache paths, TODO/FIXME, routes, migrations, translations, permissions,
Livewire/Blade/JS и importer references.

- [x] **Step 3: Обновить compliance matrix**

Каждый пункт получает `completed`, `already_compliant`,
`not_applicable` или `unresolved` с evidence.

- [x] **Step 4: Собрать exact alternate-index commit**

Включить только Task 72 files и task-specific hunks shared docs. Проверить:

```bash
git status --short --branch
git diff --cached --check
git diff --cached --name-status
```

Commit message:

```text
perf: consolidate catalog stats queries
```

- [x] **Step 5: Отправить configured remote**

```bash
composer git:doctor -- --remote
git push
```

External authentication/network rejection фиксируется как `unresolved`;
успешный local commit не выдаётся за push.

Execution outcome: exact seven-file commit `ab7ac83` created on `main`.
`composer git:doctor -- --remote` was blocked by the root Composer plugin
policy; `GIT_TERMINAL_PROMPT=0 git push origin main` was then attempted and
rejected before transfer because configured HTTPS credentials are
unavailable. Push status remains `unresolved_external_auth`.

## Post-delivery independent review correction

- [x] Reviewer inspected committed range `bec22d0..d776ee6`.
- [x] Integration RED proved that a version-bumped rebuild through the same
  `CatalogStatsSnapshotCache` graph retained memoized builder values.
- [x] `CatalogStatsPageBuilder::data()` now resets all eight build-local
  caches before every exact rebuild.
- [x] Regression coverage includes same-graph cache invalidation, table and
  index changes, both missing-media counters and draft-media visibility.
- [x] Production read-only parity matched 21 URL fields, 159 table counts
  and 519 index rows; combined/sequential resource samples recorded.
- [x] Exact seven-file review-fix commit `c53e17c` created on `main`;
  configured non-force push retried and rejected before transfer because
  HTTPS credentials remain unavailable.
