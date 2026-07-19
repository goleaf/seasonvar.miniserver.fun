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

final readonly class RevokeAdminRole
{
    public function __construct(
        private AdminAccessResolver $access,
        private AdminAuditRecorder $audit,
        private AdminRecentAuthentication $recentAuthentication,
    ) {}

    public function handle(
        User $actor,
        AdminUserRole $membership,
        string $reasonCode,
        bool $confirmed,
    ): AdminUserRole {
        Gate::forUser($actor)->authorize(AdminPermission::RolesManage->value);
        $this->recentAuthentication->ensure();

        if (! $confirmed) {
            throw new AdministrationAccessException('administration.errors.explicit_confirmation_required');
        }

        if (preg_match('/^[a-z][a-z0-9_.-]{2,63}$/D', $reasonCode) !== 1) {
            throw new AdministrationAccessException('administration.errors.invalid_reason_code');
        }

        $revoked = DB::transaction(function () use ($actor, $membership, $reasonCode): AdminUserRole {
            $locked = AdminUserRole::query()
                ->with('role:id,code,is_active')
                ->lockForUpdate()
                ->findOrFail($membership->id);

            if ($locked->status === AdminMembershipStatus::Revoked) {
                return $locked;
            }

            if ($locked->role->code === AdminRoleCode::Superadministrator) {
                $activeCount = AdminUserRole::query()
                    ->where('admin_role_id', $locked->admin_role_id)
                    ->where('status', AdminMembershipStatus::Active->value)
                    ->where(function ($query): void {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->lockForUpdate()
                    ->count();

                if ($activeCount <= 1) {
                    throw new AdministrationAccessException('administration.errors.final_superadministrator');
                }
            }

            $beforeVersion = $this->fingerprint($locked);
            $locked->forceFill([
                'status' => AdminMembershipStatus::Revoked,
                'reason_code' => $reasonCode,
                'revoked_by_id' => $actor->id,
                'revoked_at' => now(),
                'suspended_at' => null,
            ])->save();

            $this->audit->record(
                $actor,
                AdminAuditAction::AdministratorRoleRevoked,
                $locked,
                $beforeVersion,
                $this->fingerprint($locked),
                ['role_code', 'membership_status', 'revoked_at', 'reason_code'],
            );

            return $locked;
        }, 3);

        $this->access->forget((int) $revoked->user_id);

        return $revoked;
    }

    private function fingerprint(AdminUserRole $membership): string
    {
        return hash('sha256', json_encode([
            'role' => $membership->role->code->value,
            'status' => $membership->status->value,
            'assigned_at' => $membership->assigned_at?->toAtomString(),
            'expires_at' => $membership->expires_at?->toAtomString(),
            'revoked_at' => $membership->revoked_at?->toAtomString(),
        ], JSON_THROW_ON_ERROR));
    }
}
