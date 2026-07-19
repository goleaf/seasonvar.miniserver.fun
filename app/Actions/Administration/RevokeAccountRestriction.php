<?php

declare(strict_types=1);

namespace App\Actions\Administration;

use App\Enums\AdminAuditAction;
use App\Enums\AdminPermission;
use App\Exceptions\AdministrationAccessException;
use App\Models\AccountRestriction;
use App\Models\User;
use App\Notifications\AccountRestrictionNotification;
use App\Services\Admin\AdminAuditRecorder;
use App\Services\Auth\AccountAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class RevokeAccountRestriction
{
    public function __construct(
        private AccountAccessResolver $accountAccess,
        private AdminAuditRecorder $audit,
    ) {}

    public function handle(User $actor, AccountRestriction $restriction, string $reasonCode, bool $confirmed): AccountRestriction
    {
        Gate::forUser($actor)->authorize(AdminPermission::UsersRestrict->value);

        if (! $confirmed) {
            throw new AdministrationAccessException('administration.errors.explicit_confirmation_required');
        }

        if (preg_match('/^[a-z][a-z0-9_.-]{2,63}$/D', $reasonCode) !== 1) {
            throw new AdministrationAccessException('administration.errors.invalid_reason_code');
        }

        $changed = false;
        $restriction = DB::transaction(function () use ($actor, $restriction, $reasonCode, &$changed): AccountRestriction {
            $locked = AccountRestriction::query()->lockForUpdate()->findOrFail($restriction->id);

            if ($locked->revoked_at !== null) {
                return $locked;
            }

            $before = $this->fingerprint($locked);
            $locked->forceFill([
                'revoked_by_id' => $actor->id,
                'revoked_at' => now(),
                'revocation_reason_code' => $reasonCode,
            ])->save();
            $changed = true;

            $this->audit->record(
                $actor,
                AdminAuditAction::AccountRestrictionRevoked,
                $locked,
                $before,
                $this->fingerprint($locked),
                ['revoked_at', 'reason_code'],
            );

            return $locked;
        }, 3);

        $this->accountAccess->forget($restriction->user_id);

        if ($changed) {
            $restriction->user?->notify((new AccountRestrictionNotification(
                'revoked',
                $restriction->public_id,
                'administration.restrictions.notices.restored',
                null,
            ))->afterCommit());
        }

        return $restriction;
    }

    private function fingerprint(AccountRestriction $restriction): string
    {
        return hash('sha256', json_encode([
            'type' => $restriction->type->value,
            'reason' => $restriction->reason_code,
            'starts_at' => $restriction->starts_at?->toAtomString(),
            'expires_at' => $restriction->expires_at?->toAtomString(),
            'revoked_at' => $restriction->revoked_at?->toAtomString(),
        ], JSON_THROW_ON_ERROR));
    }
}
