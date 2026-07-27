<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Genre;
use App\Services\Catalog\CatalogHomePageBuilder;
use App\Services\Catalog\CatalogHomeSnapshotCache;
use App\Services\Catalog\Queries\CatalogHomeFacetGroupsQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogHomeFacetQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_genres_and_countries_are_loaded_in_one_grouped_query(): void
    {
        [$genres, $countries] = $this->seedFacets(20);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $groups = app(CatalogHomeFacetGroupsQuery::class)->handle();

        $this->assertCount($genres->count(), $groups->get('genre'));
        $this->assertCount($countries->count(), $groups->get('country'));
        $this->assertCount(1, $queries, implode("\n", $queries));
        $this->assertTrue(
            $groups->flatten(1)->every(
                fn (Genre|Country $taxonomy): bool => $taxonomy->catalog_titles_count === 1,
            ),
        );
        $this->assertTrue(
            $groups->get('country')->every(
                fn (Country $country): bool => is_string($country->getAttribute('detail_url')),
            ),
        );
    }

    public function test_web_projection_contains_every_available_facet_while_api_contract_stays_bounded(): void
    {
        [$genres, $countries] = $this->seedFacets(20);
        $years = range(2005, 2020);

        foreach ($years as $year) {
            CatalogTitle::factory()->create(['year' => $year]);
        }

        app(CatalogHomeSnapshotCache::class)->refresh();
        $builder = app(CatalogHomePageBuilder::class);
        $web = $builder->webData();
        $api = $builder->data();

        $this->assertCount($genres->count(), $web['genres']);
        $this->assertCount($countries->count(), $web['countries']);
        $this->assertSame([2021, ...array_reverse($years)], $web['yearBuckets']->pluck('year')->all());
        $this->assertCount(18, $api['genres']);
        $this->assertCount(12, $api['yearBuckets']);
        $this->assertCount($countries->count(), $api['countries']);
    }

    /** @return array{0: Collection<int, Genre>, 1: Collection<int, Country>} */
    private function seedFacets(int $count): array
    {
        $title = CatalogTitle::factory()->create(['year' => 2021]);
        $genres = new Collection;
        $countries = new Collection;

        foreach (range(1, $count) as $index) {
            $genres->push(Genre::query()->create([
                'name' => sprintf('Жанр %02d', $index),
                'slug' => sprintf('genre-%02d', $index),
            ]));
            $countries->push(Country::query()->create([
                'name' => sprintf('Страна %02d', $index),
                'slug' => sprintf('country-%02d', $index),
            ]));
        }

        $title->genres()->sync($genres->modelKeys());
        $title->countries()->sync($countries->modelKeys());

        return [$genres, $countries];
    }
}
