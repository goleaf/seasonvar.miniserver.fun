<?php

namespace Tests\Feature;

use App\Enums\ContentAudience;
use App\Enums\PublicationStatus;
use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogAdvancedFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_years_are_or_and_multiple_values_inside_relation_dimensions_are_and(): void
    {
        $russia = Country::query()->create(['name' => 'Россия', 'slug' => 'rossiya']);
        $canada = Country::query()->create(['name' => 'Канада', 'slug' => 'kanada']);
        $actor = Actor::query()->create(['name' => 'Иван Петров', 'slug' => 'ivan-petrov']);

        $firstMatch = CatalogTitle::factory()->create(['title' => 'Обе страны 2023', 'slug' => 'obe-strany-2023', 'year' => 2023]);
        $firstMatch->countries()->attach([$russia->id, $canada->id]);
        $firstMatch->actors()->attach($actor);

        $secondMatch = CatalogTitle::factory()->create(['title' => 'Обе страны 2024', 'slug' => 'obe-strany-2024', 'year' => 2024]);
        $secondMatch->countries()->attach([$russia->id, $canada->id]);
        $secondMatch->actors()->attach($actor);

        $withoutActor = CatalogTitle::factory()->create(['title' => 'Россия без Ивана', 'slug' => 'rossiya-bez-ivana', 'year' => 2024]);
        $withoutActor->countries()->attach($russia);

        $wrongYear = CatalogTitle::factory()->create(['title' => 'Сериал 2022', 'slug' => 'serial-2022', 'year' => 2022]);
        $wrongYear->countries()->attach($russia);
        $wrongYear->actors()->attach($actor);

        $this->get(route('titles.index', [
            'year' => [2023, 2024],
            'country' => ['rossiya', 'kanada'],
            'actor' => ['ivan-petrov'],
        ]))
            ->assertOk()
            ->assertSeeText('Обе страны 2023')
            ->assertSeeText('Обе страны 2024')
            ->assertDontSeeText('Россия без Ивана')
            ->assertDontSeeText('Сериал 2022');
    }

    public function test_country_exclusion_removes_matching_titles(): void
    {
        $russia = Country::query()->create(['name' => 'Россия', 'slug' => 'rossiya']);
        $usa = Country::query()->create(['name' => 'США', 'slug' => 'ssha']);
        $russianTitle = CatalogTitle::factory()->create(['title' => 'Российский сериал', 'slug' => 'rossiiskii-serial']);
        $americanTitle = CatalogTitle::factory()->create(['title' => 'Американский сериал', 'slug' => 'amerikanskii-serial']);
        $russianTitle->countries()->attach($russia);
        $americanTitle->countries()->attach($usa);

        $this->get(route('titles.index', ['exclude_country' => ['ssha']]))
            ->assertOk()
            ->assertSeeText('Российский сериал')
            ->assertDontSeeText('Американский сериал');
    }

    public function test_catalog_visibility_and_facets_are_resolved_for_the_current_user(): void
    {
        $memberGenre = Genre::query()->create(['name' => 'Для участников', 'slug' => 'dlia-uchastnikov']);
        $publicTitle = CatalogTitle::factory()->create([
            'title' => 'Публичный сериал',
            'slug' => 'publichnyi-serial',
        ]);
        $memberTitle = CatalogTitle::factory()->create([
            'title' => 'Сериал для участника',
            'slug' => 'serial-dlia-uchastnika',
            'audience' => ContentAudience::Authenticated,
        ]);
        $memberTitle->genres()->attach($memberGenre);
        CatalogTitle::factory()->create([
            'title' => 'Будущий сериал',
            'slug' => 'budushchii-serial',
            'available_from' => now()->addDay(),
        ]);
        CatalogTitle::factory()->create([
            'title' => 'Скрытый сериал',
            'slug' => 'skrytyi-serial',
            'publication_status' => PublicationStatus::Hidden,
        ]);

        $this->get(route('titles.index'))
            ->assertOk()
            ->assertSeeText($publicTitle->title)
            ->assertDontSeeText($memberTitle->title)
            ->assertDontSeeText($memberGenre->name)
            ->assertDontSeeText('Будущий сериал')
            ->assertDontSeeText('Скрытый сериал');

        $this->actingAs(User::factory()->create())
            ->get(route('titles.index'))
            ->assertOk()
            ->assertSeeText($publicTitle->title)
            ->assertSeeText($memberTitle->title)
            ->assertSeeText($memberGenre->name)
            ->assertDontSeeText('Будущий сериал')
            ->assertDontSeeText('Скрытый сериал');
    }

    public function test_multi_value_pivot_filters_keep_one_row_and_exact_paginator_total(): void
    {
        $drama = Genre::query()->create(['name' => 'Драма', 'slug' => 'drama']);
        $detective = Genre::query()->create(['name' => 'Детектив', 'slug' => 'detective']);
        $matching = CatalogTitle::factory()->create([
            'title' => 'Один подходящий сериал',
            'slug' => 'odin-podkhodiashchii-serial',
        ]);
        $matching->genres()->attach([$drama->id, $detective->id]);
        $partial = CatalogTitle::factory()->create([
            'title' => 'Только драма',
            'slug' => 'tolko-drama',
        ]);
        $partial->genres()->attach($drama);

        $this->get(route('titles.index', ['genre' => ['drama', 'detective']]))
            ->assertOk()
            ->assertSeeText('Найдено сейчас: 1')
            ->assertSeeText($matching->title)
            ->assertDontSeeText($partial->title);
    }

    public function test_unknown_title_context_never_falls_back_to_the_full_catalog(): void
    {
        CatalogTitle::factory()->create([
            'title' => 'Не должен появиться',
            'slug' => 'ne-dolzhen-poiavitsia',
        ]);

        $this->get(route('titles.index', ['title' => 'neizvestnyi-serial']))
            ->assertOk()
            ->assertSeeText('Ничего не найдено.')
            ->assertDontSeeText('Не должен появиться');
    }
}
