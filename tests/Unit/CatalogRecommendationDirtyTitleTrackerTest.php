<?php

namespace Tests\Unit;

use App\Models\CatalogRecommendationDirtyTitle;
use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogRecommendationDirtyTitleTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogRecommendationDirtyTitleTrackerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_upserts_orders_and_forgets_dirty_titles_without_duplicates(): void
    {
        $first = CatalogTitle::factory()->create();
        $second = CatalogTitle::factory()->create();
        $tracker = app(CatalogRecommendationDirtyTitleTracker::class);

        $tracker->mark($first->id, 'seasonvar-import');
        $this->travel(1)->second();
        $tracker->mark($second->id, 'media-health');
        $this->travel(1)->second();
        $tracker->mark($first->id, 'targeted-import');

        $this->assertSame(2, CatalogRecommendationDirtyTitle::query()->count());
        $this->assertDatabaseHas('catalog_recommendation_dirty_titles', [
            'catalog_title_id' => $first->id,
            'reason' => 'targeted-import',
        ]);
        $this->assertSame([$second->id], $tracker->ids(1));

        $tracker->forget([$second->id]);

        $this->assertSame([$first->id], $tracker->ids(10));

        $first->forceDelete();

        $this->assertDatabaseMissing('catalog_recommendation_dirty_titles', [
            'catalog_title_id' => $first->id,
        ]);
    }
}
