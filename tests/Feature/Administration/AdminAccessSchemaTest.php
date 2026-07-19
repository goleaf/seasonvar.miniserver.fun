<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Enums\AdminMembershipStatus;
use App\Enums\AdminPermission;
use App\Enums\AdminRoleCode;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminAccessSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function access_schema_contains_stable_reference_data_and_no_automatic_user_assignment(): void
    {
        self::assertTrue(Schema::hasTable('admin_roles'));
        self::assertTrue(Schema::hasTable('admin_permissions'));
        self::assertTrue(Schema::hasTable('admin_role_permissions'));
        self::assertTrue(Schema::hasTable('admin_user_roles'));

        self::assertSame(
            collect(AdminRoleCode::cases())->pluck('value')->sort()->values()->all(),
            DB::table('admin_roles')->pluck('code')->sort()->values()->all(),
        );
        self::assertSame(
            collect(AdminPermission::cases())->pluck('value')->sort()->values()->all(),
            DB::table('admin_permissions')->pluck('code')->sort()->values()->all(),
        );
        self::assertSame(0, DB::table('admin_user_roles')->count());
    }

    #[Test]
    public function membership_constraints_prevent_duplicate_assignments_and_cascade_deleted_users(): void
    {
        $user = User::factory()->create();
        $roleId = DB::table('admin_roles')->where('code', AdminRoleCode::Moderator->value)->value('id');

        DB::table('admin_user_roles')->insert([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'admin_role_id' => $roleId,
            'status' => AdminMembershipStatus::Active->value,
            'reason_code' => 'initial_assignment',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        try {
            DB::table('admin_user_roles')->insert([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'admin_role_id' => $roleId,
                'status' => AdminMembershipStatus::Active->value,
                'reason_code' => 'duplicate_assignment',
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            $user->delete();
            self::assertSame(0, DB::table('admin_user_roles')->count());
        }
    }
}
