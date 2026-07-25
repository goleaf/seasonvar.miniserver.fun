<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogRecommendationContext;
use App\Enums\CatalogRecommendationType;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Services\Catalog\CatalogRecommendationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogDiscoveryQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_discovery_query_count_is_bounded_independently_of_catalog_size(): void
    {
        $smallCatalogQueries = $this->measurePopularDiscoveryQueries(24);
        $largeCatalogQueries = $this->measurePopularDiscoveryQueries(48);

        $this->assertLessThanOrEqual(24, $smallCatalogQueries);
        $this->assertLessThanOrEqual(24, $largeCatalogQueries);
        $this->assertSame($smallCatalogQueries, $largeCatalogQueries);
    }

    private function measurePopularDiscoveryQueries(int $additionalTitles): int
    {
        foreach (range(1, $additionalTitles) as $index) {
            $title = CatalogTitle::factory()->create([
                'title' => "Query budget {$additionalTitles}-{$index}",
            ]);
            LicensedMedia::factory()->for($title)->create([
                'status' => 'published',
                'published_at' => now(),
            ]);
        }

        $queries = 0;
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            if (! str_contains(strtolower($query->sql), 'cache_')) {
                $queries++;
            }
        });

        app(CatalogRecommendationService::class)->discover(
            new CatalogRecommendationContext(
                type: CatalogRecommendationType::Popular,
                user: null,
                locale: 'ru',
                filters: ['query_budget_nonce' => (string) $additionalTitles],
            ),
        );

        return $queries;
    }
}
