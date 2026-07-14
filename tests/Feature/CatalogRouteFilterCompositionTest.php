<?php

namespace Tests\Feature;

use App\Livewire\CatalogSeries;
use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Genre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogRouteFilterCompositionTest extends TestCase
{
    use RefreshDatabase;

    public function test_country_route_pagination_has_a_get_fallback_and_preserves_country_state(): void
    {
        $turkey = Country::query()->create(['name' => 'Турция', 'slug' => 'turciia']);

        CatalogTitle::factory()->count(30)->create()->each(
            fn (CatalogTitle $title) => $title->countries()->attach($turkey),
        );

        $content = $this->get(route('titles.taxonomy', [
            'type' => 'country',
            'taxonomy' => $turkey->slug,
            'country' => [$turkey->slug],
        ]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<a[^>]+href="[^"]*country(?:%5B0%5D|\[0\])=turciia[^"]*page=2"[^>]+wire:click\.prevent="gotoPage\(2, \'page\'\)"/s',
            html_entity_decode($content),
        );
    }

    public function test_route_country_and_query_publication_type_can_be_removed_independently(): void
    {
        $russia = Country::query()->create(['name' => 'Россия', 'slug' => 'rossiia']);
        $query = [
            'country' => [$russia->slug],
            'publication_type' => ['show'],
        ];

        Livewire::withQueryParams($query)
            ->test(CatalogSeries::class, [
                'type' => 'country',
                'taxonomy' => $russia->slug,
            ])
            ->assertSet('filters.country', [$russia->slug])
            ->assertSet('filters.publicationTypes', ['show'])
            ->set('filters.publicationTypes', [])
            ->assertSet('filters.country', [$russia->slug])
            ->assertSet('filters.publicationTypes', [])
            ->assertNoRedirect();

        Livewire::withQueryParams($query)
            ->test(CatalogSeries::class, [
                'type' => 'country',
                'taxonomy' => $russia->slug,
            ])
            ->set('filters.country', [])
            ->assertSet('filters.country', [])
            ->assertSet('filters.publicationTypes', ['show'])
            ->assertRedirect(route('titles.index').'?'.Arr::query([
                'publication_type' => ['show'],
            ]));
    }

    public function test_route_country_removal_actions_preserve_other_filters(): void
    {
        $russia = Country::query()->create(['name' => 'Россия', 'slug' => 'rossiia']);
        $query = [
            'country' => [$russia->slug],
            'publication_type' => ['show'],
        ];
        $redirect = route('titles.index').'?'.Arr::query([
            'publication_type' => ['show'],
        ]);

        Livewire::withQueryParams($query)
            ->test(CatalogSeries::class, [
                'type' => 'country',
                'taxonomy' => $russia->slug,
            ])
            ->call('removeTaxonomy', 'country', $russia->slug)
            ->assertSet('filters.country', [])
            ->assertSet('filters.publicationTypes', ['show'])
            ->assertRedirect($redirect);

        Livewire::withQueryParams($query)
            ->test(CatalogSeries::class, [
                'type' => 'country',
                'taxonomy' => $russia->slug,
            ])
            ->call('resetGroup', 'country')
            ->assertSet('filters.country', [])
            ->assertSet('filters.publicationTypes', ['show'])
            ->assertRedirect($redirect);
    }

    public function test_country_route_combines_route_country_with_same_and_different_filter_groups(): void
    {
        $greatBritain = Country::query()->create(['name' => 'Великобритания', 'slug' => 'velikobritaniia']);
        $usa = Country::query()->create(['name' => 'США', 'slug' => 'ssa']);
        $canada = Country::query()->create(['name' => 'Канада', 'slug' => 'kanada']);
        $drama = Genre::query()->create(['name' => 'Драма', 'slug' => 'drama']);
        $comedy = Genre::query()->create(['name' => 'Комедия', 'slug' => 'komediia']);

        $britishDrama = $this->titleWithTaxonomies('Британская драма', $greatBritain, $drama);
        $americanDrama = $this->titleWithTaxonomies('Американская драма', $usa, $drama);
        $britishComedy = $this->titleWithTaxonomies('Британская комедия', $greatBritain, $comedy);
        $canadianDrama = $this->titleWithTaxonomies('Канадская драма', $canada, $drama);

        $component = Livewire::test(CatalogSeries::class, [
            'type' => 'country',
            'taxonomy' => $greatBritain->slug,
        ])->assertSet('filters.country', [$greatBritain->slug]);

        $this->assertCatalogTitleIds($component->instance()->catalogPage(), [
            $britishDrama->id,
            $britishComedy->id,
        ]);

        $component
            ->set('filters.country', [$greatBritain->slug, $usa->slug])
            ->assertSet('filters.country', [$greatBritain->slug, $usa->slug]);

        $this->assertCatalogTitleIds($component->instance()->catalogPage(), [
            $britishDrama->id,
            $americanDrama->id,
            $britishComedy->id,
        ]);

        $component
            ->set('filters.genre', [$drama->slug])
            ->assertSet('filters.genre', [$drama->slug]);

        $this->assertCatalogTitleIds($component->instance()->catalogPage(), [
            $britishDrama->id,
            $americanDrama->id,
        ]);
        $this->assertNotContains($canadianDrama->id, $component->instance()->catalogPage()['titles']->pluck('id')->all());
    }

    public function test_genre_route_combines_route_genre_with_another_genre(): void
    {
        $greatBritain = Country::query()->create(['name' => 'Великобритания', 'slug' => 'velikobritaniia']);
        $drama = Genre::query()->create(['name' => 'Драма', 'slug' => 'drama']);
        $comedy = Genre::query()->create(['name' => 'Комедия', 'slug' => 'komediia']);
        $horror = Genre::query()->create(['name' => 'Ужасы', 'slug' => 'uzhasy']);

        $dramaTitle = $this->titleWithTaxonomies('Драматический сериал', $greatBritain, $drama);
        $comedyTitle = $this->titleWithTaxonomies('Комедийный сериал', $greatBritain, $comedy);
        $horrorTitle = $this->titleWithTaxonomies('Сериал ужасов', $greatBritain, $horror);

        $component = Livewire::test(CatalogSeries::class, [
            'type' => 'genre',
            'taxonomy' => $drama->slug,
        ])
            ->assertSet('filters.genre', [$drama->slug])
            ->set('filters.genre', [$drama->slug, $comedy->slug])
            ->assertSet('filters.genre', [$drama->slug, $comedy->slug]);

        $this->assertCatalogTitleIds($component->instance()->catalogPage(), [
            $dramaTitle->id,
            $comedyTitle->id,
        ]);
        $this->assertNotContains($horrorTitle->id, $component->instance()->catalogPage()['titles']->pluck('id')->all());
    }

    public function test_taxonomy_route_get_combines_route_value_with_query_values(): void
    {
        $greatBritain = Country::query()->create(['name' => 'Великобритания', 'slug' => 'velikobritaniia']);
        $usa = Country::query()->create(['name' => 'США', 'slug' => 'ssa']);
        $canada = Country::query()->create(['name' => 'Канада', 'slug' => 'kanada']);
        $drama = Genre::query()->create(['name' => 'Драма', 'slug' => 'drama']);

        $britishTitle = $this->titleWithTaxonomies('Британский GET-сериал', $greatBritain, $drama);
        $americanTitle = $this->titleWithTaxonomies('Американский GET-сериал', $usa, $drama);
        $canadianTitle = $this->titleWithTaxonomies('Канадский GET-сериал', $canada, $drama);

        $this->get(route('titles.taxonomy', [
            'type' => 'country',
            'taxonomy' => $greatBritain->slug,
            'country' => [$usa->slug],
        ]))
            ->assertOk()
            ->assertSeeText($britishTitle->title)
            ->assertSeeText($americanTitle->title)
            ->assertDontSeeText($canadianTitle->title);
    }

    public function test_year_route_hydrates_route_year_and_keeps_another_selected_year(): void
    {
        $title2024 = CatalogTitle::factory()->create(['title' => 'Сериал 2024 года', 'year' => 2024]);
        $title2025 = CatalogTitle::factory()->create(['title' => 'Сериал 2025 года', 'year' => 2025]);
        $title2023 = CatalogTitle::factory()->create(['title' => 'Сериал 2023 года', 'year' => 2023]);

        $component = Livewire::test(CatalogSeries::class, ['year' => 2024])
            ->assertSet('filters.years', [2024])
            ->set('filters.years', [2024, 2025])
            ->assertSet('filters.years', [2024, 2025]);

        $this->assertCatalogTitleIds($component->instance()->catalogPage(), [
            $title2024->id,
            $title2025->id,
        ]);
        $this->assertNotContains($title2023->id, $component->instance()->catalogPage()['titles']->pluck('id')->all());
    }

    private function titleWithTaxonomies(string $title, Country $country, Genre $genre): CatalogTitle
    {
        $catalogTitle = CatalogTitle::factory()->create(['title' => $title]);
        $catalogTitle->countries()->attach($country);
        $catalogTitle->genres()->attach($genre);

        return $catalogTitle;
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  list<int>  $expectedIds
     */
    private function assertCatalogTitleIds(array $page, array $expectedIds): void
    {
        $this->assertEqualsCanonicalizing($expectedIds, $page['titles']->pluck('id')->all());
    }
}
