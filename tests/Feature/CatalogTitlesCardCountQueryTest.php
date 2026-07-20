<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\CatalogTitlesRequest;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Catalog\CatalogTitlesPageBuilder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogTitlesCardCountQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_catalog_hydrates_card_counts_without_correlated_title_count_subqueries(): void
    {
        $title = CatalogTitle::factory()->create([
            'indexed_at' => now(),
        ]);
        $season = Season::factory()->create(['catalog_title_id' => $title->id]);
        $episode = Episode::factory()->create(['season_id' => $season->id]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = str($query->sql)
                ->replace(['`', '"'], '')
                ->lower()
                ->squish()
                ->toString();
        });

        $data = app(CatalogTitlesPageBuilder::class)->data(
            $this->catalogRequest(['per_page' => 96]),
            includeFacets: false,
        );
        $card = $data['titles']->getCollection()->firstWhere('id', $title->id);

        $this->assertInstanceOf(CatalogTitle::class, $card);
        $this->assertSame(1, $card->getAttribute('seasons_count'));
        $this->assertSame(1, $card->getAttribute('episodes_count'));
        $this->assertSame(1, $card->getAttribute('published_media_count'));

        $correlatedCardCounts = collect($queries)->filter(
            fn (string $sql): bool => str_contains($sql, ' from catalog_titles ')
                && (str_contains($sql, '(select count(*) from seasons where catalog_titles.id = seasons.catalog_title_id')
                    || str_contains($sql, '(select count(*) from episodes inner join seasons')
                    || str_contains($sql, '(select count(*) from licensed_media where catalog_titles.id = licensed_media.catalog_title_id')),
        );

        $this->assertSame(
            [],
            $correlatedCardCounts->values()->all(),
            sprintf('Found %d correlated catalog card-count queries.', $correlatedCardCounts->count()),
        );
    }

    public function test_count_sort_keeps_its_order_and_hydrates_all_card_counts(): void
    {
        $indexedAt = now();
        $singleEpisodeTitle = CatalogTitle::factory()->create(['indexed_at' => $indexedAt]);
        $singleEpisodeSeason = Season::factory()->create(['catalog_title_id' => $singleEpisodeTitle->id]);
        Episode::factory()->create(['season_id' => $singleEpisodeSeason->id]);
        $twoEpisodeTitle = CatalogTitle::factory()->create(['indexed_at' => $indexedAt]);
        $twoEpisodeSeason = Season::factory()->create(['catalog_title_id' => $twoEpisodeTitle->id]);
        Episode::factory()->count(2)->create(['season_id' => $twoEpisodeSeason->id]);

        $data = app(CatalogTitlesPageBuilder::class)->data(
            $this->catalogRequest(['sort' => 'episodes_desc']),
            includeFacets: false,
        );
        $cards = $data['titles']->getCollection();

        $this->assertSame(
            [$twoEpisodeTitle->id, $singleEpisodeTitle->id],
            $cards->pluck('id')->all(),
        );
        $this->assertSame(2, $cards->first()->getAttribute('episodes_count'));
        $this->assertSame(1, $cards->first()->getAttribute('seasons_count'));
        $this->assertSame(0, $cards->first()->getAttribute('published_media_count'));
    }

    /** @param array<string, mixed> $query */
    private function catalogRequest(array $query): CatalogTitlesRequest
    {
        $request = CatalogTitlesRequest::create('/titles', 'GET', $query);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->setUserResolver(fn (): null => null);
        $request->validateResolved();

        return $request;
    }
}
