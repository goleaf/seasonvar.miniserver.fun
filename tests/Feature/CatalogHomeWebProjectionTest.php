<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogRecommendationType;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRating;
use App\Models\LicensedMedia;
use App\Services\Catalog\CatalogHomePageBuilder;
use App\Services\Catalog\CatalogHomeSnapshotCache;
use App\Services\Catalog\CatalogTaxonomyRegistry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogHomeWebProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_projection_is_bounded_while_api_keeps_the_full_snapshot(): void
    {
        $this->seedHomeProjectionTitles();

        $snapshot = app(CatalogHomeSnapshotCache::class)->refresh();
        $builder = app(CatalogHomePageBuilder::class);
        $full = $builder->data();
        $web = $builder->webData();

        $this->assertCount(16, $snapshot['latest_title_ids']);
        $this->assertCount(16, $full['latestTitles']);
        $this->assertCount(12, $web['latestTitles']);
        $this->assertCount(12, $full['featuredTitles']);
        $this->assertCount(8, $full['videoTitles']);
        $this->assertCount(8, $web['videoTitles']);
        $this->assertCount(12, $full['latestMedia']);
        $this->assertEmpty($web['featuredTitles']);
        $this->assertEmpty($web['latestMedia']);
        $this->assertSame(
            collect($full['latestTitles'])->take(12)->pluck('id')->all(),
            collect($web['latestTitles'])->pluck('id')->all(),
        );
        $this->assertTrue($web['hasMoreLatestTitles']);
        $this->assertSame(
            route('discover.index', ['type' => CatalogRecommendationType::RecentlyUpdated->value]),
            $web['recentlyUpdatedUrl'],
        );
        $this->assertEmpty($web['homeRecommendationItems']);

        $sharedTitleId = collect($full['latestTitles'])
            ->pluck('id')
            ->intersect(collect($full['videoTitles'])->pluck('id'))
            ->first();
        $latestTitle = collect($full['latestTitles'])->firstWhere('id', $sharedTitleId);
        $videoTitle = collect($full['videoTitles'])->firstWhere('id', $sharedTitleId);

        $this->assertIsInt($sharedTitleId);
        $this->assertInstanceOf(CatalogTitle::class, $latestTitle);
        $this->assertInstanceOf(CatalogTitle::class, $videoTitle);
        $this->assertNotSame($latestTitle, $videoTitle);
        $this->assertTrue($latestTitle->hasAttribute('content_added_at'));
        $this->assertFalse($videoTitle->hasAttribute('content_added_at'));
        $this->assertFalse($videoTitle->relationLoaded('latestSeason'));
        $this->assertFalse($latestTitle->relationLoaded('latestSeason'));

        $webResponse = $this->get(route('home'))->assertOk();
        $webHtml = $webResponse->getContent();

        $this->assertSame(12, substr_count($webHtml, 'data-home-latest-update-card'));
        $webResponse
            ->assertSee('data-home-latest-updates-all', false)
            ->assertSee($web['recentlyUpdatedUrl'], false)
            ->assertSeeText(__('home.actions.view_all'));

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonCount(16, 'data.latest_titles');
    }

    public function test_web_projection_hydrates_shared_card_sections_in_one_query_group(): void
    {
        $this->seedHomeProjectionTitles();
        app(CatalogHomeSnapshotCache::class)->refresh();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = str($query->sql)
                ->replace(['`', '"'], '')
                ->lower()
                ->squish()
                ->toString();
        });

        $web = app(CatalogHomePageBuilder::class)->webData();
        $cardTitleHydrations = collect($queries)->filter(
            fn (string $sql): bool => str_starts_with(
                $sql,
                'select id, slug, title, original_title, type, year, description, poster_url, indexed_at from catalog_titles where',
            ),
        );
        $latestMediaHydrations = collect($queries)->filter(
            fn (string $sql): bool => str_starts_with(
                $sql,
                'select id, catalog_title_id, season_id, episode_id, title, quality, translation_name, format, published_at from licensed_media where',
            ) && str_contains($sql, 'licensed_media.id in'),
        );
        $this->assertEmpty($web['featuredTitles']);
        $this->assertEmpty($web['latestMedia']);
        $this->assertCount(1, $cardTitleHydrations, implode("\n", $queries));
        $this->assertEmpty($latestMediaHydrations, implode("\n", $latestMediaHydrations->all()));

        foreach ($this->cardRelationQueryMatchers() as $relation => $matches) {
            $relationHydrations = collect($queries)->filter($matches);

            $this->assertCount(
                1,
                $relationHydrations,
                "{$relation}:\n".implode("\n", $relationHydrations->all()),
            );
        }
    }

    public function test_home_card_rating_eager_load_uses_the_existing_title_provider_index(): void
    {
        $this->seedHomeProjectionTitles();
        $validTitle = CatalogTitle::query()
            ->where('slug', 'homepage-web-projection-1')
            ->firstOrFail();
        $outOfRangeTitle = CatalogTitle::query()
            ->where('slug', 'homepage-web-projection-2')
            ->firstOrFail();
        CatalogTitleRating::query()->create([
            'catalog_title_id' => $validTitle->id,
            'provider' => 'kinopoisk',
            'rating' => 8.20,
        ]);
        CatalogTitleRating::query()->create([
            'catalog_title_id' => $validTitle->id,
            'provider' => 'metacritic',
            'rating' => 9.10,
        ]);
        CatalogTitleRating::query()->create([
            'catalog_title_id' => $outOfRangeTitle->id,
            'provider' => 'imdb',
            'rating' => 11.00,
        ]);
        app(CatalogHomeSnapshotCache::class)->refresh();
        $ratingQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$ratingQueries): void {
            if (str_contains($query->sql, 'catalog_title_ratings')) {
                $ratingQueries[] = $query;
            }
        });

        $web = app(CatalogHomePageBuilder::class)->webData();
        $validCard = $web['latestTitles']->firstWhere('id', $validTitle->id);
        $outOfRangeCard = $web['latestTitles']->firstWhere('id', $outOfRangeTitle->id);
        $ratingQuery = collect($ratingQueries)->sole();
        $normalizedSql = str($ratingQuery->sql)
            ->replace(['`', '"'], '')
            ->lower()
            ->squish()
            ->toString();

        $this->assertInstanceOf(CatalogTitle::class, $validCard);
        $this->assertInstanceOf(CatalogTitle::class, $outOfRangeCard);
        $this->assertSame(['kinopoisk'], $validCard->ratings->pluck('provider')->all());
        $this->assertSame(
            ['catalog_title_id', 'provider', 'rating'],
            array_keys($validCard->ratings->sole()->getAttributes()),
        );
        $this->assertEmpty($outOfRangeCard->ratings);
        $this->assertStringContainsString(
            'from catalog_title_ratings indexed by catalog_title_ratings_catalog_title_id_provider_unique where',
            $normalizedSql,
        );

        $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$ratingQuery->toRawSql()))
            ->pluck('detail')
            ->implode("\n");

        $this->assertStringContainsString(
            'catalog_title_ratings_catalog_title_id_provider_unique',
            $plan,
        );
        $this->assertStringNotContainsString(
            'catalog_ratings_provider_score_votes_title_idx',
            $plan,
        );
    }

    public function test_full_projection_hydrates_shared_card_sections_in_one_query_group(): void
    {
        $this->seedHomeProjectionTitles();
        app(CatalogHomeSnapshotCache::class)->refresh();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = str($query->sql)
                ->replace(['`', '"'], '')
                ->lower()
                ->squish()
                ->toString();
        });

        $full = app(CatalogHomePageBuilder::class)->data();
        $cardTitleHydrations = collect($queries)->filter(
            fn (string $sql): bool => str_starts_with(
                $sql,
                'select id, slug, title, original_title, type, year, description, poster_url, indexed_at from catalog_titles where',
            ),
        );
        $this->assertCount(16, $full['latestTitles']);
        $this->assertCount(12, $full['featuredTitles']);
        $this->assertCount(8, $full['videoTitles']);
        $this->assertEmpty($full['homeRecommendationItems']);
        $this->assertCount(1, $cardTitleHydrations, implode("\n", $queries));

        foreach ($this->cardRelationQueryMatchers() as $relation => $matches) {
            $relationHydrations = collect($queries)->filter($matches);

            $this->assertCount(
                1,
                $relationHydrations,
                "{$relation}:\n".implode("\n", $relationHydrations->all()),
            );
        }
    }

    /**
     * @return array<string, \Closure(string): bool>
     */
    private function cardRelationQueryMatchers(): array
    {
        $matchers = [
            'genres' => fn (string $sql): bool => str_contains(
                $sql,
                'from genres inner join catalog_title_genre',
            ),
            'countries' => fn (string $sql): bool => str_contains(
                $sql,
                'from countries inner join catalog_title_country',
            ),
            'ageRatings' => fn (string $sql): bool => str_contains(
                $sql,
                'from age_ratings inner join age_rating_catalog_title',
            ),
            'translations' => fn (string $sql): bool => str_contains(
                $sql,
                'from translations inner join catalog_title_translation',
            ),
            'tags' => fn (string $sql): bool => str_contains(
                $sql,
                'from tags inner join catalog_title_tag',
            ),
            'ratings' => fn (string $sql): bool => str_starts_with(
                $sql,
                'select catalog_title_ratings.catalog_title_id, catalog_title_ratings.provider, catalog_title_ratings.rating from catalog_title_ratings indexed by catalog_title_ratings_catalog_title_id_provider_unique where',
            ),
        ];

        return collect(array_keys(app(CatalogTaxonomyRegistry::class)->cardSummaryLoads()))
            ->mapWithKeys(fn (string $relation): array => [$relation => $matchers[$relation]])
            ->all();
    }

    private function seedHomeProjectionTitles(): void
    {
        $this->travelTo(now()->setDate(2026, 7, 26)->setTime(12, 0));

        foreach (range(1, 16) as $index) {
            $createdAt = now()->subMinutes($index);
            $title = CatalogTitle::factory()->create([
                'slug' => "homepage-web-projection-{$index}",
                'title' => "Главная web-проекция {$index}",
                'poster_url' => "https://media.example.com/homepage-web-projection-{$index}.jpg",
                'indexed_at' => $createdAt,
            ]);

            LicensedMedia::factory()->create([
                'catalog_title_id' => $title->id,
                'status' => 'published',
                'published_at' => $createdAt,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }
}
