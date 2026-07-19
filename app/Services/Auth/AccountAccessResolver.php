<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AccountRestrictionType;
use App\Models\AccountRestriction;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

final class AccountAccessResolver
{
    /** @var array<int, AccountRestriction|null> */
    private array $blockingRestrictions = [];

    public function canAuthenticate(User $user): bool
    {
        return $this->blockingRestriction($user) === null;
    }

    public function blockingRestriction(User $user): ?AccountRestriction
    {
        $userId = (int) $user->getKey();

        if (array_key_exists($userId, $this->blockingRestrictions)) {
            return $this->blockingRestrictions[$userId];
        }

        if (! Schema::hasTable('account_restrictions')) {
            return $this->blockingRestrictions[$userId] = null;
        }

        return $this->blockingRestrictions[$userId] = AccountRestriction::query()
            ->select(['id', 'public_id', 'user_id', 'type', 'reason_code', 'public_notice_key', 'starts_at', 'expires_at', 'revoked_at'])
            ->where('user_id', $userId)
            ->active()
            ->whereIn('type', collect(AccountRestrictionType::cases())
                ->filter->blocksAuthentication()
                ->pluck('value')
                ->all())
            ->latest('starts_at')
            ->first();
    }

    public function forget(User|int $user): void
    {
        unset($this->blockingRestrictions[$user instanceof User ? (int) $user->getKey() : $user]);
    }
}
