# Catalog Metadata Deduplication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove duplicate and polluted title metadata from the current catalog and prevent equivalent aliases, people, and lookup relations from being reintroduced by Seasonvar or future importer adapters.

**Architecture:** Reuse the ten explicit lookup and pivot tables. Centralize canonical name/alias identity in catalog services, make the existing unique lookup `slug` the concurrency-safe cross-source key, extract bounded catalog cleanup from the importer pipeline, and enforce alias uniqueness independently of alias type.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent, SQLite, PHPUnit 12.5, Laravel Pint, Playwright CLI/fallback Chromium.

## Global Constraints

- Work only on the existing `main` branch; do not create branches or worktrees.
- Keep `php artisan seasonvar:import` as the only public Seasonvar import command.
- Keep the ten explicit lookup and pivot tables; do not introduce polymorphic catalog metadata.
- Run catalog writes in bounded transactions and use grouped queries, `lazyById`, `insertOrIgnore`, and unique constraints.
- Do not stop or mutate the active production importer until code is verified and an SQLite backup exists.
- Visible UI text and console output remain Russian.
- Write every behavior change test first and observe the expected failure before implementation.

---

### Task 1: Canonical relation identity and parser boundary

**Files:**
- Modify: `tests/Unit/CatalogRelationNameSanitizerTest.php`
- Modify: `tests/Unit/SeasonvarCatalogParserTest.php`
- Modify: `app/Services/Catalog/CatalogRelationNameSanitizer.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogParser.php`

**Interfaces:**
- Produces: `CatalogRelationNameSanitizer::canonicalKey(string $type, string $name): string`
- Produces: `CatalogRelationNameSanitizer::preferredName(string $type, string $current, string $incoming): string`
- Produces: a private parser relation-path classifier that returns `actor`, `director`, `genre`, `country`, or `null` from an anchored decoded path.

- [ ] **Step 1: Write failing sanitizer tests**

Add assertions proving legitimate names survive and serial/release pollution is rejected:

```php
public function test_it_rejects_serial_titles_and_release_announcements_as_people(): void
{
    $sanitizer = app(CatalogRelationNameSanitizer::class);

    foreach ([
        '1',
        '>>> Сериал Актер (2024)/Actor',
        'Сериал Фабрика кукол/The Doll Factory (04.12.2023 сериал полностью из 6)',
        'Женский характер (12.03.2026 8 серия из 8)',
    ] as $name) {
        $this->assertFalse($sanitizer->isValid('actor', $name), $name);
        $this->assertFalse($sanitizer->isValid('director', $name), $name);
    }

    foreach (['50 Cent', 'A$AP Ferg', '«Убийца» Майк', 'Ацуко Танака'] as $name) {
        $this->assertTrue($sanitizer->isValid('actor', $name), $name);
    }
}
```

Add canonical identity assertions:

```php
$this->assertSame(
    $sanitizer->canonicalKey('actor', 'Ацуко Танака'),
    $sanitizer->canonicalKey('actor', 'Atsuko Tanaka'),
);
$this->assertSame('Ацуко Танака', $sanitizer->preferredName('actor', 'Atsuko Tanaka', 'Ацуко Танака'));
```

- [ ] **Step 2: Run sanitizer tests and verify RED**

Run: `php artisan test --filter=CatalogRelationNameSanitizerTest`

Expected: FAIL because `canonicalKey()` and `preferredName()` do not exist and polluted people are currently accepted.

- [ ] **Step 3: Implement the shared identity rules**

Use `Normalizer::FORM_KC`, `Str::squish()`, `Str::lower()`, and `Str::slug()` in the sanitizer. Return a bounded hash only when slug generation is empty. Person validation must require a Unicode letter and reject the explicit release/title patterns covered by the test. `preferredName()` prefers Cyrillic for actor/director equivalents and otherwise retains the current normalized value.

- [ ] **Step 4: Write the failing parser regression**

Add a fixture containing both links:

```html
<a href="/serial-248327-Serial_Akter_2024Actor.html">&gt;&gt;&gt; Сериал Актер (2024)/Actor</a>
<a href="/actor/Adam%20Ian%20Cohen">Adam Ian Cohen</a>
```

Assert that the parsed actor list is exactly `['Adam Ian Cohen']`.

- [ ] **Step 5: Run the parser test and verify RED**

Run: `php artisan test --filter=SeasonvarCatalogParserTest`

Expected: FAIL because the serial URL is classified as an actor by substring.

- [ ] **Step 6: Anchor relation URL classification and verify GREEN**

Decode only the path and match `^/(actor|akter)(?:/|-)`, `^/(director|rezhisser)(?:/|-)`, `^/(genre|janr|zhanr)(?:/|-)`, and `^/(country|strana)(?:/|-)`. Re-run both focused test classes and expect PASS.

- [ ] **Step 7: Commit Task 1**

```bash
git add app/Services/Catalog/CatalogRelationNameSanitizer.php app/Services/Seasonvar/SeasonvarCatalogParser.php tests/Unit/CatalogRelationNameSanitizerTest.php tests/Unit/SeasonvarCatalogParserTest.php
git commit -m "fix: validate canonical catalog relation names"
```

### Task 2: Idempotent relation synchronization

**Files:**
- Create: `tests/Feature/SeasonvarCatalogRelationSyncerTest.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogRelationSyncer.php`

**Interfaces:**
- Consumes: `CatalogRelationNameSanitizer::canonicalKey()` and `preferredName()` from Task 1.
- Produces: existing `SeasonvarCatalogRelationSyncer::sync(CatalogTitle $title, array $taxonomies, ?callable $progress = null): array` with canonical identity behavior.

- [ ] **Step 1: Write failing encoded/unencoded and Cyrillic/Latin tests**

Create one title and call `sync()` twice with `Adam Ian Cohen` using encoded and decoded Seasonvar URLs. Assert one actor row and one pivot. In a second test sync `Atsuko Tanaka` then `Ацуко Танака`; assert one actor, one pivot, canonical slug equality, and preferred Cyrillic name.

- [ ] **Step 2: Verify RED**

Run: `php artisan test --filter=SeasonvarCatalogRelationSyncerTest`

Expected: FAIL with two actor rows because raw URLs force hash-suffixed slugs.

- [ ] **Step 3: Implement canonical sync**

Normalize safe Seasonvar URLs with the existing `SeasonvarUrl::normalize()`. Deduplicate the incoming collection by canonical key, look up existing rows by canonical slug, use preferred labels, preserve a non-null canonical source URL, and upsert on `slug` without adding a URL hash suffix for an equivalent name.

- [ ] **Step 4: Verify GREEN and related importer behavior**

Run:

```bash
php artisan test --filter=SeasonvarCatalogRelationSyncerTest
php artisan test --filter=SeasonvarCatalogMetadataBackfillTest
```

Expected: PASS.

- [ ] **Step 5: Commit Task 2**

```bash
git add app/Services/Seasonvar/SeasonvarCatalogRelationSyncer.php tests/Feature/SeasonvarCatalogRelationSyncerTest.php
git commit -m "fix: reuse canonical relation identities during import"
```

### Task 3: Alias canonicalization and database constraint

**Files:**
- Create: `app/Services/Catalog/CatalogTitleAliasNormalizer.php`
- Create: `tests/Unit/CatalogTitleAliasNormalizerTest.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogParser.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Modify: `app/Services/Catalog/CatalogTitlePageBuilder.php`
- Modify: `app/Services/Catalog/CatalogSeoBuilder.php`
- Create via Artisan: `database/migrations/2026_07_13_*.php` data cleanup migration
- Create via Artisan: `database/migrations/2026_07_13_*.php` alias uniqueness migration
- Create: `tests/Feature/CatalogTitleAliasDeduplicationTest.php`

**Interfaces:**
- Produces: `CatalogTitleAliasNormalizer::key(string $name): string`
- Produces: `CatalogTitleAliasNormalizer::hash(string $name): string`
- Produces: `CatalogTitleAliasNormalizer::visible(CatalogTitle $title, Collection $aliases): Collection`

- [ ] **Step 1: Write failing normalizer tests**

Assert case/whitespace/apostrophe equivalents share a key, aliases matching display primary/original are filtered, and one highest-priority alias remains across types.

- [ ] **Step 2: Verify RED**

Run: `php artisan test --filter=CatalogTitleAliasNormalizerTest`

Expected: FAIL because the service does not exist.

- [ ] **Step 3: Implement the alias normalizer and importer filtering**

Use `CatalogTitleDisplayName::from()` for semantic primary/original exclusion. Build alias rows keyed only by `name_hash`, choose type priority `original > alternative > source-title`, and upsert with conflict columns `catalog_title_id,name_hash`.

- [ ] **Step 4: Write and verify failing importer/page/SEO tests**

Prepare a Pandora-like title `Пандора (2019)/Pandora` with original `Pandora` and aliases of all three types. Assert database aliases exclude both display names, title HTML contains no repeated alternate name, and JSON-LD `alternateName` is unique.

Run: `php artisan test --filter=CatalogTitleAliasDeduplicationTest`

Expected: FAIL before importer/page/SEO filtering.

- [ ] **Step 5: Generate and implement ordered migrations**

Run:

```bash
php artisan make:migration deduplicate_catalog_title_alias_names
php artisan make:migration enforce_unique_catalog_title_alias_names
```

The first migration keeps one row per `(catalog_title_id,name_hash)` by the priority order and deletes aliases equal to the displayed primary/original comparison keys. The second drops the old unique index and adds a unique index on `(catalog_title_id,name_hash)`. Its `down()` restores the old index.

- [ ] **Step 6: Verify alias GREEN and migration enforcement**

Run:

```bash
php artisan test --filter=CatalogTitleAliasDeduplicationTest
php artisan test --filter=CatalogPageTest
```

Expected: PASS, and inserting a second alias with the same title/hash but another type raises a query exception in the dedicated constraint test.

- [ ] **Step 7: Commit Task 3**

```bash
git add app/Services/Catalog/CatalogTitleAliasNormalizer.php app/Services/Seasonvar/SeasonvarCatalogParser.php app/Services/Seasonvar/SeasonvarCatalogImporter.php app/Services/Catalog/CatalogTitlePageBuilder.php app/Services/Catalog/CatalogSeoBuilder.php database/migrations tests
git commit -m "fix: enforce unique catalog title aliases"
```

### Task 4: Bounded catalog metadata deduplicator

**Files:**
- Create: `app/Services/Catalog/CatalogMetadataDeduplicator.php`
- Create: `tests/Feature/CatalogMetadataDeduplicatorTest.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`

**Interfaces:**
- Produces: `CatalogMetadataDeduplicator::run(?callable $progress = null): array`
- Return counters: `checked`, `records_merged`, `invalid_records_removed`, `links_moved`, `duplicate_links_removed`, `affected_titles`, and `types`.

- [ ] **Step 1: Write failing merge/idempotency tests**

Create Latin/Cyrillic actor duplicates, overlapping and disjoint title pivots, an invalid serial-title actor, and a valid genre. Run the desired service. Assert the oldest actor survives with preferred Cyrillic name, every distinct title link points to it, duplicate pivots collapse, invalid rows disappear, the valid genre remains, and a second run reports zero changes.

- [ ] **Step 2: Verify RED**

Run: `php artisan test --filter=CatalogMetadataDeduplicatorTest`

Expected: FAIL because the service does not exist.

- [ ] **Step 3: Implement bounded registry-driven cleanup**

Iterate `CatalogTaxonomyRegistry::relations()`. Obtain pivot table/key metadata from the model relationship, scan lookup records with `chunkById()` into a temporary SQLite identity table, and process duplicate groups in configurable chunks without a catalog-sized PHP map. Within each transaction insert canonical pivot rows with `insertOrIgnore`, delete duplicate pivots, delete duplicate records, and update the canonical slug/name/source URL. Collect affected title IDs only long enough to synchronize search documents after commit.

- [ ] **Step 4: Replace pipeline-local cleanup**

Inject the new service into `SeasonvarImportPipeline`, remove duplicated hardcoded table metadata, and retain the existing early/late/full/queued call positions and progress summary counters. Keep recommendation rebuild after cleanup.

- [ ] **Step 5: Verify GREEN and pipeline integration**

Run:

```bash
php artisan test --filter=CatalogMetadataDeduplicatorTest
php artisan test --filter=SeasonvarImportMaintenanceTest
```

Expected: PASS.

- [ ] **Step 6: Commit Task 4**

```bash
git add app/Services/Catalog/CatalogMetadataDeduplicator.php app/Services/Seasonvar/SeasonvarImportPipeline.php tests/Feature/CatalogMetadataDeduplicatorTest.php tests/Feature/SeasonvarImportMaintenanceTest.php
git commit -m "feat: merge duplicate catalog metadata safely"
```

### Task 5: Documentation and full verification

**Files:**
- Modify: `docs/importer.md`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/performance.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify generated blocks only through: `php artisan project:docs-refresh`

**Interfaces:**
- Documents the canonical slug contract, alias constraint, bounded cleanup, preferred labels, and production rollout.

- [ ] **Step 1: Update thematic documentation**

Describe exact-key behavior and its limitation: fuzzy actor matching is not automatic, and a future true provider person ID requires an explicit source-identity design.

- [ ] **Step 2: Format PHP and verify focused suites**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=CatalogRelationNameSanitizerTest
php artisan test --filter=SeasonvarCatalogParserTest
php artisan test --filter=SeasonvarCatalogRelationSyncerTest
php artisan test --filter=CatalogTitleAliasDeduplicationTest
php artisan test --filter=CatalogMetadataDeduplicatorTest
php artisan test --filter=SeasonvarImportMaintenanceTest
```

Expected: all commands exit 0.

- [ ] **Step 3: Run broad verification**

Run:

```bash
php artisan test
npm run build
php artisan project:docs-refresh --check
git diff --check
```

Expected: all commands exit 0 and the working tree contains only intended changes.

- [ ] **Step 4: Commit Task 5**

```bash
git add docs app tests database/migrations
git commit -m "docs: document canonical catalog metadata"
```

### Task 6: Production cleanup and live verification

**Files:**
- Create outside Git: timestamped SQLite backup beside the database or in the configured backup directory.
- Create under `output/playwright/`: desktop/mobile QA artifacts.

**Interfaces:**
- Consumes: verified committed implementation and existing `seasonvar-import-forever.service`.
- Produces: cleaned production database with service restored to active state.

- [ ] **Step 1: Reconfirm branch, service, lock, disk space, and baseline counts**

Run read-only status, `systemctl status`, `df -h`, and SQL counts for aliases, canonical actor groups, invalid actor/director rows, and Pandora aliases.

- [ ] **Step 2: Gracefully stop the sequential importer**

Run `systemctl stop seasonvar-import-forever.service`, wait for inactive state, and confirm no `artisan seasonvar:import --forever` process remains. Do not force-kill unless the documented graceful timeout is exceeded and the run state has been inspected.

- [ ] **Step 3: Create and validate an online SQLite backup**

Use SQLite's backup command, then run `PRAGMA quick_check` against the backup. Keep the backup outside Git and do not print secrets.

- [ ] **Step 4: Apply migrations and invoke cleanup**

Run `php artisan migrate --force`, then invoke `CatalogMetadataDeduplicator`, search synchronization, recommendation rebuild, and cache invalidation through a bootstrapped Artisan/Tinker process. Capture only bounded summary counters.

- [ ] **Step 5: Restart and verify the importer**

Run `systemctl start seasonvar-import-forever.service`; verify active PID, logs, and a non-failed import status.

- [ ] **Step 6: Verify database postconditions**

Expected:

- zero alias duplicate groups across types;
- zero aliases matching displayed primary/original names;
- zero canonical actor duplicate groups;
- zero invalid actor/director serial-title rows;
- Pandora exposes no repeated `Pandora` under `Другие названия`;
- relation pivots retain all distinct catalog-title links.

- [ ] **Step 7: Verify live UI with browser fallback**

The Playwright CLI currently lacks `/opt/google/chrome/chrome`, so use the managed Chromium fallback described by the project skill. Capture `1440x1200` and `390x844`, HTTP status, H1, console/page errors, failed local assets, horizontal overflow, and screenshots for the Pandora page and `/actors`.

- [ ] **Step 8: Final repository verification and commit**

Run `git status --short --branch`, confirm `main`, ensure no unrelated dirty files remain, and commit only intended final documentation/artifacts if versioned by project convention.
