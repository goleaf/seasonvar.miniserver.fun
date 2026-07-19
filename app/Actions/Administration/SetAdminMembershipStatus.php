<?php

declare(strict_types=1);

namespace App\Actions\Administration;

use App\Enums\AdminAuditAction;
use App\Enums\AdminMembershipStatus;
use App\Enums\AdminPermission;
use App\Enums\AdminRoleCode;
use App\Exceptions\AdministrationAccessException;
use App\Models\AdminUserRole;
use App\Models\User;
use App\Services\Admin\AdminAccessResolver;
use App\Services\Admin\AdminAuditRecorder;
use App\Services\Admin\AdminRecentAuthentication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class SetAdminMembershipStatus
{
    public function __construct(
        private AdminAccessResolver $access,
        private AdminAuditRecorder $audit,
        private AdminRecentAuthentication $recentAuthentication,
    ) {}

    public function handle(
        User $actor,
        AdminUserRole $membership,
        AdminMembershipStatus $status,
        string $reasonCode,
        bool $confirmed,
    ): AdminUserRole {
        Gate::forUser($actor)->authorize(AdminPermission::RolesManage->value);
        $this->recentAuthentication->ensure();

        if (! $confirmed) {
            throw new AdministrationAccessException('administration.errors.explicit_confirmation_required');
        }

        if (! in_array($status, [AdminMembershipStatus::Active, AdminMembershipStatus::Suspended], true)) {
            throw new AdministrationAccessException('administration.errors.invalid_membership_status');
        }

        if (preg_match('/^[a-z][a-z0-9_.-]{2,63}$/D', $reasonCode) !== 1) {
            throw new AdministrationAccessException('administration.errors.invalid_reason_code');
        }

        $updated = DB::transaction(function () use ($actor, $membership, $status, $reasonCode): AdminUserRole {
            $locked = AdminUserRole::query()->with('role:id,code,is_active')->lockForUpdate()->findOrFail($membership->id);

            if ($locked->status === AdminMembershipStatus::Revoked) {
                throw new AdministrationAccessException('administration.errors.revoked_membership_immutable');
            }

            if ($locked->status === $status) {
                return $locked;
            }

            if ($status === AdminMembershipStatus::Suspended && $locked->role->code === AdminRoleCode::Superadministrator) {
                $activeCount = AdminUserRole::query()
                    ->where('admin_role_id', $locked->admin_role_id)
                    ->where('status', AdminMembershipStatus::Active->value)
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->lockForUpdate()
                    ->count();

                if ($activeCount <= 1) {
                    throw new AdministrationAccessException('administration.errors.final_superadministrator');
                }
            }

            $before = $this->fingerprint($locked);
            $locked->forceFill([
                'status' => $status,
                'reason_code' => $reasonCode,
                'suspended_at' => $status === AdminMembershipStatus::Suspended ? now() : null,
            ])->save();

            $this->audit->record(
                $actor,
                $status === AdminMembershipStatus::Suspended
                    ? AdminAuditAction::AdministratorSuspended
                    : AdminAuditAction::AdministratorRestored,
                $locked,
                $before,
                $this->fingerprint($locked),
                ['role_code', 'membership_status', 'reason_code'],
            );

            return $locked;
        }, 3);

        $this->access->forget((int) $updated->user_id);

        return $updated;
    }

    private function fingerprint(AdminUserRole $membership): string
    {
        return hash('sha256', json_encode([
            'role' => $membership->role->code->value,
            'status' => $membership->status->value,
            'assigned_at' => $membership->assigned_at?->toAtomString(),
            'expires_at' => $membership->expires_at?->toAtomString(),
            'suspended_at' => $membership->suspended_at?->toAtomString(),
            'revoked_at' => $membership->revoked_at?->toAtomString(),
        ], JSON_THROW_ON_ERROR));
    }
}
