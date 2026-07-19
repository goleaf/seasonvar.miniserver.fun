<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Enums\AccountRestrictionType;
use App\Enums\AdminMembershipStatus;
use App\Enums\AdminPermission;
use App\Enums\AdminRoleCode;
use App\Models\AccountRestriction;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\User;
use App\Services\Admin\AdminEligibleUserQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminEligibleUserQueryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function staff_recipients_and_assignees_use_effective_permissions_instead_of_email_only_checks(): void
    {
        config(['seasonvar.admin_emails' => ['legacy@example.com']]);
        $support = $this->assigned(AdminRoleCode::SupportAgent);
        $legacy = User::factory()->create(['email' => 'legacy@example.com', 'email_verified_at' => now()]);
        $moderator = $this->assigned(AdminRoleCode::Moderator);
        $suspended = $this->assigned(AdminRoleCode::SupportAgent, AdminMembershipStatus::Suspended);
        $restricted = $this->assigned(AdminRoleCode::SupportAgent);
        AccountRestriction::query()->create([
            'user_id' => $restricted->id,
            'type' => AccountRestrictionType::LoginSuspended,
            'reason_code' => 'security_review',
            'public_notice_key' => AccountRestrictionType::LoginSuspended->noticeKey(),
            'applied_by_id' => $support->id,
            'starts_at' => now(),
        ]);

        $ids = app(AdminEligibleUserQuery::class)
            ->forPermission(AdminPermission::TicketsSupport)
            ->pluck('users.id')
            ->all();

        self::assertContains($support->id, $ids);
        self::assertContains($legacy->id, $ids);
        self::assertNotContains($moderator->id, $ids);
        self::assertNotContains($suspended->id, $ids);
        self::assertNotContains($restricted->id, $ids);
    }

    private function assigned(AdminRoleCode $role, AdminMembershipStatus $status = AdminMembershipStatus::Active): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AdminUserRole::query()->create([
            'user_id' => $user->id,
            'admin_role_id' => AdminRole::query()->where('code', $role)->valueOrFail('id'),
            'status' => $status,
            'reason_code' => 'eligible_query_test',
            'assigned_at' => now(),
            'suspended_at' => $status === AdminMembershipStatus::Suspended ? now() : null,
        ]);

        return $user;
    }
}
