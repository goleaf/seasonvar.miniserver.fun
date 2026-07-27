<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogRecommendationContext;
use App\Enums\CatalogRecommendationReason;
use App\Enums\CatalogRecommendationSource;
use App\Enums\CatalogRecommendationType;
use App\Enums\PublicationStatus;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Catalog\CatalogPublicDiscoveryQuery;
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

    public function test_non_popular_discovery_mounts_one_bounded_collection_explorer(): void
    {
        $collectionQueries = 0;

        DB::listen(static function (QueryExecuted $query) use (&$collectionQueries): void {
            if (str_contains(strtolower($query->sql), 'catalog_collection')) {
                $collectionQueries++;
            }
        });

        $this->get(route('discover.index', ['type' => 'random']))
            ->assertOk()
            ->assertSeeLivewire('collections.catalog-collection-explorer')
            ->assertSee('data-collection-category-tree', false);

        $this->assertGreaterThan(0, $collectionQueries);
        $this->assertLessThanOrEqual(12, $collectionQueries);
    }

    public function test_recently_updated_merges_bounded_event_sources_in_one_statement_without_changing_order(): void
    {
        $this->travelTo(now()->startOfSecond());

        $newestMediaTitle = $this->playableTitleAt('Newest media title', now());
        LicensedMedia::factory()->for($newestMediaTitle)->create([
            'status' => 'published',
            'published_at' => now()->subSeconds(30),
        ]);

        $episodeTitle = $this->playableTitleAt('Episode tie title', now()->subHours(2));
        $season = Season::factory()->for($episodeTitle)->create();
        Episode::factory()->for($season)->create([
            'released_at' => now()->subMinute(),
        ]);

        $mediaTieTitle = $this->playableTitleAt('Media tie title', now()->subMinute());
        $excludedTitle = $this->playableTitleAt('Excluded title', now()->subSecond());

        $futureEpisodeTitle = $this->playableTitleWithoutUpdateEvent('Future episode title');
        $futureSeason = Season::factory()->for($futureEpisodeTitle)->create();
        Episode::factory()->for($futureSeason)->create([
            'released_at' => now()->addMinute(),
        ]);

        $draftEpisodeTitle = $this->playableTitleWithoutUpdateEvent('Draft episode title');
        $draftSeason = Season::factory()->for($draftEpisodeTitle)->create();
        Episode::factory()->for($draftSeason)->create([
            'publication_status' => PublicationStatus::Draft,
            'released_at' => now(),
        ]);

        $deletedMediaTitle = $this->playableTitleWithoutUpdateEvent('Deleted media title');
        LicensedMedia::factory()->for($deletedMediaTitle)->create([
            'status' => 'published',
            'published_at' => now(),
        ])->delete();

        $emptyEventTitle = $this->playableTitleWithoutUpdateEvent('Empty event title');
        DB::table('licensed_media')
            ->where('catalog_title_id', $emptyEventTitle->id)
            ->update(['published_at' => '']);

        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $candidates = app(CatalogPublicDiscoveryQuery::class)->candidates(
            new CatalogRecommendationContext(
                type: CatalogRecommendationType::RecentlyUpdated,
                user: null,
                locale: 'ru',
            ),
            excludedIds: [$excludedTitle->id],
        );

        $this->assertSame(
            [$newestMediaTitle->id, $episodeTitle->id, $mediaTieTitle->id],
            array_column($candidates, 'id'),
        );
        $this->assertSame(
            [
                CatalogRecommendationSource::ContentUpdate->value,
                CatalogRecommendationSource::ContentUpdate->value,
                CatalogRecommendationSource::ContentUpdate->value,
            ],
            array_column($candidates, 'source'),
        );
        $this->assertSame(
            [
                CatalogRecommendationReason::RecentlyUpdated->value,
                CatalogRecommendationReason::RecentlyUpdated->value,
                CatalogRecommendationReason::RecentlyUpdated->value,
            ],
            array_column($candidates, 'reason'),
        );
        $this->assertSame([180, 179, 178], array_column($candidates, 'score'));
        $this->assertCount(2, $statements);

        $eventStatements = collect($statements)
            ->filter(fn (string $sql): bool => str_contains($sql, 'event_at')
                && (str_contains($sql, 'from "licensed_media"') || str_contains($sql, 'from "episodes"')))
            ->values();

        $this->assertCount(1, $eventStatements);
        $this->assertStringContainsString(' union all ', $eventStatements->sole());
        $this->assertStringContainsString('from "licensed_media"', $eventStatements->sole());
        $this->assertStringContainsString('from "episodes"', $eventStatements->sole());
        $this->assertSame(2, substr_count($eventStatements->sole(), ' limit '));
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

    private function playableTitleAt(string $title, \DateTimeInterface $publishedAt): CatalogTitle
    {
        $catalogTitle = CatalogTitle::factory()->create(['title' => $title]);
        LicensedMedia::factory()->for($catalogTitle)->create([
            'status' => 'published',
            'published_at' => $publishedAt,
        ]);

        return $catalogTitle;
    }

    private function playableTitleWithoutUpdateEvent(string $title): CatalogTitle
    {
        $catalogTitle = CatalogTitle::factory()->create(['title' => $title]);
        LicensedMedia::factory()->for($catalogTitle)->create([
            'status' => 'published',
            'published_at' => null,
        ]);

        return $catalogTitle;
    }
}
