<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\Season;
use App\Models\User;
use App\Notifications\VerifyMobileEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_requires_a_read_token_and_returns_the_private_user_resource(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');

        $user = User::factory()->create();
        $token = $user->createToken('Pixel 9', ['mobile:read'], now()->addDay());

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', $user->name)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonMissingPath('data.password')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_profile_update_normalizes_fields_and_reverifies_only_after_email_change(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'name' => 'Иван',
            'email' => 'ivan@example.com',
            'email_verified_at' => now()->subDay(),
        ]);
        $token = $user->createToken('Pixel 9', ['mobile:read', 'mobile:write'], now()->addDay());

        $this->withToken($token->plainTextToken)
            ->patchJson('/api/v1/me', ['name' => '  Иван   Иванов  '])
            ->assertOk()
            ->assertJsonPath('data.name', 'Иван Иванов')
            ->assertJsonPath('data.email_verified', true)
            ->assertHeader('Cache-Control', 'no-store, private');
        Notification::assertNothingSent();

        $this->withToken($token->plainTextToken)
            ->patchJson('/api/v1/me', ['email' => ' NEW@EXAMPLE.COM '])
            ->assertOk()
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.email_verified_at', null)
            ->assertHeader('Cache-Control', 'no-store, private');

        $user->refresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentToTimes($user, VerifyMobileEmail::class, 1);

        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'new@example.com');
    }

    public function test_profile_update_rejects_case_insensitive_duplicate_email_and_empty_payload(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        User::factory()->create(['email' => 'Existing@Example.com']);
        $token = $user->createToken('Pixel 9', ['mobile:read', 'mobile:write'], now()->addDay());

        $this->withToken($token->plainTextToken)
            ->patchJson('/api/v1/me', ['email' => ' existing@EXAMPLE.com '])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.email.0', 'Этот адрес электронной почты уже используется.');

        $this->withToken($token->plainTextToken)
            ->patchJson('/api/v1/me', [])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed');

        $this->assertSame('owner@example.com', $user->fresh()->email);
    }

    public function test_password_change_requires_the_current_password_and_revokes_every_other_token(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('Current phone', ['mobile:read', 'mobile:write'], now()->addDay());
        $otherDevice = $user->createToken('Tablet', ['mobile:read', 'mobile:write'], now()->addDay());
        $newPassword = 'New-Very-Strong-Password-43!';

        $this->withToken($current->plainTextToken)
            ->patchJson('/api/v1/me/password', [
                'current_password' => 'wrong-password',
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.current_password.0', 'Текущий пароль указан неверно.');

        $this->withToken($current->plainTextToken)
            ->patchJson('/api/v1/me/password', [
                'current_password' => 'password',
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'Пароль успешно изменён.')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertTrue(Hash::check($newPassword, $user->fresh()->password));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherDevice->accessToken->id]);

        $this->app['auth']->forgetGuards();
        $this->withToken($current->plainTextToken)
            ->getJson('/api/v1/me')
            ->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherDevice->plainTextToken)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_account_delete_verifies_password_and_removes_tokens_and_private_catalog_state(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('Current phone', ['mobile:read', 'mobile:write'], now()->addDay());
        $user->createToken('Tablet', ['mobile:read', 'mobile:write'], now()->addDay());
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->for($title, 'catalogTitle')->create();
        $episode = Episode::factory()->for($season)->create();
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => true,
        ]);
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'episode_id' => $episode->id,
            'position_seconds' => 120,
            'duration_seconds' => 1200,
            'last_watched_at' => now(),
        ]);

        $this->withToken($current->plainTextToken)
            ->deleteJson('/api/v1/me', ['password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'Не удалось подтвердить пароль.');
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        $this->withToken($current->plainTextToken)
            ->deleteJson('/api/v1/me', ['password' => 'password'])
            ->assertNoContent()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('catalog_title_user_states', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('episode_view_progress', ['user_id' => $user->id]);
        $this->assertDatabaseHas('catalog_titles', ['id' => $title->id]);
    }
}
