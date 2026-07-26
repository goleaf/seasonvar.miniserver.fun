<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PublicationStatus;
use App\Models\AgeRating;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRating;
use App\Models\Country;
use App\Models\Season;
use App\Services\Catalog\CatalogTitleCardMetadataLoader;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogTitleCardMetadataLoaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_loader_prepares_ratings_country_adult_and_recent_episode_metadata_with_date_and_visibility_boundaries(): void
    {
        $title = CatalogTitle::factory()->create();
        $secondCountry = Country::query()->create([
            'name' => 'Япония',
            'slug' => 'metadata-japan',
        ]);
        $firstCountry = Country::query()->create([
            'name' => 'Республика Корея',
            'slug' => 'metadata-south-korea',
        ]);
        $adult = AgeRating::query()->create([
            'name' => '18+',
            'slug' => 'metadata-adult',
        ]);
        $title->countries()->attach([$secondCountry->id, $firstCountry->id]);
        $title->ageRatings()->attach($adult);
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
        Season::factory()->for($title)->create([
            'number' => 1,
            'latest_episode_released_at' => today()->subDays(6),
        ]);
        Season::factory()->for($title)->create([
            'number' => 2,
            'latest_episode_released_at' => today()->subDays(7),
        ]);
        Season::factory()->for($title)->create([
            'number' => 3,
            'latest_episode_released_at' => today()->addDay(),
        ]);
        Season::factory()->for($title)->create([
            'number' => 4,
            'latest_episode_released_at' => today(),
            'publication_status' => PublicationStatus::Draft,
        ]);

        $loaded = app(CatalogTitleCardMetadataLoader::class)
            ->load(collect([$title]), null, includeCountry: true)
            ->sole();

        $this->assertSame('Республика Корея', $loaded->getAttribute('card_country_name'));
        $this->assertTrue($loaded->getAttribute('card_is_adult'));
        $this->assertTrue($loaded->getAttribute('card_has_new_episode'));
        $this->assertSame(8.5, $loaded->getAttribute('card_imdb_rating'));
        $this->assertSame(8.3, $loaded->getAttribute('card_kinopoisk_rating'));
    }

    public function test_loader_uses_one_page_bounded_union_query_and_existing_title_first_indexes(): void
    {
        $titles = CatalogTitle::factory()->count(24)->create();
        $country = Country::query()->create([
            'name' => 'Страна query plan',
            'slug' => 'metadata-query-plan-country',
        ]);
        $adult = AgeRating::query()->create([
            'name' => '18+',
            'slug' => 'metadata-query-plan-adult',
        ]);

        foreach ($titles as $title) {
            $title->countries()->attach($country);
            $title->ageRatings()->attach($adult);
            CatalogTitleRating::query()->create([
                'catalog_title_id' => $title->id,
                'provider' => 'imdb',
                'rating' => 7.50,
            ]);
            Season::factory()->for($title)->create([
                'latest_episode_released_at' => today(),
            ]);
        }

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'union all')
                && str_contains(strtolower($query->sql), 'catalog_title_ratings')) {
                $queries[] = [$query->sql, $query->bindings];
            }
        });

        app(CatalogTitleCardMetadataLoader::class)
            ->load($titles, null, includeCountry: true);

        $this->assertCount(1, $queries);
        [$sql, $bindings] = $queries[0];
        $this->assertStringContainsString('catalog_title_country', $sql);
        $this->assertStringContainsString('age_rating_catalog_title', $sql);
        $this->assertStringContainsString('seasons', $sql);
        $this->assertSame(4, substr_count(strtolower($sql), 'catalog_title_id" in ('));

        $plan = DB::select('EXPLAIN QUERY PLAN '.$sql, $bindings);
        $details = collect($plan)->pluck('detail')->implode("\n");

        $this->assertStringContainsString('catalog_title_ratings_catalog_title_id_provider_unique', $details);
        $this->assertMatchesRegularExpression('/catalog_title_country.*(?:index|primary key)/i', $details);
        $this->assertMatchesRegularExpression('/age_rating_catalog_title.*(?:index|primary key)/i', $details);
        $this->assertStringContainsString('seasons_publication_lookup_idx', $details);
    }

    public function test_empty_collection_does_not_query_the_database(): void
    {
        DB::enableQueryLog();

        try {
            $loaded = app(CatalogTitleCardMetadataLoader::class)
                ->load(collect(), null, includeCountry: false);

            $this->assertTrue($loaded->isEmpty());
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
