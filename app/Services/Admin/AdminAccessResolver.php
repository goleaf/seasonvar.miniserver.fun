<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\AdminMembershipStatus;
use App\Enums\AdminPermission;
use App\Models\AdminUserRole;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

final class AdminAccessResolver
{
    /** @var array<int, array<string, AdminPermission>> */
    private array $resolved = [];

    public function __construct(private readonly AdminLegacyAccessMap $legacy) {}

    public function allows(User $user, AdminPermission $permission): bool
    {
        return isset($this->permissionsFor($user)[$permission->value]);
    }

    public function isAdministrator(User $user): bool
    {
        return $this->allows($user, AdminPermission::AdministrationAccess);
    }

    /** @return array<string, AdminPermission> */
    public function permissionsFor(User $user): array
    {
        $userId = (int) $user->getKey();

        if (isset($this->resolved[$userId])) {
            return $this->resolved[$userId];
        }

        $permissions = [];

        if (Schema::hasTable('admin_user_roles')) {
            if (AdminUserRole::query()
                ->where('user_id', $userId)
                ->where('status', AdminMembershipStatus::Suspended->value)
                ->exists()) {
                return $this->resolved[$userId] = [];
            }

            $memberships = AdminUserRole::query()
                ->select(['id', 'user_id', 'admin_role_id'])
                ->where('user_id', $userId)
                ->where('status', AdminMembershipStatus::Active->value)
                ->where(function ($query): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->whereHas('role', fn ($query) => $query->where('is_active', true))
                ->with(['role:id,code,is_active', 'role.permissions:id,code'])
                ->get();

            foreach ($memberships as $membership) {
                foreach ($membership->role->permissions as $permissionRecord) {
                    $permission = $permissionRecord->code;

                    if ($permission instanceof AdminPermission) {
                        $permissions[$permission->value] = $permission;
                    }
                }
            }
        }

        $email = mb_strtolower(trim($user->email));

        foreach ($this->legacy->permissionsForEmail($email) as $permission) {
            $permissions[$permission->value] = $permission;
        }

        return $this->resolved[$userId] = $permissions;
    }

    public function forget(User|int $user): void
    {
        unset($this->resolved[$user instanceof User ? (int) $user->getKey() : $user]);
    }
}
