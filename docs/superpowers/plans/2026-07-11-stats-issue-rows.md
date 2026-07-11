# Stats Issue Rows Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure the `/stats` data builder returns issue rows from multiple catalog issue categories when the first category is empty.

**Architecture:** Keep the fix inside `CatalogStatsPageBuilder::statsIssueRows()`. The method still builds each issue bucket with existing Eloquent queries, maps each bucket to `titlePreviewRow()` arrays, then combines those plain rows through a fresh base collection before de-duplicating and limiting.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent, PHPUnit 12.5, Laravel Pint 1.29.

## Global Constraints

- Visible UI text remains in Russian.
- Do not change routes, Blade templates, migrations, importer lifecycle, or recommendation rebuild behavior for this task.
- Do not run destructive database commands such as `migrate:fresh`, `db:wipe`, `queue:clear`, or `cache:clear`.
- Use focused tests first, then broader tests only if the touched behavior or failures require it.
- Run `./vendor/bin/pint --dirty --format agent` after PHP changes.

---

## File Structure

- Modify `tests/Feature/CatalogPageTest.php`: add a regression test near existing stats tests.
- Modify `app/Services/Catalog/CatalogStatsPageBuilder.php`: make `statsIssueRows()` merge mapped array rows from a fresh `Collection`.

### Task 1: Add Regression Coverage

**Files:**
- Modify: `tests/Feature/CatalogPageTest.php`

**Interfaces:**
- Consumes: `CatalogTitle::factory()`, `LicensedMedia::factory()`, `app(CatalogStatsPageBuilder::class)->data()`.
- Produces: PHPUnit method `test_stats_issue_rows_merge_multiple_issue_categories(): void`.

- [ ] **Step 1: Import the stats page builder**

Add this import with the existing imports:

```php
use App\Services\Catalog\CatalogStatsPageBuilder;
```

- [ ] **Step 2: Add the failing regression test**

Place this method after `test_stats_poster_proxy_is_public_and_does_not_require_raw_url_in_page()`:

```php
public function test_stats_issue_rows_merge_multiple_issue_categories(): void
{
    $withoutPoster = CatalogTitle::factory()->create([
        'title' => 'Статистика без постера',
        'slug' => 'statistika-bez-postera',
        'poster_url' => null,
        'description' => 'Описание есть.',
    ]);
    LicensedMedia::factory()->create([
        'catalog_title_id' => $withoutPoster->id,
        'status' => 'published',
        'published_at' => now(),
    ]);

    $withoutDescription = CatalogTitle::factory()->create([
        'title' => 'Статистика без описания',
        'slug' => 'statistika-bez-opisaniya',
        'poster_url' => 'https://media.example.com/without-description.jpg',
        'description' => null,
    ]);
    LicensedMedia::factory()->create([
        'catalog_title_id' => $withoutDescription->id,
        'status' => 'published',
        'published_at' => now(),
    ]);

    $data = app(CatalogStatsPageBuilder::class)->data();

    $issueTitles = collect($data['statsIssueRows'])->pluck('title');

    $this->assertContains('Статистика без постера', $issueTitles);
    $this->assertContains('Статистика без описания', $issueTitles);
}
```

- [ ] **Step 3: Run the new test before implementation**

Run:

```bash
php artisan test --filter=test_stats_issue_rows_merge_multiple_issue_categories
```

Expected before the service fix: FAIL because one of the two titles is missing from `statsIssueRows`.

### Task 2: Fix Issue Row Merging

**Files:**
- Modify: `app/Services/Catalog/CatalogStatsPageBuilder.php`
- Test: `tests/Feature/CatalogPageTest.php`

**Interfaces:**
- Consumes: existing private method `titlePreviewRow(CatalogTitle $title, string $label, string $meta, string $icon, string $tone): array`.
- Produces: unchanged private method `statsIssueRows(): Collection`.

- [ ] **Step 1: Replace the final merge chain**

In `CatalogStatsPageBuilder::statsIssueRows()`, replace:

```php
return $withoutPublishedMedia
    ->merge($withoutPoster)
    ->merge($withoutDescription)
    ->unique('id')
    ->take(8)
    ->values();
```

with:

```php
return collect()
    ->merge($withoutPublishedMedia)
    ->merge($withoutPoster)
    ->merge($withoutDescription)
    ->unique('id')
    ->take(8)
    ->values();
```

- [ ] **Step 2: Run the focused regression test**

Run:

```bash
php artisan test --filter=test_stats_issue_rows_merge_multiple_issue_categories
```

Expected after the service fix: PASS.

- [ ] **Step 3: Run the surrounding feature test class**

Run:

```bash
php artisan test --filter=CatalogPageTest
```

Expected: PASS.

- [ ] **Step 4: Format changed PHP files**

Run:

```bash
./vendor/bin/pint --dirty --format agent
```

Expected: Pint completes without errors and either reports no changes needed or formats only dirty PHP files.

- [ ] **Step 5: Commit the implementation if a commit is requested**

Stage only the service and feature test files:

```bash
git add app/Services/Catalog/CatalogStatsPageBuilder.php tests/Feature/CatalogPageTest.php
git commit -m "fix: merge stats issue rows across categories"
```

Do not stage `docs/recommendations-playwright-plan.md` unless that documentation update is intentionally part of the same requested commit.

## Self-Review

- Spec coverage: Task 1 covers the regression test requirement; Task 2 covers the local `statsIssueRows()` fix and focused verification commands.
- Placeholder scan: no placeholder markers or deferred implementation instructions remain.
- Type consistency: `statsIssueRows(): Collection`, `CatalogStatsPageBuilder::data()`, and `statsIssueRows` array key match the current service and test code.
