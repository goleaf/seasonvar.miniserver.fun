<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogAdvancedFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_years_are_or_and_relation_dimensions_are_and(): void
    {
        $russia = Country::query()->create(['name' => 'Россия', 'slug' => 'rossiya']);
        $canada = Country::query()->create(['name' => 'Канада', 'slug' => 'kanada']);
        $actor = Actor::query()->create(['name' => 'Иван Петров', 'slug' => 'ivan-petrov']);

        $russianMatch = CatalogTitle::factory()->create(['title' => 'Россия с Иваном', 'slug' => 'rossiya-s-ivanom', 'year' => 2023]);
        $russianMatch->countries()->attach($russia);
        $russianMatch->actors()->attach($actor);

        $canadianMatch = CatalogTitle::factory()->create(['title' => 'Канада с Иваном', 'slug' => 'kanada-s-ivanom', 'year' => 2024]);
        $canadianMatch->countries()->attach($canada);
        $canadianMatch->actors()->attach($actor);

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
            ->assertSeeText('Россия с Иваном')
            ->assertSeeText('Канада с Иваном')
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
}
