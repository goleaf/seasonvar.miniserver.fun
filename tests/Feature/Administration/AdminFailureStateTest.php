<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Enums\AdminMembershipStatus;
use App\Enums\AdminRoleCode;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminFailureStateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_directory_isolates_query_failure_without_exposing_database_details(): void
    {
        $administrator = $this->administrator(AdminRoleCode::Moderator);
        Schema::drop('account_restrictions');

        $this->actingAs($administrator)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSeeText(__('administration.shared.query_failed'))
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('account_restrictions');
    }

    #[Test]
    public function audit_viewer_isolates_query_failure_without_exposing_database_details(): void
    {
        $administrator = $this->administrator(AdminRoleCode::ReadOnlyAuditor);
        Schema::drop('admin_audit_events');

        $this->actingAs($administrator)
            ->get(route('admin.audit'))
            ->assertOk()
            ->assertSeeText(__('administration.shared.query_failed'))
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('admin_audit_events');
    }

    private function administrator(AdminRoleCode $roleCode): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AdminUserRole::query()->create([
            'user_id' => $user->id,
            'admin_role_id' => AdminRole::query()->where('code', $roleCode)->valueOrFail('id'),
            'status' => AdminMembershipStatus::Active,
            'reason_code' => 'failure_state_test',
            'assigned_at' => now(),
        ]);

        return $user;
    }
}
