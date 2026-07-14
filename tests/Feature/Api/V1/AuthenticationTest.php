<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_registers_with_normalized_email_and_receives_one_plain_token(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => '  Иван   Иванов  ',
            'email' => 'IVAN@EXAMPLE.COM ',
            'password' => 'Very-Strong-Password-42!',
            'password_confirmation' => 'Very-Strong-Password-42!',
            'device_name' => 'Pixel 9',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.name', 'Иван Иванов')
            ->assertJsonPath('data.user.email', 'ivan@example.com')
            ->assertJsonPath('data.user.email_verified', false)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['user', 'token', 'expires_at']]);

        $user = User::query()->where('email', 'ivan@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('Very-Strong-Password-42!', $user->password));
        $this->assertNotSame($response->json('data.token'), $user->tokens()->value('token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'Pixel 9',
        ]);
    }

    public function test_login_uses_one_generic_credential_error_and_records_device(): void
    {
        $user = User::factory()->create(['email' => 'User@Example.com']);

        foreach ([
            ['email' => 'missing@example.com', 'password' => 'password'],
            ['email' => 'USER@example.COM', 'password' => 'wrong-password'],
        ] as $credentials) {
            $this->postJson('/api/v1/auth/login', [...$credentials, 'device_name' => 'iPhone'])
                ->assertUnprocessable()
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonPath('errors.email.0', 'Указаны неверные данные для входа.');
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => ' USER@example.com ',
            'password' => 'password',
            'device_name' => '  iPhone   16  ',
        ])->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'iPhone 16']);
    }

    public function test_registration_rejects_case_insensitive_duplicates_and_invalid_identity_fields(): void
    {
        User::factory()->create(['email' => 'Existing@Example.com']);

        $valid = [
            'name' => 'Иван Иванов',
            'email' => 'new@example.com',
            'password' => 'Very-Strong-Password-42!',
            'password_confirmation' => 'Very-Strong-Password-42!',
            'device_name' => 'Pixel 9',
        ];

        foreach ([
            ['field' => 'email', 'payload' => ['email' => ' existing@EXAMPLE.com ']],
            ['field' => 'name', 'payload' => ['name' => 'x']],
            ['field' => 'device_name', 'payload' => ['device_name' => 'x']],
            ['field' => 'password', 'payload' => ['password' => 'weak', 'password_confirmation' => 'weak']],
            ['field' => 'password', 'payload' => ['password_confirmation' => 'Different-Password-42!']],
        ] as $case) {
            $this->postJson('/api/v1/auth/register', array_replace($valid, $case['payload']))
                ->assertUnprocessable()
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonValidationErrors($case['field']);
        }
    }

    public function test_credential_routes_use_dedicated_rate_limits(): void
    {
        $payload = [
            'email' => 'limited@example.com',
            'password' => 'wrong-password',
            'device_name' => 'Rate Device',
        ];

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', $payload)
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'rate_limited')
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
