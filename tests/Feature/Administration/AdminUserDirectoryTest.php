<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Enums\AdminMembershipStatus;
use App\Enums\AdminRoleCode;
use App\Models\AccountRestriction;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminUserDirectoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_directory_is_bounded_escaped_and_omits_authentication_and_private_restriction_data(): void
    {
        $admin = $this->administrator();
        $target = User::factory()->create([
            'name' => '<script>alert(1)</script>',
            'email' => 'sensitive-person@example.com',
            'password' => Hash::make('secret-password'),
            'remember_token' => 'remember-token-must-not-leak',
        ]);
        AccountRestriction::query()->create([
            'user_id' => $target->id,
            'type' => 'under_review',
            'reason_code' => 'manual_review',
            'public_notice_key' => 'administration.restrictions.notices.under_review',
            'private_note' => 'private-note-must-not-leak',
            'applied_by_id' => $admin->id,
            'starts_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.users', ['search' => $target->public_id]));

        $response
            ->assertOk()
            ->assertSee($target->public_id)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('s***@example.com')
            ->assertDontSee('sensitive-person@example.com')
            ->assertDontSee('secret-password')
            ->assertDontSee('remember-token-must-not-leak')
            ->assertDontSee('private-note-must-not-leak')
            ->assertSeeText(__('administration.restrictions.types.under_review'));
    }

    #[Test]
    public function user_directory_requires_users_view_and_uses_private_noindex_headers(): void
    {
        $contentEditor = $this->administrator(AdminRoleCode::ContentEditor);

        $this->actingAs($contentEditor)->get('/admin/users')->assertForbidden();

        $moderator = $this->administrator();
        $this->actingAs($moderator)
            ->get('/admin/users')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Pragma', 'no-cache');
    }

    private function administrator(AdminRoleCode $role = AdminRoleCode::Moderator): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AdminUserRole::query()->create([
            'user_id' => $user->id,
            'admin_role_id' => AdminRole::query()->where('code', $role)->valueOrFail('id'),
            'status' => AdminMembershipStatus::Active,
            'reason_code' => 'directory_test',
            'assigned_at' => now(),
        ]);

        return $user;
    }
}
