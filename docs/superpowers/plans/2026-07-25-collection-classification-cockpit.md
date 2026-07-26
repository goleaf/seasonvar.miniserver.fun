# Collection Classification Cockpit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Добавить в существующую администрацию подборок bounded
human-in-the-loop классификацию с детерминированными предложениями,
объяснениями, preview и явным подтверждением.

**Architecture:** `CatalogCollectionClassificationQuery` пагинирует очередь
до загрузки evidence и ограничивает sample 50 membership-строками на
подборку. `CatalogCollectionCategorySuggestionService` чисто вычисляет
предложение по immutable rules и ничего не сохраняет.
`CatalogCollectionCategoryService` остаётся единственной write boundary и
атомарно подтверждает 1–100 assignments после повторной authorization,
UUID/activity/version validation и row locks. Existing Livewire manager
получает URL-фильтры, per-row override и двухшаговый confirm UI.

**Tech Stack:** PHP 8.5.8, Laravel 13.22.0, Livewire 4.3.3, SQLite,
PHPUnit 12.5.32, Tailwind CSS 4.3.2, Vite 8.1.4, existing
`content.view`/`content.manage`, admin audit и collection cache invalidator.

## Global Constraints

- Работать только в существующей ветке `main`; не создавать branch/worktree.
- Не reset/stash/unstage и не поглощать существующий shared staged scope.
- Ни одно предложение не сохраняется автоматически.
- Не добавлять внешний AI/API, dependency, queue, scheduler, migration,
  config или environment variable.
- Пагинация выполняется до evidence loading.
- Максимум 50 collections на page и 50 membership samples на collection.
- Global confidence filter запрещён, потому что потребовал бы inference до
  pagination по всему каталогу.
- Final write принимает только collection UUID, expected content version и
  category UUID; numeric ID/owner/moderation/confidence от browser запрещены.
- Batch содержит 1–100 unique collections.
- Видимый текст имеет парные RU/EN keys; stable slugs/codes не переводятся.
- Blade не выполняет DB/service/config/filesystem calls и не использует
  `@php`, inline CSS или inline business JavaScript.
- Существующие routes/API/SEO/importer/image-removal contracts сохраняются.
- Canonical design:
  `docs/superpowers/specs/2026-07-25-collection-classification-cockpit-design.md`.
- Каждый production-code task начинается с наблюдаемого RED и заканчивается
  focused GREEN, Pint и exact-path `git diff --check`.
- Commit выполняется только если canonical Git guard допускает exact Task 52
  scope; иначе evidence записывается как
  `unresolved_shared_index_collision`.

---

## File map

New domain files:

- `app/Enums/CatalogCollectionCategorySuggestionConfidence.php` — stable
  confidence codes and localized label/pill variant.
- `app/DTOs/CatalogCollectionCategorySuggestion.php` — immutable suggestion
  result for one collection.
- `app/DTOs/CatalogCollectionClassificationSummary.php` — aggregate progress.
- `app/DTOs/CatalogCollectionClassificationResult.php` — changed/skipped
  result returned by confirmation.
- `app/Services/Collections/CatalogCollectionCategorySuggestionRules.php` —
  immutable default-category evidence registry.
- `app/Services/Collections/CatalogCollectionCategorySuggestionService.php` —
  pure scorer/explainer.
- `app/Services/Collections/CatalogCollectionClassificationQuery.php` —
  summary, normalized queue pagination and bounded evidence loading.

Existing integration files:

- `app/Services/Collections/CatalogCollectionCategoryService.php` —
  authoritative confirmation transaction.
- `app/Services/Collections/CatalogCollectionQuery.php` — удаление
  заменённой ручной admin queue.
- `app/Enums/AdminAuditAction.php` — stable confirmation audit code.
- `app/Livewire/Collections/CatalogCollectionCategoryManager.php` —
  URL filters, selection, overrides, preview and confirm orchestration.
- `resources/views/livewire/collections/catalog-collection-category-manager.blade.php`
  — responsive classification UI inside existing admin page.
- `lang/ru/collections.php`, `lang/en/collections.php` — labels, reasons,
  validation and status messages.
- `lang/ru/administration.php`, `lang/en/administration.php` — audit labels.

Tests:

- `tests/Unit/CatalogCollectionCategorySuggestionServiceTest.php`.
- `tests/Feature/CatalogCollectionClassificationQueryTest.php`.
- `tests/Feature/CatalogCollectionClassificationAdministrationTest.php`.
- `tests/Unit/FrontendAssetContractTest.php`.
- `tests/browser/prepare-fixtures.php`.
- `tests/browser/discovery-collections.spec.js`.
- Existing category/discovery/query/cache/UI tests for regression coverage.

---

### Task 1: Confidence enum, DTOs and immutable rule registry

**Files:**

- Create: `app/Enums/CatalogCollectionCategorySuggestionConfidence.php`
- Create: `app/DTOs/CatalogCollectionCategorySuggestion.php`
- Create: `app/DTOs/CatalogCollectionClassificationSummary.php`
- Create: `app/DTOs/CatalogCollectionClassificationResult.php`
- Create: `app/Services/Collections/CatalogCollectionCategorySuggestionRules.php`
- Create: `tests/Unit/CatalogCollectionCategorySuggestionServiceTest.php`

**Interfaces:**

- Produces:
  `CatalogCollectionCategorySuggestionConfidence::{None,Low,Medium,High}`.
- Produces:
  `CatalogCollectionCategorySuggestion::__construct(string $collectionPublicId, int $expectedContentVersion, ?string $categoryPublicId, ?string $categorySlug, ?string $categoryPath, int $score, CatalogCollectionCategorySuggestionConfidence $confidence, array $reasonCodes, int $sampleSize, int $totalItems)`.
- Produces:
  `CatalogCollectionCategorySuggestion::isSuggested(): bool`.
- Produces:
  `CatalogCollectionClassificationSummary::__construct(int $total, int $categorized, int $uncategorized, int $publicUncategorized, float $completionPercentage)`.
- Produces:
  `CatalogCollectionClassificationResult::__construct(int $changed, int $skipped, array $changedCollectionIds)`.
- Produces:
  `CatalogCollectionCategorySuggestionRules::definitions(): array`.

- [x] **Step 1: Write enum/DTO/rule RED assertions**

Create the unit test with exact stable-state assertions:

```php
public function test_confidence_codes_and_thresholds_are_stable(): void
{
    $this->assertSame('none', CatalogCollectionCategorySuggestionConfidence::None->value);
    $this->assertSame(CatalogCollectionCategorySuggestionConfidence::Low, CatalogCollectionCategorySuggestionConfidence::fromScore(60));
    $this->assertSame(CatalogCollectionCategorySuggestionConfidence::Medium, CatalogCollectionCategorySuggestionConfidence::fromScore(70));
    $this->assertSame(CatalogCollectionCategorySuggestionConfidence::High, CatalogCollectionCategorySuggestionConfidence::fromScore(85));
}

public function test_rules_only_reference_supported_default_category_slugs(): void
{
    $definitions = app(CatalogCollectionCategorySuggestionRules::class)->definitions();

    $this->assertArrayHasKey('animation-and-anime', $definitions);
    $this->assertArrayHasKey('netflix', $definitions);
    $this->assertArrayHasKey('south-korea', $definitions);
    $this->assertArrayNotHasKey('calm-evening', $definitions);
    $this->assertArrayNotHasKey('other-countries', $definitions);
}
```

- [x] **Step 2: Run RED**

Run:

```bash
php artisan test --filter=CatalogCollectionCategorySuggestionServiceTest
```

Expected: FAIL because enum/rules classes do not exist.

- [x] **Step 3: Implement enum and immutable DTOs**

Enum behavior:

```php
public static function fromScore(int $score): self
{
    return match (true) {
        $score >= 85 => self::High,
        $score >= 70 => self::Medium,
        $score >= 60 => self::Low,
        default => self::None,
    };
}
```

`label()` reads
`collections.classification.confidence.{value}`. `variant()` returns
existing `success`, `neutral`, `warning`, `muted` variants; a competing
status-pill palette is not introduced.

DTO constructors use promoted `public readonly` typed properties. Clamp is
performed by the service, not silently in DTO constructors.

- [x] **Step 4: Implement exact rule registry**

Each definition contains:

```php
[
    'category_slug' => 'animation-and-anime',
    'title_terms' => ['аниме', 'animation', 'animated', 'мультсериал'],
    'description_terms' => ['аниме', 'animation', 'animated', 'мультсериал'],
    'genres' => ['anime', 'animation', 'аниме', 'мультфильм'],
    'countries' => [],
    'networks' => [],
    'studios' => [],
    'types' => ['anime'],
]
```

Add similarly explicit entries for every supported child listed in the
design. Terms are lowercase normalized evidence only; mood and `other-*`
slugs are absent.

- [x] **Step 5: Run GREEN and style**

```bash
php artisan test --filter=CatalogCollectionCategorySuggestionServiceTest
./vendor/bin/pint --dirty --format agent
git diff --check -- app/Enums/CatalogCollectionCategorySuggestionConfidence.php app/DTOs/CatalogCollectionCategorySuggestion.php app/DTOs/CatalogCollectionClassificationSummary.php app/DTOs/CatalogCollectionClassificationResult.php app/Services/Collections/CatalogCollectionCategorySuggestionRules.php tests/Unit/CatalogCollectionCategorySuggestionServiceTest.php
```

Expected: enum/rule assertions PASS and diff check silent.

---

### Task 2: Deterministic suggestion scorer

**Files:**

- Create: `app/Services/Collections/CatalogCollectionCategorySuggestionService.php`
- Modify: `tests/Unit/CatalogCollectionCategorySuggestionServiceTest.php`
- Modify: `lang/ru/collections.php`
- Modify: `lang/en/collections.php`

**Interfaces:**

- Consumes:
  `CatalogCollectionCategorySuggestionRules::definitions()`.
- Produces:
  `CatalogCollectionCategorySuggestionService::suggest(CatalogCollection $collection, Collection $activeTree): CatalogCollectionCategorySuggestion`.
- Requires relations:
  `translations`, `sourceRecord`,
  `items.catalogTitle.genres`,
  `items.catalogTitle.countries`,
  `items.catalogTitle.networks`,
  `items.catalogTitle.studios`.
- Reads synthetic model attribute `total_items_count`.

- [x] **Step 1: Add RED for strong, dominant and conflicting evidence**

Use unsaved models with explicitly loaded relations:

```php
public function test_exact_platform_name_produces_explained_high_confidence_suggestion(): void
{
    $collection = $this->collectionEvidence('Лучшие сериалы Netflix', null, []);
    $tree = $this->activeTree('netflix', 'Netflix');

    $suggestion = app(CatalogCollectionCategorySuggestionService::class)
        ->suggest($collection, $tree);

    $this->assertSame('netflix', $suggestion->categorySlug);
    $this->assertSame(CatalogCollectionCategorySuggestionConfidence::High, $suggestion->confidence);
    $this->assertContains('title_exact', $suggestion->reasonCodes);
}

public function test_dominant_sample_metadata_can_produce_medium_suggestion(): void
{
    $collection = $this->collectionEvidence(
        'Выбор редакции',
        null,
        array_fill(0, 8, ['genres' => ['anime']]),
    );
    $tree = $this->activeTree('animation-and-anime', 'Анимация и аниме');

    $suggestion = app(CatalogCollectionCategorySuggestionService::class)
        ->suggest($collection, $tree);

    $this->assertSame('animation-and-anime', $suggestion->categorySlug);
    $this->assertGreaterThanOrEqual(70, $suggestion->score);
}

public function test_close_competing_candidates_return_no_suggestion(): void
{
    $collection = $this->collectionEvidence(
        'Фантастические детективы',
        null,
        [],
    );
    $tree = $this->activeTreeWithChildren([
        'detective-and-crime' => 'Детективы и криминал',
        'science-fiction-and-fantasy' => 'Фантастика и фэнтези',
    ]);

    $suggestion = app(CatalogCollectionCategorySuggestionService::class)
        ->suggest($collection, $tree);

    $this->assertFalse($suggestion->isSuggested());
    $this->assertSame(CatalogCollectionCategorySuggestionConfidence::None, $suggestion->confidence);
    $this->assertContains('conflict', $suggestion->reasonCodes);
}
```

- [x] **Step 2: Run RED**

```bash
php artisan test --filter=CatalogCollectionCategorySuggestionServiceTest
```

Expected: FAIL because scorer class does not exist.

- [x] **Step 3: Implement normalized scoring**

Implementation rules:

```php
$scores = [];
$reasons = [];

// title exact/platform/country phrase: +70
// thematic title term: +60
// description evidence: +30
// source remote name evidence: +20
// sample dominance >= .70: +55, >= .50: +35, >= .35: +20
// homogeneous supporting title type: +35
// cap candidate at 100; deduplicate evidence family
```

Normalize with `Str::lower`, `Str::squish` and punctuation-to-space
replacement. Match whole tokens/phrases with Unicode boundaries so `max`
does not match arbitrary words. Resolve only active child/root models from
the provided tree. Sort candidates by score DESC, slug ASC. Return `none`
when top score < 60 or margin < 15.

Reasons are stable codes only:
`title_exact`, `title_theme`, `description`, `source_name`,
`dominant_genre`, `dominant_country`, `dominant_platform`,
`dominant_type`, `conflict`, `insufficient_evidence`; keep at most three.

- [x] **Step 4: Add RU/EN reason and confidence keys**

Add exact parallel arrays:

```php
'classification' => [
    'confidence' => [
        'none' => 'Нет предложения',
        'low' => 'Низкая уверенность',
        'medium' => 'Средняя уверенность',
        'high' => 'Высокая уверенность',
    ],
    'reasons' => [
        'title_exact' => 'Точная тема найдена в названии',
        // all stable reason codes in both locales
    ],
],
```

- [x] **Step 5: Run GREEN and parity**

```bash
php artisan test --filter=CatalogCollectionCategorySuggestionServiceTest
php -r '$ru=require "lang/ru/collections.php"; $en=require "lang/en/collections.php"; exit(array_keys($ru)!==array_keys($en));'
./vendor/bin/pint --dirty --format agent
```

Expected: scoring tests PASS; translation command exits 0.

---

### Task 3: Bounded classification query and progress summary

**Files:**

- Create: `app/Services/Collections/CatalogCollectionClassificationQuery.php`
- Create: `tests/Feature/CatalogCollectionClassificationQueryTest.php`

**Interfaces:**

- Produces:
  `CatalogCollectionClassificationQuery::summary(): CatalogCollectionClassificationSummary`.
- Produces:
  `CatalogCollectionClassificationQuery::paginateUncategorized(string $search = '', string $visibility = '', string $type = '', int $perPage = 20): LengthAwarePaginator`.
- Produces:
  `CatalogCollectionClassificationQuery::suggestionsFor(LengthAwarePaginator $paginator, Collection $activeTree): array<string,CatalogCollectionCategorySuggestion>`.
- Consumes:
  `CatalogCollectionSchema`,
  `CatalogCollectionCategorySuggestionService`.

- [x] **Step 1: Write summary/filter RED**

```php
public function test_summary_and_queue_use_authoritative_uncategorized_state(): void
{
    $categorized = CatalogCollection::query()->create([
        'public_id' => (string) Str::uuid(),
        'name' => 'Классифицированная',
        'slug' => 'classified-'.Str::lower(Str::random(8)),
        'catalog_collection_category_id' => $this->category()->id,
    ]);
    $public = CatalogCollection::query()->create([
        'public_id' => (string) Str::uuid(),
        'name' => 'Публичная',
        'slug' => 'public-'.Str::lower(Str::random(8)),
        'visibility' => CatalogCollectionVisibility::Public,
        'moderation_status' => CatalogCollectionModerationStatus::Approved,
        'catalog_collection_category_id' => null,
    ]);
    CatalogCollection::query()->create([
        'public_id' => (string) Str::uuid(),
        'name' => 'Личная',
        'slug' => 'private-'.Str::lower(Str::random(8)),
        'visibility' => CatalogCollectionVisibility::Private,
        'catalog_collection_category_id' => null,
    ]);

    $query = app(CatalogCollectionClassificationQuery::class);
    $summary = $query->summary();
    $page = $query->paginateUncategorized(visibility: 'public');

    $this->assertSame(3, $summary->total);
    $this->assertSame(1, $summary->categorized);
    $this->assertSame(2, $summary->uncategorized);
    $this->assertSame(1, $summary->publicUncategorized);
    $this->assertSame([$public->public_id], $page->pluck('public_id')->all());
}
```

- [x] **Step 2: Write bounded evidence/query-budget RED**

Create two collections with 80 items each, clear the query log after
fixtures, paginate, compute suggestions and assert:

```php
$this->assertLessThanOrEqual(50, $page->first()->items->count());
$this->assertLessThanOrEqual(14, count(DB::getQueryLog()));
```

Also assert an empty page performs no query containing
`catalog_collection_items`.

- [x] **Step 3: Run RED**

```bash
php artisan test --filter=CatalogCollectionClassificationQueryTest
```

Expected: FAIL because query class does not exist.

- [x] **Step 4: Implement summary and normalized pagination**

Summary uses one statement with conditional aggregates:

```sql
COUNT(*) AS total,
SUM(CASE WHEN catalog_collection_category_id IS NOT NULL THEN 1 ELSE 0 END) AS categorized,
SUM(CASE WHEN catalog_collection_category_id IS NULL THEN 1 ELSE 0 END) AS uncategorized,
SUM(CASE WHEN catalog_collection_category_id IS NULL AND visibility = 'public'
    AND moderation_status = 'approved' THEN 1 ELSE 0 END) AS public_uncategorized
```

Pagination allowlists enum values, clamps per-page, normalizes search through
existing `CatalogSearchNormalizer`, selects explicit collection projection,
applies `whereNull(category_id)`, and uses named paginator
`collectionCategoryClassificationPage`.

- [x] **Step 5: Implement current-page evidence loading**

For non-empty page:

```php
$collections->load([
    'owner:id,public_id,name',
    'translations' => fn (HasMany $query) => $query
        ->select([...projected columns...])
        ->whereIn('locale', $this->searchLocales()),
    'sourceRecord:id,catalog_collection_id,provider,remote_name',
    'items' => fn (HasMany $query) => $query
        ->select(['id', 'catalog_collection_id', 'catalog_title_id', 'position'])
        ->orderBy('position')
        ->orderBy('id')
        ->limit(50),
    'items.catalogTitle:id,title,original_title,type,year',
    'items.catalogTitle.genres:id,name,slug',
    'items.catalogTitle.countries:id,name,slug',
    'items.catalogTitle.networks:id,name,slug',
    'items.catalogTitle.studios:id,name,slug',
]);
```

Load total item counts for page IDs in one grouped query and set
`total_items_count`. Do not lazy-load anything in scorer.

- [x] **Step 6: Run GREEN, EXPLAIN and style**

```bash
php artisan test --filter=CatalogCollectionClassificationQueryTest
sqlite3 database/database.sqlite "EXPLAIN QUERY PLAN SELECT id FROM catalog_collections WHERE catalog_collection_category_id IS NULL ORDER BY updated_at DESC, id DESC LIMIT 20;"
./vendor/bin/pint --dirty --format agent
git diff --check -- app/Services/Collections/CatalogCollectionClassificationQuery.php tests/Feature/CatalogCollectionClassificationQueryTest.php
```

Measured discovery: cold `CatalogCollectionSchema::sourceSyncAvailable()` adds
two one-time compatibility probes, while a collection owner adds one eager
query. The bounded worst-case page budget is therefore 14 queries and remains
independent of collection/item count.

Expected: tests PASS; plan uses
`catalog_collections_category_public_order_idx` or a measured compatible
existing index path.

---

### Task 4: Authoritative optimistic batch confirmation

**Files:**

- Modify: `app/Services/Collections/CatalogCollectionCategoryService.php`
- Modify: `app/Enums/AdminAuditAction.php`
- Create: `tests/Feature/CatalogCollectionClassificationAdministrationTest.php`

**Interfaces:**

- Produces:
  `CatalogCollectionCategoryService::confirmAssignments(User $actor, array $assignments): CatalogCollectionClassificationResult`.
- Assignment input:
  `array{collectionPublicId:string, expectedContentVersion:int, categoryPublicId:string}`.
- Consumes:
  `CatalogCollectionCacheInvalidator::changedMany(Collection $collections)`.

- [x] **Step 1: Write authorization/validation RED**

```php
public function test_confirmation_requires_content_manage_and_rejects_invalid_batch(): void
{
    $user = User::factory()->create();

    $this->expectException(AuthorizationException::class);

    app(CatalogCollectionCategoryService::class)->confirmAssignments($user, [[
        'collectionPublicId' => (string) Str::uuid(),
        'expectedContentVersion' => 1,
        'categoryPublicId' => (string) Str::uuid(),
    ]]);
}
```

Add cases for empty, 101 rows, duplicate collection UUID, malformed UUID,
unknown collection, unknown/inactive category. Unknown/malformed input must
produce `ValidationException` and zero writes.

- [x] **Step 2: Write optimistic/atomic RED**

Create three uncategorized collections:

- current version → changed;
- stale version → skipped;
- already assigned → skipped.

Assert result `changed=1`, `skipped=2`; changed row has only category FK and
`content_version + 1`; visibility/moderation/owner unchanged. Add a test that
one inactive category rejects the entire batch and leaves all rows unchanged.

- [x] **Step 3: Run RED**

```bash
php artisan test --filter=CatalogCollectionClassificationAdministrationTest
```

Expected: FAIL because `confirmAssignments()` is undefined.

- [x] **Step 4: Implement normalized transaction**

Validation sequence:

1. authorize `content.manage`;
2. validate shape/count/unique UUID/version before transaction;
3. transaction-load all categories and collections by normalized public UUID
   using explicit projections and `lockForUpdate()`;
4. reject unknown or inactive categories/root;
5. skip stale/already assigned;
6. update valid rows and increment version;
7. record one safe `CollectionCategoryAssignmentsConfirmed` audit event per
   changed target category with stable hashes/counts;
8. return changed models internally for post-commit invalidation.

The public DTO exposes changed internal IDs only to the cache boundary, not
to browser serialization. If the DTO is passed to Livewire, expose only
counts; keep models in a local service result wrapper or invoke invalidation
inside service after transaction.

Implementation discovery: one batch may legitimately target several
categories, while `AdminAuditRecorder` requires one concrete resource per
event. Audit therefore groups changed rows by category and records one event
for each changed category rather than attaching a misleading batch to the
first category.

- [x] **Step 5: Run GREEN and regress existing bulk assignment**

```bash
php artisan test --filter=CatalogCollectionClassificationAdministrationTest
php artisan test --filter=CatalogCollectionCategoryAdministrationTest
./vendor/bin/pint --dirty --format agent
git diff --check -- app/Services/Collections/CatalogCollectionCategoryService.php app/Enums/AdminAuditAction.php app/DTOs/CatalogCollectionClassificationResult.php tests/Feature/CatalogCollectionClassificationAdministrationTest.php
```

Expected: both suites PASS.

---

### Task 5: Livewire classification state, preview and confirm

**Files:**

- Modify: `app/Livewire/Collections/CatalogCollectionCategoryManager.php`
- Modify: `tests/Feature/CatalogCollectionClassificationAdministrationTest.php`

**Interfaces:**

- URL properties:
  `classificationSearch`,
  `classificationVisibility`,
  `classificationType`,
  `classificationPerPage`.
- Public selection:
  `selectedClassificationPublicIds: list<string>`.
- Public overrides:
  `classificationCategoryByCollection: array<string,string>`.
- Public expected versions:
  `classificationVersionByCollection: array<string,int>`.
- Locked preview state:
  `classificationPreviewOpen: bool`.
- Actions:
  `selectHighConfidence()`,
  `prepareClassificationPreview()`,
  `cancelClassificationPreview()`,
  `confirmClassificationAssignments()`.

- [x] **Step 1: Write hydration/filter RED**

Assert non-manager does not receive queue/suggestion payload. Manager sees
summary/page. Updating search/visibility/type/per-page:

- normalizes allowlisted values;
- clears selection and preview;
- resets only `collectionCategoryClassificationPage`;
- does not reset pagination owned by surrounding components.

- [x] **Step 2: Write no-write selection/preview RED**

```php
Livewire::actingAs($admin)
    ->test(CatalogCollectionCategoryManager::class)
    ->call('selectHighConfidence')
    ->assertSet('classificationPreviewOpen', false);

$this->assertDatabaseMissing('catalog_collections', [
    'id' => $collection->id,
    'catalog_collection_category_id' => $category->id,
]);
```

Then select, call `prepareClassificationPreview`, assert preview is open and
DB remains unchanged.

- [x] **Step 3: Write final confirm/stale RED**

Select one suggestion and one manual override, prepare preview, then mutate
one collection version directly. Confirm and assert one changed/one skipped,
selection cleared, preview closed, translated notice shown.

- [x] **Step 4: Run RED**

```bash
php artisan test --filter=CatalogCollectionClassificationAdministrationTest
```

Expected: FAIL on missing properties/actions.

- [x] **Step 5: Implement URL state and server-derived render data**

Render:

```php
$classificationSummary = $classification->summary();
$classificationPage = $classification->paginateUncategorized(...);
$classificationSuggestions = $classification->suggestionsFor(
    $classificationPage,
    $tree,
);
```

Only perform these calls when `$canManage`. Build active category options in
PHP, not Blade. On every render prune selected UUID/override/version maps to
current page authoritative UUIDs.

- [x] **Step 6: Implement actions**

`selectHighConfidence()` derives current suggestions server-side and selects
only `High` rows with non-null category UUID.

`prepareClassificationPreview()` validates selected UUIDs, requires a
category for each, copies authoritative page `content_version`, then opens
preview without calling write service.

`confirmClassificationAssignments()` rebuilds exact assignment DTOs from
selected UUID/category/version maps and delegates to
`confirmAssignments()`. It never sends score/confidence to service.

Implementation hardening: preview creates a separate `#[Locked]`
`classificationPreviewAssignments` snapshot. Final confirmation consumes
that exact reviewed snapshot, so a category selector change cancels preview
and browser hydration cannot silently alter what the administrator reviewed.

- [x] **Step 7: Run GREEN and style**

```bash
php artisan test --filter=CatalogCollectionClassificationAdministrationTest
php artisan test --filter=CatalogCollectionCategoryAdministrationTest
./vendor/bin/pint --dirty --format agent
```

Expected: Livewire and prior category manager tests PASS.

---

### Task 6: Responsive classification UI and translations

**Files:**

- Modify: `resources/views/livewire/collections/catalog-collection-category-manager.blade.php`
- Modify: `lang/ru/collections.php`
- Modify: `lang/en/collections.php`
- Modify: `tests/Feature/CatalogCollectionClassificationAdministrationTest.php`
- Modify: `tests/Unit/FrontendAssetContractTest.php`
- Modify: `tests/browser/discovery-collections.spec.js` only if shared admin
  auth fixture supports the existing admin page; otherwise add a focused
  `tests/browser/collection-classification.spec.js`.

**Interfaces:**

- Consumes prepared summary/page/suggestion/options/preview data only.
- Adds DOM contracts:
  `data-collection-classification`,
  `data-classification-summary`,
  `data-classification-row`,
  `data-classification-preview`,
  `data-classification-confirm`.

- [x] **Step 1: Add RED markup/accessibility assertions**

Assert:

- no image/poster markup inside classification;
- summary labels and real counts;
- search, visibility, type, per-page native controls;
- each row has checkbox, category select, confidence and reasons;
- buttons have minimum-height class;
- preview confirm and cancel are distinct actions;
- no inline style/script/`@php`;
- manager without permission does not render classification DOM.

- [x] **Step 2: Run RED**

```bash
php artisan test --filter=CatalogCollectionClassificationAdministrationTest
php artisan test --filter=FrontendAssetContractTest
```

Expected: classification DOM assertions FAIL.

- [x] **Step 3: Implement summary and filters**

Use existing `x-ui.panel`, `x-ui.status-pill`, `x-form.field`,
`x-form.input-error`, `x-ui.pagination-region` and named island patterns.
Use a responsive grid, no local horizontal scroll.

- [x] **Step 4: Implement rows and two-step preview**

Each row uses text-only vertical layout on mobile and a grid at `lg`.
Category `<select>` is bound by collection UUID key. Reasons are rendered by
stable translation lookup prepared in PHP or direct exact lang key; no DB
calls.

Preview is an in-page panel with `role="region"`, labelled heading, summary,
cancel and explicit primary confirm button. It is not a JavaScript modal and
remains meaningful without custom JS or modal focus management.

- [x] **Step 5: Complete exact RU/EN parity**

Add all visible labels, empty states, notices, validation and a11y text to
both locale files. Validate recursively using the project translation parity
test, not only top-level keys.

- [x] **Step 6: Run GREEN, build and browser QA**

```bash
php artisan test --filter=CatalogCollectionClassificationAdministrationTest
php artisan test --filter=FrontendAssetContractTest
npm run build
npx playwright test tests/browser/collection-classification.spec.js --project=chromium
```

Expected: PHP tests PASS; Vite build success; Playwright desktop/mobile/tablet
has no page error or horizontal overflow. If browser fixture cannot authorize
an admin honestly, record browser admin auth as `unresolved` and run the
existing collection/discovery public regression instead of creating fake
auth.

---

### Task 7: Public reflection, cache, query-plan and compatibility regression

**Files:**

- Modify: `tests/Feature/CatalogCollectionClassificationAdministrationTest.php`
- Modify: `tests/Feature/CatalogCollectionDirectoryCategoryTest.php` only if
  required for confirmed-assignment reflection.
- Modify: `tests/Feature/CatalogDiscoveryQueryBudgetTest.php` only if required
  without overwriting existing foreign additions.

**Interfaces:**

- Confirms existing public `CatalogCollectionQuery` and
  `CatalogCollectionCategoryQuery` immediately consume confirmed FK.
- Confirms existing cache invalidator is called after commit.

- [x] **Step 1: Add RED/contract test for public reflection**

Start with a public approved uncategorized collection. Confirm assignment via
service, then assert:

```php
$directory = app(CatalogCollectionQuery::class)->publicDirectory(
    category: $root->slug,
    subcategory: $child->slug,
);

$this->assertTrue($directory->contains('public_id', $collection->public_id));
```

Also assert the grouped category count increments and the uncategorized count
decrements.

- [x] **Step 2: Add cache after-commit assertion**

Use `Cache::spy()` or existing collection invalidator test pattern. Verify no
public invalidation occurs for rolled-back transaction and relevant versions
change only after successful confirmation.

- [x] **Step 3: Run focused regression**

```bash
php artisan test --filter='CatalogCollectionClassification|CatalogCollectionCategory|CatalogCollectionDirectory|UnifiedDiscoveryCollections'
```

Expected: all Task 51/52 collection tests PASS.

- [x] **Step 4: Inspect routes/schema/query plans and forbidden legacy**

```bash
php artisan route:list --path=collections
php artisan route:list --path=discover
sqlite3 database/database.sqlite "PRAGMA index_list('catalog_collections');"
rg -n "collection.*cover|cover.*collection" app routes resources/views config resources/api --glob '!*.map'
```

Expected: no new routes; no collection cover behavior; existing category
index present.

- [x] **Step 5: Run style/build gate**

```bash
./vendor/bin/pint --dirty --format agent
npm run build
git diff --check -- app/DTOs app/Enums app/Livewire/Collections app/Services/Collections resources/views/livewire/collections lang/ru/collections.php lang/en/collections.php tests/Feature tests/Unit tests/browser
```

---

### Task 8: Canonical documentation and completion evidence

**Files:**

- Modify: `docs/architecture.md`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/administration.md`
- Modify: `docs/authorization.md`
- Modify: `docs/performance.md`
- Modify: `docs/caching.md`
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/frontend.md`
- Modify: `docs/requirements/system-wide-integration.md`
- Modify: `docs/deployment.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**

- Documents actual implementation only.
- Preserves `README.md` managed block and keeps visitor history as final H2.
- Adds one dated Russian `CHANGELOG.md` entry without altering history.

- [x] **Step 1: Update domain owners**

Record:

- human-in-the-loop only;
- score/margin/confidence behavior;
- pagination-first 50×50 cap;
- no confidence global filter;
- permission/final revalidation;
- no schema/provider/queue/cache;
- rollout and rollback.

- [x] **Step 2: Update README and CHANGELOG**

README visitor history describes the visible administrative/product result
without internal class names. CHANGELOG records domain/query/UI/tests/docs
facts in Russian.

- [x] **Step 3: Complete Task 52 compliance matrix**

Change only evidence-backed statuses to `completed` or
`already_compliant`. Preserve honest `unresolved` for external test/browser/
Git delivery blockers.

- [x] **Step 4: Refresh/check managed docs**

```bash
php artisan project:docs-refresh
php artisan project:docs-refresh --check
php artisan test --filter='CurrentPlanPolicyScriptTest|ReadmePolicyScriptTest|ChangelogPolicyScriptTest|RefreshProjectDocsCommandTest'
```

Expected: check silent; policy tests PASS.

---

### Task 9: Final verification and safe delivery

**Files:**

- Verify all Task 52 files and protected contracts.
- Do not modify foreign files solely to make unrelated tests pass.

**Interfaces:**

- Completion requires exact evidence in current plan.

- [x] **Step 1: Re-read applicable requirements and spec**

Read root `AGENTS.md`, requirement index, all Task 52 owners, canonical design
and this implementation plan. Record any conflict before continuing.

- [x] **Step 2: Run focused and broad tests**

```bash
php artisan test --filter='CatalogCollectionClassification|CatalogCollectionCategory|CatalogCollectionDirectory|UnifiedDiscoveryCollections|CatalogDiscovery'
php artisan test
```

If the known foreign
`SeasonvarImportDispatchBatcherTest` or legacy web-session test remains the
only blocker, reproduce it separately and record exact evidence without
claiming full-suite success.

- [x] **Step 3: Run final static/frontend gates**

```bash
./vendor/bin/pint --dirty --test --format agent
npm run build
php artisan project:docs-refresh --check
git diff --check
git diff --cached --check
```

- [x] **Step 4: Run browser QA**

Run focused admin classification spec when truthful auth fixture exists and
public `discovery-collections.spec.js` regression on Desktop/Mobile/Tablet.
Inspect final screenshots visually for wrapping, labels, preview hierarchy
and absence of images/overflow.

- [x] **Step 5: Repository-wide relevant legacy scan**

Search duplicate suggestion services, automatic category writes, stale
confidence filters, collection images, unbounded membership scans, hardcoded
translations and unused controls. Inspect dependencies before removing any
match.

- [x] **Step 6: Attempt safe main commit/push**

```bash
git status --short --branch
git diff --name-only
git diff --cached --name-only
```

Only when Task 52 paths can be committed without absorbing foreign staged/
unstaged work:

```bash
git add <exact Task 52 paths>
git commit -m "feat: add collection classification cockpit"
git push
```

If canonical guard rejects because the shared worktree remains dirty, restore
only any Task 52 intent-to-add state, leave user/foreign changes untouched and
record `unresolved_shared_index_collision`.
