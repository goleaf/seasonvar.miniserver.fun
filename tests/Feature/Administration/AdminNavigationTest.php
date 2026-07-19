<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Enums\AdminMembershipStatus;
use App\Enums\AdminRoleCode;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function public_shell_exposes_one_administration_entry_instead_of_duplicate_staff_destinations(): void
    {
        config(['seasonvar.admin_emails' => ['legacy@example.com']]);
        $admin = User::factory()->create(['email' => 'legacy@example.com']);

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('admin.index').'"', false)
            ->assertDontSee('href="'.route('admin.imports').'"', false)
            ->assertDontSee('href="'.route('admin.catalog').'"', false);
    }

    #[Test]
    public function administration_navigation_is_server_side_permission_filtered_and_marks_current_route(): void
    {
        $moderator = User::factory()->create();
        $this->assign($moderator, AdminRoleCode::Moderator);

        $this->actingAs($moderator)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('data-administration-navigation', false)
            ->assertSee('href="'.route('admin.comments').'"', false)
            ->assertSee('href="'.route('admin.reviews').'"', false)
            ->assertSee('href="'.route('admin.profiles').'"', false)
            ->assertSee('href="'.route('admin.catalog').'"', false)
            ->assertDontSee('href="'.route('admin.imports').'"', false)
            ->assertDontSee('href="'.route('admin.premium').'"', false)
            ->assertSee('aria-current="page"', false);
    }

    #[Test]
    public function navigation_contains_only_real_registered_destinations_and_no_absent_domains(): void
    {
        config(['seasonvar.admin_emails' => ['legacy@example.com']]);
        $admin = User::factory()->create(['email' => 'legacy@example.com']);

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSeeText(__('administration.navigation.groups.overview'))
            ->assertSeeText(__('administration.navigation.groups.content'))
            ->assertSeeText(__('administration.navigation.groups.community'))
            ->assertDontSeeText(__('administration.navigation.advertisers'))
            ->assertDontSeeText(__('administration.navigation.rights_holders'));
    }

    private function assign(User $user, AdminRoleCode $roleCode): void
    {
        AdminUserRole::query()->create([
            'user_id' => $user->id,
            'admin_role_id' => AdminRole::query()->where('code', $roleCode)->valueOrFail('id'),
            'status' => AdminMembershipStatus::Active,
            'reason_code' => 'navigation_test',
            'assigned_at' => now(),
        ]);
    }
}
