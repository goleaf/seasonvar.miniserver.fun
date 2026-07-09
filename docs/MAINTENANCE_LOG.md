# Maintenance Log

## 2026-07-09

- Replaced separate Seasonvar public commands with `php artisan seasonvar:import`, including URL mode, forced refresh, forever mode, database-backed run logs, sitemap discovery, page updates, season-page updates, media collection, and title merging.
- Added import state tables and fields for run events, source-page retry state, missing-data flags, raw HTML snapshots, reviews, and stable media updates through `source_media_key`.
- Updated Seasonvar media storage to keep external playback links, quality, translation, format, availability checks, and all variants without downloading video files.
- Moved duplicated season-title merging into an internal service so one catalog title owns all seasons.
- Updated title pages with season accordions, visitor-friendly Russian empty states, media variant selection, and Plyr/HLS video playback.
- Removed the manual playlist import command from the public Artisan command list while keeping the playlist importer as an internal service.
- Added importer support for Seasonvar IMDb/KinoPoisk ratings and alternative title aliases through concrete tables.
- Improved Seasonvar info-block parsing for labels, ratings, age ratings, countries, genres, directors, actors, and episode fallback extraction.
- Removed decorative catalog labels that did not provide navigation, filtering, counts, or actionable status.
- Added local FontAwesome icons through npm/Vite and wired icon props into shared UI components without CDN usage.
- Removed description text that had been imported as countries and blocked description-like relation names from future imports.
- Blocked long text from being imported as age rating relations.
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
## 2026-07-09

- Added portal-wide SEO metadata: canonical URLs, robots directives, OpenGraph/Twitter tags, schema.org JSON-LD, a dynamic Laravel sitemap, and a robots.txt sitemap declaration.
- Added SEO payloads for the home page, catalog listing/filter pages, and individual title pages, including TVSeries, VideoObject, CollectionPage, WebSite, and BreadcrumbList structured data.
- Expanded SEO automation with sitemap index files, static/year/taxonomy/title sitemap sections, image sitemap entries for posters, an RSS feed for recently updated titles, canonical de-duplication for filter pages, and richer OpenGraph video/image metadata.
- Added OpenSearch metadata so browsers and crawlers can discover catalog search automatically.
- Added automatic ItemList structured data for home and catalog pages, plus season and episode structured data for title pages using already-loaded relations.
- Added automatic WebPage and Organization structured data, hreflang alternates, last-modified metadata, article tags, richer TVSeries publisher/language/free-access fields, and source sameAs links.
- Added visible breadcrumb navigation, SiteNavigationElement structured data, and automatic visible FAQ blocks with matching FAQPage JSON-LD on title pages.
- Added automatic long-tail keyword generation from titles, aliases, genres, countries, actors, directors, years, seasons, episodes, and media availability, with visible search phrase blocks plus keywords/news_keywords metadata.
- Added automatic visible SEO summary text and related internal links for home, catalog, and title pages, plus speakable WebPage selectors so generated keywords are supported by readable page content.
- Added video sitemap chunks, LLM discovery text, Dublin Core metadata, semantic keyword clusters, and visible cluster blocks generated from catalog/title facts.
- Added clean year landing URLs, dynamic catalog H1/lead text for every filter/year/search state, sitemap links to year landing pages, and clickable semantic/search phrase chips for internal discovery.
- Added programmatic SEO landing sitemap for real taxonomy-plus-year combinations, covering high-value pages such as genre/year, country/year, actor/year, director/year, translation/year, and age-rating/year.
- Added portal-wide topical SEO expansion with subject/classification/page-topic meta tags, schema.org DefinedTermSet data, and visible key-topic internal links generated from existing tags, search phrases, and semantic clusters.
- Added global search-intent SEO expansion with article tags, target/search-intent metadata, schema.org ItemList thematic navigation, and visible query-navigation links generated automatically for every SEO-enabled page.
- Added global entity SEO expansion with canonical URL summary metadata, repeated entity meta tags, schema.org WebPage `about`/`mentions`, and microdata on visible internal navigation blocks.
- Added global quick-answer SEO expansion with visible page-specific answer cards and matching schema.org FAQPage data generated from each page title, description, topics, and search intent terms.
- Added global page-structure SEO expansion with automatic visible table-of-contents links, section anchors, `toc-count` metadata, schema.org ItemList contents, and WebPageElement `hasPart` data for every SEO block.
- Added global long-tail SEO expansion with automatic visible query-phrase links, `long-tail-keywords` metadata, `query-count` metadata, and schema.org ItemList data generated from page topics and search intent terms.
- Improved Seasonvar season URL parsing for `season`, `sezon`, `сезон`, and zero-padded season numbers like `00005-sezon`, so playlist media defaults to the correct current season.
- Preserved canonical catalog title content hashes when importing additional season pages for the same series, preventing non-canonical season pages from overwriting title-level sync state.
