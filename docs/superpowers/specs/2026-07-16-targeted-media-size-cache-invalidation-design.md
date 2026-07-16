# Targeted media-size cache invalidation design

Date: 2026-07-16

## Evidence and problem

`LicensedMediaFileSizeMetadataWriter` currently calls `CatalogCacheInvalidator::importedTitleChanged()` after every material file-size metadata write. That importer-wide path correctly invalidates the affected title, but it also asks `CatalogCollectionCacheInvalidator` to find public collection memberships and may bump collection, homepage, sitemap, recommendation and API generations. When warming is enabled, it also records a general critical-cache warm intent.

File-size metadata is read by `CatalogTitlePlayer` through the title playback query and is not rendered by collection cards, homepage cards, sitemap entries, recommendation results or the current public API resources. Therefore the importer-wide fan-out is broader than the data dependency.

A read-only production audit on 2026-07-16 found 18,597 checked media rows across 426 catalogue titles. None of those titles had an approved public collection membership. The current path has consequently performed one collection-membership lookup per material media-size write without finding a dependent public collection. This is an observed workload count, not a p95 or latency claim.

## Considered approaches

1. Keep `importedTitleChanged()`. This preserves correct title invalidation but retains unrelated collection queries, domain generation bumps and general warming work.
2. Inject `CacheVersionRegistry` directly into `LicensedMediaFileSizeMetadataWriter`. This would make the writer smaller at runtime, but it would create a second catalogue-cache mutation boundary and bypass existing telemetry/transaction conventions.
3. Add a focused method to the existing `CatalogCacheInvalidator` and call it from the media-size writer. This is selected because it preserves one cache mutation owner while expressing the exact dependency.

## Design

`CatalogCacheInvalidator::titlePlaybackMetadataChanged(int $titleId): void` will:

- ignore invalid IDs;
- defer the targeted invalidation with `DB::afterCommit()` when a caller is inside a transaction;
- bump only `CacheDomain::TitleDetail` scope `title:{id}`;
- emit a low-cardinality `playback-metadata-invalidation` telemetry increment;
- not query collection membership;
- not bump homepage, collections, sitemap, recommendation, API, facets, stats or catalog-page generations;
- not enqueue a general cache-warming job.

`LicensedMediaFileSizeMetadataWriter` remains the only persistence boundary for importer inspection and download-time correction. It will call the focused invalidator only after a successful conditional update whose material attributes changed. Unchanged writes, stale-source results and invalid title IDs retain their existing behavior.

The guest title page already includes the global and scoped `TitleDetail` versions in its cache context. Advancing `title:{id}` therefore makes every cached title/player variant for that title miss on its next request without invalidating unrelated titles or catalogue surfaces. Authenticated pages already bypass the shared public response cache.

## Data flow

```text
bounded inspector or authorized download response headers
    -> conditional file-size metadata update
    -> material-change comparison
    -> CatalogCacheInvalidator::titlePlaybackMetadataChanged(title ID)
    -> targeted TitleDetail title:{id} generation bump
    -> next title/player request reads current database metadata
```

## Failure and compatibility behavior

No schema, route, authorization, streaming, Range, importer counter, freshness or UI contract changes. Existing media without size metadata continue playing. A cache-version failure keeps the same existing error boundary: the database update is not rolled back, importer inspection remains non-fatal, and download-time repair remains best-effort.

No automatic warm is required for correctness. The next guest title request rebuilds only the invalidated title response through the existing bounded cache path; authenticated delivery stays uncached and private. General importer mutations continue using `importedTitleChanged()` and keep collection propagation and warming behavior.

## Documentation and verification

Update `README.md`, `CHANGELOG.md`, `docs/architecture.md`, `docs/caching.md` and `docs/performance.md` with the exact targeted boundary and measured audit. No public text, translation, configuration, migration or dependency changes are required.

Automated tests will not be created or executed under the user constraint. Non-test verification will use PHP lint, path-targeted Pint, focused Larastan, task-only `git diff --check`, dependency/fan-out searches, importer status, protected download route inspection, migration status and the frontend production build required by the original acceptance gate.
