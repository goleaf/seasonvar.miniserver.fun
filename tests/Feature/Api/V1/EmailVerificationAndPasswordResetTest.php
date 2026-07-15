<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Notifications\ResetAccountPassword;
use App\Notifications\VerifyAccountEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class EmailVerificationAndPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_sends_queued_mobile_verification_notification(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Новый пользователь',
            'email' => 'verify@example.com',
            'password' => 'Very-Strong-Password-42!',
            'password_confirmation' => 'Very-Strong-Password-42!',
            'device_name' => 'Pixel 9',
        ])->assertCreated();

        $user = User::query()->where('email', 'verify@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyAccountEmail::class);
        $this->assertInstanceOf(ShouldQueue::class, new VerifyAccountEmail);
    }

    public function test_signed_verification_link_works_without_bearer_and_rejects_invalid_signatures(): void
    {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('api.v1.auth.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $verified = $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.email_verified', true)
            ->assertHeader('Cache-Control');
        $this->assertStringContainsString('private', (string) $verified->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $verified->headers->get('Cache-Control'));
        $this->assertNotNull($user->fresh()->email_verified_at);
        Event::assertDispatched(Verified::class, fn (Verified $event): bool => $event->user->is($user));

        $tampered = URL::temporarySignedRoute('api.v1.auth.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => str_repeat('0', 40),
        ]);
        $this->getJson($tampered)->assertForbidden();

        $expired = URL::temporarySignedRoute('api.v1.auth.verify', now()->subMinute(), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);
        $this->getJson($expired)->assertForbidden();
    }

    public function test_verification_resend_requires_authentication_and_is_rate_limited(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/email/verification-notification')->assertUnauthorized();

        $user = User::factory()->unverified()->create();
        $token = $user->createToken('Verification', ['mobile:read', 'mobile:write'], now()->addDay());

        foreach (range(1, 3) as $attempt) {
            $this->withToken($token->plainTextToken)
                ->postJson('/api/v1/auth/email/verification-notification')
                ->assertOk();
        }

        Notification::assertSentToTimes($user, VerifyAccountEmail::class, 3);
        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertTooManyRequests();
    }

    public function test_forgot_password_is_non_enumerating_and_reset_revokes_all_tokens(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $user->createToken('Old phone', ['mobile:read', 'mobile:write'], now()->addDay());
        $expected = ['data' => [
            'status' => 'Если аккаунт существует, письмо для восстановления отправлено.',
        ]];

        $existing = $this->postJson('/api/v1/auth/forgot-password', ['email' => ' RESET@example.com '])
            ->assertOk()
            ->assertExactJson($expected);
        $missing = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'missing@example.com'])
            ->assertOk()
            ->assertExactJson($expected);
        $this->assertSame($existing->getContent(), $missing->getContent());

        $resetToken = null;
        Notification::assertSentTo(
            $user,
            ResetAccountPassword::class,
            function (ResetAccountPassword $notification) use (&$resetToken): bool {
                $resetToken = $notification->token;

                return $notification instanceof ShouldQueue;
            },
        );
        $this->assertIsString($resetToken);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'reset@example.com',
            'token' => $resetToken,
            'password' => 'New-Very-Strong-Password-43!',
            'password_confirmation' => 'New-Very-Strong-Password-43!',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Пароль успешно изменён.');

        $this->assertTrue(Hash::check('New-Very-Strong-Password-43!', $user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'reset@example.com',
            'token' => 'invalid-reset-token',
            'password' => 'Another-Strong-Password-44!',
            'password_confirmation' => 'Another-Strong-Password-44!',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.email.0', 'Не удалось сбросить пароль с указанными данными.');
    }
}
