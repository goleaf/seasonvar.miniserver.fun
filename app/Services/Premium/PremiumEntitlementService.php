<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Enums\PremiumAuditAction;
use App\Enums\PremiumEntitlementSource;
use App\Enums\PremiumFeature;
use App\Enums\PremiumNotificationType;
use App\Models\PremiumCouponRedemption;
use App\Models\PremiumEntitlement;
use App\Models\PremiumPayment;
use App\Models\PremiumPlan;
use App\Models\PremiumSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PremiumEntitlementService
{
    public function __construct(
        private readonly PremiumAccessResolver $resolver,
        private readonly PremiumAuditService $audit,
        private readonly PremiumNotificationService $notifications,
    ) {}

    public function grantDuration(
        User $user,
        PremiumFeature $feature,
        PremiumEntitlementSource $source,
        int $durationDays,
        string $idempotencyIdentity,
        ?User $actor = null,
        ?string $reasonCode = null,
        ?string $privateNote = null,
        ?PremiumCouponRedemption $redemption = null,
        ?PremiumPlan $plan = null,
        ?PremiumSubscription $subscription = null,
        ?PremiumPayment $payment = null,
        ?CarbonImmutable $fixedStart = null,
    ): PremiumEntitlement {
        if ($durationDays < 1 || $durationDays > 3650) {
            throw new InvalidArgumentException('Срок premium-доступа должен быть от 1 до 3650 дней.');
        }

        return $this->grant(
            $user,
            $feature,
            $source,
            $idempotencyIdentity,
            false,
            $durationDays,
            $actor,
            $reasonCode,
            $privateNote,
            $redemption,
            $plan,
            $subscription,
            $payment,
            $fixedStart,
        );
    }

    public function grantLifetime(
        User $user,
        PremiumFeature $feature,
        PremiumEntitlementSource $source,
        string $idempotencyIdentity,
        ?User $actor = null,
        ?string $reasonCode = null,
        ?string $privateNote = null,
        ?PremiumPlan $plan = null,
        ?PremiumPayment $payment = null,
    ): PremiumEntitlement {
        return $this->grant(
            $user,
            $feature,
            $source,
            $idempotencyIdentity,
            true,
            null,
            $actor,
            $reasonCode,
            $privateNote,
            plan: $plan,
            payment: $payment,
        );
    }

    public function grantPeriod(
        User $user,
        PremiumFeature $feature,
        PremiumEntitlementSource $source,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        string $idempotencyIdentity,
        PremiumPlan $plan,
        PremiumSubscription $subscription,
        PremiumPayment $payment,
    ): PremiumEntitlement {
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new InvalidArgumentException('Период entitlement должен иметь положительную длительность.');
        }

        return $this->persist(
            $user,
            $feature,
            $source,
            $idempotencyIdentity,
            $startsAt,
            $endsAt,
            false,
            null,
            null,
            null,
            null,
            $plan,
            $subscription,
            $payment,
        );
    }

    public function revokeAdministrative(
        PremiumEntitlement $entitlement,
        User $actor,
        string $reasonCode,
        ?string $privateNote = null,
    ): PremiumEntitlement {
        if (! $entitlement->source->isAdministrative() && $entitlement->source !== PremiumEntitlementSource::Promotion) {
            throw new InvalidArgumentException('Платёжный entitlement нельзя отзывать как ручную выдачу.');
        }

        return $this->revoke($entitlement, $actor, $reasonCode, $privateNote, 'administrative');
    }

    public function revokeForPayment(PremiumPayment $payment, string $reasonCode): void
    {
        $notification = $reasonCode === 'chargeback'
            ? PremiumNotificationType::PaymentDisputed
            : PremiumNotificationType::RefundCompleted;
        PremiumEntitlement::query()
            ->with('user:id,name')
            ->whereBelongsTo($payment, 'payment')
            ->whereNull('revoked_at')
            ->each(function (PremiumEntitlement $entitlement) use ($reasonCode, $notification): void {
                $this->revoke($entitlement, null, $reasonCode, null, 'payment', $notification);
            });
    }

    private function grant(
        User $user,
        PremiumFeature $feature,
        PremiumEntitlementSource $source,
        string $idempotencyIdentity,
        bool $lifetime,
        ?int $durationDays,
        ?User $actor,
        ?string $reasonCode,
        ?string $privateNote,
        ?PremiumCouponRedemption $redemption = null,
        ?PremiumPlan $plan = null,
        ?PremiumSubscription $subscription = null,
        ?PremiumPayment $payment = null,
        ?CarbonImmutable $fixedStart = null,
    ): PremiumEntitlement {
        return DB::transaction(function () use (
            $user, $feature, $source, $idempotencyIdentity, $lifetime, $durationDays, $actor,
            $reasonCode, $privateNote, $redemption, $plan, $subscription, $payment, $fixedStart,
        ): PremiumEntitlement {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $now = CarbonImmutable::now();
            $start = $fixedStart ?? $now;

            if (! $lifetime && $fixedStart === null) {
                $currentExpiry = PremiumEntitlement::query()
                    ->whereBelongsTo($user)
                    ->where('feature_code', $feature->value)
                    ->where('is_lifetime', false)
                    ->activeAt($now)
                    ->with('subscription:id,status,grace_ends_at')
                    ->get(['id', 'user_id', 'premium_subscription_id', 'source', 'ends_at'])
                    ->reduce(static function (?CarbonImmutable $latest, PremiumEntitlement $entitlement) use ($now): ?CarbonImmutable {
                        $candidate = $entitlement->effectiveEndsAt($now);

                        return $candidate !== null && ($latest === null || $candidate->greaterThan($latest)) ? $candidate : $latest;
                    });

                if ($currentExpiry instanceof CarbonImmutable) {
                    $start = $currentExpiry->max($now);
                }
            }

            return $this->persist(
                $user,
                $feature,
                $source,
                $idempotencyIdentity,
                $start,
                $lifetime ? null : $start->addDays((int) $durationDays),
                $lifetime,
                $actor,
                $reasonCode,
                $privateNote,
                $redemption,
                $plan,
                $subscription,
                $payment,
            );
        }, attempts: 3);
    }

    private function persist(
        User $user,
        PremiumFeature $feature,
        PremiumEntitlementSource $source,
        string $idempotencyIdentity,
        CarbonImmutable $startsAt,
        ?CarbonImmutable $endsAt,
        bool $lifetime,
        ?User $actor,
        ?string $reasonCode,
        ?string $privateNote,
        ?PremiumCouponRedemption $redemption,
        ?PremiumPlan $plan,
        ?PremiumSubscription $subscription,
        ?PremiumPayment $payment,
    ): PremiumEntitlement {
        $applicationKey = hash('sha256', implode(':', [$feature->value, $source->value, $idempotencyIdentity]));
        $created = false;
        $entitlement = DB::transaction(function () use (
            $user, $feature, $source, $applicationKey, $startsAt, $endsAt, $lifetime, $actor,
            $reasonCode, $privateNote, $redemption, $plan, $subscription, $payment, &$created,
        ): PremiumEntitlement {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = PremiumEntitlement::query()->where('application_key', $applicationKey)->lockForUpdate()->first();

            if ($existing instanceof PremiumEntitlement) {
                if ($existing->user_id !== $user->id || $existing->source !== $source) {
                    throw new InvalidArgumentException('Idempotency identity уже принадлежит другому entitlement.');
                }

                return $existing;
            }

            $sourceReference = match (true) {
                $redemption instanceof PremiumCouponRedemption => $redemption->public_id,
                $payment instanceof PremiumPayment => $payment->public_id,
                $subscription instanceof PremiumSubscription => $subscription->public_id,
                default => null,
            };
            $created = true;
            $entitlement = PremiumEntitlement::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'premium_plan_id' => $plan?->id,
                'premium_subscription_id' => $subscription?->id,
                'premium_payment_id' => $payment?->id,
                'premium_coupon_redemption_id' => $redemption?->id,
                'feature_code' => $feature,
                'source' => $source,
                'source_reference' => $sourceReference,
                'application_key' => $applicationKey,
                'reason_code' => $reasonCode,
                'private_note' => $privateNote,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'is_lifetime' => $lifetime,
                'granted_by_id' => $actor?->id,
            ]);
            $this->audit->record(
                PremiumAuditAction::EntitlementGranted,
                'entitlement',
                $entitlement->public_id,
                'entitlement-granted:'.$applicationKey,
                $user,
                $actor,
                [
                    'feature' => $feature->value,
                    'source' => $source->value,
                    'lifetime' => $lifetime,
                    'reason_code' => $reasonCode,
                ],
            );

            return $entitlement;
        }, attempts: 3);

        $this->resolver->forget($user);

        if ($created) {
            $kind = $source === PremiumEntitlementSource::Promotion
                ? PremiumNotificationType::PromotionRedeemed
                : ($source->isAdministrative() ? PremiumNotificationType::ManualGranted : PremiumNotificationType::Activated);
            $this->notifications->entitlement($user, $entitlement, $kind);
        }

        return $entitlement;
    }

    private function revoke(
        PremiumEntitlement $entitlement,
        ?User $actor,
        string $reasonCode,
        ?string $privateNote,
        string $identity,
        PremiumNotificationType $notification = PremiumNotificationType::ManualRevoked,
    ): PremiumEntitlement {
        $changed = false;
        $entitlement = DB::transaction(function () use ($entitlement, $actor, $reasonCode, $privateNote, $identity, &$changed): PremiumEntitlement {
            $locked = PremiumEntitlement::query()->lockForUpdate()->findOrFail($entitlement->id);

            if ($locked->revoked_at !== null) {
                return $locked;
            }

            $changed = true;
            $locked->forceFill([
                'revoked_at' => now(),
                'revocation_reason_code' => $reasonCode,
                'revocation_private_note' => $privateNote,
                'revoked_by_id' => $actor?->id,
            ])->save();
            $entitlementUser = User::query()->find($locked->user_id);
            $this->audit->record(
                PremiumAuditAction::EntitlementRevoked,
                'entitlement',
                $locked->public_id,
                'entitlement-revoked:'.$locked->public_id.':'.$identity,
                $entitlementUser,
                $actor,
                ['source' => $locked->source->value, 'reason_code' => $reasonCode],
            );

            return $locked;
        }, attempts: 3);

        $user = User::query()->find($entitlement->user_id);

        if ($user instanceof User) {
            $this->resolver->forget($user);

            if ($changed) {
                $this->notifications->entitlement($user, $entitlement, $notification);
            }
        }

        return $entitlement;
    }
}
