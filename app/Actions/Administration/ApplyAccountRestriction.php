<?php

declare(strict_types=1);

namespace App\Actions\Administration;

use App\Enums\AccountRestrictionType;
use App\Enums\AdminAuditAction;
use App\Enums\AdminPermission;
use App\Exceptions\AdministrationAccessException;
use App\Models\AccountRestriction;
use App\Models\User;
use App\Notifications\AccountRestrictionNotification;
use App\Services\Admin\AdminAuditRecorder;
use App\Services\Auth\AccountAccessResolver;
use App\Support\UserPlainText;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final readonly class ApplyAccountRestriction
{
    public function __construct(
        private AccountAccessResolver $accountAccess,
        private AdminAuditRecorder $audit,
    ) {}

    public function handle(
        User $actor,
        User $target,
        AccountRestrictionType $type,
        string $reasonCode,
        ?CarbonInterface $expiresAt,
        string $publicNoticeKey,
        ?string $privateNote,
        bool $confirmed,
    ): AccountRestriction {
        Gate::forUser($actor)->authorize(AdminPermission::UsersRestrict->value);
        $this->validate($actor, $target, $type, $reasonCode, $expiresAt, $publicNoticeKey, $confirmed);
        $privateNote = $this->sanitizePrivateNote($privateNote);

        $restriction = DB::transaction(function () use ($actor, $target, $type, $reasonCode, $expiresAt, $publicNoticeKey, $privateNote): AccountRestriction {
            $lockedTarget = User::query()->lockForUpdate()->findOrFail($target->getKey());
            $existing = AccountRestriction::query()
                ->where('user_id', $lockedTarget->id)
                ->where('type', $type->value)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($existing instanceof AccountRestriction) {
                return $existing;
            }

            $restriction = AccountRestriction::query()->create([
                'user_id' => $lockedTarget->id,
                'type' => $type,
                'reason_code' => $reasonCode,
                'public_notice_key' => $publicNoticeKey,
                'private_note' => $privateNote,
                'applied_by_id' => $actor->id,
                'starts_at' => now(),
                'expires_at' => $expiresAt,
            ]);

            if ($type->blocksAuthentication()) {
                $lockedTarget->forceFill(['remember_token' => Str::random(60)])->save();
                $lockedTarget->tokens()->delete();

                if (Schema::hasTable((string) config('session.table', 'sessions'))) {
                    DB::table((string) config('session.table', 'sessions'))->where('user_id', $lockedTarget->id)->delete();
                }
            }

            $this->audit->record(
                $actor,
                AdminAuditAction::AccountRestrictionApplied,
                $restriction,
                AdminAuditRecorder::ABSENT_VERSION,
                $this->fingerprint($restriction),
                ['restriction_type', 'reason_code', 'starts_at', 'expires_at'],
            );

            return $restriction;
        }, 3);

        $this->accountAccess->forget($target);

        if ($restriction->wasRecentlyCreated) {
            $target->notify((new AccountRestrictionNotification(
                'applied',
                $restriction->public_id,
                $restriction->public_notice_key,
                $restriction->expires_at?->toAtomString(),
            ))->afterCommit());
        }

        return $restriction;
    }

    private function validate(User $actor, User $target, AccountRestrictionType $type, string $reasonCode, ?CarbonInterface $expiresAt, string $publicNoticeKey, bool $confirmed): void
    {
        if (! $confirmed) {
            throw new AdministrationAccessException('administration.errors.explicit_confirmation_required');
        }

        if ($actor->is($target)) {
            throw new AdministrationAccessException('administration.errors.self_restriction_forbidden');
        }

        if (preg_match('/^[a-z][a-z0-9_.-]{2,63}$/D', $reasonCode) !== 1) {
            throw new AdministrationAccessException('administration.errors.invalid_reason_code');
        }

        if ($expiresAt !== null && $expiresAt->lessThanOrEqualTo(now())) {
            throw new AdministrationAccessException('administration.errors.invalid_restriction_expiry');
        }

        if ($publicNoticeKey !== $type->noticeKey()) {
            throw new AdministrationAccessException('administration.errors.invalid_restriction_notice');
        }
    }

    private function sanitizePrivateNote(?string $privateNote): ?string
    {
        $clean = UserPlainText::description($privateNote);

        return $clean === null ? null : mb_substr($clean, 0, 2000);
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
