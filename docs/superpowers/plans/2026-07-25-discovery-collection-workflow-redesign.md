# Task 56 Discovery Collection Workflow Redesign Implementation Plan

> **For Codex:** Execute this plan in the existing `main` working tree with
> strict RED → verify RED → minimal GREEN → verify GREEN cycles. Do not use a
> branch/worktree, do not reset/stash/unstage concurrent changes, and commit
> only Task 56 paths.

**Goal:** Make the text-only collection directory immediately reachable on
`/discover/popular`, remove dead zero-count navigation, accelerate
human-confirmed classification, and eliminate duplicate classification reads
inside one Livewire request.

**Architecture:** Preserve `CatalogDiscoveryPage` as the only public full-page
owner and `CatalogCollectionCategoryManager` as the existing authorized admin
owner. Public changes are presentation/order/SEO state only. Admin actions
stage bounded UUID/category state without writes; the existing
`confirmAssignments()` remains the only mutation boundary. Livewire
request-scoped computed properties reuse category tree, paginator, summary
and suggestions without introducing shared private-data cache.

**Tech Stack:** PHP 8.5.8, Laravel 13.22.0, Livewire 4.3.3, SQLite, Blade,
Tailwind CSS 4.3.2, PHPUnit 12.5.32, Playwright.

**Approved design:**
[`2026-07-25-discovery-collection-workflow-redesign-design.md`](../specs/2026-07-25-discovery-collection-workflow-redesign-design.md)

---

## Task 1: Public collection-first ordering and section navigation

**Files:**

- Modify: `tests/Feature/CatalogDiscoveryLayoutTest.php`
- Modify: `tests/Feature/UnifiedDiscoveryCollectionsTest.php`
- Modify: `app/Livewire/CatalogDiscoveryPage.php`
- Modify: `resources/views/livewire/catalog-discovery-page.blade.php`
- Modify: `lang/ru/recommendations.php`
- Modify: `lang/en/recommendations.php`

**Produces:**

- Popular-only prepared `discoverySectionNavigation`.
- Stable targets `#collections` and `#popular-titles`.
- Collection explorer before the title-results section.
- Other eight modes unchanged.

- [ ] **Step 1: Write the public-order RED**

Change the existing
`test_popular_mode_visually_separates_series_from_collection_explorer`
assertion so collection position must be lower than title position:

```php
$this->assertLessThan(
    strpos($html, 'data-discovery-title-results'),
    strpos($html, 'data-discovery-collection-results'),
);
```

Also assert:

- popular output contains `data-discovery-section-navigation`;
- it contains `href="#collections"` and `href="#popular-titles"`;
- title-results section has `id="popular-titles"`;
- random output has no discovery section navigation and no collection
  explorer.

- [ ] **Step 2: Verify RED**

```bash
php artisan test tests/Feature/CatalogDiscoveryLayoutTest.php \
  tests/Feature/UnifiedDiscoveryCollectionsTest.php
```

Expected: FAIL because current title results precede collection explorer and
the section navigation/title anchor do not exist.

- [ ] **Step 3: Implement minimal public ordering**

In `CatalogDiscoveryPage::render()` prepare a popular-only list:

```php
'discoverySectionNavigation' => $type === CatalogRecommendationType::Popular
    ? [
        ['url' => '#collections', 'label' => __('collections.navigation.collections')],
        ['url' => '#popular-titles', 'label' => __('recommendations.page.popular_series')],
    ]
    : [],
```

In Blade:

1. render a compact native anchor nav after type navigation;
2. render the existing collection child immediately after that nav;
3. keep filters and title results after the child;
4. add `id="popular-titles"` and `scroll-mt-28` to title results;
5. remove the old bottom collection child block.

Use existing button/navigation classes, visible focus, `min-h-11`, no inline
CSS/JS and no new card shell.

- [ ] **Step 4: Verify GREEN and regress all modes**

```bash
php artisan test tests/Feature/CatalogDiscoveryLayoutTest.php \
  tests/Feature/UnifiedDiscoveryCollectionsTest.php \
  tests/Feature/CatalogDiscoveryInteractionTest.php
```

Expected: PASS; nine mode routes and one-H1 contract remain green.

---

## Task 2: Locale-aware global collection anchors and collection SEO state

**Files:**

- Modify: `tests/Unit/AppLayoutOptionalNavigationTest.php`
- Modify: `tests/Feature/UnifiedDiscoveryCollectionsTest.php`
- Modify: `app/View/ViewData/AppLayoutData.php`
- Modify: `app/Livewire/CatalogDiscoveryPage.php`

**Produces:**

- Header/footer collection item URL ending in `#collections`.
- Correct default/localized discovery URLs.
- Category/subcategory URL variants treated as interactive filtered state.

- [ ] **Step 1: Write navigation and SEO RED**

Add layout tests with registered routes for RU and localized EN requests:

```php
$collectionItem = collect($layout['layoutHeader']['navigation'])
    ->firstWhere('label', __('recommendations.navigation.discover'));

$this->assertSame(
    route('discover.index', ['type' => 'popular']).'#collections',
    $collectionItem?->url,
);
```

Repeat for footer and localized route.

Add a response assertion that
`/discover/popular?collections_category=themes-and-genres` and the equivalent
subcategory variant render:

- clean canonical `/discover/popular`;
- `noindex,follow`;
- no JSON-LD/alternate output expected for an indexable unfiltered page.

- [ ] **Step 2: Verify RED**

```bash
php artisan test tests/Unit/AppLayoutOptionalNavigationTest.php \
  tests/Feature/UnifiedDiscoveryCollectionsTest.php
```

Expected: FAIL because layout URLs have no fragment and category/subcategory
are not part of `$collectionStatefulVariant`.

- [ ] **Step 3: Implement locale-aware URL helper and SEO keys**

Add a private `collectionDirectoryUrl()` in `AppLayoutData`:

```php
private function collectionDirectoryUrl(): string
{
    return $this->navigationRoute('discover.index', ['type' => 'popular'])
        .'#collections';
}
```

Use `headerLinkUrl()` / `footerLinkUrl()` for the existing collection-labelled
discover item. Do not add a second navigation item.

Extend `request()->hasAny()` with:

```php
'collections_category',
'collections_subcategory',
```

Do not change `CatalogSeoBuilder` or canonical route construction.

- [ ] **Step 4: Verify GREEN**

```bash
php artisan test tests/Unit/AppLayoutOptionalNavigationTest.php \
  tests/Feature/UnifiedDiscoveryCollectionsTest.php \
  tests/Feature/AppLayoutStructuredDataTest.php
```

Expected: PASS; unregistered optional-route behavior remains green.

---

## Task 3: Remove zero-count category dead controls

**Files:**

- Modify: `tests/Feature/CatalogCollectionExplorerCategoryTest.php`
- Modify: `app/Livewire/Collections/CatalogCollectionExplorer.php`
- Modify: `resources/views/livewire/collections/catalog-collection-explorer.blade.php`

**Produces:**

- Prepared navigation contains only positive-count roots/children plus active
  bookmarked state.
- Prepared `showUncategorizedFilter` flag.
- Blade performs no count filtering.

- [ ] **Step 1: Write zero-count RED**

Create one collection under `themes-and-genres / detective-and-crime` and no
collections under `format`.

Assert unfiltered explorer:

- sees `Темы и жанры`;
- sees `Детективы и криминал` after choosing the root;
- does not see `Формат`;
- does not see a zero-count child such as `Долгие истории`;
- still sees «Без категории» only when an uncategorized public collection
  exists.

Add a bookmarked zero-count `collections_category=format` case and assert the
selected root remains present with exact empty-state behavior.

- [ ] **Step 2: Verify RED**

```bash
php artisan test tests/Feature/CatalogCollectionExplorerCategoryTest.php
```

Expected: FAIL because all active roots and children currently render.

- [ ] **Step 3: Implement prepared filtering**

When mapping the directory tree:

- include root when count > 0 or root slug equals current category;
- include child when count > 0 or child slug equals current subcategory;
- apply the same child filtering to `subcategoryOptions`;
- pass `showUncategorizedFilter` when count > 0 or it is selected.

Wrap desktop/mobile uncategorized controls in the prepared boolean.
Do not change category query/count SQL.

- [ ] **Step 4: Verify GREEN and directory regression**

```bash
php artisan test tests/Feature/CatalogCollectionExplorerCategoryTest.php \
  tests/Feature/CatalogCollectionDirectoryCategoryTest.php \
  tests/Feature/UnifiedDiscoveryCollectionsTest.php
```

Expected: PASS; deterministic two-phase directory ordering remains green.

---

## Task 4: Moderation-aware default classification queue

**Files:**

- Modify: `tests/Feature/CatalogCollectionClassificationQueryTest.php`
- Modify: `tests/Feature/CatalogCollectionClassificationAdministrationTest.php`
- Modify: `app/Services/Collections/CatalogCollectionClassificationQuery.php`
- Modify: `app/Livewire/Collections/CatalogCollectionCategoryManager.php`
- Modify: `resources/views/livewire/collections/catalog-collection-category-manager.blade.php`
- Modify: `lang/ru/collections.php`
- Modify: `lang/en/collections.php`

**Produces:**

- Optional `moderationStatus` query predicate.
- Component default public/approved queue.
- Normalized URL-backed moderation select.

- [ ] **Step 1: Write query RED**

Create public approved, public pending, private approved and categorized
collections. Call:

```php
$page = $query->paginateUncategorized(
    visibility: 'public',
    moderationStatus: 'approved',
);
```

Assert only public approved uncategorized UUID is returned. Assert invalid
status keeps the service's backward-compatible unfiltered behavior.

- [ ] **Step 2: Verify query RED**

```bash
php artisan test tests/Feature/CatalogCollectionClassificationQueryTest.php
```

Expected: ERROR/FAIL because the named `moderationStatus` parameter does not
exist.

- [ ] **Step 3: Implement query predicate**

Import `CatalogCollectionModerationStatus`, parse with `tryFrom()`, and add:

```php
->when(
    $moderationFilter !== null,
    fn (Builder $query): Builder => $query
        ->where('moderation_status', $moderationFilter->value),
)
```

Keep method defaults unfiltered to preserve callers.

- [ ] **Step 4: Write component-default RED**

Add public approved and public pending fixtures. Assert a fresh manager:

- `classificationVisibility === 'public'`;
- `classificationModerationStatus === 'approved'`;
- page contains approved and omits pending;
- setting moderation to empty reveals both;
- update resets only named classification paginator and clears preview.

- [ ] **Step 5: Verify component RED**

```bash
php artisan test tests/Feature/CatalogCollectionClassificationAdministrationTest.php
```

Expected: FAIL because moderation state/control is absent.

- [ ] **Step 6: Implement component state/control**

Add URL property:

```php
#[Url(
    as: 'collection_classification_moderation',
    history: true,
    except: 'approved',
)]
public string $classificationModerationStatus = 'approved';
```

Set visibility default/URL exception to `public`, normalize against enum
cases, pass to query, prepare translated options in PHP, and render the fifth
filter control. Keep all user-facing strings in both locale files.

- [ ] **Step 7: Verify GREEN**

```bash
php artisan test tests/Feature/CatalogCollectionClassificationQueryTest.php \
  tests/Feature/CatalogCollectionClassificationAdministrationTest.php
```

Expected: PASS.

---

## Task 5: Page, row and batch staging without writes

**Files:**

- Modify: `tests/Feature/CatalogCollectionClassificationAdministrationTest.php`
- Modify: `app/Livewire/Collections/CatalogCollectionCategoryManager.php`
- Modify: `resources/views/livewire/collections/catalog-collection-category-manager.blade.php`
- Modify: `lang/ru/collections.php`
- Modify: `lang/en/collections.php`

**Produces:**

- `selectCurrentClassificationPage()`.
- `clearClassificationSelection()`.
- `stageClassificationSuggestion(string $publicId)`.
- `classificationBatchCategoryPublicId`.
- `applyClassificationBatchCategory()`.
- Array update hook that synchronizes selection.

- [ ] **Step 1: Write page-selection RED**

Create two current-page collections and a third excluded by filter. Assert:

```php
Livewire::actingAs($admin)
    ->test(CatalogCollectionCategoryManager::class)
    ->call('selectCurrentClassificationPage')
    ->assertSet('selectedClassificationPublicIds', [
        $first->public_id,
        $second->public_id,
    ])
    ->call('clearClassificationSelection')
    ->assertSet('selectedClassificationPublicIds', []);
```

Assert all collection category FK values remain null.

- [ ] **Step 2: Verify RED**

```bash
php artisan test tests/Feature/CatalogCollectionClassificationAdministrationTest.php \
  --filter=page_selection
```

Expected: FAIL because actions do not exist.

- [ ] **Step 3: Implement page selection/clear**

Resolve current computed page server-side, select only its normalized public
UUIDs and cap at 50. Clear selection, category map, batch category,
validation and preview in the clear action. Reuse a private
`resetClassificationSelection()` helper from filter resets where appropriate.

- [ ] **Step 4: Write row suggestion/manual-sync RED**

For a high-confidence Netflix row:

- call `stageClassificationSuggestion($uuid)`;
- assert row selected and Netflix category prepared;
- call with a valid UUID outside current page and assert no state change plus
  localized error;
- set nested category map and assert the row becomes selected;
- clear the nested value and assert row is removed;
- assert no database write.

- [ ] **Step 5: Verify RED**

```bash
php artisan test tests/Feature/CatalogCollectionClassificationAdministrationTest.php \
  --filter='suggestion|manual_category'
```

Expected: FAIL because staging action and keyed array hook behavior are
missing.

- [ ] **Step 6: Implement row staging**

Use Livewire 4 array hook:

```php
public function updatedClassificationCategoryByCollection(
    mixed $value,
    mixed $key,
): void
```

Normalize UUID key, accept only scalar UUID category state, add/remove the
row from selection, cap to current-page UUIDs during render and always close
preview. Suggestion action re-resolves current page/suggestions and accepts
only `isSuggested()` output.

- [ ] **Step 7: Write batch staging RED**

Select two rows, set `classificationBatchCategoryPublicId` to an active
category, call `applyClassificationBatchCategory`, and assert both maps are
filled without a write. Cover:

- empty selection;
- inactive/unknown category;
- browser-injected UUID outside current page;
- maximum 50 rows.

- [ ] **Step 8: Verify RED**

```bash
php artisan test tests/Feature/CatalogCollectionClassificationAdministrationTest.php \
  --filter=batch_category
```

Expected: FAIL because batch state/action do not exist.

- [ ] **Step 9: Implement batch staging and controls**

Server-resolve active category UUIDs and current-page UUIDs. Reject invalid
state through existing localized classification validation. Fill only
selected current-page map entries. Render:

- select page;
- select high-confidence;
- clear selection;
- native batch category select;
- apply-to-selected;
- review selected.

Keep actions 44 px, wrapping and disabled/loading states. Do not call
`confirmAssignments()` from any staging action.

- [ ] **Step 10: Verify GREEN and confirm regression**

```bash
php artisan test tests/Feature/CatalogCollectionClassificationAdministrationTest.php
```

Expected: PASS including existing no-write preview and stale confirmation.

---

## Task 6: Request-scoped computed reuse and confidence-first page presentation

**Files:**

- Modify: `tests/Feature/CatalogCollectionClassificationAdministrationTest.php`
- Modify: `app/Livewire/Collections/CatalogCollectionCategoryManager.php`

**Produces:**

- Boot-injected category/classification query dependencies.
- `#[Computed]` tree/page/summary/suggestion owners.
- One classification page query per Livewire action request.
- Current-page-only confidence ordering.

- [ ] **Step 1: Write duplicate-query RED**

Attach `DB::listen()` around a Livewire `selectHighConfidence` call. Normalize
SQL and count the distinctive:

```text
from "catalog_collections"
where "catalog_collection_category_id" is null
order by "updated_at" desc, "id" desc
```

Assert the paginator select/count fingerprint occurs only once per action
request rather than once in action and again in render. Ignore setup/initial
mount queries by resetting counters immediately before the call.

- [ ] **Step 2: Verify RED**

```bash
php artisan test tests/Feature/CatalogCollectionClassificationAdministrationTest.php \
  --filter=request_scoped
```

Expected: FAIL with duplicated classification paginator query fingerprints.

- [ ] **Step 3: Implement computed owners**

Add boot-injected protected services and Livewire `#[Computed]` methods:

```php
#[Computed]
public function categoryTree(): Collection;

#[Computed]
public function classificationPage(): LengthAwarePaginator;

#[Computed]
public function classificationSuggestions(): array;

#[Computed]
public function classificationSummary(): CatalogCollectionClassificationSummary;
```

Action methods and render must access the same computed property names.
Computed values remain request-only and are never public serialized state.
Do not add Laravel shared cache or `Cache::remember`.

- [ ] **Step 4: Write presentation-order RED**

Create current-page rows producing high, low and none suggestions. Assert
view page UUID order is high → low → none while:

- total/current page membership is unchanged;
- underlying query test still asserts stable database ordering;
- selecting current page includes the same UUID set.

- [ ] **Step 5: Verify RED**

```bash
php artisan test tests/Feature/CatalogCollectionClassificationAdministrationTest.php \
  --filter=confidence_order
```

Expected: FAIL because render preserves database order.

- [ ] **Step 6: Implement stable page-only order**

After attaching suggestion presentation, sort the paginator collection by:

```php
[
    confidence rank,
    negative score,
    original page index,
]
```

Do not change SQL order, total, paginator page or global filtering.

- [ ] **Step 7: Verify GREEN and query budget**

```bash
php artisan test tests/Feature/CatalogCollectionClassificationAdministrationTest.php \
  tests/Feature/CatalogCollectionClassificationQueryTest.php
```

Expected: PASS and query-budget cap unchanged.

---

## Task 7: Progressive category dictionary and exact translation parity

**Files:**

- Modify: `tests/Feature/CatalogCollectionClassificationAdministrationTest.php`
- Modify: `tests/Unit/AdministrationTranslationParityTest.php`
- Modify: `resources/views/livewire/collections/catalog-collection-category-manager.blade.php`
- Modify: `lang/ru/collections.php`
- Modify: `lang/en/collections.php`

**Produces:**

- Native closed create disclosure.
- One native root disclosure per category.
- Existing create/move/edit actions inside accessible progressive UI.

- [ ] **Step 1: Write disclosure RED**

Assert manager HTML contains:

- `data-category-create-disclosure`;
- five `data-category-root-disclosure` elements;
- a semantic `<summary>`;
- existing create form and edit/move buttons;
- no `x-data`, modal/dialog, inline script/style, `<img>` or poster.

Assert a moderator without `content.manage` still receives no classification
queue and no create controls.

- [ ] **Step 2: Verify RED**

```bash
php artisan test tests/Feature/CatalogCollectionClassificationAdministrationTest.php \
  --filter=responsive_text_only
```

Expected: FAIL because create/tree content is always expanded and data
markers are absent.

- [ ] **Step 3: Implement native disclosures**

Wrap create form in one `<details>` with a translated summary. Replace each
root `<article>` shell with `<details>`:

- summary contains root identity/state/count and chevron;
- child grid and move/edit controls render below the summary;
- avoid interactive buttons inside summary;
- root move/edit controls live in the revealed body;
- keep selected edit panel unchanged.

- [ ] **Step 4: Complete RU/EN parity**

Add exact keys for:

- popular series anchor;
- moderation filter;
- select current page;
- clear selection;
- accept suggestion;
- batch target/apply;
- invalid/foreign suggestion;
- create disclosure;
- expand category;
- selection workflow hints.

Run recursive parity test.

- [ ] **Step 5: Verify GREEN, Blade rules and build**

```bash
php artisan test tests/Feature/CatalogCollectionClassificationAdministrationTest.php \
  tests/Unit/AdministrationTranslationParityTest.php \
  tests/Unit/BladeTemplateTest.php
./vendor/bin/pint --dirty --format agent
npm run build
```

Expected: PASS; no forbidden Blade execution, inline assets or deprecated
Tailwind utilities.

---

## Task 8: Query plan, focused regression and browser QA

**Files:**

- Modify: `tests/browser/discovery-collections.spec.js`
- Inspect: changed PHP/Blade/lang/tests
- Update: current Task 56 compliance evidence

- [ ] **Step 1: Extend browser assertions before markup GREEN**

Before the final markup is considered complete, update the existing browser
spec to assert:

- header collection link URL ends in `#collections`;
- collection region bounding-box `y` is less than title-results `y`;
- section navigation anchors are visible and work;
- category zero-count controls do not render;
- admin defaults show public/approved;
- page selection and batch staging update selection without confirmation;
- suggestion accept stages one row;
- preview/cancel remains functional;
- root dictionary is collapsed and can be expanded;
- no collection-region image and no horizontal overflow.

Keep Desktop Chromium, Mobile Chromium and Tablet Chromium projects.

- [ ] **Step 2: Run focused PHP regression**

```bash
php artisan test \
  tests/Feature/CatalogDiscoveryLayoutTest.php \
  tests/Feature/UnifiedDiscoveryCollectionsTest.php \
  tests/Feature/CatalogCollectionExplorerCategoryTest.php \
  tests/Feature/CatalogCollectionDirectoryCategoryTest.php \
  tests/Feature/CatalogCollectionClassificationQueryTest.php \
  tests/Feature/CatalogCollectionClassificationAdministrationTest.php \
  tests/Unit/AppLayoutOptionalNavigationTest.php \
  tests/Unit/AdministrationTranslationParityTest.php \
  tests/Unit/BladeTemplateTest.php
```

- [ ] **Step 3: Run exact SQLite plan evidence**

```bash
sqlite3 database/database.sqlite \
  "EXPLAIN QUERY PLAN SELECT id FROM catalog_collections WHERE catalog_collection_category_id IS NULL AND visibility = 'public' AND moderation_status = 'approved' ORDER BY updated_at DESC, id DESC LIMIT 20;"
```

Record selected index/temp sort honestly. Do not add an index unless this
measured result changes the approved no-DDL decision and the plan is updated
first.

- [ ] **Step 4: Run browser QA**

```bash
npx playwright test tests/browser/discovery-collections.spec.js
```

Inspect generated desktop/mobile/tablet screenshots visually for hierarchy,
wrapping, category visibility, disclosures and absence of images.

- [ ] **Step 5: Run wider affected gates**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter='CatalogCollection|CatalogDiscovery|AppLayout|TranslationParity|BladeTemplate'
npm run build
git diff --check
```

If broad project tests expose an independent known blocker, reproduce it
separately and record it as unresolved. Do not claim a full pass.

---

## Task 9: Canonical documentation, final audit and safe delivery

**Files:**

- Modify: `docs/architecture.md`
- Modify: `docs/performance.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/administration.md`
- Modify: applicable recommendation/collection documentation from
  `docs/README.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: this plan

- [ ] **Step 1: Update canonical owners**

Record only implemented facts:

- collection-first popular order and anchors;
- zero-count navigation rules;
- stateful category/subcategory SEO;
- default public/approved classification queue;
- page/row/batch no-write staging;
- request-only computed reuse and unchanged query caps;
- progressive category dictionary;
- no route/schema/dependency/data/image change;
- rollout/rollback and manual-confirmation production limitation.

- [ ] **Step 2: Update README and CHANGELOG**

README:

- update relevant visitor/product overview;
- add a dated visitor-history entry describing easier access and category
  navigation;
- keep «История обновлений для посетителей» as the final H2;
- do not hand-edit managed project-docs block.

CHANGELOG:

- add a separate dated Russian entry;
- preserve every previous entry;
- keep exact identifiers in technical form.

- [ ] **Step 3: Refresh/check docs**

```bash
php artisan project:docs-refresh
php artisan project:docs-refresh --check --no-interaction
bash scripts/check-readme-policy.sh
bash scripts/check-changelog-policy.sh
bash scripts/ci-check.sh docs
```

- [ ] **Step 4: Re-read requirements/spec/plan**

Fresh-read:

- `AGENTS.md`;
- `docs/requirements/index.md` and every applicable owner;
- approved Task 56 design;
- this implementation plan;
- updated current compliance matrix.

Correct every unsupported `completed` status.

- [ ] **Step 5: Repository-wide relevant legacy scan**

Search and inspect:

```bash
rg -n "collection.*(cover|image|poster)|cover_url|fallback.*poster" \
  app resources routes config lang tests docs
rg -n "catalog_collection_category_id|classification|collections_category|collections_subcategory" \
  app resources routes config lang tests docs
rg -n "CatalogCollectionClassificationQuery|suggestionsFor|confirmAssignments" \
  app tests
```

Confirm no duplicate route/service, automatic category write, dead zero-count
control, unbounded inference, query from Blade, inline business JS/CSS or
stale image behavior remains in Task 56 scope.

- [ ] **Step 6: Exact diff and branch review**

```bash
git status --short --branch
git diff --name-only
git diff --check
git diff --stat
```

Branch must be `main`. Preserve concurrent staged/unstaged paths outside Task
56. Use an isolated/path-limited index if the shared index is still mixed.

- [ ] **Step 7: Commit and push**

Commit only Task 56 paths with a Russian CHANGELOG and meaningful README
change. Do not use `--no-verify`. Push the completed commit to configured
`origin main`. If authentication or remote state rejects the push, record
exact output as `unresolved`; do not report success.

---

## Final acceptance checklist

- [ ] `/discover/popular` remains the only collection directory route.
- [ ] Global «Подборки» opens `#collections` in RU and EN.
- [ ] Collection explorer appears before title results.
- [ ] Other discovery modes remain collection-free.
- [ ] Zero-count categories/subcategories are not dead public controls.
- [ ] Category/subcategory URL variants are noindex with clean canonical.
- [ ] Admin default queue is public/approved and remains filterable.
- [ ] Page/row/batch staging performs zero writes.
- [ ] Existing preview/confirm authorization/concurrency is unchanged.
- [ ] One Livewire action request does not duplicate classification page SQL.
- [ ] Confidence sorting is current-page-only.
- [ ] Category dictionary uses native progressive disclosure.
- [ ] RU/EN parity, 44 px, mobile wrapping and text-only contracts pass.
- [ ] Query cap and existing index path remain bounded.
- [ ] No migration/dependency/env/route/cache-store/production DML exists.
- [ ] Canonical docs, README, CHANGELOG and compliance evidence are current.
- [ ] Task 56 commit is isolated in `main`; push result is reported honestly.
