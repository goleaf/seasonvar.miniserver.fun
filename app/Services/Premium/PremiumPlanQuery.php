<?php

declare(strict_types=1);

namespace App\Services\Premium;

use App\DTOs\Premium\PremiumPlanData;
use App\Enums\PremiumPlanType;
use App\Models\PremiumPlan;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\Lang;

final class PremiumPlanQuery
{
    public function __construct(
        private readonly PremiumSchema $schema,
        private readonly PremiumPaymentGatewayRegistry $gateways,
        private readonly PremiumFeatureRegistry $features,
    ) {}

    /** @return list<PremiumPlanData> */
    public function publicPlans(string $locale): array
    {
        if (! $this->schema->ready()) {
            return [];
        }

        return PremiumPlan::query()
            ->purchasable()
            ->whereNotNull('amount_minor')
            ->whereNotNull('currency')
            ->whereNotNull('provider_code')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (PremiumPlan $plan): bool => $this->commerciallyValid($plan))
            ->filter(fn (PremiumPlan $plan): bool => $this->editoriallyComplete($plan))
            ->filter(fn (PremiumPlan $plan): bool => $this->regionEligible($plan))
            ->filter(fn (PremiumPlan $plan): bool => $this->gatewaySupports($plan))
            ->map(fn (PremiumPlan $plan): PremiumPlanData => $this->present($plan, $locale))
            ->values()
            ->all();
    }

    public function purchasable(string $code): ?PremiumPlan
    {
        if (! $this->schema->ready() || preg_match('/\A[a-z0-9][a-z0-9_-]{1,63}\z/', $code) !== 1) {
            return null;
        }

        $plan = PremiumPlan::query()->purchasable()->where('code', $code)->first();

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
        $currencies = array_values(array_filter(
            (array) config('premium.supported_currencies', []),
            static fn (mixed $currency): bool => is_string($currency) && preg_match('/\A[A-Z]{3}\z/', $currency) === 1,
        ));
        $entitlements = array_values(array_filter(
            (array) $plan->entitlement_codes,
            fn (mixed $code): bool => is_string($code) && $this->features->supports($code),
        ));
        $durationValid = $plan->type !== PremiumPlanType::OneTimeDuration
            || (is_int($plan->duration_days) && $plan->duration_days >= 1 && $plan->duration_days <= 3650);
        $billingValid = match ($plan->type) {
            PremiumPlanType::RecurringSubscription => in_array($plan->billing_interval, ['month', 'quarter', 'year'], true),
            PremiumPlanType::OneTimeDuration, PremiumPlanType::Lifetime => $plan->billing_interval === null,
        };
        $lifetimeValid = $plan->type !== PremiumPlanType::Lifetime || $plan->duration_days === null;
        $providerPriceValid = is_string($plan->provider_price_id)
            && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,190}\z/', $plan->provider_price_id) === 1;

        return is_int($plan->amount_minor)
            && $plan->amount_minor > 0
            && is_string($plan->currency)
            && in_array($plan->currency, $currencies, true)
            && $entitlements !== []
            && count($entitlements) === count((array) $plan->entitlement_codes)
            && count(array_unique($entitlements)) === count($entitlements)
            && $durationValid
            && $billingValid
            && $lifetimeValid
            && $providerPriceValid;
    }

    private function editoriallyComplete(PremiumPlan $plan): bool
    {
        $locales = array_values(array_filter(
            (array) config('catalog-collections.supported_locales', []),
            static fn (mixed $locale): bool => is_string($locale) && preg_match('/\A[a-z]{2}\z/', $locale) === 1,
        ));

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
