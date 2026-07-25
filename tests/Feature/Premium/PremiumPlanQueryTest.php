<?php

declare(strict_types=1);

namespace Tests\Feature\Premium;

use App\Contracts\Premium\PremiumPaymentGateway;
use App\DTOs\Premium\PremiumPlanData;
use App\Enums\PremiumFeature;
use App\Enums\PremiumPlanType;
use App\Models\PremiumPlan;
use App\Services\Premium\PremiumPaymentGatewayRegistry;
use App\Services\Premium\PremiumPlanQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

final class PremiumPlanQueryTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'premium.providers.testpay.checkout_hosts' => ['checkout.test'],
            'premium.supported_currencies' => ['USD'],
            'premium.server_region_code' => 'LT',
        ]);
        $this->installGateways([
            $this->gateway('testpay', [
                'hosted_checkout',
                'one_time_checkout',
                'recurring_checkout',
                'lifetime_checkout',
            ]),
        ]);
    }

    public function test_public_plans_use_a_projected_candidate_window_and_stable_maximum(): void
    {
        foreach (range(1, 13) as $number) {
            $this->createPlan([
                'code' => sprintf('bounded-plan-%02d', $number),
                'display_order' => match ($number) {
                    1 => 2,
                    2, 3 => 1,
                    default => $number,
                },
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $plans = app(PremiumPlanQuery::class)->publicPlans('ru');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertCount(12, $plans);
        self::assertSame([
            'bounded-plan-02',
            'bounded-plan-03',
            'bounded-plan-01',
            'bounded-plan-04',
            'bounded-plan-05',
            'bounded-plan-06',
            'bounded-plan-07',
            'bounded-plan-08',
            'bounded-plan-09',
            'bounded-plan-10',
            'bounded-plan-11',
            'bounded-plan-12',
        ], array_map(
            static fn (PremiumPlanData $plan): string => $plan->code,
            $plans,
        ));

        $planQueries = collect($queries)
            ->pluck('query')
            ->filter(static fn (string $query): bool => str_contains($query, 'from "premium_plans"'))
            ->values();

        self::assertCount(1, $planQueries);
        $sql = mb_strtolower((string) $planQueries->first());

        self::assertStringNotContainsString('select *', $sql);
        self::assertStringNotContainsString('"created_at"', $sql);
        self::assertStringNotContainsString('"updated_at"', $sql);
        self::assertStringContainsString('"provider_product_id"', $sql);
        self::assertStringContainsString('"provider_price_id"', $sql);
        self::assertStringContainsString('order by "display_order" asc, "id" asc', $sql);
        self::assertStringContainsString('limit 48', $sql);
    }

    public function test_public_plans_reject_every_incomplete_commercial_mapping(): void
    {
        $this->createPlan(['code' => 'valid-public-plan']);
        $this->createPlan(['code' => 'Invalid Plan Code']);
        $this->createPlan([
            'code' => 'unsupported-feature',
            'entitlement_codes' => ['not_implemented'],
        ]);
        $this->createPlan([
            'code' => 'duplicate-feature',
            'entitlement_codes' => [
                PremiumFeature::PremiumAccess->value,
                PremiumFeature::PremiumAccess->value,
            ],
        ]);
        $this->createPlan([
            'code' => 'invalid-region',
            'region_codes' => ['LT', 'lt'],
        ]);
        $this->createPlan([
            'code' => 'mismatched-region',
            'region_codes' => ['US'],
        ]);
        $this->createPlan(['code' => 'missing-editorial'], translated: false);
        $this->createPlan([
            'code' => 'missing-product',
            'provider_product_id' => null,
        ]);
        $this->createPlan([
            'code' => 'invalid-product',
            'provider_product_id' => 'invalid product',
        ]);
        $this->createPlan([
            'code' => 'missing-price',
            'provider_price_id' => null,
        ]);
        $this->createPlan([
            'code' => 'invalid-price',
            'provider_price_id' => 'invalid price',
        ]);
        $this->createPlan([
            'code' => 'zero-amount',
            'amount_minor' => 0,
        ]);
        $this->createPlan([
            'code' => 'unsupported-currency',
            'currency' => 'EUR',
        ]);
        $this->createPlan([
            'code' => 'invalid-duration-billing',
            'billing_interval' => 'month',
        ]);
        $this->createPlan([
            'code' => 'recurring-with-duration',
            'type' => PremiumPlanType::RecurringSubscription,
            'duration_days' => 30,
            'billing_interval' => 'month',
        ]);
        $this->createPlan([
            'code' => 'lifetime-with-duration',
            'type' => PremiumPlanType::Lifetime,
            'duration_days' => 30,
        ]);
        $this->createPlan([
            'code' => 'inactive-plan',
            'is_active' => false,
        ]);
        $this->createPlan([
            'code' => 'private-plan',
            'is_public' => false,
        ]);
        $this->createPlan([
            'code' => 'legacy-plan',
            'is_legacy' => true,
        ]);

        $this->installGateways([
            $this->gateway('testpay', [
                'hosted_checkout',
                'one_time_checkout',
                'recurring_checkout',
                'lifetime_checkout',
            ]),
            $this->gateway('limitedpay', ['hosted_checkout']),
        ]);
        config(['premium.providers.limitedpay.checkout_hosts' => ['limited.test']]);
        $this->createPlan([
            'code' => 'unsupported-capability',
            'provider_code' => 'limitedpay',
        ]);

        DB::table((new PremiumPlan)->getTable())->insert([
            'code' => 'unsupported-plan-type',
            'type' => 'unsupported_type',
            'duration_days' => null,
            'billing_interval' => null,
            'amount_minor' => 2500,
            'currency' => 'USD',
            'entitlement_codes' => json_encode([PremiumFeature::PremiumAccess->value], JSON_THROW_ON_ERROR),
            'provider_code' => 'testpay',
            'provider_product_id' => 'product-unsupported-type',
            'provider_price_id' => 'price-unsupported-type',
            'region_codes' => null,
            'is_active' => true,
            'is_public' => true,
            'is_legacy' => false,
            'display_order' => 0,
        ]);
        $this->addPlanTranslations('unsupported-plan-type');

        $plans = app(PremiumPlanQuery::class)->publicPlans('ru');

        self::assertSame(
            ['valid-public-plan'],
            array_map(static fn (PremiumPlanData $plan): string => $plan->code, $plans),
        );
    }

    public function test_price_is_an_immutable_integer_snapshot_and_locale_only_changes_presentation(): void
    {
        $plan = $this->createPlan([
            'code' => 'immutable-price',
            'amount_minor' => 12345,
            'currency' => 'USD',
        ]);
        $query = app(PremiumPlanQuery::class);

        $russian = $query->publicPlans('ru')[0];
        $english = $query->publicPlans('en')[0];
        $originalRussianPrice = $russian->price;

        self::assertSame($russian->code, $english->code);
        self::assertSame($russian->type, $english->type);
        self::assertSame($russian->durationDays, $english->durationDays);
        self::assertSame($russian->billingInterval, $english->billingInterval);
        self::assertSame(
            array_column($russian->features, 'code'),
            array_column($english->features, 'code'),
        );
        self::assertNotSame($russian->name, $english->name);
        self::assertNotSame($russian->description, $english->description);
        self::assertNotSame($russian->price, $english->price);

        $purchasable = $query->purchasable('immutable-price');

        self::assertInstanceOf(PremiumPlan::class, $purchasable);
        self::assertSame(12345, $purchasable->amount_minor);
        self::assertSame('USD', $purchasable->currency);

        $plan->forceFill(['amount_minor' => 54321])->save();

        self::assertSame($originalRussianPrice, $russian->price);
        self::assertNotSame($originalRussianPrice, $query->publicPlans('ru')[0]->price);
        self::assertSame(54321, $query->purchasable('immutable-price')->amount_minor);
        self::assertSame('USD', $query->purchasable('immutable-price')->currency);
    }

    public function test_unsupported_locale_fails_before_schema_or_plan_queries(): void
    {
        $this->createPlan(['code' => 'locale-plan']);
        $query = app(PremiumPlanQuery::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        self::assertSame([], $query->publicPlans('de'));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertSame([], $queries);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPlan(array $attributes = [], bool $translated = true): PremiumPlan
    {
        $this->sequence++;
        $code = is_string($attributes['code'] ?? null)
            ? $attributes['code']
            : 'premium-plan-'.$this->sequence;

        $plan = PremiumPlan::query()->create(array_merge([
            'code' => $code,
            'type' => PremiumPlanType::OneTimeDuration,
            'duration_days' => 30,
            'billing_interval' => null,
            'amount_minor' => 12345,
            'currency' => 'USD',
            'entitlement_codes' => [PremiumFeature::PremiumAccess->value],
            'provider_code' => 'testpay',
            'provider_product_id' => 'product-'.$this->sequence,
            'provider_price_id' => 'price-'.$this->sequence,
            'region_codes' => null,
            'is_active' => true,
            'is_public' => true,
            'is_legacy' => false,
            'display_order' => $this->sequence,
        ], $attributes));

        if ($translated) {
            $this->addPlanTranslations($code);
        }

        return $plan;
    }

    private function addPlanTranslations(string $code): void
    {
        Lang::addLines([
            "premium.plans.{$code}.name" => 'Тариф '.$code,
            "premium.plans.{$code}.description" => 'Описание '.$code,
        ], 'ru');
        Lang::addLines([
            "premium.plans.{$code}.name" => 'Plan '.$code,
            "premium.plans.{$code}.description" => 'Description '.$code,
        ], 'en');
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function gateway(string $code, array $capabilities): PremiumPaymentGateway
    {
        $gateway = $this->createStub(PremiumPaymentGateway::class);
        $gateway->method('code')->willReturn($code);
        $gateway->method('environment')->willReturn('testing');
        $gateway->method('supports')->willReturnCallback(
            static fn (string $capability): bool => in_array($capability, $capabilities, true),
        );

        return $gateway;
    }

    /**
     * @param  list<PremiumPaymentGateway>  $gateways
     */
    private function installGateways(array $gateways): void
    {
        $this->app->instance(
            PremiumPaymentGatewayRegistry::class,
            new PremiumPaymentGatewayRegistry($gateways),
        );
    }
}
