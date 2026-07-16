# HDRezka Editorial Collections Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Project rules prohibit sub-agent/worktree execution and require the existing `main` branch.

**Goal:** Синхронизировать все подборки HDRezka в существующий домен редакционных коллекций, безопасно сопоставить локальные тайтлы, хранить локальные WebP-обложки и использовать membership как recommendation signal.

**Architecture:** Один последовательный provider pipeline читает allowlisted HTML с bounded HTTP, сохраняет source provenance, применяет только уверенные match-результаты в `CatalogCollection`, пишет нормализованные recommendation signals и запрашивает существующий all-public warm. Публичные HTTP-запросы работают только с локальной БД и private cover delivery.

**Tech Stack:** PHP 8.5, Laravel 13.20, Eloquent/SQLite, Laravel HTTP client, DOM/XPath, GD WebP, Redis locks/queue, Memcached-compatible tiered cache, PHPUnit 12.5, Livewire 4, Tailwind CSS 4.3.

## Global Constraints

- Работать только в существующей ветке `main`; не создавать branch/worktree.
- Не добавлять production dependencies и не редактировать `.env`.
- `php artisan seasonvar:import` остаётся единственной публичной командой импорта Seasonvar.
- Не скачивать видео и фильмовые постеры; загружать только bounded collection covers.
- Видимый интерфейс и console output — на русском языке.
- Не выполнять network/image work внутри DB transaction.
- Не прикреплять `ambiguous`/`unmatched` source rows.
- Partial/failed run не выполняет destructive stale cleanup.
- После PHP-правок запускать `./vendor/bin/pint --dirty --format agent`.
- После пользовательского изменения обновить owner docs, `CHANGELOG.md` и `README.md`; управляемые блоки обновлять только `php artisan project:docs-refresh`.

---

## File Map

**Create**

- `database/migrations/2026_07_16_250000_create_catalog_collection_source_sync.php` — additive source/run/item schema и exact-name indexes.
- `app/Enums/CatalogCollectionSyncStatus.php` — terminal/run states.
- `app/Enums/CatalogCollectionSourceMatchStatus.php` — matched/ambiguous/unmatched.
- `app/Models/CatalogCollectionSyncRun.php`, `CatalogCollectionSource.php`, `CatalogCollectionSourceItem.php` — casts/relationships/scopes only.
- `app/DTOs/HdRezkaCollectionDefinition.php`, `HdRezkaCollectionItemData.php`, `CatalogCollectionSourceMatch.php`, `CatalogCollectionSyncResult.php` — immutable boundaries.
- `app/Services/Collections/Import/HdRezkaCollectionUrlGuard.php` — exact host/path normalization.
- `app/Services/Collections/Import/HdRezkaCollectionParser.php` — index/page/detail parsing.
- `app/Services/Collections/Import/HdRezkaCollectionMatcher.php` — deterministic candidate resolution.
- `app/Services/Collections/Import/HdRezkaCollectionCoverImporter.php` — bounded source cover to private WebP.
- `app/Services/Collections/Import/HdRezkaCollectionReconciler.php` — source/collection/item transaction.
- `app/Services/Collections/Import/HdRezkaCollectionSignalSynchronizer.php` — provider-scoped signals.
- `app/Services/Collections/Import/HdRezkaCollectionSyncService.php` — orchestration and run lifecycle.
- `app/Console/Commands/SyncHdRezkaCollections.php` — thin Artisan transport.
- Focused PHPUnit tests and synthetic HTML fixtures listed below.

**Modify**

- `app/Models/CatalogCollection.php`, `CatalogTitle.php` — relationships only.
- `app/Services/Catalog/CatalogRecommendationCandidateGenerator.php` — collection signal candidate keys.
- `app/Services/Catalog/CatalogRecommendationPairScorer.php` — capped collection source score.
- `app/Services/Catalog/CatalogTitleRecommendationBuilder.php` — load `editorial_collection` signals.
- `app/Services/Catalog/CatalogRecommendationPresenter.php`, `app/Enums/CatalogRecommendationReason.php`, `lang/{ru,en}/recommendations.php` — truthful reason label.
- `app/View/ViewModels/CatalogCollectionCardViewModel.php`, `resources/views/components/collections/collection-card.blade.php` — source editorial visual badge using already prepared state.
- `routes/console.php`, `.env.example`, `config/catalog-collection-imports.php` — schedule and safe configuration.
- Owner documentation and changelogs.

---

### Task 1: Add source-sync schema and model contracts

**Files:**

- Create migration, two enums and three models from the File Map.
- Modify `app/Models/CatalogCollection.php` and `app/Models/CatalogTitle.php`.
- Test: `tests/Feature/HdRezkaCollectionSourceSchemaTest.php`.

**Interfaces:**

- Produces `CatalogCollectionSource::collection()`, `items()`, `runs()` and `CatalogCollectionSourceItem::catalogTitle()`.
- Produces enum values `running|completed|partial|failed` and `matched|ambiguous|unmatched`.

- [ ] **Step 1: Write the failing schema test**

```php
public function test_source_schema_has_identity_reconciliation_and_match_indexes(): void
{
    $this->assertTrue(Schema::hasColumns('catalog_collection_sources', [
        'provider', 'source_key', 'catalog_collection_id', 'source_path',
        'cover_path', 'cover_content_hash', 'last_successful_sync_at',
    ]));
    $this->assertTrue(Schema::hasColumns('catalog_collection_source_items', [
        'catalog_collection_source_id', 'source_item_key', 'source_title',
        'normalized_title_key', 'source_year', 'source_type', 'countries',
        'match_status', 'catalog_title_id', 'match_method', 'match_confidence',
        'match_reasons', 'last_seen_run_id', 'source_position',
    ]));
    $this->assertTrue(Schema::hasColumns('catalog_collection_sync_runs', [
        'provider', 'status', 'counters', 'error_summary', 'started_at', 'completed_at',
    ]));
}
```

- [ ] **Step 2: Run the test and confirm missing-table failure**

Run: `php artisan test --filter=HdRezkaCollectionSourceSchemaTest`

Expected: FAIL because `catalog_collection_sources` does not exist.

- [ ] **Step 3: Implement additive schema**

Use unique `(provider, source_key)`, unique `catalog_collection_id`, unique `(catalog_collection_source_id, source_item_key)`, the four indexes from the design, nullable FKs with `nullOnDelete()` where source audit must survive a deleted title, JSON casts for counters/countries/reasons, and reversible `down()`.

Add exact indexes:

```php
Schema::table('catalog_title_search_documents', function (Blueprint $table): void {
    $table->index(['normalized_title_key', 'catalog_title_id'], 'catalog_search_docs_title_key_idx');
    $table->index(['normalized_original_title_key', 'catalog_title_id'], 'catalog_search_docs_original_key_idx');
});
```

- [ ] **Step 4: Run focused schema/model tests**

Run: `php artisan test --filter=HdRezkaCollectionSourceSchemaTest`

Expected: PASS.

---

### Task 2: Guard URLs and parse index, pages and detail JSON-LD

**Files:**

- Create the four DTOs and `HdRezkaCollectionUrlGuard.php`, `HdRezkaCollectionParser.php`.
- Create `tests/Fixtures/hdrezka/collections-index.html`, `collection-page-1.html`, `collection-page-2.html`, `title-detail.html`.
- Test: `tests/Unit/HdRezkaCollectionUrlGuardTest.php`, `tests/Unit/HdRezkaCollectionParserTest.php`.

**Interfaces:**

- `HdRezkaCollectionUrlGuard::absolute(string $urlOrPath, string $purpose): string` throws `InvalidArgumentException` for off-host/scheme/port/path.
- `HdRezkaCollectionParser::collections(string $html): array` returns `list<HdRezkaCollectionDefinition>`.
- `HdRezkaCollectionParser::page(string $html, string $collectionPath, int $page): array{items:list<HdRezkaCollectionItemData>,next_path:?string}`.
- `HdRezkaCollectionParser::detail(string $html): array{original_title:?string,year:?int,type:?string,genres:list<string>}`.

- [ ] **Step 1: Write URL and parser tests**

```php
public function test_guard_accepts_only_expected_hdrezka_paths(): void
{
    $guard = app(HdRezkaCollectionUrlGuard::class);
    $this->assertSame('https://hdrezka.my/collections.html', $guard->absolute('/collections.html', 'index'));
    $this->expectException(InvalidArgumentException::class);
    $guard->absolute('https://example.test/collections.html', 'index');
}

public function test_parser_returns_stable_items_and_next_page(): void
{
    $result = app(HdRezkaCollectionParser::class)->page(
        file_get_contents(base_path('tests/Fixtures/hdrezka/collection-page-1.html')),
        '/xfsearch/collections/films/',
        1,
    );
    $this->assertSame('668', $result['items'][0]->sourceItemKey);
    $this->assertSame('Муфаса: Король Лев', $result['items'][0]->title);
    $this->assertSame(2024, $result['items'][0]->year);
    $this->assertSame('/xfsearch/collections/films/page/2/', $result['next_path']);
}
```

- [ ] **Step 2: Run and confirm class-not-found failures**

Run: `php artisan test --filter='HdRezkaCollection(URLGuard|Parser)Test'`

Expected: FAIL before classes exist.

- [ ] **Step 3: Implement DOM/XPath parsers and normalization**

Use `DOMDocument`, `DOMXPath`, `CatalogSearchNormalizer::display/key`, numeric ID from `/{id}-{slug}.html`, year from `card_item__misc`, type from category icon and strictly normalized relative paths. Reject empty names, missing IDs, repeated/self next paths and invalid UTF-8.

- [ ] **Step 4: Run parser tests**

Expected: all URL/parser tests PASS with `Http::preventStrayRequests()` unused because these units parse strings only.

---

### Task 3: Implement deterministic title matcher

**Files:**

- Create `app/Services/Collections/Import/HdRezkaCollectionMatcher.php`.
- Test: `tests/Feature/HdRezkaCollectionMatcherTest.php`.

**Interfaces:**

- `match(HdRezkaCollectionItemData $item, ?array $detail = null): CatalogCollectionSourceMatch`.
- `CatalogCollectionSourceMatch` exposes `status`, nullable `catalogTitleId`, `method`, integer `confidence`, and safe reason array.

- [ ] **Step 1: Write failing match matrix**

Cover exact primary+year, original title, alias, year mismatch, type mismatch, country tie-break, ambiguous equal candidates and no candidate.

```php
public function test_exact_title_and_year_matches_one_local_title(): void
{
    $title = CatalogTitle::factory()->create(['title' => 'Муфаса: Король Лев', 'year' => 2024, 'type' => 'cartoon']);
    $this->indexTitle($title);
    $match = app(HdRezkaCollectionMatcher::class)->match(new HdRezkaCollectionItemData(
        sourceItemKey: '668', title: 'Муфаса: Король Лев', normalizedTitleKey: 'муфаса король лев',
        year: 2024, type: 'cartoon', countries: ['сша'], detailPath: '/668-mufasa.html',
        page: 1, position: 1,
    ));
    $this->assertSame(CatalogCollectionSourceMatchStatus::Matched, $match->status);
    $this->assertSame($title->id, $match->catalogTitleId);
}
```

- [ ] **Step 2: Run and confirm matcher failure**

Run: `php artisan test --filter=HdRezkaCollectionMatcherTest`

- [ ] **Step 3: Implement indexed candidate query and scoring**

Use exact normalized search-document columns plus indexed alias hash, eager-load only `id,title,original_title,type,year`, countries and aliases needed for confirmation. Hard-reject explicit year mismatch; score primary 100, original 95, alias 90, exact year 40, compatible type 20, country overlap 10, detail original 25 and detail genre overlap up to 15. Require score at least 130 and lead at least 20; otherwise return ambiguous/unmatched.

- [ ] **Step 4: Run matcher and query-budget tests**

Expected: PASS and bounded query count independent of catalog size.

---

### Task 4: Convert and store collection covers as private WebP

**Files:**

- Create `HdRezkaCollectionCoverImporter.php`.
- Test: `tests/Feature/HdRezkaCollectionCoverImporterTest.php`.

**Interfaces:**

- `prepare(string $sourceUrl): ?PreparedImportedCollectionCover` performs bounded HTTP/decode/resize outside transaction.
- `apply(CatalogCollection $collection, PreparedImportedCollectionCover $cover): bool` stores `catalog-collections/{uuid}/imported/{sha256}.webp`, atomically updates existing cover columns and schedules deletion of the previous imported file after commit.

- [ ] **Step 1: Write fake-storage/fake-HTTP tests**

Assert WebP MIME, max dimensions, no duplicate version bump for identical bytes, old-file cleanup on replacement and previous cover retention after invalid image.

- [ ] **Step 2: Run and confirm missing-service failure**

Run: `php artisan test --filter=HdRezkaCollectionCoverImporterTest`

- [ ] **Step 3: Implement bounded stream and GD conversion**

Read at most configured `max_source_bytes`, reject oversized `Content-Length`, decode with `imagecreatefromstring`, preserve aspect ratio, never upscale, use `imagewebp($target, null, quality)`, verify output dimensions/MIME, and write to `config('uploads.disk')` with private visibility.

- [ ] **Step 4: Run cover tests**

Expected: PASS using `Storage::fake('uploads')` and `Http::preventStrayRequests()`.

---

### Task 5: Reconcile source rows, editorial collections and membership

**Files:**

- Create `HdRezkaCollectionReconciler.php` and `HdRezkaCollectionSignalSynchronizer.php`.
- Modify `CatalogCollection` relationships.
- Test: `tests/Feature/HdRezkaCollectionReconciliationTest.php`.

**Interfaces:**

- `reconcile(CatalogCollectionSyncRun $run, HdRezkaCollectionDefinition $definition, array $items, bool $complete): array{collection_id:int,created:bool,membership_changed:bool,matched:int,ambiguous:int,unmatched:int,removed:int}`.
- `synchronizeForRun(CatalogCollectionSyncRun $run): array{upserted:int,deleted:int}` runs destructive stale-signal cleanup only for completed runs.

- [ ] **Step 1: Write failing reconciliation cases**

Test initial create, exact repeat, order update, duplicate title from two remote cards, disappeared item, partial failure retention, hidden collection moderation preservation and source-only signal cleanup.

- [ ] **Step 2: Run and confirm failure**

Run: `php artisan test --filter=HdRezkaCollectionReconciliationTest`

- [ ] **Step 3: Implement short transaction**

Resolve/create source by `(provider,source_key)`, create editorial collection with `owner_id=null`, preserve local description/SEO/featured/moderation, bulk-upsert source rows, build deduplicated matched membership ordered by minimal source position, compare current IDs/positions before writes, increment `content_version` once only for material change, and call `CatalogCollectionCacheInvalidator` after commit.

- [ ] **Step 4: Implement recommendation signals**

Upsert `source=hdrezka`, `signal_type=editorial_collection`, `signal_key={source_key}`, configured weight, `observed_at=now()`. Delete stale provider signals only after a completed full run and only when no current matched source item proves membership.

- [ ] **Step 5: Run reconciliation tests**

Expected: PASS with idempotent versions and no destructive partial cleanup.

---

### Task 6: Orchestrate full pagination and expose Artisan/scheduler controls

**Files:**

- Create `HdRezkaCollectionSyncService.php`, `SyncHdRezkaCollections.php`, `config/catalog-collection-imports.php`.
- Modify `.env.example`, `routes/console.php`.
- Test: `tests/Feature/SyncHdRezkaCollectionsCommandTest.php`, `tests/Feature/HdRezkaCollectionSyncTest.php`.

**Interfaces:**

- `sync(bool $dryRun = false, bool $retryUnresolved = false, ?callable $progress = null): CatalogCollectionSyncResult`.
- Command signature: `catalog-collections:sync-hdrezka {--dry-run} {--retry-unresolved} {--limit-collections=}`.

- [ ] **Step 1: Write fake two-page full-sync test**

Use `Http::fake()` for index, page 1, page 2 and cover; assert all requested URLs, three source items, counters, one collection, matched membership, local WebP, completed run and recommendation/warm effects.

- [ ] **Step 2: Write partial and loop-guard tests**

Assert page-2 500 produces partial without stale deletion; repeated next URL and configured page/item limits terminate with safe failure codes.

- [ ] **Step 3: Implement orchestration**

Acquire `Cache::store(config('catalog-collection-imports.lock_store'))->lock('catalog-collections:sync:hdrezka', lock_seconds)`, create run, fetch/parse index, process each collection independently, prepare cover outside transaction, aggregate bounded counters, mark completed/partial/failed and release lock in `finally`.

- [ ] **Step 4: Add thin command and daily schedule**

Schedule at `03:37`, `withoutOverlapping(360)`, `onOneServer()`, conditional on `catalog-collection-imports.hdrezka.enabled`. Add `.env.example` keys for enabled, delay, page/item/response/cover limits, WebP dimensions/quality and lock store.

- [ ] **Step 5: Run command/sync tests**

Run: `php artisan test --filter='(SyncHdRezkaCollectionsCommand|HdRezkaCollectionSync)Test'`

Expected: PASS and no stray network.

---

### Task 7: Add editorial-collection signal to recommendation v6

**Files:**

- Modify recommendation generator/scorer/builder/presenter/enum/config/lang files from File Map.
- Test existing/new `CatalogRecommendationCandidateGeneratorTest`, `CatalogRecommendationPairScorerTest`, `CatalogTitleRecommendationBuilderTest`, `CatalogRecommendationListTest`.

**Interfaces:**

- Candidate keys include only `editorial_collection:*` positive signals.
- Pair scorer emits `collection_signal` with capped source score and diversity feature.
- Presenter maps it to `CatalogRecommendationReason::SharedEditorialCollection` and Russian label `Одна подборка`.

- [ ] **Step 1: Add failing generator/scorer tests**

```php
public function test_shared_editorial_collection_is_a_bounded_candidate_signal(): void
{
    $generator = new CatalogRecommendationCandidateGenerator();
    $generator->add(['id' => 1, 'signals' => ['editorial_collection:love' => 300]]);
    $generator->add(['id' => 2, 'signals' => ['editorial_collection:love' => 300]]);
    $this->assertSame([2], $generator->idsFor(['id' => 1, 'signals' => ['editorial_collection:love' => 300]], 10));
}
```

- [ ] **Step 2: Run and confirm signal is ignored**

Run: `php artisan test --filter='CatalogRecommendation(CandidateGenerator|PairScorer)Test'`

- [ ] **Step 3: Implement bounded scoring and label**

Index at most 32 editorial collection signal keys per profile; compute score from configured weight with size/frequency penalty and `collection_score_cap`; keep playable-media/minimum-score gates unchanged; distinguish `collection_signal` from imported provider relation.

- [ ] **Step 4: Run recommendation suite**

Run: `php artisan test --filter='Catalog(Recommendation|TitleRecommendation)'`

Expected: PASS, including existing quality gate fixtures.

---

### Task 8: Polish local collection presentation without new runtime queries

**Files:**

- Modify collection card ViewModel/component and admin collection page only if all required state is prepared.
- Test: `tests/Feature/HdRezkaCollectionPresentationTest.php`, existing `CatalogVisualSystemTest.php`.

**Interfaces:**

- Visitor sees local cover, name, visible count and editorial badge.
- Admin sees last sync counters from one bounded query; no raw URL/HTML.

- [ ] **Step 1: Write failing public/admin presentation tests**

Assert local cover route, Russian editorial label, no source URL, responsive grid classes and sanitized sync summary.

- [ ] **Step 2: Implement smallest Blade/ViewModel change**

Reuse `x-ui.poster-frame`, `x-ui.status-pill`, `CatalogCollectionCardViewModel`; keep 16:9 ratio and `sm:grid-cols-2 xl:grid-cols-3`; do not add `@php`, inline CSS/JS or database queries.

- [ ] **Step 3: Run UI feature tests and build**

Run: `php artisan test --filter='(HdRezkaCollectionPresentation|CatalogVisualSystem)Test'`

Run: `npm run build`

Expected: PASS and successful Vite build.

---

### Task 9: Documentation, dry-run, real sync and verification

**Files:**

- Modify `README.md`, `CHANGELOG.md`, `docs/architecture.md`, `docs/DATA_RELATIONS.md`, `docs/importer.md`, `docs/caching.md`, `docs/performance.md`, `docs/development.md`, `docs/deployment.md`, `.env.example`.

- [ ] **Step 1: Document exact operation and visitor outcome**

Add Russian quick commands, env controls, schedule, matching fail-closed behavior, WebP storage, recommendation signal, Redis/Memcached warm flow and rollback. Keep `История обновлений для посетителей` as the final H2 in README.

- [ ] **Step 2: Refresh/check managed docs**

Run: `php artisan project:docs-refresh`

Run: `php artisan project:docs-refresh --check`

Expected: both succeed without manual edits inside managed blocks.

- [ ] **Step 3: Run formatter and focused suite**

Run: `./vendor/bin/pint --dirty --format agent`

Run: `php artisan test --filter=HdRezka`

Run: `php artisan test --filter='CatalogRecommendation(CandidateGenerator|PairScorer)Test'`

Expected: PASS.

- [ ] **Step 4: Run broad verification**

Run: `php artisan test`

Run: `npm run build`

Expected: PASS. If unrelated pre-existing dirty code fails, record exact failure and do not rewrite unrelated work.

- [ ] **Step 5: Run source dry-run**

Run: `HDREZKA_COLLECTION_SYNC_ENABLED=1 php artisan catalog-collections:sync-hdrezka --dry-run`

Expected: all discovered collections/pages counted, zero DB membership/cover mutations, non-zero match diagnostics.

- [ ] **Step 6: Run real sync conservatively**

Run: `HDREZKA_COLLECTION_SYNC_ENABLED=1 php artisan catalog-collections:sync-hdrezka`

Expected: completed or explicitly partial run with no hidden failures; local collection/source rows and WebP files created.

- [ ] **Step 7: Rebuild/warm and inspect metrics**

Run: `php artisan cache:warm-catalog --scope=all-public --dry-run`

Run: `php artisan cache:warm-catalog --scope=all-public --queue --refresh`

Expected: collection directory/detail targets included and jobs placed on configured Redis queue.

- [ ] **Step 8: Browser QA**

Check `/collections` and one imported detail at phone, tablet and desktop widths; assert no console/network errors, WebP cover response, stable cards and no calls to `hdrezka.my` from the browser.

- [ ] **Step 9: Commit on `main` after dirty-tree resolution**

Run `git status --short --branch`; commit only after all authorized changes are staged, README policy passes, and no unrelated dirty/untracked files remain. Do not bypass `.githooks/pre-commit`.

---

## Plan Self-Review

- Spec coverage: schema, crawl, pagination, matching, WebP, local collections, recommendation scoring, cache warming, UI, schedule, docs and live verification each have an owning task.
- Placeholder scan: passed; every change step names concrete behavior and verification.
- Type consistency: DTO/service names and status values are identical across producer/consumer tasks.
- Scope: HDRezka is collection metadata/provenance only; it does not become a second catalog/video importer.
