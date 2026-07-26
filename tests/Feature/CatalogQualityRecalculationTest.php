<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleQualityIssue;
use App\Models\CatalogTitleQualitySnapshot;
use App\Services\Catalog\Quality\CatalogMetadataProvenanceRecorder;
use App\Services\Catalog\Quality\CatalogTitleQualityInputLoader;
use App\Services\Catalog\Quality\CatalogTitleQualityRecalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CatalogQualityRecalculationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function recalculation_is_idempotent_preserves_first_detection_and_removes_resolved_issues(): void
    {
        Carbon::setTestNow('2026-07-26 10:00:00');
        $title = CatalogTitle::factory()->create([
            'poster_url' => null,
        ]);

        $recalculator = app(CatalogTitleQualityRecalculator::class);

        self::assertSame(1, $recalculator->recalculate([$title->id]));
        $firstDetectedAt = CatalogTitleQualityIssue::query()
            ->whereBelongsTo($title)
            ->where('code', 'missing_country')
            ->valueOrFail('first_detected_at');

        $title->update(['poster_url' => 'https://images.example.com/poster.jpg']);
        CatalogTitleQualitySnapshot::query()
            ->whereKey($title->id)
            ->update(['needs_refresh' => true]);
        Carbon::setTestNow('2026-07-26 12:00:00');

        self::assertSame(1, $recalculator->recalculate([$title->id]));
        self::assertFalse(
            CatalogTitleQualityIssue::query()
                ->whereBelongsTo($title)
                ->where('code', 'missing_poster')
                ->exists(),
        );
        self::assertTrue(
            Carbon::parse($firstDetectedAt)->equalTo(
                CatalogTitleQualityIssue::query()
                    ->whereBelongsTo($title)
                    ->where('code', 'missing_country')
                    ->valueOrFail('first_detected_at'),
            ),
        );
        self::assertFalse($title->qualitySnapshot()->sole()->needs_refresh);

        Carbon::setTestNow();
    }

    #[Test]
    public function fact_loader_uses_a_constant_number_of_queries_for_a_batch(): void
    {
        $one = CatalogTitle::factory()->create();
        $many = CatalogTitle::factory()->count(20)->create();
        $loader = app(CatalogTitleQualityInputLoader::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $loader->load([$one->id]);
        $singleCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $loader->load($many->modelKeys());
        $batchCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertLessThanOrEqual($singleCount + 2, $batchCount);
        self::assertLessThanOrEqual(12, $batchCount);
    }

    #[Test]
    public function recalculation_uses_current_provider_observations_when_legacy_baseline_is_absent(): void
    {
        $title = CatalogTitle::factory()->create([
            'title' => 'Редакторское название',
            'provider_field_values' => null,
        ]);
        app(CatalogMetadataProvenanceRecorder::class)->recordProviderSnapshot(
            $title,
            $title->sourcePage,
            ['title' => 'Название Seasonvar'],
        );

        app(CatalogTitleQualityRecalculator::class)->recalculate([$title->id]);

        self::assertDatabaseHas('catalog_title_quality_issues', [
            'catalog_title_id' => $title->id,
            'code' => 'data_conflicts',
        ]);
    }
}
