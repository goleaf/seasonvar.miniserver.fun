<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\Genre;
use App\Models\User;
use App\Services\Catalog\CatalogDirectoryQuery;
use App\Services\Catalog\CatalogDirectoryRegistry;
use App\Services\Catalog\CatalogFacetQuery;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogFacetCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_taxonomy_snapshot_is_reused_until_its_domain_version_changes(): void
    {
        $firstTitle = CatalogTitle::factory()->create();
        $firstGenre = Genre::query()->create(['name' => 'Драма', 'slug' => 'drama']);
        $firstTitle->genres()->attach($firstGenre);
        $facets = app(CatalogFacetQuery::class);

        $this->assertSame(['drama'], $facets->taxonomies('genre')->pluck('slug')->all());

        $secondTitle = CatalogTitle::factory()->create();
        $secondGenre = Genre::query()->create(['name' => 'Комедия', 'slug' => 'comedy']);
        $secondTitle->genres()->attach($secondGenre);

        $this->assertSame(['drama'], $facets->taxonomies('genre')->pluck('slug')->all());

        app(CacheVersionRegistry::class)->bump(CacheDomain::CatalogFacets);

        $this->assertEqualsCanonicalizing(
            ['comedy', 'drama'],
            $facets->taxonomies('genre')->pluck('slug')->all(),
        );
    }

    public function test_authenticated_taxonomy_reads_bypass_the_shared_public_snapshot(): void
    {
        $firstTitle = CatalogTitle::factory()->create();
        $firstGenre = Genre::query()->create(['name' => 'Драма', 'slug' => 'drama']);
        $firstTitle->genres()->attach($firstGenre);
        $facets = app(CatalogFacetQuery::class);

        $this->assertSame(['drama'], $facets->taxonomies('genre')->pluck('slug')->all());

        $secondTitle = CatalogTitle::factory()->create();
        $secondGenre = Genre::query()->create(['name' => 'Комедия', 'slug' => 'comedy']);
        $secondTitle->genres()->attach($secondGenre);

        $this->assertEqualsCanonicalizing(
            ['comedy', 'drama'],
            $facets->taxonomies('genre', user: User::factory()->create())->pluck('slug')->all(),
        );
        $this->assertSame(['drama'], $facets->taxonomies('genre')->pluck('slug')->all());
    }

    public function test_public_taxonomy_snapshot_excludes_internal_source_urls_and_unused_columns(): void
    {
        $title = CatalogTitle::factory()->create();
        $genre = Genre::query()->create([
            'name' => 'Драма',
            'slug' => 'drama',
            'source_url' => 'https://seasonvar.ru/genre/drama',
        ]);
        $title->genres()->attach($genre);

        $attributes = app(CatalogFacetQuery::class)->taxonomies('genre')->sole()->getAttributes();

        $this->assertSame(
            ['id', 'name', 'slug', 'context_titles_count', 'catalog_titles_count'],
            array_keys($attributes),
        );
        $this->assertArrayNotHasKey('source_url', $attributes);
        $this->assertArrayNotHasKey('created_at', $attributes);
        $this->assertArrayNotHasKey('updated_at', $attributes);
    }

    public function test_directory_metadata_snapshots_are_reused_until_the_facet_version_changes(): void
    {
        app(CacheVersionRegistry::class)->bump(CacheDomain::CatalogFacets);

        $firstTitle = CatalogTitle::factory()->create();
        $firstActor = Actor::query()->create([
            'name' => 'Alice Actor',
            'slug' => 'alice-directory-cache-actor',
        ]);
        $firstTitle->actors()->attach($firstActor);

        $directory = app(CatalogDirectoryRegistry::class)->find('actors');
        $this->assertNotNull($directory);
        $query = app(CatalogDirectoryQuery::class);
        $firstSummary = $query->summary($directory);
        $firstLetters = $query->letters($directory)->all();

        $this->assertSame(['values' => 1, 'titles' => 1], $firstSummary);
        $this->assertSame(['A'], $firstLetters);

        $secondTitle = CatalogTitle::factory()->create();
        $secondActor = Actor::query()->create([
            'name' => 'Борис Актёр',
            'slug' => 'boris-directory-cache-akter',
        ]);
        $secondTitle->actors()->attach($secondActor);

        $this->assertSame($firstSummary, $query->summary($directory));
        $this->assertSame($firstLetters, $query->letters($directory)->all());

        app(CacheVersionRegistry::class)->bump(CacheDomain::CatalogFacets);

        $this->assertSame(['values' => 2, 'titles' => 2], $query->summary($directory));
        $this->assertEqualsCanonicalizing(
            ['A', 'Б'],
            $query->letters($directory)->all(),
        );
    }

    public function test_directory_decades_snapshot_is_reused_until_the_facet_version_changes(): void
    {
        CatalogTitle::factory()->create(['year' => 2024]);
        CatalogTitle::factory()->create(['year' => 2015]);
        app(CacheVersionRegistry::class)->bump(CacheDomain::CatalogFacets);

        $query = app(CatalogDirectoryQuery::class);
        $firstDecades = $query->decades()->all();

        $this->assertSame([2020, 2010], $firstDecades);

        CatalogTitle::factory()->create(['year' => 2005]);

        $this->assertSame($firstDecades, $query->decades()->all());

        app(CacheVersionRegistry::class)->bump(CacheDomain::CatalogFacets);

        $this->assertSame([2020, 2010, 2000], $query->decades()->all());
    }

    public function test_directory_decades_snapshot_identity_includes_resolved_year_bounds(): void
    {
        CatalogTitle::factory()->create(['year' => 2024]);
        CatalogTitle::factory()->create(['year' => 2015]);
        config(['catalog.directories.maximum_year' => 2019]);
        app(CacheVersionRegistry::class)->bump(CacheDomain::CatalogFacets);

        $query = app(CatalogDirectoryQuery::class);

        $this->assertSame([2010], $query->decades()->all());

        config(['catalog.directories.maximum_year' => 2024]);

        $this->assertSame([2020, 2010], $query->decades()->all());
    }
}
