<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Catalog\CatalogDirectoryRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PublicCacheRouteSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_routes_never_have_public_page_cache(): void
    {
        $forbidden = [
            'admin.catalog',
            'profile.show',
            'profiles.collections',
            'localized.profiles.collections',
            'playback.source',
            'titles.media.download',
            'api.v1.me.show',
        ];

        foreach ($forbidden as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name);
            $this->assertFalse($this->hasPublicPageCache($route), $name);
        }
    }

    public function test_only_indexable_discovery_types_receive_shared_context(): void
    {
        config([
            'cache-architecture.page_cache.enabled' => true,
            'cache-architecture.domains.catalog-pages.jitter_percent' => 0,
        ]);

        $this->get('/discover/popular')
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'MISS');
        $this->get('/discover/random')
            ->assertOk()
            ->assertHeader('X-Seasonvar-Page-Cache', 'BYPASS');
    }

    public function test_shared_page_cache_routes_are_an_explicit_allowlist(): void
    {
        $allowed = collect([
            'home',
            'localized.home',
            'stats',
            'titles.index',
            'titles.year',
            'titles.taxonomy',
            'titles.show',
            'collections.index',
            'localized.collections.index',
            'requests.index',
            'requests.show',
            'localized.requests.index',
            'localized.requests.show',
            'discover.index',
            'localized.discover.index',
            'legacy.tags.show',
            ...collect(CatalogDirectoryRegistry::routeMap())
                ->keys()
                ->map(fn (string $directory): string => $directory.'.index')
                ->all(),
        ]);

        if (Route::has('top.show')) {
            $allowed->push('top.show');
        }

        if (Route::has('localized.top.show')) {
            $allowed->push('localized.top.show');
        }

        $cached = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (LaravelRoute $route): bool => $this->hasPublicPageCache($route))
            ->map(fn (LaravelRoute $route): ?string => $route->getName())
            ->filter()
            ->values();

        $this->assertSame([], $cached->diff($allowed)->values()->all());
        $this->assertSame([], $allowed->diff($cached)->values()->all());
    }

    private function hasPublicPageCache(LaravelRoute $route): bool
    {
        return collect($route->gatherMiddleware())
            ->contains(fn (string $middleware): bool => str_contains($middleware, 'CachePublicPage')
                || str_starts_with($middleware, 'public.page:'));
    }
}
