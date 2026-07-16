---
name: seasonvar-importer
description: Use for Seasonvar import, crawler, parser, media metadata, media availability, source page refresh, importer database behavior, and the `php artisan seasonvar:import` command. Trigger when editing `app/Services/Seasonvar`, `app/Services/Media`, importer migrations/models/tests, or any logic that reads Seasonvar pages, stores seasons/episodes, or stores external playback variants.
---

# Seasonvar Importer

## Overview

Keep Seasonvar ingestion reliable, polite, and database-efficient. The importer stores catalog facts and external playback metadata only. It may inspect metadata with bounded `HEAD` or one-byte Range requests, but it never reads or stores a complete video body. Authenticated on-demand download is a separate streaming delivery boundary and never persists imported video copies.

## First Steps

- Read `AGENTS.md`, `docs/architecture.md`, `docs/performance.md`, `docs/DATA_RELATIONS.md`, and neighboring service/model/test files before editing.
- Use Laravel Boost `application_info` at the start of Laravel work and `search_docs` before version-sensitive Laravel API changes.
- Preserve `php artisan seasonvar:import` as the only public Seasonvar import command. Add internal services or private helpers instead of new public importer commands.

## Import Rules

- Keep source URLs inside `https://seasonvar.ru/` and normalize/validate them before storage or requests.
- Store seasons and episodes inside one `CatalogTitle`; never create separate catalog titles for individual seasons.
- Store external media URL, quality, format, translation/subtitle state, availability status, stable source keys, and exact file-size metadata when a trusted bounded response provides it. Never download or persist a complete video during import.
- On-demand authenticated direct-file delivery may stream bounded chunks from an authorized, revalidated upstream source. It must not write video bodies to application storage/cache/database; HLS manifests and segments are never combined into a downloadable file.
- Use Laravel HTTP client timeouts, connect timeouts, retry rules, crawl delays, and `Http::fake()` in tests.
- Treat unchanged source hashes as a safe skip path when existing code supports it.

## Database Rules

- Use transactions for multi-table catalog writes.
- Prefer bulk `upsert`, grouped queries, `chunkById`, cursors, or lazy collections for large imports.
- Add indexes for new filters, joins, sorted lists, queues, and bulk refresh patterns.
- Use existing explicit catalog pivot tables; do not introduce polymorphic catalog metadata unless the user explicitly asks for redesign.
- Keep controllers thin; parsing, import orchestration, media availability, and heavy query building belong in services.

## Verification

- Run focused tests for changed importer/media behavior first, then broader tests when the blast radius is larger.
- Use `Http::preventStrayRequests()` in new importer/crawler/media tests.
- After PHP edits, run `./vendor/bin/pint --dirty --format agent`.
