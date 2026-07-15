<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Livewire\Auth\VerifyEmailPage;
use App\Models\User;
use App\Notifications\VerifyAccountEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

final class WebEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_web_verification_routes_exist_and_notice_is_private(): void
    {
        $this->assertTrue(Route::has('verification.notice'));
        $this->assertTrue(Route::has('verification.verify'));

        $this->get(route('verification.notice'))
            ->assertRedirect(route('login'));

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSeeText('Подтверждение почты')
            ->assertSeeText($user->email)
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_signed_web_verification_works_without_an_active_session_and_is_idempotent(): void
    {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->get($url)
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Адрес электронной почты подтверждён.');

        $this->assertNotNull($user->fresh()->email_verified_at);
        Event::assertDispatchedTimes(Verified::class, 1);

        $this->get($url)
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Адрес электронной почты уже был подтверждён.');

        Event::assertDispatchedTimes(Verified::class, 1);
    }

    public function test_signed_verification_returns_the_matching_authenticated_owner_to_library(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)
            ->get($url)
            ->assertRedirect(route('library.index'))
            ->assertSessionHas('status', 'Адрес электронной почты подтверждён.');
    }

    public function test_tampered_expired_and_wrong_hash_links_render_a_safe_russian_error(): void
    {
        $user = User::factory()->unverified()->create();
        $valid = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $tampered = $valid.'&tampered=1';
        $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);
        $wrongHash = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => str_repeat('0', 40),
        ]);

        foreach ([$tampered, $expired, $wrongHash] as $url) {
            $response = $this->get($url)
                ->assertForbidden()
                ->assertSeeText('Ссылка недействительна или срок её действия истёк.');

            $this->assertStringNotContainsString('Illuminate\\', $response->getContent());
            $this->assertStringNotContainsString('Stack trace', $response->getContent());
        }

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_unverified_user_can_resend_with_a_dedicated_limit_and_verified_user_cannot(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        foreach (range(1, 3) as $attempt) {
            Livewire::actingAs($user)
                ->test(VerifyEmailPage::class)
                ->call('resend')
                ->assertHasNoErrors()
                ->assertSet('status', 'Новое письмо для подтверждения отправлено.');
        }

        Notification::assertSentToTimes($user, VerifyAccountEmail::class, 3);

        Livewire::actingAs($user)
            ->test(VerifyEmailPage::class)
            ->call('resend')
            ->assertHasErrors('email')
            ->assertSeeText('Слишком много запросов.');

        $verified = User::factory()->create();

        Livewire::actingAs($verified)
            ->test(VerifyEmailPage::class)
            ->assertRedirect(route('library.index'));

        Notification::assertNotSentTo($verified, VerifyAccountEmail::class);
    }

    public function test_human_verification_notification_uses_the_web_completion_route(): void
    {
        $user = User::factory()->unverified()->create();
        $message = (new VerifyAccountEmail)->toMail($user);

        $this->assertIsString($message->actionUrl);
        $this->assertSame(
            route('verification.verify', [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ], absolute: false),
            parse_url($message->actionUrl, PHP_URL_PATH),
        );
    }
}
