<?php

declare(strict_types=1);

namespace Tests\Feature\Premium;

use App\Contracts\Premium\PremiumPaymentGateway;
use App\Services\Premium\PremiumPaymentGatewayRegistry;
use App\Services\Premium\PremiumPlanQuery;
use App\Services\Premium\PremiumSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PremiumQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_provider_plan_lookup_executes_no_database_queries(): void
    {
        config([
            'premium.providers' => [],
            'premium.supported_currencies' => ['USD'],
        ]);
        $plans = app(PremiumPlanQuery::class);

        self::assertSame(0, $this->countQueries(function () use ($plans): void {
            self::assertSame([], $plans->publicPlans('ru'));
            self::assertNull($plans->purchasable('monthly'));
        }));
    }

    public function test_schema_readiness_uses_one_memoized_framework_inventory_operation(): void
    {
        $schema = new PremiumSchema;

        self::assertSame(2, $this->countQueries(function () use ($schema): void {
            self::assertTrue($schema->ready());
            self::assertTrue($schema->ready());
        }));
    }

    public function test_schema_readiness_fails_closed_and_memoizes_a_missing_table(): void
    {
        Schema::drop('premium_audit_events');
        $schema = new PremiumSchema;

        self::assertSame(2, $this->countQueries(function () use ($schema): void {
            self::assertFalse($schema->ready());
            self::assertFalse($schema->ready());
        }));
    }

    public function test_invalid_currency_allowlists_fail_before_database_access(): void
    {
        $this->installGatewayStub();

        foreach ([
            [],
            ['usd'],
            ['USD', 'USD'],
            ['USD', 42],
            [['USD']],
        ] as $currencies) {
            config(['premium.supported_currencies' => $currencies]);
            $plans = app(PremiumPlanQuery::class);

            self::assertSame(0, $this->countQueries(function () use ($plans): void {
                self::assertSame([], $plans->publicPlans('ru'));
                self::assertNull($plans->purchasable('monthly'));
            }));
        }
    }

    public function test_configured_commerce_reaches_one_plan_query_after_schema_inventory(): void
    {
        $this->installGatewayStub();
        config(['premium.supported_currencies' => ['USD']]);
        $plans = app(PremiumPlanQuery::class);

        self::assertSame(3, $this->countQueries(function () use ($plans): void {
            self::assertSame([], $plans->publicPlans('ru'));
        }));
    }

    public function test_invalid_plan_code_fails_before_schema_or_plan_queries(): void
    {
        $this->installGatewayStub();
        config(['premium.supported_currencies' => ['USD']]);
        $plans = app(PremiumPlanQuery::class);

        self::assertSame(0, $this->countQueries(function () use ($plans): void {
            self::assertNull($plans->purchasable('<invalid>'));
        }));
    }

    private function installGatewayStub(): void
    {
        $gateway = $this->createStub(PremiumPaymentGateway::class);
        $gateway->method('code')->willReturn('testpay');
        $gateway->method('environment')->willReturn('testing');

        $this->app->instance(
            PremiumPaymentGatewayRegistry::class,
            new PremiumPaymentGatewayRegistry([$gateway]),
        );
    }

    private function countQueries(callable $operation): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $operation();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
