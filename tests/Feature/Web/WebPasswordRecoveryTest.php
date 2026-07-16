<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Livewire\Auth\ConfirmPasswordPage;
use App\Livewire\Auth\ForgotPasswordPage;
use App\Livewire\Auth\ResetPasswordPage;
use App\Models\User;
use App\Notifications\ResetAccountPassword;
use App\Services\Auth\AccountPasswordResetService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Tests\TestCase;

final class WebPasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_recovery_and_confirmation_routes_exist_and_render_with_correct_guards(): void
    {
        foreach (['password.request', 'password.reset', 'password.confirm'] as $route) {
            $this->assertTrue(Route::has($route), "Missing browser password route: {$route}");
        }

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSeeText('Восстановление пароля');
        $this->get(route('password.reset', ['token' => 'visibleresettoken']).'?email=user%40example.com')
            ->assertOk()
            ->assertSeeText('Новый пароль');
        $this->get(route('password.confirm'))
            ->assertRedirect(route('login'));
    }

    public function test_forgot_password_exposes_one_non_enumerating_status(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $requestStatus = app(AccountPasswordResetService::class)->requestStatus();

        foreach ([' RESET@example.com ', 'missing@example.com'] as $email) {
            Livewire::test(ForgotPasswordPage::class)
                ->set('form.email', $email)
                ->call('sendResetLink')
                ->assertHasNoErrors()
                ->assertSet('status', $requestStatus)
                ->assertSeeText($requestStatus);
        }

        Notification::assertSentToTimes($user, ResetAccountPassword::class, 1);
    }

    public function test_human_reset_notification_uses_the_web_reset_form(): void
    {
        $user = User::factory()->create(['email' => 'reset-link@example.com']);
        $message = (new ResetAccountPassword('plainresettoken'))->toMail($user);

        $this->assertIsString($message->actionUrl);
        $this->assertSame(
            route('password.reset', ['token' => 'plainresettoken'], absolute: false),
            parse_url($message->actionUrl, PHP_URL_PATH),
        );
        parse_str((string) parse_url($message->actionUrl, PHP_URL_QUERY), $query);
        $this->assertSame('reset-link@example.com', $query['email'] ?? null);
    }

    public function test_valid_web_reset_consumes_token_changes_password_and_revokes_api_tokens(): void
    {
        Event::fake([PasswordReset::class]);
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $user->createToken('Phone', ['mobile:read', 'mobile:write'], now()->addDay());
        $token = Password::createToken($user);

        Livewire::withQueryParams(['email' => ' RESET@example.com '])
            ->test(ResetPasswordPage::class, ['token' => $token])
            ->set('form.password', 'New-Very-Strong-Password-43!')
            ->set('form.passwordConfirmation', 'New-Very-Strong-Password-43!')
            ->call('resetPassword')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $this->assertSame('Пароль успешно изменён. Войдите с новым паролем.', session('status'));
        $this->assertTrue(Hash::check('New-Very-Strong-Password-43!', $user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        Event::assertDispatchedTimes(PasswordReset::class, 1);

        Livewire::withQueryParams(['email' => 'reset@example.com'])
            ->test(ResetPasswordPage::class, ['token' => $token])
            ->set('form.password', 'Another-Strong-Password-44!')
            ->set('form.passwordConfirmation', 'Another-Strong-Password-44!')
            ->call('resetPassword')
            ->assertHasErrors('form.email');
    }

    public function test_password_confirmation_records_framework_timestamp_and_honours_intended_url(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(ConfirmPasswordPage::class)
            ->set('form.password', 'wrong-password')
            ->call('confirm')
            ->assertHasErrors(['form.password' => 'Не удалось подтвердить пароль.']);

        $this->withSession(['url.intended' => route('library.index')]);

        Livewire::test(ConfirmPasswordPage::class)
            ->set('form.password', 'password')
            ->call('confirm')
            ->assertHasNoErrors()
            ->assertRedirect(route('library.index'));

        $this->assertSame(now()->unix(), session('auth.password_confirmed_at'));
    }

    public function test_auth_session_logs_out_a_browser_session_after_password_hash_changes(): void
    {
        $this->assertContains(
            AuthenticateSession::class,
            app(PersistentMiddleware::class)->getPersistentMiddleware(),
        );

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('library.index'))
            ->assertOk();

        $this->assertNotNull(session('password_hash_web'));

        $user->forceFill(['password' => 'New-Very-Strong-Password-43!'])->save();
        $this->app['auth']->forgetGuards();

        $this->get(route('library.index'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
