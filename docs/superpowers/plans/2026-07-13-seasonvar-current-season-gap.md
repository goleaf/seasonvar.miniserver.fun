# Seasonvar Current Season Gap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure every parsed Seasonvar page exposes the current season so extracted episodes can always be persisted under their exact season number.

**Architecture:** `SeasonvarCatalogParser` will determine the current season before parsing the season list and pass that number into `seasons()`. The season parser will preserve linked metadata and synthesize only a missing current-season entry using the current page URL.

**Tech Stack:** PHP 8.5, Laravel 13.19, PHPUnit 12.5.

## Global Constraints

- Keep `php artisan seasonvar:import` as the only public importer command.
- Store seasons and episodes inside one `CatalogTitle`.
- Do not download video files or add dependencies.
- Keep source URLs inside `https://seasonvar.ru/`.
- Work directly on the existing `main` branch and leave it clean.

---

### Task 1: Guarantee the current season in parser output

**Files:**
- Modify: `tests/Unit/SeasonvarCatalogParserTest.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogParser.php`
- Modify: `docs/superpowers/plans/2026-07-13-seasonvar-current-season-gap.md`

**Interfaces:**
- Consumes: `SeasonvarCatalogParser::parse(string $html, string $url): array`.
- Produces: `SeasonvarCatalogParser::seasons(DOMXPath $xpath, string $baseUrl, int $currentSeasonNumber): array`, whose result always contains `currentSeasonNumber`.

- [ ] **Step 1: Write the failing parser regression test**

Add `test_it_keeps_the_canonical_current_season_when_the_list_only_links_other_seasons()` to `tests/Unit/SeasonvarCatalogParserTest.php`. Parse a canonical URL without a season suffix, an HTML season list containing only season 2, and an `arEpisodes` payload containing episodes 1 and 2. Assert:

```php
$this->assertSame(1, $data['current_season_number']);
$this->assertSame([1, 2], collect($data['seasons'])->pluck('number')->all());
$this->assertSame(
    'https://seasonvar.ru/serial-24914-TCeni_kazhdyj_den__psakpir.html',
    $data['seasons'][0]['source_url'],
);
$this->assertSame([1, 1], collect($data['episodes'])->pluck('season_number')->all());
```

- [ ] **Step 2: Run the regression test and verify RED**

Run:

```bash
php artisan test tests/Unit/SeasonvarCatalogParserTest.php --filter=test_it_keeps_the_canonical_current_season_when_the_list_only_links_other_seasons
```

Expected: FAIL because the returned season numbers are currently `[2]` instead of `[1, 2]`.

- [ ] **Step 3: Implement the minimal parser change**

In `SeasonvarCatalogParser::parse()`, determine the current season before parsing seasons:

```php
$currentSeasonNumber = $this->seasonNumberFromUrl($url) ?? $this->seasonNumber($title) ?? 1;
$seasons = $this->seasons($xpath, $url, $currentSeasonNumber);
```

Change the private method signature:

```php
private function seasons(DOMXPath $xpath, string $baseUrl, int $currentSeasonNumber): array
```

After collecting direct season links, add only the missing current season:

```php
if (! array_key_exists($currentSeasonNumber, $seasons)) {
    $releaseStatus = $this->emptySeasonReleaseStatus();

    $seasons[$currentSeasonNumber] = [
        'number' => $currentSeasonNumber,
        'title' => "Сезон {$currentSeasonNumber}",
        'source_url' => $baseUrl,
        'latest_episode_released_at' => $releaseStatus['latest_episode_released_at'],
        'episodes_released' => $releaseStatus['episodes_released'],
        'episodes_total' => $releaseStatus['episodes_total'],
        'translation_name' => $releaseStatus['translation_name'],
        'release_status_text' => $releaseStatus['release_status_text'],
    ];
}
```

Remove the old `if ($seasons === [])` fallback because the new invariant supersedes it.

- [ ] **Step 4: Run focused tests and verify GREEN**

Run:

```bash
php artisan test tests/Unit/SeasonvarCatalogParserTest.php
php artisan test tests/Feature/SeasonvarParsePageCommandTest.php
```

Expected: all parser and parse-page tests pass.

- [ ] **Step 5: Format and verify the importer suite**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=Seasonvar
php artisan project:docs-refresh --check
git diff --check
```

Expected: all commands exit with status 0.

- [ ] **Step 6: Commit, push, restart workers, and verify recovery readiness**

Mark all plan checkboxes complete, stage the complete clean-scope change, and run:

```bash
git status --short --branch
git add app/Services/Seasonvar/SeasonvarCatalogParser.php tests/Unit/SeasonvarCatalogParserTest.php docs/superpowers/plans/2026-07-13-seasonvar-current-season-gap.md
git commit -m "fix: preserve current Seasonvar season"
git push origin main
php artisan queue:restart
php artisan seasonvar:import --status
```

Expected: `main` is synchronized, exactly 10 systemd workers restart, and the existing recovery planner can re-import affected `missing_data` pages without manual queue deletion.
