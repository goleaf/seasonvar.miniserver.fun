# Seasonvar Nested Playlist Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Импортировать видео из вложенных Seasonvar-плейлистов и при каждом запуске безопасно повторять ограниченный пакет страниц, требующих внимания.

**Architecture:** `SeasonvarCatalogImporter` рекурсивно разворачивает внешнее JSON-дерево в листовые записи до существующей нормализации и сохранения медиа. `SeasonvarRefreshPlanner` добавляет перед обычными due-кандидатами ограниченный пакет `missing_data`, используя текущий chunk size, индексы и дедупликацию одного запуска.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent, Laravel HTTP fakes, PHPUnit 12.5, SQLite.

## Global Constraints

- Единственная публичная команда импорта остаётся `php artisan seasonvar:import`.
- Внешние URL проверяются существующими Seasonvar/media guards; видео не скачиваются.
- Тесты используют `Http::fake()` и `Http::preventStrayRequests()`.
- Работа выполняется только в существующей ветке `main`; пользовательские незакоммиченные изменения не перезаписываются.

---

### Task 1: Nested playlist regression

**Files:**
- Modify: `tests/Feature/SeasonvarParsePageCommandTest.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`

**Interfaces:**
- Consumes: JSON entries containing `file`, `id`, `title`, or nested `folder` arrays.
- Produces: `flattenSeasonvarPlaylistEntries(array $entries): array` returning leaf entry arrays consumed by `parseSeasonvarPlaylistItem()`.

- [ ] **Step 1: Write the failing feature test**

Add a test that fakes a Seasonvar page with episodes 1 and 101 and a playlist with two top-level `folder` groups. Assert that both decoded MP4 URLs are stored against their corresponding episode IDs.

- [ ] **Step 2: Run the focused test and verify RED**

Run: `php artisan test --filter=test_it_imports_nested_seasonvar_player_playlist_folders`

Expected: FAIL because no `licensed_media` rows are created from nested `folder` entries.

- [ ] **Step 3: Implement recursive leaf extraction**

Add a private typed helper that visits every array entry, appends entries with a string `file`, and recursively visits array-valued `folder`. Make `parseSeasonvarPlaylistItem()` iterate over the flattened leaves.

- [ ] **Step 4: Run the focused playlist tests and verify GREEN**

Run: `php artisan test --filter='SeasonvarParsePageCommandTest|SeasonvarCatalogParserTest'`

Expected: nested and flat playlist tests pass.

### Task 2: Startup attention retry

**Files:**
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`
- Modify: `app/Services/Seasonvar/SeasonvarRefreshPlanner.php`

**Interfaces:**
- Consumes: `pageChunksForImportCycle(int $chunkSize, Carbon $refreshAfter, ?int $importRunId, ?callable $progress)`.
- Produces: the first yielded chunk contains at most `$chunkSize` oldest `missing_data` pages and reports reason `needs_attention`.

- [ ] **Step 1: Write the failing planner test**

Create more fresh `missing_data` pages than chunk size, all with future `retry_after_at`. Assert that a new cycle selects only one chunk before due candidates and emits `needs_attention`.

- [ ] **Step 2: Run the focused test and verify RED**

Run: `php artisan test --filter=test_refresh_planner_retries_a_bounded_attention_batch_on_every_start`

Expected: FAIL because future retry timestamps currently exclude all pages.

- [ ] **Step 3: Implement bounded attention selection**

Query parsed `missing_data` pages through `baseQuery()`, order by `retry_after_at`, `last_imported_at`, and `id`, limit by chunk size, report the selection, register IDs in the existing deduplication set, and yield before current candidate queries.

- [ ] **Step 4: Run importer maintenance tests and verify GREEN**

Run: `php artisan test --filter=SeasonvarImportMaintenanceTest`

Expected: the new startup retry test and existing refresh-priority tests pass.

### Task 3: Verification and live repair

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/performance.md`
- Modify: `docs/DATA_RELATIONS.md`

**Interfaces:**
- Consumes: completed importer behavior.
- Produces: project-specific operational documentation and repaired catalog data for title 7762.

- [ ] **Step 1: Format PHP changes**

Run: `./vendor/bin/pint --dirty --format agent`

Expected: exit code 0.

- [ ] **Step 2: Run focused and broad tests**

Run: `php artisan test --filter='SeasonvarParsePageCommandTest|SeasonvarImportMaintenanceTest|SeasonvarParallelImportTest'`

Then run: `php artisan test`

Expected: 0 failures.

- [ ] **Step 3: Repair the affected title**

Run: `php artisan seasonvar:import 'https://seasonvar.ru/serial-7762-Poka_prohodit_zhizn_psfhncj.html' --force`

Expected: media rows are attached and the source page changes from `missing_data` to `parsed`.

- [ ] **Step 4: Verify database and public response**

Check that catalog title 3972 has one season, 187 episodes, published media for the available episode IDs, empty missing-data flags, and that the public page no longer reports 0 available series.

- [ ] **Step 5: Update importer documentation**

Document nested playlist support and bounded startup attention retries in the three managed project documents without replacing unrelated text.

- [ ] **Step 6: Commit only when the worktree can be made clean safely**

Run `git status --short --branch` and confirm `main`. If unrelated pre-existing changes remain, report them as the project-mandated commit blocker instead of staging or committing them without authority.
