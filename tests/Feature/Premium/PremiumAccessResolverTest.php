<?php

declare(strict_types=1);

namespace Tests\Feature\Premium;

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
use App\Services\Premium\PremiumAccessResolver;
use App\Services\Premium\PremiumEntitlementService;
use App\Services\Premium\PremiumSchema;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PremiumAccessResolverTest extends TestCase
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

        parent::tearDown();
    }

    public function test_guest_and_user_without_entitlements_are_inactive(): void
    {
        $resolver = app(PremiumAccessResolver::class);
        $user = User::factory()->create();

        $guest = $resolver->resolve(null);
        $empty = $resolver->resolve($user);

        self::assertFalse($guest->active);
        self::assertFalse($empty->active);
        self::assertNull($empty->startsAt);
        self::assertNull($empty->expiresAt);
        self::assertSame([], $empty->features);
        self::assertSame([], $empty->sources);
    }

    public function test_future_expired_boundary_and_revoked_entitlements_are_inactive(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::now();

        $this->createEntitlement($user, [
            'starts_at' => $now->addSecond(),
            'ends_at' => $now->addDay(),
        ]);
        $this->createEntitlement($user, [
            'starts_at' => $now->subDays(2),
            'ends_at' => $now->subSecond(),
        ]);
        $this->createEntitlement($user, [
            'starts_at' => $now->subDay(),
            'ends_at' => $now,
        ]);
        $this->createEntitlement($user, [
            'starts_at' => $now->subDay(),
            'ends_at' => $now->addDay(),
            'revoked_at' => $now,
        ]);

        $summary = app(PremiumAccessResolver::class)->resolve($user);

        self::assertFalse($summary->active);
        self::assertFalse(app(PremiumAccessResolver::class)->has($user, PremiumFeature::PremiumAccess));
    }

    public function test_exact_start_boundary_is_active_until_the_exclusive_end_boundary(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::now();
        $entitlement = $this->createEntitlement($user, [
            'starts_at' => $now,
            'ends_at' => $now->addSecond(),
        ]);

        $summary = app(PremiumAccessResolver::class)->resolve($user);

        self::assertTrue($summary->active);
        self::assertTrue($entitlement->isActiveAt($now));
        self::assertFalse($entitlement->isActiveAt($now->addSecond()));
        self::assertTrue(app(PremiumAccessResolver::class)->has($user, PremiumFeature::PremiumAccess));
    }

    public function test_lifetime_manual_promotion_and_subscription_sources_coexist(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::now();
        $subscription = $this->createSubscription($user, [
            'status' => PremiumSubscriptionStatus::Active,
            'current_period_start' => $now->subDay(),
            'current_period_end' => $now->addDays(10),
        ]);

        $this->createEntitlement($user, [
            'source' => PremiumEntitlementSource::ManualGrant,
            'starts_at' => $now->subDays(5),
            'ends_at' => $now->addDays(5),
        ]);
        $this->createEntitlement($user, [
            'source' => PremiumEntitlementSource::Promotion,
            'starts_at' => $now->subDays(4),
            'ends_at' => $now->addDays(7),
        ]);
        $this->createEntitlement($user, [
            'source' => PremiumEntitlementSource::Subscription,
            'premium_subscription_id' => $subscription->id,
            'starts_at' => $now->subDay(),
            'ends_at' => $now->addDays(10),
        ]);
        $this->createEntitlement($user, [
            'source' => PremiumEntitlementSource::LifetimePurchase,
            'starts_at' => $now->subDays(2),
            'ends_at' => null,
            'is_lifetime' => true,
        ]);

        $summary = app(PremiumAccessResolver::class)->resolve($user);

        self::assertTrue($summary->active);
        self::assertSame($now->subDays(5)->toAtomString(), $summary->startsAt?->toAtomString());
        self::assertNull($summary->expiresAt);
        self::assertTrue($summary->lifetime);
        self::assertTrue($summary->manual);
        self::assertTrue($summary->subscription);
        self::assertFalse($summary->gracePeriod);
        self::assertFalse($summary->cancellationScheduled);
        self::assertTrue($summary->regionalRestrictionsApply);
        self::assertSame([PremiumFeature::PremiumAccess->value], $summary->features);
        self::assertSame([
            PremiumEntitlementSource::LifetimePurchase->value,
            PremiumEntitlementSource::ManualGrant->value,
            PremiumEntitlementSource::Promotion->value,
            PremiumEntitlementSource::Subscription->value,
        ], $summary->sources);
    }

    public function test_provider_grace_extends_only_an_explicit_subscription_entitlement(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::now();
        $graceEndsAt = $now->addDays(3);
        $subscription = $this->createSubscription($user, [
            'status' => PremiumSubscriptionStatus::GracePeriod,
            'current_period_start' => $now->subMonth(),
            'current_period_end' => $now->subDay(),
            'grace_ends_at' => $graceEndsAt,
        ]);
        $entitlement = $this->createEntitlement($user, [
            'source' => PremiumEntitlementSource::Subscription,
            'premium_subscription_id' => $subscription->id,
            'starts_at' => $now->subMonth(),
            'ends_at' => $now->subDay(),
        ]);

        $summary = app(PremiumAccessResolver::class)->resolve($user);

        self::assertTrue($summary->active);
        self::assertTrue($summary->subscription);
        self::assertTrue($summary->gracePeriod);
        self::assertSame($graceEndsAt->toAtomString(), $summary->expiresAt?->toAtomString());
        self::assertTrue($entitlement->load('subscription')->graceActiveAt($now));
    }

    public function test_cancellation_is_scheduled_only_before_the_provider_period_end(): void
    {
        $user = User::factory()->create();
        $now = CarbonImmutable::now();
        $subscription = $this->createSubscription($user, [
            'status' => PremiumSubscriptionStatus::CancellationScheduled,
            'current_period_start' => $now->subDay(),
            'current_period_end' => $now->addDays(5),
            'cancel_at_period_end' => true,
        ]);
        $this->createEntitlement($user, [
            'source' => PremiumEntitlementSource::Subscription,
            'premium_subscription_id' => $subscription->id,
            'starts_at' => $now->subDay(),
            'ends_at' => $now->addDays(5),
        ]);
        $resolver = app(PremiumAccessResolver::class);

        self::assertTrue($resolver->resolve($user)->cancellationScheduled);

        $subscription->forceFill(['current_period_end' => $now])->save();
        $resolver->forget($user);

        self::assertFalse($resolver->resolve($user)->cancellationScheduled);
    }

    public function test_duration_grants_extend_from_the_current_expiry_without_shortening_access(): void
    {
        $user = User::factory()->create();
        Notification::fake();
        $service = app(PremiumEntitlementService::class);
        $now = CarbonImmutable::now();

        $first = $service->grantDuration(
            $user,
            PremiumFeature::PremiumAccess,
            PremiumEntitlementSource::ManualGrant,
            30,
            'duration-first',
        );
        $second = $service->grantDuration(
            $user,
            PremiumFeature::PremiumAccess,
            PremiumEntitlementSource::SupportCompensation,
            10,
            'duration-second',
        );
        $resolver = app(PremiumAccessResolver::class);
        $summary = $resolver->resolve($user);

        self::assertNotNull($first->ends_at);
        self::assertNotNull($second->ends_at);
        self::assertSame($now->toAtomString(), $first->starts_at->toAtomString());
        self::assertSame($now->addDays(30)->toAtomString(), $first->ends_at->toAtomString());
        self::assertSame($first->ends_at->toAtomString(), $second->starts_at->toAtomString());
        self::assertSame($now->addDays(40)->toAtomString(), $second->ends_at->toAtomString());
        self::assertSame($first->ends_at->toAtomString(), $summary->expiresAt?->toAtomString());
        self::assertTrue($summary->manual);

        CarbonImmutable::setTestNow($first->ends_at);
        $resolver->forget($user);

        self::assertSame(
            $second->ends_at->toAtomString(),
            $resolver->resolve($user)->expiresAt?->toAtomString(),
        );
    }

    public function test_payment_revoke_preserves_unrelated_payment_and_manual_entitlements(): void
    {
        $user = User::factory()->create();
        Notification::fake();
        $firstPayment = $this->createPayment($user);
        $secondPayment = $this->createPayment($user);
        $linked = $this->createEntitlement($user, [
            'source' => PremiumEntitlementSource::OneTimePurchase,
            'premium_payment_id' => $firstPayment->id,
        ]);
        $unrelatedPayment = $this->createEntitlement($user, [
            'source' => PremiumEntitlementSource::OneTimePurchase,
            'premium_payment_id' => $secondPayment->id,
        ]);
        $manual = $this->createEntitlement($user, [
            'source' => PremiumEntitlementSource::ManualGrant,
        ]);

        app(PremiumEntitlementService::class)->revokeForPayment($firstPayment, 'chargeback');

        self::assertNotNull($linked->fresh()?->revoked_at);
        self::assertNull($unrelatedPayment->fresh()?->revoked_at);
        self::assertNull($manual->fresh()?->revoked_at);

        $summary = app(PremiumAccessResolver::class)->resolve($user);

        self::assertTrue($summary->active);
        self::assertSame([
            PremiumEntitlementSource::ManualGrant->value,
            PremiumEntitlementSource::OneTimePurchase->value,
        ], $summary->sources);
    }

    public function test_request_memo_requires_explicit_invalidation_after_a_mutation(): void
    {
        $user = User::factory()->create();
        $resolver = app(PremiumAccessResolver::class);
        $initial = $resolver->resolve($user);

        $this->createEntitlement($user);

        self::assertSame($initial, $resolver->resolve($user));
        self::assertFalse($resolver->resolve($user)->active);

        $resolver->forget($user);

        self::assertTrue($resolver->resolve($user)->active);
    }

    public function test_active_read_is_bounded_projected_and_does_not_sort_rows_unnecessarily(): void
    {
        $user = User::factory()->create();
        $subscription = $this->createSubscription($user);
        $this->createEntitlement($user, [
            'source' => PremiumEntitlementSource::Subscription,
            'premium_subscription_id' => $subscription->id,
        ]);
        $schema = new PremiumSchema;
        self::assertTrue($schema->ready());
        $resolver = new PremiumAccessResolver($schema);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $summary = $resolver->resolve($user);
        $queries = collect(DB::getQueryLog())->pluck('query')->values();
        DB::disableQueryLog();

        self::assertTrue($summary->active);
        self::assertCount(2, $queries);
        self::assertStringContainsString('from "premium_entitlements"', $queries[0]);
        self::assertStringNotContainsString('order by', mb_strtolower($queries[0]));
        self::assertStringNotContainsString('"private_note"', $queries[0]);
        self::assertStringNotContainsString('"application_key"', $queries[0]);
        self::assertStringContainsString('from "premium_subscriptions"', $queries[1]);
        self::assertStringNotContainsString('"provider_customer_id"', $queries[1]);
        self::assertStringNotContainsString('"provider_subscription_id"', $queries[1]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        self::assertSame($summary, $resolver->resolve($user));
        self::assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
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
            'application_key' => hash('sha256', 'resolver-entitlement-'.$this->sequence),
            'starts_at' => CarbonImmutable::now()->subDay(),
            'ends_at' => CarbonImmutable::now()->addDay(),
            'is_lifetime' => false,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createSubscription(User $user, array $attributes = []): PremiumSubscription
    {
        $this->sequence++;
        $plan = PremiumPlan::query()->create([
            'code' => 'resolver-plan-'.$this->sequence,
            'type' => PremiumPlanType::RecurringSubscription,
            'billing_interval' => 'month',
            'amount_minor' => 1000,
            'currency' => 'USD',
            'entitlement_codes' => [PremiumFeature::PremiumAccess->value],
            'is_active' => true,
            'is_public' => false,
            'is_legacy' => false,
            'display_order' => $this->sequence,
        ]);

        return PremiumSubscription::query()->create(array_merge([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'premium_plan_id' => $plan->id,
            'provider_code' => 'resolver-test',
            'provider_subscription_id' => 'subscription-'.$this->sequence,
            'status' => PremiumSubscriptionStatus::Active,
            'current_period_start' => CarbonImmutable::now()->subDay(),
            'current_period_end' => CarbonImmutable::now()->addMonth(),
            'cancel_at_period_end' => false,
        ], $attributes));
    }

    private function createPayment(User $user): PremiumPayment
    {
        $this->sequence++;

        return PremiumPayment::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'provider_code' => 'resolver-test',
            'provider_payment_id' => 'payment-'.$this->sequence,
            'status' => PremiumPaymentStatus::Succeeded,
            'amount_minor' => 1000,
            'currency' => 'USD',
            'confirmed_at' => CarbonImmutable::now(),
            'provider_created_at' => CarbonImmutable::now(),
            'provider_updated_at' => CarbonImmutable::now(),
        ]);
    }
}
