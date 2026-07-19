<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogRecommendationContext;
use App\Enums\CatalogRecommendationType;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\Season;
use App\Services\Catalog\CatalogRecommendationTitleLoader;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogRecommendationTitleLoaderQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_episode_counts_use_bounded_available_season_projection(): void
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->create(['catalog_title_id' => $title->id]);
        Episode::factory()->create(['season_id' => $season->id]);
        $episodeSql = null;
        DB::listen(function (QueryExecuted $query) use (&$episodeSql): void {
            $sql = str($query->sql)->replace(['`', '"'], '')->lower()->squish()->toString();
            if (str_starts_with($sql, 'select available_seasons.catalog_title_id')) {
                $episodeSql = $sql;
            }
        });

        $titles = app(CatalogRecommendationTitleLoader::class)->load(
            new CatalogRecommendationContext(CatalogRecommendationType::Popular, null, 'ru'),
            [$title->id],
            watchable: false,
        );

        $this->assertSame(1, $titles->first()?->getAttribute('episodes_count'));
        $this->assertIsString($episodeSql);
        $this->assertStringContainsString('inner join (select id, catalog_title_id from seasons where', $episodeSql);
        $this->assertStringContainsString('available_seasons.id = episodes.season_id', $episodeSql);
        $this->assertStringContainsString('catalog_title_id in (', $episodeSql);
        $this->assertStringNotContainsString('seasons.id in (select', $episodeSql);
    }
}
