maksimalno peredelaj footer, maksimalno vyvedi tam poleznuju informaciju, no ne delaj pustye i ne nuznye veshi, podkliuci mcp dlia codex ctob rabotat s dizainom i rabotalo namnogo lucse cem sejcias,
  ctob dizajn byl namnogo lucse cem sejcias
  polnostju peredelaj stranicu ili element dlia pagination, sejcias dizajn ciornyj, a nadpisi vse na anglijskom, a portal na russkom: Showing 1 to 24 of 23729 results
 
  peredelaj vsio na russkom a potom peredelaj pagination ctob byl svetlogo cveta, a tak ze sdelaj mobile responsive dlia pagination, sejcias netu nikakix perevodov dlia pagination na mobile. a tak ze
  peredelaj i lucse sdelaj mobile versiju portala
  vezde gde est takie filtry:
 
  Недавно обновленные
  Видео: больше сначала
  Серий: больше сначала
  Год: новые сначала
  Год: старые сначала
  Название: А-я
 
  sdelaj esio bolse punktov dlia filtra, no sdelaj tak ctob dizajn ne byl peregruzonyj i bylo udobno polzovatsia imi i bylo vsio intuitivno jasno, posmotri informaciju v internete i realizuj eto
  naprimer na stranice: https://seasonvar.miniserver.fun/titles?actor=irina-rozanova&sort=title_asc u nas est tolko filtraciaj po aktioru, a ja xociu ctob mozno bylo dobavit esio kakie nibud aktiory, i
  drugije momenty, goda, rezisior, strany i ne odnu, vezde eto nado sdelat, ctob byl odin giganskij universalnyj poisk dlia podkliucenija obsoliutno vsex etix punktov. obnovi eto delo. sdelaj plan i
  nacni programirovanija, ne zadavaja nikakix voprosov. delaj vsio po tvoej rekomendaciji, najdi v vsiu nuznuju informaciju v internete. podkliuci skills, podkliuci mcp, obnovi vse md fajly, delaj vsiu
  rabotu ot samogo naciala plana i do samogo konca plana
 
  Create a plan?  shift + tab use Plan mode   esc dismiss


# Catalog Facets, Complete Title Pages, and Metadata Backfill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a fast multi-select Seasonvar catalog, fully clickable cards, complete safe title pages, and progressive importer recovery for missing relations.

**Architecture:** Preserve the existing Laravel Request → PageBuilder → Query → ViewModel and Seasonvar pipeline boundaries. Add grouped filter state and aggregate facet queries for the web catalog, reusable Blade components with progressive JavaScript enhancement, and a shared relation synchronizer plus versioned snapshot/media backfill inside the existing `seasonvar:import` command.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent/SQLite, Blade, Tailwind CSS 4.3, Vite 8, vanilla JavaScript, PHPUnit 12.5, Laravel HTTP fakes, Playwright CLI.

## Global Constraints

- Visible interface text and user-facing validation errors are Russian.
- `php artisan seasonvar:import` remains the only public Seasonvar import command.
- No production dependency is added and `.env` is not edited.
- Explicit relation tables and pivots remain; no polymorphic metadata relation is introduced.
- Scalar legacy filter URLs continue to work; normalized interactive URLs use repeated arrays.
- OR is used within one filter type and AND between filter types.
- Each filter type accepts at most 20 unique values.
- Public pages never expose source pages, snapshots, import events, hashes, raw source URLs, or unselected media URLs. The selected published playback URL remains the existing player contract until a separately designed media proxy exists.
- Missing optional source metadata is rendered as `Не указано`; it is never invented.
- Work in the current dirty shared checkout, preserve unrelated/concurrent changes, and use exact-file diff checkpoints instead of commits.

---

### Task 1: Normalize and Validate Multi-Value Filter Input

**Files:**

- Modify: `app/Http/Requests/CatalogTitlesRequest.php`
- Modify: `tests/Unit/CatalogTitlesRequestTest.php`
- Modify: `tests/Feature/CatalogValidationTest.php`

**Interfaces:**

- Produces: `CatalogTitlesRequest::years(): list<int>`.
- Produces: `CatalogTitlesRequest::filterSlugs(): array<string, list<string>>`.
- Produces: `CatalogTitlesRequest::videoAvailability(): ?string`.
- Produces: `CatalogTitlesRequest::episodeAvailability(): ?string`.
- Preserves: existing scalar `year()`, `requestedYear()`, `filterSlug()`, legacy route parameters, search, and sort behavior until downstream callers migrate.

- [ ] **Step 1: Add request tests for scalar compatibility and arrays**

Add tests which initialize the Form Request with query parameters and assert:

```php
$request = CatalogTitlesRequest::create('/titles', 'GET', [
    'year' => ['2024', '2023', '2024'],
    'country' => ['rossiya', 'kanada', 'rossiya'],
    'actor' => 'ivan-ivanov',
    'video' => 'available',
    'episodes' => 'missing',
]);
$request->setContainer(app())->setRedirector(app('redirect'));
$request->validateResolved();

$this->assertSame([2024, 2023], $request->years());
$this->assertSame(['rossiya', 'kanada'], $request->filterSlugs()['country']);
$this->assertSame(['ivan-ivanov'], $request->filterSlugs()['actor']);
$this->assertSame('available', $request->videoAvailability());
$this->assertSame('missing', $request->episodeAvailability());
```

Also assert that 21 values, nested arrays, malformed slugs, years below 1900, and years above `now()->year + 1` fail with Russian messages.

Cover `/titles/year/{year}` explicitly: when the query has no `year`, `prepareForValidation()` must normalize the route value before validation so `years()` and downstream grouped state see it. Replace the legacy expectation that malformed `year=abcd` renders a 200-page chip with the validated redirect/error contract.

- [ ] **Step 2: Verify RED**

Run:

```bash
php artisan test --filter=CatalogTitlesRequestTest
php artisan test --filter=CatalogValidationTest
```

Expected: new array tests fail because fields are scalar and the new accessors do not exist.

- [ ] **Step 3: Implement bounded normalization**

Add `public const MAX_SELECTIONS = 20`. In `prepareForValidation()`, normalize `year` and each `CatalogFilterType::value` with a helper that turns a scalar into a one-element list, preserves a real list, trims scalar members, removes empty values, and de-duplicates without truncating. Leave nested values intact so validation rejects them.

Rules use:

```php
'year' => ['nullable', 'array', 'max:'.self::MAX_SELECTIONS],
'year.*' => ['required', 'integer', 'distinct', 'min:1900', 'max:'.((int) now()->format('Y') + 1)],
'video' => ['nullable', 'string', Rule::in(['available', 'missing'])],
'episodes' => ['nullable', 'string', Rule::in(['available', 'missing'])],
```

Each relation uses an array rule plus `relation.*` with the existing maximum length and `CatalogFilterSlug`. `years()` casts validated values to integers; `filterSlugs()` returns every supported type, including an empty list. `year()` returns the sole selected year or `null` for zero/multiple values so legacy SEO callers cannot silently discard a second year.

- [ ] **Step 4: Verify GREEN and checkpoint**

Run the two commands from Step 2. Then run:

```bash
./vendor/bin/pint --dirty --format agent
git diff --check -- app/Http/Requests/CatalogTitlesRequest.php tests/Unit/CatalogTitlesRequestTest.php tests/Feature/CatalogValidationTest.php
```

Expected: all request/validation tests pass and diff check is empty.

---

### Task 2: Apply OR/AND Query Semantics and Stable Search Candidates

**Files:**

- Modify: `app/Services/Catalog/CatalogTitleQuery.php`
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php`
- Modify: `app/View/ViewModels/CatalogTitlesViewModel.php`
- Modify: `app/Services/Catalog/CatalogSeoBuilder.php`
- Create: `tests/Feature/CatalogMultiFilterTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `tests/Unit/CatalogTitlesViewModelTest.php`

**Interfaces:**

- `CatalogTitleQuery::filteredTitles()` consumes `Collection<string, Collection<int, Model>>`, invalid slug lists, `list<int> $years`, availability values, and an optional reusable candidate ID collection.
- `CatalogTitleQuery::searchCandidateIds()` evaluates a non-empty normalized search once.
- `CatalogTitlesPageBuilder` exposes grouped active/invalid selections and never runs the unrelated full-catalog search fallback.
- `CatalogTitlesViewModel` builds repeated array query parameters and removes one selected value at a time.

- [ ] **Step 1: Write multi-filter feature tests**

Create fixtures proving:

```php
// OR inside years.
$this->get('/titles?year[]=2023&year[]=2024')
    ->assertSeeText('Сериал 2023')
    ->assertSeeText('Сериал 2024')
    ->assertDontSeeText('Сериал 2022');

// OR inside countries, AND across actor.
$this->get('/titles?country[]=rossiya&country[]=kanada&actor[]=ivan-petrov')
    ->assertSeeText('Россия с Иваном')
    ->assertSeeText('Канада с Иваном')
    ->assertDontSeeText('Россия без Ивана');
```

Cover every relation type in one parameterized test, `video=available|missing`, `episodes=available|missing`, unknown valid slugs yielding zero results, query preservation in pagination/sort links, and no fallback title for an unknown search.

- [ ] **Step 2: Verify RED**

Run:

```bash
php artisan test --filter=CatalogMultiFilterTest
```

Expected: array filters do not resolve or apply and fallback behavior is still visible.

- [ ] **Step 3: Refactor relation filtering**

Replace one-model-per-type filtering with one indexed pivot subquery per group:

```php
foreach ($activeTaxonomyGroups as $filterType => $activeTaxonomies) {
    if ($filterType === $exceptTaxonomyType || $activeTaxonomies->isEmpty()) {
        continue;
    }

    $query->whereIn(
        $catalogTitleTable.'.id',
        DB::table($pivotTable)
            ->select($pivotTable.'.'.$titlePivotKey)
            ->whereIn($pivotTable.'.'.$relatedPivotKey, $activeTaxonomies->modelKeys()),
    );
}
```

Apply years with `whereIn`, apply availability with `whereHas`/`whereDoesntHave`, and keep `published()` at the base. When search is non-empty, call `searchCandidateIds()` once in the builder and constrain subsequent queries by those IDs instead of replaying the broad search expression.

- [ ] **Step 4: Bulk-resolve requested slugs and remove fallback**

For each type, query the model once with `whereIn('slug', $requestedSlugs)`, restore requested ordering, and compute invalid slugs with `array_diff`. Remove the second paginator query that substitutes an empty search. Flatten active models only for the existing SEO helper, using stable compound keys such as `country` and `country:kanada`; keep grouped collections for query/UI behavior.

- [ ] **Step 5: Update query-building view model**

Represent `year`, relation values, and invalid values as arrays. `filterQuery($type, $slug)` toggles one slug; `withoutFilterValueQuery($type, $slug)` removes one; `yearQuery($year)` toggles one year; all builders preserve search, non-default sort, title context, video, and episodes, and omit `page`.

- [ ] **Step 6: Update SEO compatibility**

Flatten names for copy, use the sole selected year only for the indexable legacy landing, and mark any multi-year or multi-relation combination `noindex,follow`. Ensure canonical query sorting uses normalized arrays. Delete all “ближайшие результаты” fallback claims.

- [ ] **Step 7: Verify GREEN and checkpoint**

Run:

```bash
php artisan test --filter=CatalogMultiFilterTest
php artisan test --filter=CatalogPageTest
php artisan test --filter=CatalogTitlesViewModelTest
./vendor/bin/pint --dirty --format agent
git diff --check -- app/Services/Catalog app/View/ViewModels/CatalogTitlesViewModel.php tests/Feature/CatalogMultiFilterTest.php tests/Feature/CatalogPageTest.php tests/Unit/CatalogTitlesViewModelTest.php
```

Expected: multi-filter, existing catalog, and view-model suites pass; identical sorts end in descending title ID.

---

### Task 3: Replace Correlated Global Facets with Context Aggregates

**Files:**

- Create: `app/Services/Catalog/CatalogFacetQuery.php`
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php`
- Modify: `app/Services/Catalog/CatalogTitleQuery.php`
- Create: `tests/Unit/CatalogFacetQueryTest.php`
- Modify: `tests/Feature/CatalogMultiFilterTest.php`

**Interfaces:**

- `CatalogFacetQuery::relationFacets(...): Collection<string, Collection<int, Model>>` returns models carrying `context_titles_count`.
- `CatalogFacetQuery::yearFacets(...): Collection<int, object{year:int,context_titles_count:int}>` returns contextual years.
- Counts ignore the current dimension but apply search, years/other relations, availability, publication, and title context.

- [ ] **Step 1: Write aggregate facet tests**

Assert that a selected Russia filter still exposes Canada with the count produced by all non-country constraints, while an actor constraint reduces both country counts. Assert unpublished titles never affect counts, selected values are retained outside the normal top limit, and query count stays bounded.

- [ ] **Step 2: Verify RED**

Run:

```bash
php artisan test --filter=CatalogFacetQueryTest
```

Expected: class missing.

- [ ] **Step 3: Implement pivot aggregates**

For each registered relation, obtain pivot metadata from the Eloquent relation and build:

```php
$counts = DB::table($pivotTable)
    ->joinSub($contextTitles->select('catalog_titles.id'), $alias, fn ($join) =>
        $join->on($alias.'.id', '=', $pivotTable.'.'.$titlePivotKey))
    ->selectRaw($pivotTable.'.'.$relatedPivotKey.' as relation_id')
    ->selectRaw('count(distinct '.$pivotTable.'.'.$titlePivotKey.') as context_titles_count')
    ->groupBy($pivotTable.'.'.$relatedPivotKey);
```

Join this subquery to the lookup model, order by selected-first, contextual count descending, then name, and limit by the existing per-type limits. Merge selected records that have zero context. Do not calculate `catalog_titles_count` globally.

- [ ] **Step 4: Reuse search candidates and remove old count method**

Pass the materialized non-empty search candidates to every context query. Delete `relationContextCounts()` after all callers migrate. Year facets use the same context query with years excluded.

- [ ] **Step 5: Measure and verify GREEN**

Run unit/feature tests, then measure the builder against the real read-only database using a timed GET through a local server or browser. Record HTML size, query count, and warm duration for `q=бухта` in `docs/performance.md`; do not alter the real database.

```bash
php artisan test --filter=CatalogFacetQueryTest
php artisan test --filter=CatalogMultiFilterTest
./vendor/bin/pint --dirty --format agent
```

Expected: correct contextual counts and a substantial reduction from the observed 10–11 second baseline.

---

### Task 4: Build Responsive Filter Drawer, Facet Search, and Clickable Cards

**Files:**

- Create: `resources/views/components/catalog/filter-panel.blade.php`
- Create: `resources/views/components/catalog/facet-group.blade.php`
- Modify: `resources/views/catalog/titles.blade.php`
- Modify: `resources/views/components/title-card.blade.php`
- Modify: `app/View/Components/TitleCard.php`
- Create: `resources/js/catalog-filters.js`
- Modify: `resources/js/app.js`
- Modify: `resources/css/app.css`
- Modify: `tests/Feature/CatalogVisualSystemTest.php`
- Modify: `tests/Feature/CatalogBladeComponentTest.php`
- Modify: `tests/Unit/FrontendAssetContractTest.php`

**Interfaces:**

- Filter form submits repeated GET arrays and retains search/sort/context/availability.
- `catalog-filters.js` controls the mobile drawer, Escape/return-focus, disclosure option filtering, and clear ARIA state.
- Title card has one show-route anchor whose pseudo-element/stretched area covers non-interactive card space; relation chips remain independent links above it.

- [ ] **Step 1: Add failing accessibility/contract tests**

Assert one title show URL per card, `data-catalog-card`, `data-catalog-filter-dialog`, repeated checkbox names like `country[]`, Russian apply/reset copy, labelled facet searches, and no nested anchors. Assert frontend entry lazy-loads the filter module only when the trigger exists.

- [ ] **Step 2: Verify RED**

```bash
php artisan test --filter=CatalogVisualSystemTest
php artisan test --filter=CatalogBladeComponentTest
php artisan test --filter=FrontendAssetContractTest
```

- [ ] **Step 3: Implement reusable facet form**

Use native `<details>` groups containing labelled checkbox rows and contextual counts. Keep years/countries/genres prominent and high-cardinality groups collapsed. Add local option search with `data-facet-search` and normalized case-insensitive matching. The drawer footer contains `Применить` and a route-based `Сбросить` link.

- [ ] **Step 4: Implement mobile drawer behavior**

The module opens the existing sidebar as a modal surface below `lg`, sets `aria-expanded`, locks only page scrolling while open, closes on backdrop/close/Escape, and returns focus to the trigger. Desktop remains a sticky, height-bounded sidebar. No filter markup is duplicated.

- [ ] **Step 5: Make cards compact and stretched-link safe**

Use a horizontal poster/content grid below `sm` and vertical card above it. Put the title link pseudo-element over the card, then give relation-chip containers `relative z-10`. Remove the separate poster anchor so keyboard users get one title tab stop.

- [ ] **Step 6: Verify GREEN and frontend build**

```bash
php artisan test --filter=CatalogVisualSystemTest
php artisan test --filter=CatalogBladeComponentTest
php artisan test --filter=FrontendAssetContractTest
npm run build
git diff --check -- resources/views/catalog/titles.blade.php resources/views/components resources/js resources/css/app.css tests/Feature/CatalogVisualSystemTest.php tests/Feature/CatalogBladeComponentTest.php tests/Unit/FrontendAssetContractTest.php
```

---

### Task 5: Render One Complete, Safe Title Information Surface

**Files:**

- Modify: `app/Services/Catalog/CatalogTitlePageBuilder.php`
- Modify: `app/Services/Catalog/CatalogTaxonomyRegistry.php`
- Modify: `app/View/ViewModels/CatalogShowViewModel.php`
- Create: `resources/views/components/catalog/title-relations.blade.php`
- Modify: `resources/views/catalog/show.blade.php`
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `tests/Feature/CatalogBladeComponentTest.php`
- Modify: `tests/Feature/SecurityHardeningTest.php`

**Interfaces:**

- Builder eager-loads all ten public taxonomy relations, aliases, ratings, up to 20 recent reviews, seasons/episodes, and published safe media state.
- View model produces relation rows for all ten groups, plus aliases/ratings, with empty-state metadata.
- Blade displays every group exactly once and never exposes internal relations/URLs.

- [ ] **Step 1: Add failing full-relation tests**

Create a title with one record in every relation, aliases, ratings, a review, season, episode, and media. Assert each Russian label/value appears, only one `<main` exists, one `<h1` exists, and source/importer URLs, hashes, internal fields, and unselected media URLs do not appear. Preserve the selected published playback URL required by the current player contract. Add a second empty title and assert each requested group renders `Не указано`.

Add publication-boundary regressions: an unpublished title cannot be opened through route binding, and genre/year fallback recommendations never include unpublished titles.

- [ ] **Step 2: Verify RED**

```bash
php artisan test --filter=title_page_renders_all_public_relations
php artisan test --filter=title_page_renders_explicit_empty_relation_states
```

- [ ] **Step 3: Load and shape safe public data**

Add a constrained reviews eager-load ordered by publication/id and limited to 20. Build rows from the registry so labels/icons cannot drift between catalog and show. Ratings expose provider, numeric rating, and votes; aliases expose name/type only.

- [ ] **Step 4: Recompose the page**

Replace the inner `<main>` with a section/div. Keep a compact hero, move player immediately after it, render the single `О сериале` component, then seasons, reviews, recommendations, and FAQ. Convert the sidebar to page anchors and counts; remove duplicate actor/taxonomy blocks.

- [ ] **Step 5: Verify GREEN**

```bash
php artisan test --filter=CatalogPageTest
php artisan test --filter=CatalogBladeComponentTest
php artisan test --filter=SecurityHardeningTest
./vendor/bin/pint --dirty --format agent
npm run build
```

---

### Task 6: Expand Trusted Seasonvar Metadata Parsing and Shared Relation Sync

**Files:**

- Create: `app/Services/Seasonvar/SeasonvarCatalogRelationSyncer.php`
- Create: `app/Services/Seasonvar/SeasonvarRelationMetadataNormalizer.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogParser.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Modify: `app/Services/Media/ExternalMediaMetadata.php`
- Modify: `app/Services/Catalog/CatalogRelationNameSanitizer.php`
- Modify: `tests/Unit/SeasonvarCatalogParserTest.php`
- Modify: `tests/Feature/SeasonvarParsePageCommandTest.php`
- Modify: `tests/Unit/ExternalMediaMetadataTest.php`
- Modify: `tests/Unit/CatalogRelationNameSanitizerTest.php`

**Interfaces:**

- `SeasonvarCatalogRelationSyncer::sync(CatalogTitle $title, array $taxonomies, ?callable $progress): array` returns attached IDs/counts by type.
- `SeasonvarRelationMetadataNormalizer::translation(?string $value): ?string` strips playback quality prefixes and rejects trailers/subtitle placeholders.
- Parser reads trusted main info, official `pgs-trans`, structured production data, strictly labelled official description metadata, and curated broadcaster tags; it never mines reviews or user comments.

- [ ] **Step 1: Write parser/noise tests**

Fixtures include:

- main info `Статус: идет`, `Телеканал: Пятница`, `Студии: A-1 Pictures`;
- official `pgs-trans` entries for `RuDub`, `NewStudio`, and `LostFilm`;
- JSON-LD `productionCompany`;
- official description line `Студия: J.C.Staff`;
- user comment containing `Канал: TV Tokyo`, which must not create a network;
- review text containing `Статус: завершен`, which must not create a status;
- placeholders `ничего не найдено` and `Статус: Рекомендовано!`, which must be rejected;
- country values `Тайвань`, `Армения`, `Исландия`, `Чехословакия`, and `Филиппины`.

- [ ] **Step 2: Write media normalization tests**

Assert `HDRuDub`, `SDRuDub`, and `FullHDRuDub` normalize to `RuDub`; `HDLostFilm` to `LostFilm`; `HDHDRezka` to `HDRezka`; `Трейлеры` and subtitles return `null`; original variants return `Оригинал`.

- [ ] **Step 3: Verify RED**

```bash
php artisan test --filter=SeasonvarCatalogParserTest
php artisan test --filter=ExternalMediaMetadataTest
php artisan test --filter=CatalogRelationNameSanitizerTest
```

- [ ] **Step 4: Extract the shared synchronizer**

Move relation config, bulk lookup `upsert`, slug generation, validation, and `syncWithoutDetaching` from the importer into the new service. Inject it into the importer and preserve existing progress event names/payloads. Split upserts with and without a source URL so a new null observation cannot erase a previously stored URL.

- [ ] **Step 5: Expand only trusted parsing**

Parse official translation selector nodes and direct metadata labels. Expand the country allowlist using verified source values and canonical aliases. Extract studio/network/status from official description/metadata nodes only when an exact line label and value validator match; reviews and comments are excluded. Exact tags may map to a small curated network list. Map status vocabulary to stable Russian values; never infer a series status merely because one season has all episodes.

- [ ] **Step 6: Sync derived relations after media import**

After parsed playlists/media are stored, collect normalized voiceover/original variants and attach title translations through the same synchronizer. Do not attach trailer/subtitle values as translations.

- [ ] **Step 7: Fix multi-match parsing and transaction boundaries**

Change parser checks that currently use `preg_match_all(...) !== 1` so any positive match count is processed, with tests containing at least two translations, episode numbers, and media URLs. Resolve or queue media availability HTTP before/after the catalog persistence transaction; the transaction contains only database work and cannot repeat external HTTP on retry.

- [ ] **Step 8: Verify GREEN**

```bash
php artisan test --filter=SeasonvarCatalogParserTest
php artisan test --filter=SeasonvarParsePageCommandTest
php artisan test --filter=ExternalMediaMetadataTest
php artisan test --filter=CatalogRelationNameSanitizerTest
./vendor/bin/pint --dirty --format agent
```

---

### Task 7: Build the Versioned Local Metadata Backfill Core

**Files:**

- Create: `database/migrations/2026_07_12_000000_add_metadata_versions_to_catalog_import_tables.php`
- Modify: `app/Models/SourcePage.php`
- Modify: `app/Models/CatalogTitle.php`
- Modify: `app/Models/SourcePageSnapshot.php`
- Create: `app/Services/Seasonvar/SeasonvarCatalogMetadataBackfill.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogParser.php`
- Modify: `config/seasonvar.php`
- Modify: `.env.example`
- Create: `tests/Feature/SeasonvarCatalogMetadataBackfillTest.php`
- Modify: `tests/Unit/EloquentRelationshipTest.php`

**Interfaces:**

- `SeasonvarCatalogParser::METADATA_VERSION` is the current parser contract.
- `source_pages.metadata_parser_version`, `metadata_attempted_version`, `metadata_parsed_at`, `metadata_presence`, and `catalog_titles.relation_metadata_version` are additive; version columns default to zero and are indexed with ID.
- `SeasonvarCatalogMetadataBackfill::run(?callable $progress): array{pages_checked:int,pages_updated:int,titles_checked:int,titles_updated:int,relations_attached:int,failed:int}`.
- Local backfill performs no HTTP and is independently callable by the existing pipeline in Task 8.

- [ ] **Step 1: Write migration/model contract tests**

Assert all version columns cast to integer, default to zero in both schema and model attributes, are fillable, and have queue indexes. Assert `metadata_parsed_at` is a datetime and `metadata_presence` is an array. Add a `latestSnapshot(): HasOne` relation using `ofMany(['captured_at' => 'max', 'id' => 'max'])`, backed by `(source_page_id, captured_at, id)`.

- [ ] **Step 2: Write failing snapshot backfill tests**

Create a parsed page/version zero, linked title/version zero, and retained snapshots containing official translations/studio/network. Make the most recently captured snapshot reuse an older row ID/hash and assert it is selected by `captured_at`. Run the service and assert relations attach, parser/attempted/title versions advance only after success, presence states are stored, a second run is idempotent with zero newly attached pivots, and no HTTP request occurs (`Http::preventStrayRequests()` plus `Http::assertNothingSent()`).

Add a season-linked source page without direct `catalog_titles.source_page_id`, resolve it through the season source hash, and assert the correct title is updated. Add a media-only title with `HDRuDub` and assert normalized `RuDub` attaches. Add a deliberately invalid snapshot and assert the parser version remains stale, the attempted version advances, it is counted only once locally, and later valid rows are not starved. Remote-refresh eligibility is covered at the planner integration boundary in Task 8.

Assert hard per-cycle page/title limits cap total records rather than only chunk memory. Assert presence precedence is `present` over `rejected_invalid` over `absent_in_source` without persisting raw rejected values. Force relation sync failure and assert pivots and every version roll back together. A missing snapshot is skipped without being counted as a parser failure.

- [ ] **Step 3: Verify RED**

```bash
php artisan test --filter=SeasonvarCatalogMetadataBackfillTest
```

- [ ] **Step 4: Implement additive migration and bounded service**

Use reversible nullable-safe version, datetime, and JSON columns with indexes:

```php
$table->unsignedInteger('metadata_parser_version')->default(0);
$table->unsignedInteger('metadata_attempted_version')->default(0);
$table->timestamp('metadata_parsed_at')->nullable();
$table->json('metadata_presence')->nullable();
$table->index(['page_type', 'metadata_parser_version', 'metadata_attempted_version', 'id'], 'source_pages_metadata_queue_idx');
```

and the equivalent title column/index, plus the snapshot lookup index. Generate the migration with Artisan and keep its actual unique timestamp. The service uses `lazyById(...)->take($hardLimit)`, latest retained snapshots, shared parsing/sync, title media/season eager loads, per-record database-only transactions, and separately configured chunk sizes and hard per-cycle limits. DOM parsing and validation occur before the transaction. Deterministic invalid HTML advances only `metadata_attempted_version`; database/infrastructure failures advance no version.

- [ ] **Step 5: Verify GREEN and checkpoint**

```bash
php artisan test --filter=SeasonvarCatalogMetadataBackfillTest
php artisan test --filter=EloquentRelationshipTest
./vendor/bin/pint --format agent <exact Task 7 PHP files>
```

Expected: versioned local backfill is idempotent, hard-bounded, transactional, and sends no HTTP.

---

### Task 8: Integrate Metadata Backfill, Refresh Planning, and Retention

**Files:**

- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Modify: `app/Services/Seasonvar/SeasonvarRefreshPlanner.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportStorageMaintenance.php`
- Modify: `app/Services/Seasonvar/SeasonvarTitleMerger.php`
- Modify: `app/Services/Seasonvar/SeasonvarUrl.php`
- Modify: `app/Console/Commands/Concerns/OutputsSeasonvarProgress.php`
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`
- Modify: `tests/Feature/SeasonvarParsePageCommandTest.php`
- Modify: `tests/Feature/SeasonvarTitleMergeTest.php`
- Modify: `tests/Unit/SeasonvarImportStorageMaintenanceTest.php`
- Modify: `tests/Unit/SeasonvarCatalogParserTest.php`

**Interfaces:**

- Pipeline calls the Task 7 service once per cycle before remote page selection and stores its raw result under `summary.last_metadata_backfill`.
- `stale_metadata` selects only pages that cannot still be handled by an unattempted retained snapshot.
- Seasonvar catalog/source URLs are HTTPS-only.

- [ ] **Step 1: Write failing integration tests**

Assert an unchanged page skips only at the current parser version, while a stale version reparses and successful remote parsing advances both page versions. Assert an unattempted retained snapshot is excluded from every remote planner reason; a missing snapshot or deterministic attempted failure is eligible for bounded `stale_metadata`, ordered before generic `stale`.

Assert maintenance always retains the snapshot selected by `captured_at`, a title merge preserves the minimum `relation_metadata_version`, pipeline progress exposes all six prefixed metadata counters with Russian labels, and `last_metadata_backfill.failed` never overwrites the remote parse failure counter. Assert `http://seasonvar.ru/...` is rejected while HTTPS catalog URLs remain accepted.

- [ ] **Step 2: Verify RED**

```bash
php artisan test --filter=SeasonvarImportMaintenanceTest
php artisan test --filter=SeasonvarImportStorageMaintenanceTest
php artisan test --filter=SeasonvarTitleMergeTest
php artisan test --filter=SeasonvarParsePageCommandTest
```

- [ ] **Step 3: Make remote import and planner version-aware**

The unchanged-page fast path may skip only when `metadata_parser_version >= METADATA_VERSION`. Reserve pages with retained snapshots and `metadata_attempted_version < METADATA_VERSION` for local work so generic stale selection cannot fetch them early. Add a hard-bounded `stale_metadata` reason for pages without a snapshot or with `metadata_attempted_version >= METADATA_VERSION` and a stale parser version; order it before generic `stale`. On successful remote parse update both page versions. Preserve existing media retry flags; do not add `no_studio` or `no_network` retry loops. Enforce the HTTPS Seasonvar source boundary in the shared URL service.

- [ ] **Step 4: Integrate pipeline, progress, retention, and merging**

Run metadata backfill after retention maintenance and before relation cleanup, recommendation rebuild, and remote page selection. Prefix cycle counters with `metadata_` and store the raw result under `summary.last_metadata_backfill` so its `failed` count cannot overwrite remote-page failures. Add Russian progress labels. Snapshot maintenance may prune old duplicates but must always retain each page's latest captured snapshot. When titles merge, keep the minimum `relation_metadata_version` so stale derived metadata is never hidden by a current canonical record.

- [ ] **Step 5: Verify GREEN and checkpoint**

```bash
php artisan test --filter=SeasonvarImportMaintenanceTest
php artisan test --filter=SeasonvarImportStorageMaintenanceTest
php artisan test --filter=SeasonvarTitleMergeTest
php artisan test --filter=SeasonvarParsePageCommandTest
php artisan test --filter=Seasonvar
./vendor/bin/pint --format agent <exact Task 8 PHP files>
```

Expected: the existing `seasonvar:import` lifecycle performs bounded local recovery first, never creates unbounded remote calls, and preserves existing remote failure semantics.

---

### Task 9: Documentation, Full Verification, Browser QA, and Independent Review

**Files:**

- Modify: `README.md`
- Modify: `docs/CODE_STANDARDS.md`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/performance.md`
- Modify: `docs/catalog-search.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: `.env.example`

**Interfaces:**

- Documentation reports the actual optional nature and measured coverage of relations.
- QA artifacts stay outside tracked project files unless the existing docs explicitly require a report.

- [ ] **Step 1: Update project-specific documentation**

Document repeated-array URLs, OR/AND semantics, legacy routes, availability filters, facet limits, safe public title fields, trusted parser sources, metadata versions, progressive backfill, configuration keys, and measured before/after timing/HTML size. Correct claims that all optional relation types are already universally populated.

- [ ] **Step 2: Refresh managed documentation**

Run:

```bash
php artisan project:docs-refresh
git diff --check
```

Inspect the command diff and keep only intended managed documentation changes.

- [ ] **Step 3: Run formatting and focused suites**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=CatalogMultiFilterTest
php artisan test --filter=CatalogFacetQueryTest
php artisan test --filter=CatalogPageTest
php artisan test --filter=SeasonvarCatalogParserTest
php artisan test --filter=SeasonvarCatalogMetadataBackfillTest
```

- [ ] **Step 4: Run full backend and frontend verification**

```bash
php artisan test
npm run build
```

Expected: zero failed tests and build exit code zero. If a pre-existing/concurrent failure remains, reproduce it alone and report it distinctly.

- [ ] **Step 5: Perform Playwright QA**

At 390×844, 768×1024, 1440×1200, and 1920×1080 verify:

- search `бухта`;
- two years, two countries, and country+actor semantics;
- active chip removal, reset, sort, and pagination query preservation;
- mobile drawer open/close/Escape/focus return;
- option search and contextual counts;
- whole-card click versus relation-chip click;
- one populated and one empty title page;
- one main landmark, one H1, player position, no horizontal overflow;
- zero application console errors and no failed local assets.

- [ ] **Step 6: Re-measure data and performance read-only**

Record relation coverage after code (without running production migration/import), local request duration/query count/HTML size, and expected backfill gains from existing snapshots/media. Never run `migrate:fresh`, `db:wipe`, or a real broad import as verification.

- [ ] **Step 7: Request independent code review**

Prepare a diff package limited to this plan’s files. Reviewer checks spec coverage, query correctness, validation abuse cases, publication boundary, importer idempotency, source trust, URL exposure, responsive accessibility, test quality, and concurrent-change preservation. Fix all Critical/Important findings and re-run the covering tests before completion.
