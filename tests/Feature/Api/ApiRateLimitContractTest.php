<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ApiRateLimitContractTest extends TestCase
{
    public function test_abuse_prone_api_routes_use_named_rate_limiters(): void
    {
        $expected = [
            'api.v1.search.suggestions' => 'throttle:api-search-suggestions',
            'api.catalog.people' => 'throttle:api-search-suggestions',
            'api.v1.titles.playback-sessions.store' => 'throttle:api-playback-session',
            'api.v1.titles.episodes.progress.update' => 'throttle:api-playback-progress',
            'api.v1.sync.manifest' => 'throttle:api-catalog-sync',
            'api.v1.sync.changes' => 'throttle:api-catalog-sync',
            'api.v1.me.sync.push' => 'throttle:api-user-sync',
        ];

        foreach ($expected as $routeName => $middleware) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Маршрут {$routeName} не найден.");
            $this->assertContains($middleware, $route->gatherMiddleware(), "Маршрут {$routeName} не ограничен.");
        }
    }

    public function test_named_api_rate_limits_are_bounded_without_throttling_normal_navigation(): void
    {
        $expectedAttempts = [
            'api-search-suggestions' => 120,
            'api-playback-session' => 30,
            'api-playback-progress' => 120,
            'api-catalog-sync' => 60,
            'api-user-sync' => 30,
        ];
        $request = Request::create('/api/v1/test', server: ['REMOTE_ADDR' => '203.0.113.20']);

        foreach ($expectedAttempts as $name => $attempts) {
            $limiter = RateLimiter::limiter($name);

            $this->assertNotNull($limiter, "Rate limiter {$name} не зарегистрирован.");
            $limit = $limiter($request);
            $this->assertInstanceOf(Limit::class, $limit);
            $this->assertSame($attempts, $limit->maxAttempts);
            $this->assertSame(60, $limit->decaySeconds);
        }
    }

    public function test_api_rate_limit_response_uses_the_safe_error_envelope(): void
    {
        RateLimiter::for('api-contract-test', fn (Request $request): Limit => Limit::perMinute(1)
            ->by('api-contract|'.$request->ip()));
        Route::middleware(['api', 'throttle:api-contract-test'])
            ->get('/api/_tests/rate-limit', static fn () => response()->json(['ok' => true]));

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->getJson('/api/_tests/rate-limit')
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->getJson('/api/_tests/rate-limit')
            ->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited')
            ->assertJsonPath('message', 'Слишком много запросов. Повторите попытку позже.')
            ->assertJsonStructure(['request_id']);
    }
}
