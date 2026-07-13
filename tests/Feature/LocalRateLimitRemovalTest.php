<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class LocalRateLimitRemovalTest extends TestCase
{
    public function test_livewire_upload_throttle_is_disabled_when_cached_config_lacks_the_new_setting(): void
    {
        config(['livewire.temporary_file_upload.middleware' => null]);

        (new AppServiceProvider(app()))->register();

        $this->assertSame('web', config('livewire.temporary_file_upload.middleware'));
    }

    public function test_public_web_and_api_routes_have_no_throttle_middleware(): void
    {
        foreach ([
            'health.ready',
            'stats',
            'stats.poster',
            'playback.source',
            'titles.index',
            'titles.year',
            'titles.taxonomy',
            'api.catalog.people',
            'api.titles.index',
            'api.titles.show',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Route [{$routeName}] is not registered.");
            $this->assertSame(
                [],
                array_values(array_filter(
                    $route->gatherMiddleware(),
                    static fn (string $middleware): bool => str_starts_with($middleware, 'throttle:'),
                )),
                "Route [{$routeName}] still has throttle middleware.",
            );
        }
    }

    public function test_livewire_update_route_has_no_throttle_middleware(): void
    {
        foreach (['livewire.update', 'livewire.upload-file'] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Route [{$routeName}] is not registered.");
            $this->assertSame(
                [],
                array_values(array_filter(
                    $route->gatherMiddleware(),
                    static fn (string $middleware): bool => str_starts_with($middleware, 'throttle:')
                        || str_contains($middleware, 'ThrottleRequests'),
                )),
                "Route [{$routeName}] still has throttle middleware.",
            );
            $this->assertContains('web', $route->gatherMiddleware());
        }
    }

    public function test_application_registers_no_named_local_rate_limiters(): void
    {
        foreach ([
            'catalog-stats',
            'catalog-query',
            'livewire-action',
            'catalog-api',
            'infrastructure-health',
            'playback-source',
        ] as $limiter) {
            $this->assertNull(RateLimiter::limiter($limiter));
        }
    }
}
