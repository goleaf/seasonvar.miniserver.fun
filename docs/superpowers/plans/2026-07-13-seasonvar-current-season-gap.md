# Seasonvar Current-Season Gap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure every parsed Seasonvar page exposes its current season so the importer can persist the page's episodes instead of marking the title as missing episodes.

**Architecture:** Determine the current season once at the start of `SeasonvarCatalogParser::parse()` and pass it into the existing season-list parser. The season parser keeps trusted linked metadata unchanged and inserts one normalized fallback entry for the current page only when that season number is absent.

**Tech Stack:** PHP 8.5, Laravel 13.19, PHPUnit 12.5, Laravel Pint 1.29.

## Global Constraints

- Keep all seasons and episodes inside one `CatalogTitle`.
- Store only normalized `https://seasonvar.ru/` source URLs.
- Do not download video files or add dependencies or migrations.
- Preserve metadata from a linked current-season entry when it already exists.

---

### Task 1: Guarantee the current season in parser output

**Files:**
- Modify: `app/Services/Seasonvar/SeasonvarCatalogParser.php:84`
- Test: `tests/Unit/SeasonvarCatalogParserTest.php`

**Interfaces:**
- Consumes: `SeasonvarCatalogParser::parse(string $html, string $url): array` and the existing URL/title season-number helpers.
- Produces: `SeasonvarCatalogParser::seasons(DOMXPath $xpath, string $baseUrl, int $currentSeasonNumber): array`, whose result always contains `$currentSeasonNumber`.

- [x] **Step 1: Write the failing parser regression test**

Add a test that parses a canonical season-1 page whose `pgs-seaslist` contains only a season-2 link:

```php
public function test_it_keeps_the_canonical_current_season_when_the_list_only_links_other_seasons(): void
{
    $parser = app(SeasonvarCatalogParser::class);

    $data = $parser->parse(
        <<<'HTML'
        <html>
            <head><title>Цени каждый день смотреть онлайн</title></head>
            <body>
                <h1>Цени каждый день/Cherish the Day</h1>
                <div class="pgs-seaslist">
                    <a href="/serial-24914-TCeni_kazhdyj_den__psakpir-2-season.html">2 сезон</a>
                </div>
                <script>
                    var arEpisodes = [{"1_seriya":{"n":"1","next":"2"}},{"2_seriya":{"n":"2"}}];
                </script>
            </body>
        </html>
        HTML,
        'https://seasonvar.ru/serial-24914-TCeni_kazhdyj_den__psakpir.html',
    );

    $this->assertSame(1, $data['current_season_number']);
    $this->assertSame([1, 2], collect($data['seasons'])->pluck('number')->all());
    $this->assertSame('Сезон 1', $data['seasons'][0]['title']);
    $this->assertSame('https://seasonvar.ru/serial-24914-TCeni_kazhdyj_den__psakpir.html', $data['seasons'][0]['source_url']);
    $this->assertSame([1, 1], collect($data['episodes'])->pluck('season_number')->all());
}
```

- [x] **Step 2: Run the regression test and verify RED**

Run:

```bash
php artisan test tests/Unit/SeasonvarCatalogParserTest.php --filter=test_it_keeps_the_canonical_current_season_when_the_list_only_links_other_seasons
```

Expected: FAIL because parsed seasons contain only season 2.

- [x] **Step 3: Pass the resolved current season into season parsing**

In `parse()`, resolve `$currentSeasonNumber` before calling `seasons()` and change the call to:

```php
$currentSeasonNumber = $this->seasonNumberFromUrl($url) ?? $this->seasonNumber($title) ?? 1;
$seasons = $this->seasons($xpath, $url, $currentSeasonNumber);
```

Update the private method signature:

```php
private function seasons(DOMXPath $xpath, string $baseUrl, int $currentSeasonNumber): array
```

- [x] **Step 4: Insert only a missing current-season entry**

After direct links have been parsed and before sorting, add the current page using the existing empty release-status shape only when the key is absent:

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

Remove the broader `$seasons === []` fallback because the new condition covers empty lists and explicit current seasons without overwriting linked metadata.

- [x] **Step 5: Run focused parser tests and verify GREEN**

Run:

```bash
php artisan test tests/Unit/SeasonvarCatalogParserTest.php
```

Expected: all parser tests PASS.

- [x] **Step 6: Format and verify importer regressions**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter='SeasonvarCatalogParserTest|SeasonvarParsePageCommandTest'
```

Expected: Pint exits successfully and both parser/importer test groups PASS.

- [x] **Step 7: Commit and deploy the implementation**

```bash
git status --short --branch
git add app/Services/Seasonvar/SeasonvarCatalogParser.php tests/Unit/SeasonvarCatalogParserTest.php docs/superpowers/plans/2026-07-13-seasonvar-current-season-gap.md
git commit -m "fix: preserve current Seasonvar season"
git push origin main
php artisan queue:restart
php artisan seasonvar:import --status
```
