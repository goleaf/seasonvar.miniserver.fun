<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_registers_with_normalized_email_and_receives_one_plain_token(): void
    {
        Notification::fake();
        DB::table('password_reset_tokens')->insert([
            'email' => 'ivan@example.com',
            'token' => Hash::make('orphaned-reset-token'),
            'created_at' => now(),
        ]);

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
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'ivan@example.com']);
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

    public function test_openapi_describes_the_complete_mobile_authentication_contract(): void
    {
        $document = $this->getJson('/api/openapi.json')->assertOk();

        foreach ([
            'paths./api/v1/auth/register.post.operationId' => 'registerMobileUser',
            'paths./api/v1/auth/login.post.operationId' => 'loginMobileUser',
            'paths./api/v1/auth/email/verify/{id}/{hash}.get.operationId' => 'verifyMobileEmail',
            'paths./api/v1/auth/email/verification-notification.post.operationId' => 'resendMobileEmailVerification',
            'paths./api/v1/auth/forgot-password.post.operationId' => 'requestMobilePasswordReset',
            'paths./api/v1/auth/reset-password.post.operationId' => 'resetMobilePassword',
            'paths./api/v1/auth/devices.get.operationId' => 'listMobileDevices',
            'paths./api/v1/auth/devices/{token}.delete.operationId' => 'revokeMobileDevice',
            'paths./api/v1/auth/token/refresh.post.operationId' => 'refreshMobileToken',
            'paths./api/v1/auth/logout.post.operationId' => 'logoutMobileDevice',
            'paths./api/v1/auth/logout-all.post.operationId' => 'logoutAllMobileDevices',
            'paths./api/v1/me.get.operationId' => 'getMobileAccount',
            'paths./api/v1/me.patch.operationId' => 'updateMobileAccount',
            'paths./api/v1/me.delete.operationId' => 'deleteMobileAccount',
            'paths./api/v1/me/password.patch.operationId' => 'updateMobilePassword',
        ] as $path => $operationId) {
            $document->assertJsonPath($path, $operationId);
        }

        $document
            ->assertJsonPath('paths./api/v1/me.get.security.0.bearerAuth', [])
            ->assertJsonPath('paths./api/v1/auth/devices.get.security.0.bearerAuth', [])
            ->assertJsonPath(
                'paths./api/v1/auth/email/verify/{id}/{hash}.get.responses.200.content.application/json.schema.$ref',
                '#/components/schemas/VerificationResponse',
            )
            ->assertJsonPath('components.schemas.User.type', 'object')
            ->assertJsonPath('components.schemas.DeviceToken.properties.current.type', 'boolean')
            ->assertJsonPath('components.schemas.AuthTokenResponse.properties.data.properties.token_type.const', 'Bearer')
            ->assertJsonPath('components.responses.Unauthorized.content.application/json.examples.default.value.code', 'unauthenticated')
            ->assertJsonPath('components.responses.Forbidden.content.application/json.examples.default.value.code', 'forbidden')
            ->assertJsonPath('components.responses.TooManyRequests.content.application/json.examples.default.value.code', 'rate_limited');
    }
}
