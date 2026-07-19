<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Actions\Administration\ApplyAccountRestriction;
use App\Actions\Administration\RevokeAccountRestriction;
use App\Enums\AccountRestrictionType;
use App\Enums\AdminAuditAction;
use App\Enums\AdminMembershipStatus;
use App\Enums\AdminRoleCode;
use App\Models\AdminAuditEvent;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Auth\MobileAuthenticationService;
use App\Services\Auth\WebAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AccountRestrictionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function blocking_restriction_revokes_sessions_and_tokens_preserves_premium_and_blocks_all_logins(): void
    {
        $actor = $this->administrator();
        $user = User::factory()->create([
            'email' => 'restricted@example.com',
            'password' => Hash::make('correct-password'),
        ]);
        $token = $user->createToken('existing-device', ['mobile:read']);
        DB::table('sessions')->insert([
            'id' => 'restricted-session',
            'user_id' => $user->id,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
        DB::table('premium_entitlements')->insert([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'feature_code' => 'ad_free',
            'source' => 'manual',
            'application_key' => hash('sha256', 'restriction-premium'),
            'starts_at' => now(),
            'is_lifetime' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $restriction = app(ApplyAccountRestriction::class)->handle(
            actor: $actor,
            target: $user,
            type: AccountRestrictionType::LoginSuspended,
            reasonCode: 'security_review',
            expiresAt: now()->addDay(),
            publicNoticeKey: 'administration.restrictions.notices.login_suspended',
            privateNote: 'Sensitive staff context that must never enter audit.',
            confirmed: true,
        );

        self::assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        self::assertSame(0, $user->tokens()->count());
        self::assertSame(1, DB::table('premium_entitlements')->where('user_id', $user->id)->count());
        self::assertFalse(app(WebAuthenticationService::class)->attempt($user->email, 'correct-password', false));

        try {
            app(MobileAuthenticationService::class)->login($user->email, 'correct-password', 'new-device');
            self::fail('A blocked account must not receive a mobile token.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('email', $exception->errors());
        }

        $event = AdminAuditEvent::query()->latest('id')->firstOrFail();
        self::assertSame(AdminAuditAction::AccountRestrictionApplied, $event->action);
        self::assertNotContains('private_note', $event->changed_fields);
        self::assertDatabaseHas('account_restrictions', ['id' => $restriction->id, 'reason_code' => 'security_review']);
        self::assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    #[Test]
    public function revocation_restores_future_authentication_without_restoring_old_sessions(): void
    {
        $actor = $this->administrator();
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        $restriction = app(ApplyAccountRestriction::class)->handle(
            $actor,
            $user,
            AccountRestrictionType::AccountDisabled,
            'appeal_pending',
            null,
            'administration.restrictions.notices.account_disabled',
            null,
            true,
        );

        app(RevokeAccountRestriction::class)->handle($actor, $restriction, 'appeal_approved', true);

        self::assertTrue(app(WebAuthenticationService::class)->attempt($user->email, 'correct-password', false));
        self::assertSame(AdminAuditAction::AccountRestrictionRevoked, AdminAuditEvent::query()->latest('id')->value('action'));
    }

    #[Test]
    public function an_existing_browser_session_cannot_bypass_a_blocking_restriction(): void
    {
        $actor = $this->administrator();
        $user = User::factory()->create();
        app(ApplyAccountRestriction::class)->handle(
            $actor,
            $user,
            AccountRestrictionType::LoginSuspended,
            'security_review',
            now()->addHour(),
            AccountRestrictionType::LoginSuspended->noticeKey(),
            null,
            true,
        );

        $this->actingAs($user)
            ->get(route('profile.show'))
            ->assertForbidden()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertGuest();
    }

    #[Test]
    public function an_optional_mobile_identity_cannot_bypass_a_blocking_restriction(): void
    {
        $actor = $this->administrator();
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        app(ApplyAccountRestriction::class)->handle(
            $actor,
            $user,
            AccountRestrictionType::LoginSuspended,
            'security_review',
            now()->addHour(),
            AccountRestrictionType::LoginSuspended->noticeKey(),
            null,
            true,
        );
        $token = $user->createToken('simulated-stale-token', ['mobile:read']);

        $this->withToken($token->plainTextToken)
            ->postJson("/api/v1/titles/{$title->slug}/playback-sessions")
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    private function administrator(): User
    {
        $user = User::factory()->create();
        AdminUserRole::query()->create([
            'user_id' => $user->id,
            'admin_role_id' => AdminRole::query()->where('code', AdminRoleCode::Moderator)->valueOrFail('id'),
            'status' => AdminMembershipStatus::Active,
            'reason_code' => 'restriction_test',
            'assigned_at' => now(),
        ]);

        return $user;
    }
}
