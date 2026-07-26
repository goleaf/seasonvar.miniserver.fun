# Catalog Stats Adaptive Distinct URL Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Уменьшить cold SQL cost `/stats`, удалив redundant
`COUNT(DISTINCT playback_url)` только когда тот же authoritative statement
доказал точную эквивалентность `path` и `playback_url`.

**Architecture:** Private helper `CatalogStatsPageBuilder::
loadExternalUrlCounts()` выполняет primary grouped aggregate с distinct для
`path`/`source_url` и mismatch count пары `path`/`playback_url`. При нулевом
mismatch он переиспользует exact `path_distinct`; иначе прежний отдельный
exact count остаётся fallback. Schema, cache и public DTO не меняются.

**Tech Stack:** PHP 8.5, Laravel 13.22, PHPUnit 12.5, SQLite 3.46, Laravel
query builder.

## Global Constraints

- Работать только в существующей `main`, не создавать branch/worktree.
- Не изменять schema, indexes, routes, translations, cache identity/TTL или
  `licensed_media` write paths.
- Сохранять текущую filled-семантику: `IS NOT NULL AND value != ''`.
- Fast-path разрешён только при mismatch count `0` из того же primary query.
- Любой mismatch обязан выполнить прежний exact distinct fallback.
- Не использовать `PRAGMA temp_store=MEMORY`: measured RSS 314 796 KiB
  превышает worker limit 256 MiB.
- Production code появляется только после наблюдаемого focused RED.
- Чужие shared-worktree изменения не добавляются в task commit.

---

### Task 1: Зафиксировать adaptive SQL contract тестами

**Files:**

- Modify: `tests/Feature/CatalogStatsQueryConsolidationTest.php`

**Interfaces:**

- Consumes: `CatalogStatsPageBuilder::data(): array<string,mixed>`.
- Produces: query-shape и exact-value regression для fast-path и fallback.

- [x] **Step 1: Добавить failing fast-path test**

Создать fixture с duplicate одинаковым URL, эквивалентной empty-парой
`path=''`/`playback_url=NULL` и одинаковым whitespace value, затем собрать
`QueryExecuted`. Проверить все filled/distinct/absolute/empty значения,
точный selector-dependent порядок bindings и query shape:
Проверить:

```php
$this->assertCount(1, $this->queriesContainingAll($queries, [
    'path_distinct',
    'source_url_distinct',
    'playback_url_path_mismatches',
]));
$this->assertSame([], $this->queriesContainingAll($queries, [
    'playback_url_distinct',
]));
```

Также проверить одинаковое `unique_display` для «Ссылка на видео» и
«Ссылка воспроизведения».

- [x] **Step 2: Обновить mismatch contract существующего теста**

Текущий mixed fixture с `playback_url=null` обязан утверждать:

```php
$this->assertCount(1, $this->queriesContainingAll($queries, [
    'path_distinct',
    'source_url_distinct',
    'playback_url_path_mismatches',
]));
$this->assertCount(1, $this->queriesContainingAll($queries, [
    'count(distinct',
    'playback_url',
    'from "licensed_media"',
]));
```

Прежние exact values `3/2/3/1` для playback URL остаются без изменений.

- [x] **Step 3: Запустить RED**

Run:

```bash
php artisan test tests/Feature/CatalogStatsQueryConsolidationTest.php
```

Expected: новый fast-path test и обновлённый query-shape assertion падают,
потому что production query всё ещё содержит три distinct aggregate и не
содержит mismatch alias.

Observed: 3 tests, 1 passed, 2 failed exactly because the primary query did
not contain `playback_url_path_mismatches`.

### Task 2: Реализовать минимальный adaptive exact aggregate

**Files:**

- Modify: `app/Services/Catalog/CatalogStatsPageBuilder.php`
- Test: `tests/Feature/CatalogStatsQueryConsolidationTest.php`

**Interfaces:**

- Consumes: existing `externalUrlFields()` и per-build count arrays.
- Produces: те же `presentCounts`, `distinctPresentCounts`,
  `absoluteUrlCounts`.

- [x] **Step 1: Определить targeted pair внутри helper**

В `loadExternalUrlCounts()` вычислить:

```php
$reusesPlaybackDistinct = $table === 'licensed_media'
    && $fields->contains('column', 'path')
    && $fields->contains('column', 'playback_url');
```

- [x] **Step 2: Удалить только redundant selector**

Для `playback_url` не добавлять primary distinct selector, когда targeted
pair присутствует. Filled и absolute selectors остаются прежними.

- [x] **Step 3: Добавить portable mismatch selector**

Добавить в primary aggregate alias
`playback_url_path_mismatches`:

```sql
COUNT(CASE WHEN
    ((path IS NULL OR path = '') AND playback_url IS NOT NULL AND playback_url != '')
    OR ((playback_url IS NULL OR playback_url = '') AND path IS NOT NULL AND path != '')
    OR (path IS NOT NULL AND path != ''
        AND playback_url IS NOT NULL AND playback_url != ''
        AND path != playback_url)
THEN 1 END)
```

Все empty-string bindings передаются query builder, не интерполируются из
input.

- [x] **Step 4: Заполнить exact fast-path или fallback**

После primary query:

```php
$playbackMatchesPath = $reusesPlaybackDistinct
    && (int) ($row->playback_url_path_mismatches ?? -1) === 0;
```

Для `playback_url` при `true` использовать `path_distinct`. При `false`
private helper немедленно выполняет canonical
`queryDistinctPresentCount()` fallback и заполняет тот же build-local count
key, не повторяя primary aggregate.

- [x] **Step 5: Запустить focused GREEN**

Run:

```bash
php artisan test tests/Feature/CatalogStatsQueryConsolidationTest.php
```

Expected: все tests pass; fast fixture не выполняет playback distinct
fallback, mixed fixture выполняет ровно один.

Observed after review hardening/refactor/Pint: 4/4 tests passed, 33
assertions. Дополнительный fixture с двумя filled, но различающимися URL
доказывает exact fallback при разных distinct cardinalities.

### Task 3: Проверить snapshot/cache/public compatibility

**Files:**

- No production file changes expected.

**Interfaces:**

- Consumes: existing snapshot/cache/sanitizer/public page contracts.
- Produces: regression evidence без изменения public DTO.

- [x] **Step 1: Запустить stats matrix**

```bash
php artisan test --filter=CatalogStats
php artisan test tests/Feature/CatalogPageTest.php --filter=stats
```

Expected: pass; snapshot invalidation видит новые rows на том же service
graph.

- [x] **Step 2: Запустить форматирование и static gates**

```bash
./vendor/bin/pint --dirty --format agent
composer analyse
composer rector:check
```

Expected: exit `0`.

Observed: Pint, PHPStan and scoped Rector passed. Repository-wide Rector
reported only two foreign collection files requiring `never` return types;
Task 75 files had zero proposed changes.

- [x] **Step 3: Проверить exact SQL values на production-scale read-only DB**

Запустить baseline и adaptive SQL в отдельных `sqlite3 -readonly` процессах
минимум по три раза. Сравнить все nine scalar values; записать elapsed,
maximum RSS, filesystem output и active importer context.

- [x] **Step 4: Проверить полный builder**

В отдельном PHP process вызвать `CatalogStatsPageBuilder::data()`, записать
query count/wall/query time и hashes 21 external URL fields. Сравнить с
Task 72 exact shape/evidence.

Observed: same-transaction baseline/adaptive values matched across all nine
metrics with mismatch `0`; three builders retained 142 queries with wall
times 23 800,77 / 24 438,56 / 25 062,60 ms. Fresh post-review builder also
retained 142 queries: wall `25 139,20 ms`, SQL `21 853,90 ms`, maximum RSS
`76 424 KiB`.

### Task 4: Документация, final audit и delivery

**Files:**

- Modify: `docs/performance.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Preserve: all unrelated shared-worktree files.

**Interfaces:**

- Produces: canonical measured evidence, visitor history, Russian changelog
  и final compliance matrix.

- [x] **Step 1: Обновить performance owner**

Добавить только measured before/after, exact fallback contract, отсутствие
schema/cache changes и rejected memory/index/materialization alternatives.

- [x] **Step 2: Обновить README и CHANGELOG**

Добавить посетителю краткий результат ускорения обновления статистики без
внутренних URL. Добавить отдельную русскую техническую запись за
26.07.2026, не изменяя прежние записи.

- [x] **Step 3: Финальный repository audit**

Повторно прочитать применимые requirements, найти legacy triple distinct,
duplicate helper/cache/read-model, `TODO|FIXME`, debug и secret markers.
Проверить branch/status и exact task diff.

Observed: применимые requirements перечитаны; активного legacy
triple-distinct helper, duplicate stats read model, debug/secret markers или
unfinished code в Task 75 scope не найдено. Исторические design/plan
упоминания и exact fallback regression assertions сохранены намеренно.
Независимый повторный code review после исправления edge-case coverage и
устранения duplicate fallback завершился `Ready: Yes` без замечаний.

- [x] **Step 4: Выполнить docs и broad gates**

```bash
php artisan project:docs-refresh --check
php artisan test
npm run build
```

Expected: task-related gates pass; unrelated shared-tree/full-suite failures
записываются отдельно и не маскируются.

Observed: related matrix прошла 136/136 тестов и 1 085 assertions;
остановленный full-suite класс отдельно прошёл 13/13 и 90 assertions; Vite
build passed. Единый full-suite process исчерпал накопительный PHP limit
256M в foreign homepage/security path. `project:docs-refresh --check`
сообщил только о foreign dirty `docs/MAINTENANCE_LOG.md`; Task 75 managed
blocks не меняет.

- [ ] **Step 5: Commit и push**

Подготовить alternate index только из exact Task 75 paths, проверить staged
diff, commit в `main`, затем выполнить configured non-force
`git push origin main`. Hook/credential/shared-tree failure остаётся
`unresolved`, без bypass и ложного success.

Observed: exact alternate index от `1982d17` содержал только восемь Task 75
files/hunks и прошёл `git diff --cached --check`. Штатный commit отклонён
managed-doc hook: `Документация требует обновления:
docs/MAINTENANCE_LOG.md`. Этот foreign dirty generated owner не относится к
Task 75; hook не обходился, файл не добавлялся. Task commit не создан,
поэтому push Task 75 не выполнялся.

## Plan self-review

- Spec coverage: fast-path, fallback, exact semantics, profiling,
  compatibility, docs и rollback имеют отдельные steps.
- Placeholder scan: `TBD`, `TODO`, «implement later» отсутствуют.
- Type consistency: используются существующие arrays/helper names; новых
  public signatures нет.
