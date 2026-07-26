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

    public function test_list_card_renders_a_bounded_safe_summary_with_requested_metadata_and_details_action(): void
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
        $title->setAttribute('episodes_count', 16);

        $component = new TitleCard($title);
        $html = Blade::render(
            '<x-catalog.title-card :title="$title" layout="list" />',
            ['title' => $title],
        );

        $this->assertNotNull($component->descriptionExcerpt);
        $this->assertLessThanOrEqual(240, mb_strlen($component->descriptionExcerpt));
        $this->assertStringNotContainsString('private-internal-text', $component->descriptionExcerpt);
        $this->assertStringNotContainsString('НЕ ДОЛЖНО ПОПАСТЬ', $component->descriptionExcerpt);
        $this->assertSame('КиноПоиск 8,2', $component->ratingLabel);
        $this->assertSame(3, $component->cardGenres->count());
        $this->assertStringContainsString('data-title-card-description', $html);
        $this->assertStringContainsString('line-clamp-3', $html);
        $this->assertStringContainsString('data-title-card-details', $html);
        $this->assertStringContainsString('Подробнее', $html);
        $this->assertStringContainsString('2024', $html);
        $this->assertStringContainsString('КиноПоиск 8,2', $html);
        $this->assertStringContainsString('16 серий', $html);
        $this->assertStringContainsString('compact-drama', $html);
        $this->assertStringContainsString('compact-detective', $html);
        $this->assertStringContainsString('compact-thriller', $html);
        $this->assertStringNotContainsString('compact-melodrama', $html);
        $this->assertStringNotContainsString('compact-south-korea', $html);
        $this->assertStringNotContainsString('private-internal-text', $html);
        $this->assertStringNotContainsString('НЕ ДОЛЖНО ПОПАСТЬ', $html);
    }

    public function test_recommendation_card_renders_only_the_primary_reason(): void
    {
        $title = CatalogTitle::factory()->make([
            'title' => 'Одна причина',
            'slug' => 'single-reason',
            'description' => str_repeat('Короткое содержание рекомендации. ', 20),
        ]);
        $title->setAttribute('episodes_count', 12);

        $html = Blade::render(
            '<x-catalog.title-card :title="$title" layout="recommendation" :reason-labels="$reasons" />',
            [
                'title' => $title,
                'reasons' => ['Похожие жанры и темы', 'Та же страна производства', 'Общие актёры'],
            ],
        );

        $this->assertStringContainsString('Почему это показано', $html);
        $this->assertStringContainsString('Похожие жанры и темы', $html);
        $this->assertStringNotContainsString('Та же страна производства', $html);
        $this->assertStringNotContainsString('Общие актёры', $html);
        $this->assertStringContainsString('line-clamp-3', $html);
        $this->assertStringContainsString('data-title-card-details', $html);
    }

    public function test_titles_page_eager_loads_one_bounded_rating_relation_for_all_cards(): void
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
        $ratingQueries = collect($queries)
            ->filter(fn (string $sql): bool => str_contains($sql, 'from "catalog_title_ratings"'))
            ->values();

        $response
            ->assertSeeText('КиноПоиск 7,6')
            ->assertSeeText('2 серии')
            ->assertSeeText('Подробнее');
        $this->assertCount(1, $ratingQueries);
        $this->assertStringContainsString(
            'select "catalog_title_ratings"."catalog_title_id", "catalog_title_ratings"."provider", "catalog_title_ratings"."rating"',
            $ratingQueries->sole(),
        );
        $this->assertStringContainsString(
            'from "catalog_title_ratings" INDEXED BY "catalog_title_ratings_catalog_title_id_provider_unique"',
            $ratingQueries->sole(),
        );
    }
}
