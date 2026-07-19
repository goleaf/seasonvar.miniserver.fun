<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Enums\AdminPermission;
use App\Enums\AdminRoleCode;
use App\Livewire\Administration\AdminAccessManagementPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminAccessManagementPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function access_page_shows_translated_stable_role_matrix_without_configuration_secrets(): void
    {
        config([
            'administration.bootstrap_superadministrator_emails' => ['root-admin@example.com'],
            'seasonvar.admin_emails' => ['legacy-secret@example.com'],
        ]);
        $admin = User::factory()->create([
            'email' => 'root-admin@example.com',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.access'))
            ->assertOk()
            ->assertSeeText(AdminRoleCode::Superadministrator->label())
            ->assertSeeText(AdminRoleCode::Moderator->label())
            ->assertSeeText(AdminPermission::RolesManage->label())
            ->assertSeeText(__('administration.access.superadministrator_policy'))
            ->assertDontSee('administration.roles.')
            ->assertDontSee('administration.permissions.')
            ->assertDontSee('legacy-secret@example.com')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    #[Test]
    public function access_page_requires_roles_view_permission(): void
    {
        config(['seasonvar.admin_emails' => ['legacy@example.com']]);
        $legacyAdmin = User::factory()->create(['email' => 'legacy@example.com', 'email_verified_at' => now()]);

        $this->actingAs($legacyAdmin)->get('/admin/access')->assertForbidden();
    }

    #[Test]
    public function sensitive_action_failure_is_rendered_as_a_safe_translated_error_state(): void
    {
        config(['administration.bootstrap_superadministrator_emails' => ['root-admin@example.com']]);
        $administrator = User::factory()->create(['email' => 'root-admin@example.com', 'email_verified_at' => now()]);
        $target = User::factory()->create(['email_verified_at' => now()]);

        Livewire::actingAs($administrator)
            ->test(AdminAccessManagementPage::class)
            ->set('userPublicId', $target->public_id)
            ->set('roleCode', AdminRoleCode::Moderator->value)
            ->set('reasonCode', 'staffing_change')
            ->call('assignRole')
            ->assertHasErrors(['action'])
            ->assertSee(__('administration.errors.recent_authentication_required'));
    }
}
