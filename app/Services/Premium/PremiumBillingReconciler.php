<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\DTOs\Premium\PremiumProviderEventData;
use App\Enums\PremiumAuditAction;
use App\Enums\PremiumCheckoutStatus;
use App\Enums\PremiumDisputeStatus;
use App\Enums\PremiumEntitlementSource;
use App\Enums\PremiumFeature;
use App\Enums\PremiumNotificationType;
use App\Enums\PremiumPaymentStatus;
use App\Enums\PremiumPlanType;
use App\Enums\PremiumProviderEventStatus;
use App\Enums\PremiumRefundStatus;
use App\Enums\PremiumSubscriptionStatus;
use App\Exceptions\InvalidPremiumWebhook;
use App\Exceptions\PremiumEventDependencyMissing;
use App\Models\PremiumCheckoutSession;
use App\Models\PremiumDispute;
use App\Models\PremiumPayment;
use App\Models\PremiumProviderEvent;
use App\Models\PremiumRefund;
use App\Models\PremiumSubscription;
use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class PremiumBillingReconciler
{
    public function __construct(
        private readonly PremiumSchema $schema,
        private readonly PremiumPaymentGatewayRegistry $gateways,
        private readonly PremiumFeatureRegistry $features,
        private readonly PremiumEntitlementService $entitlements,
        private readonly PremiumAuditService $audit,
        private readonly PremiumNotificationService $notifications,
    ) {}

    public function process(string $provider, PremiumProviderEventData $event, string $payloadHash): void
    {
        $gateway = $this->gateways->get($provider);

        if (! $this->schema->ready()
            || $gateway === null
            || ! hash_equals($gateway->environment(), $event->environment)
            || preg_match('/\A[a-z0-9][a-z0-9_-]{1,31}\z/', $provider) !== 1
            || preg_match('/\A[a-zA-Z0-9_.:-]{1,191}\z/', $event->eventId) !== 1
            || preg_match('/\A[a-z][a-z0-9_.-]{1,95}\z/', $event->type) !== 1
            || preg_match('/\A[a-z][a-z0-9_]{1,31}\z/', $event->objectType) !== 1
            || ! $this->validProviderIdentity($event->objectId)
            || preg_match('/\A[a-f0-9]{64}\z/', $payloadHash) !== 1) {
            throw new InvalidPremiumWebhook('Некорректная identity provider event.');
        }

        $providerEvent = DB::transaction(function () use ($provider, $event, $payloadHash): PremiumProviderEvent {
            $stored = PremiumProviderEvent::query()
                ->where('provider_code', $provider)
                ->where('provider_event_id', $event->eventId)
                ->lockForUpdate()
                ->first();

            if ($stored instanceof PremiumProviderEvent) {
                if (! hash_equals($stored->payload_hash, $payloadHash)) {
                    throw new InvalidPremiumWebhook('Повторный provider event имеет другой payload.');
                }

                return $stored;
            }

            return PremiumProviderEvent::query()->create([
                'provider_code' => $provider,
                'provider_event_id' => $event->eventId,
                'event_type' => $event->type,
                'environment' => $event->environment,
                'status' => PremiumProviderEventStatus::Received,
                'object_type' => $event->objectType,
                'object_id' => $event->objectId,
                'payload_hash' => $payloadHash,
                'occurred_at' => $event->occurredAt,
            ]);
        }, attempts: 3);

        if (in_array($providerEvent->status, [PremiumProviderEventStatus::Processed, PremiumProviderEventStatus::Ignored], true)) {
            return;
        }

        try {
            $processed = DB::transaction(function () use ($providerEvent, $provider, $event): bool {
                $lockedEvent = PremiumProviderEvent::query()->lockForUpdate()->findOrFail($providerEvent->id);

                if (in_array($lockedEvent->status, [PremiumProviderEventStatus::Processed, PremiumProviderEventStatus::Ignored], true)) {
                    return $lockedEvent->status === PremiumProviderEventStatus::Processed;
                }

                $lockedEvent->forceFill([
                    'status' => PremiumProviderEventStatus::Processing,
                    'attempts' => $lockedEvent->attempts + 1,
                    'failed_at' => null,
                    'error_category' => null,
                ])->save();

                $processed = match ($event->type) {
                    'payment.succeeded' => $this->paymentSucceeded($provider, $event),
                    'payment.failed' => $this->paymentFailed($provider, $event),
                    'subscription.updated' => $this->subscriptionUpdated($provider, $event),
                    'refund.succeeded' => $this->refundSucceeded($provider, $event),
                    'refund.failed' => $this->refundFailed($provider, $event),
                    'dispute.opened', 'dispute.closed', 'chargeback.created' => $this->disputeUpdated($provider, $event),
                    default => false,
                };

                $lockedEvent->forceFill([
                    'status' => $processed ? PremiumProviderEventStatus::Processed : PremiumProviderEventStatus::Ignored,
                    'processed_at' => now(),
                ])->save();
                $this->audit->record(
                    PremiumAuditAction::ProviderEventProcessed,
                    'provider_event',
                    (string) $lockedEvent->id,
                    'provider-event:'.$provider.':'.$event->eventId,
                    context: [
                        'provider' => $provider,
                        'event_type' => $event->type,
                        'processed' => $processed,
                    ],
                );

                return $processed;
            }, attempts: 3);

            unset($processed);
        } catch (Throwable $exception) {
            PremiumProviderEvent::query()->whereKey($providerEvent->id)->increment('attempts', 1, [
                'status' => PremiumProviderEventStatus::Failed->value,
                'failed_at' => now(),
                'error_category' => $this->errorCategory($exception),
                'updated_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function paymentSucceeded(string $provider, PremiumProviderEventData $event): bool
    {
        $checkout = $this->checkout($provider, $event);
        $plan = $checkout->plan;
        $user = $checkout->user;

        if ($event->paymentId === null || ! $this->validProviderIdentity($event->paymentId)
            || ($event->customerId !== null && ! $this->validProviderIdentity($event->customerId))
            || $event->amountMinor === null || $event->currency === null || ! $this->validCurrency($event->currency)) {
            throw new InvalidPremiumWebhook('Provider payment не содержит обязательные identity.');
        }

        $expected = Money::from($checkout->amount_minor, $checkout->currency);
        $received = Money::from($event->amountMinor, $event->currency);

        if (! $expected->equals($received)) {
            throw new InvalidPremiumWebhook('Amount или currency не совпадает с checkout snapshot.');
        }

        $payment = PremiumPayment::query()
            ->where('provider_code', $provider)
            ->where('provider_payment_id', $event->paymentId)
            ->lockForUpdate()
            ->first();

        if ($payment instanceof PremiumPayment && $this->isNewer($payment->provider_updated_at, $event)) {
            return false;
        }

        if ($payment instanceof PremiumPayment
            && ($payment->user_id !== $user->id
                || $payment->premium_plan_id !== $plan->id
                || $payment->premium_checkout_session_id !== $checkout->id
                || $payment->amount_minor !== $received->minor
                || $payment->currency !== $received->currency)) {
            throw new InvalidPremiumWebhook('Provider payment конфликтует с локальной identity.');
        }

        if ($payment instanceof PremiumPayment && in_array($payment->status, [
            PremiumPaymentStatus::PartiallyRefunded,
            PremiumPaymentStatus::Refunded,
            PremiumPaymentStatus::Disputed,
            PremiumPaymentStatus::Chargeback,
        ], true)) {
            return false;
        }

        $subscription = null;

        if ($plan->type === PremiumPlanType::RecurringSubscription) {
            if ($event->subscriptionId === null || ! $this->validProviderIdentity($event->subscriptionId)
                || $event->periodStart === null || $event->periodEnd === null
                || $event->periodEnd->lessThanOrEqualTo($event->periodStart)) {
                throw new InvalidPremiumWebhook('Renewal не содержит подтверждённый provider period.');
            }

            $subscription = PremiumSubscription::query()
                ->where('provider_code', $provider)
                ->where('provider_subscription_id', $event->subscriptionId)
                ->lockForUpdate()
                ->first() ?? new PremiumSubscription([
                    'provider_code' => $provider,
                    'provider_subscription_id' => $event->subscriptionId,
                ]);

            if ($subscription->exists && ($subscription->user_id !== $user->id || $subscription->premium_plan_id !== $plan->id)) {
                throw new InvalidPremiumWebhook('Provider subscription принадлежит другому account.');
            }

            if ($subscription->exists
                && $subscription->provider_customer_id !== null
                && $event->customerId !== null
                && ! hash_equals($subscription->provider_customer_id, $event->customerId)) {
                throw new InvalidPremiumWebhook('Provider customer не совпадает с локальной subscription.');
            }

            $subscriptionEventIsOlder = $subscription->exists && $this->subscriptionStateOutranksPaymentEvent($subscription, $event);

            $subscription->fill([
                'public_id' => $subscription->public_id ?? (string) Str::uuid(),
                'user_id' => $user->id,
                'premium_plan_id' => $plan->id,
                'provider_customer_id' => $event->customerId ?? $subscription->provider_customer_id,
                'status' => $subscriptionEventIsOlder
                    ? $subscription->status
                    : ($event->cancelAtPeriodEnd ? PremiumSubscriptionStatus::CancellationScheduled : PremiumSubscriptionStatus::Active),
                'current_period_start' => $subscriptionEventIsOlder ? $subscription->current_period_start : $event->periodStart,
                'current_period_end' => $subscriptionEventIsOlder ? $subscription->current_period_end : $event->periodEnd,
                'grace_ends_at' => $subscriptionEventIsOlder ? $subscription->grace_ends_at : $event->graceEndsAt,
                'cancel_at_period_end' => $subscriptionEventIsOlder ? $subscription->cancel_at_period_end : $event->cancelAtPeriodEnd,
                'provider_updated_at' => $subscriptionEventIsOlder ? $subscription->provider_updated_at : $event->occurredAt,
            ])->save();
        }

        $payment ??= new PremiumPayment([
            'public_id' => (string) Str::uuid(),
            'provider_code' => $provider,
            'provider_payment_id' => $event->paymentId,
        ]);
        $payment->fill([
            'user_id' => $user->id,
            'premium_plan_id' => $plan->id,
            'premium_checkout_session_id' => $checkout->id,
            'premium_subscription_id' => $subscription?->id,
            'status' => PremiumPaymentStatus::Succeeded,
            'amount_minor' => $received->minor,
            'currency' => $received->currency,
            'confirmed_at' => $payment->confirmed_at ?? now(),
            'provider_created_at' => $payment->provider_created_at ?? $event->occurredAt,
            'provider_updated_at' => $event->occurredAt,
        ])->save();

        $checkout->forceFill([
            'status' => PremiumCheckoutStatus::Succeeded,
            'completed_at' => now(),
        ])->save();

        foreach ((array) $plan->entitlement_codes as $featureCode) {
            $feature = is_string($featureCode) ? PremiumFeature::tryFrom($featureCode) : null;

            if (! $feature instanceof PremiumFeature || ! $this->features->supports($feature->value)) {
                continue;
            }

            match ($plan->type) {
                PremiumPlanType::RecurringSubscription => $this->entitlements->grantPeriod(
                    $user,
                    $feature,
                    PremiumEntitlementSource::Subscription,
                    $event->periodStart,
                    $event->periodEnd,
                    'subscription-payment:'.$payment->public_id.':'.$feature->value,
                    $plan,
                    $subscription,
                    $payment,
                ),
                PremiumPlanType::OneTimeDuration => $this->entitlements->grantDuration(
                    $user,
                    $feature,
                    PremiumEntitlementSource::OneTimePurchase,
                    (int) $plan->duration_days,
                    'one-time-payment:'.$payment->public_id.':'.$feature->value,
                    plan: $plan,
                    payment: $payment,
                ),
                PremiumPlanType::Lifetime => $this->entitlements->grantLifetime(
                    $user,
                    $feature,
                    PremiumEntitlementSource::LifetimePurchase,
                    'lifetime-payment:'.$payment->public_id.':'.$feature->value,
                    plan: $plan,
                    payment: $payment,
                ),
            };
        }

        $this->audit->record(
            PremiumAuditAction::PaymentConfirmed,
            'payment',
            $payment->public_id,
            'payment-confirmed:'.$payment->public_id,
            $user,
            context: [
                'plan_code' => $plan->code,
                'provider' => $provider,
                'amount_minor' => $payment->amount_minor,
                'currency' => $payment->currency,
            ],
        );
        $this->notifications->activity($user, PremiumNotificationType::PaymentSucceeded, $payment->public_id);

        if ($subscription instanceof PremiumSubscription) {
            $this->notifications->activity(
                $user,
                PremiumNotificationType::SubscriptionRenewed,
                $payment->public_id,
                $subscription->current_period_end?->toAtomString(),
            );
        }

        return true;
    }

    private function paymentFailed(string $provider, PremiumProviderEventData $event): bool
    {
        $checkout = $this->checkout($provider, $event);
        $user = $checkout->user;
        $plan = $checkout->plan;

        if ($event->paymentId === null || ! $this->validProviderIdentity($event->paymentId)
            || $event->amountMinor === null || $event->currency === null || ! $this->validCurrency($event->currency)
            || ! Money::from($checkout->amount_minor, $checkout->currency)->equals(Money::from($event->amountMinor, $event->currency))) {
            throw new InvalidPremiumWebhook('Failed payment не совпадает с checkout snapshot.');
        }

        $payment = PremiumPayment::query()
            ->where('provider_code', $provider)
            ->where('provider_payment_id', $event->paymentId)
            ->lockForUpdate()
            ->first() ?? new PremiumPayment([
                'provider_code' => $provider,
                'provider_payment_id' => $event->paymentId,
            ]);

        if ($payment->exists && $this->isNewer($payment->provider_updated_at, $event)) {
            return false;
        }

        if ($payment->exists
            && ($payment->user_id !== $user->id
                || $payment->premium_plan_id !== $plan->id
                || $payment->premium_checkout_session_id !== $checkout->id
                || $payment->amount_minor !== $event->amountMinor
                || $payment->currency !== $event->currency)) {
            throw new InvalidPremiumWebhook('Failed payment конфликтует с локальной identity.');
        }

        if ($payment->exists && in_array($payment->status, [
            PremiumPaymentStatus::Succeeded,
            PremiumPaymentStatus::PartiallyRefunded,
            PremiumPaymentStatus::Refunded,
            PremiumPaymentStatus::Disputed,
            PremiumPaymentStatus::Chargeback,
        ], true)) {
            return false;
        }

        $payment->fill([
            'public_id' => $payment->public_id ?? (string) Str::uuid(),
            'user_id' => $user->id,
            'premium_plan_id' => $plan->id,
            'premium_checkout_session_id' => $checkout->id,
            'status' => PremiumPaymentStatus::Failed,
            'amount_minor' => $event->amountMinor,
            'currency' => $event->currency,
            'failed_at' => now(),
            'provider_created_at' => $payment->provider_created_at ?? $event->occurredAt,
            'provider_updated_at' => $event->occurredAt,
        ])->save();
        $checkout->forceFill(['status' => PremiumCheckoutStatus::Failed])->save();
        $this->audit->record(
            PremiumAuditAction::PaymentFailed,
            'payment',
            $payment->public_id,
            'payment-failed:'.$payment->public_id,
            $user,
            context: ['provider' => $provider, 'plan_code' => $plan->code],
        );
        $this->notifications->activity($user, PremiumNotificationType::PaymentFailed, $payment->public_id);

        return true;
    }

    private function subscriptionUpdated(string $provider, PremiumProviderEventData $event): bool
    {
        if ($event->subscriptionId === null || ! $this->validProviderIdentity($event->subscriptionId) || $event->status === null) {
            throw new InvalidPremiumWebhook('Subscription event не содержит identity/status.');
        }

        if (($event->periodStart === null) !== ($event->periodEnd === null)
            || ($event->periodStart !== null && $event->periodEnd?->lessThanOrEqualTo($event->periodStart) === true)
            || ($event->customerId !== null && ! $this->validProviderIdentity($event->customerId))) {
            throw new InvalidPremiumWebhook('Subscription event содержит некорректный period/customer.');
        }

        $subscription = PremiumSubscription::query()
            ->where('provider_code', $provider)
            ->where('provider_subscription_id', $event->subscriptionId)
            ->lockForUpdate()
            ->first();
        $status = PremiumSubscriptionStatus::tryFrom($event->status);

        if (! $status instanceof PremiumSubscriptionStatus) {
            throw new InvalidPremiumWebhook('Subscription status не поддерживается доменной моделью.');
        }

        if (! $subscription instanceof PremiumSubscription) {
            throw new PremiumEventDependencyMissing('Локальная subscription ещё не создана подтверждённым payment event.');
        }

        if ($this->isNewer($subscription->provider_updated_at, $event)) {
            return false;
        }

        if ($subscription->provider_updated_at?->equalTo($event->occurredAt) === true
            && (($subscription->cancel_at_period_end && ! $event->cancelAtPeriodEnd)
                || (in_array($subscription->status, [
                    PremiumSubscriptionStatus::Cancelled,
                    PremiumSubscriptionStatus::Expired,
                    PremiumSubscriptionStatus::Suspended,
                ], true) && $status->mayHavePortalAccess()))) {
            return false;
        }

        if ($event->customerId !== null
            && $subscription->provider_customer_id !== null
            && ! hash_equals($subscription->provider_customer_id, $event->customerId)) {
            throw new InvalidPremiumWebhook('Provider customer не совпадает с локальной subscription.');
        }

        $wasScheduled = $subscription->cancel_at_period_end;
        $subscription->forceFill([
            'status' => $event->cancelAtPeriodEnd && $status->mayHavePortalAccess()
                ? PremiumSubscriptionStatus::CancellationScheduled
                : $status,
            'provider_customer_id' => $event->customerId ?? $subscription->provider_customer_id,
            'current_period_start' => $event->periodStart ?? $subscription->current_period_start,
            'current_period_end' => $event->periodEnd ?? $subscription->current_period_end,
            'grace_ends_at' => $event->graceEndsAt,
            'cancel_at_period_end' => $event->cancelAtPeriodEnd,
            'cancelled_at' => $status === PremiumSubscriptionStatus::Cancelled ? now() : $subscription->cancelled_at,
            'ended_at' => in_array($status, [PremiumSubscriptionStatus::Cancelled, PremiumSubscriptionStatus::Expired], true) ? now() : $subscription->ended_at,
            'provider_updated_at' => $event->occurredAt,
        ])->save();
        $user = $subscription->user_id !== null ? User::query()->find($subscription->user_id) : null;
        $this->audit->record(
            PremiumAuditAction::SubscriptionUpdated,
            'subscription',
            $subscription->public_id,
            'subscription-updated:'.$provider.':'.$event->eventId,
            $user,
            context: [
                'provider' => $provider,
                'status' => $subscription->status->value,
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
            ],
        );

        if ($user instanceof User && ! $wasScheduled && $subscription->cancel_at_period_end) {
            $this->notifications->activity(
                $user,
                PremiumNotificationType::CancellationScheduled,
                $subscription->public_id,
                $subscription->current_period_end?->toAtomString(),
            );
        }

        return true;
    }

    private function refundSucceeded(string $provider, PremiumProviderEventData $event): bool
    {
        $payment = $this->payment($provider, $event);

        if ($event->refundId === null || ! $this->validProviderIdentity($event->refundId)
            || $event->amountMinor === null || $event->currency === null || ! $this->validCurrency($event->currency)
            || $event->amountMinor < 1 || $event->amountMinor > $payment->amount_minor
            || $payment->currency !== $event->currency) {
            throw new InvalidPremiumWebhook('Refund event не содержит корректную сумму/identity.');
        }

        $refund = PremiumRefund::query()->firstOrNew([
            'premium_payment_id' => $payment->id,
            'provider_refund_id' => $event->refundId,
        ]);

        if ($refund->exists && $this->isNewer($refund->provider_updated_at, $event)) {
            return false;
        }

        if ($refund->exists && $refund->status === PremiumRefundStatus::Succeeded) {
            return false;
        }

        $refund->fill([
            'public_id' => $refund->public_id ?? (string) Str::uuid(),
            'idempotency_key' => hash('sha256', 'provider-refund:'.$provider.':'.$event->refundId),
            'status' => PremiumRefundStatus::Succeeded,
            'amount_minor' => $event->amountMinor,
            'currency' => $event->currency,
            'confirmed_at' => now(),
            'provider_updated_at' => $event->occurredAt,
        ])->save();
        $total = (int) PremiumRefund::query()
            ->where('premium_payment_id', $payment->id)
            ->where('status', PremiumRefundStatus::Succeeded->value)
            ->sum('amount_minor');

        if ($total > $payment->amount_minor) {
            throw new InvalidPremiumWebhook('Сумма confirmed refunds превышает исходный payment.');
        }

        $full = $total === $payment->amount_minor;
        $payment->forceFill([
            'refunded_amount_minor' => $total,
            'status' => $payment->status === PremiumPaymentStatus::Chargeback
                ? PremiumPaymentStatus::Chargeback
                : ($full ? PremiumPaymentStatus::Refunded : PremiumPaymentStatus::PartiallyRefunded),
            'provider_updated_at' => $event->occurredAt,
        ])->save();

        if ($full) {
            $this->entitlements->revokeForPayment($payment, 'full_refund');
        }

        $user = $payment->user_id !== null ? User::query()->find($payment->user_id) : null;
        $this->audit->record(
            PremiumAuditAction::RefundUpdated,
            'refund',
            $refund->public_id,
            'refund-succeeded:'.$provider.':'.$event->refundId,
            $user,
            context: [
                'amount_minor' => $refund->amount_minor,
                'currency' => $refund->currency,
                'full_refund' => $full,
            ],
        );

        if ($user instanceof User) {
            $this->notifications->activity($user, PremiumNotificationType::RefundCompleted, $refund->public_id);
        }

        return true;
    }

    private function refundFailed(string $provider, PremiumProviderEventData $event): bool
    {
        $payment = $this->payment($provider, $event);

        if ($event->refundId === null || ! $this->validProviderIdentity($event->refundId)
            || $event->amountMinor === null || $event->amountMinor < 1 || $event->amountMinor > $payment->amount_minor
            || $event->currency === null || ! $this->validCurrency($event->currency)
            || $event->currency !== $payment->currency) {
            return false;
        }

        $refund = PremiumRefund::query()->firstOrNew([
            'premium_payment_id' => $payment->id,
            'provider_refund_id' => $event->refundId,
        ]);

        if ($refund->exists && $this->isNewer($refund->provider_updated_at, $event)) {
            return false;
        }

        if ($refund->exists && $refund->status === PremiumRefundStatus::Succeeded) {
            return false;
        }

        $refund->fill([
            'public_id' => $refund->public_id ?? (string) Str::uuid(),
            'idempotency_key' => hash('sha256', 'provider-refund:'.$provider.':'.$event->refundId),
            'status' => PremiumRefundStatus::Failed,
            'amount_minor' => $event->amountMinor,
            'currency' => $event->currency,
            'provider_updated_at' => $event->occurredAt,
        ])->save();

        return true;
    }

    private function disputeUpdated(string $provider, PremiumProviderEventData $event): bool
    {
        $payment = $this->payment($provider, $event);

        if ($event->disputeId === null || ! $this->validProviderIdentity($event->disputeId)
            || (($event->amountMinor === null) !== ($event->currency === null))
            || ($event->amountMinor !== null && ($event->amountMinor < 1 || $event->amountMinor > $payment->amount_minor))
            || ($event->currency !== null && (! $this->validCurrency($event->currency) || $event->currency !== $payment->currency))) {
            throw new InvalidPremiumWebhook('Dispute event не содержит identity.');
        }

        $dispute = PremiumDispute::query()->firstOrNew([
            'premium_payment_id' => $payment->id,
            'provider_dispute_id' => $event->disputeId,
        ]);

        if ($dispute->exists && $this->isNewer($dispute->provider_updated_at, $event)) {
            return false;
        }

        $status = $event->type === 'dispute.closed'
            ? (PremiumDisputeStatus::tryFrom((string) $event->status) ?? PremiumDisputeStatus::Closed)
            : PremiumDisputeStatus::Open;
        $chargeback = $event->type === 'chargeback.created';
        $dispute->fill([
            'public_id' => $dispute->public_id ?? (string) Str::uuid(),
            'status' => $status,
            'amount_minor' => $event->amountMinor,
            'currency' => $event->currency,
            'opened_at' => $dispute->opened_at ?? $event->occurredAt,
            'closed_at' => $status === PremiumDisputeStatus::Open ? null : $event->occurredAt,
            'provider_updated_at' => $event->occurredAt,
        ])->save();
        $payment->forceFill([
            'status' => $payment->status === PremiumPaymentStatus::Chargeback || $chargeback
                ? PremiumPaymentStatus::Chargeback
                : PremiumPaymentStatus::Disputed,
            'provider_updated_at' => $event->occurredAt,
        ])->save();

        if ($chargeback) {
            $this->entitlements->revokeForPayment($payment, 'chargeback');
        }

        $user = $payment->user_id !== null ? User::query()->find($payment->user_id) : null;
        $this->audit->record(
            PremiumAuditAction::DisputeUpdated,
            'dispute',
            $dispute->public_id,
            'dispute-updated:'.$provider.':'.$event->eventId,
            $user,
            context: [
                'status' => $dispute->status->value,
                'chargeback' => $chargeback,
            ],
        );

        if ($user instanceof User) {
            $this->notifications->activity($user, PremiumNotificationType::PaymentDisputed, $dispute->public_id);
        }

        return true;
    }

    private function checkout(string $provider, PremiumProviderEventData $event): PremiumCheckoutSession
    {
        if ($event->checkoutPublicId === null || ! Str::isUuid($event->checkoutPublicId)) {
            throw new InvalidPremiumWebhook('Provider event не содержит checkout reference.');
        }

        $checkout = PremiumCheckoutSession::query()
            ->with(['plan', 'user'])
            ->where('public_id', $event->checkoutPublicId)
            ->lockForUpdate()
            ->first();

        if (! $checkout instanceof PremiumCheckoutSession || $checkout->provider_code !== $provider) {
            throw new InvalidPremiumWebhook('Checkout reference не найден или provider не совпадает.');
        }

        return $checkout;
    }

    private function payment(string $provider, PremiumProviderEventData $event): PremiumPayment
    {
        if ($event->paymentId === null || ! $this->validProviderIdentity($event->paymentId)) {
            throw new InvalidPremiumWebhook('Provider event не содержит payment identity.');
        }

        $payment = PremiumPayment::query()
            ->where('provider_code', $provider)
            ->where('provider_payment_id', $event->paymentId)
            ->lockForUpdate()
            ->first();

        if (! $payment instanceof PremiumPayment) {
            throw new PremiumEventDependencyMissing('Payment reference ожидает подтверждённое payment event.');
        }

        return $payment;
    }

    private function isNewer(mixed $storedAt, PremiumProviderEventData $event): bool
    {
        return $storedAt !== null && $storedAt->greaterThan($event->occurredAt);
    }

    private function subscriptionStateOutranksPaymentEvent(PremiumSubscription $subscription, PremiumProviderEventData $event): bool
    {
        if ($this->isNewer($subscription->provider_updated_at, $event)) {
            return true;
        }

        return $subscription->provider_updated_at?->equalTo($event->occurredAt) === true
            && ($subscription->cancel_at_period_end || in_array($subscription->status, [
                PremiumSubscriptionStatus::Cancelled,
                PremiumSubscriptionStatus::Expired,
                PremiumSubscriptionStatus::Suspended,
            ], true));
    }

    private function validProviderIdentity(string $identity): bool
    {
        return preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,190}\z/', $identity) === 1;
    }

    private function validCurrency(string $currency): bool
    {
        return preg_match('/\A[A-Z]{3}\z/', $currency) === 1;
    }

    private function errorCategory(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof PremiumEventDependencyMissing => 'dependency_missing',
            $exception instanceof InvalidPremiumWebhook => 'invalid_event',
            default => 'processing_failed',
        };
    }
}
