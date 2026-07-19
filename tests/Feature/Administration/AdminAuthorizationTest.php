<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Enums\AdminMembershipStatus;
use App\Enums\AdminPermission;
use App\Enums\AdminRoleCode;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\User;
use App\Services\Admin\AdminAccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function active_membership_grants_only_role_permissions_through_stable_and_legacy_gates(): void
    {
        $user = User::factory()->create();
        $this->assign($user, AdminRoleCode::Moderator);

        self::assertTrue(Gate::forUser($user)->allows(AdminPermission::CommentsModerate->value));
        self::assertTrue(Gate::forUser($user)->allows('manage-comments'));
        self::assertFalse(Gate::forUser($user)->allows(AdminPermission::ContentManage->value));
        self::assertFalse(Gate::forUser($user)->allows('manage-catalog'));
        self::assertFalse(Gate::forUser($user)->allows(AdminPermission::BillingView->value));
    }

    #[Test]
    public function suspended_revoked_expired_memberships_and_inactive_roles_grant_nothing(): void
    {
        foreach ([AdminMembershipStatus::Suspended, AdminMembershipStatus::Revoked] as $status) {
            $user = User::factory()->create();
            $this->assign($user, AdminRoleCode::Moderator, $status);

            self::assertFalse(Gate::forUser($user)->allows(AdminPermission::AdministrationAccess->value), $status->value);
        }

        $expired = User::factory()->create();
        $this->assign($expired, AdminRoleCode::Moderator, expiresAt: now()->subSecond());
        self::assertFalse(Gate::forUser($expired)->allows(AdminPermission::AdministrationAccess->value));

        $inactive = User::factory()->create();
        $membership = $this->assign($inactive, AdminRoleCode::Moderator);
        $membership->role()->update(['is_active' => false]);
        self::assertFalse(Gate::forUser($inactive)->allows(AdminPermission::AdministrationAccess->value));
    }

    #[Test]
    public function legacy_catalog_allowlist_preserves_old_capabilities_without_new_sensitive_access(): void
    {
        config([
            'seasonvar.admin_emails' => ['legacy@example.com'],
            'premium.administration.grant_emails' => [],
            'premium.administration.billing_audit_emails' => [],
            'premium.administration.reconciliation_emails' => [],
        ]);
        $legacy = User::factory()->create(['email' => 'Legacy@Example.com']);

        self::assertTrue(Gate::forUser($legacy)->allows('manage-catalog'));
        self::assertTrue(Gate::forUser($legacy)->allows('manage-technical-issues'));
        self::assertTrue(Gate::forUser($legacy)->allows(AdminPermission::AdministrationAccess->value));
        self::assertFalse(Gate::forUser($legacy)->allows(AdminPermission::RolesManage->value));
        self::assertFalse(Gate::forUser($legacy)->allows('manage-premium-grants'));
        self::assertFalse(Gate::forUser($legacy)->allows('view-premium-billing-audit'));
        self::assertFalse(Gate::forUser($legacy)->allows('reconcile-premium'));
        self::assertFalse(Gate::forUser($legacy)->allows(AdminPermission::RightsIdentityDocuments->value));
    }

    #[Test]
    public function each_legacy_premium_allowlist_grants_only_its_exact_capability(): void
    {
        config([
            'seasonvar.admin_emails' => ['premium@example.com'],
            'premium.administration.grant_emails' => ['premium@example.com'],
            'premium.administration.promotion_emails' => [],
            'premium.administration.billing_audit_emails' => [],
            'premium.administration.reconciliation_emails' => [],
        ]);
        $user = User::factory()->create(['email' => 'premium@example.com']);

        self::assertTrue(Gate::forUser($user)->allows('manage-premium-grants'));
        self::assertFalse(Gate::forUser($user)->allows('manage-premium-promotions'));
        self::assertFalse(Gate::forUser($user)->allows('view-premium-billing-audit'));
        self::assertFalse(Gate::forUser($user)->allows('reconcile-premium'));
    }

    #[Test]
    public function permission_graph_is_loaded_once_per_resolver_scope(): void
    {
        $user = User::factory()->create();
        $this->assign($user, AdminRoleCode::Moderator);
        $resolver = app(AdminAccessResolver::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $resolver->permissionsFor($user);
        $afterFirst = count(DB::getQueryLog());
        $resolver->permissionsFor($user);
        $afterSecond = count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertGreaterThan(0, $afterFirst);
        self::assertSame($afterFirst, $afterSecond);
    }

    private function assign(
        User $user,
        AdminRoleCode $roleCode,
        AdminMembershipStatus $status = AdminMembershipStatus::Active,
        mixed $expiresAt = null,
    ): AdminUserRole {
        $role = AdminRole::query()->where('code', $roleCode)->sole();

        return AdminUserRole::query()->create([
            'user_id' => $user->id,
            'admin_role_id' => $role->id,
            'status' => $status,
            'reason_code' => 'test_assignment',
            'assigned_at' => now(),
            'expires_at' => $expiresAt,
            'suspended_at' => $status === AdminMembershipStatus::Suspended ? now() : null,
            'revoked_at' => $status === AdminMembershipStatus::Revoked ? now() : null,
        ]);
    }
}
