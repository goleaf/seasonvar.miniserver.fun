<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Genre;
use App\Models\LicensedMedia;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SitemapAndRobotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_sitemap_url_returns_sitemap_index(): void
    {
        CatalogTitle::factory()->create([
            'slug' => 'testovyi-serial',
            'title' => 'Тестовый сериал',
            'is_published' => true,
            'indexed_at' => now(),
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertStreamed();

        $content = $response->streamedContent();

        $this->assertStringContainsString('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $content);
        $this->assertStringContainsString('/sitemap-static.xml', $content);
        $this->assertStringContainsString('/sitemap-taxonomies.xml', $content);
        $this->assertStringContainsString('/sitemap-landings.xml', $content);
        $this->assertStringContainsString('/sitemap-titles-1.xml', $content);
        $this->assertStringContainsString('/sitemap-videos-1.xml', $content);
    }

    public function test_static_sitemap_uses_only_published_title_years(): void
    {
        CatalogTitle::factory()->create([
            'year' => 2026,
            'is_published' => true,
        ]);
        CatalogTitle::factory()->create([
            'year' => 1999,
            'is_published' => false,
        ]);

        $response = $this->get('/sitemap-static.xml');

        $response->assertOk();
        $response->assertStreamed();

        $content = $response->streamedContent();

        $this->assertStringContainsString('/titles/year/2026', $content);
        $this->assertStringNotContainsString('/titles/year/1999', $content);
    }

    public function test_static_sitemap_contains_every_directory_hub_without_alias_duplicates(): void
    {
        $response = $this->get('/sitemap-static.xml');

        $response->assertOk();
        $content = $response->streamedContent();

        foreach (['genres', 'countries', 'actors', 'directors', 'age-ratings', 'translations', 'statuses', 'networks', 'studios', 'tags', 'years'] as $path) {
            $this->assertStringContainsString(url('/'.$path), $content);
        }

        $this->assertStringNotContainsString('/genres/drama', $content);
        $this->assertStringNotContainsString('/years/2026', $content);
    }

    public function test_title_sitemap_contains_published_titles_and_poster_images(): void
    {
        $title = CatalogTitle::factory()->create([
            'slug' => 'serial-s-posterom',
            'title' => 'Сериал с постером',
            'poster_url' => 'https://cdn.example.com/posters/serial.jpg',
            'is_published' => true,
            'indexed_at' => now(),
        ]);

        $response = $this->get('/sitemap-titles-1.xml');

        $response->assertOk();
        $response->assertStreamed();

        $content = $response->streamedContent();

        $this->assertStringContainsString(route('titles.show', $title), $content);
        $this->assertStringContainsString('<image:image>', $content);
        $this->assertStringContainsString('https://cdn.example.com/posters/serial.jpg', $content);
        $this->assertStringContainsString('Постер Сериал с постером', $content);
    }

    public function test_video_sitemap_uses_internal_player_locations_without_exposing_media_sources(): void
    {
        $title = CatalogTitle::factory()->create([
            'slug' => 'serial-s-video',
            'title' => 'Сериал с видео',
            'poster_url' => 'https://cdn.example.com/posters/video.jpg',
            'is_published' => true,
            'indexed_at' => now(),
        ]);

        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'title' => 'Серия 1',
            'path' => 'licensed/local-video.mp4',
            'playback_url' => null,
            'status' => 'published',
            'published_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'title' => 'Серия 2',
            'path' => 'licensed/ignored-when-playback-exists.mp4',
            'playback_url' => 'https://media.example.com/serial/s01e02.mp4',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $response = $this->get('/sitemap-videos-1.xml');

        $response->assertOk();
        $response->assertStreamed();

        $content = $response->streamedContent();

        $this->assertStringContainsString('<video:video>', $content);
        $this->assertStringContainsString('<video:player_loc>', $content);
        $this->assertStringNotContainsString('https://media.example.com/serial/s01e02.mp4', $content);
        $this->assertStringNotContainsString('licensed/local-video.mp4', $content);
    }

    public function test_feed_contains_all_published_titles_without_limit(): void
    {
        $oldestTitle = null;

        foreach (range(1, 101) as $number) {
            $title = CatalogTitle::factory()->create([
                'slug' => 'rss-card-'.$number,
                'title' => 'RSS сериал '.$number,
                'description' => $number === 101
                    ? 'Описание RSS без сокращения '.str_repeat('длинный текст ', 30).'финальный фрагмент'
                    : 'Описание RSS '.$number,
                'is_published' => true,
                'indexed_at' => now()->subMinutes($number),
            ]);

            if ($number === 101) {
                $oldestTitle = $title;
            }
        }

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'catalog_titles')) {
                $queries[] = strtolower($query->sql);
            }
        });

        $response = $this->get('/feed.xml');

        $response->assertOk();
        $response->assertStreamed();

        $content = $response->streamedContent();

        $this->assertNotNull($oldestTitle);
        $this->assertSame(101, substr_count($content, '<item>'));
        $this->assertStringContainsString(route('titles.show', $oldestTitle), $content);
        $this->assertStringContainsString('финальный фрагмент', $content);
        $this->assertNotEmpty($queries);
        $this->assertFalse(
            collect($queries)->contains(fn (string $sql): bool => preg_match('/\blimit\b/', $sql) === 1),
            'Feed catalog query should not use SQL LIMIT.',
        );
    }

    public function test_landing_sitemap_uses_grouped_taxonomy_year_queries(): void
    {
        $genres = collect(range(1, 8))->map(fn (int $number): Genre => Genre::query()->create([
            'name' => 'Жанр '.$number,
            'slug' => 'zhanr-'.$number,
        ]));
        $countries = collect(range(1, 8))->map(fn (int $number): Country => Country::query()->create([
            'name' => 'Страна '.$number,
            'slug' => 'strana-'.$number,
        ]));

        $genres->each(function (Genre $genre, int $index) use ($countries): void {
            $title = CatalogTitle::factory()->create([
                'slug' => 'landing-title-'.$index,
                'title' => 'Посадочный сериал '.$index,
                'year' => 2020 + ($index % 4),
                'is_published' => true,
            ]);

            $title->genres()->attach($genre);
            $title->countries()->attach($countries->get($index));
        });

        $draft = CatalogTitle::factory()->create([
            'slug' => 'landing-draft',
            'title' => 'Черновик посадочной',
            'year' => 2026,
            'is_published' => false,
        ]);
        $draft->genres()->attach($genres->first());

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->get('/sitemap-landings.xml');

        $response->assertOk();
        $response->assertStreamed();

        $content = $response->streamedContent();

        $this->assertStringContainsString('/titles/genre/zhanr-1?year=2020', $content);
        $this->assertStringContainsString('/titles/country/strana-2?year=2021', $content);
        $this->assertStringNotContainsString('/titles/genre/zhanr-1?year=2026', $content);
        $this->assertLessThanOrEqual(14, count($queries), 'Landing sitemap should not execute one query per taxonomy/year pair.');
    }

    public function test_public_robots_declares_only_stable_sitemap_index(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertIsString($robots);
        $this->assertStringContainsString("User-agent: ClaudeBot\n", $robots);
        $this->assertStringContainsString("Disallow: /titles?\n", $robots);
        $this->assertStringContainsString("Crawl-delay: 30\n", $robots);
        $this->assertStringContainsString('User-agent: *', $robots);
        $this->assertStringContainsString('Host: seasonvar.miniserver.fun', $robots);
        $this->assertStringContainsString('Sitemap: https://seasonvar.miniserver.fun/sitemap-index.xml', $robots);
        $this->assertStringNotContainsString('sitemap-videos-1.xml', $robots);
        $this->assertStringNotContainsString('sitemap-landings.xml', $robots);
    }
}
