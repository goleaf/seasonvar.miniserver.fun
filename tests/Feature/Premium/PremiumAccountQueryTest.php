<?php

declare(strict_types=1);

namespace Tests\Feature\Premium;

use App\DTOs\Premium\PremiumPaymentHistoryData;
use App\Enums\PremiumEntitlementSource;
use App\Enums\PremiumFeature;
use App\Enums\PremiumPaymentStatus;
use App\Enums\PremiumPlanType;
use App\Enums\PremiumSubscriptionStatus;
use App\Models\PremiumEntitlement;
use App\Models\PremiumPayment;
use App\Models\PremiumPlan;
use App\Models\PremiumSubscription;
use App\Models\User;
use App\Services\Premium\PremiumAccountQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PremiumAccountQueryTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-25 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Paginator::currentPageResolver(static fn (): int => 1);

        parent::tearDown();
    }

    public function test_snapshot_reuses_one_entitlement_read_without_losing_active_access_behind_history_limit(): void
    {
        $user = User::factory()->create();
        $plan = $this->createPlan('account-recurring');
        $subscription = $this->createSubscription($user, $plan);
        $activeEntitlement = $this->createEntitlement($user, [
            'premium_plan_id' => $plan->id,
            'premium_subscription_id' => $subscription->id,
            'source' => PremiumEntitlementSource::Subscription,
            'starts_at' => CarbonImmutable::now()->subDays(60),
            'ends_at' => CarbonImmutable::now()->addDays(10),
        ]);

        foreach (range(1, 25) as $days) {
            $this->createEntitlement($user, [
                'starts_at' => CarbonImmutable::now()->addDays($days),
                'ends_at' => CarbonImmutable::now()->addDays($days + 1),
            ]);
        }

        Lang::addLines([
            'premium.plans.account-recurring.name' => 'Основной тариф',
        ], 'ru');
        DB::flushQueryLog();
        DB::enableQueryLog();

        $snapshot = app(PremiumAccountQuery::class)->snapshot($user, 'ru', 'UTC');
        $queries = DB::getQueryLog();

        DB::disableQueryLog();

        self::assertTrue($snapshot['overview']['summary']->active);
        self::assertSame('Основной тариф', $snapshot['overview']['active_plan']);
        self::assertCount(25, $snapshot['overview']['entitlements']);
        self::assertNotContains(
            $activeEntitlement->public_id,
            array_column($snapshot['overview']['entitlements'], 'public_id'),
        );
        self::assertSame(PremiumSubscriptionStatus::Active->label(), $snapshot['overview']['subscription']['status']);

        $entitlementQueries = collect($queries)
            ->pluck('query')
            ->filter(static fn (string $sql): bool => str_contains($sql, 'from "premium_entitlements"'))
            ->values();

        self::assertCount(1, $entitlementQueries);
        self::assertStringStartsWith('select "id", "public_id", "user_id"', mb_strtolower($entitlementQueries->first()));

        $subscriptionQueries = collect($queries)
            ->pluck('query')
            ->filter(static function (string $sql): bool {
                $firstFrom = mb_strpos(mb_strtolower($sql), ' from ');

                return $firstFrom !== false
                    && str_starts_with(mb_substr(mb_strtolower($sql), $firstFrom), ' from "premium_subscriptions"');
            })
            ->values();

        self::assertCount(1, $subscriptionQueries);
    }

    public function test_payment_history_is_owner_scoped_bounded_projected_and_deterministically_ordered(): void
    {
        config(['premium.history_per_page' => 1000]);
        $user = User::factory()->create();
        $foreignUser = User::factory()->create();
        $createdAt = CarbonImmutable::now()->subHour();
        $expectedIds = [];

        foreach (range(1, 52) as $position) {
            $payment = $this->createPayment($user, $createdAt);
            $expectedIds[] = $payment->public_id;
        }
        $this->createPayment($foreignUser, $createdAt->addMinute());

        DB::flushQueryLog();
        DB::enableQueryLog();

        $payments = app(PremiumAccountQuery::class)->payments($user, 'ru', 'UTC');
        $queries = DB::getQueryLog();

        DB::disableQueryLog();

        self::assertSame('premiumPaymentsPage', $payments->getPageName());
        self::assertSame(50, $payments->perPage());
        self::assertSame(52, $payments->total());
        self::assertCount(50, $payments->items());
        self::assertSame(
            array_slice(array_reverse($expectedIds), 0, 50),
            array_map(
                static fn (PremiumPaymentHistoryData $payment): string => $payment->publicId,
                $payments->items(),
            ),
        );

        $pageQuery = collect($queries)
            ->pluck('query')
            ->first(static fn (string $sql): bool => str_contains($sql, 'from "premium_payments"')
                && ! str_contains($sql, 'count(*)'));

        self::assertIsString($pageQuery);
        self::assertStringNotContainsString('select *', mb_strtolower($pageQuery));
        self::assertStringContainsString('"premium_plan_id"', $pageQuery);
        self::assertStringContainsString('order by "created_at" desc, "id" desc', mb_strtolower($pageQuery));
    }

    public function test_second_payment_page_keeps_the_same_page_name_and_has_no_tie_duplicates(): void
    {
        $user = User::factory()->create();
        $createdAt = CarbonImmutable::now()->subHour();
        $expectedIds = [];

        foreach (range(1, 17) as $position) {
            $payment = $this->createPayment($user, $createdAt);
            $expectedIds[] = $payment->public_id;
        }

        Paginator::currentPageResolver(
            static fn (string $pageName): int => $pageName === 'premiumPaymentsPage' ? 2 : 1,
        );

        $payments = app(PremiumAccountQuery::class)->payments($user, 'ru', 'UTC');

        self::assertSame('premiumPaymentsPage', $payments->getPageName());
        self::assertSame(2, $payments->currentPage());
        self::assertSame(
            array_slice(array_reverse($expectedIds), 15),
            array_map(
                static fn (PremiumPaymentHistoryData $payment): string => $payment->publicId,
                $payments->items(),
            ),
        );
    }

    public function test_owner_settings_page_renders_the_prepared_payment_row_with_private_headers(): void
    {
        $user = User::factory()->create();
        $this->createPayment($user, CarbonImmutable::now()->subHour());

        $response = $this->actingAs($user)
            ->get(route('settings.index', ['section' => 'premium']));

        $response
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee(__('premium.settings.payments'))
            ->assertSee(PremiumPaymentStatus::Succeeded->label());
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    private function createPlan(string $code): PremiumPlan
    {
        return PremiumPlan::query()->create([
            'code' => $code,
            'type' => PremiumPlanType::RecurringSubscription,
            'billing_interval' => 'month',
            'amount_minor' => 1000,
            'currency' => 'USD',
            'entitlement_codes' => [PremiumFeature::PremiumAccess->value],
            'is_active' => true,
            'is_public' => false,
            'is_legacy' => false,
            'display_order' => 1,
        ]);
    }

    private function createSubscription(User $user, PremiumPlan $plan): PremiumSubscription
    {
        return PremiumSubscription::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'premium_plan_id' => $plan->id,
            'provider_code' => 'account-test',
            'provider_subscription_id' => 'subscription-'.Str::uuid(),
            'status' => PremiumSubscriptionStatus::Active,
            'current_period_start' => CarbonImmutable::now()->subMonth(),
            'current_period_end' => CarbonImmutable::now()->addDays(10),
            'cancel_at_period_end' => false,
            'provider_updated_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createEntitlement(User $user, array $attributes = []): PremiumEntitlement
    {
        $this->sequence++;

        return PremiumEntitlement::query()->create(array_merge([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'feature_code' => PremiumFeature::PremiumAccess,
            'source' => PremiumEntitlementSource::ManualGrant,
            'source_reference' => 'account-entitlement-'.$this->sequence,
            'application_key' => hash('sha256', 'account-entitlement-'.$this->sequence),
            'reason_code' => 'account_test',
            'starts_at' => CarbonImmutable::now()->subDay(),
            'ends_at' => CarbonImmutable::now()->addDay(),
            'is_lifetime' => false,
        ], $attributes));
    }

    private function createPayment(User $user, CarbonImmutable $createdAt): PremiumPayment
    {
        $this->sequence++;

        $payment = PremiumPayment::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'provider_code' => 'account-test',
            'provider_payment_id' => 'payment-'.$this->sequence,
            'status' => PremiumPaymentStatus::Succeeded,
            'amount_minor' => 1000,
            'currency' => 'USD',
            'refunded_amount_minor' => 0,
            'confirmed_at' => $createdAt,
            'provider_created_at' => $createdAt,
            'provider_updated_at' => $createdAt,
        ]);
        $payment->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        return $payment;
    }
}
