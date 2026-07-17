<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Catalog\CatalogDirectoryRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class LivewireWebBoundaryTest extends TestCase
{
    public function test_controller_tree_contains_only_api_controllers_and_shared_base(): void
    {
        $unexpected = collect(File::allFiles(app_path('Http/Controllers')))
            ->map(fn ($file): string => str_replace('\\', '/', $file->getRelativePathname()))
            ->reject(fn (string $path): bool => $path === 'Controller.php' || str_starts_with($path, 'Api/'))
            ->values()
            ->all();

        $this->assertSame([], $unexpected);
    }

    public function test_no_web_route_uses_a_controller(): void
    {
        $unexpected = collect(Route::getRoutes()->getRoutes())
            ->reject(fn ($route): bool => $route->uri() === 'api' || str_starts_with($route->uri(), 'api/'))
            ->map(fn ($route): string => $route->getActionName())
            ->filter(fn (string $action): bool => str_starts_with($action, 'App\\Http\\Controllers\\'))
            ->values()
            ->all();

        $this->assertSame([], $unexpected);
    }

    public function test_redirect_routes_use_no_non_api_controller(): void
    {
        $routes = [
            'comments.show',
            'localized.comments.show',
            'reviews.show',
            'legacy.collections.show',
            'legacy.selections.show',
            ...app(CatalogDirectoryRegistry::class)->all()->pluck('detailRouteName')->all(),
        ];

        foreach ($routes as $name) {
            $action = Route::getRoutes()->getByName($name)?->getActionName() ?? '';

            $this->assertStringNotContainsString('App\\Http\\Controllers', $action, $name);
        }
    }

    public function test_direct_response_routes_use_no_non_api_controller(): void
    {
        foreach ([
            'profile.export',
            'verification.verify',
            'settings.preferences.migrate',
            'titles.media.download',
            'collections.cover',
            'issues.attachments.show',
            'profiles.media',
        ] as $name) {
            $action = Route::getRoutes()->getByName($name)?->getActionName() ?? '';

            $this->assertStringNotContainsString('App\\Http\\Controllers', $action, $name);
        }
    }

    public function test_machine_routes_use_neither_livewire_nor_non_api_controllers(): void
    {
        foreach ([
            'sitemap',
            'sitemap.index',
            'sitemap.static',
            'sitemap.taxonomies',
            'sitemap.landings',
            'sitemap.collections',
            'sitemap.profiles',
            'sitemap.titles',
            'sitemap.videos',
            'sitemap.requests',
            'feed',
            'opensearch',
            'llms',
            'health.ready',
            'stats.poster',
            'playback.source',
        ] as $name) {
            $action = Route::getRoutes()->getByName($name)?->getActionName() ?? '';

            $this->assertStringNotContainsString('App\\Livewire', $action, $name);
            $this->assertStringNotContainsString('App\\Http\\Controllers', $action, $name);
        }
    }
}
