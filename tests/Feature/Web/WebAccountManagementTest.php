<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Livewire\Profile\ProfilePage;
use App\Livewire\Profile\SecurityPage;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\User;
use App\Notifications\VerifyAccountEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

final class WebAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_and_security_routes_are_private_and_render_owner_navigation(): void
    {
        foreach (['profile.show', 'profile.security'] as $route) {
            $this->assertTrue(Route::has($route), "Missing browser account route: {$route}");
            $this->get(route($route))->assertRedirect(route('login'));
        }

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertSeeText('Профиль')
            ->assertSeeText('Моя библиотека')
            ->assertSeeText('Продолжить')
            ->assertSee($user->email);
        $this->get(route('profile.security'))
            ->assertOk()
            ->assertSeeText('Безопасность')
            ->assertSeeText('Устройства API');
        $this->get(route('home'))
            ->assertSee(route('settings.index'), false);
    }

    public function test_profile_update_normalizes_fields_and_reverifies_only_after_email_change(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'name' => 'Иван',
            'email' => 'old@example.com',
        ]);

        Livewire::actingAs($user)
            ->test(ProfilePage::class)
            ->set('name', '  Иван   Иванов ')
            ->set('email', ' NEW@EXAMPLE.COM ')
            ->call('saveProfile')
            ->assertHasNoErrors()
            ->assertSet('name', 'Иван Иванов')
            ->assertSet('email', 'new@example.com')
            ->assertSet('status', 'Профиль обновлён. Подтвердите новый адрес электронной почты.');

        $this->assertNull($user->fresh()->email_verified_at);
        Notification::assertSentToTimes($user, VerifyAccountEmail::class, 1);

        Livewire::actingAs($user->fresh())
            ->test(ProfilePage::class)
            ->set('name', '  Иван   Петров ')
            ->set('email', ' NEW@example.com ')
            ->call('saveProfile')
            ->assertHasNoErrors()
            ->assertSet('status', 'Профиль обновлён.');

        Notification::assertSentToTimes($user, VerifyAccountEmail::class, 1);
    }

    public function test_profile_rejects_case_insensitive_email_owned_by_another_user(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        User::factory()->create(['email' => 'Existing@Example.com']);

        Livewire::actingAs($user)
            ->test(ProfilePage::class)
            ->set('email', ' existing@EXAMPLE.com ')
            ->call('saveProfile')
            ->assertHasErrors('email')
            ->assertSeeText('Этот адрес электронной почты уже используется.');

        $this->assertSame('owner@example.com', $user->fresh()->email);
    }

    public function test_browser_password_change_keeps_current_session_and_revokes_api_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('Телефон', ['mobile:read', 'mobile:write'], now()->addDay());
        Auth::guard('web')->login($user);
        session()->put('password_hash_web', $user->password);

        $component = Livewire::test(SecurityPage::class)
            ->set('currentPassword', 'wrong-password')
            ->set('password', 'New-Very-Strong-Password-43!')
            ->set('passwordConfirmation', 'New-Very-Strong-Password-43!')
            ->call('updatePassword')
            ->assertHasErrors(['currentPassword' => 'Текущий пароль указан неверно.'])
            ->assertSet('currentPassword', '')
            ->assertSet('password', '')
            ->assertSet('passwordConfirmation', '');

        $component
            ->set('currentPassword', 'password')
            ->set('password', 'New-Very-Strong-Password-43!')
            ->set('passwordConfirmation', 'New-Very-Strong-Password-43!')
            ->call('updatePassword')
            ->assertHasNoErrors()
            ->assertSet('currentPassword', '')
            ->assertSet('password', '')
            ->assertSet('passwordConfirmation', '')
            ->assertSet('passwordStatus', 'Пароль успешно изменён.');

        $user->refresh();
        $this->assertTrue(Hash::check('New-Very-Strong-Password-43!', $user->password));
        $this->assertSame($user->password, session('password_hash_web'));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);

        $this->app['auth']->forgetGuards();
        $this->get(route('profile.security'))->assertOk();
    }

    public function test_owner_can_view_revoke_and_clear_only_their_api_devices(): void
    {
        $user = User::factory()->create();
        $phone = $user->createToken('Телефон', ['mobile:read'], now()->addDay());
        $tablet = $user->createToken('Планшет', ['mobile:read', 'mobile:write'], now()->addDay());

        Livewire::actingAs($user)
            ->test(SecurityPage::class)
            ->assertSeeText('Телефон')
            ->assertSeeText('Планшет')
            ->assertDontSee($phone->accessToken->token)
            ->assertDontSee('mobile:write')
            ->call('revokeDevice', $phone->accessToken->id)
            ->assertHasNoErrors()
            ->assertSet('deviceStatus', 'Устройство отключено.');

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $phone->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tablet->accessToken->id]);

        Livewire::actingAs($user)
            ->test(SecurityPage::class)
            ->call('revokeAllDevices')
            ->assertSet('deviceStatus', 'Все устройства API отключены.');

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_foreign_api_device_cannot_be_revoked_from_browser_account(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $foreignToken = $foreign->createToken('Чужое устройство', ['mobile:read'], now()->addDay());

        Livewire::actingAs($owner)
            ->test(SecurityPage::class)
            ->call('revokeDevice', $foreignToken->accessToken->id)
            ->assertNotFound();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $foreignToken->accessToken->id]);
    }

    public function test_logout_other_browser_sessions_preserves_current_session(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $currentSessionId = session()->getId();
        $this->actingAs($user);

        foreach ([$currentSessionId, 'other-browser-session'] as $sessionId) {
            DB::table('sessions')->insert([
                'id' => $sessionId,
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Web account test',
                'payload' => 'test-session-payload',
                'last_activity' => now()->timestamp,
            ]);
        }

        Livewire::test(SecurityPage::class)
            ->set('currentPassword', 'password')
            ->call('logoutOtherDevices')
            ->assertHasNoErrors()
            ->assertSet('currentPassword', '')
            ->assertSet('sessionStatus', 'Другие браузерные сессии завершены.');

        $this->assertDatabaseHas('sessions', ['id' => $currentSessionId, 'user_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-browser-session']);
        $this->assertSame($user->fresh()->password, session('password_hash_web'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_account_deletion_requires_password_and_removes_private_state(): void
    {
        $user = User::factory()->create();
        $user->createToken('Телефон', ['mobile:read'], now()->addDay());
        $title = CatalogTitle::factory()->create();
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => true,
        ]);
        DB::table('sessions')->insert([
            'id' => 'account-delete-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Web account deletion test',
            'payload' => 'test-session-payload',
            'last_activity' => now()->timestamp,
        ]);

        $component = Livewire::actingAs($user)
            ->test(SecurityPage::class)
            ->set('currentPassword', 'wrong-password')
            ->call('deleteAccount')
            ->assertHasErrors(['currentPassword' => 'Не удалось подтвердить пароль.']);

        $component
            ->set('currentPassword', 'password')
            ->call('deleteAccount')
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('catalog_title_user_states', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('catalog_titles', ['id' => $title->id]);
    }
}
