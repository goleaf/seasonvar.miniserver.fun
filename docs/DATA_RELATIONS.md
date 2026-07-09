# Data Relations And Filters

Last updated: 2026-07-09

## Core relations

- `CatalogTitle belongsTo Source`
- `CatalogTitle belongsTo SourcePage`
- `CatalogTitle hasMany Season`
- `CatalogTitle hasManyThrough Episode`
- `CatalogTitle hasMany LicensedMedia`
- `CatalogTitle hasMany CatalogTitleAlias`
- `CatalogTitle hasMany CatalogTitleRating`
- `CatalogTitle hasMany CatalogTitleReview`
- `CatalogTitle belongsToMany Genre`
- `CatalogTitle belongsToMany Country`
- `CatalogTitle belongsToMany Actor`
- `CatalogTitle belongsToMany Director`
- `CatalogTitle belongsToMany AgeRating`
- `CatalogTitle belongsToMany Translation`
- `CatalogTitle belongsToMany CatalogStatus`
- `CatalogTitle belongsToMany Network`
- `CatalogTitle belongsToMany Studio`
- `CatalogTitle belongsToMany Tag`
- `Season belongsTo CatalogTitle`
- `Season belongsTo SourcePage`
- `Season hasMany Episode`
- `Episode belongsTo Season`
- `Episode belongsTo SourcePage`
- `LicensedMedia belongsTo CatalogTitle`
- `LicensedMedia belongsTo Season`
- `LicensedMedia belongsTo Episode`
- `SourcePage hasMany SourcePageSnapshot`
- `SeasonvarImportRun hasMany SeasonvarImportEvent`
- Every catalog relation model belongs to many `CatalogTitle` records through an explicit pivot table.
- No morph or polymorphic relations are used for catalog metadata.

## Taxonomy filter types

These taxonomy types are filterable and should have local pages:

- `genre`
- `country`
- `actor`
- `director`
- `age_rating`
- `translation`
- `status`
- `network`
- `studio`
- `tag`

## Filter behavior

- `/titles/{type}/{taxonomy}` must show only titles connected to that exact taxonomy.
- Multiple query filters must combine as AND conditions.
- Counts in filter sidebars have two meanings: current filtered count and global count.
- Invalid filter values must not fall back to full catalog results.
- Year filters must validate four-digit years from 1900 through next year.

## Query indexes

The catalog depends on these query indexes for stable filtering and sync performance:

- Per-relation reverse pivot indexes on related ID and `catalog_title_id` for filters and recommendations.
- `catalog_titles_indexed_at_idx` on `indexed_at` for newest title lists.
- `catalog_titles_year_indexed_idx` on `year, indexed_at` for year-filtered title lists.
- `source_pages_status_type_id_idx` on `parse_status, page_type, id` for pending source-page selection.
- `source_pages_type_status_crawled_id_idx` on `page_type, parse_status, last_crawled_at, id` for refresh cycles.
- `licensed_media_title_status_published_idx` on `catalog_title_id, status, published_at` for title media lists.
- `licensed_media_episode_status_quality_idx` on `episode_id, status, quality` for episode media selection.
- Unique `licensed_media.catalog_title_id + source_media_key` for stable video URL updates.
- Source-page import state indexes on `import_status`, `retry_after_at`, and `last_imported_at` for the single import command.

## Parser fields

Current Seasonvar parser stores:

- title
- original title
- type
- year
- description
- poster URL
- external source ID
- aliases
- IMDb and KinoPoisk ratings
- reviews
- current season number
- seasons
- season release status: latest episode date, released episode count, total episode count when known, season translation, raw status text
- episodes
- media candidates, external playback URLs, quality, format, translation, and availability status
- parsed relation values for genres, countries, actors, directors, age ratings, translations, statuses, networks, studios, and tags
- raw HTML snapshots for diagnostics

## Parser relation extraction

Current parser relation sources:

- structured JSON-LD genre, actor, director, country
- `pgs-sinfo_list` labels for genre, country, age, translation, status, network, studio
- `itemprop=directors` for directors
- `data-info=actor` and schema actor blocks for actors
- Season list translation markers such as `(AniDub)`
- Season list update markers such as `(09.07.2026 1 серия (AniDub) из ??)` as structured `seasons` fields
- Seasonvar tag list links when present
- Subtitle text markers as `tag=субтитры`

## Import behavior

- The only public Seasonvar command is `php artisan seasonvar:import`.
- Without arguments, the command reads the Seasonvar sitemap, stores all found serial-page URLs, then processes queued pages one request at a time.
- With a URL argument, the command updates that title and detected direct season pages.
- Existing relations, episodes, and media are preserved when they disappear from the source page.
- Changed title, description, poster, rating, and video URL fields are updated.
- Duplicate season pages are merged into one `CatalogTitle`; seasons remain internal records.
