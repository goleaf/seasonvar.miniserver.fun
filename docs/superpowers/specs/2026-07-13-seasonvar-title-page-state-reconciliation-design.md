# Seasonvar title page state reconciliation design

## Problem

`SeasonvarCatalogImporter` calculates `missing_data_flags` from the whole `CatalogTitle`, but stores the result only on the `SourcePage` that has just been parsed. During a multi-season import, the first page can therefore persist flags such as `seasons_without_episodes` before a later season page supplies the missing episodes or media. The later page receives the correct final state while the earlier page remains `missing_data` and is selected for an unnecessary retry.

Production data confirms that this is not an isolated display issue: 391 linked pages retain `seasons_without_episodes` after their title has no empty seasons, and 1,343 retain `episodes_without_video` after every episode has published media.

## Chosen approach

After every successful parse, and after a safe unchanged-page skip, calculate the title-level flags once from a freshly loaded title and synchronize them to every already parsed source page linked to that title. Linked page ids come from the current page, the title's canonical `source_page_id`, and source pages whose `url_hash` matches a season's `source_url_hash`.

Season `source_page_id` cannot identify every linked page because the existing season upsert associates all season rows with the page currently supplying their metadata. The stable cross-page identity is the normalized season URL hash.

The synchronization updates only pages whose `parse_status` is `parsed` and whose `import_status` is `parsed` or `missing_data`, plus the current successfully parsed page. It must not change pending, claimed, or failed pages because their page-level lifecycle still requires independent work or retry handling.

The current page continues to receive its own `last_imported_at`, `last_import_run_id`, and reset `failure_count`. Sibling pages receive only the derived title-state fields: `import_status`, `missing_data_flags`, and `retry_after_at`. This preserves page-specific crawl and run history.

## Data flow

1. Parse and persist the current page, seasons, episodes, and external media as today, or confirm that an unchanged page's existing title does not require media recovery.
2. Reload the `CatalogTitle` with seasons, episodes, season media, and title media.
3. Calculate the final title-level missing-data flags once.
4. Collect the current and canonical page ids, then resolve every stored season URL hash to a source page from the same source.
5. Update the current page's successful import metadata and derived state.
6. Bulk-update the derived state on eligible parsed sibling pages.
7. A later season page can therefore clear or replace stale flags written by an earlier page without another HTTP request.

## Concurrency and performance

Queued page jobs for one title are already serialized by the existing Redis title-group lock. The reconciliation adds no network calls and one bounded bulk update per successful page. A title normally owns only a small number of season pages, so this is cheaper than scheduling and downloading stale pages again.

The implementation remains safe for the synchronous targeted command because it applies after each detected season page in the same sequence.

## Alternatives rejected

- Waiting for each stale page's next retry keeps false state for hours or days and creates avoidable source traffic.
- A global reconciliation only at the end of a cycle is too late for continuously running queues and adds an unbounded catalog scan.
- Updating every linked page regardless of lifecycle can erase a real failure or falsely mark an unparsed page as parsed.

## Compatibility and safety

- `php artisan seasonvar:import` remains the only public importer command.
- No dependency, migration, video download, or new remote request is introduced.
- Failed, pending, and claimed sibling pages retain their independent status; active claim tokens are an explicit exclusion even when the prior import status is `parsed` or `missing_data`.
- Page-specific crawl timestamps, hashes, failures, claims, and import-run attribution are not copied between pages.

## Tests

- Extend the multi-season command test so the first page initially observes an empty linked season and the second page fills it.
- Assert that both parsed source pages finish with identical final title-level flags and that the first page no longer retains `seasons_without_episodes`.
- Assert that parsed siblings keep their original `last_imported_at` and `last_import_run_id` when another page reconciles the shared title state.
- Assert that a linked failed or pending page is not changed by successful reconciliation.
