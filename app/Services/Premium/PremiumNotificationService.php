<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Enums\PremiumNotificationType;
use App\Models\PremiumEntitlement;
use App\Models\User;
use App\Notifications\PremiumActivityNotification;
use App\Support\DeterministicUuid;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class PremiumNotificationService
{
    public function entitlement(User $user, PremiumEntitlement $entitlement, PremiumNotificationType $kind): void
    {
        $this->activity(
            $user,
            $kind,
            $entitlement->public_id,
            $entitlement->ends_at?->toAtomString(),
            $entitlement->is_lifetime,
        );
    }

    public function activity(
        User $user,
        PremiumNotificationType $kind,
        string $resourcePublicId,
        ?string $expiresAt = null,
        bool $lifetime = false,
    ): void {
        DB::afterCommit(function () use ($user, $kind, $resourcePublicId, $expiresAt, $lifetime): void {
            $notification = new PremiumActivityNotification(
                $kind,
                $resourcePublicId,
                $expiresAt,
                $lifetime,
            );
            $notification->id = DeterministicUuid::from(
                'seasonvar.premium.notification',
                implode(':', [$user->id, $resourcePublicId, $kind->value]),
            );

            DB::transaction(function () use ($user, $notification): void {
                $locked = User::query()->lockForUpdate()->find($user->id);

                if (! $locked instanceof User || $locked->notifications()->whereKey($notification->id)->exists()) {
                    return;
                }

                try {
                    $locked->notify($notification);
                } catch (QueryException $exception) {
                    if (! $locked->notifications()->whereKey($notification->id)->exists()) {
                        throw $exception;
                    }
                }
            }, attempts: 3);
        });
    }
}
