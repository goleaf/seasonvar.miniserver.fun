<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CatalogTitle;
use App\Models\ContentRequest;
use App\Models\Genre;
use App\Services\Catalog\PublicCatalogWarmTargetSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PublicCatalogWarmTargetSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_lists_only_canonical_guest_targets(): void
    {
        CatalogTitle::factory()->create(['slug' => 'safe-title']);

        $batch = app(PublicCatalogWarmTargetSource::class)->batch(null, 500);
        $urls = collect($batch->targets)->pluck('relativeUrl');

        $this->assertTrue($urls->contains('/'));
        $this->assertTrue($urls->contains('/titles/safe-title'));

        if (Route::has('top.show')) {
            $this->assertTrue($urls->contains('/top/movies'));
        }

        if (Route::has('localized.top.show')) {
            $this->assertTrue($urls->contains('/ru/top/movies'));
        }

        $this->assertFalse($urls->contains(fn (string $url): bool => str_contains($url, '/admin')));
        $this->assertFalse($urls->contains(fn (string $url): bool => str_contains($url, '/api/v1/me')));
        $this->assertFalse($urls->contains(fn (string $url): bool => str_contains($url, 'episode=')));
        $this->assertFalse($urls->contains(fn (string $url): bool => str_contains($url, 'media=')));
    }

    public function test_source_resumes_titles_by_id_without_duplicates(): void
    {
        CatalogTitle::factory()->count(3)->create();
        $source = app(PublicCatalogWarmTargetSource::class);

        $first = $source->batch(['source' => 'titles', 'position' => ['last_id' => 0]], 2);
        $second = $source->batch($first->nextCursor, 1);
        $firstUrls = collect($first->targets)->pluck('relativeUrl')->all();
        $secondUrls = collect($second->targets)->pluck('relativeUrl')->all();

        $this->assertCount(2, $firstUrls);
        $this->assertCount(1, $secondUrls);
        $this->assertSame([], array_values(array_intersect($firstUrls, $secondUrls)));
    }

    public function test_source_enumerates_every_existing_catalog_page(): void
    {
        CatalogTitle::factory()->count(25)->create();

        $batch = app(PublicCatalogWarmTargetSource::class)->batch([
            'source' => 'catalog_pages',
            'position' => ['page' => 1],
        ], 10);
        $urls = collect($batch->targets)->pluck('relativeUrl');

        $this->assertTrue($urls->contains('/titles'));
        $this->assertTrue($urls->contains('/titles?page=2'));
    }

    public function test_source_enumerates_taxonomy_pagination_without_filter_combinations(): void
    {
        $genre = Genre::query()->create(['name' => 'Драма', 'slug' => 'drama']);
        $titles = CatalogTitle::factory()->count(25)->create();
        $genre->catalogTitles()->attach($titles->modelKeys());

        $batch = app(PublicCatalogWarmTargetSource::class)->batch([
            'source' => 'taxonomies',
            'position' => ['type_index' => 0, 'last_id' => 0, 'page' => 1],
        ], 30);
        $urls = collect($batch->targets)->pluck('relativeUrl');

        $this->assertTrue($urls->contains('/titles/genre/drama'));
        $this->assertTrue($urls->contains('/titles/genre/drama?page=2'));
        $this->assertFalse($urls->contains(fn (string $url): bool => str_contains($url, 'genre%5B')));
    }

    public function test_source_loads_a_taxonomy_batch_in_one_query(): void
    {
        $title = CatalogTitle::factory()->create();

        foreach (['drama', 'comedy', 'thriller'] as $slug) {
            $genre = Genre::query()->create(['name' => $slug, 'slug' => $slug]);
            $genre->catalogTitles()->attach($title);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $batch = app(PublicCatalogWarmTargetSource::class)->batch([
            'source' => 'taxonomies',
            'position' => ['type_index' => 0, 'last_id' => 0, 'page' => 1],
        ], 3);

        $genreQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains($query, 'from "genres"'));

        DB::disableQueryLog();

        $this->assertCount(3, $batch->targets);
        $this->assertCount(1, $genreQueries->all());
    }

    public function test_source_enumerates_only_public_request_details(): void
    {
        $public = $this->contentRequest('10000000-0000-4000-8000-000000000001', true);
        $private = $this->contentRequest('10000000-0000-4000-8000-000000000002', false);

        $batch = app(PublicCatalogWarmTargetSource::class)->batch([
            'source' => 'requests',
            'position' => [
                'stage' => 'details',
                'variant_index' => 0,
                'page' => 1,
                'last_id' => 0,
                'current_id' => 0,
            ],
        ], 10);
        $urls = collect($batch->targets)->pluck('relativeUrl');

        $this->assertTrue($urls->contains('/requests/'.$public->public_id));
        $this->assertTrue($urls->contains('/ru/requests/'.$public->public_id));
        $this->assertFalse($urls->contains(fn (string $url): bool => str_contains($url, $private->public_id)));
    }

    public function test_source_rejects_invalid_cursors(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(PublicCatalogWarmTargetSource::class)->batch([
            'source' => 'private',
            'position' => [],
        ], 10);
    }

    public function test_dry_run_estimate_attributes_targets_to_their_real_source(): void
    {
        CatalogTitle::factory()->count(2)->create();

        $estimate = app(PublicCatalogWarmTargetSource::class)->estimate();

        $this->assertSame(2, $estimate['by_source']['titles'] ?? null);
        $this->assertSame(1, $estimate['by_source']['catalog_pages'] ?? null);
        $this->assertSame(array_sum($estimate['by_source']), $estimate['targets']);
    }

    public function test_dry_run_counts_a_large_taxonomy_with_one_aggregate_query(): void
    {
        $title = CatalogTitle::factory()->create();
        $rows = [];

        for ($number = 1; $number <= 1_001; $number++) {
            $rows[] = ['name' => 'Genre '.$number, 'slug' => 'genre-'.$number];
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            Genre::query()->insert($chunk);
        }

        Genre::query()->orderBy('id')->pluck('id')->chunk(250)->each(
            fn ($ids) => $title->genres()->attach($ids),
        );

        DB::flushQueryLog();
        DB::enableQueryLog();

        $estimate = app(PublicCatalogWarmTargetSource::class)->estimate();
        $genreQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains($query, '"genres"'));

        DB::disableQueryLog();

        $this->assertSame(1_001, $estimate['by_source']['taxonomies'] ?? null);
        $this->assertCount(1, $genreQueries->all());
    }

    private function contentRequest(string $publicId, bool $isPublic): ContentRequest
    {
        return ContentRequest::query()->create([
            'public_id' => $publicId,
            'type' => 'serial',
            'title' => 'Запрос '.$publicId,
            'normalized_title' => 'запрос '.$publicId,
            'normalized_title_hash' => hash('sha256', $publicId.'-normalized'),
            'exact_identity_hash' => hash('sha256', $publicId.'-identity'),
            'submission_key' => hash('sha256', $publicId.'-submission'),
            'is_public' => $isPublic,
        ]);
    }
}
