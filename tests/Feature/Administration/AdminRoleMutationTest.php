<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Actions\Administration\AssignAdminRole;
use App\Actions\Administration\RevokeAdminRole;
use App\Actions\Administration\SetAdminMembershipStatus;
use App\Enums\AdminAuditAction;
use App\Enums\AdminMembershipStatus;
use App\Enums\AdminPermission;
use App\Enums\AdminRoleCode;
use App\Exceptions\AdministrationAccessException;
use App\Models\AdminAuditEvent;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminRoleMutationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function role_assignment_requires_recent_authentication_and_assignable_permissions(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $this->directAssign($actor, AdminRoleCode::Superadministrator);

        $this->expectException(AdministrationAccessException::class);
        app(AssignAdminRole::class)->handle($actor, $target, AdminRoleCode::Moderator, 'staffing_change');
    }

    #[Test]
    public function actor_cannot_assign_a_role_containing_permissions_the_actor_does_not_possess(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $this->directAssign($actor, AdminRoleCode::Moderator);
        $this->withRecentAuthentication();

        self::assertTrue($actor->can(AdminPermission::RolesManage->value) === false);

        $this->expectException(AuthorizationException::class);
        app(AssignAdminRole::class)->handle($actor, $target, AdminRoleCode::ContentManager, 'invalid_escalation');
    }

    #[Test]
    public function authorized_assignment_is_idempotent_and_audited_without_private_reason_text(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $this->directAssign($actor, AdminRoleCode::Superadministrator);
        $this->withRecentAuthentication();

        $first = app(AssignAdminRole::class)->handle($actor, $target, AdminRoleCode::Moderator, 'staffing_change');
        $second = app(AssignAdminRole::class)->handle($actor, $target, AdminRoleCode::Moderator, 'staffing_change');

        self::assertTrue($first->is($second));
        self::assertSame(AdminMembershipStatus::Active, $second->status);
        self::assertSame(1, AdminUserRole::query()->whereBelongsTo($target)->where('admin_role_id', $second->admin_role_id)->count());
        self::assertSame(1, AdminAuditEvent::query()->where('action', AdminAuditAction::AdministratorRoleAssigned->value)->count());
        self::assertStringNotContainsString('staffing_change', AdminAuditEvent::query()->latest('id')->value('after_version'));
    }

    #[Test]
    public function final_active_superadministrator_cannot_be_revoked(): void
    {
        $actor = User::factory()->create();
        $membership = $this->directAssign($actor, AdminRoleCode::Superadministrator);
        $this->withRecentAuthentication();

        $this->expectException(AdministrationAccessException::class);
        app(RevokeAdminRole::class)->handle($actor, $membership, 'security_correction', true);
    }

    #[Test]
    public function one_of_multiple_superadministrators_can_be_revoked_with_confirmation_and_audit(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $this->directAssign($actor, AdminRoleCode::Superadministrator);
        $membership = $this->directAssign($target, AdminRoleCode::Superadministrator);
        $this->withRecentAuthentication();

        $revoked = app(RevokeAdminRole::class)->handle($actor, $membership, 'security_correction', true);

        self::assertSame(AdminMembershipStatus::Revoked, $revoked->status);
        self::assertNotNull($revoked->revoked_at);
        self::assertSame($actor->id, $revoked->revoked_by_id);
        self::assertSame(1, AdminAuditEvent::query()->where('action', AdminAuditAction::AdministratorRoleRevoked->value)->count());
    }

    #[Test]
    public function membership_can_be_suspended_and_restored_with_audit_and_final_superadministrator_protection(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $this->directAssign($actor, AdminRoleCode::Superadministrator);
        $membership = $this->directAssign($target, AdminRoleCode::Moderator);
        $this->withRecentAuthentication();

        $suspended = app(SetAdminMembershipStatus::class)->handle(
            $actor,
            $membership,
            AdminMembershipStatus::Suspended,
            'security_review',
            true,
        );
        self::assertSame(AdminMembershipStatus::Suspended, $suspended->status);
        self::assertNotNull($suspended->suspended_at);

        $restored = app(SetAdminMembershipStatus::class)->handle(
            $actor,
            $suspended,
            AdminMembershipStatus::Active,
            'review_completed',
            true,
        );
        self::assertSame(AdminMembershipStatus::Active, $restored->status);
        self::assertNull($restored->suspended_at);
        self::assertSame(1, AdminAuditEvent::query()->where('action', AdminAuditAction::AdministratorSuspended->value)->count());
        self::assertSame(1, AdminAuditEvent::query()->where('action', AdminAuditAction::AdministratorRestored->value)->count());
    }

    private function directAssign(User $user, AdminRoleCode $roleCode): AdminUserRole
    {
        return AdminUserRole::query()->create([
            'user_id' => $user->id,
            'admin_role_id' => AdminRole::query()->where('code', $roleCode)->valueOrFail('id'),
            'status' => AdminMembershipStatus::Active,
            'reason_code' => 'test_bootstrap',
            'assigned_at' => now(),
        ]);
    }

    private function withRecentAuthentication(): void
    {
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    }
}
