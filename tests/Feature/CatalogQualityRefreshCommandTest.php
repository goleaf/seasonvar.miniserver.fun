<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogQualitySeverity;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleQualitySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CatalogQualityRefreshCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function command_prioritizes_missing_then_dirty_snapshots_and_honors_the_limit(): void
    {
        Carbon::setTestNow('2026-07-26 10:00:00');
        $dirty = CatalogTitle::factory()->create();
        $oldVersion = CatalogTitle::factory()->create();
        $missing = CatalogTitle::factory()->create();
        $fresh = CatalogTitle::factory()->create();

        CatalogTitleQualitySnapshot::factory()->for($dirty)->create([
            'needs_refresh' => true,
            'evaluated_at' => now(),
        ]);
        CatalogTitleQualitySnapshot::factory()->for($oldVersion)->create([
            'scoring_version' => 0,
            'evaluated_at' => now(),
        ]);
        CatalogTitleQualitySnapshot::factory()->for($fresh)->create([
            'quality_score' => 99,
            'severity' => CatalogQualitySeverity::Healthy,
            'issue_count' => 0,
            'evaluated_at' => now(),
        ]);

        $this->artisan('catalog:quality-refresh', ['--limit' => '2'])
            ->expectsOutputToContain('Пересчитано карточек: 2.')
            ->assertSuccessful();

        self::assertDatabaseHas('catalog_title_quality_snapshots', [
            'catalog_title_id' => $missing->id,
            'scoring_version' => 1,
            'needs_refresh' => false,
        ]);
        self::assertDatabaseHas('catalog_title_quality_snapshots', [
            'catalog_title_id' => $dirty->id,
            'scoring_version' => 1,
            'needs_refresh' => false,
        ]);
        self::assertDatabaseHas('catalog_title_quality_snapshots', [
            'catalog_title_id' => $oldVersion->id,
            'scoring_version' => 0,
        ]);
        self::assertDatabaseHas('catalog_title_quality_snapshots', [
            'catalog_title_id' => $fresh->id,
            'quality_score' => 99,
        ]);
        $runId = (int) $missing->qualitySnapshot()->valueOrFail('catalog_quality_run_id');
        self::assertDatabaseHas('catalog_quality_runs', [
            'id' => $runId,
            'status' => 'succeeded',
            'trigger' => 'command',
            'requested_limit' => 2,
            'processed_count' => 2,
            'failure_code' => null,
        ]);

        Carbon::setTestNow();
    }

    #[Test]
    public function command_rejects_zero_negative_non_numeric_and_excessive_limits(): void
    {
        foreach (['0', '-1', 'nope', '1001'] as $limit) {
            $this->artisan('catalog:quality-refresh', ['--limit' => $limit])
                ->assertExitCode(2);
        }
    }

    #[Test]
    public function command_reports_an_unapplied_schema_without_an_sql_exception(): void
    {
        Schema::drop('catalog_title_quality_issues');
        Schema::drop('catalog_title_quality_snapshots');

        $this->artisan('catalog:quality-refresh')
            ->expectsOutputToContain('Схема центра качества ещё не установлена.')
            ->assertFailed();
    }
}
