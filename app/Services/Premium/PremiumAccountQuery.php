<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\DTOs\Premium\PremiumAccessSummary;
use App\Enums\PremiumEntitlementSource;
use App\Models\PremiumCoupon;
use App\Models\PremiumEntitlement;
use App\Models\PremiumPayment;
use App\Models\PremiumSubscription;
use App\Models\User;
use App\Services\Auth\AccountDateTimeFormatter;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as LaravelLengthAwarePaginator;

final class PremiumAccountQuery
{
    public function __construct(
        private readonly PremiumSchema $schema,
        private readonly PremiumAccessResolver $resolver,
        private readonly PremiumFeatureRegistry $features,
        private readonly AccountDateTimeFormatter $dateTimes,
    ) {}

    /**
     * @return array{
     *   available: bool,
     *   summary: PremiumAccessSummary,
     *   status_label: string,
     *   status_message: string,
     *   starts_at: ?string,
     *   expires_at: ?string,
     *   features: list<array{code: string, label: string, description: string}>,
     *   entitlements: list<array<string, mixed>>,
     *   subscription: ?array<string, mixed>,
     *   coupon_available: bool
     * }
     */
    public function overview(User $user, string $locale, string $timezone): array
    {
        if (! $this->schema->ready()) {
            return [
                'available' => false,
                'summary' => PremiumAccessSummary::inactive(),
                'status_label' => __('premium.states.unavailable'),
                'status_message' => __('premium.settings.unavailable'),
                'starts_at' => null,
                'expires_at' => null,
                'features' => [],
                'entitlements' => [],
                'subscription' => null,
                'coupon_available' => false,
                'active_plan' => null,
                'active_sources' => [],
                'active_sources_label' => null,
            ];
        }

        $summary = $this->resolver->resolve($user);
        $now = CarbonImmutable::now();
        $entitlements = PremiumEntitlement::query()
            ->whereBelongsTo($user)
            ->with('subscription:id,status,grace_ends_at')
            ->latest('starts_at')
            ->latest('id')
            ->limit(25)
            ->get([
                'id', 'public_id', 'user_id', 'premium_subscription_id', 'feature_code', 'source', 'reason_code',
                'starts_at', 'ends_at', 'is_lifetime', 'revoked_at',
            ])
            ->map(function (PremiumEntitlement $entitlement) use ($locale, $timezone, $now): array {
                $effectiveEndsAt = $entitlement->effectiveEndsAt($now);

                return [
                    'public_id' => $entitlement->public_id,
                    'feature' => $entitlement->feature_code->label(),
                    'source' => $entitlement->source->label(),
                    'reason_code' => $entitlement->reason_code,
                    'starts_at' => $this->dateTimes->value($entitlement->starts_at, $locale, $timezone),
                    'expires_at' => $effectiveEndsAt !== null
                        ? $this->dateTimes->value($effectiveEndsAt, $locale, $timezone)
                        : null,
                    'lifetime' => $entitlement->is_lifetime,
                    'revoked' => $entitlement->revoked_at !== null,
                    'active' => $entitlement->isActiveAt($now),
                    'status' => match (true) {
                        $entitlement->revoked_at !== null => __('premium.states.cancelled'),
                        $entitlement->starts_at->greaterThan($now) => __('premium.states.pending'),
                        $entitlement->isActiveAt($now) => __('premium.states.active'),
                        default => __('premium.states.expired'),
                    },
                    'period' => $entitlement->is_lifetime
                        ? __('premium.settings.lifetime')
                        : __('premium.settings.active_until', [
                            'date' => $effectiveEndsAt !== null
                                ? $this->dateTimes->value($effectiveEndsAt, $locale, $timezone)
                                : '—',
                        ]),
                ];
            })->all();
        $subscription = PremiumSubscription::query()
            ->whereBelongsTo($user)
            ->latest('provider_updated_at')
            ->latest('id')
            ->first();
        $activeEntitlement = PremiumEntitlement::query()
            ->whereBelongsTo($user)
            ->activeAt(now())
            ->with('plan:id,code')
            ->latest('starts_at')
            ->first();
        $sourceLabels = collect($summary->sources)
            ->map(fn (string $source): ?string => PremiumEntitlementSource::tryFrom($source)?->label())
            ->filter()
            ->values()
            ->all();

        return [
            'available' => true,
            'summary' => $summary,
            'status_label' => $summary->active
                ? ($summary->lifetime ? __('premium.states.lifetime') : __('premium.states.active'))
                : __('premium.states.inactive'),
            'status_message' => $summary->active
                ? ($summary->lifetime
                    ? __('premium.settings.lifetime')
                    : __('premium.settings.active_until', [
                        'date' => $summary->expiresAt !== null
                            ? $this->dateTimes->value($summary->expiresAt, $locale, $timezone)
                            : '—',
                    ]))
                : __('premium.settings.inactive'),
            'starts_at' => $summary->startsAt !== null
                ? $this->dateTimes->value($summary->startsAt, $locale, $timezone)
                : null,
            'expires_at' => $summary->expiresAt !== null
                ? $this->dateTimes->value($summary->expiresAt, $locale, $timezone)
                : null,
            'features' => collect($this->features->active())
                ->filter(fn (array $feature): bool => in_array($feature['code'], $summary->features, true))
                ->map(fn (array $feature): array => [
                    'code' => $feature['code'],
                    'label' => $feature['label'],
                    'description' => $feature['description'],
                ])->values()->all(),
            'entitlements' => $entitlements,
            'active_plan' => $activeEntitlement?->plan !== null
                ? $this->planLabel($activeEntitlement->plan->code, $locale)
                : null,
            'active_sources' => $sourceLabels,
            'active_sources_label' => $sourceLabels !== [] ? implode(', ', $sourceLabels) : null,
            'subscription' => $subscription instanceof PremiumSubscription ? [
                'status' => $subscription->status->label(),
                'period_end' => $subscription->current_period_end !== null
                    ? $this->dateTimes->value($subscription->current_period_end, $locale, $timezone)
                    : null,
                'grace_end' => $subscription->grace_ends_at !== null
                    ? $this->dateTimes->value($subscription->grace_ends_at, $locale, $timezone)
                    : null,
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
            ] : null,
            'coupon_available' => PremiumCoupon::query()
                ->where('is_active', true)
                ->whereHas('promotion', fn ($query) => $query
                    ->where('is_active', true)
                    ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                    ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now)))
                ->exists(),
        ];
    }

    private function planLabel(string $code, string $locale): string
    {
        $key = "premium.plans.{$code}.name";

        return trans()->has($key, $locale) ? __($key, locale: $locale) : __('premium.plans.unnamed', locale: $locale);
    }

    /**
     * @return LengthAwarePaginator<int, array{
     *   public_id: string,
     *   plan_code: ?string,
     *   status: string,
     *   amount: string,
     *   created_at: ?string,
     *   confirmed_at: ?string,
     *   refunded_amount: ?string
     * }>
     */
    public function payments(User $user, string $locale, string $timezone): LengthAwarePaginator
    {
        if (! $this->schema->ready()) {
            return new LaravelLengthAwarePaginator(
                items: [],
                total: 0,
                perPage: max(5, (int) config('premium.history_per_page', 15)),
                currentPage: 1,
                options: ['pageName' => 'premiumPaymentsPage'],
            );
        }

        return PremiumPayment::query()
            ->whereBelongsTo($user)
            ->with('plan:id,code')
            ->latest('created_at')
            ->latest('id')
            ->paginate(max(5, (int) config('premium.history_per_page', 15)), pageName: 'premiumPaymentsPage')
            ->through(fn (PremiumPayment $payment): array => [
                'public_id' => $payment->public_id,
                'plan_code' => $payment->plan?->code,
                'status' => $payment->status->label(),
                'amount' => Money::from($payment->amount_minor, $payment->currency)->format($locale),
                'created_at' => $payment->created_at !== null
                    ? $this->dateTimes->value($payment->created_at, $locale, $timezone)
                    : null,
                'confirmed_at' => $payment->confirmed_at !== null
                    ? $this->dateTimes->value($payment->confirmed_at, $locale, $timezone)
                    : null,
                'refunded_amount' => $payment->refunded_amount_minor > 0
                    ? Money::from($payment->refunded_amount_minor, $payment->currency)->format($locale)
                    : null,
            ]);
    }
}
