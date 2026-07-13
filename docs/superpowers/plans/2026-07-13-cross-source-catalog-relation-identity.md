# Cross-Source Catalog Relation Identity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Закрепить actor/director/genre/country/age rating/translation/status/network/studio/tag за стабильной identity каждого источника, чтобы повторный import или refresh не создавал новую строку после изменения подписи.

**Architecture:** Существующие десять lookup-таблиц и явные pivot остаются источником каталожных связей. Новая неполиморфная таблица хранит hash provider key и canonical slug; общий registry атомарно закрепляет решение, `CatalogRelationSyncer` и `SeasonvarTaxonomyPageImporter` используют его внутри catalog transaction, а maintenance переносит identity при legacy merge.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent/Query Builder, SQLite, PHPUnit 12.5.

## Global Constraints

- Единственная публичная команда Seasonvar остаётся `php artisan seasonvar:import`.
- Не добавлять production dependency и не редактировать `.env`.
- Не вводить morph/polymorphic catalog metadata; десять справочников и pivot остаются явными.
- В реестре не хранить raw external ID, URL, credentials или provider payload — только SHA-256.
- Catalog multi-table writes выполняются в короткой transaction; повторный import остаётся additive через `syncWithoutDetaching`.
- Работать только в существующей ветке `main`, сохранять параллельные изменения и коммитить только перечисленные файлы.
- Production migration/maintenance разрешены только после завершения активных import jobs и резервной копии SQLite.

---

### Task 1: Provider identity registry

**Files:**
- Create: `database/migrations/2026_07_13_210000_create_catalog_relation_source_identities_table.php`
- Create: `app/Models/CatalogRelationSourceIdentity.php`
- Create: `app/Services/Catalog/CatalogRelationSourceIdentityRegistry.php`
- Modify: `app/Models/Source.php`
- Create: `tests/Feature/CatalogRelationSourceIdentityTest.php`

**Interfaces:**
- Consumes: `CatalogTaxonomyRegistry::supports(string): bool` and existing `sources.id`.
- Produces: `CatalogRelationSourceIdentityRegistry::resolve(int $sourceId, string $type, string|int|null $sourceExternalId, ?string $sourceUrl, string $fallbackCanonicalKey): string`.
- Produces: `sourceKeyHash(string|int|null $sourceExternalId, ?string $sourceUrl): ?string`, `rebind(string $type, array $previousCanonicalKeys, string $canonicalKey): int`, and `pruneMissing(string $type, string $relationTable): int`.

- [ ] **Step 1: Write failing registry tests**

Add a `RefreshDatabase` PHPUnit class that proves the first provider key is immutable, raw keys are absent, unsupported types do not create rows, and URL fragments/host case produce one hash:

```php
public function test_provider_identity_keeps_the_first_canonical_key(): void
{
    $source = Source::factory()->create();
    $registry = app(CatalogRelationSourceIdentityRegistry::class);

    $first = $registry->resolve($source->id, 'actor', 'person-42', null, 'john-smith');
    $second = $registry->resolve($source->id, 'actor', 'person-42', null, 'johnathan-smith');

    $this->assertSame('john-smith', $first);
    $this->assertSame($first, $second);
    $this->assertDatabaseCount('catalog_relation_source_identities', 1);
    $this->assertSame(64, strlen(CatalogRelationSourceIdentity::query()->sole()->source_key_hash));
    $this->assertStringNotContainsString('person-42', json_encode(CatalogRelationSourceIdentity::query()->sole()->toArray(), JSON_THROW_ON_ERROR));
}
```

- [ ] **Step 2: Run the test and confirm RED**

Run: `php artisan test tests/Feature/CatalogRelationSourceIdentityTest.php`

Expected: FAIL because the migration, model, and registry do not exist.

- [ ] **Step 3: Add the additive table and model**

Create a migration with this schema and an exactly reversible `down()`:

```php
Schema::create('catalog_relation_source_identities', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('source_id')->constrained()->cascadeOnDelete();
    $table->string('relation_type', 32);
    $table->string('source_key_hash', 64);
    $table->string('canonical_key');
    $table->timestamps();
    $table->unique(
        ['source_id', 'relation_type', 'source_key_hash'],
        'catalog_relation_source_identity_unique',
    );
    $table->index(
        ['relation_type', 'canonical_key'],
        'catalog_relation_source_identity_canonical_idx',
    );
});
```

The model exposes only `source_id`, `relation_type`, `source_key_hash`, and `canonical_key` as fillable, plus `belongsTo(Source::class)`. Add `Source::catalogRelationSourceIdentities(): HasMany`.

- [ ] **Step 4: Implement the registry**

Normalize an external ID with NFKC + trim, reject empty/control/over-255 values, and hash `external-id\0{$value}`. If no external ID exists, normalize a valid HTTPS URL by lowercasing scheme/host, removing fragment and default port, then hash `url\0{$url}`. `resolve()` must:

```php
if (! $this->taxonomies->supports($type) || $sourceId < 1 || $canonicalKey === '' || $hash === null) {
    return $canonicalKey;
}

DB::table('catalog_relation_source_identities')->insertOrIgnore([
    'source_id' => $sourceId,
    'relation_type' => $type,
    'source_key_hash' => $hash,
    'canonical_key' => $canonicalKey,
    'created_at' => now(),
    'updated_at' => now(),
]);

return (string) DB::table('catalog_relation_source_identities')
    ->where(compact('source_id', 'relation_type', 'source_key_hash'))
    ->value('canonical_key');
```

Use explicit query conditions rather than the illustrative snake-case `compact()` names. `rebind()` performs one bounded update for unique non-empty previous keys. `pruneMissing()` deletes rows of one type whose canonical key has no matching `slug` in the registered lookup table.

- [ ] **Step 5: Run registry tests and format the exact files**

Run:

```bash
php artisan test tests/Feature/CatalogRelationSourceIdentityTest.php
./vendor/bin/pint --format agent app/Models/CatalogRelationSourceIdentity.php app/Models/Source.php app/Services/Catalog/CatalogRelationSourceIdentityRegistry.php database/migrations/2026_07_13_210000_create_catalog_relation_source_identities_table.php tests/Feature/CatalogRelationSourceIdentityTest.php
```

Expected: PASS and Pint exit code 0.

- [ ] **Step 6: Commit the registry**

Stage only the five Task 1 paths and commit `feat: add catalog relation source identity registry`.

### Task 2: Universal relation sync boundary

**Files:**
- Modify: `app/Services/Catalog/CatalogRelationSyncer.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogRelationSyncer.php`
- Modify: `tests/Feature/CatalogRelationSourceIdentityTest.php`
- Modify: `tests/Feature/SeasonvarCatalogMetadataBackfillTest.php`

**Interfaces:**
- Consumes: Task 1 `resolve()` and `sourceKeyHash()`.
- Produces: `CatalogRelationSyncer::sync()` accepting optional `source_id` and `source_external_id` per observation while preserving the existing result shape.

- [ ] **Step 1: Write failing sync tests**

Add one data-provider test covering valid before/after names for all ten types, one cross-source Cyrillic/Latin test, one normalized URL fallback test, and one rollback test. The core assertion for every type is:

```php
$syncer->sync($title, [[
    'type' => $type,
    'name' => $firstName,
    'source_external_id' => 'stable-'.$type,
]]);
$syncer->sync($title, [[
    'type' => $type,
    'name' => $renamedValue,
    'source_external_id' => 'stable-'.$type,
]]);

$modelClass = app(CatalogTaxonomyRegistry::class)->modelClass($type);
$this->assertSame(1, $modelClass::query()->count());
$this->assertSame(
    app(CatalogRelationNameSanitizer::class)->canonicalKey($type, $firstName),
    $modelClass::query()->sole()->slug,
);
```

For rollback, force-delete a `CatalogTitle`, call `sync()` on the stale instance, catch `QueryException`, then assert both the lookup row and identity row are absent.

- [ ] **Step 2: Run focused tests and confirm RED**

Run: `php artisan test tests/Feature/CatalogRelationSourceIdentityTest.php tests/Feature/SeasonvarCatalogMetadataBackfillTest.php`

Expected: rename and rollback cases fail because sync does not use the registry or own a transaction.

- [ ] **Step 3: Integrate identity before bulk upsert**

Wrap the body of `CatalogRelationSyncer::sync()` in `DB::transaction()`. Extend the observation shape to:

```php
array{
    type: string,
    name: string,
    source_id?: int|null,
    source_external_id?: string|int|null,
    source_url?: string|null
}
```

Within each type, normalize safe source URLs first, fetch existing rows by `source_url` in one query, and choose candidate key in this order:

1. slug of an existing exact normalized provenance URL;
2. canonical key from the incoming name.

Then call registry `resolve()` with observation `source_id` or `$title->source_id`. Group and upsert by the returned key. When an identity points at an existing row, keep `preferredName()` semantics so a non-equivalent later label cannot rename the row.

- [ ] **Step 4: Preserve Seasonvar adapter validation**

Keep `SeasonvarCatalogRelationSyncer` responsible only for canonicalizing/allowlisting `seasonvar.ru` URLs, preserving optional external/source identity fields when it delegates. Do not duplicate registry logic in the adapter.

- [ ] **Step 5: Run focused sync tests**

Run:

```bash
php artisan test tests/Feature/CatalogRelationSourceIdentityTest.php tests/Feature/SeasonvarCatalogMetadataBackfillTest.php
./vendor/bin/pint --format agent app/Services/Catalog/CatalogRelationSyncer.php app/Services/Seasonvar/SeasonvarCatalogRelationSyncer.php tests/Feature/CatalogRelationSourceIdentityTest.php tests/Feature/SeasonvarCatalogMetadataBackfillTest.php
```

Expected: all focused tests PASS; existing Seasonvar URL and Cyrillic-preference tests remain green.

- [ ] **Step 6: Commit sync integration**

Stage only Task 2 paths and commit `fix: stabilize relation identity across source refreshes`.

### Task 3: Taxonomy metadata pages use the same identity

**Files:**
- Modify: `app/Services/Seasonvar/SeasonvarTaxonomyPageImporter.php`
- Modify: `tests/Feature/SeasonvarParsePageCommandTest.php`

**Interfaces:**
- Consumes: Task 1 registry and `SourcePage::source_id`.
- Produces: identical identity resolution for direct taxonomy pages and serial-page relation observations.

- [ ] **Step 1: Add the failing metadata-page assertion**

Extend `test_metadata_taxonomy_pages_are_idempotent_and_queue_only_bounded_valid_serial_links` to assert one identity row with the page source, `actor`, and the actor canonical key. Add a second forced response for the same canonical URL with a non-equivalent valid display name and assert the original actor row is reused.

- [ ] **Step 2: Run the exact test and confirm RED**

Run: `php artisan test --filter=test_metadata_taxonomy_pages_are_idempotent_and_queue_only_bounded_valid_serial_links`

Expected: FAIL because taxonomy-page importer has not created a source identity.

- [ ] **Step 3: Resolve and persist the mapped canonical key**

Inject `CatalogRelationSourceIdentityRegistry`. In the existing retry-aware transaction:

```php
$fallbackKey = $this->relationNames->canonicalKey($type, $data->displayName);
$bySourceUrl = $modelClass::query()->where('source_url', $data->canonicalSourceUrl)->first();
$canonicalKey = $this->identities->resolve(
    $page->source_id,
    $type,
    null,
    $data->canonicalSourceUrl,
    (string) ($bySourceUrl?->slug ?: $fallbackKey),
);
$taxonomy = $modelClass::query()->where('slug', $canonicalKey)->first()
    ?? $bySourceUrl
    ?? new $modelClass;
```

Save the returned `$canonicalKey`; do not recompute slug from a later non-equivalent label.

- [ ] **Step 4: Run and format**

Run:

```bash
php artisan test --filter=test_metadata_taxonomy_pages_are_idempotent_and_queue_only_bounded_valid_serial_links
php artisan test tests/Feature/SeasonvarParsePageCommandTest.php
./vendor/bin/pint --format agent app/Services/Seasonvar/SeasonvarTaxonomyPageImporter.php tests/Feature/SeasonvarParsePageCommandTest.php
```

Expected: both commands PASS.

- [ ] **Step 5: Commit taxonomy integration**

Stage the two Task 3 paths and commit `fix: share taxonomy page source identities`.

### Task 4: Identity-safe legacy maintenance

**Files:**
- Modify: `app/Services/Catalog/CatalogMetadataDeduplicator.php`
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`

**Interfaces:**
- Consumes: Task 1 `rebind()` and `pruneMissing()`.
- Produces: every surviving identity points to an existing canonical lookup slug after maintenance.

- [ ] **Step 1: Write the failing merge regression**

In the existing canonical actor merge test, insert two identity rows pointing to the two legacy actor slugs. After `run()`, assert both rows use `atsuko-tanaka`; after the second run, assert counts and canonical keys are unchanged. Add an identity pointing to a deleted invalid actor and assert it is pruned.

- [ ] **Step 2: Run the exact maintenance tests and confirm RED**

Run: `php artisan test --filter='test_it_merges_canonical_catalog_relations_and_preserves_title_links|test_it_canonicalizes_relation_slugs_without_transient_unique_collisions'`

Expected: FAIL because identity keys still point to legacy/deleted slugs.

- [ ] **Step 3: Carry current slugs through the bounded identity map**

Select `slug` while filling the temporary identity table and add a `current_key TEXT NOT NULL` column. For each duplicate group, call:

```php
$this->sourceIdentities->rebind(
    $type,
    $identities->pluck('current_key')->map(fn (mixed $key): string => (string) $key)->all(),
    (string) $group->canonical_key,
);
```

Before a single surviving row changes from staged/current slug to computed canonical slug, rebind that old key. After each type is complete, call `pruneMissing($type, $modelTable)`. Keep all operations bounded and inside the existing maintenance transaction points.

- [ ] **Step 4: Run maintenance and importer regression tests**

Run:

```bash
php artisan test tests/Feature/SeasonvarImportMaintenanceTest.php
php artisan test tests/Feature/CatalogRelationSourceIdentityTest.php tests/Feature/SeasonvarCatalogMetadataBackfillTest.php tests/Feature/SeasonvarParsePageCommandTest.php
./vendor/bin/pint --format agent app/Services/Catalog/CatalogMetadataDeduplicator.php tests/Feature/SeasonvarImportMaintenanceTest.php
```

Expected: all tests PASS and repeat maintenance reports no catalog mutations.

- [ ] **Step 5: Commit maintenance integration**

Stage the two Task 4 paths and commit `fix: preserve source identities during catalog deduplication`.

### Task 5: Documentation, migration verification, and safe deployment gate

**Files:**
- Modify: `docs/importer.md`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/performance.md`
- Modify: `CHANGELOG.md`
- Delete after completion: `docs/superpowers/specs/2026-07-13-catalog-relation-source-identity-design.md`
- Delete after completion: `docs/superpowers/plans/2026-07-13-cross-source-catalog-relation-identity.md`

**Interfaces:**
- Consumes: all completed behavior.
- Produces: deployable documented migration and evidence-backed verification.

- [ ] **Step 1: Update the source identity contract**

Document the new table, hash-only storage, adapter fields, transaction requirement, lazy transition for existing provenance URLs, merge rebind, and the rule that no fuzzy person matching is performed. Update the contradictory statement that metadata provenance has no separate table.

- [ ] **Step 2: Verify migrations from empty SQLite**

Create a temporary SQLite path and run:

```bash
DB_CONNECTION=sqlite DB_DATABASE=/tmp/seasonvar-relation-identity-test.sqlite php artisan migrate --force
```

Expected: every migration succeeds and the new table has both named indexes. Remove only this known temporary file afterward.

- [ ] **Step 3: Run broad verification**

Run:

```bash
./vendor/bin/pint --format agent \
  app/Models/CatalogRelationSourceIdentity.php \
  app/Models/Source.php \
  app/Services/Catalog/CatalogRelationSourceIdentityRegistry.php \
  app/Services/Catalog/CatalogRelationSyncer.php \
  app/Services/Catalog/CatalogMetadataDeduplicator.php \
  app/Services/Seasonvar/SeasonvarCatalogRelationSyncer.php \
  app/Services/Seasonvar/SeasonvarTaxonomyPageImporter.php \
  database/migrations/2026_07_13_210000_create_catalog_relation_source_identities_table.php \
  tests/Feature/CatalogRelationSourceIdentityTest.php \
  tests/Feature/SeasonvarCatalogMetadataBackfillTest.php \
  tests/Feature/SeasonvarParsePageCommandTest.php \
  tests/Feature/SeasonvarImportMaintenanceTest.php
php artisan test tests/Feature/CatalogRelationSourceIdentityTest.php tests/Feature/SeasonvarCatalogMetadataBackfillTest.php tests/Feature/SeasonvarParsePageCommandTest.php tests/Feature/SeasonvarImportMaintenanceTest.php
php artisan test
git diff --check -- \
  app/Models/CatalogRelationSourceIdentity.php \
  app/Models/Source.php \
  app/Services/Catalog/CatalogRelationSourceIdentityRegistry.php \
  app/Services/Catalog/CatalogRelationSyncer.php \
  app/Services/Catalog/CatalogMetadataDeduplicator.php \
  app/Services/Seasonvar/SeasonvarCatalogRelationSyncer.php \
  app/Services/Seasonvar/SeasonvarTaxonomyPageImporter.php \
  database/migrations/2026_07_13_210000_create_catalog_relation_source_identities_table.php \
  tests/Feature/CatalogRelationSourceIdentityTest.php \
  tests/Feature/SeasonvarCatalogMetadataBackfillTest.php \
  tests/Feature/SeasonvarParsePageCommandTest.php \
  tests/Feature/SeasonvarImportMaintenanceTest.php \
  docs/importer.md docs/DATA_RELATIONS.md docs/performance.md CHANGELOG.md
```

Expected: focused suite PASS. Record any pre-existing unrelated full-suite failures with exact test names and output; do not modify unrelated files.

- [ ] **Step 4: Remove completed temporary design/plan docs and commit implementation documentation**

Stage only the four thematic docs, changelog, and the two deletions. Commit `docs: document cross-source catalog identities`.

- [ ] **Step 5: Check the production safety gate**

Run read-only checks:

```bash
php artisan seasonvar:import --status
git status --short --branch
sqlite3 database/database.sqlite 'PRAGMA quick_check;'
```

If queued/running import jobs or claims remain, do not migrate production. Report the gate as pending. If imports are idle, create a timestamped SQLite backup, verify the backup with `PRAGMA quick_check`, run `php artisan migrate --force`, restart long-lived queue workers through the existing deployment contract, and run one idempotent relation-maintenance cycle through the existing importer lifecycle.

- [ ] **Step 6: Final repository check**

Confirm branch is `main`, all task changes are committed, and every unrelated dirty/untracked path is listed as an external blocker rather than staged, reverted, or deleted.
