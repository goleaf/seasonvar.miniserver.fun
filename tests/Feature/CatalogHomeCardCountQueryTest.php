<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Catalog\CatalogHomePageBuilder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogHomeCardCountQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_hydrates_card_counts_without_correlated_title_count_subqueries(): void
    {
        $title = CatalogTitle::factory()->create([
            'poster_url' => 'https://media.example.com/home-card-counts.jpg',
            'indexed_at' => now(),
        ]);
        $season = Season::factory()->create(['catalog_title_id' => $title->id]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = str($query->sql)
                ->replace(['`', '"'], '')
                ->lower()
                ->squish()
                ->toString();
        });

        $data = app(CatalogHomePageBuilder::class)->data();
        $homeTitle = collect($data['latestTitles'])
            ->concat($data['featuredTitles'])
            ->concat($data['videoTitles'])
            ->firstWhere('id', $title->id);
        $latestMediaTitle = collect($data['latestMedia'])
            ->firstWhere('catalog_title_id', $title->id)
            ?->catalogTitle;

        $this->assertInstanceOf(CatalogTitle::class, $homeTitle);
        $this->assertSame(1, $homeTitle->getAttribute('seasons_count'));
        $this->assertSame(1, $homeTitle->getAttribute('episodes_count'));
        $this->assertSame(1, $homeTitle->getAttribute('published_media_count'));
        $this->assertInstanceOf(CatalogTitle::class, $latestMediaTitle);
        $this->assertSame(1, $latestMediaTitle->getAttribute('seasons_count'));
        $this->assertSame(1, $latestMediaTitle->getAttribute('episodes_count'));
        $this->assertSame(1, $latestMediaTitle->getAttribute('published_media_count'));

        $correlatedCardCounts = collect($queries)->filter(
            fn (string $sql): bool => str_contains($sql, ' from catalog_titles ')
                && (str_contains($sql, '(select count(*) from seasons where catalog_titles.id = seasons.catalog_title_id')
                    || str_contains($sql, '(select count(*) from episodes inner join seasons')
                    || str_contains($sql, '(select count(*) from licensed_media where catalog_titles.id = licensed_media.catalog_title_id')),
        );

        $this->assertSame(
            [],
            $correlatedCardCounts->values()->all(),
            sprintf('Found %d correlated homepage card-count queries.', $correlatedCardCounts->count()),
        );
    }
}
