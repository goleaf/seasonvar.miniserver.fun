# Catalog directory hubs implementation plan

**Goal:** Add eleven local, public, searchable directory hubs without duplicating catalog taxonomy or visibility logic.

**Architecture:** `CatalogDirectoryRegistry` owns route/UI metadata and delegates taxonomy identity to `CatalogTaxonomyRegistry`. `CatalogDirectoryQuery` applies the existing guest visibility boundary and grouped SQL counts. One Livewire 4 component owns small URL-bound state and renders one reusable directory view. Generic title-filter routes remain canonical; friendly detail routes are permanent aliases.

**Stack:** Laravel 13.19, Livewire 4.3, PHP 8.5, SQLite, Blade, Tailwind CSS 4.3, Vite 8.

## Delivery sequence

1. Extend existing route/catalog/sitemap tests with the eleven route contracts, published-only counts, URL restoration, alias redirects, invalid input, sitemap coverage, and global Livewire assets.
2. Add typed directory definitions, the registry, grouped query service, SEO-aware page builder, and thin redirect controller.
3. Add the reusable Livewire browser with locked directory identity, allowlisted sort, normalized search/letter/decade state, stable pagination, and page recovery.
4. Add responsive Blade components, Russian translations, navigation/internal links, breadcrumbs, canonical/OG/CollectionPage/ItemList metadata, and global Livewire assets.
5. Add directory hubs to the static sitemap while keeping only canonical generic detail URLs in taxonomy/year sitemaps.
6. Inspect SQL/query counts, run focused tests, Pint, broader tests, docs refresh, and frontend build; review the full diff before commit and push.

## Verification targets

- All eleven hubs return 200 and show only locally related public titles.
- Taxonomy/year aliases return 301 to the existing canonical title listings; missing values return 404.
- Search, alphabet, decade, sort, and pagination state survives a Livewire URL round trip and rejects malformed state.
- Counts are duplicate-safe and independent of page size; rendering does not issue one query per directory item.
- Static sitemap contains hubs, detail sitemaps contain canonical URLs only, and ordinary Blade pages load one Livewire asset set.
