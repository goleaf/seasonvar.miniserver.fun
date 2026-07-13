# Canonical Catalog Metadata Deduplication Design

## Context

The production catalog currently exposes three independent duplication defects:

- `catalog_title_aliases` treats `(catalog_title_id, type, name_hash)` as unique, so the same visible name may be stored once as `original`, once as `alternative`, and once as `source-title`. The Pandora page demonstrates this with two visible `Pandora` values.
- Actor and director discovery classifies every link whose full URL contains `actor`, `akter`, `director`, or `rezhisser`. Serial slugs containing those words are therefore imported as people. The first `/actors` page currently includes complete serial titles as actor names.
- Person identity compares raw provider URLs. Encoded and decoded forms such as `/actor/Adam Ian Cohen` and `/actor/Adam%20Ian%20Cohen` become different records. Cyrillic and Latin spellings that produce the same transliterated slug are also deliberately split when their raw URLs differ.

The read-only production audit on 13 July 2026 found:

- 26,321 alias duplicate groups containing 26,346 extra rows across alias types;
- 86,566 alias rows that repeat the title's displayed primary or original name;
- 9,675 actor canonical-slug duplicate groups containing 9,774 extra actor rows;
- 38 exact Cyrillic/Latin transliteration groups, all visually consistent person-name pairs in the inspected sample;
- at least seven polluted actor rows and one polluted director row containing serial/release text.

The sequential `seasonvar:import --forever` service is active. Production cleanup must therefore use the existing importer lock boundary and a SQLite backup; it must not race the running process.

## Chosen approach

Keep the ten explicit lookup tables and pivot tables. Do not add a polymorphic metadata model. A shared catalog identity service will make the existing unique `slug` column the canonical cross-source name key:

1. Normalize Unicode, HTML entities, whitespace, apostrophes, case, and transliteration through one service.
2. Resolve equivalent Latin/Cyrillic names to one deterministic slug.
3. Prefer a Cyrillic label when an equivalent Cyrillic and Latin label are merged because the public interface is Russian; otherwise preserve the oldest normalized label.
4. Treat a normalized provider URL as supporting identity evidence, never as a reason to split two names with the same canonical key.

This preserves the current schema and gives future importer adapters a reusable identity boundary. A future source with a true provider person ID may add an explicit source-identity table as a separate design; guessed fuzzy matching and a polymorphic table are outside this change.

## Parser boundary

`SeasonvarCatalogParser` will classify relation links from the decoded URL path only. Actor links must start with `/actor/` or `/akter/` (including the established hyphen variant); director links must start with `/director/` or `/rezhisser/`. A serial path that merely contains `actor` or `akter` is not a person link.

`CatalogRelationNameSanitizer` will reject person values that contain no letters or contain clear serial/release markers such as a leading `>>>`, a dated release announcement, `серия из`, `сериал полностью`, or a serial-title prefix. The same validator is used by parsing, importing, and maintenance so rejected data cannot be reintroduced through another Seasonvar path.

## Import identity

`SeasonvarCatalogRelationSyncer` will:

- canonicalize an allowlisted Seasonvar relation URL through `SeasonvarUrl::normalize()` before identity lookup or storage;
- deduplicate an incoming type batch by the shared canonical name key;
- look up and upsert the existing canonical slug rather than adding a provider-URL hash suffix for an equivalent name;
- preserve the preferred Russian display label when a later source supplies the equivalent Latin spelling;
- keep `syncWithoutDetaching()` so a partial provider snapshot cannot remove local relations.

The database's existing unique `slug` index remains the concurrency backstop for every lookup table. A generic `CatalogRelationSyncer` owns cross-source normalization/upsert semantics; the Seasonvar syncer and metadata-page importer are provider adapters that validate their URLs before reusing that boundary.

## Existing-data maintenance

A focused `CatalogMetadataDeduplicator` service will use `CatalogTaxonomyRegistry` as the authority for all ten model/relation pairs. For each lookup table it scans by ID in bounded chunks into a temporary SQLite identity table, groups records by canonical slug without retaining the whole directory in PHP memory, and processes duplicate IDs in bounded transactions.

For each group the oldest row remains canonical. The service may update its label to the preferred normalized label and its slug to the canonical key. Duplicate pivot rows are inserted into the canonical ID with `insertOrIgnore`, existing duplicate pivots are removed, then duplicate lookup rows are deleted. Invalid records and their pivots are removed through the same service. Primary-key pivot constraints make the operation idempotent.

The service reports checked records, merged records, removed invalid records, moved links, duplicate links collapsed, and affected title counts per type. The synchronous full-import cycle and queued finalizer both call it before recommendation rebuild. This makes future cleanup automatic without adding another public importer command.

## Alias identity

Alias equality is independent of alias type. A shared alias normalizer will generate the existing SHA-256 `name_hash` from a normalized comparison key. The parser/importer will retain the highest-priority observation for a name (`original`, then `alternative`, then `source-title`) and upsert on `(catalog_title_id, name_hash)`.

Aliases equal to `CatalogTitleDisplayName::primary` or `CatalogTitleDisplayName::original` are not stored because those fields already supply search and display data. The title page and SEO builder will also apply the same collection filter as defense in depth during rolling deployment.

Two ordered migrations implement the database constraint:

1. an idempotent data migration removes lower-priority duplicate alias rows and aliases equal to the displayed title/original values;
2. a schema migration replaces the old three-column unique index with `UNIQUE (catalog_title_id, name_hash)`.

The schema migration's `down()` restores the previous index. Deleted duplicate data is intentionally not recreated; a forward correction is required if the cleanup rule ever changes.

## Consistency and operations

Relation merging and alias cleanup run in bounded SQLite transactions without external HTTP calls. Search documents for affected titles are synchronized after committed cleanup. Full importer/finalizer flow already rebuilds recommendations after relation maintenance and invalidates catalog caches at its normal boundary.

For the requested one-time production cleanup:

1. finish code verification and commit on `main`;
2. gracefully stop the sequential importer through systemd so its signal handler closes the active run;
3. create an online SQLite backup outside Git;
4. apply migrations;
5. invoke the internal deduplicator, synchronize affected search documents, rebuild recommendations, and invalidate catalog caches;
6. restart the same service and verify it is active;
7. compare database duplicate counts and the live Pandora and `/actors` pages.

If the service cannot be stopped cleanly, production mutation is deferred rather than bypassing the importer lock.

## Testing

Tests will prove the behavior in red-green order:

- parser ignores serial URLs containing actor/director tokens and accepts anchored person URLs;
- person validation rejects polluted release/title strings while keeping legitimate stage names;
- relation sync merges encoded/unencoded URLs and Cyrillic/Latin equivalent names into one row and one pivot;
- deduplicator moves all links, collapses duplicate pivots, removes invalid values, and is idempotent across registered relation types;
- alias import stores one row across types and skips primary/original display names;
- title page and JSON-LD expose normalized unique alternate names;
- migrations apply from scratch and enforce the new alias uniqueness rule.

Focused PHPUnit tests run first, followed by Pint, the full test suite, migration checks, and production read-only SQL/browser verification.
