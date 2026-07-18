<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\Enums\PremiumSubscriptionStatus;
use App\Models\PremiumCouponRedemption;
use App\Models\PremiumEntitlement;
use App\Models\PremiumPayment;
use App\Models\PremiumRefund;
use App\Models\PremiumSubscription;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class PremiumAccountService
{
    public function __construct(private readonly PremiumSchema $schema) {}

    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        if (! $this->schema->ready()) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'entitlements' => PremiumEntitlement::query()
                ->whereBelongsTo($user)
                ->oldest('created_at')
                ->get()
                ->map(fn (PremiumEntitlement $entitlement): array => [
                    'public_id' => $entitlement->public_id,
                    'feature_code' => $entitlement->feature_code->value,
                    'source' => $entitlement->source->value,
                    'reason_code' => $entitlement->reason_code,
                    'starts_at' => $entitlement->starts_at->toAtomString(),
                    'ends_at' => $entitlement->ends_at?->toAtomString(),
                    'lifetime' => $entitlement->is_lifetime,
                    'revoked_at' => $entitlement->revoked_at?->toAtomString(),
                ])->all(),
            'subscriptions' => PremiumSubscription::query()
                ->whereBelongsTo($user)
                ->with('plan:id,code')
                ->oldest('created_at')
                ->get()
                ->map(fn (PremiumSubscription $subscription): array => [
                    'public_id' => $subscription->public_id,
                    'plan_code' => $subscription->plan->code,
                    'provider' => $subscription->provider_code,
                    'status' => $subscription->status->value,
                    'current_period_start' => $subscription->current_period_start?->toAtomString(),
                    'current_period_end' => $subscription->current_period_end?->toAtomString(),
                    'grace_ends_at' => $subscription->grace_ends_at?->toAtomString(),
                    'cancel_at_period_end' => $subscription->cancel_at_period_end,
                ])->all(),
            'payments' => PremiumPayment::query()
                ->whereBelongsTo($user)
                ->with([
                    'plan:id,code',
                    'refunds:id,premium_payment_id,public_id,status,amount_minor,currency,confirmed_at,created_at',
                ])
                ->oldest('created_at')
                ->get()
                ->map(fn (PremiumPayment $payment): array => [
                    'public_id' => $payment->public_id,
                    'plan_code' => $payment->plan?->code,
                    'provider' => $payment->provider_code,
                    'status' => $payment->status->value,
                    'amount_minor' => $payment->amount_minor,
                    'currency' => $payment->currency,
                    'refunded_amount_minor' => $payment->refunded_amount_minor,
                    'created_at' => $payment->created_at?->toAtomString(),
                    'confirmed_at' => $payment->confirmed_at?->toAtomString(),
                    'refunds' => $payment->refunds
                        ->sortBy('created_at')
                        ->map(fn (PremiumRefund $refund): array => [
                            'public_id' => $refund->public_id,
                            'status' => $refund->status->value,
                            'amount_minor' => $refund->amount_minor,
                            'currency' => $refund->currency,
                            'confirmed_at' => $refund->confirmed_at?->toAtomString(),
                        ])->all(),
                ])->all(),
            'promotions' => PremiumCouponRedemption::query()
                ->whereBelongsTo($user)
                ->with('promotion:id,public_id,code,duration_days')
                ->oldest('redeemed_at')
                ->get()
                ->map(fn (PremiumCouponRedemption $redemption): array => [
                    'public_id' => $redemption->public_id,
                    'campaign_code' => $redemption->promotion->code,
                    'duration_days' => $redemption->promotion->duration_days,
                    'redeemed_at' => $redemption->redeemed_at->toAtomString(),
                ])->all(),
        ];
    }

    public function ensureDeletionSafe(User $user): void
    {
        if (! $this->schema->ready()) {
            return;
        }

        $active = PremiumSubscription::query()
            ->whereBelongsTo($user)
            ->whereIn('status', array_map(
                static fn (PremiumSubscriptionStatus $status): string => $status->value,
                [
                    PremiumSubscriptionStatus::Pending,
                    PremiumSubscriptionStatus::Trialing,
                    PremiumSubscriptionStatus::Active,
                    PremiumSubscriptionStatus::PastDue,
                    PremiumSubscriptionStatus::GracePeriod,
                    PremiumSubscriptionStatus::CancellationScheduled,
                ],
            ))
            ->exists();

        if ($active) {
            throw ValidationException::withMessages([
                'password' => [__('premium.errors.account_deletion_subscription')],
            ]);
        }
    }
}
