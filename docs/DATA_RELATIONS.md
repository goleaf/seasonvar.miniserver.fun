# Data Relations And Filters

Last updated: 2026-07-09

## Core relations

- `CatalogTitle belongsTo Source`
- `CatalogTitle belongsTo SourcePage`
- `CatalogTitle hasMany Season`
- `CatalogTitle hasManyThrough Episode`
- `CatalogTitle hasMany LicensedMedia`
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

## Parser fields

Current Seasonvar parser stores:

- title
- original title
- type
- year
- description
- poster URL
- external source ID
- current season number
- seasons
- season release status: latest episode date, released episode count, total episode count when known, season translation, raw status text
- episodes
- parsed relation values for genres, countries, actors, directors, age ratings, translations, statuses, networks, studios, and tags

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
