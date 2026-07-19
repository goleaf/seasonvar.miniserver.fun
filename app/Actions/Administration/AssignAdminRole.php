<?php

declare(strict_types=1);

namespace App\Actions\Administration;

use App\Enums\AdminAuditAction;
use App\Enums\AdminMembershipStatus;
use App\Enums\AdminPermission;
use App\Enums\AdminRoleCode;
use App\Exceptions\AdministrationAccessException;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\User;
use App\Services\Admin\AdminAccessRegistry;
use App\Services\Admin\AdminAccessResolver;
use App\Services\Admin\AdminAuditRecorder;
use App\Services\Admin\AdminRecentAuthentication;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class AssignAdminRole
{
    public function __construct(
        private AdminAccessRegistry $registry,
        private AdminAccessResolver $access,
        private AdminAuditRecorder $audit,
        private AdminRecentAuthentication $recentAuthentication,
    ) {}

    public function handle(
        User $actor,
        User $target,
        AdminRoleCode $roleCode,
        string $reasonCode,
        ?CarbonInterface $expiresAt = null,
    ): AdminUserRole {
        Gate::forUser($actor)->authorize(AdminPermission::RolesManage->value);
        $this->recentAuthentication->ensure();
        $this->validateReason($reasonCode);

        if (! $target->hasVerifiedEmail()) {
            throw new AdministrationAccessException('administration.errors.verified_administrator_required');
        }

        $actorPermissions = $this->access->permissionsFor($actor);

        foreach ($this->registry->permissionsFor($roleCode) as $permission) {
            if (! isset($actorPermissions[$permission->value])) {
                Gate::forUser($actor)->authorize($permission->value);
            }
        }

        $created = false;
        $membership = DB::transaction(function () use (
            $actor,
            $target,
            $roleCode,
            $reasonCode,
            $expiresAt,
            &$created,
        ): AdminUserRole {
            $role = AdminRole::query()
                ->where('code', $roleCode->value)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $role->is_active) {
                throw new AdministrationAccessException('administration.errors.inactive_role');
            }

            $membership = AdminUserRole::query()
                ->where('user_id', $target->id)
                ->where('admin_role_id', $role->id)
                ->lockForUpdate()
                ->first();

            $sameExpiry = ($membership?->expires_at === null && $expiresAt === null)
                || ($membership?->expires_at !== null && $expiresAt !== null && $membership->expires_at->equalTo($expiresAt));

            if ($membership?->status === AdminMembershipStatus::Active && $sameExpiry) {
                return $membership;
            }

            $beforeVersion = $membership instanceof AdminUserRole
                ? $this->fingerprint($membership)
                : AdminAuditRecorder::ABSENT_VERSION;

            if (! $membership instanceof AdminUserRole) {
                $membership = new AdminUserRole([
                    'user_id' => $target->id,
                    'admin_role_id' => $role->id,
                ]);
                $created = true;
            }

            $membership->forceFill([
                'status' => AdminMembershipStatus::Active,
                'assigned_by_id' => $actor->id,
                'revoked_by_id' => null,
                'reason_code' => $reasonCode,
                'assigned_at' => now(),
                'expires_at' => $expiresAt,
                'suspended_at' => null,
                'revoked_at' => null,
            ])->save();
            $membership->setRelation('role', $role);

            $this->audit->record(
                $actor,
                AdminAuditAction::AdministratorRoleAssigned,
                $membership,
                $beforeVersion,
                $this->fingerprint($membership),
                ['role_code', 'membership_status', 'assigned_at', 'expires_at', 'reason_code'],
            );

            return $membership;
        }, 3);

        if ($created || $membership->wasChanged()) {
            $this->access->forget($target);
        }

        return $membership;
    }

    private function validateReason(string $reasonCode): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{2,63}$/D', $reasonCode) !== 1) {
            throw new AdministrationAccessException('administration.errors.invalid_reason_code');
        }
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
