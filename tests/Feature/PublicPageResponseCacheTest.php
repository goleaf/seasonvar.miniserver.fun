<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\User;
use App\Services\Catalog\CatalogCacheInvalidator;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PublicPageResponseCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_is_served_from_shared_html_cache_without_catalog_queries(): void
    {
        $title = CatalogTitle::factory()->create([
            'title' => 'Быстрый сериал',
            'slug' => 'bystryi-serial',
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'MISS')
            ->assertSeeText('Быстрый сериал');

        $catalogQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$catalogQueries): void {
            if (str_contains($query->sql, 'catalog_')
                || str_contains($query->sql, 'licensed_media')
                || str_contains($query->sql, 'episodes')
                || str_contains($query->sql, 'seasons')) {
                $catalogQueries[] = $query->sql;
            }
        });

        $this->get(route('home'))
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'HIT')
            ->assertSeeText('Быстрый сериал');

        $this->assertSame([], $catalogQueries);
    }

    public function test_private_and_free_text_requests_bypass_shared_html_cache(): void
    {
        $this->get(route('home'))->assertHeader('X-Seasonvar-Page-Cache', 'MISS');

        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'BYPASS');

        $this->get(route('titles.index', ['q' => 'личный поиск']))
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'BYPASS');
    }

    public function test_guest_flash_input_bypasses_shared_html_cache(): void
    {
        $this->withSession(['_old_input' => ['q' => 'private-flashed-search']])
            ->get(route('home'))
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'BYPASS')
            ->assertSee('value="private-flashed-search"', false);
    }

    public function test_title_page_hit_executes_only_the_route_binding_catalog_query(): void
    {
        $title = CatalogTitle::factory()->create([
            'title' => 'Кэшируемая страница сериала',
            'slug' => 'keshiruemaia-stranitsa-seriala',
        ]);

        $this->get(route('titles.show', $title))
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'MISS');

        $catalogQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$catalogQueries): void {
            if (str_contains($query->sql, 'catalog_')
                || str_contains($query->sql, 'licensed_media')
                || str_contains($query->sql, 'episodes')
                || str_contains($query->sql, 'seasons')) {
                $catalogQueries[] = $query->sql;
            }
        });

        $this->get(route('titles.show', $title))
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'HIT')
            ->assertSeeText('Кэшируемая страница сериала');

        $this->assertLessThanOrEqual(1, count($catalogQueries), implode("\n", $catalogQueries));
    }

    public function test_catalog_invalidation_makes_the_next_homepage_request_rebuild_the_cache(): void
    {
        CatalogTitle::factory()->create(['title' => 'Первый сериал']);

        $this->get(route('home'))->assertHeader('X-Seasonvar-Page-Cache', 'MISS');
        $this->get(route('home'))->assertHeader('X-Seasonvar-Page-Cache', 'HIT');

        $newTitle = CatalogTitle::factory()->create(['title' => 'Новый сериал после импорта']);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $newTitle->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        app(CatalogCacheInvalidator::class)->catalogChanged([$newTitle->id]);

        $this->get(route('home'))
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'MISS')
            ->assertSeeText('Новый сериал после импорта');

        $this->get(route('home'))->assertHeader('X-Seasonvar-Page-Cache', 'HIT');
    }

    public function test_large_compressible_public_html_is_served_from_shared_cache(): void
    {
        config([
            'cache-architecture.page_cache.max_payload_bytes' => 850_000,
            'cache-architecture.page_cache.max_uncompressed_payload_bytes' => 1_500_000,
        ]);
        $html = '<html><body>'.str_repeat('Большой публичный блок ', 25_000).'</body></html>';
        Route::middleware(['web', 'public.page:stats'])
            ->get('/_tests/large-public-page', fn () => response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']))
            ->name('tests.large-public-page');

        $first = $this->get('/_tests/large-public-page')
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'MISS')
            ->assertSeeText('Большой публичный блок');

        $this->assertGreaterThan(850_000, strlen((string) $first->getContent()));

        $this->get('/_tests/large-public-page')
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'HIT')
            ->assertSeeText('Большой публичный блок');
    }

    public function test_stats_livewire_page_is_served_from_shared_cache(): void
    {
        $this->get(route('stats'))
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'MISS')
            ->assertSeeText('Сводка каталога');

        $this->get(route('stats'))
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'HIT')
            ->assertSeeText('Сводка каталога');
    }

    public function test_standard_guest_session_cookies_do_not_prevent_shared_caching(): void
    {
        Route::middleware(['web', 'public.page:stats'])
            ->get('/_tests/public-page-with-session-cookies', function () {
                return response('<html><body>Публичная Livewire страница</body></html>')
                    ->cookie('XSRF-TOKEN', 'test-token')
                    ->cookie((string) config('session.cookie'), 'test-session');
            })
            ->name('tests.public-page-with-session-cookies');

        $this->get('/_tests/public-page-with-session-cookies')
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'MISS');

        $this->get('/_tests/public-page-with-session-cookies')
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'HIT');
    }

    public function test_custom_response_cookie_prevents_shared_caching(): void
    {
        Route::middleware(['web', 'public.page:stats'])
            ->get('/_tests/public-page-with-custom-cookie', function () {
                return response('<html><body>Ответ с прикладным cookie</body></html>')
                    ->cookie('catalog-preference', 'private-value');
            })
            ->name('tests.public-page-with-custom-cookie');

        $this->get('/_tests/public-page-with-custom-cookie')
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'BYPASS');

        $this->get('/_tests/public-page-with-custom-cookie')
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'BYPASS');
    }

    public function test_cacheable_public_routes_have_the_expected_profiles(): void
    {
        $expected = [
            'home' => 'public.page:homepage',
            'stats' => 'public.page:stats',
            'titles.index' => 'public.page:catalog',
            'titles.year' => 'public.page:catalog',
            'titles.taxonomy' => 'public.page:catalog',
            'titles.show' => 'public.page:title',
            'genres.index' => 'public.page:catalog',
            'years.index' => 'public.page:catalog',
        ];

        foreach ($expected as $routeName => $middleware) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Маршрут {$routeName} не найден.");
            $this->assertContains($middleware, $route->gatherMiddleware());
        }
    }
}
