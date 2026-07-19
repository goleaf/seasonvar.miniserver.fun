<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\DTOs\Administration\AdminMembershipData;
use App\DTOs\Administration\AdminPermissionData;
use App\DTOs\Administration\AdminRoleData;
use App\Enums\AdminMembershipStatus;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class AdminAccessManagementQuery
{
    /** @return list<AdminRoleData> */
    public function roles(): array
    {
        return AdminRole::query()
            ->select(['id', 'code', 'is_active'])
            ->with(['permissions' => fn ($query) => $query->select(['admin_permissions.id', 'code', 'sensitivity'])->orderBy('code')])
            ->orderBy('code')
            ->get()
            ->map(fn (AdminRole $role): AdminRoleData => new AdminRoleData(
                code: $role->code->value,
                label: $role->code->label(),
                active: $role->is_active,
                permissions: $role->permissions->map(fn ($permission): AdminPermissionData => new AdminPermissionData(
                    code: $permission->code->value,
                    label: $permission->code->label(),
                    sensitivity: $permission->sensitivity->value,
                    sensitivityLabel: __("administration.access.sensitivity.{$permission->sensitivity->value}"),
                ))->all(),
            ))
            ->all();
    }

    /** @return LengthAwarePaginator<int, AdminMembershipData> */
    public function memberships(int $page = 1): LengthAwarePaginator
    {
        return AdminUserRole::query()
            ->select(['id', 'public_id', 'user_id', 'admin_role_id', 'status', 'assigned_at', 'expires_at'])
            ->whereIn('status', [AdminMembershipStatus::Active->value, AdminMembershipStatus::Suspended->value])
            ->with(['user:id,public_id,name,email', 'role:id,code,is_active'])
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->paginate(25, pageName: 'membershipsPage', page: max(1, $page))
            ->through(fn (AdminUserRole $membership): AdminMembershipData => new AdminMembershipData(
                publicId: $membership->public_id,
                userPublicId: (string) $membership->user->public_id,
                userName: (string) $membership->user->name,
                maskedEmail: $this->maskEmail((string) $membership->user->email),
                roleCode: $membership->role->code->value,
                roleLabel: $membership->role->code->label(),
                status: $membership->status->value,
                statusLabel: __("administration.access.membership_status.{$membership->status->value}"),
                assignedAtLabel: $membership->assigned_at?->translatedFormat('d.m.Y H:i') ?? '—',
                expiresAtLabel: $membership->expires_at?->translatedFormat('d.m.Y H:i') ?? __('administration.access.no_expiry'),
            ));
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***'.($domain !== '' ? '@'.$domain : '');
    }
}
