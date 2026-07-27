<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleRating;
use App\Models\Country;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\Season;
use App\View\Components\Catalog\TitleCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogCompactTitleCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_grid_card_keeps_the_title_dominant_and_renders_one_rating_two_genres_and_accessible_actions(): void
    {
        app()->setLocale('ru');
        $title = CatalogTitle::factory()->create([
            'title' => 'Очень длинное русское название сериала, которое занимает не больше двух строк',
            'original_title' => 'The Original Title That Must Stay On One Line',
            'slug' => 'new-grid-card',
            'year' => 2020,
            'description' => 'Полное описание нельзя показывать в grid-карточке.',
        ]);
        $genres = collect([
            ['name' => 'Детектив', 'slug' => 'grid-detective'],
            ['name' => 'Триллер', 'slug' => 'grid-thriller'],
            ['name' => 'Мелодрама', 'slug' => 'grid-melodrama'],
        ])->map(fn (array $attributes): Genre => Genre::query()->create($attributes));
        $title->genres()->attach($genres->pluck('id')->all());
        CatalogTitleRating::query()->create([
            'catalog_title_id' => $title->id,
            'provider' => 'imdb',
            'rating' => 8.50,
        ]);
        CatalogTitleRating::query()->create([
            'catalog_title_id' => $title->id,
            'provider' => 'kinopoisk',
            'rating' => 8.30,
        ]);
        $title->load(['genres', 'ratings']);
        $title->setAttribute('seasons_count', 1);
        $title->setAttribute('episodes_count', 16);
        $title->setAttribute('published_media_count', 1);
        $title->setAttribute('card_is_adult', true);
        $title->setAttribute('card_has_new_episode', true);

        $html = Blade::render(
            '<x-catalog.title-card :title="$title" layout="grid" :show-description="false" interactive viewer-authenticated />',
            ['title' => $title],
        );

        $this->assertStringContainsString('data-title-card-title', $html);
        $this->assertStringContainsString('line-clamp-2', $html);
        $this->assertStringContainsString('data-title-card-original-title', $html);
        $this->assertStringContainsString('line-clamp-1', $html);
        $this->assertStringContainsString('data-title-card-new-episode', $html);
        $this->assertStringContainsString('data-title-card-age-rating', $html);
        $this->assertStringContainsString('data-title-card-rating', $html);
        $this->assertSame(1, substr_count($html, 'data-title-card-rating'));
        $this->assertStringContainsString('IMDb 8,5', $html);
        $this->assertStringNotContainsString('КиноПоиск 8,3', $html);
        $this->assertStringContainsString('1 сезон', $html);
        $this->assertStringContainsString('grid-detective', $html);
        $this->assertStringContainsString('grid-thriller', $html);
        $this->assertStringNotContainsString('grid-melodrama', $html);
        $this->assertStringNotContainsString('Полное описание', $html);
        $this->assertStringContainsString('data-title-card-actions', $html);
        $this->assertStringContainsString('data-title-card-watch', $html);
        $this->assertStringContainsString('data-title-card-library', $html);
        $this->assertStringContainsString('data-title-card-menu', $html);
        $this->assertStringContainsString('focus-visible:ring-4', $html);
        $this->assertStringContainsString('wire:click="setCardWatchlist(', $html);
    }

    public function test_list_card_renders_a_bounded_safe_summary_with_requested_metadata_and_actions(): void
    {
        app()->setLocale('ru');
        $description = '<script>private-internal-text</script><strong>Начало краткого описания.</strong> '
            .str_repeat('Продолжение истории помогает проверить ограничение полного текста. ', 8)
            .'НЕ ДОЛЖНО ПОПАСТЬ В КАРТОЧКУ';
        $title = CatalogTitle::factory()->create([
            'title' => 'Компактная карточка',
            'slug' => 'compact-card',
            'year' => 2024,
            'description' => $description,
        ]);
        $genres = collect([
            ['name' => 'Драма', 'slug' => 'compact-drama'],
            ['name' => 'Детектив', 'slug' => 'compact-detective'],
            ['name' => 'Триллер', 'slug' => 'compact-thriller'],
            ['name' => 'Мелодрама', 'slug' => 'compact-melodrama'],
        ])->map(fn (array $attributes): Genre => Genre::query()->create($attributes));
        $country = Country::query()->create([
            'name' => 'Республика Корея',
            'slug' => 'compact-south-korea',
        ]);
        $title->genres()->attach($genres->pluck('id')->all());
        $title->countries()->attach($country);
        CatalogTitleRating::query()->create([
            'catalog_title_id' => $title->id,
            'provider' => 'imdb',
            'rating' => 9.10,
        ]);
        CatalogTitleRating::query()->create([
            'catalog_title_id' => $title->id,
            'provider' => 'kinopoisk',
            'rating' => 8.20,
        ]);
        $title->load(['genres', 'countries', 'ratings']);
        $title->setAttribute('seasons_count', 1);
        $title->setAttribute('episodes_count', 16);
        $title->setAttribute('published_media_count', 1);
        $title->setAttribute('card_country_name', 'Республика Корея');

        $component = new TitleCard($title, layout: 'list', interactive: true, viewerAuthenticated: true);
        $html = Blade::render(
            '<x-catalog.title-card :title="$title" layout="list" interactive viewer-authenticated />',
            ['title' => $title],
        );

        $this->assertNotNull($component->descriptionExcerpt);
        $this->assertLessThanOrEqual(240, mb_strlen($component->descriptionExcerpt));
        $this->assertStringNotContainsString('private-internal-text', $component->descriptionExcerpt);
        $this->assertStringNotContainsString('НЕ ДОЛЖНО ПОПАСТЬ', $component->descriptionExcerpt);
        $this->assertSame(['IMDb 9,1', 'КиноПоиск 8,2'], $component->ratingLabels);
        $this->assertSame(2, $component->cardGenres->count());
        $this->assertStringContainsString('data-title-card-description', $html);
        $this->assertStringContainsString('line-clamp-3', $html);
        $this->assertStringContainsString('data-title-card-details', $html);
        $this->assertStringContainsString('Подробнее', $html);
        $this->assertStringContainsString('2024', $html);
        $this->assertStringContainsString('Республика Корея', $html);
        $this->assertStringContainsString('IMDb 9,1', $html);
        $this->assertStringContainsString('КиноПоиск 8,2', $html);
        $this->assertStringContainsString('1 сезон', $html);
        $this->assertStringContainsString('16 серий', $html);
        $this->assertStringContainsString('compact-drama', $html);
        $this->assertStringContainsString('compact-detective', $html);
        $this->assertStringNotContainsString('compact-thriller', $html);
        $this->assertStringNotContainsString('compact-melodrama', $html);
        $this->assertStringNotContainsString('private-internal-text', $html);
        $this->assertStringNotContainsString('НЕ ДОЛЖНО ПОПАСТЬ', $html);
        $this->assertStringContainsString('data-title-card-watch', $html);
        $this->assertStringContainsString('data-title-card-library', $html);
        $this->assertStringContainsString('data-title-card-menu', $html);
    }

    public function test_compact_layout_preserves_its_existing_three_genre_contract(): void
    {
        $title = CatalogTitle::factory()->create([
            'title' => 'Компактный совместимый layout',
        ]);
        $genres = collect(range(1, 4))->map(fn (int $number): Genre => Genre::query()->create([
            'name' => "Компактный жанр {$number}",
            'slug' => "compact-compatible-genre-{$number}",
        ]));
        $title->genres()->attach($genres);
        $title->load('genres');

        $html = Blade::render(
            '<x-catalog.title-card :title="$title" layout="compact" />',
            ['title' => $title],
        );

        $this->assertStringContainsString('data-ui-poster-layout="compact"', $html);
        $this->assertStringContainsString('compact-compatible-genre-1', $html);
        $this->assertStringContainsString('compact-compatible-genre-2', $html);
        $this->assertStringContainsString('compact-compatible-genre-3', $html);
        $this->assertStringNotContainsString('compact-compatible-genre-4', $html);
        $this->assertStringNotContainsString('data-title-card-actions', $html);
    }

    public function test_recommendation_card_prioritizes_three_reasons_and_bounded_metadata_over_long_copy(): void
    {
        app()->setLocale('ru');
        $title = CatalogTitle::factory()->create([
            'title' => 'Компактная похожая рекомендация',
            'original_title' => 'Compact Similar Recommendation',
            'slug' => 'single-reason',
            'description' => str_repeat('Короткое содержание рекомендации. ', 20),
            'year' => 2015,
        ]);
        CatalogTitleRating::query()->create([
            'catalog_title_id' => $title->id,
            'provider' => 'imdb',
            'rating' => 7.70,
        ]);
        CatalogTitleRating::query()->create([
            'catalog_title_id' => $title->id,
            'provider' => 'kinopoisk',
            'rating' => 8.20,
        ]);
        $title->load('ratings');
        $title->setAttribute('seasons_count', 1);
        $title->setAttribute('episodes_count', 12);

        $component = new TitleCard(
            $title,
            layout: 'recommendation',
            reasonLabels: ['Похожие жанры и темы', 'Та же страна производства', 'Общие актёры', 'Общий режиссёр'],
        );
        $html = Blade::render(
            '<x-catalog.title-card :title="$title" layout="recommendation" :reason-labels="$reasons" reason-heading="Почему похож" />',
            [
                'title' => $title,
                'reasons' => ['Похожие жанры и темы', 'Та же страна производства', 'Общие актёры', 'Общий режиссёр'],
            ],
        );

        $this->assertLessThanOrEqual(180, mb_strlen((string) $component->descriptionExcerpt));
        $this->assertSame(
            ['Похожие жанры и темы', 'Та же страна производства', 'Общие актёры'],
            $component->recommendationReasons,
        );
        $this->assertStringContainsString('Почему похож', $html);
        $this->assertStringContainsString('Похожие жанры и темы', $html);
        $this->assertStringContainsString('Та же страна производства', $html);
        $this->assertStringContainsString('Общие актёры', $html);
        $this->assertStringNotContainsString('Общий режиссёр', $html);
        $this->assertStringContainsString('data-recommendation-reasons', $html);
        $this->assertStringContainsString('line-clamp-2', $html);
        $this->assertStringContainsString('line-clamp-1', $html);
        $this->assertStringContainsString('2015', $html);
        $this->assertStringContainsString('1 сезон', $html);
        $this->assertStringContainsString('IMDb 7,7', $html);
        $this->assertStringNotContainsString('КиноПоиск 8,2', $html);
        $this->assertStringContainsString('data-title-card-details', $html);
    }

    public function test_titles_page_loads_one_bounded_card_metadata_union_for_all_cards(): void
    {
        app()->setLocale('ru');
        $title = CatalogTitle::factory()->create([
            'title' => 'Карточка с рейтингом',
            'slug' => 'card-with-rating',
            'year' => 2025,
        ]);
        $season = Season::factory()->for($title)->create();
        Episode::factory()
            ->count(2)
            ->for($season)
            ->sequence(
                ['number' => 1],
                ['number' => 2],
            )
            ->create();
        CatalogTitleRating::query()->create([
            'catalog_title_id' => $title->id,
            'provider' => 'kinopoisk',
            'rating' => 7.60,
        ]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->get(route('titles.index'))->assertOk();
        $metadataQueries = collect($queries)
            ->filter(
                fn (string $sql): bool => str_contains($sql, 'from "catalog_title_ratings"')
                    && str_contains(strtolower($sql), 'union all'),
            )
            ->values();

        $response
            ->assertSeeText('КиноПоиск 7,6')
            ->assertSeeText('1 сезон')
            ->assertSee('data-ui-poster-layout="grid"', false)
            ->assertSeeText('Подробнее')
            ->assertSee('data-title-card-actions', false);
        $this->assertCount(1, $metadataQueries);
        $this->assertStringContainsString(
            'from "catalog_title_ratings"',
            $metadataQueries->sole(),
        );
        $this->assertStringContainsString(
            'union all',
            strtolower($metadataQueries->sole()),
        );
    }
}
