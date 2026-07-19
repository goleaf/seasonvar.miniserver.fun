<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AdminPermission;
use App\Enums\AdminPermissionSensitivity;
use App\Enums\AdminRoleCode;
use App\Services\Admin\AdminAccessRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdminAccessRegistryTest extends TestCase
{
    #[Test]
    public function stable_role_and_permission_codes_are_unique(): void
    {
        $roleCodes = array_map(static fn (AdminRoleCode $role): string => $role->value, AdminRoleCode::cases());
        $permissionCodes = array_map(static fn (AdminPermission $permission): string => $permission->value, AdminPermission::cases());

        self::assertSame($roleCodes, array_values(array_unique($roleCodes)));
        self::assertSame($permissionCodes, array_values(array_unique($permissionCodes)));
        self::assertNotEmpty($roleCodes);
        self::assertNotEmpty($permissionCodes);
    }

    #[Test]
    public function every_role_has_administration_access_and_only_known_permissions(): void
    {
        $registry = new AdminAccessRegistry;
        $known = array_map(static fn (AdminPermission $permission): string => $permission->value, AdminPermission::cases());

        foreach (AdminRoleCode::cases() as $role) {
            $permissions = $registry->permissionsFor($role);

            self::assertContains(AdminPermission::AdministrationAccess, $permissions, $role->value);
            self::assertSame(
                [],
                array_values(array_diff(
                    array_map(static fn (AdminPermission $permission): string => $permission->value, $permissions),
                    $known,
                )),
                $role->value,
            );
        }
    }

    #[Test]
    public function superadministrator_does_not_silently_bypass_explicit_billing_or_legal_permissions(): void
    {
        $permissions = (new AdminAccessRegistry)->permissionsFor(AdminRoleCode::Superadministrator);

        self::assertNotContains(AdminPermission::BillingRefund, $permissions);
        self::assertNotContains(AdminPermission::BillingReconcile, $permissions);
        self::assertNotContains(AdminPermission::RightsIdentityDocuments, $permissions);
        self::assertNotContains(AdminPermission::RightsAuthorityDocuments, $permissions);
        self::assertSame(AdminPermissionSensitivity::HighlySensitive, AdminPermission::BillingRefund->sensitivity());
        self::assertSame(AdminPermissionSensitivity::HighlySensitive, AdminPermission::RightsIdentityDocuments->sensitivity());
    }

    #[Test]
    public function legacy_gate_mapping_is_explicit_and_complete(): void
    {
        $mapping = (new AdminAccessRegistry)->legacyGatePermissions();

        self::assertSame(AdminPermission::ImportsExecute, $mapping['manage-seasonvar-imports']);
        self::assertSame(AdminPermission::ContentManage, $mapping['manage-catalog']);
        self::assertSame(AdminPermission::CommentsModerate, $mapping['manage-comments']);
        self::assertSame(AdminPermission::ReviewsModerate, $mapping['manage-reviews']);
        self::assertSame(AdminPermission::RequestsModerate, $mapping['manage-content-requests']);
        self::assertSame(AdminPermission::TicketsSupport, $mapping['manage-technical-issues']);
        self::assertSame(AdminPermission::CalendarManage, $mapping['manage-release-calendar']);
        self::assertSame(AdminPermission::HelpManage, $mapping['manage-help-center']);
        self::assertSame(AdminPermission::PremiumView, $mapping['view-premium-administration']);
        self::assertSame(AdminPermission::PremiumGrant, $mapping['manage-premium-grants']);
        self::assertSame(AdminPermission::PremiumPromotions, $mapping['manage-premium-promotions']);
        self::assertSame(AdminPermission::BillingView, $mapping['view-premium-billing-audit']);
        self::assertSame(AdminPermission::BillingReconcile, $mapping['reconcile-premium']);
    }
}
