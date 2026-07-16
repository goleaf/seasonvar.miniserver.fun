<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogTopListCategory;
use App\Enums\ContentAudience;
use App\Enums\MediaHealthStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRating;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\CatalogTopListQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogTopListPageTest extends TestCase
{
    use RefreshDatabase;

    private ?Genre $animationGenre = null;

    public function test_four_top_list_routes_apply_distinct_catalog_boundaries(): void
    {
        $movie = $this->rankableTitle('Фильм из рейтинга', CatalogTopListCategory::Movies);
        $series = $this->rankableTitle('Сериал из рейтинга', CatalogTopListCategory::Series);
        $anime = $this->rankableTitle('Аниме из рейтинга', CatalogTopListCategory::Anime);
        $cartoon = $this->rankableTitle('Мультфильм из рейтинга', CatalogTopListCategory::Cartoons);

        $expectations = [
            CatalogTopListCategory::Movies->value => [$movie, $series, $anime, $cartoon],
            CatalogTopListCategory::Series->value => [$series, $movie, $anime, $cartoon],
            CatalogTopListCategory::Anime->value => [$anime, $movie, $series, $cartoon],
            CatalogTopListCategory::Cartoons->value => [$cartoon, $movie, $series, $anime],
        ];

        foreach ($expectations as $category => $titles) {
            $visible = $titles[0];
            $hidden = array_slice($titles, 1);
            $response = $this->get(route('top.show', ['category' => $category]));

            $response
                ->assertOk()
                ->assertSee($visible->title)
                ->assertSee('Топ 100')
                ->assertSee('data-top-list-row', false);

            foreach ($hidden as $title) {
                $response->assertDontSee(route('titles.show', $title), false);
            }
        }

        $this->get('/top')->assertRedirect(route('top.show', ['category' => CatalogTopListCategory::Movies->value]));
        $this->get('/top/not-a-category')->assertRedirect(route('home'));
    }

    public function test_ranking_uses_kinopoisk_then_imdb_and_smooths_sparse_scores(): void
    {
        $trusted = $this->rankableTitle(
            'Надёжная оценка',
            CatalogTopListCategory::Series,
            kinopoiskRating: 8.6,
            kinopoiskVotes: 50_000,
        );
        $sparse = $this->rankableTitle(
            'Один случайный голос',
            CatalogTopListCategory::Series,
            kinopoiskRating: 9.9,
            kinopoiskVotes: 1,
        );
        $imdbFallback = $this->rankableTitle(
            'Только IMDb',
            CatalogTopListCategory::Series,
            kinopoiskRating: null,
            kinopoiskVotes: 0,
            imdbRating: 8.4,
            imdbVotes: 5_000,
        );
        $kinopoiskWins = $this->rankableTitle(
            'Приоритет Кинопоиска',
            CatalogTopListCategory::Series,
            kinopoiskRating: 7.2,
            kinopoiskVotes: 20_000,
            imdbRating: 9.8,
            imdbVotes: 100_000,
        );

        $response = $this->get(route('top.show', ['category' => CatalogTopListCategory::Series->value]))->assertOk();
        $html = $response->getContent();

        $this->assertLessThan(strpos($html, $imdbFallback->title), strpos($html, $trusted->title));
        $this->assertLessThan(strpos($html, $sparse->title), strpos($html, $trusted->title));
        $this->assertStringContainsString('Кинопоиск 8,6', $html);
        $this->assertStringContainsString('IMDb 8,4', $html);
        $this->assertStringContainsString('Кинопоиск 7,2', $html);
        $this->assertStringNotContainsString('IMDb 9,8', $html);
        $this->assertStringContainsString('50 000', $html);
        $this->assertSame(1, substr_count($html, 'data-top-list-rank="1"'));
        $this->assertSame(4, substr_count($html, 'data-top-list-row'));
        $this->assertNotSame($trusted->id, $sparse->id);
        $this->assertNotSame($trusted->id, $kinopoiskWins->id);
    }

    public function test_movie_classification_groups_episode_counts_once_instead_of_correlating_each_title(): void
    {
        $this->rankableTitle('Быстрый фильм', CatalogTopListCategory::Movies);

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(CatalogTopListQuery::class)->items(CatalogTopListCategory::Movies, null);

        $rankingSql = collect(DB::getQueryLog())
            ->pluck('query')
            ->first(fn (string $query): bool => str_contains($query, 'top_weighted_score'));

        $this->assertIsString($rankingSql);
        $this->assertStringContainsString('group by "seasons"."catalog_title_id"', $rankingSql);
        $this->assertStringNotContainsString('(select count(*) from "episodes"', $rankingSql);
    }

    public function test_list_excludes_private_unrated_and_unwatchable_titles_even_for_authenticated_viewers(): void
    {
        $visible = $this->rankableTitle('Публичный доступный сериал', CatalogTopListCategory::Series);
        $private = $this->rankableTitle('Закрытый сериал', CatalogTopListCategory::Series, audience: ContentAudience::Authenticated);
        $unrated = $this->rankableTitle('Сериал без оценки', CatalogTopListCategory::Series, kinopoiskRating: null);
        $broken = $this->rankableTitle('Сериал со сломанным видео', CatalogTopListCategory::Series);
        $broken->licensedMedia()->update(['health_status' => MediaHealthStatus::Unavailable]);
        $draftMedia = $this->rankableTitle('Сериал с черновиком видео', CatalogTopListCategory::Series);
        $draftMedia->licensedMedia()->update(['status' => 'draft']);

        $response = $this
            ->actingAs(User::factory()->create())
            ->get(route('top.show', ['category' => CatalogTopListCategory::Series->value]));

        $response
            ->assertOk()
            ->assertSee($visible->title)
            ->assertDontSee($private->title)
            ->assertDontSee($unrated->title)
            ->assertDontSee($broken->title)
            ->assertDontSee($draftMedia->title);
    }

    public function test_page_is_capped_at_one_hundred_items_and_exposes_complete_seo_and_navigation(): void
    {
        $lowest = null;
        $highest = null;

        foreach (range(1, 101) as $number) {
            $title = $this->rankableTitle(
                'Рейтинговый сериал '.$number,
                CatalogTopListCategory::Series,
                kinopoiskRating: 8.0,
                kinopoiskVotes: 10_000 + $number,
            );
            $lowest ??= $title;
            $highest = $title;
        }

        $url = route('top.show', ['category' => CatalogTopListCategory::Series->value]);
        $response = $this->get($url)->assertOk();
        $html = $response->getContent();

        $this->assertInstanceOf(CatalogTitle::class, $lowest);
        $this->assertInstanceOf(CatalogTitle::class, $highest);
        $this->assertSame(100, substr_count($html, 'data-top-list-row'));
        $this->assertStringContainsString(route('titles.show', $highest), $html);
        $this->assertStringNotContainsString(route('titles.show', $lowest), $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.$url.'">', $html);
        $this->assertStringContainsString('"@type":"CollectionPage"', $html);
        $this->assertStringContainsString('"@type":"ItemList"', $html);
        $this->assertStringContainsString('"numberOfItems":100', $html);
        $this->assertStringContainsString('href="'.route('top.show', ['category' => CatalogTopListCategory::Movies->value]).'"', $html);
        $this->assertStringContainsString('Топ 100', $html);
        $this->assertStringContainsString('data-top-list-podium', $html);
        $this->assertStringContainsString('data-top-list-main', $html);
    }

    public function test_non_empty_top_lists_are_discoverable_in_static_sitemap_and_localized_route(): void
    {
        foreach (CatalogTopListCategory::cases() as $category) {
            $this->rankableTitle('Sitemap '.$category->value, $category);
        }

        $content = $this->get('/sitemap-static.xml')
            ->assertOk()
            ->assertStreamed()
            ->streamedContent();

        foreach (CatalogTopListCategory::cases() as $category) {
            $this->assertStringContainsString(route('top.show', ['category' => $category->value]), $content);
            $this->assertStringContainsString(route('localized.top.show', [
                'locale' => 'en',
                'category' => $category->value,
            ]), $content);
        }

        $localized = $this->get(route('localized.top.show', [
            'locale' => 'en',
            'category' => CatalogTopListCategory::Movies->value,
        ]))->assertOk();
        $localized->assertSee('<html lang="en">', false);
    }

    public function test_empty_category_has_an_honest_state_and_catalog_action(): void
    {
        $response = $this->get(route('top.show', ['category' => CatalogTopListCategory::Movies->value]));

        $response
            ->assertOk()
            ->assertSee('Сейчас в этой категории нет доступных тайтлов с оценками.')
            ->assertSee(route('titles.index'), false)
            ->assertDontSee('data-top-list-row', false);
    }

    private function rankableTitle(
        string $title,
        CatalogTopListCategory $category,
        ?float $kinopoiskRating = 8.0,
        int $kinopoiskVotes = 10_000,
        ?float $imdbRating = null,
        int $imdbVotes = 0,
        ContentAudience $audience = ContentAudience::Public,
    ): CatalogTitle {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => $title,
            'slug' => str($title)->slug()->append('-', fake()->unique()->numberBetween(1, 1_000_000))->toString(),
            'type' => $category === CatalogTopListCategory::Anime ? 'anime' : 'serial',
            'audience' => $audience,
        ]);

        if ($category === CatalogTopListCategory::Cartoons) {
            $catalogTitle->genres()->attach($this->animationGenre());
        }

        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
            'audience' => $audience,
        ]);
        $episodeCount = match ($category) {
            CatalogTopListCategory::Movies, CatalogTopListCategory::Cartoons => 1,
            CatalogTopListCategory::Series, CatalogTopListCategory::Anime => 2,
        };
        $episodes = Episode::factory()
            ->count($episodeCount)
            ->sequence(fn ($sequence): array => ['number' => $sequence->index + 1])
            ->create([
                'season_id' => $season->id,
                'audience' => $audience,
            ]);

        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episodes->first()->id,
            'status' => 'published',
            'published_at' => now(),
            'health_status' => MediaHealthStatus::Active,
            'audience' => $audience,
        ]);

        if ($kinopoiskRating !== null) {
            CatalogTitleRating::query()->create([
                'catalog_title_id' => $catalogTitle->id,
                'provider' => 'kinopoisk',
                'rating' => $kinopoiskRating,
                'votes' => $kinopoiskVotes,
                'raw_value' => (string) $kinopoiskRating,
            ]);
        }

        if ($imdbRating !== null) {
            CatalogTitleRating::query()->create([
                'catalog_title_id' => $catalogTitle->id,
                'provider' => 'imdb',
                'rating' => $imdbRating,
                'votes' => $imdbVotes,
                'raw_value' => (string) $imdbRating,
            ]);
        }

        return $catalogTitle;
    }

    private function animationGenre(): Genre
    {
        return $this->animationGenre ??= Genre::query()->create([
            'name' => 'анимационные',
            'slug' => 'animacionnye',
        ]);
    }
}
