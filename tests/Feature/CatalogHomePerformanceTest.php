<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Catalog\CatalogCacheInvalidator;
use App\Services\Catalog\CatalogHomeContentAdditionQuery;
use App\Services\Catalog\CatalogHomeMetricsCache;
use App\Services\Catalog\CatalogHomePageBuilder;
use App\Services\Catalog\CatalogHomeSnapshotCache;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheKeyFactory;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CatalogHomePerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_home_uses_the_bounded_recently_added_discovery_path(): void
    {
        $data = app(CatalogHomePageBuilder::class)->data();

        $this->assertSame(
            __('recommendations.types.recently_added.title'),
            $data['homeRecommendationPresentation']['title'],
        );
    }

    public function test_latest_update_snapshot_uses_the_existing_created_at_indexes(): void
    {
        $catalogTitle = CatalogTitle::factory()->create();
        $season = Season::factory()->create(['catalog_title_id' => $catalogTitle->id]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = str($query->sql)
                ->replace(['`', '"'], '')
                ->lower()
                ->squish()
                ->toString();
        });

        $updates = app(CatalogHomeContentAdditionQuery::class)->latestTitleUpdates();

        $this->assertSame([$catalogTitle->id], collect($updates)->pluck('id')->all());
        $this->assertTrue(
            collect($queries)->contains(
                fn (string $sql): bool => str_contains($sql, 'episodes indexed by episodes_created_at_idx'),
            ),
            implode("\n", $queries),
        );
        $this->assertTrue(
            collect($queries)->contains(
                fn (string $sql): bool => str_contains($sql, 'licensed_media indexed by licensed_media_created_at_idx'),
            ),
            implode("\n", $queries),
        );
        $this->assertTrue(
            collect($queries)->contains(
                fn (string $sql): bool => str_contains($sql, 'episodes not indexed'),
            ),
            implode("\n", $queries),
        );
        $this->assertTrue(
            collect($queries)->contains(
                fn (string $sql): bool => str_contains($sql, 'licensed_media not indexed'),
            ),
            implode("\n", $queries),
        );
    }

    public function test_home_snapshot_uses_a_correlated_video_exists_probe(): void
    {
        $catalogTitle = CatalogTitle::factory()->create(['indexed_at' => now()]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = str($query->sql)
                ->replace(['`', '"'], '')
                ->lower()
                ->squish()
                ->toString();
        });

        $snapshot = app(CatalogHomeSnapshotCache::class)->refresh();

        $this->assertSame([$catalogTitle->id], $snapshot['video_title_ids']);
        $this->assertTrue(
            collect($queries)->contains(
                fn (string $sql): bool => str_contains($sql, 'exists (select 1 from licensed_media')
                    && str_contains($sql, 'licensed_media.catalog_title_id = catalog_titles.id'),
            ),
            implode("\n", $queries),
        );
        $this->assertFalse(
            collect($queries)->contains(
                fn (string $sql): bool => str_contains(
                    $sql,
                    'catalog_titles.id in (select licensed_media.catalog_title_id',
                ),
            ),
            implode("\n", $queries),
        );
    }

    public function test_catalog_page_invalidation_keeps_metrics_until_the_warmer_refreshes_them(): void
    {
        Queue::fake();
        $metrics = app(CatalogHomeMetricsCache::class);
        $metrics->forget();
        CatalogTitle::factory()->create();

        $this->assertSame(1, $metrics->metrics()['titles']);

        CatalogTitle::factory()->create();
        app(CatalogCacheInvalidator::class)->catalogChanged();

        $this->assertSame(1, $metrics->metrics()['titles']);
        $this->assertSame(2, $metrics->refresh()['titles']);
    }

    public function test_home_metrics_use_the_stats_ttl_inside_the_homepage_namespace(): void
    {
        config(['cache-architecture.domains.homepage.fresh' => 0]);
        CatalogTitle::factory()->create();

        $this->assertSame(1, app(CatalogHomeMetricsCache::class)->metrics()['titles']);

        $key = app(CacheKeyFactory::class)->data(
            CacheDomain::Homepage,
            'metrics',
            ['audience' => 'public', 'locale' => app()->getLocale()],
            app(CacheVersionRegistry::class)->version(CacheDomain::Homepage, 'metrics'),
        );

        $this->assertTrue(Cache::store('array')->has($key));
    }

    public function test_mass_import_homepage_remains_inside_the_shared_html_cache_budget(): void
    {
        Queue::fake();
        config([
            'cache-architecture.page_cache.max_payload_bytes' => 180_000,
            'cache-architecture.page_cache.max_uncompressed_payload_bytes' => 180_000,
        ]);
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Большой пакет для кэшируемой главной',
            'slug' => 'bolshoi-paket-dlia-keshiruemoi-glavnoi',
        ]);
        $season = Season::factory()->create(['catalog_title_id' => $catalogTitle->id]);

        foreach (range(1, 40) as $number) {
            $episode = Episode::factory()->create([
                'season_id' => $season->id,
                'number' => $number,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            LicensedMedia::factory()->create([
                'catalog_title_id' => $catalogTitle->id,
                'season_id' => $season->id,
                'episode_id' => $episode->id,
                'status' => 'published',
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(CatalogCacheInvalidator::class)->catalogChanged();

        $first = $this->get(route('home'))
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'MISS')
            ->assertSeeText('На странице сериала доступны остальные серии и видео.');

        $this->assertLessThanOrEqual(180_000, strlen((string) $first->getContent()));

        $this->get(route('home'))
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'HIT');
    }
}
