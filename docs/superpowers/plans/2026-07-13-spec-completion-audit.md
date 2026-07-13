# Seasonvar Specification Completion Audit and Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Проверить каждую существующую design-spec и implementation-plan по фактическому коду, закрыть подтвержденные пробелы и завершить работу только после повторной трассировки требований, тестов, runtime-проверок и документации.

**Architecture:** Сохранить текущие границы Laravel-приложения: catalog search остаётся внутри `App\Services\Catalog`, импорт и локальное восстановление metadata — внутри `App\Services\Seasonvar`, публичной командой Seasonvar остаётся только `seasonvar:import`. Сначала исправляется наблюдаемая незавершающаяся queued-finalization, затем добавляется versioned local metadata backfill, после него — отдельный безопасный FTS5 rollout с legacy fallback. Исторические планы не считаются источником runtime-истины: статус подтверждается кодом, миграциями, тестами, документацией и эксплуатационным состоянием.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4.3, SQLite 3.46/FTS5, Redis queues and locks, Blade, Tailwind CSS 4.3, Vite 8.1, PHPUnit 12.5, Laravel Pint 1.29, Playwright CLI.

## Global Constraints

- Работать только в существующей ветке `main`; не создавать branches или worktrees.
- Не добавлять production dependencies и не редактировать `.env`.
- Не выполнять destructive database/queue/cache commands.
- Не скачивать video; сохранять только внешние URL и metadata.
- `php artisan seasonvar:import` остаётся единственной публичной командой импорта Seasonvar.
- Новая maintenance-команда поиска может обслуживать только локальный search index и не импортирует Seasonvar.
- Видимый интерфейс остаётся русским, светлым и без fake content.
- Новейшее прямое требование пользователя об удалении hero на главной имеет приоритет над пунктом hero в visual-system spec; `docs/UI_STANDARDS.md` уже фиксирует актуальное состояние.
- Более поздние утверждённые Livewire/publication/no-outline планы уточняют ранние catalog specs там, где требования конфликтуют.
- Каждый code task выполняется через TDD: RED, минимальная реализация, GREEN, рефакторинг, focused checks.
- PHP-правки форматируются через `./vendor/bin/pint --dirty --format agent`.
- Frontend/Blade/Tailwind changes требуют `npm run build` и responsive browser QA.
- Production migrations, search rebuild и worker restart выполняются только после зелёных isolated checks и с учётом активной очереди.
- После каждого этапа запускать `php artisan project:docs-refresh`, `git diff --check`, проверять `main`, коммитить весь разрешённый scope и оставлять clean worktree.

---

## Audit Baseline

- Текущий инвентарь после появления дополнительных требований во время аудита: 18 файлов `docs/superpowers/specs/*.md` и 30 файлов `docs/superpowers/plans/*.md`.
- Во время closure параллельная задача создала отдельный активный `docs/plans/2026-07-13-cache-performance-architecture.md`. Он не подменяет исторический formal inventory выше; его код, инфраструктурные tests, документация, commit и clean-tree acceptance проверяются дополнительно перед закрытием этого аудита.
- `php artisan test`: 312 tests, 2184 assertions, zero failures.
- `npm run build`: production build successful; emitted local Plyr SVG and only solid/regular FontAwesome fonts.
- `php artisan migrate:status`: все существующие migrations применены; pending migrations отсутствуют.
- `core.hooksPath=.githooks`; branch `main`; до этого плана tree был clean, локальный `main` опережал `origin/main` на commit `2796ed6`.
- `php artisan seasonvar:import --status`: Redis queue доступна, 10 workers запущены, но одновременно видны 4 running queued runs, 0 live claims и 4 reserved finalizer jobs.
- Journal подтверждает повторяющийся timeout: несколько `FinalizeSeasonvarQueuedImport` начинались одновременно и получали `SIGKILL` ровно через 900 секунд.
- Read-only event audit run `#11` показывает 4332 локальных metadata updates, затем 2853 последовательных внешних media checks; finalizer не дошёл до completion до worker timeout.

## Design Specification Traceability

| Design spec | Статус | Подтверждение | Оставшаяся работа |
| --- | --- | --- | --- |
| `2026-07-11-stats-issue-rows-design.md` | Реализована | `CatalogStatsPageBuilder::statsIssueRows()`, `CatalogPageTest::test_stats_issue_rows_merge_multiple_issue_categories`, commit `abed22a` | Только отразить статус в итоговом аудите |
| `2026-07-12-all-seasons-catalog-design.md` | Реализована с позднейшим Livewire-уточнением | `CatalogTitlesRequest`, `CatalogTitlesCriteria`, `CatalogTitleQuery`, `CatalogFacetQuery`, `CatalogSeries`, GET fallback, advanced filter/search/UI tests | Повторная browser/performance matrix после FTS rollout |
| `2026-07-12-catalog-facets-title-metadata-design.md` | Частично реализована | Facets, full title surface, trusted relation parser and shared synchronizer существуют | Нет version columns, `latestSnapshot()` и `SeasonvarCatalogMetadataBackfill`; выполнить Tasks 3-4 |
| `2026-07-12-catalog-search-overhaul-design.md` | Core реализован, rollout незавершён | Normalizer/parser, honest states, exact/legacy query, aliases index и tests существуют | Нет FTS documents/state/triggers/indexer/rebuild/ranked driver/incremental sync/people API/trigram rollout; выполнить Tasks 5-9 |
| `2026-07-12-catalog-visual-system-design.md` | Текущий этап реализован, home hero superseded | Layout, tokens, local assets, card tab-stop, title hero, visual tests и build | Hero намеренно удалён commit `2796ed6`; этапы 2-9 из раздела «Максимальная программа» являются roadmap, не acceptance текущего этапа |
| `2026-07-12-parallel-seasonvar-import-design.md` | Код реализован, runtime completion нарушен | Claims, Redis page jobs, title lock, finalizer, status, tests | Finalizer делает unbounded external backlog и параллельное global maintenance; выполнить Tasks 1-2 |
| `2026-07-12-seasonvar-queue-architecture-design.md` | Реализована, runtime hardening нужен | Typed failure, action, hooks, DTO/status, architecture tests | Устранить 900-second finalizer timeout и concurrent finalizers |
| `2026-07-12-seasonvar-title-group-lock-design.md` | Реализована | Canonical group key, runtime recompute, SQLite IMMEDIATE, focused tests | Нет |
| `2026-07-13-home-latest-updates-grid-design.md` | Реализована | Главная без hero, адаптивная сетка последних обновлений и `CatalogVisualSystemTest` | Нет; более новое прямое требование пользователя об удалении hero имеет приоритет над ранней visual-system spec |
| `2026-07-13-remove-catalog-relations-panel-design.md` | Реализована | Дублирующая панель, panel-only payload и orphaned translations удалены; contextual taxonomy и feature regression сохранены | Нет |
| `2026-07-13-responsive-icon-system-design.md` | Реализована | Все интерфейсные иконки проходят через `x-ui.icon`, геометрия задаётся единым `.ui-icon`, raw `<i>` разрешён только внутри компонента; responsive feature/browser checks выполнены | Нет |
| `2026-07-13-seasonvar-current-season-gap-design.md` | Реализована | Parser regression и importer test | Нет |
| `2026-07-13-seasonvar-large-episode-batch-design.md` | Реализована | 50-row upsert chunks, 2600-episode regression | Нет |
| `2026-07-13-seasonvar-nested-playlist-recovery-design.md` | Реализована, plan checkboxes stale | Recursive `folder` flattening, bounded attention batch, two regressions | Обновить audit status, не переписывая историю RED/GREEN |
| `2026-07-13-seasonvar-queue-retry-lease-design.md` | Реализована | Retry deadline uses max retry/claim window and two regression branches | Нет |
| `2026-07-13-seasonvar-queue-status-active-run-design.md` | Реализована | Dominant active run and active count tests | Нет |
| `2026-07-13-seasonvar-title-page-state-reconciliation-design.md` | Реализована | Synchronizer service, multi-season/lifecycle tests, docs | Нет |
| `2026-07-13-site-footer-redesign-design.md` | Реализована | Трёхчастный Blade footer, semantic feature test и Playwright 390/1440 | Нет |

## Implementation Plan Traceability

| Implementation plan | Фактический статус |
| --- | --- |
| `2026-07-11-stats-issue-rows.md` | Complete; unchecked historical checklist is stale |
| `2026-07-12-all-seasons-catalog.md` | Complete through later catalog/Livewire plans; old unchecked steps are stale or superseded |
| `2026-07-12-catalog-facets-title-metadata.md` | Tasks 1-6 substantially complete; Tasks 7-9 metadata backfill/integration remain open |
| `2026-07-12-catalog-multi-value-filters.md` | Complete |
| `2026-07-12-catalog-search-core.md` | Complete; checklist not updated |
| `2026-07-12-catalog-search-url-pagination.md` | Complete; FTS explicitly deferred |
| `2026-07-12-catalog-visual-system.md` | Complete except home hero deliberately superseded by latest user instruction |
| `2026-07-12-domain-publication-integrity.md` | Complete |
| `2026-07-12-foundational-stability.md` | Complete |
| `2026-07-12-livewire-series-catalog.md` | Complete; checklist not updated |
| `2026-07-12-livewire-title-detail.md` | Complete |
| `2026-07-12-multi-select-catalog-filters-plan.md` | Completed pass; lettered section is advisory improvement backlog |
| `2026-07-12-no-outline-ui-polish-plan.md` | Core complete; remaining numbered section is living QA guidance |
| `2026-07-12-parallel-seasonvar-import.md` | Structural code complete; production finalizer acceptance currently fails |
| `2026-07-12-seasonvar-queue-architecture.md` | Structural code complete; production finalizer acceptance currently fails |
| `2026-07-12-seasonvar-stats-poll-hardening-next-plan.md` | Completed pass verified; future section is conditional backlog, not accepted implementation checklist |
| `2026-07-12-seasonvar-title-group-lock.md` | Complete |
| `2026-07-13-home-latest-updates-grid.md` | Complete |
| `2026-07-13-livewire-player-lifecycle.md` | Complete |
| `2026-07-13-playback-authorization.md` | Complete |
| `2026-07-13-remove-catalog-relations-panel.md` | Complete; checklist synchronized during this audit |
| `2026-07-13-responsive-icon-system.md` | Complete; component/CSS contract, feature tests and responsive browser matrix implemented in `f9cbd5a` |
| `2026-07-13-seasonvar-current-season-gap.md` | Complete |
| `2026-07-13-seasonvar-large-episode-batch.md` | Complete |
| `2026-07-13-seasonvar-nested-playlist-recovery.md` | Complete in code/tests; checklist stale |
| `2026-07-13-seasonvar-queue-retry-lease.md` | Complete |
| `2026-07-13-seasonvar-queue-status-active-run.md` | Complete |
| `2026-07-13-seasonvar-title-page-state-reconciliation.md` | Complete |
| `2026-07-13-site-footer-redesign.md` | Complete; browser QA and commit are recorded |
| `2026-07-13-spec-completion-audit.md` | Active closure plan; implementation Tasks 1-9 complete, Task 10 and production runtime acceptance remain |

---

### Task 1: Bound External Media Health Work Per Finalizer

**Files:**
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- Modify: `config/seasonvar.php`
- Modify: `.env.example`
- Modify: `docs/importer.md`
- Modify: `docs/performance.md`

**Interfaces:**
- `seasonvar.media_check.max_per_cycle` is a hard cap, independent from the query memory chunk size.
- `SeasonvarImportPipeline::refreshMediaBacklog()` selects due rows in stable `id` order and never performs more than the configured hard cap per sync cycle or queued finalizer.
- Progress context reports `chunk_size`, `max_per_cycle`, `selected`, and processed counters without raw URLs.

- [x] Add a failing feature test with more due media rows than the hard cap, `Http::fake()`, and `Http::preventStrayRequests()`; assert only the cap is requested/updated and remaining rows stay due.
- [x] Run `php artisan test --filter=test_it_limits_external_media_health_checks_per_import_cycle` and confirm RED (4 checked instead of 2).
- [x] Add `SEASONVAR_MEDIA_CHECK_MAX_PER_CYCLE` to config/example; default to `20`, bounded to 600 seconds at three 10-second attempts per URL.
- [x] Stop the stable `lazyById()` stream with `take($hardCap)` before chunk processing; retain `chunk_size` only for memory/query batches.
- [x] Run the focused test, surrounding media-health tests and `SeasonvarImportMaintenanceTest` until GREEN.
- [x] Update importer/performance docs with the hard-cap invariant and operational throughput tradeoff.
- [x] Run Pint, docs refresh/check and `git diff --check`.

### Task 2: Serialize Catalog-Wide Queued Finalization

**Files:**
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`
- Modify: `app/Jobs/FinalizeSeasonvarQueuedImport.php`
- Modify: `config/seasonvar.php`
- Modify: `deploy/systemd/seasonvar-import-worker@.service`
- Modify: `.env.example`
- Modify: `docs/architecture.md`
- Modify: `docs/queues.md`
- Modify: `docs/importer.md`
- Modify: `docs/performance.md`

**Interfaces:**
- Per-run `ShouldBeUnique` remains responsible for duplicate jobs of the same run.
- A second Redis atomic lock with one global key serializes catalog-wide storage/media/relation/merge/recommendation maintenance across different runs.
- If the global lock is occupied, the finalizer releases itself with the configured delay without changing run counters or terminal status.
- The lock expiry is longer than one bounded finalizer execution but finite, so `SIGKILL` cannot deadlock all later runs.
- Run state and outstanding claims are checked again after acquiring the global lock.

- [x] Add a failing job test: hold the global lock for run A, invoke finalizer run B with fake queue interactions, assert release delay and zero pipeline calls.
- [x] Add a test proving the lock is released after successful finalization and another run can acquire it.
- [x] Run the lock-contention test and confirm RED (global lock contract absent).
- [x] Implement explicit configured-store lock acquisition/release around `finalizeQueuedRun()` with `try/finally`; do not use default database cache for this lock.
- [x] Recheck run status and claims after lock acquisition to avoid stale work.
- [x] Run `SeasonvarParallelImportTest` and `SeasonvarImportMaintenanceTest`: 54 tests, 298 assertions, GREEN; full suite: 314 tests, 2194 assertions, GREEN.
- [x] Update queue/architecture/importer/performance documentation.
- [x] Commit Tasks 1-2 as one operational reliability change on `main` after all checks pass (`0451f2c`).
- [x] Runtime-аудит выявил, что spec-compatible worker `--memory=256` не повышал PHP `memory_limit=128M`: добавить RED/GREEN infrastructure regression, PHP hard limit `384M`, сохранить recycle threshold `256` и добавить `Restart=always`; установить синхронизированный systemd template.
- [ ] Restart long-lived workers through `php artisan queue:restart`, verify exactly ten active units, zero repeated 900-second finalizer kills, decreasing reserved jobs and terminal completion of runs with zero claims.

### Task 3: Add Versioned Metadata Schema and Model Contracts

**Files:**
- Create: `database/migrations/2026_07_13_160000_add_metadata_versions_to_catalog_import_tables.php`
- Modify: `app/Models/SourcePage.php`
- Modify: `app/Models/CatalogTitle.php`
- Modify: `app/Models/SourcePageSnapshot.php`
- Modify: `config/seasonvar.php`
- Modify: `.env.example`
- Create: `tests/Feature/SeasonvarCatalogMetadataBackfillTest.php`
- Modify: `tests/Unit/EloquentRelationshipTest.php`

**Interfaces:**
- `SeasonvarCatalogParser::METADATA_VERSION` is a positive current parser version.
- Source pages gain `metadata_parser_version`, `metadata_attempted_version`, `metadata_parsed_at`, and JSON `metadata_presence`.
- Titles gain `relation_metadata_version`.
- `SourcePage::latestSnapshot(): HasOne` selects by maximum `captured_at`, then maximum `id`.
- Queue indexes match page type/version/attempt/id and title version/id; snapshots add `(source_page_id, captured_at, id)`.

- [x] Write migration/default/cast/fillable/relation/index tests and confirm RED.
- [x] Create one additive reversible migration with no production backfill.
- [x] Implement model casts, fillable attributes and `latestSnapshot()`.
- [x] Add bounded metadata chunk/hard-limit config keys and `.env.example` entries.
- [x] Run migration/model tests, rollback on an isolated SQLite database, Pint and diff checks.
- [x] Commit the schema/model contract on `main` (`a00ed45`).

### Task 4: Implement and Integrate Local Metadata Backfill

**Files:**
- Create: `app/Services/Seasonvar/SeasonvarCatalogMetadataBackfill.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogParser.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Modify: `app/Services/Seasonvar/SeasonvarRefreshPlanner.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportStorageMaintenance.php`
- Modify: `app/Services/Seasonvar/SeasonvarTitleMerger.php`
- Modify: `app/Services/Seasonvar/SeasonvarUrl.php`
- Modify: `app/Console/Commands/Concerns/OutputsSeasonvarProgress.php`
- Modify: `tests/Feature/SeasonvarCatalogMetadataBackfillTest.php`
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`
- Modify: `tests/Feature/SeasonvarParsePageCommandTest.php`
- Modify: `tests/Feature/SeasonvarTitleMergeTest.php`
- Modify: `tests/Unit/SeasonvarImportStorageMaintenanceTest.php`
- Modify: `tests/Unit/SeasonvarCatalogParserTest.php`

**Interfaces:**
- `SeasonvarCatalogMetadataBackfill::run(?callable $progress): array{pages_checked:int,pages_updated:int,titles_checked:int,titles_updated:int,relations_attached:int,failed:int}`.
- Local backfill performs no HTTP, has per-cycle page/title hard limits, and uses per-record database transactions.
- `metadata_presence` stores only allowlisted state values: `present`, `rejected_invalid`, `absent_in_source`.
- Successful local/remote parse advances current versions atomically; deterministic invalid snapshot advances only attempted version; infrastructure failure advances no version.
- `stale_metadata` remote planner reason excludes pages still eligible for an unattempted retained snapshot.

- [x] Add RED tests for latest-snapshot ordering, idempotency, season URL-hash title resolution, media-derived translation, presence precedence, invalid snapshot fairness, hard limits, rollback, zero HTTP and missing snapshot behavior.
- [x] Implement bounded local parsing before transaction and shared relation synchronization inside transaction.
- [x] Add RED integration tests for unchanged fast path, successful remote version advancement, planner ordering, snapshot retention, merge minimum version, progress counters and HTTPS-only source URLs.
- [x] Integrate backfill after retention maintenance and before remote selection/global derived work in sync and queued finalization.
- [x] Preserve the snapshot selected by latest `captured_at`; preserve minimum relation version during merge.
- [x] Run metadata tests, importer regression tests, Pint, isolated migrate/rollback/migrate and docs refresh.
- [x] Commit the local metadata recovery milestone on `main` (`a00ed45`, completed integration in `2b868ad`).

### Task 5: Add Additive FTS5 Schema and Index State

**Files:**
- Create: `database/migrations/2026_07_13_170000_create_catalog_search_index.php`
- Create: `app/Models/CatalogTitleSearchDocument.php`
- Create: `app/Models/CatalogSearchIndexState.php`
- Create: `app/Enums/CatalogSearchIndexStatus.php`
- Create: `tests/Feature/CatalogSearchIndexSchemaTest.php`
- Modify: `tests/Unit/EloquentRelationshipTest.php`

**Interfaces:**
- `catalog_title_search_documents.catalog_title_id` is the primary foreign key with cascade delete.
- Weighted columns: title, original title, aliases, transliteration, people, taxonomies, description, suggestion names, exact normalized keys, fingerprint, timestamps.
- `catalog_title_search_fts` is external-content FTS5 using `unicode61 remove_diacritics 2`.
- Insert/update/delete triggers keep FTS rows synchronized with document rows.
- Singleton state stores version, `building|ready|stale|failed`, source/document counts, checkpoint ID and completion/error timestamps without public exposure.

- [x] Write RED schema/trigger/cascade/state tests on SQLite FTS5.
- [x] Implement reversible migration; `down()` drops triggers and virtual table before ordinary tables.
- [x] Implement models/enums/casts and relationships.
- [x] Verify isolated migrate/rollback/migrate, trigger synchronization, cascade delete, focused tests and Pint.
- [x] Commit the additive search schema milestone (`2b868ad`).

### Task 6: Build Search Documents, Indexer and Resumable Rebuild

**Files:**
- Create: `app/Services/Catalog/Search/CatalogSearchDocumentBuilder.php`
- Create: `app/Services/Catalog/Search/CatalogSearchIndexer.php`
- Create: `app/Console/Commands/RebuildCatalogSearch.php`
- Create: `tests/Unit/CatalogSearchDocumentBuilderTest.php`
- Create: `tests/Feature/CatalogSearchIndexerTest.php`
- Create: `tests/Feature/RebuildCatalogSearchCommandTest.php`
- Modify: `routes/console.php` only if command discovery is not automatic
- Modify: `docs/catalog-search.md`

**Interfaces:**
- Document builder receives an eagerly loaded title and emits only safe searchable text plus deterministic fingerprint.
- Indexer accepts title IDs or a `chunkById` rebuild, eager-loads all ten relations/aliases, and upserts only changed fingerprints.
- `catalog:search-rebuild --chunk=200` refuses active import runs, resumes from checkpoint and marks `ready` only when counts and FTS integrity pass.
- Failed rebuild records sanitized state and keeps legacy search active.

- [x] Add RED builder tests for weights/transliteration/no raw URL or importer state/fingerprint stability.
- [x] Add RED indexer tests for idempotent bulk updates, trigger visibility and bounded eager loading.
- [x] Add RED command tests for active-import refusal, checkpoint resume, count mismatch, integrity failure and successful ready state.
- [x] Implement builder, indexer and command with no production backfill in migration.
- [x] Run focused tests, command on isolated fixture DB, Pint and update search documentation.
- [x] Commit the rebuild milestone (`2b868ad`).

### Task 7: Synchronize Search Index With Catalog Writes

**Files:**
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- Modify: `app/Services/Seasonvar/SeasonvarTitleMerger.php`
- Modify: `app/Services/Catalog/CatalogAdministrationService.php`
- Modify: `app/Services/Catalog/Search/CatalogSearchIndexer.php`
- Modify: `tests/Feature/SeasonvarParsePageCommandTest.php`
- Modify: `tests/Feature/SeasonvarTitleMergeTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `tests/Feature/CatalogAdministrationTest.php` if administration coverage is extracted from `CatalogPageTest`

**Interfaces:**
- Import collects changed title IDs and indexes after catalog transaction commit.
- Targeted URL import flushes the one changed canonical title.
- Merger deletes duplicate documents by cascade and reindexes canonical title after commit.
- Admin metadata/relation changes reindex the affected title after successful transaction.
- Incremental failure marks state stale and never hides titles because search falls back to legacy.

- [x] Add RED tests for importer/direct/metadata-backfill/merge/admin synchronization and unchanged-page no-op.
- [x] Implement post-transaction/batch indexing at existing service boundaries; do not add observers.
- [x] Add failure test proving sanitized stale state plus legacy visibility.
- [x] Run catalog/admin/importer focused suites and Pint.
- [x] Commit incremental synchronization (`2b868ad`, expanded regression coverage in `6efa6fd`).

### Task 8: Use Ranked FTS With Safe Legacy Fallback

**Files:**
- Create: `app/Services/Catalog/Search/CatalogTitleSearch.php`
- Modify: `app/Services/Catalog/CatalogTitleQuery.php`
- Modify: `app/Services/Catalog/CatalogTitlesCriteria.php`
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php`
- Modify: `app/Services/Catalog/CatalogFacetQuery.php`
- Modify: `app/Enums/CatalogSort.php`
- Modify: `app/View/ViewModels/CatalogTitlesViewModel.php`
- Modify: `tests/Feature/CatalogSearchPageTest.php`
- Modify: `tests/Feature/CatalogAdvancedFilterTest.php`
- Modify: `tests/Unit/CatalogTitlesViewModelTest.php`

**Interfaces:**
- FTS is used only for matching parser/index versions with `ready` state; all other states use current legacy search.
- Candidate subquery is shared by results, total, years and relation facets.
- Ranking order is exact normalized title, original title, alias, weighted BM25, `indexed_at DESC`, ID DESC.
- Default sort with active query is `relevance`; explicit sorts remain deterministic.

- [x] Add RED ranking corpus tests for title/original/alias/person/taxonomy/description/year/short/punctuation/transliteration.
- [x] Add RED stale/building/failed fallback tests and shared-facet candidate tests.
- [x] Implement parameterized FTS expression and BM25 weights without raw user SQL.
- [x] Inspect `EXPLAIN QUERY PLAN`; candidate IDs remain a SQL subquery and use the FTS virtual-table index without PHP materialization.
- [x] Run search/facet/request/page tests and Pint.
- [x] Commit ranked FTS activation (`2b868ad`).

### Task 9: Complete Search UI, People Lookup, Suggestions and QA

**Files:**
- Create: `app/Http/Requests/CatalogPeopleLookupRequest.php`
- Create: `app/Http/Controllers/Api/CatalogPeopleLookupController.php`
- Create: `app/Http/Resources/CatalogPersonOptionResource.php`
- Modify: `routes/api.php`
- Create: `app/Services/Catalog/Search/CatalogSearchSuggestion.php`
- Modify: `app/Livewire/CatalogSeries.php`
- Modify: `app/Livewire/Forms/CatalogSeriesFilters.php`
- Modify: `resources/views/catalog/titles.blade.php`
- Modify: `resources/js/app.js`
- Modify: `resources/css/app.css`
- Modify: `tests/Feature/ApiCatalogTitleTest.php`
- Modify: `tests/Feature/CatalogSearchPageTest.php`
- Modify: `tests/Feature/CatalogVisualSystemTest.php`
- Modify: `docs/catalog-search.md`
- Modify: `docs/forms.md`
- Modify: `docs/frontend.md`
- Modify: `docs/UI_STANDARDS.md`

**Interfaces:**
- Actor/director lookup accepts only allowlisted type and normalized 2-80 character query, returns at most 20 public `slug`, `name`, and count values via API Resource.
- Mobile filters use an accessible native dialog with Escape/focus return; GET fallback remains functional.
- Suggestions use bounded trigram lookup only on true zero, never change result count/canonical URL, and render under `Возможно, подойдет`.
- One catalog search landmark, one title tab-stop, 44x44 targets, no overflow at 320px.

- [x] Add RED API validation/resource/boundary tests.
- [x] Add RED UI contract and suggestion-state tests.
- [x] Implement lookup, combobox cancellation/debounce/keyboard behavior, native mobile dialog and compact mobile rows.
- [x] Implement bounded trigram suggestion service against normalized document names.
- [x] Run focused PHP tests, `npm run build`, dependency audits and docs refresh.
- [x] Run Playwright at 320x720, 390x844, 768x1024 and 1440x1200 for normal/filtered/insufficient/zero/suggestion/pagination/people lookup/back-forward states; report is stored under ignored `output/playwright/`.
- [x] Commit the search UI milestone (`6efa6fd`; final compact-mobile refinements in `93e1d8e`).

### Task 10: Final Cross-Spec Verification and Closure

**Files:**
- Modify: `README.md`
- Modify: `docs/architecture.md`
- Modify: `docs/CODE_STANDARDS.md`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/performance.md`
- Modify: `docs/testing.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: this plan

- [ ] Re-run a requirement-by-requirement audit of all 18 design specs and 30 implementation plans; every remaining item must be marked complete, superseded with an explicit newer source, advisory roadmap, or blocked by an external operational prerequisite.
- [ ] Run `./vendor/bin/pint --dirty --format agent`.
- [ ] Run every focused suite named in Tasks 1-9.
- [ ] Run `php artisan test` and `./vendor/bin/phpunit`; both must report zero failures.
- [ ] Run `npm run build` and inspect emitted assets for no CDN/brand/v4 font regression.
- [ ] Run `php artisan project:docs-refresh` and `php artisan project:docs-refresh --check`.
- [ ] Run isolated full migrations and rollback for new additive migrations; never touch production data destructively.
- [ ] After active importer/finalizer completion and backup, apply production migrations and run `catalog:search-rebuild --chunk=200`; verify index state ready, counts equal and FTS integrity passes.
- [ ] Measure acceptance search corpus and warm SQLite p95 targets on a production-scale copy.
- [ ] Run full Playwright route/viewport matrix, collect console/network/accessibility/overflow evidence and keep artifacts under ignored `output/playwright/`.
- [ ] Run `git diff --check`, inspect full diff, verify branch `main`, commit all intended changes, push without force only after verification, and confirm clean synchronized worktree.
- [ ] Mark this plan complete only when no implementation or operational acceptance requirement remains.

## Self-Review

- Every design spec and implementation plan has one explicit audit classification.
- Confirmed open work is assigned to a task with exact files, interfaces, RED/GREEN checks, documentation and operational verification.
- Current user instructions supersede the historical home hero requirement explicitly; no other incomplete acceptance criterion is silently reclassified.
- Runtime evidence is included: passing tests alone do not close the queued-import specs while finalizers time out.
- No placeholder, TODO, abbreviated code body, unspecified file or omitted verification step is used.
