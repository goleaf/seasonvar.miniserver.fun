<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Services\Catalog\CatalogHomeMetricsCache;
use App\Services\Catalog\CatalogHomeSnapshotCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_filter_schema_exposes_separate_cyrillic_latin_and_other_groups(): void
    {
        $this->getJson('/api/v1/catalog/filters')
            ->assertOk()
            ->assertJsonPath('data.alphabet.latin.0', 'A')
            ->assertJsonPath('data.alphabet.latin.25', 'Z')
            ->assertJsonPath('data.alphabet.cyrillic.0', 'А')
            ->assertJsonPath('data.alphabet.other.0', '#')
            ->assertJsonFragment(['value' => 'year_desc']);
    }

    public function test_directory_index_and_items_are_paginated_with_available_alphabet_groups(): void
    {
        $title = CatalogTitle::factory()->create();
        $actors = collect([
            ['name' => 'Alice Actor', 'slug' => 'alice-actor'],
            ['name' => 'Борис Актёр', 'slug' => 'boris-akter'],
            ['name' => '123 Actor', 'slug' => '123-actor'],
        ])->map(fn (array $attributes): Actor => Actor::query()->create($attributes));
        $title->actors()->attach($actors->pluck('id'));

        $this->getJson('/api/v1/catalog/directories')
            ->assertOk()
            ->assertJsonCount(11, 'data')
            ->assertJsonFragment(['key' => 'actors']);

        $this->getJson('/api/v1/catalog/directories/actors?letter=A&sort=count_desc&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'alice-actor')
            ->assertJsonPath('data.0.titles_count', 1)
            ->assertJsonPath('meta.alphabet.cyrillic.0', 'Б')
            ->assertJsonPath('meta.alphabet.latin.0', 'A')
            ->assertJsonPath('meta.alphabet.other.0', '#')
            ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'last_page', 'alphabet']]);
    }

    public function test_directory_query_validation_is_strict_and_unknown_directory_is_not_found(): void
    {
        foreach (['letter=AB', 'sort=unknown', 'per_page=51', 'page=0', 'decade=2025'] as $query) {
            $this->getJson('/api/v1/catalog/directories/actors?'.$query)
                ->assertUnprocessable()
                ->assertJsonPath('code', 'validation_failed');
        }

        $this->getJson('/api/v1/catalog/directories/unknown')->assertNotFound();
    }

    public function test_home_returns_bounded_safe_sections_without_raw_media_or_source_fields(): void
    {
        $sourceUrl = 'https://seasonvar.ru/private-home-source';
        $providerUrl = 'https://media.example.com/private-home-playback.m3u8';
        $title = CatalogTitle::factory()->create([
            'slug' => 'mobile-home-title',
            'poster_url' => 'https://media.example.com/mobile-home-poster.jpg',
            'source_url' => $sourceUrl,
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'status' => 'published',
            'quality' => '1080p',
            'format' => 'm3u8',
            'playback_url' => $providerUrl,
            'published_at' => now(),
        ]);

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('data.latest_titles.0.slug', 'mobile-home-title')
            ->assertJsonPath('data.latest_releases.0.catalog_title.slug', 'mobile-home-title')
            ->assertJsonStructure(['data' => [
                'stats',
                'latest_titles',
                'featured_titles',
                'titles_with_video',
                'latest_releases',
                'year_buckets',
                'genres',
                'countries',
            ]])
            ->assertDontSee($sourceUrl, false)
            ->assertDontSee($providerUrl, false)
            ->assertDontSee('storage_disk', false)
            ->assertDontSee('source_url', false)
            ->assertDontSee('seo', false);
    }

    public function test_openapi_describes_catalog_discovery_operations(): void
    {
        $this->getJson('/api/openapi.json')
            ->assertOk()
            ->assertJsonPath('paths./api/v1/home.get.operationId', 'getMobileHome')
            ->assertJsonPath('paths./api/v1/catalog/filters.get.operationId', 'getCatalogFilterSchema')
            ->assertJsonPath('paths./api/v1/catalog/directories.get.operationId', 'getCatalogDirectories')
            ->assertJsonPath('paths./api/v1/catalog/directories/{directory}.get.operationId', 'getCatalogDirectoryItems')
            ->assertJsonPath('paths./api/v1/catalog/directories/{directory}.get.parameters.0.name', 'directory')
            ->assertJsonPath('components.schemas.CatalogAlphabet.required.0', 'cyrillic');
    }

    public function test_directory_and_home_query_counts_are_constant_as_results_grow(): void
    {
        $directoryTitle = CatalogTitle::factory()->create(['slug' => 'directory-budget-title']);
        $firstActor = Actor::query()->create(['name' => 'Budget Actor 1', 'slug' => 'budget-actor-1']);
        $directoryTitle->actors()->attach($firstActor);
        $oneDirectoryItemQueries = $this->captureQueries(
            fn () => $this->getJson('/api/v1/catalog/directories/actors?per_page=20')->assertOk(),
        );

        foreach (range(2, 20) as $index) {
            $actor = Actor::query()->create([
                'name' => "Budget Actor {$index}",
                'slug' => "budget-actor-{$index}",
            ]);
            $directoryTitle->actors()->attach($actor);
        }

        $twentyDirectoryItemQueries = $this->captureQueries(
            fn () => $this->getJson('/api/v1/catalog/directories/actors?per_page=20')
                ->assertOk()
                ->assertJsonCount(20, 'data'),
        );
        $this->assertLessThanOrEqual($oneDirectoryItemQueries + 2, $twentyDirectoryItemQueries);

        app(CatalogHomeSnapshotCache::class)->refresh();
        app(CatalogHomeMetricsCache::class)->refresh();
        $oneHomeItemQueries = $this->captureQueries(
            fn () => $this->getJson('/api/v1/home')->assertOk(),
        );

        foreach (range(2, 20) as $index) {
            CatalogTitle::factory()->create(['slug' => "home-budget-title-{$index}"]);
        }

        app(CatalogHomeSnapshotCache::class)->refresh();
        app(CatalogHomeMetricsCache::class)->refresh();
        $twentyHomeItemQueries = $this->captureQueries(
            fn () => $this->getJson('/api/v1/home')->assertOk(),
        );

        $this->assertLessThanOrEqual($oneHomeItemQueries + 2, $twentyHomeItemQueries);
    }

    private function captureQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
