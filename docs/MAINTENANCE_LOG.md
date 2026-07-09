# Maintenance Log

## 2026-07-09

- Fixed chunked discovered URL `upsert` payload handling and kept sitemap URL storage memory-bounded.
- Added unchanged-page fast path to skip HTML parsing and catalog writes when parsed content hash is unchanged.
- Optimized Seasonvar catalog-title upsert with exact `source_url_hash` lookup before title-based duplicate detection.
- Optimized discovered Seasonvar URL storage with chunked batch `upsert` instead of per-URL `firstOrNew()`.
- Optimized Seasonvar importer seasons and episodes sync with batch `upsert` operations.
- Wrapped Seasonvar importer catalog writes in a transaction and split concrete relation syncing into a typed batch helper.
- Extracted catalog poster rendering into shared `x-title-poster` and reused it on home, card, and show pages.
- Extracted responsive home-page title rows with poster thumbnails into a shared `x-title-list-row` component.
- Improved responsive catalog layouts and added poster thumbnails beside title rows on the home page.
- Optimized Seasonvar importer relation syncing for concrete catalog relation tables with grouped upserts and one pivot sync per relation.
- Added concrete catalog relation tables and Eloquent `belongsToMany` relations for genres, countries, actors, directors, age ratings, translations, statuses, networks, studios, and tags without morph relations.
- Added Seasonvar season-list status parsing for latest episode date, released episode count, known/unknown total count, season translation, and raw status text.
- Reduced title show query load by removing unused `source`, nested season source pages, episode source pages, and recommendation eager-loads.
- Added query indexes for taxonomy filters, newest lists, source-page sync queues, and title media lists.
- Reduced taxonomy sidebar context-count calculation from per-type SQL queries to one aggregated union query over the pivot relation.
- Optimized catalog filter taxonomy lookup into one batched query for active filters.
- Optimized sidebar context counts from per-item count queries to per-taxonomy-type batched `withCount()` queries.
- Optimized year context counts into one grouped query.
- Optimized title show page by removing duplicate typed taxonomy eager-loads and preparing taxonomy groups once in the controller.
- Kept catalog UI light-only and component-based.
- Added documentation standards for code, UI, relations, parser behavior, and future maintenance updates.
