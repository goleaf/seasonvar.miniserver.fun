<?php

declare(strict_types=1);

namespace Tests\Feature\Pwa;

use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\Auth\WebAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WebPushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_schema_is_encrypted_indexed_and_cascades_with_user(): void
    {
        $this->assertTrue(Schema::hasColumns('web_push_subscriptions', [
            'id',
            'public_id',
            'user_id',
            'endpoint',
            'endpoint_hash',
            'installation_hash',
            'locale',
            'failure_count',
            'last_success_at',
            'last_failure_at',
            'disabled_at',
            'created_at',
            'updated_at',
        ]));

        $indexes = collect(Schema::getIndexes('web_push_subscriptions'))->keyBy('name');
        $this->assertTrue((bool) $indexes->get('web_push_endpoint_hash_unique')['unique']);
        $this->assertSame(
            ['user_id', 'disabled_at', 'id'],
            $indexes->get('web_push_user_delivery_idx')['columns'],
        );
        $this->assertSame(
            ['user_id', 'installation_hash'],
            $indexes->get('web_push_user_installation_unique')['columns'],
        );

        $user = User::factory()->create();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/secret-capability';
        $subscription = WebPushSubscription::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'installation_hash' => hash('sha256', (string) Str::uuid()),
            'locale' => 'ru',
        ]);

        $this->assertSame($endpoint, $subscription->fresh()?->endpoint);
        $this->assertNotSame(
            $endpoint,
            DB::table('web_push_subscriptions')->where('id', $subscription->id)->value('endpoint'),
        );

        $user->deleteOrFail();
        $this->assertDatabaseCount('web_push_subscriptions', 0);
    }

    public function test_subscription_registration_is_owner_scoped_validated_and_transferable(): void
    {
        $this->configurePush([
            'fcm.googleapis.com',
            '*.push.services.mozilla.com',
            'web.push.apple.com',
        ]);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $installation = (string) Str::uuid();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/private-endpoint';
        $payload = [
            'installation_id' => $installation,
            'endpoint' => $endpoint,
            'locale' => 'ru',
        ];

        $this->postJson('/pwa/push-subscriptions', $payload)->assertUnauthorized();

        $this->actingAs($first)
            ->postJson('/pwa/push-subscriptions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.enabled', true);

        $this->assertDatabaseHas('web_push_subscriptions', [
            'user_id' => $first->id,
            'endpoint_hash' => hash('sha256', $endpoint),
            'installation_hash' => hash('sha256', $installation),
            'locale' => 'ru',
        ]);

        $this->actingAs($second)
            ->postJson('/pwa/push-subscriptions', [
                ...$payload,
                'locale' => 'en',
            ])
            ->assertCreated();

        $this->assertDatabaseCount('web_push_subscriptions', 1);
        $this->assertDatabaseHas('web_push_subscriptions', [
            'user_id' => $second->id,
            'endpoint_hash' => hash('sha256', $endpoint),
            'locale' => 'en',
        ]);
    }

    public function test_subscription_rejects_non_browser_or_private_endpoints_and_owner_can_revoke_current_installation(): void
    {
        $this->configurePush(['fcm.googleapis.com']);
        $user = User::factory()->create();
        $installation = (string) Str::uuid();

        foreach ([
            'http://fcm.googleapis.com/fcm/send/no-tls',
            'https://user:password@fcm.googleapis.com/fcm/send/credentials',
            'https://127.0.0.1/push',
            'https://example.com/push',
            'https://fcm.googleapis.com:8443/fcm/send/wrong-port',
        ] as $endpoint) {
            $this->actingAs($user)
                ->postJson('/pwa/push-subscriptions', [
                    'installation_id' => $installation,
                    'endpoint' => $endpoint,
                    'locale' => 'ru',
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('endpoint');
        }

        $endpoint = 'https://fcm.googleapis.com/fcm/send/revocable';
        $this->actingAs($user)
            ->postJson('/pwa/push-subscriptions', [
                'installation_id' => $installation,
                'endpoint' => $endpoint,
                'locale' => 'ru',
            ])
            ->assertCreated();

        $this->actingAs($user)
            ->deleteJson('/pwa/push-subscriptions', [
                'installation_id' => $installation,
            ])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->assertDatabaseCount('web_push_subscriptions', 0);
    }

    public function test_web_logout_removes_only_the_current_installation_mapping(): void
    {
        $user = User::factory()->create();
        $currentInstallation = (string) Str::uuid();
        $otherInstallation = (string) Str::uuid();

        foreach ([
            [$currentInstallation, 'current'],
            [$otherInstallation, 'other'],
        ] as [$installation, $suffix]) {
            WebPushSubscription::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'endpoint' => "https://fcm.googleapis.com/fcm/send/{$suffix}",
                'endpoint_hash' => hash('sha256', "https://fcm.googleapis.com/fcm/send/{$suffix}"),
                'installation_hash' => hash('sha256', $installation),
                'locale' => 'ru',
            ]);
        }

        $this->actingAs($user);
        session()->put('pwa_push_installation_hash', hash('sha256', $currentInstallation));
        app(WebAuthenticationService::class)->logout();

        $this->assertGuest();
        $this->assertDatabaseMissing('web_push_subscriptions', [
            'installation_hash' => hash('sha256', $currentInstallation),
        ]);
        $this->assertDatabaseHas('web_push_subscriptions', [
            'installation_hash' => hash('sha256', $otherInstallation),
        ]);
    }

    /** @param list<string> $allowedHosts */
    private function configurePush(array $allowedHosts): void
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
            'pwa.push.allowed_hosts' => $allowedHosts,
        ]);
    }
}
