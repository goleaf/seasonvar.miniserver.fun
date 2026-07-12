# Seasonvar Title Page State Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep every successfully imported page of one Seasonvar title on the same final `missing_data_flags` state without downloading sibling pages again.

**Architecture:** Extract title-level missing-data evaluation and linked-page synchronization into `SeasonvarTitlePageStateSynchronizer`. `SeasonvarCatalogImporter` calls it after a parsed page and after a safe unchanged-page skip; the service updates the current page's successful-import metadata and bulk-updates only eligible parsed, unclaimed siblings resolved through canonical id and normalized season URL hashes.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent, SQLite, PHPUnit 12.5, Laravel Pint 1.29.

## Global Constraints

- Keep `php artisan seasonvar:import` as the only public Seasonvar importer command.
- Add no dependency or migration and make no additional remote request.
- Never update a pending, failed, or actively claimed sibling page.
- Preserve sibling crawl hashes, failure metadata, claims, `last_imported_at`, and `last_import_run_id`.
- Resolve season pages by normalized `source_url_hash`, not mutable `seasons.source_page_id`.

---

### Task 1: Reproduce stale cross-page state

**Files:**
- Modify: `tests/Feature/SeasonvarParsePageCommandTest.php:36`

**Interfaces:**
- Consumes: the existing `seasonvar:import` targeted URL flow and `seasonPageHtml()` fixture.
- Produces: a regression assertion that all parsed pages of the title receive the same final title-level flags.

- [x] **Step 1: Add the failing assertions**

At the end of `test_it_parses_requested_page_and_all_detected_seasons_into_one_title()`, add:

```php
$sourcePages = SourcePage::query()
    ->whereIn('url', [
        'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
        'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html',
    ])
    ->get()
    ->keyBy('url');

$seasonFourPage = $sourcePages->get('https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html');
$seasonOnePage = $sourcePages->get('https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html');

$this->assertNotNull($seasonFourPage);
$this->assertNotNull($seasonOnePage);
$this->assertSame($seasonOnePage->missing_data_flags, $seasonFourPage->missing_data_flags);
$this->assertNotContains('seasons_without_episodes', $seasonFourPage->missing_data_flags);
```

- [x] **Step 2: Run the regression and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/SeasonvarParsePageCommandTest.php --filter=test_it_parses_requested_page_and_all_detected_seasons_into_one_title
```

Expected: FAIL because season 4 retains `seasons_without_episodes` after season 1 has been imported.

- [x] **Step 3: Commit the RED test with the implementation task, not separately**

Keep the failing test unstaged until Task 2 is green so `main` is never pushed in a failing state.

---

### Task 2: Synchronize derived title state safely

**Files:**
- Create: `app/Services/Seasonvar/SeasonvarTitlePageStateSynchronizer.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php:31,431-449,513-522,1842-1850,1934-1981`
- Test: `tests/Feature/SeasonvarTitlePageStateSynchronizerTest.php`

**Interfaces:**
- Consumes: `CatalogTitle`, the current `SourcePage`, nullable import run id, `seasonvar.import.missing_data_retry_hours`, and already persisted seasons/episodes/media.
- Produces: `SeasonvarTitlePageStateSynchronizer::synchronize(CatalogTitle $catalogTitle, SourcePage $currentPage, ?int $importRunId): array`, returning `list<string>` final flags.

- [x] **Step 1: Add the lifecycle-boundary test**

Create `tests/Feature/SeasonvarTitlePageStateSynchronizerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Season;
use App\Models\SeasonvarImportRun;
use App\Models\Source;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarTitlePageStateSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeasonvarTitlePageStateSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_only_parsed_unclaimed_pages_and_preserves_sibling_history(): void
    {
        $this->travelTo('2026-07-13 14:00:00');
        config(['seasonvar.import.missing_data_retry_hours' => 24]);

        $source = Source::factory()->create();
        $previousRun = SeasonvarImportRun::query()->create(['mode' => 'url']);
        $currentRun = SeasonvarImportRun::query()->create(['mode' => 'url']);
        $originalImportedAt = now()->subDays(2);

        $makePage = function (string $url, array $attributes = []) use ($source): SourcePage {
            return SourcePage::factory()->create([
                'source_id' => $source->id,
                'url' => $url,
                'url_hash' => hash('sha256', $url),
                ...$attributes,
            ]);
        };

        $currentPage = $makePage('https://seasonvar.ru/serial-42-title-1-season.html', [
            'parse_status' => 'parsed',
            'import_status' => 'missing_data',
        ]);
        $eligibleSibling = $makePage('https://seasonvar.ru/serial-42-title-2-season.html', [
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'missing_data_flags' => [],
            'last_imported_at' => $originalImportedAt,
            'last_import_run_id' => $previousRun->id,
        ]);
        $pendingSibling = $makePage('https://seasonvar.ru/serial-42-title-3-season.html', [
            'parse_status' => 'pending',
            'import_status' => 'pending',
            'missing_data_flags' => ['pending-sentinel'],
        ]);
        $failedSibling = $makePage('https://seasonvar.ru/serial-42-title-4-season.html', [
            'parse_status' => 'failed',
            'import_status' => 'failed',
            'missing_data_flags' => ['failed-sentinel'],
        ]);
        $claimedSibling = $makePage('https://seasonvar.ru/serial-42-title-5-season.html', [
            'parse_status' => 'parsed',
            'import_status' => 'missing_data',
            'missing_data_flags' => ['claimed-sentinel'],
            'import_claim_token' => 'live-claim',
            'import_claimed_at' => now(),
            'import_claim_expires_at' => now()->addHour(),
        ]);

        $catalogTitle = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $currentPage->id,
            'source_url' => $currentPage->url,
            'source_url_hash' => $currentPage->url_hash,
        ]);

        foreach ([$eligibleSibling, $pendingSibling, $failedSibling, $claimedSibling] as $index => $page) {
            Season::factory()->create([
                'catalog_title_id' => $catalogTitle->id,
                'number' => $index + 2,
                'source_url' => $page->url,
                'source_url_hash' => $page->url_hash,
            ]);
        }

        $flags = app(SeasonvarTitlePageStateSynchronizer::class)
            ->synchronize($catalogTitle, $currentPage, $currentRun->id);

        $this->assertContains('no_episodes', $flags);
        $this->assertSame($flags, $eligibleSibling->fresh()->missing_data_flags);
        $this->assertSame($originalImportedAt->toDateTimeString(), $eligibleSibling->fresh()->last_imported_at?->toDateTimeString());
        $this->assertSame($previousRun->id, $eligibleSibling->fresh()->last_import_run_id);
        $this->assertSame(['pending-sentinel'], $pendingSibling->fresh()->missing_data_flags);
        $this->assertSame(['failed-sentinel'], $failedSibling->fresh()->missing_data_flags);
        $this->assertSame(['claimed-sentinel'], $claimedSibling->fresh()->missing_data_flags);
        $this->assertSame($currentRun->id, $currentPage->fresh()->last_import_run_id);
        $this->assertSame(now()->toDateTimeString(), $currentPage->fresh()->last_imported_at?->toDateTimeString());
    }
}
```

- [x] **Step 2: Run both tests and verify RED**

Run:

```bash
php artisan test --compact --filter='SeasonvarParsePageCommandTest|SeasonvarTitlePageStateSynchronizerTest'
```

Expected: FAIL because the synchronizer class does not exist and the first page remains stale.

- [x] **Step 3: Implement the synchronizer**

Create a final service with this public flow:

```php
/**
 * @return list<string>
 */
public function synchronize(CatalogTitle $catalogTitle, SourcePage $currentPage, ?int $importRunId): array
{
    $catalogTitle = $catalogTitle->fresh([
        'seasons.episodes',
        'seasons.licensedMedia',
        'licensedMedia',
    ]) ?? $catalogTitle;

    $flags = $this->missingDataFlags($catalogTitle);
    $retryAfter = $flags === [] ? null : now()->addHours(
        max(1, (int) config('seasonvar.import.missing_data_retry_hours', 24)),
    );

    $currentPage->update([
        'import_status' => $flags === [] ? 'parsed' : 'missing_data',
        'missing_data_flags' => $flags,
        'retry_after_at' => $retryAfter,
        'failure_count' => 0,
        'last_imported_at' => now(),
        'last_import_run_id' => $importRunId,
    ]);

    $seasonUrlHashes = $catalogTitle->seasons
        ->pluck('source_url_hash')
        ->filter()
        ->unique()
        ->values();

    $linkedPageIds = SourcePage::query()
        ->where('source_id', $catalogTitle->source_id)
        ->where(function (Builder $query) use ($catalogTitle, $currentPage, $seasonUrlHashes): void {
            $query->whereKey($currentPage->id);

            if ($catalogTitle->source_page_id !== null) {
                $query->orWhere('id', $catalogTitle->source_page_id);
            }

            if ($seasonUrlHashes->isNotEmpty()) {
                $query->orWhereIn('url_hash', $seasonUrlHashes);
            }
        })
        ->pluck('id');

    SourcePage::query()
        ->whereKey($linkedPageIds)
        ->where('id', '!=', $currentPage->id)
        ->where('parse_status', 'parsed')
        ->whereIn('import_status', ['parsed', 'missing_data'])
        ->whereNull('import_claim_token')
        ->update([
            'import_status' => $flags === [] ? 'parsed' : 'missing_data',
            'missing_data_flags' => json_encode($flags, JSON_THROW_ON_ERROR),
            'retry_after_at' => $retryAfter,
        ]);

    return $flags;
}
```

Add the evaluator to the same service:

```php
/**
 * @return list<string>
 */
private function missingDataFlags(CatalogTitle $catalogTitle): array
{
    $flags = [];
    $seasons = $catalogTitle->seasons;
    $episodes = $seasons->flatMap->episodes;
    $media = $catalogTitle->licensedMedia;
    $publishedMedia = $media->where('status', 'published');

    if (! $seasons->isNotEmpty()) {
        $flags[] = 'no_seasons';
    }

    if (! $episodes->isNotEmpty()) {
        $flags[] = 'no_episodes';
    }

    if ($seasons->contains(fn (Season $season): bool => $season->episodes->isEmpty())) {
        $flags[] = 'seasons_without_episodes';
    }

    if (! $media->isNotEmpty()) {
        $flags[] = 'no_video';
    }

    if ($media->isNotEmpty() && ! $publishedMedia->isNotEmpty()) {
        $flags[] = 'no_published_video';
    }

    if ($seasons->contains(fn (Season $season): bool => $season->licensedMedia->where('status', 'published')->isEmpty())) {
        $flags[] = 'seasons_without_video';
    }

    if ($episodes->isNotEmpty()) {
        $publishedEpisodeIds = $publishedMedia
            ->pluck('episode_id')
            ->filter()
            ->unique()
            ->values();

        if ($episodes->whereNotIn('id', $publishedEpisodeIds)->isNotEmpty()) {
            $flags[] = 'episodes_without_video';
        }
    }

    if ($media->contains(fn (LicensedMedia $media): bool => $media->status === 'unavailable'
        || in_array($media->check_status, ['check_failed', 'unavailable'], true))) {
        $flags[] = 'unavailable_video';
    }

    return $flags;
}
```

Import `Builder`, `CatalogTitle`, `LicensedMedia`, `Season`, and `SourcePage` in the service. This is the complete evaluator currently owned by the importer; do not retain a duplicate.

- [x] **Step 4: Delegate both successful importer exits**

Inject the service into `SeasonvarCatalogImporter`:

```php
private readonly SeasonvarTitlePageStateSynchronizer $titlePageStateSynchronizer,
```

In the unchanged-page branch replace the direct status update with:

```php
$this->titlePageStateSynchronizer->synchronize($existingCatalogTitle, $page, $importRunId);
```

After media and translations are synchronized replace local flag calculation and current-page update with:

```php
$missingDataFlags = $this->titlePageStateSynchronizer
    ->synchronize($catalogTitle, $page, $importRunId);
```

Remove `missingDataFlags()` and `missingDataRetryAfter()` from the importer. Add `'missing_data_flags' => $missingDataFlags` to the existing `page-parse-complete` progress context so the local result remains observable and is not dead code.

- [x] **Step 5: Run focused tests and verify GREEN**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --compact --filter='SeasonvarParsePageCommandTest|SeasonvarTitlePageStateSynchronizerTest|SeasonvarImportMaintenanceTest'
```

Expected: all selected tests PASS and Pint exits successfully.

- [x] **Step 6: Commit the tested implementation**

```bash
git status --short --branch
git add app/Services/Seasonvar/SeasonvarCatalogImporter.php app/Services/Seasonvar/SeasonvarTitlePageStateSynchronizer.php tests/Feature/SeasonvarParsePageCommandTest.php tests/Feature/SeasonvarTitlePageStateSynchronizerTest.php docs/superpowers/plans/2026-07-13-seasonvar-title-page-state-reconciliation.md
git commit -m "fix: reconcile Seasonvar title page state"
git push origin main
```

---

### Task 3: Document and verify operations

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/performance.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: `docs/superpowers/plans/2026-07-13-seasonvar-title-page-state-reconciliation.md`

**Interfaces:**
- Consumes: the service and tests from Task 2.
- Produces: project documentation that explains bounded sibling reconciliation and why it reduces duplicate recovery traffic.

- [x] **Step 1: Add concrete documentation**

Add these project-specific statements:

```markdown
- `SeasonvarTitlePageStateSynchronizer` пересчитывает title-level `missing_data_flags` после успешного parse или unchanged-skip и одним bounded update синхронизирует только уже parsed/unclaimed страницы того же тайтла, найденные по canonical id и season URL hashes.
- После импорта более поздней страницы сезона устаревшие missing-data flags ранней страницы очищаются без повторного HTTP-запроса; pending, failed и claimed страницы сохраняют собственный lifecycle.
```

Record the production stale counts and completed fix in `docs/MAINTENANCE_LOG.md` without claiming that historical rows were bulk-rewritten.

- [x] **Step 2: Run broad verification**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test
git diff --check
```

Expected: Pint succeeds, the complete PHPUnit suite passes, and `git diff --check` prints nothing.

- [x] **Step 3: Mark plan steps complete and commit docs**

Change every completed checkbox in this plan to `[x]`, then run:

```bash
git status --short --branch
git add docs/architecture.md docs/performance.md docs/MAINTENANCE_LOG.md docs/superpowers/plans/2026-07-13-seasonvar-title-page-state-reconciliation.md
git commit -m "docs: explain Seasonvar page state reconciliation"
git push origin main
```

- [x] **Step 4: Restart long-lived workers and verify status**

Run:

```bash
php artisan queue:restart
php artisan seasonvar:import --status
git status --short --branch
```

Expected: queue restart signal is accepted, importer status reports the existing queue/run state, and `main` is clean and synchronized with `origin/main`.
