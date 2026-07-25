<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\DTOs\Premium\PremiumPlanData;
use App\Enums\PremiumPlanType;
use App\Models\PremiumPlan;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Lang;

final class PremiumPlanQuery
{
    private const int MAX_PUBLIC_PLANS = 12;

    private const int MAX_PUBLIC_PLAN_CANDIDATES = 48;

    /** @var list<string> */
    private const array PLAN_COLUMNS = [
        'id',
        'code',
        'type',
        'duration_days',
        'billing_interval',
        'amount_minor',
        'currency',
        'entitlement_codes',
        'provider_code',
        'provider_product_id',
        'provider_price_id',
        'region_codes',
        'display_order',
    ];

    public function __construct(
        private readonly PremiumSchema $schema,
        private readonly PremiumPaymentGatewayRegistry $gateways,
        private readonly PremiumFeatureRegistry $features,
    ) {}

    /** @return list<PremiumPlanData> */
    public function publicPlans(string $locale): array
    {
        if (! in_array($locale, $this->supportedLocales(), true)
            || ! $this->commerceConfigured()
            || ! $this->schema->ready()) {
            return [];
        }

        return $this->publicCandidateQuery()
            ->orderBy('display_order')
            ->orderBy('id')
            ->limit(self::MAX_PUBLIC_PLAN_CANDIDATES)
            ->get()
            ->filter(fn (PremiumPlan $plan): bool => $this->commerciallyValid($plan))
            ->filter(fn (PremiumPlan $plan): bool => $this->editoriallyComplete($plan))
            ->filter(fn (PremiumPlan $plan): bool => $this->regionEligible($plan))
            ->filter(fn (PremiumPlan $plan): bool => $this->gatewaySupports($plan))
            ->take(self::MAX_PUBLIC_PLANS)
            ->map(fn (PremiumPlan $plan): PremiumPlanData => $this->present($plan, $locale))
            ->values()
            ->all();
    }

    public function purchasable(string $code): ?PremiumPlan
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{1,63}\z/', $code) !== 1
            || ! $this->commerceConfigured()
            || ! $this->schema->ready()) {
            return null;
        }

        $plan = $this->publicCandidateQuery()
            ->where('code', $code)
            ->first();

        return $plan instanceof PremiumPlan
            && $this->commerciallyValid($plan)
            && $this->editoriallyComplete($plan)
            && $this->regionEligible($plan)
            && is_int($plan->amount_minor)
            && is_string($plan->currency)
            && is_string($plan->provider_code)
            && $this->gatewaySupports($plan)
                ? $plan
                : null;
    }

    /** @return Builder<PremiumPlan> */
    private function publicCandidateQuery(): Builder
    {
        return PremiumPlan::query()
            ->select(self::PLAN_COLUMNS)
            ->purchasable()
            ->where('amount_minor', '>', 0)
            ->whereIn('currency', $this->supportedCurrencies())
            ->whereIn('provider_code', $this->gateways->codes())
            ->whereNotNull('provider_product_id')
            ->whereNotNull('provider_price_id')
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $query): void {
                        $query
                            ->where('type', PremiumPlanType::OneTimeDuration->value)
                            ->whereBetween('duration_days', [1, 3650])
                            ->whereNull('billing_interval');
                    })
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('type', PremiumPlanType::RecurringSubscription->value)
                            ->whereNull('duration_days')
                            ->whereIn('billing_interval', ['month', 'quarter', 'year']);
                    })
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('type', PremiumPlanType::Lifetime->value)
                            ->whereNull('duration_days')
                            ->whereNull('billing_interval');
                    });
            });
    }

    private function commerceConfigured(): bool
    {
        $currencies = array_values((array) config('premium.supported_currencies', []));

        if ($this->gateways->codes() === [] || $currencies === []) {
            return false;
        }

        foreach ($currencies as $currency) {
            if (! is_string($currency) || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
                return false;
            }
        }

        return count(array_unique($currencies)) === count($currencies);
    }

    /** @return list<string> */
    private function supportedCurrencies(): array
    {
        return array_values(array_filter(
            (array) config('premium.supported_currencies', []),
            static fn (mixed $currency): bool => is_string($currency)
                && preg_match('/\A[A-Z]{3}\z/', $currency) === 1,
        ));
    }

    /** @return list<string> */
    private function supportedLocales(): array
    {
        return array_values(array_unique(array_filter(
            (array) config('catalog-collections.supported_locales', []),
            static fn (mixed $locale): bool => is_string($locale)
                && preg_match('/\A[a-z]{2}\z/', $locale) === 1,
        )));
    }

    private function regionEligible(PremiumPlan $plan): bool
    {
        if ($plan->region_codes === null || $plan->region_codes === []) {
            return true;
        }

        $regions = array_values(array_filter(
            (array) $plan->region_codes,
            static fn (mixed $region): bool => is_string($region) && preg_match('/\A[A-Z]{2}\z/', $region) === 1,
        ));

        if (count($regions) !== count((array) $plan->region_codes)
            || count(array_unique($regions)) !== count($regions)) {
            return false;
        }

        $serverRegion = config('premium.server_region_code');

        return is_string($serverRegion)
            && preg_match('/\A[A-Z]{2}\z/', $serverRegion) === 1
            && in_array($serverRegion, $regions, true);
    }

    private function gatewaySupports(PremiumPlan $plan): bool
    {
        if (! is_string($plan->provider_code)) {
            return false;
        }

        $capability = match ($plan->type) {
            PremiumPlanType::OneTimeDuration => 'one_time_checkout',
            PremiumPlanType::RecurringSubscription => 'recurring_checkout',
            PremiumPlanType::Lifetime => 'lifetime_checkout',
        };

        return $this->gateways->available($plan->provider_code, 'hosted_checkout')
            && $this->gateways->supportsHostedRedirects($plan->provider_code)
            && $this->gateways->available($plan->provider_code, $capability);
    }

    private function commerciallyValid(PremiumPlan $plan): bool
    {
        $entitlements = array_values(array_filter(
            (array) $plan->entitlement_codes,
            fn (mixed $code): bool => is_string($code) && $this->features->supports($code),
        ));
        $typeFieldsValid = match ($plan->type) {
            PremiumPlanType::OneTimeDuration => is_int($plan->duration_days)
                && $plan->duration_days >= 1
                && $plan->duration_days <= 3650
                && $plan->billing_interval === null,
            PremiumPlanType::RecurringSubscription => $plan->duration_days === null
                && in_array($plan->billing_interval, ['month', 'quarter', 'year'], true),
            PremiumPlanType::Lifetime => $plan->duration_days === null
                && $plan->billing_interval === null,
        };
        $providerProductValid = is_string($plan->provider_product_id)
            && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,190}\z/', $plan->provider_product_id) === 1;
        $providerPriceValid = is_string($plan->provider_price_id)
            && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,190}\z/', $plan->provider_price_id) === 1;

        return preg_match('/\A[a-z0-9][a-z0-9_-]{1,63}\z/', $plan->code) === 1
            && is_int($plan->amount_minor)
            && $plan->amount_minor > 0
            && is_string($plan->currency)
            && in_array($plan->currency, $this->supportedCurrencies(), true)
            && $entitlements !== []
            && count($entitlements) === count((array) $plan->entitlement_codes)
            && count(array_unique($entitlements)) === count($entitlements)
            && $typeFieldsValid
            && $providerProductValid
            && $providerPriceValid;
    }

    private function editoriallyComplete(PremiumPlan $plan): bool
    {
        $locales = $this->supportedLocales();

        return $locales !== [] && collect($locales)->every(fn (string $locale): bool => Lang::has("premium.plans.{$plan->code}.name", $locale)
            && Lang::has("premium.plans.{$plan->code}.description", $locale));
    }

    private function present(PremiumPlan $plan, string $locale): PremiumPlanData
    {
        $nameKey = "premium.plans.{$plan->code}.name";
        $descriptionKey = "premium.plans.{$plan->code}.description";
        $featureCodes = array_values(array_filter($plan->entitlement_codes, 'is_string'));
        $featureRows = collect($featureCodes)
            ->filter(fn (string $code): bool => $this->features->supports($code))
            ->unique()
            ->map(fn (string $code): array => [
                'code' => $code,
                'label' => __("premium.features.{$code}.name", locale: $locale),
                'description' => __("premium.features.{$code}.description", locale: $locale),
            ])->values()->all();

        return new PremiumPlanData(
            code: $plan->code,
            name: Lang::has($nameKey, $locale) ? __($nameKey, locale: $locale) : __('premium.plans.unnamed', locale: $locale),
            description: Lang::has($descriptionKey, $locale) ? __($descriptionKey, locale: $locale) : __('premium.plans.no_description', locale: $locale),
            type: $plan->type->value,
            price: Money::from((int) $plan->amount_minor, (string) $plan->currency)->format($locale),
            durationDays: $plan->duration_days,
            billingInterval: $plan->billing_interval,
            recurring: $plan->type === PremiumPlanType::RecurringSubscription,
            providerAvailable: true,
            features: $featureRows,
        );
    }
}
