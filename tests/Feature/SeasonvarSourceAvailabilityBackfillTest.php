<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SeasonvarSourceAvailability;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use App\Models\SourcePageSnapshot;
use App\Services\Seasonvar\SeasonvarCatalogParser;
use App\Services\Seasonvar\SeasonvarRefreshPlanner;
use App\Services\Seasonvar\SeasonvarSourceAvailabilityBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeasonvarSourceAvailabilityBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_existing_region_blocked_snapshots_without_network_requests(): void
    {
        $this->travelTo('2026-07-13 12:00:00');
        config(['seasonvar.provider_availability.retry_hours' => 168]);
        Http::preventStrayRequests();
        $blocked = $this->pageWithSnapshot(
            '<html><div class="pgs-player-block">По просьбе правообладателя, сезон заблокирован для вашей страны.</div></html>',
        );
        $withoutKnownRestriction = $this->pageWithSnapshot(
            '<html><div class="pgs-player-block">Видео временно не найдено.</div></html>',
        );

        $result = app(SeasonvarSourceAvailabilityBackfill::class)->run();

        $this->assertSame(2, $result['pages_checked']);
        $this->assertSame(2, $result['pages_updated']);
        $this->assertSame(1, $result['region_blocked']);
        $this->assertSame(1, $result['without_known_restriction']);
        $this->assertSame(
            SeasonvarSourceAvailability::RegionBlocked,
            $blocked->fresh()->provider_availability_status,
        );
        $this->assertNotNull($blocked->fresh()->provider_availability_checked_at);
        $this->assertSame('2026-07-20 12:00:00', $blocked->fresh()->retry_after_at?->toDateTimeString());
        $this->assertNull($withoutKnownRestriction->fresh()->provider_availability_status);
        $this->assertNotNull($withoutKnownRestriction->fresh()->provider_availability_checked_at);
        Http::assertNothingSent();
    }

    public function test_full_import_runs_the_bounded_snapshot_backfill(): void
    {
        Http::preventStrayRequests();
        $page = $this->pageWithSnapshot(
            '<html><div class="pgs-player-block">По просьбе правообладателя, сезон заблокирован для вашей страны.</div></html>',
        );
        $page->update([
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'last_imported_at' => now(),
            'metadata_parser_version' => SeasonvarCatalogParser::METADATA_VERSION,
            'metadata_attempted_version' => SeasonvarCatalogParser::METADATA_VERSION,
        ]);

        $this->artisan('seasonvar:import', [
            '--no-discovery' => true,
            '--page-type' => ['serial'],
        ])->assertExitCode(0);

        $run = SeasonvarImportRun::query()->latest('id')->firstOrFail();

        $this->assertSame(1, $run->summary['last_provider_availability_backfill']['pages_checked']);
        $this->assertSame(1, $run->summary['last_provider_availability_backfill']['region_blocked']);
        $this->assertSame(
            SeasonvarSourceAvailability::RegionBlocked,
            $page->fresh()->provider_availability_status,
        );
        Http::assertNothingSent();
    }

    public function test_refresh_planner_selects_due_region_blocked_pages(): void
    {
        $page = $this->pageWithSnapshot('<html><h1>Заблокированный сезон</h1></html>');
        $page->update([
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'provider_availability_status' => SeasonvarSourceAvailability::RegionBlocked,
            'provider_availability_checked_at' => now()->subDay(),
            'retry_after_at' => now()->subMinute(),
            'last_imported_at' => now(),
            'metadata_parser_version' => SeasonvarCatalogParser::METADATA_VERSION,
            'metadata_attempted_version' => SeasonvarCatalogParser::METADATA_VERSION,
        ]);

        $selectedIds = collect(app(SeasonvarRefreshPlanner::class)->pageChunksForImportCycle(
            100,
            now()->subHours(24),
        ))
            ->flatten(1)
            ->pluck('id');

        $this->assertTrue($selectedIds->contains($page->id));
    }

    private function pageWithSnapshot(string $html): SourcePage
    {
        $page = SourcePage::factory()->create([
            'page_type' => 'serial',
            'provider_availability_status' => null,
            'provider_availability_checked_at' => null,
        ]);

        SourcePageSnapshot::query()->create([
            'source_page_id' => $page->id,
            'url' => $page->url,
            'content_hash' => hash('sha256', $html),
            'http_status' => 200,
            'body_bytes' => mb_strlen($html, '8bit'),
            'html' => $html,
            'captured_at' => now(),
        ]);

        return $page;
    }
}
