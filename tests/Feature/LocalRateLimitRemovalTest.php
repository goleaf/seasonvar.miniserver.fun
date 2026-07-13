<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlaybackAvailability;
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

    public function test_application_code_has_no_action_rate_limiter(): void
    {
        $files = collect([
            app_path('Livewire/CatalogSeries.php'),
            app_path('Livewire/CatalogTitlePlayer.php'),
            app_path('Livewire/ViewingActivity.php'),
            app_path('Livewire/SeasonvarImportManager.php'),
            app_path('Livewire/CatalogAdministrationManager.php'),
        ]);

        $this->assertFalse($files->contains(
            static fn (string $file): bool => str_contains((string) file_get_contents($file), 'SensitiveActionRateLimiter'),
        ));
    }

    public function test_action_rate_limiter_service_is_removed(): void
    {
        $this->assertFileDoesNotExist(app_path('Services/Security/SensitiveActionRateLimiter.php'));
    }

    public function test_playback_has_no_local_too_many_requests_status(): void
    {
        $statuses = array_map(
            static fn (PlaybackAvailability $status): int => $status->httpStatus(),
            PlaybackAvailability::cases(),
        );

        $this->assertNotContains(429, $statuses);
    }

    public function test_environment_and_ci_configuration_have_no_limiter_workload(): void
    {
        foreach ([
            base_path('.env.example'),
            base_path('.github/workflows/ci.yml'),
        ] as $path) {
            $contents = (string) file_get_contents($path);

            foreach (['RATE_LIMIT_', 'CACHE_LIMITER_', 'REDIS_LIMITER_', 'redis-limiter', 'redis_limiter'] as $marker) {
                $this->assertStringNotContainsString($marker, $contents, $path);
            }
        }
    }

    public function test_current_documentation_does_not_advertise_local_rate_limits(): void
    {
        foreach ([
            base_path('docs/security.md'),
            base_path('docs/architecture.md'),
            base_path('docs/administration.md'),
            base_path('docs/deployment.md'),
            base_path('docs/authorization.md'),
            base_path('docs/api.md'),
            base_path('docs/catalog-search.md'),
            base_path('docs/caching.md'),
            base_path('docs/environment.md'),
            base_path('docs/performance.md'),
            base_path('docs/testing.md'),
            base_path('docs/audit.md'),
        ] as $path) {
            $contents = (string) file_get_contents($path);

            $this->assertStringNotContainsString('RATE_LIMIT_', $contents, $path);
            $this->assertStringNotContainsString('throttle:', $contents, $path);
            $this->assertStringNotContainsString('redis-limiter', $contents, $path);
            $this->assertStringNotContainsString('redis_limiter', $contents, $path);
        }
    }
}
