<?php

declare(strict_types=1);

namespace App\Actions\Premium;

use App\DTOs\Premium\PremiumCheckoutCreation;
use App\Enums\PremiumAuditAction;
use App\Enums\PremiumCheckoutStatus;
use App\Enums\PremiumPlanType;
use App\Enums\PremiumSubscriptionStatus;
use App\Models\PremiumCheckoutSession;
use App\Models\User;
use App\Services\Premium\PremiumAccessResolver;
use App\Services\Premium\PremiumAuditService;
use App\Services\Premium\PremiumPaymentGatewayRegistry;
use App\Services\Premium\PremiumPlanQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CreatePremiumCheckout
{
    public function __construct(
        private readonly PremiumPlanQuery $plans,
        private readonly PremiumPaymentGatewayRegistry $gateways,
        private readonly PremiumAuditService $audit,
        private readonly PremiumAccessResolver $access,
    ) {}

    public function handle(User $user, string $planCode, string $locale, string $requestToken): PremiumCheckoutCreation
    {
        if (! in_array($locale, (array) config('catalog-collections.supported_locales', []), true)) {
            throw ValidationException::withMessages(['selectedPlan' => [__('premium.errors.checkout_invalid')]]);
        }

        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['selectedPlan' => [__('premium.errors.verified_account_required')]]);
        }

        if (preg_match('/\A[0-9a-f-]{36}\z/i', $requestToken) !== 1) {
            throw ValidationException::withMessages(['selectedPlan' => [__('premium.errors.checkout_invalid')]]);
        }

        $plan = $this->plans->purchasable($planCode);

        if ($plan === null || ! is_string($plan->provider_code)) {
            throw ValidationException::withMessages(['selectedPlan' => [__('premium.errors.plan_unavailable')]]);
        }

        $gateway = $this->gateways->get($plan->provider_code);

        if ($gateway === null) {
            throw ValidationException::withMessages(['selectedPlan' => [__('premium.errors.provider_unavailable')]]);
        }

        $summary = $this->access->resolve($user);

        if (($plan->type === PremiumPlanType::Lifetime && $summary->lifetime)
            || ($plan->type === PremiumPlanType::RecurringSubscription
                && $user->premiumSubscriptions()->whereIn('status', array_map(
                    static fn (PremiumSubscriptionStatus $status): string => $status->value,
                    [
                        PremiumSubscriptionStatus::Pending,
                        PremiumSubscriptionStatus::Trialing,
                        PremiumSubscriptionStatus::Active,
                        PremiumSubscriptionStatus::PastDue,
                        PremiumSubscriptionStatus::GracePeriod,
                        PremiumSubscriptionStatus::CancellationScheduled,
                    ],
                ))->exists())) {
            throw ValidationException::withMessages(['selectedPlan' => [__('premium.errors.duplicate_purchase')]]);
        }

        $idempotencyKey = hash('sha256', implode(':', ['checkout', $user->id, $plan->code, $requestToken]));
        $created = false;
        $checkout = DB::transaction(function () use ($user, $plan, $locale, $idempotencyKey, &$created): PremiumCheckoutSession {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $existing = PremiumCheckoutSession::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($existing instanceof PremiumCheckoutSession) {
                return $existing;
            }

            $pending = PremiumCheckoutSession::query()
                ->whereBelongsTo($user)
                ->whereBelongsTo($plan, 'plan')
                ->whereIn('status', [PremiumCheckoutStatus::Created->value, PremiumCheckoutStatus::Pending->value])
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->lockForUpdate()
                ->first();

            if ($pending instanceof PremiumCheckoutSession) {
                return $pending;
            }

            $created = true;
            $checkout = PremiumCheckoutSession::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'premium_plan_id' => $plan->id,
                'provider_code' => $plan->provider_code,
                'idempotency_key' => $idempotencyKey,
                'status' => PremiumCheckoutStatus::Created,
                'amount_minor' => $plan->amount_minor,
                'currency' => $plan->currency,
                'locale' => $locale,
                'expires_at' => now()->addMinutes(max(5, (int) config('premium.checkout_ttl_minutes', 30))),
            ]);
            $this->audit->record(
                PremiumAuditAction::CheckoutCreated,
                'checkout',
                $checkout->public_id,
                'checkout-created:'.$idempotencyKey,
                $user,
                context: [
                    'plan_code' => $plan->code,
                    'provider' => $plan->provider_code,
                    'amount_minor' => $plan->amount_minor,
                    'currency' => $plan->currency,
                ],
            );

            return $checkout;
        }, attempts: 3);

        if (! $created) {
            throw ValidationException::withMessages(['selectedPlan' => [__('premium.errors.checkout_already_created')]]);
        }

        try {
            $returnRoute = in_array($locale, (array) config('catalog-collections.supported_locales', []), true)
                ? 'localized.premium.return'
                : 'premium.return';
            $returnParameters = array_filter([
                'locale' => $returnRoute === 'localized.premium.return' ? $locale : null,
                'checkout' => $checkout->public_id,
            ]);
            $hosted = $gateway->createHostedCheckout(
                $checkout,
                route($returnRoute, $returnParameters),
                route($returnRoute, [...$returnParameters, 'result' => 'cancelled']),
            );
            if (! $this->gateways->allowsHostedRedirect($plan->provider_code, $hosted->redirectUrl)
                || preg_match('/\A[a-zA-Z0-9_.:-]{1,191}\z/', $hosted->providerSessionId) !== 1
                || $hosted->expiresAt?->isPast() === true
                || $hosted->expiresAt?->greaterThan(now()->addMinutes(max(5, (int) config('premium.checkout_ttl_minutes', 30)) + 5)) === true) {
                throw new \RuntimeException('Провайдер вернул небезопасный checkout URL.');
            }

            $checkout->forceFill([
                'provider_session_id' => $hosted->providerSessionId,
                'status' => PremiumCheckoutStatus::Pending,
                'expires_at' => $hosted->expiresAt ?? $checkout->expires_at,
            ])->save();
        } catch (Throwable $exception) {
            $checkout->forceFill(['status' => PremiumCheckoutStatus::Failed])->save();
            report($exception);

            throw ValidationException::withMessages(['selectedPlan' => [__('premium.errors.checkout_failed')]]);
        }

        return new PremiumCheckoutCreation($checkout->refresh(), $hosted->redirectUrl);
    }
}
