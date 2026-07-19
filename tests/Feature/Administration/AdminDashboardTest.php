<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Enums\AdminMembershipStatus;
use App\Enums\AdminRoleCode;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Admin\AdministrationDashboardQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dashboard_shows_real_permission_scoped_catalog_counts_without_operations_data(): void
    {
        CatalogTitle::factory()->create(['is_published' => true]);
        CatalogTitle::factory()->create(['is_published' => false]);
        $admin = User::factory()->create();
        $this->assign($admin, AdminRoleCode::ContentManager);

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSeeText(__('administration.dashboard.sections.catalog'))
            ->assertSeeText(__('administration.dashboard.metrics.titles_total'))
            ->assertSee('data-dashboard-metric-value="2"', false)
            ->assertSeeText(__('administration.dashboard.metrics.titles_unpublished'))
            ->assertSee('data-dashboard-metric-value="1"', false)
            ->assertDontSee('dashboard-section-operations', false)
            ->assertDontSeeText(__('administration.dashboard.metrics.failed_jobs'));
    }

    #[Test]
    public function operations_summary_uses_real_failed_job_count_and_omits_catalog_for_system_operator(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => fake()->uuid(),
            'connection' => 'sync',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'redacted in dashboard',
            'failed_at' => now(),
        ]);
        $admin = User::factory()->create();
        $this->assign($admin, AdminRoleCode::SystemOperator);

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSeeText(__('administration.dashboard.sections.operations'))
            ->assertSeeText(__('administration.dashboard.metrics.failed_jobs'))
            ->assertSee('data-dashboard-metric-value="1"', false)
            ->assertDontSee('redacted in dashboard')
            ->assertDontSee('dashboard-section-catalog', false);
    }

    #[Test]
    public function unavailable_optional_operations_schema_does_not_take_down_dashboard(): void
    {
        $admin = User::factory()->create();
        $this->assign($admin, AdminRoleCode::SystemOperator);
        Schema::drop('failed_jobs');

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSeeText(__('administration.dashboard.states.unavailable'))
            ->assertSeeText(__('administration.dashboard.title'));
    }

    #[Test]
    public function each_dashboard_domain_is_loaded_with_one_grouped_aggregate_query(): void
    {
        $admin = User::factory()->create();
        $this->assign($admin, AdminRoleCode::ContentManager);
        $query = app(AdministrationDashboardQuery::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $sections = $query->for($admin);
        $selects = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_starts_with(strtolower(ltrim($sql)), 'select'))
            ->values();
        DB::disableQueryLog();

        self::assertCount(1, $sections);
        self::assertSame('catalog', $sections[0]->code);
        self::assertSame(1, $selects->filter(fn (string $sql): bool => str_contains($sql, 'titles_total'))->count());
    }

    private function assign(User $user, AdminRoleCode $roleCode): void
    {
        AdminUserRole::query()->create([
            'user_id' => $user->id,
            'admin_role_id' => AdminRole::query()->where('code', $roleCode)->valueOrFail('id'),
            'status' => AdminMembershipStatus::Active,
            'reason_code' => 'dashboard_test',
            'assigned_at' => now(),
        ]);
    }
}
