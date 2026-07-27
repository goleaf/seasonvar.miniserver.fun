<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\DTOs\Premium\PremiumAccessSummary;
use App\DTOs\Premium\PremiumPaymentHistoryData;
use App\Enums\PremiumEntitlementSource;
use App\Models\PremiumCoupon;
use App\Models\PremiumEntitlement;
use App\Models\PremiumPayment;
use App\Models\PremiumSubscription;
use App\Models\User;
use App\Services\Auth\AccountDateTimeFormatter;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

final class PremiumAccountQuery
{
    private const ENTITLEMENT_HISTORY_LIMIT = 25;

    private const PAYMENT_HISTORY_MINIMUM = 5;

    private const PAYMENT_HISTORY_MAXIMUM = 50;

    public function __construct(
        private readonly PremiumSchema $schema,
        private readonly PremiumAccessResolver $resolver,
        private readonly PremiumFeatureRegistry $features,
        private readonly AccountDateTimeFormatter $dateTimes,
    ) {}

    /**
     * @return array{
     *   overview: array<string, mixed>,
     *   payments: LengthAwarePaginator<int, PremiumPaymentHistoryData>
     * }
     */
    public function snapshot(User $user, string $locale, string $timezone): array
    {
        if (! $this->schema->ready()) {
            return [
                'overview' => $this->unavailableOverview(),
                'payments' => $this->emptyPayments(),
            ];
        }

        return [
            'overview' => $this->preparedOverview($user, $locale, $timezone),
            'payments' => $this->preparedPayments($user, $locale, $timezone),
        ];
    }

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
            return $this->unavailableOverview();
        }

        return $this->preparedOverview($user, $locale, $timezone);
    }

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
     *   coupon_available: bool,
     *   active_plan: ?string,
     *   active_sources: list<string>,
     *   active_sources_label: ?string
     * }
     */
    private function preparedOverview(User $user, string $locale, string $timezone): array
    {
        $now = CarbonImmutable::now();
        $accountState = $this->accountEntitlementSnapshot($user, $now);
        $accountEntitlements = $accountState['entitlements'];
        $summary = $this->resolver->resolveLoaded($user, $accountEntitlements, $now);
        $entitlements = $accountEntitlements
            ->take(self::ENTITLEMENT_HISTORY_LIMIT)
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
        $subscription = $accountState['subscription'];
        $activeEntitlement = $accountEntitlements->first(
            static fn (PremiumEntitlement $entitlement): bool => $entitlement->isActiveAt($now)
                && $entitlement->plan !== null,
        );
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

    /**
     * @return array{
     *   entitlements: Collection<int, PremiumEntitlement>,
     *   subscription: ?PremiumSubscription
     * }
     */
    private function accountEntitlementSnapshot(User $user, CarbonImmutable $now): array
    {
        $recentEntitlementIds = PremiumEntitlement::query()
            ->select('id')
            ->whereBelongsTo($user)
            ->latest('starts_at')
            ->latest('id')
            ->limit(self::ENTITLEMENT_HISTORY_LIMIT);

        $entitlements = PremiumEntitlement::query()
            ->whereBelongsTo($user)
            ->where(function (Builder $query) use ($now, $recentEntitlementIds): void {
                $query->activeAt($now)
                    ->orWhereIn('id', $recentEntitlementIds);
            })
            ->with('plan:id,code')
            ->latest('starts_at')
            ->latest('id')
            ->get([
                'id', 'public_id', 'user_id', 'premium_plan_id', 'premium_subscription_id',
                'feature_code', 'source', 'reason_code', 'starts_at', 'ends_at',
                'is_lifetime', 'revoked_at',
            ]);
        $latestSubscriptionId = PremiumSubscription::query()
            ->select('id')
            ->whereBelongsTo($user)
            ->latest('provider_updated_at')
            ->latest('id')
            ->limit(1);
        $subscriptionIds = $entitlements
            ->pluck('premium_subscription_id')
            ->filter(static fn (mixed $id): bool => is_int($id))
            ->values()
            ->all();
        $subscriptions = PremiumSubscription::query()
            ->whereBelongsTo($user)
            ->where(function (Builder $query) use ($subscriptionIds, $latestSubscriptionId): void {
                $query->whereIn('id', $subscriptionIds)
                    ->orWhereIn('id', $latestSubscriptionId);
            })
            ->latest('provider_updated_at')
            ->latest('id')
            ->get([
                'id', 'user_id', 'status', 'current_period_end', 'grace_ends_at',
                'cancel_at_period_end', 'provider_updated_at',
            ]);
        $subscriptionsById = $subscriptions->keyBy('id');

        $entitlements->each(static function (PremiumEntitlement $entitlement) use ($subscriptionsById): void {
            $entitlement->setRelation(
                'subscription',
                $entitlement->premium_subscription_id !== null
                    ? $subscriptionsById->get($entitlement->premium_subscription_id)
                    : null,
            );
        });

        return [
            'entitlements' => $entitlements,
            'subscription' => $subscriptions->first(),
        ];
    }

    /**
     * @return array{
     *   available: false,
     *   summary: PremiumAccessSummary,
     *   status_label: string,
     *   status_message: string,
     *   starts_at: null,
     *   expires_at: null,
     *   features: array{},
     *   entitlements: array{},
     *   subscription: null,
     *   coupon_available: false,
     *   active_plan: null,
     *   active_sources: array{},
     *   active_sources_label: null
     * }
     */
    private function unavailableOverview(): array
    {
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

    private function planLabel(string $code, string $locale): string
    {
        $key = "premium.plans.{$code}.name";

        return trans()->has($key, $locale) ? __($key, locale: $locale) : __('premium.plans.unnamed', locale: $locale);
    }

    /**
     * @return LengthAwarePaginator<int, PremiumPaymentHistoryData>
     */
    public function payments(User $user, string $locale, string $timezone): LengthAwarePaginator
    {
        if (! $this->schema->ready()) {
            return $this->emptyPayments();
        }

        return $this->preparedPayments($user, $locale, $timezone);
    }

    /**
     * @return LengthAwarePaginator<int, PremiumPaymentHistoryData>
     */
    private function preparedPayments(User $user, string $locale, string $timezone): LengthAwarePaginator
    {
        $payments = PremiumPayment::query()
            ->whereBelongsTo($user)
            ->with('plan:id,code')
            ->latest('created_at')
            ->latest('id')
            ->paginate(
                perPage: $this->paymentHistoryPerPage(),
                columns: [
                    'id', 'public_id', 'user_id', 'premium_plan_id', 'status',
                    'amount_minor', 'currency', 'refunded_amount_minor',
                    'confirmed_at', 'created_at',
                ],
                pageName: 'premiumPaymentsPage',
            );
        $items = $payments->getCollection()
            ->map(fn (PremiumPayment $payment): PremiumPaymentHistoryData => new PremiumPaymentHistoryData(
                publicId: $payment->public_id,
                planCode: $payment->plan?->code,
                status: $payment->status->label(),
                amount: Money::from($payment->amount_minor, $payment->currency)->format($locale),
                createdAt: $payment->created_at !== null
                    ? $this->dateTimes->value($payment->created_at, $locale, $timezone)
                    : null,
                confirmedAt: $payment->confirmed_at !== null
                    ? $this->dateTimes->value($payment->confirmed_at, $locale, $timezone)
                    : null,
                refundedAmount: $payment->refunded_amount_minor > 0
                    ? Money::from($payment->refunded_amount_minor, $payment->currency)->format($locale)
                    : null,
            ));

        return new LengthAwarePaginator(
            items: $items,
            total: $payments->total(),
            perPage: $payments->perPage(),
            currentPage: $payments->currentPage(),
            options: $payments->getOptions(),
        );
    }

    /**
     * @return LengthAwarePaginator<int, PremiumPaymentHistoryData>
     */
    private function emptyPayments(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            items: [],
            total: 0,
            perPage: $this->paymentHistoryPerPage(),
            currentPage: 1,
            options: ['pageName' => 'premiumPaymentsPage'],
        );
    }

    private function paymentHistoryPerPage(): int
    {
        return min(
            self::PAYMENT_HISTORY_MAXIMUM,
            max(
                self::PAYMENT_HISTORY_MINIMUM,
                (int) config('premium.history_per_page', 15),
            ),
        );
    }
}
