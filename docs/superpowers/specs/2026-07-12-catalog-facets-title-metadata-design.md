# Faceted Catalog, Complete Title Pages, and Metadata Backfill Design

## Goal

Turn `/titles` into a fast, usable faceted catalog where every result card opens a complete title page, users can combine multiple years and multiple values from every catalog relation, and `seasonvar:import` progressively fills metadata that the current database is missing.

The interface remains Russian, public pages never expose raw source URLs or importer internals, and no new production dependency or public import command is introduced.

## Current evidence

- The live `/titles?q=бухта` page returns 14 cards but renders 305 filter links, is roughly 413 KB of HTML, has a 12,905 px desktop sidebar, and takes about 10–11 seconds to first byte.
- On a 390 px viewport the filters begin below the results near 11,647 px and the whole page is roughly 24,669 px tall.
- Cards link only their poster and title; the card body is not clickable.
- The title page eagerly loads ten catalog relations, but duplicates them across the hero, fact table, and sidebar. It also nests a `<main>` inside the layout `<main>`, does not display aliases, ratings, or reviews, and renders empty relation headings without a useful explanation.
- The current request accepts one scalar year and one scalar slug per relation. Different relation types are ANDed, but values within one type cannot be combined.
- Current relation coverage is strong for years, genres, countries, actors, directors, and age ratings, but translations cover only about 12%, tags about 28%, and statuses/networks/studios have no links in the live database.
- Parser branches for status/network/studio exist, but unchanged parsed pages are skipped and metadata completeness is not versioned. Fixing only the parser cannot repair existing titles.

## Considered approaches

### 1. Minimal request and Blade patch

Normalize scalar query values to arrays, add checkboxes, and leave the current facet queries and importer skip rules intact.

This has the smallest diff, but it keeps the slow correlated facet counts, the oversized page, limited discoverability for high-cardinality relations, and the empty historical metadata tables. It does not satisfy the performance or backfill requirements.

### 2. Faceted catalog with versioned local backfill — selected

Keep the existing Request → PageBuilder → Query → ViewModel and importer service boundaries, but introduce grouped filter state, aggregate pivot-based facet queries, a compact responsive filter form, a relation-complete title view, shared relation synchronization, and versioned metadata backfill from stored snapshots and parsed media.

This solves the requested behavior without a new dependency, a second import command, or a redesign of the existing explicit pivot schema.

### 3. Full search platform

Add SQLite FTS, asynchronous facet autocomplete, provenance tables, and external metadata providers.

This may be valuable later, but external enrichment requires source licensing, confidence/provenance rules, and operational limits that are outside this feature. FTS and remote autocomplete should be separate follow-up projects after the faceted query path has measured baselines.

## Filter contract

The canonical interactive query shape uses repeated array parameters:

```text
/titles?year[]=2023&year[]=2024&country[]=rossiya&country[]=kanada&actor[]=ivan-ivanov
```

Existing scalar URLs such as `?year=2024`, `?country=rossiya`, `/titles/year/2024`, and `/titles/country/rossiya` remain valid. The Form Request converts each scalar to a one-element list before validation.

Rules:

- OR within one dimension: `year 2023 OR 2024`, `country Russia OR Canada`.
- AND across dimensions: selected years AND selected countries AND selected actors.
- Values are trimmed, de-duplicated, and bounded to 20 values per dimension.
- Each year is an integer from 1900 through the current year plus one.
- Every relation value uses the existing `CatalogFilterSlug` validation.
- Unknown but syntactically valid slugs produce zero results and removable error chips; they never fall back to the full catalog.
- Search, sort, pagination, title context, and filter links preserve normalized arrays.
- Multi-value combinations are `noindex,follow`; the existing single year and single taxonomy landing routes remain indexable.

Two additional, bounded availability filters are included because they are supported by existing indexed relations:

- `video=available|missing`
- `episodes=available|missing`

No arbitrary count ranges or speculative rating filters are added.

## Backend architecture

### Filter state

`CatalogTitlesRequest` exposes normalized `years()`, `filterSlugs()`, `videoAvailability()`, and `episodeAvailability()` methods. `CatalogTitlesPageBuilder` bulk-resolves all requested slugs once per relation type and keeps:

- `Collection<string, Collection<Model>>` active relation groups;
- invalid slug lists by relation type;
- selected year list;
- scalar legacy route compatibility.

The public controller remains orchestration-only.

### Query semantics

`CatalogTitleQuery` always starts from published titles. It applies `whereIn(year, years)` and one indexed pivot subquery per selected relation type. Each pivot subquery uses `whereIn(related_id, selectedIds)`, which implements OR inside the group; separate subqueries implement AND across groups.

Search IDs are materialized once when a non-empty search is present and reused by the result, facet, and year-count queries. This avoids repeating the expensive broad text/relation search for every facet. Search fallback to unrelated titles is removed.

Every catalog sort ends with `catalog_titles.id DESC` for stable pagination.

### Facets

A focused `CatalogFacetQuery` replaces global `Model::withCount()` scans. For each relation it:

1. builds the title context while excluding that relation type;
2. joins the relation pivot to the context title query;
3. groups by the related ID and orders by contextual title count;
4. joins the lookup table and returns a bounded list;
5. merges selected values even when they fall outside the normal limit.

The UI displays contextual counts only. It no longer computes a second global count for every option. Years use the same context rule and include selected years outside the normal visible range.

Facet limits remain conservative, with high-cardinality actor/director groups smaller than countries or genres. A local filter input searches the options already returned for each group. Direct taxonomy pages and title relation links remain the route for rare values; asynchronous global autocomplete is deferred.

### Publication boundary

Index queries, facet contexts, year buckets, fallback recommendations, and title route binding must all exclude unpublished titles. API behavior remains read-only and unchanged unless its own filter contract is intentionally added later.

## Catalog interface

The filter UI becomes one GET form:

- a mobile “Фильтры” button opens the filter panel without moving it below thousands of pixels of results;
- desktop keeps a sticky sidebar;
- each dimension is a native disclosure group with a count and searchable checkbox list;
- years, countries, and genres are initially prominent; high-cardinality groups are collapsed;
- a sticky footer contains “Применить” and “Сбросить”; selected chips above the result grid can remove one value at a time;
- all controls have labels, visible focus, 44 px minimum touch targets, Escape/close behavior, and no horizontal overflow.

Cards use one stretched title link covering the card. Relation chips sit above the overlay with their own links, so the markup has one title tab stop and valid non-nested anchors. Mobile cards use a compact poster-plus-content row; larger screens retain the grid card.

The empty state reports that no titles match the selected combination and never silently shows unrelated content.

## Title page

The layout contains one `<main>` landmark from the application shell. The title page itself uses sections/divs.

Order on mobile and desktop:

1. compact hero with poster, title, original title, year, and safe counts;
2. player and playback variants;
3. one “О сериале” section;
4. seasons and episodes;
5. source reviews when present;
6. recommendations.

“О сериале” is the single source of truth for public metadata. It displays:

- year and original title;
- genres, countries, actors, directors, age ratings, translations, statuses, networks/channels, studios, and tags;
- aliases and public ratings;
- an explicit “Не указано” state for every empty group.

Reviews are loaded with a safe limit and escaped output. Source pages, snapshots, import events, source URLs, hashes, internal error state, and unselected media URLs are not exposed. The selected published playback URL remains the existing player contract; replacing it with an internal proxy is a separate architectural change. The sidebar becomes compact page navigation/status rather than a duplicate relation dump.

## Importer and metadata recovery

### Parsing

The parser continues to trust the main Seasonvar information block first and expands supported labels conservatively (`Телеканал`, `Студии`, and equivalent explicit labels). The official `ul.pgs-trans` selector supplies translation names. Structured data may supply production companies and other directly named entities.

Only the official description/metadata nodes may contribute line-oriented, explicitly labeled metadata. Reviews and user comments are not treated as catalog metadata. Status values are accepted only when they match a small domain vocabulary such as completed, airing, renewed, closed, announced, or in production. Placeholder phrases such as “ничего не найдено” are rejected. Exact source tags matching a curated broadcaster vocabulary may add a channel; arbitrary tags are not reclassified.

Translation names derived from media are normalized by removing playback-quality prefixes while preserving the actual voiceover/studio name. Trailers are never translations; subtitles remain a tag/playback property; original tracks may add “Оригинал”.

### Shared storage

Relation upsert/attachment moves from the private importer method into a focused shared synchronizer. It keeps the existing bulk `upsert` and indexed pivot inserts and uses `syncWithoutDetaching`, because one title can aggregate multiple season pages.

### Versioned backfill

An additive migration records:

- the metadata parser version and completion time processed for each source page;
- the parser version already attempted locally for each source page, so deterministic snapshot failures do not starve the bounded queue;
- a JSON presence map with `present`, `absent_in_source`, or `rejected_invalid` per optional field;
- the derived relation backfill version processed for each catalog title;
- indexes supporting both queues.

`seasonvar:import` remains the only public command. Each cycle performs bounded work:

1. parse the latest retained snapshot (selected by capture time, not merely row ID) for stale source pages before scheduling a remote refresh;
2. attach newly recognized explicit relations through the shared synchronizer;
3. derive missing title translations from normalized season/media metadata;
4. mark the page/title version only after a successful transaction;
5. reserve unattempted retained snapshots for local work, while pages without a snapshot or with a deterministic local failure remain eligible for the normal conservative refresh planner.

Both chunk size and a separate hard per-cycle record limit bound the work; `lazyById()` alone is not treated as a total-work limit. Snapshot cleanup always retains the latest snapshot for every page so a future parser-version bump still has a local source. New remote parses write the current version and immediately synchronize derived title relations. Season-page snapshots resolve their canonical title through the stored season source hash when no direct title link exists.

Metadata absence is not automatically an importer failure: many sources legitimately omit a channel or studio. Completeness reporting distinguishes “processed by the current parser” from “value present” and “source value rejected”. Existing media/episode missing flags remain focused on actionable retry conditions.

The same importer hardening pass fixes data-loss conditions where `preg_match_all()` accepted exactly one match but discarded two or more matches. Media availability HTTP is moved outside database transactions so SQLite locks and transaction retries never wrap external network calls. Relation lookup upserts preserve an existing non-null `source_url` when the new observation has no URL.

Progress events and run summaries report pages/titles checked, relation records attached, translations derived, and failures. No source URL is emitted into public pages.

## Performance constraints

- Preserve all existing pivot reverse indexes and the `(year, indexed_at)` indexes.
- Do not query from Blade.
- Eager-load every relation used by cards or title serialization.
- Do not render hundreds of always-expanded links.
- Reuse the materialized search candidate set across facets.
- Use bounded chunks, `lazyById`, `upsert`, transactions, and grouped queries for backfill.
- Record before/after query count and timing for the `бухта` search. The practical target is a warm response below one second in the local/runtime environment; any external infrastructure limit is reported separately.

## Error handling

- Malformed arrays fail with Russian validation messages.
- Unknown slugs remain visible as removable invalid chips and produce zero results.
- A failed snapshot parse does not advance metadata versions and is reported through importer progress.
- A deterministic failed snapshot advances only the local attempted version, cannot starve later pages, and becomes eligible for conservative remote refresh.
- A missing optional relation renders “Не указано”; it does not throw and does not invent content.
- External HTTP remains guarded by the existing URL normalization, timeouts, retries, crawl delay, and command lock.

## Test and QA strategy

TDD coverage includes:

- scalar-to-array normalization, duplicate removal, limits, nested/malformed values, and Russian errors;
- two years (OR), two countries (OR), country plus actor (AND), all relation types, availability filters, query preservation, and stable sorting;
- aggregate facet context counts, selected-value retention, published-only results, and bounded query/output behavior;
- one stretched title link with independent relation links;
- all ten relation groups, aliases, ratings, reviews, empty states, one main landmark, and absence of raw URLs;
- parser fixtures for status/network/studio/tags/translation variants and rejection of comment noise/placeholders;
- local snapshot backfill, unchanged-page metadata versioning, derived translations, idempotency, rollback on failure, and progress summaries.

Verification order:

1. focused PHPUnit tests per behavior;
2. `./vendor/bin/pint --dirty --format agent`;
3. full `php artisan test`;
4. `npm run build`;
5. browser QA at 390, 768, 1440, and wide desktop widths, including console/network checks, keyboard navigation, mobile drawer behavior, filter combinations, card clicks, and a populated/empty title page;
6. fresh database coverage and timing queries, without mutating the production-like database.

## Documentation

Update `README.md`, `docs/CODE_STANDARDS.md`, `docs/DATA_RELATIONS.md`, `docs/UI_STANDARDS.md`, `docs/performance.md`, the relevant catalog/importer documentation, and `docs/MAINTENANCE_LOG.md`. Documentation must describe actual coverage and progressive backfill rather than claim that every optional source field is always present.
