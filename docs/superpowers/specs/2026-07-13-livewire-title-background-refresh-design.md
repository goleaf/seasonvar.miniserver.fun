# Livewire title background refresh design

## Problem

The public title page renders catalog metadata from the local database and delegates only playback state to the nested `CatalogTitlePlayer` Livewire component. Visiting a title therefore does not request a fresh Seasonvar parse, and data imported after the initial HTTP response cannot update the static title shell without a browser reload.

The requested behavior is to start a background refresh when a visitor opens a title page, parse the canonical title page and every known season and episode, and expose all resulting catalog changes on the already open page through Livewire. Public traffic must not create unbounded Seasonvar requests or duplicate queue work.

## Chosen approach

Introduce a dedicated per-title refresh coordinator, a unique queued job on the existing `seasonvar-import` queue, and a Livewire page component that owns the complete title-page presentation while retaining `CatalogTitlePlayer` as a nested playback boundary.

The coordinator treats a title as fresh for 15 minutes after its latest successful targeted refresh. When the title is stale, it atomically records the dispatch state and queues one job keyed by `CatalogTitle::id`. Concurrent visitors observe the same state and do not dispatch duplicate work. The queued job reuses the existing targeted `SeasonvarImportPipeline`, which parses the trusted canonical URL first and then all direct season URLs discovered on the refreshed title.

While a refresh is queued or running, the page component polls every three seconds. Each poll rebuilds the complete title-page data through the existing catalog page builder. When the persisted catalog revision changes, the outer page rerenders title metadata and dispatches a scoped Livewire event to the nested player so it clears request-local model caches and reloads seasons, episodes, and media. The player preserves the selected season, episode, media, and profile when they remain valid and falls back through its existing authorization and playback-selection boundaries when imported data invalidates the old selection.

## Components and responsibilities

### Title refresh coordinator

The coordinator is the only entry point used by the public Livewire page to request a refresh. It:

- accepts a server-resolved `CatalogTitle`, never a public source URL;
- confirms that the title has a stored Seasonvar source URL;
- calculates freshness from the last successful title refresh;
- uses the configured Redis lock store to make the freshness check and dispatch decision atomic;
- stores only scalar refresh state with a bounded TTL;
- dispatches the dedicated job after the database transaction commits when applicable;
- returns a small state object or array suitable for the Livewire view.

The public GET remains read-oriented: it may request background work, but it does not perform crawling, parsing, or multi-table writes in the HTTP or Livewire request.

### Per-title refresh job

The job implements Laravel's unique-job contract with a key scoped to the catalog title id. It uses the existing Redis queue connection, `seasonvar-import` queue, timeout, retry window, and lock store. Before importing, it resolves the title again, normalizes the stored source URL through `SeasonvarUrl`, and rejects anything outside `https://seasonvar.ru/`.

The job acquires the same title-group lock used by queued source-page imports so a visitor-triggered refresh cannot race a scheduled import for the same title. It then runs the existing targeted pipeline with forced refresh enabled and discovery disabled. The pipeline continues to own canonical-page parsing, season URL selection, episode/media persistence, title-state reconciliation, cache invalidation, and import-run recording.

The job updates bounded refresh state to `queued`, `running`, `completed`, or `failed`. Technical exception details are logged through the existing sanitized importer error path and are never stored in Livewire state.

### Livewire title page

The existing controller keeps route model binding and canonical-slug redirects. It delegates page rendering to a new Livewire title-page component using only the bound title id and normalized playback query state.

The component:

- locks the catalog title id against client tampering;
- verifies public visibility through the existing catalog query boundary on every request;
- asks the coordinator to dispatch only during initial mount;
- polls with `wire:poll.3s.visible` only while state is `queued` or `running`;
- rebuilds all static title data through `CatalogTitlePageBuilder` on each active poll;
- stops polling on `completed` or `failed`;
- renders a compact Russian status: `Обновляем данные`, `Данные обновлены`, or `Не удалось обновить`;
- sends no raw Seasonvar URL, external media URL, exception, lock token, or queue payload to the browser.

The complete title shell, including title, original title, description, poster, year, taxonomies, counts, season summaries, and recommendations, lives inside this component. `CatalogTitlePlayer` remains nested with a stable component identity so outer polls do not destroy active playback.

### Playback refresh listener

`CatalogTitlePlayer` gains one scoped event listener for a completed catalog revision. The listener clears its resolved title, season, and episode caches before the next render. Existing selection normalization remains the single authority for retaining or replacing season, episode, media, quality, format, and variant state. No new public write boundary is introduced.

## Data flow

1. A visitor opens the canonical public title route and immediately receives the latest local catalog data.
2. The Livewire page resolves the visible title and asks the coordinator for refresh state.
3. If a successful refresh is newer than 15 minutes, no job is dispatched and polling stays disabled.
4. If the title is stale, one atomic coordinator decision stores `queued` and dispatches the unique per-title job.
5. The job marks `running`, validates the stored Seasonvar URL, obtains the title-group lock, and runs the existing forced targeted pipeline.
6. The targeted pipeline refreshes the canonical page and every direct season URL, persisting seasons, episodes, external media metadata, taxonomies, title fields, and derived state in existing transactions.
7. While the state is active, Livewire polls every three seconds and rebuilds the full page from the database.
8. When the catalog revision changes, the outer component rerenders the complete title data and asks the nested player to reload its catalog-backed state.
9. The job marks `completed` after the targeted pipeline finishes with a successful or partial import status. The next poll displays the final local state and stops polling.
10. A failure marks `failed`; the page retains the last successful database state and stops polling.

## Freshness and deduplication

The 15-minute window is per catalog title. It begins only after a completed targeted refresh, not when the job is dispatched. A queued or running state suppresses additional dispatches independently of the freshness timestamp.

Laravel's unique job lock is the second deduplication boundary, not the only source of freshness truth. This covers races between multiple web requests while keeping the user-visible state explicit. State entries and locks use bounded TTLs longer than the configured worker timeout and retry window so abandoned work can recover without permanent suppression.

The successful refresh timestamp must be derived from coordinator state or an existing persisted importer timestamp with an exact title linkage. It must not use unrelated catalog edits as proof that Seasonvar was refreshed.

## Error handling and security

- Anonymous visitors can trigger only a refresh of the already visible, server-bound title.
- Client input cannot choose an arbitrary URL, source page, queue, force option, or import mode.
- Every source URL is normalized and restricted to the configured Seasonvar origin before a remote request.
- Queue dispatch failure leaves the current page usable and records a sanitized failure state.
- Import failure never deletes or replaces the last successfully persisted catalog data as a compensating action.
- Existing HTTP timeouts, retries, crawl delay, snapshot handling, database transactions, media URL validation, and no-video-download rules remain in force.
- Existing title-group locking serializes visitor-triggered and scheduled work for the same title.
- Status polling exposes only a small enum-like state and timestamps required for UI behavior.

## User interface behavior

The initial page is never blocked by the importer. A compact status appears near the title-page controls while background work is relevant. It does not include technical instructions or importer internals.

`wire:poll.3s.visible` runs only during `queued` and `running`. Completed and failed states render once and then remove the polling attribute. Livewire's normal background-tab throttling remains enabled; `.keep-alive` is not used.

All title content is refreshed together. The nested player keeps a stable key during ordinary polls, so current playback is not restarted merely because a status check occurred. It reloads catalog-backed options only after a real revision change.

## Performance

- Public title requests perform bounded local queries and at most one atomic cache-lock attempt.
- No remote request runs in an HTTP or Livewire process.
- Unique per-title jobs prevent repeated queue entries from concurrent visitors.
- The 15-minute success window bounds repeat imports for popular titles.
- Three-second polling is active only while work is outstanding and the component is visible.
- Existing page-builder eager loading and playback query boundaries remain responsible for preventing N+1 queries.
- The targeted pipeline skips sitemap discovery and global maintenance, recommendation rebuilds, and unrelated title work.

## Alternatives rejected

### Reuse the global `RunSeasonvarImport` job

This is smaller but its global unique lock serializes unrelated titles and makes popular-page refreshes compete with the full importer. Its lifecycle also does not expose a precise per-title queued state before the pipeline creates an import run.

### Refresh only `CatalogTitlePlayer`

This preserves the static controller page but leaves title text, poster, taxonomies, counts, and recommendations stale. It does not satisfy the requirement that all refreshed information appear without a reload.

### Reload the browser after import

A browser reload is simpler than a Livewire page shell but interrupts playback and loses the current in-page interaction state.

## Tests

Implementation follows test-first development.

- Coordinator tests cover the 15-minute success window, atomic concurrent suppression, active-state suppression, stale recovery, missing source URL, and dispatch failure.
- Job tests cover queue connection/name, uniqueness by title id, retry/timeout policy, source URL validation, title-group locking, targeted pipeline arguments, and terminal state transitions.
- Importer integration tests use `Http::fake()` and `Http::preventStrayRequests()` to prove that a forced targeted refresh parses the canonical page and all direct season URLs without sitemap discovery.
- Livewire tests cover initial dispatch, no dispatch for fresh titles, locked title id, visibility enforcement, three-second visible polling markup, full-page data changes, terminal polling removal, Russian status text, and sanitized payloads.
- Player tests cover the scoped refresh event, cache reset, valid selection preservation, and fallback when an imported episode or media item is no longer available.
- Existing catalog page, playback, importer, queue, security, and frontend contract tests remain green.

After PHP changes, run `./vendor/bin/pint --dirty --format agent`, then focused PHPUnit tests, the full `php artisan test` suite, and `npm run build` because the title Blade/Livewire markup and Tailwind utility scan change.

## Documentation

Update the importer, queue, frontend/view, UI standards, testing, and maintenance documents identified by `docs/README.md`. The public command contract remains unchanged: `php artisan seasonvar:import` is still the only public Seasonvar import command; the new job and coordinator are internal application boundaries.
