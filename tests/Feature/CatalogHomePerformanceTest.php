<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogRecommendationContext;
use App\Enums\CatalogRecommendationType;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Catalog\CatalogCacheInvalidator;
use App\Services\Catalog\CatalogHomeContentAdditionQuery;
use App\Services\Catalog\CatalogHomeMetricsCache;
use App\Services\Catalog\CatalogHomePageBuilder;
use App\Services\Catalog\CatalogHomeSnapshotCache;
use App\Services\Catalog\CatalogPublicDiscoveryQuery;
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

    public function test_recently_added_sqlite_query_uses_the_existing_created_at_order_index(): void
    {
        $this->travelTo(now()->setDate(2026, 7, 26)->setTime(12, 0));
        $olderTitle = CatalogTitle::factory()->create([
            'created_at' => now()->subDay(),
        ]);
        $firstRecentTitle = CatalogTitle::factory()->create([
            'created_at' => now(),
        ]);
        $secondRecentTitle = CatalogTitle::factory()->create([
            'created_at' => now(),
        ]);
        $excludedTitle = CatalogTitle::factory()->create([
            'created_at' => now()->addMinute(),
        ]);

        foreach ([$olderTitle, $firstRecentTitle, $secondRecentTitle, $excludedTitle] as $catalogTitle) {
            LicensedMedia::factory()->create([
                'catalog_title_id' => $catalogTitle->id,
                'status' => 'published',
                'published_at' => now(),
            ]);
        }

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = str($query->sql)
                ->replace(['`', '"'], '')
                ->lower()
                ->squish()
                ->toString();
        });
        $context = new CatalogRecommendationContext(
            type: CatalogRecommendationType::RecentlyAdded,
            user: null,
            locale: app()->currentLocale(),
            excludedTitleIds: [$excludedTitle->id],
            perPage: 8,
        );

        $candidates = app(CatalogPublicDiscoveryQuery::class)->candidates(
            $context,
            [$excludedTitle->id],
        );
        $candidateQuery = collect($queries)->first(
            fn (string $sql): bool => str_contains(
                $sql,
                'order by catalog_titles.created_at desc, catalog_titles.id desc',
            ),
        );

        $this->assertSame(
            [$secondRecentTitle->id, $firstRecentTitle->id, $olderTitle->id],
            collect($candidates)->pluck('id')->all(),
        );
        $this->assertIsString($candidateQuery, implode("\n", $queries));
        $this->assertStringContainsString(
            'from catalog_titles indexed by catalog_titles_created_at_idx',
            $candidateQuery,
        );
    }

    public function test_home_release_hydration_uses_primary_key_lookups_for_bounded_episode_ids(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'indexed_at' => now(),
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $contentAdditions = app(CatalogHomeContentAdditionQuery::class);
        $updates = $contentAdditions->latestTitleUpdates();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = str($query->sql)
                ->replace(['`', '"'], '')
                ->lower()
                ->squish()
                ->toString();
        });

        $groups = $contentAdditions->latestReleaseGroups(
            collect([$catalogTitle]),
            $updates,
        );
        $group = $groups->sole();
        $primaryKeyHydrations = collect($queries)
            ->filter(fn (string $sql): bool => str_contains(
                $sql,
                'from episodes not indexed',
            ))
            ->values();

        $this->assertSame([$episode->id], $group['episodes']->pluck('id')->all());
        $this->assertSame([$media->id], $group['media']->pluck('id')->all());
        $this->assertSame($episode->id, $group['media']->sole()->episode?->id);
        $this->assertCount(2, $primaryKeyHydrations, implode("\n", $queries));
        $this->assertTrue(
            $primaryKeyHydrations->contains(
                fn (string $sql): bool => str_contains(
                    $sql,
                    'row_number() over (partition by seasons.catalog_title_id',
                ),
            ),
            implode("\n", $primaryKeyHydrations->all()),
        );
        $this->assertTrue(
            $primaryKeyHydrations->contains(
                fn (string $sql): bool => str_contains(
                    $sql,
                    'select id, season_id, number, kind, sort_order, title, released_at, created_at',
                ),
            ),
            implode("\n", $primaryKeyHydrations->all()),
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

    public function test_home_snapshot_latest_media_uses_correlated_title_visibility(): void
    {
        $this->travelTo(now()->setDate(2026, 7, 26)->setTime(12, 0));
        $olderTitle = CatalogTitle::factory()->create();
        $newerTitle = CatalogTitle::factory()->create();
        $hiddenTitle = CatalogTitle::factory()->create(['is_published' => false]);
        $authenticatedMediaTitle = CatalogTitle::factory()->create();
        $olderMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $olderTitle->id,
            'status' => 'published',
            'published_at' => now()->subMinutes(4),
        ]);
        $newerMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $newerTitle->id,
            'status' => 'published',
            'published_at' => now()->subMinutes(3),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $authenticatedMediaTitle->id,
            'status' => 'published',
            'audience' => 'authenticated',
            'published_at' => now()->subMinutes(2),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $hiddenTitle->id,
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $normalized = str($query->sql)
                ->replace(['`', '"'], '')
                ->lower()
                ->squish()
                ->toString();

            if (str_starts_with($normalized, 'select id from licensed_media')
                && str_contains($normalized, 'order by published_at desc, id desc limit 12')) {
                $queries[] = $query;
            }
        });

        $snapshot = app(CatalogHomeSnapshotCache::class)->refresh();
        $latestMediaQuery = collect($queries)->sole();
        $normalizedSql = str($latestMediaQuery->sql)
            ->replace(['`', '"'], '')
            ->lower()
            ->squish()
            ->toString();

        $this->assertSame(
            [$newerMedia->id, $olderMedia->id],
            $snapshot['latest_media_ids'],
        );
        $this->assertStringContainsString(
            'exists (select 1 from catalog_titles',
            $normalizedSql,
        );
        $this->assertStringContainsString(
            'catalog_titles.id = licensed_media.catalog_title_id',
            $normalizedSql,
        );
        $this->assertStringNotContainsString(
            'catalog_title_id in (select id from catalog_titles',
            $normalizedSql,
        );

        $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$latestMediaQuery->toRawSql()))
            ->pluck('detail')
            ->implode("\n");

        $this->assertStringContainsString('licensed_media_home_feed_idx', $plan);
        $this->assertStringContainsString(
            'SEARCH catalog_titles USING INTEGER PRIMARY KEY',
            $plan,
        );
        $this->assertStringNotContainsString('LIST SUBQUERY', $plan);
    }

    public function test_home_snapshot_reuses_year_buckets_until_the_facet_version_changes(): void
    {
        $this->travelTo(now()->setDate(2026, 7, 26)->setTime(12, 0));
        CatalogTitle::factory()->create(['year' => 2026]);
        CatalogTitle::factory()->create(['year' => 2025]);
        CatalogTitle::factory()->create([
            'year' => 2027,
            'is_published' => false,
        ]);
        $versions = app(CacheVersionRegistry::class);
        $versions->bump(CacheDomain::CatalogFacets);
        $yearQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$yearQueries): void {
            $sql = str($query->sql)
                ->replace(['`', '"'], '')
                ->lower()
                ->squish()
                ->toString();

            if (str_contains($sql, 'select year, count(*) as titles_count')
                && str_contains($sql, 'group by year')) {
                $yearQueries[] = $sql;
            }
        });
        $snapshot = app(CatalogHomeSnapshotCache::class);

        $first = $snapshot->refresh();
        $second = $snapshot->refresh();

        $this->assertSame([
            ['year' => 2026, 'titles_count' => 1],
            ['year' => 2025, 'titles_count' => 1],
        ], $first['year_buckets']);
        $this->assertSame($first['year_buckets'], $second['year_buckets']);
        $this->assertCount(1, $yearQueries);

        CatalogTitle::factory()->create(['year' => 2026]);
        $stillCached = $snapshot->refresh();

        $this->assertSame($first['year_buckets'], $stillCached['year_buckets']);
        $this->assertCount(1, $yearQueries);

        $versions->bump(CacheDomain::CatalogFacets);
        $rebuilt = $snapshot->refresh();

        $this->assertSame([
            ['year' => 2026, 'titles_count' => 2],
            ['year' => 2025, 'titles_count' => 1],
        ], $rebuilt['year_buckets']);
        $this->assertCount(2, $yearQueries);
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
