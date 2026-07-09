---
name: seasonvar-seo
description: Use for Seasonvar SEO, sitemap, robots.txt, canonical URLs, structured data, feeds, Search Console, Google Analytics, indexing diagnostics, and search-discovery documentation. Trigger when editing SEO builders, sitemap responders, robots, metadata, docs for Google integrations, or analytics/search-console config.
---

# Seasonvar SEO

## Overview

Keep search-discovery features factual, crawlable, and safe. SEO output must reflect real catalog data and must not leak private source URLs, raw importer state, stack traces, or secrets.

## First Steps

- Read `docs/audit.md`, `docs/DATA_RELATIONS.md`, `docs/performance.md`, `docs/security.md`, `docs/integrations/google.md` when present, and neighboring SEO/sitemap services before editing.
- Use Laravel Boost for Laravel version-sensitive behavior.
- Check existing tests for sitemap, robots, metadata, and public output terminology before changing response shape.

## SEO Rules

- Generate titles, descriptions, keywords, JSON-LD, FAQ, and internal links from real fields only.
- Keep canonical URLs stable and avoid duplicate-indexing paths for filter/search states unless the existing SEO builder intentionally exposes them.
- Keep sitemap/feed responses streamed or chunked when they can grow with the catalog.
- Do not expose source HTML snapshots, internal importer events, private media URLs, secrets, or stack traces.
- Google Search Console and Google Analytics integrations should default to read-only scopes and aggregate storage.

## Google Workflow

- Search Console API requires OAuth 2.0; use `webmasters.readonly` unless a write action is explicitly required.
- GA4 reporting should use the Google Analytics Data API and `GOOGLE_APPLICATION_CREDENTIALS` or an external secret store.
- Store only required configuration keys in `.env.example`; never commit credential JSON, refresh tokens, OAuth client secrets, or exported private reports.

## Verification

- Run sitemap/robots/SEO focused tests first, then wider tests for shared SEO builders.
- Run `npm run build` only when frontend assets or Blade asset assumptions changed.
