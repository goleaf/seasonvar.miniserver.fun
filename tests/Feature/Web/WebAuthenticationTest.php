<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Livewire\Auth\LoginPage;
use App\Livewire\Auth\LogoutButton;
use App\Livewire\Auth\RegisterPage;
use App\Models\User;
use App\Notifications\VerifyAccountEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

final class WebAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_authentication_routes_render_and_private_library_redirects_to_login(): void
    {
        foreach (['login', 'register', 'verification.notice', 'library.index'] as $route) {
            $this->assertTrue(Route::has($route), "Missing browser account route: {$route}");
        }

        $this->get('/login')
            ->assertOk()
            ->assertSeeText('Вход');
        $this->get('/register')
            ->assertOk()
            ->assertSeeText('Регистрация');
        $this->get('/library')
            ->assertRedirect(route('login'));
    }

    public function test_guest_can_register_with_normalized_identity_and_is_sent_to_verification(): void
    {
        Notification::fake();

        Livewire::test(RegisterPage::class)
            ->set('form.name', '  Иван   Иванов  ')
            ->set('form.email', ' IVAN@EXAMPLE.COM ')
            ->set('form.password', 'Very-Strong-Password-42!')
            ->set('form.passwordConfirmation', 'Very-Strong-Password-42!')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'ivan@example.com')->firstOrFail();

        $this->assertSame('Иван Иванов', $user->name);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Auth::check());
        $this->assertTrue(Auth::user()?->is($user));
        Notification::assertSentTo($user, VerifyAccountEmail::class);
    }

    public function test_registration_rejects_weak_password_and_case_insensitive_duplicate_email(): void
    {
        User::factory()->create(['email' => 'Existing@Example.com']);

        Livewire::test(RegisterPage::class)
            ->set('form.name', 'Иван Иванов')
            ->set('form.email', ' existing@EXAMPLE.com ')
            ->set('form.password', 'weak')
            ->set('form.passwordConfirmation', 'different')
            ->call('register')
            ->assertHasErrors(['form.email', 'form.password', 'form.passwordConfirmation'])
            ->assertSeeText('Этот адрес электронной почты уже используется.');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_uses_a_generic_error_and_honours_the_intended_destination(): void
    {
        $user = User::factory()->create(['email' => 'User@Example.com']);

        Livewire::test(LoginPage::class)
            ->set('form.email', 'missing@example.com')
            ->set('form.password', 'wrong-password')
            ->call('login')
            ->assertHasErrors(['form.email' => 'Указаны неверные данные для входа.']);

        Livewire::test(LoginPage::class)
            ->set('form.email', ' USER@example.COM ')
            ->set('form.password', 'wrong-password')
            ->call('login')
            ->assertHasErrors(['form.email' => 'Указаны неверные данные для входа.']);

        $this->withSession(['url.intended' => route('library.index')]);
        $oldSessionId = session()->getId();

        Livewire::test(LoginPage::class)
            ->set('form.email', ' USER@example.COM ')
            ->set('form.password', 'password')
            ->set('form.remember', true)
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('library.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSessionId, session()->getId());
    }

    public function test_browser_registration_and_login_have_separate_action_rate_limits(): void
    {
        Notification::fake();

        foreach (range(1, 3) as $attempt) {
            Livewire::test(RegisterPage::class)
                ->set('form.name', 'Пользователь '.$attempt)
                ->set('form.email', 'register-'.$attempt.'@example.com')
                ->set('form.password', 'Very-Strong-Password-42!')
                ->set('form.passwordConfirmation', 'Very-Strong-Password-42!')
                ->call('register')
                ->assertHasNoErrors()
                ->assertRedirect(route('verification.notice'));

            Auth::guard('web')->logout();
        }

        Livewire::test(RegisterPage::class)
            ->set('form.name', 'Лишний пользователь')
            ->set('form.email', 'register-4@example.com')
            ->set('form.password', 'Very-Strong-Password-42!')
            ->set('form.passwordConfirmation', 'Very-Strong-Password-42!')
            ->call('register')
            ->assertHasErrors('form.email')
            ->assertSeeText('Слишком много попыток регистрации.');

        foreach (range(1, 5) as $attempt) {
            Livewire::test(LoginPage::class)
                ->set('form.email', 'limited@example.com')
                ->set('form.password', 'wrong-password')
                ->call('login')
                ->assertHasErrors(['form.email' => 'Указаны неверные данные для входа.']);
        }

        Livewire::test(LoginPage::class)
            ->set('form.email', 'limited@example.com')
            ->set('form.password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('form.email')
            ->assertSeeText('Слишком много попыток входа.');

        $this->assertDatabaseCount('users', 3);
    }

    public function test_authenticated_user_can_logout_and_the_private_session_is_invalidated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        session()->put('private-marker', 'must-disappear');
        $oldToken = session()->token();

        Livewire::test(LogoutButton::class)
            ->call('logout')
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull(session('private-marker'));
        $this->assertNotSame($oldToken, session()->token());
    }

    public function test_header_exposes_only_navigation_available_to_the_current_visitor(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('login'), false)
            ->assertSee(route('register'), false)
            ->assertDontSee('Выйти');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee(route('library.index'), false)
            ->assertSeeText('Выйти')
            ->assertDontSee(route('register'), false);
    }
}
