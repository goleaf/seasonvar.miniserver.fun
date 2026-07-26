<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PwaHttpContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_is_installable_and_only_declares_local_icons(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $manifest = $response->json();

        $this->assertSame('/', $manifest['id']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('#ecfdf5', $manifest['theme_color']);
        $this->assertSame('#f8fafc', $manifest['background_color']);
        $this->assertNotEmpty($manifest['name']);
        $this->assertNotEmpty($manifest['short_name']);
        $this->assertContains('any', array_column($manifest['icons'], 'purpose'));
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));

        foreach ($manifest['icons'] as $icon) {
            $this->assertStringStartsWith('/icons/', $icon['src']);
            $this->assertSame('image/png', $icon['type']);
        }
    }

    public function test_service_worker_has_a_root_scope_and_never_caches_private_or_media_requests(): void
    {
        $response = $this->get('/service-worker.js');

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
            ->assertHeader('Service-Worker-Allowed', '/');

        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );

        $source = (string) $response->getContent();

        foreach ([
            'request.method !== \'GET\'',
            'request.headers.has(\'Authorization\')',
            'request.destination === \'video\'',
            'request.destination === \'audio\'',
            '\'/playback/\'',
            '\'/download\'',
            '/manifest.webmanifest',
            '/icons/pwa-192.png',
            '/icons/pwa-512.png',
            '/icons/pwa-maskable-512.png',
            '\'.m3u8\'',
            '\'.m4s\'',
            '\'.ts\'',
            '\'/pwa/library-snapshot\'',
            '\'/pwa/actions\'',
            '\'/pwa/push-subscriptions\'',
            "createObjectStore('meta'",
            "createObjectStore('snapshots'",
            "createObjectStore('actions'",
        ] as $requiredGuard) {
            $this->assertStringContainsString($requiredGuard, $source);
        }

        $this->assertStringNotContainsString('skipWaiting()', $source);
        $this->assertStringNotContainsString('innerHTML', $source);
    }

    public function test_worker_build_failure_returns_javascript_503_without_unregistering_an_existing_worker(): void
    {
        File::shouldReceive('isFile')
            ->once()
            ->with(public_path('build/manifest.json'))
            ->andReturnFalse();

        $response = $this->get('/service-worker.js');

        $response
            ->assertStatus(503)
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
            ->assertHeader('Service-Worker-Allowed', '/');

        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );
        $this->assertStringNotContainsString(
            'registration.unregister()',
            (string) $response->getContent(),
        );
    }

    public function test_disabled_pwa_serves_a_cleanup_worker_for_a_safe_rollback(): void
    {
        config(['pwa.enabled' => false]);

        $response = $this->get('/service-worker.js');

        $response
            ->assertOk()
            ->assertHeader('Service-Worker-Allowed', '/');

        $source = (string) $response->getContent();

        $this->assertStringContainsString('registration.unregister()', $source);
        $this->assertStringContainsString('caches.keys()', $source);
        $this->assertStringContainsString('seasonvar-', $source);
        $this->assertStringNotContainsString('PRECACHE_URLS', $source);
    }

    public function test_offline_shell_is_public_noindex_and_contains_no_session_or_video_promise(): void
    {
        $response = $this->get('/offline');

        $response
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('data-pwa-offline-shell', false)
            ->assertSeeText('Сохранённая копия')
            ->assertSeeText('Видео без сети недоступно')
            ->assertSee('data-pwa-rating-label="Оценка"', false)
            ->assertSee('data-pwa-saved-at-label="Сохранено"', false)
            ->assertSee('data-pwa-offline-library-saved-at', false)
            ->assertSee('data-pwa-offline-help-saved-at', false)
            ->assertDontSee('csrf-token', false)
            ->assertDontSee('livewire/update', false)
            ->assertDontSee('<script src=', false)
            ->assertDontSee('offline-просмотр доступен', false);

        $this->assertStringContainsString(
            'public',
            (string) $response->headers->get('Cache-Control'),
        );
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_application_layout_links_manifest_and_exposes_no_private_push_key(): void
    {
        config([
            'pwa.enabled' => true,
            'pwa.push.enabled' => false,
            'pwa.push.public_key' => null,
        ]);

        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('/manifest.webmanifest', false)
            ->assertSee('rel="apple-touch-icon"', false)
            ->assertSee('name="apple-mobile-web-app-capable" content="yes"', false)
            ->assertSee('name="apple-mobile-web-app-status-bar-style" content="default"', false)
            ->assertSee('data-pwa-enabled="1"', false)
            ->assertDontSee('data-pwa-vapid-public-key=', false);
    }

    public function test_push_settings_never_show_actionable_controls_without_a_complete_server_configuration(): void
    {
        $user = User::factory()->create();
        config([
            'pwa.enabled' => true,
            'pwa.push.enabled' => false,
        ]);

        $this->actingAs($user)
            ->get(route('settings.index', ['section' => 'notifications']))
            ->assertOk()
            ->assertSeeText('Push-уведомления сейчас недоступны')
            ->assertDontSee('data-pwa-push-enable', false);

        $this->configureVapid();

        $this->actingAs($user)
            ->get(route('settings.index', ['section' => 'notifications']))
            ->assertOk()
            ->assertSee('data-pwa-push-enable', false)
            ->assertSee('data-pwa-push-disable', false)
            ->assertDontSeeText('Push-уведомления сейчас недоступны');
    }

    private function configureVapid(): void
    {
        $resource = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $this->assertNotFalse($resource);
        $this->assertTrue(openssl_pkey_export($resource, $privateKey));
        $details = openssl_pkey_get_details($resource);
        $this->assertIsArray($details);
        $publicKey = "\x04".$details['ec']['x'].$details['ec']['y'];

        config([
            'pwa.enabled' => true,
            'pwa.push.enabled' => true,
            'pwa.push.private_key' => base64_encode($privateKey),
            'pwa.push.public_key' => rtrim(strtr(base64_encode($publicKey), '+/', '-_'), '='),
            'pwa.push.subject' => 'mailto:operations@example.com',
        ]);
    }
}
