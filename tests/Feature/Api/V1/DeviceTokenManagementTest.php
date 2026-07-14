<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class DeviceTokenManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_lists_only_safe_metadata_for_their_own_device_tokens(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $current = $user->createToken('Pixel 9', ['mobile:read', 'mobile:write'], now()->addDays(90));
        $otherDevice = $user->createToken('iPhone 16', ['mobile:read', 'mobile:write'], now()->addDays(30));
        $foreign = $otherUser->createToken('Foreign device', ['mobile:read', 'mobile:write'], now()->addDay());

        $otherDevice->accessToken->forceFill(['last_used_at' => now()->subHour()])->save();

        $response = $this->withToken($current->plainTextToken)
            ->getJson('/api/v1/auth/devices')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $otherDevice->accessToken->id)
            ->assertJsonPath('data.0.name', 'iPhone 16')
            ->assertJsonPath('data.0.current', false)
            ->assertJsonPath('data.1.id', $current->accessToken->id)
            ->assertJsonPath('data.1.name', 'Pixel 9')
            ->assertJsonPath('data.1.current', true)
            ->assertJsonStructure(['data' => [
                '*' => ['id', 'name', 'last_used_at', 'expires_at', 'current'],
            ]]);

        $this->assertArrayNotHasKey('token', $response->json('data.0'));
        $this->assertArrayNotHasKey('abilities', $response->json('data.0'));
        $this->assertStringNotContainsString($foreign->accessToken->token, $response->getContent());
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_user_rotates_the_current_token_atomically_and_keeps_its_device_contract(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('Pixel 9', ['mobile:read', 'mobile:write'], now()->addDay());
        $oldTokenId = $current->accessToken->id;

        $newPlainTextToken = $this->withToken($current->plainTextToken)
            ->postJson('/api/v1/auth/token/refresh')
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['token', 'token_type', 'expires_at']])
            ->assertHeader('Cache-Control', 'no-store, private')
            ->json('data.token');

        $this->assertIsString($newPlainTextToken);
        $this->assertNotSame($current->plainTextToken, $newPlainTextToken);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldTokenId]);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $newToken = PersonalAccessToken::findToken($newPlainTextToken);
        $this->assertNotNull($newToken);
        $this->assertSame($user->id, $newToken->tokenable_id);
        $this->assertSame('Pixel 9', $newToken->name);
        $this->assertSame(['mobile:read', 'mobile:write'], $newToken->abilities);
        $this->assertTrue($newToken->expires_at?->isFuture());

        $this->app['auth']->forgetGuards();
        $this->withToken($current->plainTextToken)
            ->getJson('/api/v1/auth/devices')
            ->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($newPlainTextToken)
            ->getJson('/api/v1/auth/devices')
            ->assertOk();
    }

    public function test_logout_revokes_only_the_current_token_and_logout_all_revokes_every_token(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('Current phone', ['mobile:read', 'mobile:write'], now()->addDay());
        $otherDevice = $user->createToken('Tablet', ['mobile:read', 'mobile:write'], now()->addDay());

        $this->withToken($current->plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherDevice->accessToken->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($current->plainTextToken)
            ->getJson('/api/v1/auth/devices')
            ->assertUnauthorized();

        $thirdDevice = $user->createToken('Laptop', ['mobile:read', 'mobile:write'], now()->addDay());
        $this->app['auth']->forgetGuards();
        $this->withToken($otherDevice->plainTextToken)
            ->postJson('/api/v1/auth/logout-all')
            ->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($thirdDevice->plainTextToken)
            ->getJson('/api/v1/auth/devices')
            ->assertUnauthorized();
    }

    public function test_user_revokes_an_owned_device_but_cannot_address_another_users_token(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('Current phone', ['mobile:read', 'mobile:write'], now()->addDay());
        $ownedDevice = $user->createToken('Old tablet', ['mobile:read', 'mobile:write'], now()->addDay());
        $foreign = User::factory()->create()
            ->createToken('Foreign device', ['mobile:read', 'mobile:write'], now()->addDay());

        $this->withToken($current->plainTextToken)
            ->deleteJson('/api/v1/auth/devices/'.$ownedDevice->accessToken->id)
            ->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $ownedDevice->accessToken->id]);

        $this->withToken($current->plainTextToken)
            ->deleteJson('/api/v1/auth/devices/'.$foreign->accessToken->id)
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $foreign->accessToken->id]);
    }

    public function test_device_mutations_require_the_mobile_write_ability(): void
    {
        $user = User::factory()->create();
        $readOnly = $user->createToken('Read only', ['mobile:read'], now()->addDay());
        $otherDevice = $user->createToken('Other device', ['mobile:read', 'mobile:write'], now()->addDay());

        $this->withToken($readOnly->plainTextToken)
            ->getJson('/api/v1/auth/devices')
            ->assertOk();

        foreach ([
            ['post', '/api/v1/auth/token/refresh'],
            ['post', '/api/v1/auth/logout'],
            ['post', '/api/v1/auth/logout-all'],
            ['delete', '/api/v1/auth/devices/'.$otherDevice->accessToken->id],
        ] as [$method, $uri]) {
            $this->withToken($readOnly->plainTextToken)
                ->json($method, $uri)
                ->assertForbidden()
                ->assertJsonPath('code', 'forbidden');
        }

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $readOnly->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherDevice->accessToken->id]);
    }
}
