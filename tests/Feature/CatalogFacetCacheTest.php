<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Genre;
use App\Models\User;
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
}
