<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Enums\AdminAuditAction;
use App\Enums\AdminMembershipStatus;
use App\Enums\AdminRoleCode;
use App\Models\AdminAuditEvent;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\User;
use App\Services\Admin\AdminAuditQuery;
use App\Services\Admin\AdminNavigationQuery;
use App\Services\Admin\AdminUserQuery;
use App\Support\Administration\AdminTableState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_directory_query_count_stays_bounded_as_rows_grow(): void
    {
        User::factory()->count(100)->create();
        $state = AdminTableState::from([], ['registered' => 'created_at'], 'registered', []);

        $count = $this->countQueries(fn () => app(AdminUserQuery::class)->paginate($state));

        self::assertLessThanOrEqual(6, $count);
    }

    #[Test]
    public function navigation_does_not_query_once_per_registered_item(): void
    {
        $administrator = $this->administrator(AdminRoleCode::PortalAdministrator);
        $this->app['request']->setUserResolver(fn (): User => $administrator);

        $count = $this->countQueries(fn () => app(AdminNavigationQuery::class)->for($administrator));

        self::assertLessThanOrEqual(6, $count);
    }

    #[Test]
    public function audit_page_uses_bounded_pagination_and_one_actor_eager_load(): void
    {
        $administrator = $this->administrator(AdminRoleCode::ReadOnlyAuditor);

        foreach (range(1, 80) as $index) {
            AdminAuditEvent::query()->create([
                'actor_id' => $administrator->id,
                'action' => AdminAuditAction::TitleUpdated,
                'resource_type' => 'catalog_title',
                'resource_id' => $index,
                'before_version' => hash('sha256', 'before:'.$index),
                'after_version' => hash('sha256', 'after:'.$index),
                'changed_fields' => ['title'],
                'occurred_at' => now()->subSecond($index),
            ]);
        }

        $state = AdminTableState::from([], ['occurred' => 'occurred_at'], 'occurred', []);
        $count = $this->countQueries(fn () => app(AdminAuditQuery::class)->paginate($state));

        self::assertLessThanOrEqual(3, $count);
    }

    private function administrator(AdminRoleCode $roleCode): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        AdminUserRole::query()->create([
            'user_id' => $user->id,
            'admin_role_id' => AdminRole::query()->where('code', $roleCode)->valueOrFail('id'),
            'status' => AdminMembershipStatus::Active,
            'reason_code' => 'query_budget_test',
            'assigned_at' => now(),
        ]);

        return $user;
    }

    private function countQueries(callable $operation): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $operation();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
