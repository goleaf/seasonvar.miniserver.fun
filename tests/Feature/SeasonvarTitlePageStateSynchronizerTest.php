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
