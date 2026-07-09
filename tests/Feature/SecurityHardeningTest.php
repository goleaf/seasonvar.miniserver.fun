<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Media\ExternalPlaylistImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_responses_include_security_headers(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
    }

    public function test_catalog_stats_route_is_rate_limited(): void
    {
        $user = User::factory()->create();
        $limiterKey = md5('catalog-statsuser:'.$user->id);

        foreach (range(1, 30) as $attempt) {
            RateLimiter::hit($limiterKey, 60);
        }

        $this
            ->actingAs($user)
            ->get(route('stats'))
            ->assertTooManyRequests();
    }

    public function test_local_filesystem_serving_routes_are_disabled_by_default(): void
    {
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
    }

    public function test_external_playlist_urls_must_not_target_local_or_private_hosts(): void
    {
        $importer = app(ExternalPlaylistImporter::class);

        foreach (['http://localhost/list.m3u', 'http://127.0.0.1/list.m3u', 'http://10.0.0.5/list.m3u'] as $url) {
            try {
                $importer->safeExternalUrl($url);
                $this->fail("URL [{$url}] was not blocked.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Этот хост заблокирован.', $exception->getMessage());
            }
        }
    }
}
