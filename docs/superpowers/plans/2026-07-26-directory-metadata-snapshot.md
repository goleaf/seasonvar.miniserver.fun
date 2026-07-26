# Directory Metadata Snapshot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ускорить cold alphabet справочников и сделать повторные public
summary/alphabet reads cache-backed без изменения web/API semantics.

**Architecture:** `CatalogDirectoryQuery` остаётся единственным query owner,
но использует существующий `CatalogFacetSnapshotCache` для двух compact
resources. Alphabet rebuild сначала группирует visible taxonomy IDs, затем
вычисляет label/initial один раз на ID; summary cold SQL сохраняется.

**Tech Stack:** PHP 8.5.8, Laravel 13.22.0, Laravel Boost 2.4.13,
Livewire 4.3.3, PHPUnit 12.5.32, SQLite 3.46.1, Laravel Query Builder,
project `TieredCache`.

## Global Constraints

- Работать только в существующей `main`, без branch/worktree и без изменения
  foreign shared-tree scope.
- Не добавлять dependency, migration, index, table, DML, route, translation,
  permission, environment variable, queue или scheduler.
- Сохранить exact `CatalogTitleQuery::visibleTo(null)`, tag eligibility,
  locale/fallback priority, summary/alphabet/page/API shape.
- Использовать только существующие `CatalogFacetSnapshotCache`,
  `CatalogFacets` version/TTL/stale/lock/telemetry/invalidation owners.
- Production code появляется только после наблюдаемого RED.
- Rollback остаётся code/docs revert без restore, reindex, backfill или
  store-wide cache flush.

---

### Task 1: Зафиксировать query shape, exact semantics и cache lifecycle в RED

**Files:**

- Modify: `tests/Feature/CatalogDirectoryQueryOptimizationTest.php`
- Modify: `tests/Feature/CatalogFacetCacheTest.php`
- Reference: `app/Services/Catalog/CatalogDirectoryQuery.php`

**Interfaces:**

- Consumes:
  `CatalogDirectoryQuery::summary(CatalogDirectoryDefinition): array{values:int,titles:int}`.
- Consumes:
  `CatalogDirectoryQuery::letters(CatalogDirectoryDefinition): Collection<int,string>`.
- Produces: exact query-shape и versioned snapshot regressions для Tasks 2–3.

- [x] **Step 1: Добавить failing alphabet query-shape test**

Создать actors fixture с visible/draft/future/expired/deleted тайтлами и
symbol/cyrillic/latin actors. Перехватить SQL `letters()` и проверить exact
letters. Новый shape должен содержать grouped `directory_visible_values`
subquery до join `actors`, а outer query не должен напрямую join-ить
`catalog_title_actor`.

```php
$alphabetQuery = collect($queries)->sole(
    fn (string $sql): bool => str_contains($sql, 'as initial')
        && str_contains($sql, 'from actors'),
);

$this->assertStringContainsString(
    'inner join (select catalog_title_actor.actor_id as directory_value_id',
    $alphabetQuery,
);
$this->assertStringContainsString('group by catalog_title_actor.actor_id', $alphabetQuery);
$this->assertStringContainsString('as directory_visible_values', $alphabetQuery);
```

- [x] **Step 2: Добавить failing compact snapshot test**

В `CatalogFacetCacheTest` вызвать `summary()` и `letters()` дважды. После
первого чтения добавить новую visible связь напрямую: до bump должны
возвращаться прежние compact values, после
`CacheVersionRegistry::bump(CacheDomain::CatalogFacets)` — новые.

```php
$this->assertSame($firstSummary, $query->summary($directory));
$this->assertSame($firstLetters, $query->letters($directory)->all());

app(CacheVersionRegistry::class)->bump(CacheDomain::CatalogFacets);

$this->assertSame(2, $query->summary($directory)['values']);
$this->assertSame(['A', 'Б'], $query->letters($directory)->all());
```

- [x] **Step 3: Добавить failing same-locale tag lookup test**

Установить active/fallback locale `ru`, создать eligible localized tag и
проверить, что alphabet SQL содержит ровно один lookup
`tag_translations ... locale = ?`, а letters остаются exact.

- [x] **Step 4: Запустить RED**

```bash
php artisan test tests/Feature/CatalogDirectoryQueryOptimizationTest.php \
  tests/Feature/CatalogFacetCacheTest.php
```

Expected: existing assertions проходят; новый query-shape и snapshot reuse
падают на текущем direct aggregate path.

Observed: `8` tests, `5` passed, `3` failed after `33` assertions exactly on
the direct actor pivot join, two identical tag locale lookups and immediate
uncached summary refresh.

---

### Task 2: Ввести compact summary/alphabet snapshots

**Files:**

- Modify: `app/Services/Catalog/CatalogDirectoryQuery.php`
- Test: `tests/Feature/CatalogFacetCacheTest.php`

**Interfaces:**

- Adds constructor dependency:
  `CatalogFacetSnapshotCache $snapshots`.
- Produces:
  `private function buildSummary(CatalogDirectoryDefinition $directory): array`.
- Produces:
  `private function buildLetters(CatalogDirectoryDefinition $directory): Collection`.
- Preserves public `summary()` and `letters()` signatures.

- [x] **Step 1: Перенести прежний summary SQL в private rebuild**

Публичный метод хранит одну scalar row:

```php
public function summary(CatalogDirectoryDefinition $directory): array
{
    $row = $this->snapshots->remember(
        'directory-summary-v1',
        ['directory' => $directory->key],
        fn (): array => [$this->buildSummary($directory)],
    )[0] ?? [];

    return [
        'values' => (int) ($row['values'] ?? 0),
        'titles' => (int) ($row['titles'] ?? 0),
    ];
}
```

`buildSummary()` должен содержать прежние year/taxonomy queries без изменения
join, visibility, distinct counts или tag eligibility.

- [x] **Step 2: Обернуть alphabet в отдельный compact resource**

Для unsupported directory сразу вернуть empty collection. Для остальных
сохранять только `[['letter' => 'А'], ...]`.

```php
$rows = $this->snapshots->remember(
    'directory-alphabet-v1',
    $this->alphabetSnapshotDimensions($directory),
    fn (): array => $this->buildLetters($directory)
        ->map(fn (string $letter): array => ['letter' => $letter])
        ->all(),
);
```

Dimensions обязаны включать `directory`; `locale` включается только для
canonical tags и равен SHA-256 active/fallback locale sequence, без raw query
или пользовательского состояния.

- [x] **Step 3: Проверить snapshot GREEN**

```bash
php artisan test tests/Feature/CatalogFacetCacheTest.php
```

Expected: repeat reads остаются прежними до version bump и перестраиваются
после него; existing public facet tests остаются GREEN.

Observed together with Task 3: `8/8` focused tests passed with `39`
assertions.

---

### Task 3: Дедуплицировать visible IDs до alphabet label

**Files:**

- Modify: `app/Services/Catalog/CatalogDirectoryQuery.php`
- Test: `tests/Feature/CatalogDirectoryQueryOptimizationTest.php`

**Interfaces:**

- Produces:
  `private function visibleDirectoryValueIds(string $filterType, array $pivot): QueryBuilder`.
- Preserves:
  `private function normalizedInitial(string $initial): string`.
- Preserves public collection order and values.

- [x] **Step 1: Создать grouped visible-ID subquery**

```php
private function visibleDirectoryValueIds(string $filterType, array $pivot): QueryBuilder
{
    $query = DB::table($pivot['table'])
        ->selectRaw(
            "{$pivot['table']}.{$pivot['related_key']} as directory_value_id",
        )
        ->joinSub(
            $this->titles->visibleTo(null)->select('catalog_titles.id'),
            'directory_visible_value_titles',
            'directory_visible_value_titles.id',
            '=',
            $pivot['table'].'.'.$pivot['title_key'],
        )
        ->groupBy($pivot['table'].'.'.$pivot['related_key']);

    if ($filterType === 'tag') {
        $query->whereIn(
            $pivot['table'].'.'.$pivot['related_key'],
            Tag::query()->publiclyEligible()->select('tags.id'),
        );
    }

    return $query;
}
```

- [x] **Step 2: Переключить alphabet outer query**

`buildLetters()` join-ит `directory_visible_values.directory_value_id` к
taxonomy `id`, после чего применяет прежние name checks, label expression,
initial grouping, normalization и sort. Прямые pivot/title joins из outer
alphabet query удалить.

- [x] **Step 3: Удалить duplicate locale lookup**

`localizedTagNameSql()` и `localizedTagNameBindings()` строят ordered unique
active/fallback locales. При `ru === fallback ru` формируется один scalar
translation subquery плюс canonical `tags.name`; при разных locales — два в
прежнем порядке.

- [x] **Step 4: Запустить focused GREEN**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/CatalogDirectoryQueryOptimizationTest.php \
  tests/Feature/CatalogFacetCacheTest.php
```

Expected: exact summary/alphabet, grouped subquery, cache lifecycle и
same-locale lookup проходят.

Observed: focused matrix passed `8/8` tests and `39` assertions. Task 85
files already matched Pint; repository-wide `--dirty` formatting touched two
foreign Task 84 tests, which remain excluded from Task 85 delivery.

---

### Task 4: Проверить public compatibility и production-scale effect

**Files:**

- No production file change expected.

**Interfaces:**

- Verifies unchanged web/API/cache-warm/SEO contracts.
- Produces measured evidence for Task 5 documentation.

- [x] **Step 1: Запустить связанную PHPUnit-матрицу**

```bash
php artisan test tests/Feature/CatalogPageTest.php
php artisan test tests/Feature/Api/V1/CatalogDiscoveryTest.php
php artisan test --filter=CatalogDirectory
php artisan test tests/Feature/CacheWarmJobTest.php
php artisan test tests/Feature/CatalogCacheInvalidatorTest.php
```

- [x] **Step 2: Выполнить static gates**

```bash
php -l app/Services/Catalog/CatalogDirectoryQuery.php
./vendor/bin/phpstan analyse app/Services/Catalog/CatalogDirectoryQuery.php \
  --memory-limit=1G
./vendor/bin/rector process app/Services/Catalog/CatalogDirectoryQuery.php \
  --dry-run
```

- [x] **Step 3: Выполнить production-scale read-only profile**

Для actors/directors/tags измерить:

- uncached summary и alphabet минимум пятью чередующимися samples;
- hot snapshot wall time и количество SQL;
- exact normalized letter hash и summary values;
- `EXPLAIN QUERY PLAN` для rebuilt alphabet.

Любая concurrent importer lock/noise фиксируется как diagnostic limitation,
не маскируется и не объявляется SLA.

- [x] **Step 4: Проверить cache failure contract**

Переиспользовать существующую `TieredCache` failure matrix; новый код не
должен перехватывать ошибки store самостоятельно или возвращать private/raw
данные.

Observed:

- related web/API/directory/warming/invalidation commands: `115` tests and
  `1 042` assertions GREEN;
- additional TieredCache/state/facet and alphabet/API/invalidation commands:
  `28` tests and `154` assertions GREEN;
- PHP syntax, scoped PHPStan, Rector and task diff check GREEN;
- same-snapshot exact alphabet hashes matched for actors/directors/tags;
- five alternating medians: actors `665,56 → 407,06 ms`, directors
  `165,86 → 114,20 ms`, tags `410,69 → 249,32 ms`;
- hot array-backed snapshots returned exact values in `0,345–0,385 ms` with
  `0` SQL;
- EXPLAIN uses existing publication, pivot covering, tag eligibility,
  taxonomy PK and tag translation indexes; no new index is justified.

---

### Task 5: Документация, final audit и delivery

**Files:**

- Modify: `docs/caching.md`
- Modify: `docs/performance.md`
- Modify: `docs/catalog-search.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Preserve: all unrelated shared-worktree files.

**Interfaces:**

- Documents resources, dimensions, invalidation, measured effect and rollback.
- Produces final Task 85 compliance evidence.

- [x] **Step 1: Обновить canonical owners**

`caching.md` получает resource/payload/dimension/invalidation/failure
contract. `performance.md` получает only measured cold/hot results and
rejected summary/index/materialized alternatives. `catalog-search.md`
фиксирует unchanged web/API summary/alphabet shape.

- [x] **Step 2: Проверить и обновить README**

Так как visitor-facing directory latency меняется, добавить краткую русскую
строку в последний раздел `История обновлений для посетителей`, не меняя
managed block вручную.

- [x] **Step 3: Добавить отдельную русскую CHANGELOG entry**

Записать точные tests/query/profile/cache facts. Не смешивать и не удалять
чужие записи.

- [x] **Step 4: Финальный requirements/legacy audit**

Перечитать requirements/spec/plan; проверить duplicate cache/query services,
старый direct alphabet join, stale resource names, debug markers, secrets,
routes/translations/schema/dependency drift и exact task diff.

- [x] **Step 5: Выполнить final verification**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=CatalogDirectory
php artisan test tests/Feature/CatalogFacetCacheTest.php
php artisan project:docs-refresh --check
bash scripts/ci-check.sh docs
npm run build
```

Full suite запускается после focused matrix, если shared repository state не
создаёт независимый подтверждённый blocker.

Observed:

- свежие focused и related команды прошли `119` тестов с `1 059`
  утверждениями; отдельная матрица отказа и состояния кеша прошла `15`
  тестов с `56` утверждениями;
- Pint, полный ограниченный профиль PHPStan, scoped Rector, Vite,
  `project:docs-refresh --check` и `ci-check.sh docs` завершились с кодом
  `0`;
- две попытки монолитного PHPUnit-прогона породили отсоединённые процессы и
  не вернули достоверный итог runner; остановлены только их точные PID,
  поэтому полный suite остаётся честным `unresolved`, а не объявляется
  успешным;
- whole dirty-worktree проверка CHANGELOG дошла до отдельной параллельной
  записи с английским обычным словом; точный staged Task 85 snapshot остаётся
  обязательным commit gate.

- [x] **Step 6: Exact commit и configured push**

Проверить `main`, сформировать hook-enabled commit только из Task 85 paths/
hunks и выполнить non-force `git push origin main`. HTTPS authentication или
shared-tree failure фиксируется `unresolved`; hooks, force, reset, stash,
unstage чужих изменений и смена remote запрещены.

Observed: hook-enabled exact implementation/docs snapshot зафиксирован в
существующей `main` commit-ом `34a84c9`. Обычный
`GIT_TERMINAL_PROMPT=0 git push origin main` завершился кодом `128` до
передачи данных: среда не предоставила имя пользователя GitHub HTTPS.
Хуки, remote и история не обходились и не переписывались; push остаётся
`unresolved_authentication`.
