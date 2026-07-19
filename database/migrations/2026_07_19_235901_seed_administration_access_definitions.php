<?php

declare(strict_types=1);

use App\Enums\AdminPermission;
use App\Enums\AdminRoleCode;
use App\Services\Admin\AdminAccessRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $registry = new AdminAccessRegistry;

        DB::transaction(function () use ($now, $registry): void {
            DB::table('admin_roles')->insertOrIgnore(array_map(
                static fn (AdminRoleCode $role): array => [
                    'code' => $role->value,
                    'is_active' => true,
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                AdminRoleCode::cases(),
            ));

            DB::table('admin_permissions')->insertOrIgnore(array_map(
                static fn (AdminPermission $permission): array => [
                    'code' => $permission->value,
                    'sensitivity' => $permission->sensitivity()->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                AdminPermission::cases(),
            ));

            $roleIds = DB::table('admin_roles')->pluck('id', 'code');
            $permissionIds = DB::table('admin_permissions')->pluck('id', 'code');
            $rows = [];

            foreach (AdminRoleCode::cases() as $role) {
                foreach ($registry->permissionsFor($role) as $permission) {
                    $rows[] = [
                        'admin_role_id' => $roleIds->get($role->value),
                        'admin_permission_id' => $permissionIds->get($permission->value),
                        'created_at' => $now,
                    ];
                }
            }

            DB::table('admin_role_permissions')->insertOrIgnore($rows);
        });
    }

    public function down(): void
    {
        DB::table('admin_role_permissions')->delete();
        DB::table('admin_permissions')->delete();
        DB::table('admin_roles')->delete();
    }
};
