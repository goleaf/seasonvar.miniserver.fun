# Code Standards

Last updated: 2026-07-09

## Required workflow

Every future code change must update this documentation set when it changes architecture, parser rules, UI components, relations, commands, or query patterns.

Update these files together when relevant:

- `docs/CODE_STANDARDS.md` for coding conventions and architecture rules.
- `docs/UI_STANDARDS.md` for visual and Blade component rules.
- `docs/DATA_RELATIONS.md` for parser fields, Eloquent relations, filters, and database meaning.
- `docs/MAINTENANCE_LOG.md` for a short dated summary of important changes.

## Laravel standards

- Keep server configuration unchanged from application work.
- Do not use seeders for production catalog data.
- Keep `php artisan seasonvar:full-sync` as the single operational sync entry point.
- Prefer Eloquent relationships over raw queries.
- Eager-load all relationships used by Blade views.
- Do not run database queries in Blade views.
- Use `withCount()` for relation counts shown in lists.
- Batch count calculations where possible instead of running one query per visible filter item.
- Keep route filters internal: `/titles/{type}/{taxonomy}` and `/titles?...` should always resolve to local catalog pages.
- Normalize and validate all query inputs before applying filters.

## Parser standards

- Use only `https://seasonvar.ru/` as the Seasonvar source domain.
- Parse metadata, relations, seasons, episodes, poster URLs, and source page state.
- Do not depend on external player pages for local navigation.
- Do not store or expose scraped video playlist internals as application requirements.
- Clean titles before storage: remove `>>>`, trailing online text, date noise, and Seasonvar suffixes.
- Store season-list update text like `(09.07.2026 1 серия (AniDub) из ??)` as structured season fields plus the raw status text.
- Store relation-like values in concrete relation tables when they are filterable: genre, country, actor, director, age rating, translation, status, network, studio, tag.
- Do not introduce morph or polymorphic relations for catalog metadata; use explicit `belongsToMany` relations and pivot tables.
- Importer relation sync must group parsed relation values by type, batch `upsert` lookup rows, fetch IDs in one query per type, and `sync()` each pivot relation once.
- Importer catalog writes after successful HTTP parsing must run in one database transaction: title, relation pivots, seasons, episodes, and final source-page parsed status.
- Importer season and episode sync must use batch `upsert` for parsed page payloads instead of per-row `updateOrCreate()`.
- Store season/episode structure as `seasons` and `episodes`, not as tags.

## Query standards

- `CatalogController::titles()` must resolve active filters through the concrete relation model for that filter type.
- Filter sidebar context counts must be calculated by aggregated/union queries over relation pivot tables, not per item loops.
- Year context counts must be calculated by grouped year query, not per year row loops.
- `CatalogController::show()` must prepare grouped relation collections once and pass them to Blade.
- `CatalogController::show()` must eager-load only relations used by the current Blade blocks.
- Recommended-title queries must not eager-load seasons or relation collections unless the recommendation block displays them.
- Many-to-many catalog relation filters must keep a reverse pivot index on related ID and `catalog_title_id`.
- Lists ordered by `indexed_at` must keep an index that supports the ordering and common year filters.
- Media lookups for title pages must keep an index on title, status, and published timestamp.

## Naming standards

- Models are singular: `CatalogTitle`, `Genre`, `Country`, `Actor`, `Director`, `AgeRating`, `Translation`, `CatalogStatus`, `Network`, `Studio`, `Tag`, `Season`, `Episode`.
- Collections use plural descriptive names: `$recommendedTitles`, `$taxonomiesByType`.
- View data names should describe prepared data, not implementation details.
- Blade components live under `resources/views/components` and reusable UI under `resources/views/components/ui`.
