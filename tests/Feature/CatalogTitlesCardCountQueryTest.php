<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogSearchIndexStatus;
use App\Http\Requests\CatalogTitlesRequest;
use App\Models\CatalogSearchIndexState;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Catalog\CatalogTitlesPageBuilder;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('countSortCases')]
    public function test_count_sort_keeps_its_order_and_avoids_correlated_title_aggregate(
        string $sort,
        string $countAttribute,
        string $correlatedSql,
        string $groupedAlias,
        ?string $search,
    ): void {
        $indexedAt = now();
        $titleAttributes = [
            'indexed_at' => $indexedAt,
            ...($search === null ? [] : ['year' => (int) $search]),
        ];
        $lowerCountTitle = CatalogTitle::factory()->create($titleAttributes);
        $higherCountTitle = CatalogTitle::factory()->create($titleAttributes);
        $this->createCountSortRelations($lowerCountTitle, $sort, 1);
        $this->createCountSortRelations($higherCountTitle, $sort, 2);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = str($query->sql)
                ->replace(['`', '"'], '')
                ->lower()
                ->squish()
                ->toString();
        });

        $data = app(CatalogTitlesPageBuilder::class)->data(
            $this->catalogRequest([
                'sort' => $sort,
                ...($search === null ? [] : ['q' => $search]),
            ]),
            includeFacets: false,
        );
        $cards = $data['titles']->getCollection();

        $this->assertSame(
            [$higherCountTitle->id, $lowerCountTitle->id],
            $cards->pluck('id')->all(),
        );
        $this->assertSame(2, $cards->first()->getAttribute($countAttribute));

        $correlatedSortQueries = collect($queries)
            ->filter(fn (string $sql): bool => str_contains($sql, $correlatedSql));

        $this->assertSame(
            [],
            $correlatedSortQueries->values()->all(),
            sprintf('Found %d correlated queries for sort %s.', $correlatedSortQueries->count(), $sort),
        );
        $this->assertSame(
            1,
            collect($queries)->filter(fn (string $sql): bool => str_contains($sql, $groupedAlias))->count(),
            sprintf('Expected one grouped aggregate query for sort %s.', $sort),
        );
    }

    /**
     * @return iterable<string, array{string, string, string, string, string|null}>
     */
    public static function countSortCases(): iterable
    {
        yield 'episodes' => [
            'episodes_desc',
            'episodes_count',
            '(select count(*) from episodes inner join seasons',
            'catalog_episode_sort_counts',
            null,
        ];
        yield 'seasons' => [
            'seasons_desc',
            'seasons_count',
            '(select count(*) from seasons where catalog_titles.id = seasons.catalog_title_id',
            'catalog_season_sort_counts',
            null,
        ];
        yield 'video' => [
            'with_video',
            'published_media_count',
            '(select count(*) from licensed_media where catalog_titles.id = licensed_media.catalog_title_id',
            'catalog_media_sort_counts',
            null,
        ];
        yield 'ranked episodes' => [
            'episodes_desc',
            'episodes_count',
            '(select count(*) from episodes inner join seasons',
            'catalog_episode_sort_counts',
            '2024',
        ];
        yield 'ranked seasons' => [
            'seasons_desc',
            'seasons_count',
            '(select count(*) from seasons where catalog_titles.id = seasons.catalog_title_id',
            'catalog_season_sort_counts',
            '2024',
        ];
        yield 'ranked video' => [
            'with_video',
            'published_media_count',
            '(select count(*) from licensed_media where catalog_titles.id = licensed_media.catalog_title_id',
            'catalog_media_sort_counts',
            '2024',
        ];
    }

    public function test_ranked_search_keeps_count_sort_order_with_the_grouped_aggregate(): void
    {
        $indexedAt = now();
        $lowerCountTitle = CatalogTitle::factory()->create([
            'title' => 'Сортировка поиска один',
            'indexed_at' => $indexedAt,
        ]);
        $higherCountTitle = CatalogTitle::factory()->create([
            'title' => 'Сортировка поиска два',
            'indexed_at' => $indexedAt,
        ]);
        $this->createCountSortRelations($lowerCountTitle, 'episodes_desc', 1);
        $this->createCountSortRelations($higherCountTitle, 'episodes_desc', 2);
        app(CatalogSearchIndexer::class)->indexTitleIds([
            $lowerCountTitle->id,
            $higherCountTitle->id,
        ]);
        CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID)->update([
            'version' => CatalogSearchIndexer::INDEX_VERSION,
            'status' => CatalogSearchIndexStatus::Ready,
            'source_count' => 2,
            'document_count' => 2,
            'completed_at' => now(),
        ]);

        $data = app(CatalogTitlesPageBuilder::class)->data(
            $this->catalogRequest([
                'q' => 'сортировка поиска',
                'sort' => 'episodes_desc',
            ]),
            includeFacets: false,
        );

        $this->assertSame(2, $data['titles']->total());
        $this->assertSame(
            [$higherCountTitle->id, $lowerCountTitle->id],
            $data['titles']->getCollection()->pluck('id')->all(),
        );
        $this->assertSame(2, $data['titles']->getCollection()->first()->episodes_count);
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

    private function createCountSortRelations(CatalogTitle $title, string $sort, int $count): void
    {
        if ($sort === 'seasons_desc') {
            foreach (range(1, $count) as $number) {
                Season::factory()->create([
                    'catalog_title_id' => $title->id,
                    'number' => $number,
                ]);
            }

            return;
        }

        if ($sort === 'episodes_desc') {
            $season = Season::factory()->create(['catalog_title_id' => $title->id]);

            foreach (range(1, $count) as $number) {
                Episode::factory()->create([
                    'season_id' => $season->id,
                    'number' => $number,
                ]);
            }

            return;
        }

        LicensedMedia::factory()->count($count)->create([
            'catalog_title_id' => $title->id,
            'season_id' => null,
            'episode_id' => null,
            'status' => 'published',
            'published_at' => now(),
        ]);
    }
}
