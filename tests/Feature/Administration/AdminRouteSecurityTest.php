<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Enums\AdminMembershipStatus;
use App\Enums\AdminRoleCode;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminRouteSecurityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function canonical_dashboard_requires_authentication_verification_and_administrator_access(): void
    {
        $ordinary = User::factory()->create();
        $unverified = User::factory()->unverified()->create(['email' => 'legacy@example.com']);
        config(['seasonvar.admin_emails' => ['legacy@example.com']]);

        $this->get(route('admin.index'))->assertRedirectToRoute('login');
        $this->actingAs($ordinary)->get(route('admin.index'))->assertForbidden();
        $this->actingAs($unverified)->get(route('admin.index'))->assertRedirectToRoute('verification.notice');
    }

    #[Test]
    public function active_member_receives_private_no_store_noindex_dashboard(): void
    {
        $admin = User::factory()->create();
        $this->assign($admin, AdminRoleCode::Moderator);

        $response = $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSeeLivewire('administration.administration-dashboard-page')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('max-age=0', $cacheControl);
    }

    #[Test]
    public function suspended_membership_denies_admin_even_for_legacy_allowlisted_identity(): void
    {
        config(['seasonvar.admin_emails' => ['suspended@example.com']]);
        $admin = User::factory()->create(['email' => 'suspended@example.com']);
        $this->assign($admin, AdminRoleCode::Moderator, AdminMembershipStatus::Suspended);

        $this->actingAs($admin)->get(route('admin.index'))->assertForbidden();
    }

    #[Test]
    public function all_admin_routes_share_the_canonical_security_boundary_and_existing_names_remain(): void
    {
        $expectedExisting = [
            'admin.calendar',
            'admin.catalog',
            'admin.comments',
            'admin.help',
            'admin.help.preview',
            'admin.imports',
            'admin.issues',
            'admin.premium',
            'admin.profiles',
            'admin.requests',
            'admin.reviews',
            'admin.tags',
        ];

        $newRoutes = ['admin.index', 'admin.users', 'admin.access', 'admin.audit', 'admin.operations'];

        foreach ([...$newRoutes, ...$expectedExisting] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            self::assertNotNull($route, $routeName);
            self::assertContains('auth', $route->gatherMiddleware(), $routeName);
            self::assertContains('auth.session', $route->gatherMiddleware(), $routeName);
            self::assertContains('verified', $route->gatherMiddleware(), $routeName);
            self::assertContains('account.private', $route->gatherMiddleware(), $routeName);
            self::assertContains('account.active', $route->gatherMiddleware(), $routeName);
            self::assertContains('admin.access', $route->gatherMiddleware(), $routeName);
            self::assertFalse(in_array('GET', $route->methods(), true) && str_contains($route->uri(), 'delete'));
        }
    }

    private function assign(
        User $user,
        AdminRoleCode $roleCode,
        AdminMembershipStatus $status = AdminMembershipStatus::Active,
    ): void {
        $role = AdminRole::query()->where('code', $roleCode)->sole();

        AdminUserRole::query()->create([
            'user_id' => $user->id,
            'admin_role_id' => $role->id,
            'status' => $status,
            'reason_code' => 'route_security_test',
            'assigned_at' => now(),
            'suspended_at' => $status === AdminMembershipStatus::Suspended ? now() : null,
        ]);
    }
}
