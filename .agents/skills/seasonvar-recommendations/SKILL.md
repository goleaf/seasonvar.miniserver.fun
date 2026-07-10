---
name: seasonvar-recommendations
description: "Use when planning, implementing, testing, or debugging Seasonvar title recommendations across importer signals, recommendation migrations/models, CatalogTitleRecommendationBuilder scoring, import-cycle rebuilds, title-page `Советуем посмотреть` UI, fallback recommendations, and recommendation QA metrics."
---

# Seasonvar Recommendations

## Overview

Improve recommendations as a cross-cutting feature: source parsing, stored signals, scoring, importer lifecycle, database indexes, page builder queries, Blade output, and Playwright verification must stay aligned.

## First Steps

- Read `app/Services/Catalog/CatalogTitleRecommendationBuilder.php`, `app/Services/Catalog/CatalogTitlePageBuilder.php`, `resources/views/catalog/show.blade.php`, recommendation models/migrations, and relevant tests.
- Use `seasonvar-importer` for parser/import-cycle behavior and `seasonvar-ui` plus `tailwindcss-development` for Blade/Tailwind work.
- Use Laravel Boost docs/search before version-sensitive Laravel query or migration changes.
- Use read-only database checks to understand real local data before changing thresholds.

## Data Rules

- Keep `php artisan seasonvar:import` as the only public Seasonvar import command.
- Rebuild recommendations at the end of the existing import cycle, after source page parsing, media refresh, invalid relation cleanup, and season-title merge.
- Store external/source-derived recommendation signals as normalized rows; do not store raw private HTML or unbounded source payloads.
- Add indexes for new joins, stale-signal cleanup, ranking, algorithm version, and title lookups.
- Keep migrations additive and reversible.

## Scoring Rules

- Separate score buckets when possible: metadata match, source signals, and quality/availability.
- Favor strong shared metadata: genres, tags, directors, actors, studios/networks, translations, status, country, age rating, year proximity.
- Favor candidates with playable published media; never recommend unavailable candidates above playable ones.
- Penalize or filter weak matches rather than filling the block with unrelated titles.
- Store enough breakdown/reason data for debugging and UI badges, but keep public labels short and Russian.

## UI Rules

- `Советуем посмотреть` should show useful ranked recommendations for the current title.
- Do not render an empty recommendation panel followed by a separate fallback panel unless that is an intentional UX decision.
- Show reason badges such as `Жанр`, `Актеры`, `Год`, `Источник`, `Видео` when they clarify why the item is recommended.
- Avoid database queries in Blade; feed all data through page builders/view models.

## Verification

- Run focused builder, parser/importer, and catalog page tests.
- Use Playwright QA on a title with precomputed recommendations and one without them.
- Check database metrics after an import cycle: titles processed, titles with recommendations, stored rows, algorithm version, and stale-row cleanup.
- Run `php artisan test` for broad recommendation changes and `npm run build` for title-page UI changes.
