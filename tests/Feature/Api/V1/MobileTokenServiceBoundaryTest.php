<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\DTOs\MobileTokenRotationResult;
use App\Models\User;
use App\Services\Auth\MobileTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class MobileTokenServiceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_lists_only_owner_devices_and_returns_a_typed_rotation_result(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $older = $user->createToken('Tablet', ['mobile:read'], now()->addDay());
        $current = $user->createToken('Pixel 9', ['mobile:read', 'mobile:write'], now()->addDay());
        $foreign = $otherUser->createToken('Foreign', ['mobile:read'], now()->addDay());
        $service = app(MobileTokenService::class);

        $this->assertSame(
            [$current->accessToken->id, $older->accessToken->id],
            $service->devices($user)->modelKeys(),
        );

        $result = $service->rotate($user, $current->accessToken);

        $this->assertInstanceOf(MobileTokenRotationResult::class, $result);
        $this->assertTrue($result->expiresAt->isFuture());
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $older->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $foreign->accessToken->id]);

        $replacement = PersonalAccessToken::findToken($result->token);

        $this->assertNotNull($replacement);
        $this->assertSame($user->id, $replacement->tokenable_id);
        $this->assertSame('Pixel 9', $replacement->name);
        $this->assertSame(['mobile:read', 'mobile:write'], $replacement->abilities);
    }
}
