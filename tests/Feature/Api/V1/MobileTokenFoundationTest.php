<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\HasApiTokens;
use Tests\TestCase;

final class MobileTokenFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_uses_sanctum_and_issues_a_hashed_expiring_mobile_token(): void
    {
        $this->assertContains(HasApiTokens::class, class_uses_recursive(User::class));

        $user = User::factory()->create();
        $token = $user->createToken(
            'Pixel 9',
            ['mobile:read', 'mobile:write'],
            now()->addDays(90),
        );

        $this->assertNotSame($token->plainTextToken, $token->accessToken->token);
        $this->assertSame(['mobile:read', 'mobile:write'], $token->accessToken->abilities);
        $this->assertTrue($token->accessToken->expires_at?->isFuture());
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'Pixel 9',
        ]);
    }

    public function test_expired_sanctum_tokens_are_scheduled_for_daily_pruning(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('sanctum:prune-expired --hours=24')
            ->assertSuccessful();
    }
}
