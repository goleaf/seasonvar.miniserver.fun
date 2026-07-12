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
}
