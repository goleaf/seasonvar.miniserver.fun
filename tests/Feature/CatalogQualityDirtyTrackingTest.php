<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleQualitySnapshot;
use App\Services\Catalog\CatalogCacheInvalidator;
use App\Services\Catalog\Quality\CatalogTitleQualityDirtyTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CatalogQualityDirtyTrackingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function exact_catalog_and_playback_invalidations_mark_only_their_snapshots_dirty(): void
    {
        $changed = CatalogTitle::factory()->create();
        $unchanged = CatalogTitle::factory()->create();
        CatalogTitleQualitySnapshot::factory()->for($changed)->create();
        CatalogTitleQualitySnapshot::factory()->for($unchanged)->create();
        $cache = app(CatalogCacheInvalidator::class);

        $cache->catalogChanged([$changed->id]);

        self::assertTrue($changed->qualitySnapshot()->sole()->needs_refresh);
        self::assertFalse($unchanged->qualitySnapshot()->sole()->needs_refresh);

        CatalogTitleQualitySnapshot::query()
            ->whereKey($changed->id)
            ->update(['needs_refresh' => false]);
        $cache->titlePlaybackMetadataChanged($changed->id);

        self::assertTrue($changed->qualitySnapshot()->sole()->needs_refresh);
        self::assertFalse($unchanged->qualitySnapshot()->sole()->needs_refresh);
    }

    #[Test]
    public function global_cache_invalidation_does_not_mass_update_quality_snapshots(): void
    {
        $titles = CatalogTitle::factory()->count(3)->create();

        foreach ($titles as $title) {
            CatalogTitleQualitySnapshot::factory()->for($title)->create();
        }

        app(CatalogCacheInvalidator::class)->catalogChanged();

        self::assertSame(
            0,
            CatalogTitleQualitySnapshot::query()->where('needs_refresh', true)->count(),
        );
    }

    #[Test]
    public function dirty_tracking_is_safe_during_a_code_before_migration_deployment_window(): void
    {
        Schema::drop('catalog_title_quality_issues');
        Schema::drop('catalog_title_quality_snapshots');

        self::assertSame(
            0,
            app(CatalogTitleQualityDirtyTracker::class)->mark([1]),
        );
    }
}
